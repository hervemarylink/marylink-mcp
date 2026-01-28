<?php
/**
 * Chain Tools - MCP tools for publication chain resolution (tool-map v1)
 *
 * Tools:
 * - ml_get_publication_chain: Resolve full dependency chain for export/duplication
 *
 * TICKET T2.1: Publication chain resolution
 * Features:
 * - Recursive dependency resolution
 * - Circular reference detection
 * - Depth limit per plan
 * - Tree and flat output formats
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Render_Service;
use MCP_No_Headless\Ops\Rate_Limiter;
use MCP_No_Headless\Picasso\Picasso_Adapter;

class Chain_Tools {

    /**
     * Default depth limit if not determined by plan
     */
    private const DEFAULT_DEPTH_LIMIT = 5;

    /**
     * Session prefix for chain operations
     */
    private const SESSION_PREFIX = 'mcpnh_chain_';

    /**
     * Session TTL in seconds (5 minutes)
     */
    private const SESSION_TTL = 300;

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_get_publication_chain' => [
                'name' => 'ml_get_publication_chain',
                'description' => 'Resolve full dependency chain for a publication (styles, data, prompts, docs). Use for export/duplication preparation.',
                'category' => 'MaryLink Chain',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID of the root publication to resolve',
                        ],
                        'include_content' => [
                            'type' => 'boolean',
                            'description' => 'Include full content in results (default: false for summary only)',
                            'default' => false,
                        ],
                        'format' => [
                            'type' => 'string',
                            'enum' => ['tree', 'flat'],
                            'description' => 'Output format: tree (nested hierarchy) or flat (deduplicated list)',
                            'default' => 'tree',
                        ],
                        'max_depth' => [
                            'type' => 'integer',
                            'description' => 'Maximum depth to resolve (overrides plan limit if lower)',
                            'minimum' => 1,
                            'maximum' => 10,
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
     * Execute a chain tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();
        $permissions = new Permission_Checker($user_id);

        if ($user_id <= 0) {
            return Tool_Response::error('authentication_required', 'You must be logged in to resolve chains.', $request_id);
        }

        switch ($tool) {
            case 'ml_get_publication_chain':
                return self::handle_get_publication_chain($args, $user_id, $permissions, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_get_publication_chain
     */
    private static function handle_get_publication_chain(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];
        $include_content = (bool) ($args['include_content'] ?? false);
        $format = $args['format'] ?? 'tree';
        $requested_max_depth = isset($args['max_depth']) ? (int) $args['max_depth'] : null;

        // Check root publication access
        if (!$permissions->can_see_publication($publication_id)) {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        // Get depth limit from plan
        $plan_depth = Rate_Limiter::get_chain_depth_limit($user_id);
        $max_depth = $plan_depth ?: self::DEFAULT_DEPTH_LIMIT;

        // Allow user to request lower depth
        if ($requested_max_depth !== null && $requested_max_depth < $max_depth) {
            $max_depth = $requested_max_depth;
        }

        // Start chain resolution
        $visited = [];
        $circular_refs = [];
        $start_time = microtime(true);

        try {
            $chain = self::resolve_chain(
                $publication_id,
                $permissions,
                $include_content,
                $max_depth,
                0,
                $visited,
                $circular_refs
            );
        } catch (\Exception $e) {
            return Tool_Response::error('chain_error', 'Failed to resolve chain: ' . $e->getMessage(), $request_id);
        }

        $resolution_time_ms = (int) ((microtime(true) - $start_time) * 1000);

        // Build response based on format
        if ($format === 'flat') {
            $result = self::flatten_chain($chain, $visited, $permissions, $include_content);
        } else {
            $result = [
                'root' => $chain,
            ];
        }

        // Add metadata
        $result['meta'] = [
            'root_id' => $publication_id,
            'format' => $format,
            'max_depth' => $max_depth,
            'items_resolved' => count($visited),
            'circular_refs_detected' => count($circular_refs),
            'resolution_time_ms' => $resolution_time_ms,
        ];

        // Include circular references warning if any
        if (!empty($circular_refs)) {
            $result['warnings'] = [
                'circular_references' => array_values($circular_refs),
            ];
        }

        return Tool_Response::ok($result, $request_id);
    }

    /**
     * Recursively resolve chain dependencies
     *
     * @param int $publication_id Publication to resolve
     * @param Permission_Checker $permissions Permission checker
     * @param bool $include_content Include full content
     * @param int $max_depth Maximum depth
     * @param int $current_depth Current depth level
     * @param array &$visited Visited IDs (for deduplication)
     * @param array &$circular_refs Detected circular references
     * @return array|null Publication chain node
     */
    private static function resolve_chain(
        int $publication_id,
        Permission_Checker $permissions,
        bool $include_content,
        int $max_depth,
        int $current_depth,
        array &$visited,
        array &$circular_refs
    ): ?array {
        // Circular reference detection
        if (in_array($publication_id, $visited, true)) {
            $circular_refs[$publication_id] = [
                'id' => $publication_id,
                'detected_at_depth' => $current_depth,
            ];
            return [
                'id' => $publication_id,
                'circular_ref' => true,
                'note' => 'Circular reference detected, skipping to prevent infinite loop',
            ];
        }

        // Permission check
        if (!$permissions->can_see_publication($publication_id)) {
            return [
                'id' => $publication_id,
                'access_denied' => true,
                'note' => 'No permission to access this publication',
            ];
        }

        // Get publication data
        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            return null;
        }

        // Mark as visited
        $visited[] = $publication_id;

        // Build node
        $node = [
            'id' => $publication_id,
            'title' => $post->post_title,
            'type' => self::get_publication_type($publication_id),
            'step' => Picasso_Adapter::get_publication_step($publication_id),
            'depth' => $current_depth,
        ];

        // Include content if requested
        if ($include_content) {
            $content = Render_Service::prepare_content($post->post_content);
            $node['content_text'] = $content['content_text'];
            $node['content_length'] = strlen($post->post_content);
        } else {
            $node['excerpt'] = Render_Service::excerpt_from_html($post->post_content, 160);
        }

        // Check depth limit before resolving children
        if ($current_depth >= $max_depth) {
            $node['depth_limit_reached'] = true;
            return $node;
        }

        // Resolve dependencies
        $node['dependencies'] = self::resolve_dependencies(
            $publication_id,
            $permissions,
            $include_content,
            $max_depth,
            $current_depth + 1,
            $visited,
            $circular_refs
        );

        return $node;
    }

    /**
     * Resolve all dependencies for a publication
     */
    private static function resolve_dependencies(
        int $publication_id,
        Permission_Checker $permissions,
        bool $include_content,
        int $max_depth,
        int $next_depth,
        array &$visited,
        array &$circular_refs
    ): array {
        $deps = [
            'styles' => [],
            'contents' => [],
            'linked' => [],
        ];

        // Get linked styles
        $style_ids = Picasso_Adapter::get_tool_linked_styles($publication_id);
        foreach ($style_ids as $style_id) {
            $resolved = self::resolve_chain(
                $style_id,
                $permissions,
                $include_content,
                $max_depth,
                $next_depth,
                $visited,
                $circular_refs
            );
            if ($resolved) {
                $deps['styles'][] = $resolved;
            }
        }

        // Get linked contents
        $content_ids = Picasso_Adapter::get_tool_linked_contents($publication_id);
        foreach ($content_ids as $content_id) {
            $resolved = self::resolve_chain(
                $content_id,
                $permissions,
                $include_content,
                $max_depth,
                $next_depth,
                $visited,
                $circular_refs
            );
            if ($resolved) {
                $deps['contents'][] = $resolved;
            }
        }

        // Get other linked publications
        $linked = get_post_meta($publication_id, '_ml_linked_publications', true);
        if (is_array($linked)) {
            foreach ($linked as $linked_id) {
                $linked_id = (int) $linked_id;
                // Avoid duplicating already resolved
                if (in_array($linked_id, $style_ids, true) || in_array($linked_id, $content_ids, true)) {
                    continue;
                }
                $resolved = self::resolve_chain(
                    $linked_id,
                    $permissions,
                    $include_content,
                    $max_depth,
                    $next_depth,
                    $visited,
                    $circular_refs
                );
                if ($resolved) {
                    $deps['linked'][] = $resolved;
                }
            }
        }

        return $deps;
    }

    /**
     * Flatten chain into deduplicated list grouped by type
     */
    private static function flatten_chain(array $chain, array $visited, Permission_Checker $permissions, bool $include_content): array {
        $flat = [
            'publications' => [],
            'by_type' => [
                'tool' => [],
                'prompt' => [],
                'style' => [],
                'data' => [],
                'doc' => [],
                'other' => [],
            ],
        ];

        // Get all publication details
        foreach ($visited as $pub_id) {
            $post = get_post($pub_id);
            if (!$post) continue;

            $type = self::get_publication_type($pub_id);
            $item = [
                'id' => $pub_id,
                'title' => $post->post_title,
                'type' => $type,
                'step' => Picasso_Adapter::get_publication_step($pub_id),
                'url' => get_permalink($pub_id),
            ];

            if ($include_content) {
                $content = Render_Service::prepare_content($post->post_content);
                $item['content_text'] = $content['content_text'];
            } else {
                $item['excerpt'] = Render_Service::excerpt_from_html($post->post_content, 160);
            }

            $flat['publications'][] = $item;

            // Categorize by type
            $type_key = in_array($type, ['tool', 'prompt', 'style', 'data', 'doc']) ? $type : 'other';
            $flat['by_type'][$type_key][] = $item;
        }

        // Clean up empty type arrays
        foreach ($flat['by_type'] as $type => $items) {
            if (empty($items)) {
                unset($flat['by_type'][$type]);
            }
        }

        return $flat;
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
