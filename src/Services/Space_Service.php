<?php
/**
 * Space Service - Business logic for spaces
 *
 * Handles:
 * - Space listing with permission filtering
 * - Space details retrieval
 * - Workflow steps management
 * - Space permissions summary
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\MCP\Permission_Checker;
use MCP_No_Headless\Picasso\Picasso_Adapter;

class Space_Service {

    private int $user_id;
    private Permission_Checker $permissions;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
    }

    /**
     * List spaces accessible by user
     *
     * @param array $filters Filters (search, etc.)
     * @param int $offset Pagination offset
     * @param int $limit Pagination limit
     * @return array [spaces, has_more, total_count]
     */
    public function list_spaces(array $filters = [], int $offset = 0, int $limit = 20): array {
        // Get accessible space IDs
        $accessible_ids = $this->permissions->get_user_spaces();

        if (empty($accessible_ids)) {
            return [
                'spaces' => [],
                'has_more' => false,
                'total_count' => 0,
            ];
        }

        // Build query
        $query_args = [
            'post_type' => 'space',
            'post_status' => 'publish',
            'post__in' => $accessible_ids,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        // Apply search filter
        if (!empty($filters['search'])) {
            $query_args['s'] = sanitize_text_field($filters['search']);
        }

        // Query with pagination
        $result = Query_Service::query_posts($query_args, $offset, $limit);

        // Map to output format
        $spaces = array_map(function ($post) {
            return $this->format_space_summary($post);
        }, $result['posts']);

        return [
            'spaces' => $spaces,
            'has_more' => $result['has_more'],
            'total_count' => count($accessible_ids),
        ];
    }

    /**
     * Get single space details
     *
     * @param int $space_id Space ID
     * @return array|null Space data or null if not found/accessible
     */
    public function get_space(int $space_id): ?array {
        // Check permission
        if (!$this->permissions->can_see_space($space_id)) {
            return null;
        }

        $post = get_post($space_id);
        if (!$post || $post->post_type !== 'space') {
            return null;
        }

        return $this->format_space_full($post);
    }

    /**
     * Get workflow steps for a space
     *
     * @param int $space_id Space ID
     * @return array|null Steps or null if not accessible
     */
    public function get_steps(int $space_id): ?array {
        if (!$this->permissions->can_see_space($space_id)) {
            return null;
        }

        return Picasso_Adapter::get_space_steps($space_id);
    }

    /**
     * Get permissions summary for user on space/step
     *
     * @param int $space_id Space ID
     * @param string|null $step_name Optional step name
     * @return array|null Permissions or null if not accessible
     */
    public function get_permissions_summary(int $space_id, ?string $step_name = null): ?array {
        if (!$this->permissions->can_see_space($space_id)) {
            return null;
        }

        return $this->permissions->get_permissions_summary($space_id, $step_name);
    }

    /**
     * Count publications in a space
     *
     * @param int $space_id Space ID
     * @return int Count
     */
    public function count_publications(int $space_id): int {
        return Query_Service::count_posts([
            'post_type' => 'publication',
            'post_parent' => $space_id,
            'post_status' => 'publish',
        ]);
    }

    /**
     * Format space for list output (summary)
     */
    private function format_space_summary(\WP_Post $post): array {
        $thumbnail_id = get_post_thumbnail_id($post->ID);

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'excerpt' => Render_Service::excerpt_from_html($post->post_content, 160),
            'url' => get_permalink($post->ID),
            'publication_count' => $this->count_publications($post->ID),
            'thumbnail' => $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'thumbnail') : null,
            'date' => Render_Service::format_date($post->post_date),
        ];
    }

    /**
     * Format space for full output (details)
     */
    private function format_space_full(\WP_Post $post): array {
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        $content = Render_Service::prepare_content($post->post_content);

        $space = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content_html' => $content['content_html'],
            'content_text' => $content['content_text'],
            'excerpt' => Render_Service::excerpt_from_html($post->post_content, 240),
            'url' => get_permalink($post->ID),
            'thumbnail' => $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'medium') : null,
            'date' => Render_Service::format_date($post->post_date),
            'date_modified' => Render_Service::format_date($post->post_modified),
            'publication_count' => $this->count_publications($post->ID),
            'steps' => Picasso_Adapter::get_space_steps($post->ID),
        ];

        // Add meta
        $meta_keys = [
            '_ml_space_type' => 'type',
            '_ml_space_visibility' => 'visibility',
            '_ml_hide_ratings' => 'hide_ratings',
        ];

        foreach ($meta_keys as $key => $name) {
            $value = get_post_meta($post->ID, $key, true);
            if ($value !== '') {
                $space[$name] = $value;
            }
        }

        // Add permissions for this user
        $space['my_permissions'] = $this->permissions->get_permissions_summary($post->ID);

        return $space;
    }

    /**
     * Get space ID for a publication
     */
    public static function get_publication_space_id(int $publication_id): ?int {
        $post = get_post($publication_id);
        if (!$post) {
            return null;
        }

        // Try post_parent first
        if ($post->post_parent > 0) {
            return (int) $post->post_parent;
        }

        // Try meta
        return Picasso_Adapter::get_publication_space($publication_id);
    }

    /**
     * Check if space post type exists
     */
    public static function is_available(): bool {
        return post_type_exists('space');
    }
}
