<?php
/**
 * Duplicate Tools - MCP tools for publication duplication (tool-map v1)
 *
 * Tools:
 * - ml_duplicate_publication: Duplicate a publication with options (prepare/commit)
 *
 * TICKET T2.2: Publication duplication
 * Features:
 * - Prepare/commit flow for safe duplication
 * - Copy options: include_dependencies, target_space, new_title
 * - Dependency chain duplication
 * - Link preservation or reset
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Picasso\Picasso_Adapter;
use MCP_No_Headless\Services\Render_Service;

class Duplicate_Tools {

    /**
     * Session prefix for duplicate operations
     */
    private const SESSION_PREFIX = 'mcpnh_dup_';

    /**
     * Session TTL in seconds (5 minutes)
     */
    private const SESSION_TTL = 300;

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_duplicate_publication' => [
                'name' => 'ml_duplicate_publication',
                'description' => 'Duplicate a publication with options. Use stage=prepare to preview, stage=commit to execute.',
                'category' => 'MaryLink Duplication',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID of the publication to duplicate',
                        ],
                        'stage' => [
                            'type' => 'string',
                            'enum' => ['prepare', 'commit'],
                            'description' => 'Stage: prepare (preview) or commit (execute)',
                            'default' => 'prepare',
                        ],
                        'session_id' => [
                            'type' => 'string',
                            'description' => 'Session ID from prepare stage (required for commit)',
                        ],
                        'new_title' => [
                            'type' => 'string',
                            'description' => 'Title for the duplicate (default: "Copy of [original]")',
                        ],
                        'target_space_id' => [
                            'type' => 'integer',
                            'description' => 'Space ID to create duplicate in (default: same space)',
                        ],
                        'include_dependencies' => [
                            'type' => 'boolean',
                            'description' => 'Also duplicate linked styles/contents (default: false)',
                            'default' => false,
                        ],
                        'preserve_links' => [
                            'type' => 'boolean',
                            'description' => 'Preserve links to original dependencies instead of duplicating (default: true)',
                            'default' => true,
                        ],
                        'copy_meta' => [
                            'type' => 'boolean',
                            'description' => 'Copy custom meta fields (default: true)',
                            'default' => true,
                        ],
                        'reset_step' => [
                            'type' => 'boolean',
                            'description' => 'Reset to first workflow step (default: true)',
                            'default' => true,
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => false,
                ],
            ],
        ];
    }

    /**
     * Execute a duplicate tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();
        $permissions = new Permission_Checker($user_id);

        if ($user_id <= 0) {
            return Tool_Response::error('authentication_required', 'You must be logged in to duplicate publications.', $request_id);
        }

        switch ($tool) {
            case 'ml_duplicate_publication':
                return self::handle_duplicate_publication($args, $user_id, $permissions, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_duplicate_publication
     */
    private static function handle_duplicate_publication(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];
        $stage = $args['stage'] ?? 'prepare';

        // Check source publication access
        if (!$permissions->can_see_publication($publication_id)) {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        if ($stage === 'prepare') {
            return self::prepare_duplicate($publication_id, $post, $args, $user_id, $permissions, $request_id);
        } elseif ($stage === 'commit') {
            return self::commit_duplicate($publication_id, $args, $user_id, $permissions, $request_id);
        } else {
            return Tool_Response::error('validation_failed', 'Invalid stage. Use "prepare" or "commit".', $request_id);
        }
    }

    /**
     * Prepare duplicate - show preview
     */
    private static function prepare_duplicate(int $publication_id, \WP_Post $post, array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        // Get options with defaults
        $new_title = $args['new_title'] ?? sprintf('Copy of %s', $post->post_title);
        $target_space_id = (int) ($args['target_space_id'] ?? $post->post_parent);
        $include_dependencies = (bool) ($args['include_dependencies'] ?? false);
        $preserve_links = (bool) ($args['preserve_links'] ?? true);
        $copy_meta = (bool) ($args['copy_meta'] ?? true);
        $reset_step = (bool) ($args['reset_step'] ?? true);

        // Validate target space
        if ($target_space_id > 0) {
            if (!$permissions->can_see_space($target_space_id)) {
                return Tool_Response::error('access_denied', 'Cannot access target space', $request_id);
            }
            if (!$permissions->can_publish_in_space($target_space_id)) {
                return Tool_Response::error('access_denied', 'Cannot create publications in target space', $request_id);
            }
        }

        // Get dependencies that will be affected
        $dependencies_info = [];
        if ($include_dependencies && !$preserve_links) {
            $style_ids = Picasso_Adapter::get_tool_linked_styles($publication_id);
            $content_ids = Picasso_Adapter::get_tool_linked_contents($publication_id);

            foreach ($style_ids as $style_id) {
                if ($permissions->can_see_publication($style_id)) {
                    $style_post = get_post($style_id);
                    if ($style_post) {
                        $dependencies_info[] = [
                            'id' => $style_id,
                            'title' => $style_post->post_title,
                            'type' => 'style',
                            'action' => 'will_duplicate',
                        ];
                    }
                }
            }

            foreach ($content_ids as $content_id) {
                if ($permissions->can_see_publication($content_id)) {
                    $content_post = get_post($content_id);
                    if ($content_post) {
                        $dependencies_info[] = [
                            'id' => $content_id,
                            'title' => $content_post->post_title,
                            'type' => 'content',
                            'action' => 'will_duplicate',
                        ];
                    }
                }
            }
        } elseif (!$preserve_links) {
            // Not duplicating dependencies, links will be removed
            $style_ids = Picasso_Adapter::get_tool_linked_styles($publication_id);
            $content_ids = Picasso_Adapter::get_tool_linked_contents($publication_id);

            foreach (array_merge($style_ids, $content_ids) as $dep_id) {
                $dep_post = get_post($dep_id);
                if ($dep_post) {
                    $dependencies_info[] = [
                        'id' => $dep_id,
                        'title' => $dep_post->post_title,
                        'action' => 'link_will_be_removed',
                    ];
                }
            }
        } else {
            // Preserve links - they'll point to same targets
            $style_ids = Picasso_Adapter::get_tool_linked_styles($publication_id);
            $content_ids = Picasso_Adapter::get_tool_linked_contents($publication_id);

            foreach (array_merge($style_ids, $content_ids) as $dep_id) {
                $dep_post = get_post($dep_id);
                if ($dep_post) {
                    $dependencies_info[] = [
                        'id' => $dep_id,
                        'title' => $dep_post->post_title,
                        'action' => 'link_preserved',
                    ];
                }
            }
        }

        // Get target step
        $target_step = null;
        if ($reset_step && $target_space_id > 0) {
            $steps = Picasso_Adapter::get_space_steps($target_space_id);
            if (!empty($steps)) {
                $target_step = $steps[0]['name'];
            }
        } else {
            $target_step = Picasso_Adapter::get_publication_step($publication_id);
        }

        // Create session
        $session_data = [
            'user_id' => $user_id,
            'source_id' => $publication_id,
            'new_title' => $new_title,
            'target_space_id' => $target_space_id,
            'include_dependencies' => $include_dependencies,
            'preserve_links' => $preserve_links,
            'copy_meta' => $copy_meta,
            'reset_step' => $reset_step,
            'target_step' => $target_step,
            'created_at' => time(),
        ];

        $session_id = self::create_session($session_data);

        // Build preview
        $target_space = null;
        if ($target_space_id > 0) {
            $space_post = get_post($target_space_id);
            if ($space_post) {
                $target_space = [
                    'id' => $target_space_id,
                    'title' => $space_post->post_title,
                ];
            }
        }

        $preview = [
            'source' => [
                'id' => $publication_id,
                'title' => $post->post_title,
                'type' => self::get_publication_type($publication_id),
                'space_id' => (int) $post->post_parent,
            ],
            'duplicate' => [
                'title' => $new_title,
                'target_space' => $target_space,
                'step' => $target_step,
            ],
            'options' => [
                'include_dependencies' => $include_dependencies,
                'preserve_links' => $preserve_links,
                'copy_meta' => $copy_meta,
                'reset_step' => $reset_step,
            ],
        ];

        if (!empty($dependencies_info)) {
            $preview['dependencies'] = $dependencies_info;
        }

        // Estimate content size
        $preview['content_size'] = strlen($post->post_content);

        return Tool_Response::ok([
            'stage' => 'prepare',
            'session_id' => $session_id,
            'expires_in' => self::SESSION_TTL,
            'preview' => $preview,
            'next_action' => [
                'tool' => 'ml_duplicate_publication',
                'args' => [
                    'publication_id' => $publication_id,
                    'stage' => 'commit',
                    'session_id' => $session_id,
                ],
                'hint' => 'Call commit to create the duplicate.',
            ],
        ], $request_id);
    }

    /**
     * Commit duplicate - execute duplication
     */
    private static function commit_duplicate(int $publication_id, array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        // Validate session
        $session_id = $args['session_id'] ?? '';
        $session = self::validate_session($session_id, $user_id);

        if (!$session) {
            return Tool_Response::error(
                'session_expired',
                'Session expired or invalid. Please run prepare stage again.',
                $request_id
            );
        }

        // Validate session matches
        if (($session['source_id'] ?? 0) !== $publication_id) {
            return Tool_Response::error(
                'session_mismatch',
                'Session does not match request.',
                $request_id
            );
        }

        // Get source post
        $source_post = get_post($publication_id);
        if (!$source_post) {
            return Tool_Response::error('not_found', 'Source publication not found', $request_id);
        }

        // Re-validate permissions (target space might have changed)
        $target_space_id = (int) ($session['target_space_id'] ?? $source_post->post_parent);
        if ($target_space_id > 0 && !$permissions->can_publish_in_space($target_space_id)) {
            return Tool_Response::error('access_denied', 'Cannot create publications in target space', $request_id);
        }

        // Create duplicate post
        $new_post_data = [
            'post_title' => $session['new_title'],
            'post_content' => $source_post->post_content,
            'post_status' => 'draft', // Always start as draft
            'post_type' => 'publication',
            'post_author' => $user_id,
            'post_parent' => $target_space_id,
        ];

        // Copy excerpt if exists
        if (!empty($source_post->post_excerpt)) {
            $new_post_data['post_excerpt'] = $source_post->post_excerpt;
        }

        $new_post_id = wp_insert_post($new_post_data, true);

        if (is_wp_error($new_post_id)) {
            return Tool_Response::error('creation_failed', 'Failed to create duplicate: ' . $new_post_id->get_error_message(), $request_id);
        }

        // Copy meta if requested
        $meta_copied = [];
        if ($session['copy_meta']) {
            $meta_copied = self::copy_meta($publication_id, $new_post_id, $session);
        }

        // Handle dependencies
        $dependencies_result = self::handle_dependencies($publication_id, $new_post_id, $session, $user_id, $permissions);

        // Set step
        if (!empty($session['target_step'])) {
            update_post_meta($new_post_id, '_ml_step', $session['target_step']);
        }

        // Copy thumbnail if exists
        $thumbnail_id = get_post_thumbnail_id($publication_id);
        if ($thumbnail_id) {
            set_post_thumbnail($new_post_id, $thumbnail_id);
        }

        // Copy taxonomies
        $taxonomies = get_object_taxonomies('publication');
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($publication_id, $taxonomy, ['fields' => 'ids']);
            if (!is_wp_error($terms) && !empty($terms)) {
                wp_set_object_terms($new_post_id, $terms, $taxonomy);
            }
        }

        // Clean up session
        self::cleanup_session($session_id);

        // Build result
        $new_post = get_post($new_post_id);

        return Tool_Response::ok([
            'stage' => 'commit',
            'success' => true,
            'duplicate' => [
                'id' => $new_post_id,
                'title' => $new_post->post_title,
                'url' => get_permalink($new_post_id),
                'edit_url' => get_edit_post_link($new_post_id, 'raw'),
                'space_id' => $target_space_id,
                'step' => $session['target_step'],
                'status' => 'draft',
            ],
            'source' => [
                'id' => $publication_id,
                'title' => $source_post->post_title,
            ],
            'meta_copied' => count($meta_copied),
            'dependencies' => $dependencies_result,
            'message' => sprintf('Successfully duplicated "%s" as "%s".', $source_post->post_title, $new_post->post_title),
        ], $request_id);
    }

    /**
     * Copy meta fields from source to duplicate
     */
    private static function copy_meta(int $source_id, int $target_id, array $session): array {
        $copied = [];

        // Meta keys to skip
        $skip_keys = [
            '_edit_lock',
            '_edit_last',
            '_wp_page_template',
            '_ml_step', // Handled separately
            '_ml_linked_styles', // Handled in dependencies
            '_ml_linked_publications',
            '_ml_tool_contents',
        ];

        $source_meta = get_post_meta($source_id);

        foreach ($source_meta as $key => $values) {
            // Skip internal WordPress meta
            if (in_array($key, $skip_keys, true)) {
                continue;
            }

            // Skip private meta (starts with underscore) unless it's a known ML key
            if (strpos($key, '_') === 0 && strpos($key, '_ml_') !== 0) {
                continue;
            }

            foreach ($values as $value) {
                $unserialized = maybe_unserialize($value);
                add_post_meta($target_id, $key, $unserialized);
                $copied[] = $key;
            }
        }

        return array_unique($copied);
    }

    /**
     * Handle dependency duplication or linking
     */
    private static function handle_dependencies(int $source_id, int $target_id, array $session, int $user_id, Permission_Checker $permissions): array {
        $result = [
            'styles_linked' => 0,
            'contents_linked' => 0,
            'styles_duplicated' => 0,
            'contents_duplicated' => 0,
        ];

        $style_ids = Picasso_Adapter::get_tool_linked_styles($source_id);
        $content_ids = Picasso_Adapter::get_tool_linked_contents($source_id);

        if ($session['preserve_links']) {
            // Just copy the links as-is
            if (!empty($style_ids)) {
                update_post_meta($target_id, '_ml_linked_styles', $style_ids);
                $result['styles_linked'] = count($style_ids);
            }
            if (!empty($content_ids)) {
                update_post_meta($target_id, '_ml_tool_contents', $content_ids);
                $result['contents_linked'] = count($content_ids);
            }
        } elseif ($session['include_dependencies']) {
            // Duplicate the dependencies
            $new_style_ids = [];
            $new_content_ids = [];

            foreach ($style_ids as $style_id) {
                if ($permissions->can_see_publication($style_id)) {
                    $new_id = self::duplicate_single_publication($style_id, $user_id, $session['target_space_id']);
                    if ($new_id) {
                        $new_style_ids[] = $new_id;
                        $result['styles_duplicated']++;
                    }
                }
            }

            foreach ($content_ids as $content_id) {
                if ($permissions->can_see_publication($content_id)) {
                    $new_id = self::duplicate_single_publication($content_id, $user_id, $session['target_space_id']);
                    if ($new_id) {
                        $new_content_ids[] = $new_id;
                        $result['contents_duplicated']++;
                    }
                }
            }

            if (!empty($new_style_ids)) {
                update_post_meta($target_id, '_ml_linked_styles', $new_style_ids);
            }
            if (!empty($new_content_ids)) {
                update_post_meta($target_id, '_ml_tool_contents', $new_content_ids);
            }
        }
        // else: don't copy any links

        return $result;
    }

    /**
     * Duplicate a single publication (for dependencies)
     */
    private static function duplicate_single_publication(int $source_id, int $user_id, int $target_space_id): ?int {
        $source_post = get_post($source_id);
        if (!$source_post) {
            return null;
        }

        $new_post_data = [
            'post_title' => sprintf('Copy of %s', $source_post->post_title),
            'post_content' => $source_post->post_content,
            'post_status' => 'draft',
            'post_type' => 'publication',
            'post_author' => $user_id,
            'post_parent' => $target_space_id > 0 ? $target_space_id : $source_post->post_parent,
        ];

        $new_id = wp_insert_post($new_post_data, true);

        if (is_wp_error($new_id)) {
            return null;
        }

        // Copy type meta
        $type = get_post_meta($source_id, '_ml_publication_type', true);
        if ($type) {
            update_post_meta($new_id, '_ml_publication_type', $type);
        }

        return $new_id;
    }

    /**
     * Get publication type
     */
    private static function get_publication_type(int $publication_id): string {
        $type = get_post_meta($publication_id, '_ml_publication_type', true);
        return $type ?: 'publication';
    }

    /**
     * Create session for prepare/commit flow
     */
    private static function create_session(array $data): string {
        $session_id = 'dup_' . bin2hex(random_bytes(16));
        set_transient(self::SESSION_PREFIX . $session_id, $data, self::SESSION_TTL);
        return $session_id;
    }

    /**
     * Validate session
     */
    private static function validate_session(string $session_id, int $user_id): ?array {
        if (empty($session_id) || strpos($session_id, 'dup_') !== 0) {
            return null;
        }

        $session = get_transient(self::SESSION_PREFIX . $session_id);
        if (!$session || !is_array($session)) {
            return null;
        }

        if (($session['user_id'] ?? 0) !== $user_id) {
            return null;
        }

        return $session;
    }

    /**
     * Clean up session
     */
    private static function cleanup_session(string $session_id): void {
        delete_transient(self::SESSION_PREFIX . $session_id);
    }

    /**
     * Check if available
     */
    public static function is_available(): bool {
        return post_type_exists('publication');
    }
}
