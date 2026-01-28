<?php
/**
 * Group Service - BuddyBoss/BuddyPress group operations
 *
 * Provides read-only access to groups with strict permission checks.
 * Uses BuddyBoss functions directly for reliability.
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\BuddyBoss;

class Group_Service {

    private int $user_id;
    private const CACHE_TTL = 60; // 60 seconds cache
    private const MAX_LIMIT = 20;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
    }

    /**
     * Check if BuddyBoss/BuddyPress groups are available
     */
    public static function is_available(): bool {
        return function_exists('groups_get_groups') && function_exists('bp_is_active') && bp_is_active('groups');
    }

    /**
     * Search groups with filters
     *
     * @param string $query Search query
     * @param array $filters Filters: my_only, status, has_space_id
     * @param int $limit Max results
     * @param int $page Page number
     * @return array
     */
    public function search_groups(string $query = '', array $filters = [], int $limit = 10, int $page = 1): array {
        if (!self::is_available()) {
            return ['error' => 'buddyboss_not_available', 'groups' => []];
        }

        $limit = min($limit, self::MAX_LIMIT);
        $cache_key = 'mcpnh_groups_' . md5($this->user_id . $query . serialize($filters) . $limit . $page);

        // Check cache
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Build query args
        $args = [
            'per_page' => $limit,
            'page' => $page,
            'search_terms' => $query,
            'show_hidden' => false, // Never show hidden in search
            'orderby' => 'last_activity',
            'order' => 'DESC',
        ];

        // Filter: my groups only
        if (!empty($filters['my_only'])) {
            $args['user_id'] = $this->user_id;
        }

        // Filter: by status
        if (!empty($filters['status'])) {
            $status = $filters['status'];
            if ($status === 'public') {
                $args['status'] = ['public'];
            } elseif ($status === 'private') {
                // Private groups: only show if user is member
                $args['status'] = ['private'];
                $args['user_id'] = $this->user_id; // Force member filter
            }
            // Hidden groups never appear in search
        }

        // Get groups
        $groups_query = groups_get_groups($args);
        $groups = $groups_query['groups'] ?? [];
        $total = $groups_query['total'] ?? 0;

        // Format results with permission checks
        $formatted = [];
        $index = 1;
        foreach ($groups as $group) {
            // Double-check permission
            if (!$this->can_see_group($group)) {
                continue;
            }

            $formatted[] = $this->format_group($group, $index++);
        }

        $result = [
            'groups' => $formatted,
            'total' => $total,
            'page' => $page,
            'per_page' => $limit,
            'has_more' => ($page * $limit) < $total,
        ];

        // Cache result
        set_transient($cache_key, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Fetch a single group by ID
     *
     * @param int $group_id Group ID
     * @return array Group data or error
     */
    public function get_group(int $group_id): array {
        if (!self::is_available()) {
            return ['error' => 'buddyboss_not_available'];
        }

        $group = groups_get_group($group_id);

        if (!$group || !$group->id) {
            return ['error' => 'not_found', 'message' => 'Group not found'];
        }

        // Permission check - CRITICAL anti-leak
        if (!$this->can_see_group($group)) {
            // Return same error as not found (anti-leak)
            return ['error' => 'not_found', 'message' => 'Group not found'];
        }

        return [
            'group' => $this->format_group_full($group),
        ];
    }

    /**
     * List group members
     *
     * @param int $group_id Group ID
     * @param int $page Page number
     * @param int $per_page Results per page
     * @return array
     */
    public function list_members(int $group_id, int $page = 1, int $per_page = 10): array {
        if (!self::is_available()) {
            return ['error' => 'buddyboss_not_available'];
        }

        $group = groups_get_group($group_id);

        // Permission check - must be able to see group AND list members
        if (!$group || !$this->can_see_group($group) || !$this->can_list_members($group)) {
            return ['error' => 'not_found', 'message' => 'Group not found'];
        }

        $per_page = min($per_page, self::MAX_LIMIT);

        // Get members using BP function
        $members_query = new \BP_Group_Member_Query([
            'group_id' => $group_id,
            'per_page' => $per_page,
            'page' => $page,
            'exclude_admins_mods' => false,
            'exclude_banned' => true,
        ]);

        $members = [];
        $index = 1;
        foreach ($members_query->results as $member) {
            $members[] = $this->format_member_minimal($member, $index++);
        }

        return [
            'group_id' => $group_id,
            'group_name' => $group->name,
            'members' => $members,
            'total' => $members_query->total_users,
            'page' => $page,
            'per_page' => $per_page,
            'has_more' => ($page * $per_page) < $members_query->total_users,
            'next_actions' => [
                'Tapez "membre N" pour voir le profil du membre N',
            ],
        ];
    }

    /**
     * Check if current user can see a group
     */
    public function can_see_group($group): bool {
        if (!$group) {
            return false;
        }

        // Normalize to object if ID passed
        if (is_numeric($group)) {
            $group = groups_get_group($group);
            if (!$group) {
                return false;
            }
        }

        $status = $group->status ?? 'public';

        // Public groups: visible to all
        if ($status === 'public') {
            return true;
        }

        // Private groups: visible to members (and admins)
        if ($status === 'private') {
            // Site admins can see
            if (current_user_can('manage_options')) {
                return true;
            }
            // Group members can see
            return groups_is_user_member($this->user_id, $group->id);
        }

        // Hidden groups: only members and site admins
        if ($status === 'hidden') {
            if (current_user_can('manage_options')) {
                return true;
            }
            return groups_is_user_member($this->user_id, $group->id);
        }

        return false;
    }

    /**
     * Check if user can list group members
     */
    private function can_list_members($group): bool {
        // Public groups: anyone can list members
        if ($group->status === 'public') {
            return true;
        }

        // Private/hidden: only members
        return groups_is_user_member($this->user_id, $group->id) || current_user_can('manage_options');
    }

    /**
     * Format group for list view (minimal)
     */
    private function format_group($group, int $index): array {
        return [
            'index' => $index,
            'id' => (int) $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'status' => $group->status,
            'description' => wp_trim_words(strip_tags($group->description), 20),
            'members_count' => (int) $group->total_member_count,
            'url' => bp_get_group_permalink($group),
            'is_member' => groups_is_user_member($this->user_id, $group->id),
        ];
    }

    /**
     * Format group for full view
     */
    private function format_group_full($group): array {
        $data = $this->format_group($group, 1);
        unset($data['index']);

        // Add more details
        $data['description_full'] = strip_tags($group->description);
        $data['date_created'] = $group->date_created;
        $data['last_activity'] = $group->last_activity ?? null;
        $data['creator_id'] = (int) $group->creator_id;

        // User's role in group
        if (groups_is_user_member($this->user_id, $group->id)) {
            $data['user_role'] = $this->get_user_group_role($group->id);
        }

        // Check for linked Space (if mapping exists)
        $space_id = $this->get_linked_space_id($group->id);
        if ($space_id) {
            $data['linked_space_id'] = $space_id;
        }

        $data['next_actions'] = [
            'ml_group_members(' . $group->id . ') pour voir les membres',
            'ml_activity_list(group_id:' . $group->id . ') pour voir les posts',
        ];

        return $data;
    }

    /**
     * Format member for minimal view (data minimization)
     */
    private function format_member_minimal($member, int $index): array {
        $user_id = is_object($member) ? ($member->user_id ?? $member->ID) : $member;
        $user = get_userdata($user_id);

        if (!$user) {
            return ['index' => $index, 'id' => $user_id, 'error' => 'user_not_found'];
        }

        return [
            'index' => $index,
            'id' => (int) $user_id,
            'display_name' => $user->display_name,
            'url' => bp_core_get_user_domain($user_id),
            'avatar_url' => bp_core_fetch_avatar([
                'item_id' => $user_id,
                'type' => 'thumb',
                'html' => false,
            ]),
        ];
    }

    /**
     * Get user's role in group
     */
    private function get_user_group_role(int $group_id): string {
        if (groups_is_user_admin($this->user_id, $group_id)) {
            return 'admin';
        }
        if (groups_is_user_mod($this->user_id, $group_id)) {
            return 'mod';
        }
        if (groups_is_user_member($this->user_id, $group_id)) {
            return 'member';
        }
        return 'none';
    }

    /**
     * Get linked Space ID for a group (if mapping exists)
     */
    private function get_linked_space_id(int $group_id): ?int {
        // Check group meta for linked space
        $space_id = groups_get_groupmeta($group_id, '_ml_linked_space_id', true);
        return $space_id ? (int) $space_id : null;
    }
}
