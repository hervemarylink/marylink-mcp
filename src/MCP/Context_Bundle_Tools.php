<?php
/**
 * Context Bundle Tools - Build context bundles for prompts
 *
 * Tools:
 * - ml_context_bundle_build: Build a context bundle from publications/docs
 *
 * Returns citations and extracted passages for use in prompts.
 * Sans embeddings - utilise search textuel pour le moment.
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Publication_Service;
use MCP_No_Headless\Services\Query_Service;
use MCP_No_Headless\Picasso\Picasso_Adapter;

class Context_Bundle_Tools {

    /**
     * Max documents to include in bundle
     */
    private const MAX_DOCS = 10;

    /**
     * Max passages per document
     */
    private const MAX_PASSAGES_PER_DOC = 3;

    /**
     * Max characters per passage
     */
    private const MAX_PASSAGE_LENGTH = 500;

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_context_bundle_build' => [
                'name' => 'ml_context_bundle_build',
                'description' => 'Build a context bundle of relevant documents for a prompt. Returns citations and extracted passages.',
                'category' => 'MaryLink Context',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query to find relevant documents',
                        ],
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'Optional: Limit search to a specific space',
                        ],
                        'publication_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Optional: Specific publication IDs to include',
                        ],
                        'types' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional: Filter by publication types (doc, data, style)',
                            'default' => ['doc', 'data'],
                        ],
                        'max_docs' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 10,
                            'default' => 5,
                            'description' => 'Maximum documents to include',
                        ],
                        'max_passages' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 3,
                            'default' => 2,
                            'description' => 'Maximum passages per document',
                        ],
                    ],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                ],
            ],
        ];
    }

    /**
     * Execute a context bundle tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();

        switch ($tool) {
            case 'ml_context_bundle_build':
                return self::handle_build($args, $user_id, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_context_bundle_build
     */
    private static function handle_build(array $args, int $user_id, string $request_id): array {
        $permissions = new Permission_Checker($user_id);

        $query = trim($args['query'] ?? '');
        $space_id = isset($args['space_id']) ? (int) $args['space_id'] : null;
        $publication_ids = $args['publication_ids'] ?? [];
        $types = $args['types'] ?? ['doc', 'data'];
        $max_docs = min(self::MAX_DOCS, max(1, (int) ($args['max_docs'] ?? 5)));
        $max_passages = min(self::MAX_PASSAGES_PER_DOC, max(1, (int) ($args['max_passages'] ?? 2)));

        $documents = [];
        $citations = [];
        $extracts = [];

        // Strategy 1: Use provided publication IDs
        if (!empty($publication_ids)) {
            foreach (array_slice($publication_ids, 0, $max_docs) as $pub_id) {
                $pub_id = (int) $pub_id;
                if ($permissions->can_see_publication($pub_id)) {
                    $doc = self::get_document($pub_id, $max_passages);
                    if ($doc) {
                        $documents[] = $doc;
                    }
                }
            }
        }

        // Strategy 2: Search by query
        if (!empty($query) && count($documents) < $max_docs) {
            $search_results = self::search_publications(
                $query,
                $space_id,
                $types,
                $max_docs - count($documents),
                $user_id
            );

            foreach ($search_results as $pub_id) {
                if (!in_array($pub_id, $publication_ids, true)) {
                    $doc = self::get_document($pub_id, $max_passages);
                    if ($doc) {
                        $documents[] = $doc;
                    }
                }
            }
        }

        // Strategy 3: Get linked contents from tool if space specified
        if ($space_id && count($documents) < $max_docs) {
            $linked = self::get_space_docs($space_id, $types, $max_docs - count($documents), $user_id);
            foreach ($linked as $pub_id) {
                if (!in_array($pub_id, array_column($documents, 'id'), true)) {
                    $doc = self::get_document($pub_id, $max_passages);
                    if ($doc) {
                        $documents[] = $doc;
                    }
                }
            }
        }

        // Build output format
        foreach ($documents as $doc) {
            $citations[] = [
                'id' => $doc['id'],
                'title' => $doc['title'],
                'url' => $doc['url'],
                'type' => $doc['type'],
            ];

            foreach ($doc['passages'] as $passage) {
                $extracts[] = [
                    'source_id' => $doc['id'],
                    'source_title' => $doc['title'],
                    'text' => $passage,
                ];
            }
        }

        if (empty($documents)) {
            return Tool_Response::empty_list(
                $request_id,
                'No relevant documents found for this context.',
                ['suggestions' => ['Try a broader search query', 'Check space_id permissions']]
            );
        }

        return Tool_Response::ok([
            'document_count' => count($documents),
            'extract_count' => count($extracts),
            'citations' => $citations,
            'extracts' => $extracts,
            'bundle_text' => self::format_bundle_text($documents),
        ], $request_id);
    }

    /**
     * Search publications by query
     */
    private static function search_publications(
        string $query,
        ?int $space_id,
        array $types,
        int $limit,
        int $user_id
    ): array {
        $permissions = new Permission_Checker($user_id);

        $query_args = [
            'post_type' => 'publication',
            'post_status' => 'publish',
            's' => $query,
            'posts_per_page' => $limit * 2, // Over-sample for permission filtering
        ];

        if ($space_id) {
            if (!$permissions->can_see_space($space_id)) {
                return [];
            }
            $query_args['post_parent'] = $space_id;
        }

        // Filter by types if specified
        if (!empty($types)) {
            $query_args['meta_query'][] = [
                'relation' => 'OR',
                [
                    'key' => '_ml_publication_type',
                    'value' => $types,
                    'compare' => 'IN',
                ],
                [
                    'key' => '_publication_type',
                    'value' => $types,
                    'compare' => 'IN',
                ],
            ];
        }

        $wp_query = new \WP_Query($query_args);
        $results = [];

        foreach ($wp_query->posts as $post) {
            if ($permissions->can_see_publication($post->ID)) {
                $results[] = $post->ID;
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Get documents from a space (top docs by rating/recency)
     */
    private static function get_space_docs(int $space_id, array $types, int $limit, int $user_id): array {
        $permissions = new Permission_Checker($user_id);

        if (!$permissions->can_see_space($space_id)) {
            return [];
        }

        $query_args = [
            'post_type' => 'publication',
            'post_status' => 'publish',
            'post_parent' => $space_id,
            'posts_per_page' => $limit * 2,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if (!empty($types)) {
            $query_args['meta_query'][] = [
                'relation' => 'OR',
                [
                    'key' => '_ml_publication_type',
                    'value' => $types,
                    'compare' => 'IN',
                ],
            ];
        }

        $wp_query = new \WP_Query($query_args);
        $results = [];

        foreach ($wp_query->posts as $post) {
            if ($permissions->can_see_publication($post->ID)) {
                $results[] = $post->ID;
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Get document with extracted passages
     */
    private static function get_document(int $publication_id, int $max_passages): ?array {
        $post = get_post($publication_id);
        if (!$post) {
            return null;
        }

        $content = strip_tags($post->post_content);
        $type = get_post_meta($publication_id, '_ml_publication_type', true) ?: 'doc';

        // Extract passages
        $passages = self::extract_passages($content, $max_passages);

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'type' => $type,
            'url' => get_permalink($post->ID),
            'passages' => $passages,
        ];
    }

    /**
     * Extract key passages from content
     */
    private static function extract_passages(string $content, int $max_passages): array {
        // Split into paragraphs
        $paragraphs = preg_split('/\n\n+/', $content);
        $paragraphs = array_filter($paragraphs, fn($p) => strlen(trim($p)) > 50);
        $paragraphs = array_values($paragraphs);

        if (empty($paragraphs)) {
            // Fallback: take truncated content
            return [self::truncate_text($content, self::MAX_PASSAGE_LENGTH)];
        }

        // Take evenly distributed passages
        $total = count($paragraphs);
        $step = max(1, (int) floor($total / $max_passages));
        $passages = [];

        for ($i = 0; $i < $total && count($passages) < $max_passages; $i += $step) {
            $passage = trim($paragraphs[$i]);
            if (strlen($passage) > 50) {
                $passages[] = self::truncate_text($passage, self::MAX_PASSAGE_LENGTH);
            }
        }

        return $passages;
    }

    /**
     * Truncate text to max length, respecting word boundaries
     */
    private static function truncate_text(string $text, int $max_length): string {
        $text = trim($text);
        if (mb_strlen($text) <= $max_length) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $max_length);
        $last_space = mb_strrpos($truncated, ' ');

        if ($last_space !== false && $last_space > $max_length * 0.8) {
            $truncated = mb_substr($truncated, 0, $last_space);
        }

        return $truncated . '...';
    }

    /**
     * Format bundle as text for inclusion in prompts
     */
    private static function format_bundle_text(array $documents): string {
        $parts = [];

        foreach ($documents as $doc) {
            $doc_text = "--- {$doc['title']} ({$doc['url']}) ---\n";
            $doc_text .= implode("\n\n", $doc['passages']);
            $parts[] = $doc_text;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Check if context bundle tools are available
     */
    public static function is_available(): bool {
        return post_type_exists('publication');
    }
}
