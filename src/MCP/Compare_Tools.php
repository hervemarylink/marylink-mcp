<?php
/**
 * Compare Tools - MCP tools for publication comparison (tool-map v1)
 *
 * Tools:
 * - ml_compare_publications: Compare two or more publications
 *
 * TICKET T3.2: Publication comparison
 * Features:
 * - Content diff (text similarity)
 * - Metadata comparison
 * - Dependency comparison
 * - Structure analysis
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Render_Service;
use MCP_No_Headless\Picasso\Picasso_Adapter;

class Compare_Tools {

    /**
     * Maximum publications to compare at once
     */
    private const MAX_COMPARE_ITEMS = 5;

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_compare_publications' => [
                'name' => 'ml_compare_publications',
                'description' => 'Compare two or more publications to find differences in content, metadata, and structure.',
                'category' => 'MaryLink Compare',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Array of publication IDs to compare (2-5 items)',
                            'minItems' => 2,
                            'maxItems' => 5,
                        ],
                        'compare_aspects' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                                'enum' => ['content', 'metadata', 'dependencies', 'structure', 'all'],
                            ],
                            'description' => 'Aspects to compare (default: all)',
                            'default' => ['all'],
                        ],
                        'include_content_diff' => [
                            'type' => 'boolean',
                            'description' => 'Include detailed content diff (can be large)',
                            'default' => false,
                        ],
                        'similarity_threshold' => [
                            'type' => 'number',
                            'description' => 'Similarity threshold 0-1 for content matching (default: 0.8)',
                            'minimum' => 0,
                            'maximum' => 1,
                            'default' => 0.8,
                        ],
                    ],
                    'required' => ['publication_ids'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                ],
            ],
        ];
    }

    /**
     * Execute a compare tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();
        $permissions = new Permission_Checker($user_id);

        if ($user_id <= 0) {
            return Tool_Response::error('authentication_required', 'You must be logged in to compare publications.', $request_id);
        }

        switch ($tool) {
            case 'ml_compare_publications':
                return self::handle_compare_publications($args, $user_id, $permissions, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_compare_publications
     */
    private static function handle_compare_publications(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_ids']);
        if ($validation) {
            return $validation;
        }

        $publication_ids = array_map('intval', $args['publication_ids']);
        $compare_aspects = $args['compare_aspects'] ?? ['all'];
        $include_content_diff = (bool) ($args['include_content_diff'] ?? false);
        $similarity_threshold = (float) ($args['similarity_threshold'] ?? 0.8);

        // Validate count
        if (count($publication_ids) < 2) {
            return Tool_Response::error('validation_failed', 'At least 2 publications required for comparison.', $request_id);
        }

        if (count($publication_ids) > self::MAX_COMPARE_ITEMS) {
            return Tool_Response::error('validation_failed', sprintf('Maximum %d publications can be compared at once.', self::MAX_COMPARE_ITEMS), $request_id);
        }

        // Remove duplicates
        $publication_ids = array_unique($publication_ids);

        // Normalize aspects
        if (in_array('all', $compare_aspects, true)) {
            $compare_aspects = ['content', 'metadata', 'dependencies', 'structure'];
        }

        // Load publications
        $publications = [];
        $errors = [];

        foreach ($publication_ids as $pub_id) {
            if (!$permissions->can_see_publication($pub_id)) {
                $errors[] = ['id' => $pub_id, 'reason' => 'access_denied'];
                continue;
            }

            $post = get_post($pub_id);
            if (!$post || $post->post_type !== 'publication') {
                $errors[] = ['id' => $pub_id, 'reason' => 'not_found'];
                continue;
            }

            $publications[$pub_id] = [
                'post' => $post,
                'type' => self::get_publication_type($pub_id),
                'step' => Picasso_Adapter::get_publication_step($pub_id),
                'meta' => get_post_meta($pub_id),
            ];
        }

        if (count($publications) < 2) {
            return Tool_Response::error('insufficient_items', 'At least 2 accessible publications required.', $request_id, ['errors' => $errors]);
        }

        // Perform comparison
        $comparison = [
            'publications' => [],
            'differences' => [],
            'similarities' => [],
        ];

        // Build publication summaries
        foreach ($publications as $pub_id => $data) {
            $comparison['publications'][] = [
                'id' => $pub_id,
                'title' => $data['post']->post_title,
                'type' => $data['type'],
                'step' => $data['step'],
                'author_id' => (int) $data['post']->post_author,
                'date' => $data['post']->post_date,
                'modified' => $data['post']->post_modified,
                'content_length' => strlen($data['post']->post_content),
            ];
        }

        // Compare content
        if (in_array('content', $compare_aspects, true)) {
            $comparison['content'] = self::compare_content($publications, $include_content_diff, $similarity_threshold);
        }

        // Compare metadata
        if (in_array('metadata', $compare_aspects, true)) {
            $comparison['metadata'] = self::compare_metadata($publications);
        }

        // Compare dependencies
        if (in_array('dependencies', $compare_aspects, true)) {
            $comparison['dependencies'] = self::compare_dependencies($publications, $permissions);
        }

        // Compare structure
        if (in_array('structure', $compare_aspects, true)) {
            $comparison['structure'] = self::compare_structure($publications);
        }

        // Summary stats
        $comparison['summary'] = [
            'publications_compared' => count($publications),
            'aspects_analyzed' => $compare_aspects,
        ];

        if (!empty($errors)) {
            $comparison['warnings'] = [
                'inaccessible_publications' => $errors,
            ];
        }

        return Tool_Response::ok($comparison, $request_id);
    }

    /**
     * Compare content between publications
     */
    private static function compare_content(array $publications, bool $include_diff, float $threshold): array {
        $result = [
            'similarity_matrix' => [],
            'common_words' => [],
            'unique_sections' => [],
        ];

        $pub_ids = array_keys($publications);
        $contents = [];

        // Extract text content
        foreach ($publications as $pub_id => $data) {
            $contents[$pub_id] = Render_Service::html_to_text($data['post']->post_content);
        }

        // Build similarity matrix
        for ($i = 0; $i < count($pub_ids); $i++) {
            for ($j = $i + 1; $j < count($pub_ids); $j++) {
                $id1 = $pub_ids[$i];
                $id2 = $pub_ids[$j];

                $similarity = self::calculate_similarity($contents[$id1], $contents[$id2]);

                $result['similarity_matrix'][] = [
                    'publication_a' => $id1,
                    'publication_b' => $id2,
                    'similarity' => round($similarity, 3),
                    'similar' => $similarity >= $threshold,
                ];
            }
        }

        // Find common words/phrases
        $word_sets = [];
        foreach ($contents as $pub_id => $content) {
            $words = array_filter(str_word_count(strtolower($content), 1));
            $word_sets[$pub_id] = array_unique($words);
        }

        $common_words = null;
        foreach ($word_sets as $words) {
            if ($common_words === null) {
                $common_words = $words;
            } else {
                $common_words = array_intersect($common_words, $words);
            }
        }

        // Filter to significant words (length > 4)
        $significant_common = array_filter($common_words, fn($w) => strlen($w) > 4);
        $result['common_words'] = array_values(array_slice($significant_common, 0, 20));

        // Content lengths
        $result['content_lengths'] = [];
        foreach ($contents as $pub_id => $content) {
            $result['content_lengths'][$pub_id] = [
                'chars' => strlen($content),
                'words' => str_word_count($content),
            ];
        }

        // Include diff if requested (only for 2 publications)
        if ($include_diff && count($publications) === 2) {
            $id1 = $pub_ids[0];
            $id2 = $pub_ids[1];
            $result['diff'] = self::generate_diff($contents[$id1], $contents[$id2]);
        }

        return $result;
    }

    /**
     * Compare metadata between publications
     */
    private static function compare_metadata(array $publications): array {
        $result = [
            'common_meta' => [],
            'different_meta' => [],
            'unique_meta' => [],
        ];

        $meta_keys_all = [];
        $meta_values = [];

        // Collect all meta keys and values
        foreach ($publications as $pub_id => $data) {
            $meta = $data['meta'];
            foreach ($meta as $key => $values) {
                // Skip internal WordPress meta
                if (strpos($key, '_edit') === 0 || strpos($key, '_wp_') === 0) {
                    continue;
                }

                $meta_keys_all[$key] = ($meta_keys_all[$key] ?? 0) + 1;
                $meta_values[$key][$pub_id] = $values[0] ?? null;
            }
        }

        $pub_count = count($publications);

        foreach ($meta_keys_all as $key => $count) {
            if ($count === $pub_count) {
                // All publications have this key
                $values = array_values($meta_values[$key]);
                $unique_values = array_unique($values);

                if (count($unique_values) === 1) {
                    // Same value in all
                    $result['common_meta'][$key] = $values[0];
                } else {
                    // Different values
                    $result['different_meta'][$key] = $meta_values[$key];
                }
            } else {
                // Only some publications have this key
                $result['unique_meta'][$key] = $meta_values[$key];
            }
        }

        return $result;
    }

    /**
     * Compare dependencies between publications
     */
    private static function compare_dependencies(array $publications, Permission_Checker $permissions): array {
        $result = [
            'shared_dependencies' => [],
            'unique_dependencies' => [],
            'by_publication' => [],
        ];

        $all_deps = [];

        foreach ($publications as $pub_id => $data) {
            $deps = Picasso_Adapter::get_publication_dependencies($pub_id);
            $dep_ids = $deps['dependencies'] ?? [];

            // Filter to accessible only
            $accessible_deps = [];
            foreach ($dep_ids as $dep_id) {
                if ($permissions->can_see_publication($dep_id)) {
                    $dep_post = get_post($dep_id);
                    if ($dep_post) {
                        $accessible_deps[] = [
                            'id' => $dep_id,
                            'title' => $dep_post->post_title,
                            'type' => self::get_publication_type($dep_id),
                        ];
                        $all_deps[$dep_id] = ($all_deps[$dep_id] ?? 0) + 1;
                    }
                }
            }

            $result['by_publication'][$pub_id] = [
                'count' => count($accessible_deps),
                'dependencies' => $accessible_deps,
            ];
        }

        $pub_count = count($publications);

        // Categorize dependencies
        foreach ($all_deps as $dep_id => $count) {
            $dep_post = get_post($dep_id);
            $dep_info = [
                'id' => $dep_id,
                'title' => $dep_post ? $dep_post->post_title : 'Unknown',
                'type' => self::get_publication_type($dep_id),
                'used_by_count' => $count,
            ];

            if ($count === $pub_count) {
                $result['shared_dependencies'][] = $dep_info;
            } else {
                $result['unique_dependencies'][] = $dep_info;
            }
        }

        return $result;
    }

    /**
     * Compare structure between publications
     */
    private static function compare_structure(array $publications): array {
        $result = [
            'by_publication' => [],
            'heading_comparison' => [],
        ];

        foreach ($publications as $pub_id => $data) {
            $content = $data['post']->post_content;

            // Extract headings
            preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/i', $content, $matches);
            $headings = [];
            for ($i = 0; $i < count($matches[0]); $i++) {
                $headings[] = [
                    'level' => (int) $matches[1][$i],
                    'text' => strip_tags($matches[2][$i]),
                ];
            }

            // Count elements
            $structure = [
                'headings' => $headings,
                'heading_count' => count($headings),
                'paragraph_count' => preg_match_all('/<p[^>]*>/i', $content),
                'list_count' => preg_match_all('/<[ou]l[^>]*>/i', $content),
                'image_count' => preg_match_all('/<img[^>]*>/i', $content),
                'link_count' => preg_match_all('/<a[^>]*>/i', $content),
                'code_block_count' => preg_match_all('/<(pre|code)[^>]*>/i', $content),
                'table_count' => preg_match_all('/<table[^>]*>/i', $content),
            ];

            $result['by_publication'][$pub_id] = $structure;
        }

        // Compare heading structures
        $heading_texts = [];
        foreach ($result['by_publication'] as $pub_id => $struct) {
            foreach ($struct['headings'] as $h) {
                $key = strtolower(trim($h['text']));
                $heading_texts[$key] = ($heading_texts[$key] ?? []);
                $heading_texts[$key][] = $pub_id;
            }
        }

        $pub_count = count($publications);
        foreach ($heading_texts as $text => $pubs) {
            if (count($pubs) === $pub_count) {
                $result['heading_comparison']['common'][] = $text;
            } else {
                $result['heading_comparison']['unique'][] = [
                    'text' => $text,
                    'in_publications' => $pubs,
                ];
            }
        }

        return $result;
    }

    /**
     * Calculate text similarity (Jaccard index on words)
     */
    private static function calculate_similarity(string $text1, string $text2): float {
        $words1 = array_unique(str_word_count(strtolower($text1), 1));
        $words2 = array_unique(str_word_count(strtolower($text2), 1));

        if (empty($words1) && empty($words2)) {
            return 1.0; // Both empty = identical
        }

        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));

        return $union > 0 ? $intersection / $union : 0;
    }

    /**
     * Generate simple line-by-line diff
     */
    private static function generate_diff(string $text1, string $text2): array {
        $lines1 = explode("\n", $text1);
        $lines2 = explode("\n", $text2);

        $diff = [
            'removed' => [],
            'added' => [],
            'unchanged' => 0,
        ];

        // Simple diff: lines only in text1, lines only in text2
        $lines1_set = array_flip($lines1);
        $lines2_set = array_flip($lines2);

        foreach ($lines1 as $i => $line) {
            $line_trimmed = trim($line);
            if (empty($line_trimmed)) continue;

            if (!isset($lines2_set[$line])) {
                $diff['removed'][] = ['line' => $i + 1, 'content' => substr($line, 0, 200)];
            } else {
                $diff['unchanged']++;
            }
        }

        foreach ($lines2 as $i => $line) {
            $line_trimmed = trim($line);
            if (empty($line_trimmed)) continue;

            if (!isset($lines1_set[$line])) {
                $diff['added'][] = ['line' => $i + 1, 'content' => substr($line, 0, 200)];
            }
        }

        // Limit output
        $diff['removed'] = array_slice($diff['removed'], 0, 50);
        $diff['added'] = array_slice($diff['added'], 0, 50);

        return $diff;
    }

    /**
     * Get publication type
     */
    private static function get_publication_type(int $publication_id): string {
        $type = get_post_meta($publication_id, '_ml_publication_type', true);
        return $type ?: 'publication';
    }

    /**
     * Check if available
     */
    public static function is_available(): bool {
        return post_type_exists('publication');
    }
}
