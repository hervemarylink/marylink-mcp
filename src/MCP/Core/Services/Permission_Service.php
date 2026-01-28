<?php
/**
 * Permission Service - Centralized permission checking for MCP V3
 *
 * Handles read/write permissions for publications, spaces, tools.
 * Integrates with WordPress capabilities and BuddyPress groups.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Services;

class Permission_Service {

    const VERSION = '3.0.0';

    // Permission actions
    const ACTION_READ = 'read';
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_EXECUTE = 'execute';

    // Resource types
    const RESOURCE_PUBLICATION = 'publication';
    const RESOURCE_SPACE = 'space';
    const RESOURCE_TOOL = 'tool';
    const RESOURCE_USER = 'user';
    const RESOURCE_GROUP = 'group';

    /**
     * Check if user can perform action on resource
     *
     * @param int $user_id User ID
     * @param string $action Action to check
     * @param string $resource_type Type of resource
     * @param int|null $resource_id Resource ID (null for create)
     * @param array $context Additional context (space_id, etc.)
     * @return array ['allowed' => bool, 'reason' => string]
     */
    public static function check(
        int $user_id,
        string $action,
        string $resource_type,
        ?int $resource_id = null,
        array $context = []
    ): array {
        // Admin can do everything
        if (self::is_admin($user_id)) {
            return ['allowed' => true, 'reason' => 'admin'];
        }

        // Dispatch to specific checker
        return match ($resource_type) {
            self::RESOURCE_PUBLICATION => self::check_publication_permission($user_id, $action, $resource_id, $context),
            self::RESOURCE_SPACE => self::check_space_permission($user_id, $action, $resource_id, $context),
            self::RESOURCE_TOOL => self::check_tool_permission($user_id, $action, $resource_id, $context),
            self::RESOURCE_USER => self::check_user_permission($user_id, $action, $resource_id, $context),
            self::RESOURCE_GROUP => self::check_group_permission($user_id, $action, $resource_id, $context),
            default => ['allowed' => false, 'reason' => 'unknown_resource_type'],
        };
    }

    /**
     * Check if user can read a resource
     */
    public static function can_read(int $user_id, string $resource_type, int $resource_id, array $context = []): bool {
        $result = self::check($user_id, self::ACTION_READ, $resource_type, $resource_id, $context);
        return $result['allowed'];
    }

    /**
     * Check if user can create a resource
     */
    public static function can_create(int $user_id, string $resource_type, array $context = []): bool {
        $result = self::check($user_id, self::ACTION_CREATE, $resource_type, null, $context);
        return $result['allowed'];
    }

    /**
     * Check if user can update a resource
     */
    public static function can_update(int $user_id, string $resource_type, int $resource_id, array $context = []): bool {
        $result = self::check($user_id, self::ACTION_UPDATE, $resource_type, $resource_id, $context);
        return $result['allowed'];
    }

    /**
     * Check if user can delete a resource
     */
    public static function can_delete(int $user_id, string $resource_type, int $resource_id, array $context = []): bool {
        $result = self::check($user_id, self::ACTION_DELETE, $resource_type, $resource_id, $context);
        return $result['allowed'];
    }

    /**
     * Filter array of items to only those user can read
     *
     * @param int $user_id User ID
     * @param string $resource_type Resource type
     * @param array $items Items with 'id' key
     * @return array Filtered items
     */
    public static function filter_readable(int $user_id, string $resource_type, array $items): array {
        if (self::is_admin($user_id)) {
            return $items;
        }

        return array_filter($items, function ($item) use ($user_id, $resource_type) {
            $id = $item['id'] ?? $item['ID'] ?? null;
            if (!$id) {
                return false;
            }
            return self::can_read($user_id, $resource_type, (int) $id);
        });
    }

    // =========================================================================
    // PUBLICATION PERMISSIONS
    // =========================================================================

    private static function check_publication_permission(int $user_id, string $action, ?int $publication_id, array $context): array {
        switch ($action) {
            case self::ACTION_READ:
                return self::check_publication_read($user_id, $publication_id, $context);

            case self::ACTION_CREATE:
                return self::check_publication_create($user_id, $context);

            case self::ACTION_UPDATE:
            case self::ACTION_DELETE:
                return self::check_publication_edit($user_id, $publication_id, $context);

            default:
                return ['allowed' => false, 'reason' => 'unknown_action'];
        }
    }

    private static function check_publication_read(int $user_id, ?int $publication_id, array $context): array {
        if (!$publication_id) {
            return ['allowed' => false, 'reason' => 'no_publication_id'];
        }

        $post = get_post($publication_id);
        if (!$post) {
            return ['allowed' => false, 'reason' => 'publication_not_found'];
        }

        // Public posts are readable by everyone
        if ($post->post_status === 'publish') {
            // Check space restriction
            $space_id = get_post_meta($publication_id, '_ml_space_id', true);
            if ($space_id && !self::is_space_member($user_id, (int) $space_id)) {
                // Space-restricted content
                $visibility = get_post_meta($publication_id, '_ml_visibility', true);
                if ($visibility === 'space') {
                    return ['allowed' => false, 'reason' => 'space_member_required'];
                }
            }
            return ['allowed' => true, 'reason' => 'public'];
        }

        // Private/draft: only author or editor can read
        if ((int) $post->post_author === $user_id) {
            return ['allowed' => true, 'reason' => 'author'];
        }

        // Check co-authors
        $co_authors = get_post_meta($publication_id, '_ml_co_authors', true);
        if (is_array($co_authors) && in_array($user_id, $co_authors)) {
            return ['allowed' => true, 'reason' => 'co_author'];
        }

        // Check space moderator
        $space_id = get_post_meta($publication_id, '_ml_space_id', true);
        if ($space_id && self::is_space_moderator($user_id, (int) $space_id)) {
            return ['allowed' => true, 'reason' => 'space_moderator'];
        }

        return ['allowed' => false, 'reason' => 'not_authorized'];
    }

    private static function check_publication_create(int $user_id, array $context): array {
        // Check basic capability
        if (!user_can($user_id, 'publish_posts') && !user_can($user_id, 'edit_posts')) {
            return ['allowed' => false, 'reason' => 'no_publish_capability'];
        }

        // Check space permission if space_id provided
        $space_id = $context['space_id'] ?? null;
        if ($space_id) {
            if (!self::can_post_to_space($user_id, (int) $space_id)) {
                return ['allowed' => false, 'reason' => 'cannot_post_to_space'];
            }
        }

        return ['allowed' => true, 'reason' => 'has_capability'];
    }

    private static function check_publication_edit(int $user_id, ?int $publication_id, array $context): array {
        if (!$publication_id) {
            return ['allowed' => false, 'reason' => 'no_publication_id'];
        }

        $post = get_post($publication_id);
        if (!$post) {
            return ['allowed' => false, 'reason' => 'publication_not_found'];
        }

        // Author can always edit their own content
        if ((int) $post->post_author === $user_id) {
            return ['allowed' => true, 'reason' => 'author'];
        }

        // Co-authors can edit
        $co_authors = get_post_meta($publication_id, '_ml_co_authors', true);
        if (is_array($co_authors) && in_array($user_id, $co_authors)) {
            return ['allowed' => true, 'reason' => 'co_author'];
        }

        // Space moderator can edit
        $space_id = get_post_meta($publication_id, '_ml_space_id', true);
        if ($space_id && self::is_space_moderator($user_id, (int) $space_id)) {
            return ['allowed' => true, 'reason' => 'space_moderator'];
        }

        // Team member with edit permission
        $team_can_edit = get_post_meta($publication_id, '_ml_team_can_edit', true);
        if ($team_can_edit) {
            $team = get_post_meta($publication_id, '_ml_team', true);
            if (is_array($team) && in_array($user_id, $team)) {
                return ['allowed' => true, 'reason' => 'team_member'];
            }
        }

        // WordPress capability
        if (user_can($user_id, 'edit_others_posts')) {
            return ['allowed' => true, 'reason' => 'editor_capability'];
        }

        return ['allowed' => false, 'reason' => 'not_authorized'];
    }

    // =========================================================================
    // SPACE PERMISSIONS
    // =========================================================================

    private static function check_space_permission(int $user_id, string $action, ?int $space_id, array $context): array {
        switch ($action) {
            case self::ACTION_READ:
                return self::check_space_read($user_id, $space_id);

            case self::ACTION_CREATE:
                return self::check_space_create($user_id, $context);

            case self::ACTION_UPDATE:
            case self::ACTION_DELETE:
                return self::check_space_admin($user_id, $space_id);

            default:
                return ['allowed' => false, 'reason' => 'unknown_action'];
        }
    }

    private static function check_space_read(int $user_id, ?int $space_id): array {
        if (!$space_id) {
            return ['allowed' => false, 'reason' => 'no_space_id'];
        }

        if (!function_exists('groups_get_group')) {
            return ['allowed' => true, 'reason' => 'no_buddypress'];
        }

        $group = groups_get_group($space_id);
        if (!$group || !$group->id) {
            return ['allowed' => false, 'reason' => 'space_not_found'];
        }

        // Public groups are readable
        if ($group->status === 'public') {
            return ['allowed' => true, 'reason' => 'public_space'];
        }

        // Private/hidden: must be member
        if (self::is_space_member($user_id, $space_id)) {
            return ['allowed' => true, 'reason' => 'member'];
        }

        return ['allowed' => false, 'reason' => 'not_member'];
    }

    private static function check_space_create(int $user_id, array $context): array {
        if (!function_exists('bp_user_can_create_groups')) {
            return ['allowed' => true, 'reason' => 'no_buddypress'];
        }

        if (bp_user_can_create_groups()) {
            return ['allowed' => true, 'reason' => 'can_create_groups'];
        }

        return ['allowed' => false, 'reason' => 'cannot_create_groups'];
    }

    private static function check_space_admin(int $user_id, ?int $space_id): array {
        if (!$space_id) {
            return ['allowed' => false, 'reason' => 'no_space_id'];
        }

        if (self::is_space_admin($user_id, $space_id)) {
            return ['allowed' => true, 'reason' => 'space_admin'];
        }

        return ['allowed' => false, 'reason' => 'not_space_admin'];
    }

    // =========================================================================
    // TOOL PERMISSIONS
    // =========================================================================

    private static function check_tool_permission(int $user_id, string $action, ?int $tool_id, array $context): array {
        switch ($action) {
            case self::ACTION_READ:
            case self::ACTION_EXECUTE:
                return self::check_tool_access($user_id, $tool_id, $context);

            case self::ACTION_CREATE:
            case self::ACTION_UPDATE:
            case self::ACTION_DELETE:
                return self::check_tool_edit($user_id, $tool_id, $context);

            default:
                return ['allowed' => false, 'reason' => 'unknown_action'];
        }
    }

    private static function check_tool_access(int $user_id, ?int $tool_id, array $context): array {
        if (!$tool_id) {
            return ['allowed' => false, 'reason' => 'no_tool_id'];
        }

        $post = get_post($tool_id);
        if (!$post || $post->post_type !== 'ml_tool') {
            return ['allowed' => false, 'reason' => 'tool_not_found'];
        }

        // Published tools are accessible
        if ($post->post_status === 'publish') {
            // Check role restriction
            $allowed_roles = get_post_meta($tool_id, '_ml_allowed_roles', true);
            if (!empty($allowed_roles) && is_array($allowed_roles)) {
                $user = get_userdata($user_id);
                if ($user && empty(array_intersect($user->roles, $allowed_roles))) {
                    return ['allowed' => false, 'reason' => 'role_restricted'];
                }
            }

            // Check space restriction
            $space_restriction = get_post_meta($tool_id, '_ml_space_restriction', true);
            if (!empty($space_restriction) && is_array($space_restriction)) {
                $is_member_of_any = false;
                foreach ($space_restriction as $space_id) {
                    if (self::is_space_member($user_id, (int) $space_id)) {
                        $is_member_of_any = true;
                        break;
                    }
                }
                if (!$is_member_of_any) {
                    return ['allowed' => false, 'reason' => 'space_restricted'];
                }
            }

            return ['allowed' => true, 'reason' => 'published'];
        }

        // Draft tools: only author can access
        if ((int) $post->post_author === $user_id) {
            return ['allowed' => true, 'reason' => 'author'];
        }

        return ['allowed' => false, 'reason' => 'not_published'];
    }

    private static function check_tool_edit(int $user_id, ?int $tool_id, array $context): array {
        // Create: check capability
        if (!$tool_id) {
            if (user_can($user_id, 'edit_posts')) {
                return ['allowed' => true, 'reason' => 'can_edit_posts'];
            }
            return ['allowed' => false, 'reason' => 'cannot_create_tools'];
        }

        $post = get_post($tool_id);
        if (!$post) {
            return ['allowed' => false, 'reason' => 'tool_not_found'];
        }

        // Author can edit
        if ((int) $post->post_author === $user_id) {
            return ['allowed' => true, 'reason' => 'author'];
        }

        // Editor capability
        if (user_can($user_id, 'edit_others_posts')) {
            return ['allowed' => true, 'reason' => 'editor_capability'];
        }

        return ['allowed' => false, 'reason' => 'not_authorized'];
    }

    // =========================================================================
    // USER PERMISSIONS
    // =========================================================================

    private static function check_user_permission(int $user_id, string $action, ?int $target_user_id, array $context): array {
        switch ($action) {
            case self::ACTION_READ:
                // Users can read public profile info
                return ['allowed' => true, 'reason' => 'public_profile'];

            case self::ACTION_UPDATE:
                // Users can only update themselves
                if ($target_user_id === $user_id) {
                    return ['allowed' => true, 'reason' => 'self'];
                }
                if (user_can($user_id, 'edit_users')) {
                    return ['allowed' => true, 'reason' => 'edit_users_capability'];
                }
                return ['allowed' => false, 'reason' => 'not_authorized'];

            default:
                return ['allowed' => false, 'reason' => 'unknown_action'];
        }
    }

    // =========================================================================
    // GROUP PERMISSIONS
    // =========================================================================

    private static function check_group_permission(int $user_id, string $action, ?int $group_id, array $context): array {
        // Same as space for now
        return self::check_space_permission($user_id, $action, $group_id, $context);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Check if user is admin
     */
    public static function is_admin(int $user_id): bool {
        return user_can($user_id, 'manage_options');
    }

    /**
     * Check if user is member of a space/group
     */
    public static function get_user_space_ids(int $user_id): array {
        // Canonical spaces are WP posts of type 'space' (Picasso).
        // Membership is stored in post meta arrays: _space_members, _space_contributors, _space_moderators (and author = admin/owner).
        $ids = [];

        $owned = get_posts([
            'post_type' => 'space',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'author' => $user_id,
        ]);
        if (!is_wp_error($owned) && !empty($owned)) {
            $ids = array_merge($ids, $owned);
        }

        $meta_keys = ['_space_moderators', '_space_contributors', '_space_members'];
        foreach ($meta_keys as $key) {
            $q = get_posts([
                'post_type' => 'space',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => $key,
                        'value' => '"' . $user_id . '"',
                        'compare' => 'LIKE',
                    ],
                ],
            ]);
            if (!is_wp_error($q) && !empty($q)) {
                $ids = array_merge($ids, $q);
            }
        }

        $ids = array_values(array_unique(array_map('absint', $ids)));
        sort($ids);
        return $ids;
    }

    public static function is_space_member(int $user_id, int $space_id): bool {
        // Canonical: CPT 'space'
        $post = get_post($space_id);
        if ($post && $post->post_type === 'space') {
            if ((int) $post->post_author === (int) $user_id) {
                return true;
            }
            $members = (array) get_post_meta($space_id, '_space_members', true);
            $contributors = (array) get_post_meta($space_id, '_space_contributors', true);
            $moderators = (array) get_post_meta($space_id, '_space_moderators', true);
            $all = array_merge($members, $contributors, $moderators);
            $all = array_map('intval', $all);
            return in_array((int) $user_id, $all, true);
        }

        // Legacy: BuddyBoss group id
        if (!function_exists('groups_is_user_member')) {
            return true; // No BuddyPress = no restriction
        }
        return groups_is_user_member($user_id, $space_id);
    }

    /**
     * Check if user is moderator of a space/group
     */
    public static function is_space_moderator(int $user_id, int $space_id): bool {
        $post = get_post($space_id);
        if ($post && $post->post_type === 'space') {
            if ((int) $post->post_author === (int) $user_id) {
                return true;
            }
            $moderators = (array) get_post_meta($space_id, '_space_moderators', true);
            $moderators = array_map('intval', $moderators);
            return in_array((int) $user_id, $moderators, true);
        }

        if (!function_exists('groups_is_user_mod')) {
            return false;
        }
        return groups_is_user_mod($user_id, $space_id);
    }

    /**
     * Check if user is admin of a space/group
     */
    public static function is_space_admin(int $user_id, int $space_id): bool {
        $post = get_post($space_id);
        if ($post && $post->post_type === 'space') {
            return ((int) $post->post_author === (int) $user_id);
        }

        if (!function_exists('groups_is_user_admin')) {
            return false;
        }
        return groups_is_user_admin($user_id, $space_id);
    }

    /**
     * Check if user can post to space
     */
    public static function can_post_to_space(int $user_id, int $space_id): bool {
        // WordPress admins can post anywhere
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        $post = get_post($space_id);
        if ($post && $post->post_type === 'space') {
            // In spaces (CPT), membership is the gating factor.
            return self::is_space_member($user_id, $space_id) || self::is_space_moderator($user_id, $space_id) || self::is_space_admin($user_id, $space_id);
        }

        // Legacy: BuddyBoss group permissions
        if (!function_exists('groups_get_group')) {
            return true;
        }

        $group = groups_get_group($space_id);
        if (!$group || !$group->id) {
            return false;
        }

        // Must be member to post
        if (!self::is_space_member($user_id, $space_id)) {
            return false;
        }

        // Check if activity posting is enabled for members
        if (function_exists('groups_get_groupmeta')) {
            $posting = groups_get_groupmeta($space_id, 'allow_member_posting');
            if ($posting === '0' && !self::is_space_moderator($user_id, $space_id) && !self::is_space_admin($user_id, $space_id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Log permission denial for audit
     */
    public static function log_denial(int $user_id, string $action, string $resource_type, ?int $resource_id, string $reason): void {
        $log_data = [
            'user_id' => $user_id,
            'action' => $action,
            'resource_type' => $resource_type,
            'resource_id' => $resource_id,
            'reason' => $reason,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ];

        do_action('mcp_permission_denied', $log_data);

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[MCP Permission Denied] ' . wp_json_encode($log_data));
        }
    }
}
