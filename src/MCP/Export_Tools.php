<?php
/**
 * Export Tools - MCP tools for publication export (tool-map v1)
 *
 * Tools:
 * - ml_export_bundle: Export a publication with dependencies as a bundle
 *
 * TICKET T4.2: Export bundle
 * Features:
 * - Export publication with all dependencies
 * - Multiple formats (JSON, markdown)
 * - Plan-based export limits
 * - Bundle metadata and manifest
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Ops\Rate_Limiter;
use MCP_No_Headless\Services\Render_Service;
use MCP_No_Headless\Picasso\Picasso_Adapter;

class Export_Tools {

    /**
     * Export formats supported
     */
    private const FORMATS = ['json', 'markdown', 'full'];

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_export_bundle' => [
                'name' => 'ml_export_bundle',
                'description' => 'Export a publication with its dependencies as a portable bundle (JSON or Markdown).',
                'category' => 'MaryLink Export',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID of the root publication to export',
                        ],
                        'format' => [
                            'type' => 'string',
                            'enum' => ['json', 'markdown', 'full'],
                            'description' => 'Export format: json (structured), markdown (readable), full (both)',
                            'default' => 'json',
                        ],
                        'include_dependencies' => [
                            'type' => 'boolean',
                            'description' => 'Include all dependencies (styles, contents, linked)',
                            'default' => true,
                        ],
                        'include_metadata' => [
                            'type' => 'boolean',
                            'description' => 'Include metadata (authors, dates, steps)',
                            'default' => true,
                        ],
                        'include_comments' => [
                            'type' => 'boolean',
                            'description' => 'Include public comments',
                            'default' => false,
                        ],
                        'max_depth' => [
                            'type' => 'integer',
                            'description' => 'Maximum depth for dependency resolution',
                            'minimum' => 1,
                            'maximum' => 5,
                            'default' => 3,
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                ],
            ],
        ];
    }

    /**
     * Execute an export tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();
        $permissions = new Permission_Checker($user_id);

        if ($user_id <= 0) {
            return Tool_Response::error('authentication_required', 'You must be logged in to export publications.', $request_id);
        }

        switch ($tool) {
            case 'ml_export_bundle':
                return self::handle_export_bundle($args, $user_id, $permissions, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_export_bundle
     */
    private static function handle_export_bundle(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];
        $format = $args['format'] ?? 'json';
        $include_dependencies = (bool) ($args['include_dependencies'] ?? true);
        $include_metadata = (bool) ($args['include_metadata'] ?? true);
        $include_comments = (bool) ($args['include_comments'] ?? false);
        $max_depth = (int) ($args['max_depth'] ?? 3);

        // Validate format
        if (!in_array($format, self::FORMATS, true)) {
            return Tool_Response::error('validation_failed', 'Invalid format. Use: ' . implode(', ', self::FORMATS), $request_id);
        }

        // Check access
        if (!$permissions->can_see_publication($publication_id)) {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        // Check export limits (per day)
        $export_limit = Rate_Limiter::get_export_limit($user_id);
        if ($export_limit === 0) {
            return Tool_Response::error('plan_limit', 'Export feature not available on your plan.', $request_id);
        }

        // Check daily limit
        $today_exports = self::get_today_exports($user_id);
        if ($today_exports >= $export_limit) {
            return Tool_Response::error('limit_exceeded', sprintf('Daily export limit reached (%d/%d).', $today_exports, $export_limit), $request_id);
        }

        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        // Build bundle
        $start_time = microtime(true);

        try {
            $bundle = self::build_bundle(
                $publication_id,
                $permissions,
                $include_dependencies,
                $include_metadata,
                $include_comments,
                $max_depth
            );
        } catch (\Exception $e) {
            return Tool_Response::error('export_error', 'Failed to build bundle: ' . $e->getMessage(), $request_id);
        }

        $build_time_ms = (int) ((microtime(true) - $start_time) * 1000);

        // Record export
        self::record_export($user_id, $publication_id);

        // Format output based on requested format
        $result = [
            'manifest' => [
                'version' => '1.0',
                'format' => $format,
                'exported_at' => current_time('c'),
                'exported_by' => $user_id,
                'root_publication' => $publication_id,
                'total_items' => $bundle['stats']['total_items'],
                'build_time_ms' => $build_time_ms,
            ],
            'stats' => $bundle['stats'],
        ];

        if ($format === 'json' || $format === 'full') {
            $result['bundle'] = $bundle['data'];
        }

        if ($format === 'markdown' || $format === 'full') {
            $result['markdown'] = self::generate_markdown($bundle['data']);
        }

        // Add remaining exports info
        $result['limits'] = [
            'exports_today' => $today_exports + 1,
            'daily_limit' => $export_limit,
            'remaining' => $export_limit - ($today_exports + 1),
        ];

        return Tool_Response::ok($result, $request_id);
    }

    /**
     * Build the export bundle
     */
    private static function build_bundle(
        int $publication_id,
        Permission_Checker $permissions,
        bool $include_dependencies,
        bool $include_metadata,
        bool $include_comments,
        int $max_depth
    ): array {
        $visited = [];
        $stats = [
            'total_items' => 0,
            'by_type' => [],
            'dependencies_resolved' => 0,
        ];

        // Build root publication
        $root = self::export_publication(
            $publication_id,
            $permissions,
            $include_metadata,
            $include_comments
        );

        if (!$root) {
            throw new \Exception('Failed to export root publication');
        }

        $visited[$publication_id] = true;
        $stats['total_items']++;
        $type = $root['type'] ?? 'publication';
        $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;

        // Resolve dependencies
        if ($include_dependencies) {
            $root['dependencies'] = self::resolve_dependencies(
                $publication_id,
                $permissions,
                $include_metadata,
                $max_depth,
                1,
                $visited,
                $stats
            );
        }

        return [
            'data' => $root,
            'stats' => $stats,
        ];
    }

    /**
     * Export a single publication
     */
    private static function export_publication(
        int $publication_id,
        Permission_Checker $permissions,
        bool $include_metadata,
        bool $include_comments
    ): ?array {
        if (!$permissions->can_see_publication($publication_id)) {
            return null;
        }

        $post = get_post($publication_id);
        if (!$post) {
            return null;
        }

        $content = Render_Service::prepare_content($post->post_content);

        $export = [
            'id' => $publication_id,
            'title' => $post->post_title,
            'type' => get_post_meta($publication_id, '_ml_publication_type', true) ?: 'publication',
            'content_html' => $content['content_html'],
            'content_text' => $content['content_text'],
        ];

        if ($include_metadata) {
            $export['metadata'] = [
                'author' => [
                    'id' => (int) $post->post_author,
                    'name' => get_the_author_meta('display_name', $post->post_author),
                ],
                'space_id' => (int) $post->post_parent,
                'step' => Picasso_Adapter::get_publication_step($publication_id),
                'created' => $post->post_date,
                'modified' => $post->post_modified,
                'status' => $post->post_status,
            ];

            // Categories and tags
            $categories = wp_get_post_categories($publication_id, ['fields' => 'names']);
            if (!empty($categories) && !is_wp_error($categories)) {
                $export['metadata']['categories'] = $categories;
            }

            $tags = wp_get_post_tags($publication_id, ['fields' => 'names']);
            if (!empty($tags) && !is_wp_error($tags)) {
                $export['metadata']['tags'] = $tags;
            }

            // Custom meta
            $custom_meta = [];
            $meta_keys = ['_ml_input_schema', '_ml_output_format', '_ml_instruction'];
            foreach ($meta_keys as $key) {
                $value = get_post_meta($publication_id, $key, true);
                if (!empty($value)) {
                    $custom_meta[str_replace('_ml_', '', $key)] = $value;
                }
            }
            if (!empty($custom_meta)) {
                $export['metadata']['custom'] = $custom_meta;
            }
        }

        if ($include_comments) {
            $comments = get_comments([
                'post_id' => $publication_id,
                'status' => 'approve',
                'type' => 'comment',
                'number' => 50,
            ]);

            $export['comments'] = array_map(function ($comment) {
                return [
                    'id' => (int) $comment->comment_ID,
                    'author' => $comment->comment_author,
                    'content' => $comment->comment_content,
                    'date' => $comment->comment_date,
                ];
            }, $comments);
        }

        return $export;
    }

    /**
     * Resolve dependencies recursively
     */
    private static function resolve_dependencies(
        int $publication_id,
        Permission_Checker $permissions,
        bool $include_metadata,
        int $max_depth,
        int $current_depth,
        array &$visited,
        array &$stats
    ): array {
        if ($current_depth > $max_depth) {
            return [];
        }

        $deps = [
            'styles' => [],
            'contents' => [],
            'linked' => [],
        ];

        // Get linked styles
        $style_ids = Picasso_Adapter::get_tool_linked_styles($publication_id);
        foreach ($style_ids as $style_id) {
            if (isset($visited[$style_id])) {
                $deps['styles'][] = ['ref' => $style_id, 'already_exported' => true];
                continue;
            }

            $export = self::export_publication($style_id, $permissions, $include_metadata, false);
            if ($export) {
                $visited[$style_id] = true;
                $stats['total_items']++;
                $stats['dependencies_resolved']++;
                $type = $export['type'] ?? 'style';
                $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;

                // Recursive
                $export['dependencies'] = self::resolve_dependencies(
                    $style_id,
                    $permissions,
                    $include_metadata,
                    $max_depth,
                    $current_depth + 1,
                    $visited,
                    $stats
                );

                $deps['styles'][] = $export;
            }
        }

        // Get linked contents
        $content_ids = Picasso_Adapter::get_tool_linked_contents($publication_id);
        foreach ($content_ids as $content_id) {
            if (isset($visited[$content_id])) {
                $deps['contents'][] = ['ref' => $content_id, 'already_exported' => true];
                continue;
            }

            $export = self::export_publication($content_id, $permissions, $include_metadata, false);
            if ($export) {
                $visited[$content_id] = true;
                $stats['total_items']++;
                $stats['dependencies_resolved']++;
                $type = $export['type'] ?? 'content';
                $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;

                $export['dependencies'] = self::resolve_dependencies(
                    $content_id,
                    $permissions,
                    $include_metadata,
                    $max_depth,
                    $current_depth + 1,
                    $visited,
                    $stats
                );

                $deps['contents'][] = $export;
            }
        }

        // Get other linked
        $linked = get_post_meta($publication_id, '_ml_linked_publications', true);
        if (is_array($linked)) {
            foreach ($linked as $linked_id) {
                $linked_id = (int) $linked_id;
                if (in_array($linked_id, $style_ids, true) || in_array($linked_id, $content_ids, true)) {
                    continue;
                }
                if (isset($visited[$linked_id])) {
                    $deps['linked'][] = ['ref' => $linked_id, 'already_exported' => true];
                    continue;
                }

                $export = self::export_publication($linked_id, $permissions, $include_metadata, false);
                if ($export) {
                    $visited[$linked_id] = true;
                    $stats['total_items']++;
                    $stats['dependencies_resolved']++;
                    $type = $export['type'] ?? 'publication';
                    $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;

                    $export['dependencies'] = self::resolve_dependencies(
                        $linked_id,
                        $permissions,
                        $include_metadata,
                        $max_depth,
                        $current_depth + 1,
                        $visited,
                        $stats
                    );

                    $deps['linked'][] = $export;
                }
            }
        }

        // Clean empty arrays
        return array_filter($deps, fn($arr) => !empty($arr));
    }

    /**
     * Generate markdown representation
     */
    private static function generate_markdown(array $data, int $level = 1): string {
        $md = '';
        $prefix = str_repeat('#', min($level, 6));

        // Title
        $md .= "{$prefix} {$data['title']}\n\n";

        // Metadata
        if (!empty($data['metadata'])) {
            $md .= "**Type:** {$data['type']}\n";
            if (!empty($data['metadata']['author'])) {
                $md .= "**Author:** {$data['metadata']['author']['name']}\n";
            }
            if (!empty($data['metadata']['step'])) {
                $md .= "**Step:** {$data['metadata']['step']}\n";
            }
            $md .= "\n";
        }

        // Content
        $md .= $data['content_text'] ?? '';
        $md .= "\n\n";

        // Dependencies
        if (!empty($data['dependencies'])) {
            $md .= "---\n\n";

            foreach (['styles', 'contents', 'linked'] as $dep_type) {
                if (empty($data['dependencies'][$dep_type])) {
                    continue;
                }

                $label = ucfirst($dep_type);
                $md .= "{$prefix}# {$label}\n\n";

                foreach ($data['dependencies'][$dep_type] as $dep) {
                    if (isset($dep['already_exported'])) {
                        $md .= "- [Reference to #{$dep['ref']}]\n";
                    } else {
                        $md .= self::generate_markdown($dep, $level + 1);
                    }
                }
            }
        }

        return $md;
    }

    /**
     * Get today's export count for user
     */
    private static function get_today_exports(int $user_id): int {
        $key = 'mcpnh_exports_' . date('Y-m-d') . '_' . $user_id;
        return (int) get_transient($key);
    }

    /**
     * Record an export
     */
    private static function record_export(int $user_id, int $publication_id): void {
        $key = 'mcpnh_exports_' . date('Y-m-d') . '_' . $user_id;
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, DAY_IN_SECONDS);
    }

    /**
     * Check if available
     */
    public static function is_available(): bool {
        return post_type_exists('publication');
    }
}
