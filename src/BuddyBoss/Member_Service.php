<?php
/**
 * Member Service - BuddyBoss/BuddyPress member operations
 *
 * Provides read-only access to member profiles with data minimization.
 * Only exposes public profile information.
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\BuddyBoss;

class Member_Service {

    private int $user_id;
    private const CACHE_TTL = 120; // 2 minutes cache
    private const MAX_LIMIT = 20;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
    }

    /**
     * Check if BuddyBoss/BuddyPress members is available
     */
    public static function is_available(): bool {
        return function_exists('bp_core_get_users') && function_exists('bp_is_active') && bp_is_active('members');
    }

    /**
     * Search members
     *
     * @param string $query Search query
     * @param array $filters Filters: friends_only, group_id
     * @param int $limit Max results
     * @param int $page Page number
     * @return array
     */
    public function search_members(string $query = '', array $filters = [], int $limit = 10, int $page = 1): array {
        if (!self::is_available()) {
            return ['error' => 'buddyboss_not_available', 'members' => []];
        }

        $limit = min($limit, self::MAX_LIMIT);
        $cache_key = 'mcpnh_members_' . md5($this->user_id . $query . serialize($filters) . $limit . $page);

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
            'type' => 'active', // Order by last activity
        ];

        // Filter: friends only
        if (!empty($filters['friends_only']) && function_exists('friends_get_friend_user_ids')) {
            $friend_ids = friends_get_friend_user_ids($this->user_id);
            if (empty($friend_ids)) {
                return ['members' => [], 'total' => 0, 'page' => $page, 'has_more' => false];
            }
            $args['include'] = $friend_ids;
        }

        // Filter: members of specific group
        if (!empty($filters['group_id'])) {
            $group_service = new Group_Service($this->user_id);
            if (!$group_service->can_see_group($filters['group_id'])) {
                return ['members' => [], 'total' => 0, 'page' => $page, 'has_more' => false];
            }

            // Get group member IDs
            $group_members = groups_get_group_members([
                'group_id' => $filters['group_id'],
                'per_page' => 9999,
                'exclude_admins_mods' => false,
            ]);

            $member_ids = wp_list_pluck($group_members['members'] ?? [], 'ID');
            if (empty($member_ids)) {
                return ['members' => [], 'total' => 0, 'page' => $page, 'has_more' => false];
            }
            $args['include'] = $member_ids;
        }

        // Execute query
        $members_query = bp_core_get_users($args);

        // Format results
        $formatted = [];
        $index = 1;
        foreach ($members_query['users'] ?? [] as $member) {
            $formatted[] = $this->format_member($member, $index++);
        }

        $result = [
            'members' => $formatted,
            'total' => $members_query['total'] ?? 0,
            'page' => $page,
            'per_page' => $limit,
            'has_more' => ($page * $limit) < ($members_query['total'] ?? 0),
            'next_actions' => [
                'Tapez "membre N" pour voir le profil du membre N',
            ],
        ];

        // Cache result
        set_transient($cache_key, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Fetch a single member profile
     *
     * @param int $member_id User ID
     * @return array Member data or error
     */
    public function get_member(int $member_id): array {
        if (!self::is_available()) {
            return ['error' => 'buddyboss_not_available'];
        }

        $user = get_userdata($member_id);

        if (!$user) {
            return ['error' => 'not_found', 'message' => 'Member not found'];
        }

        // Check if profile is visible
        if (!$this->can_see_profile($member_id)) {
            return ['error' => 'not_found', 'message' => 'Member not found'];
        }

        return [
            'member' => $this->format_member_full($user),
        ];
    }

    /**
     * Check if current user can see a member's profile
     */
    public function can_see_profile(int $member_id): bool {
        // Own profile: always visible
        if ($member_id === $this->user_id) {
            return true;
        }

        // Admin: can see all
        if (current_user_can('manage_options')) {
            return true;
        }

        // Check BP profile visibility settings (if exists)
        if (function_exists('bp_user_can')) {
            // BuddyBoss has granular privacy settings
            return bp_user_can($this->user_id, 'bp_xprofile_view_field', ['field_id' => 'all', 'user_id' => $member_id]);
        }

        // Default: public profiles are visible
        return true;
    }

    /**
     * Format member for list view (data minimization)
     */
    private function format_member($member, int $index): array {
        $user_id = is_object($member) ? ($member->ID ?? $member->id) : $member;

        return [
            'index' => $index,
            'id' => (int) $user_id,
            'display_name' => bp_core_get_user_displayname($user_id),
            'username' => bp_core_get_username($user_id),
            'url' => bp_core_get_user_domain($user_id),
            'avatar_url' => bp_core_fetch_avatar([
                'item_id' => $user_id,
                'type' => 'thumb',
                'html' => false,
            ]),
            'last_activity' => bp_get_user_last_activity($user_id),
            'is_friend' => $this->is_friend($user_id),
        ];
    }

    /**
     * Format member for full view
     */
    private function format_member_full($user): array {
        $user_id = $user->ID;

        $data = [
            'id' => (int) $user_id,
            'display_name' => bp_core_get_user_displayname($user_id),
            'username' => bp_core_get_username($user_id),
            'url' => bp_core_get_user_domain($user_id),
            'avatar_url' => bp_core_fetch_avatar([
                'item_id' => $user_id,
                'type' => 'full',
                'html' => false,
            ]),
            'registered' => $user->user_registered,
            'last_activity' => bp_get_user_last_activity($user_id),
        ];

        // Public bio (if available and visible)
        $bio = $this->get_public_bio($user_id);
        if ($bio) {
            $data['bio'] = $bio;
        }

        // Friend status
        $data['friendship'] = $this->get_friendship_status($user_id);

        // Common groups (only public ones)
        $common_groups = $this->get_common_groups($user_id);
        if (!empty($common_groups)) {
            $data['common_groups'] = $common_groups;
        }

        // Member's group count
        $data['groups_count'] = (int) bp_get_user_meta($user_id, 'total_group_count', true);

        $data['next_actions'] = [];

        // Suggest actions based on relationship
        if ($data['friendship']['status'] === 'not_friends') {
            $data['next_actions'][] = 'Envoyer une demande d\'ami';
        }

        if (!empty($common_groups)) {
            $data['next_actions'][] = 'ml_group_fetch(' . $common_groups[0]['id'] . ') pour voir un groupe commun';
        }

        return $data;
    }

    /**
     * Get public bio for a user
     */
    private function get_public_bio(int $user_id): ?string {
        // Try xProfile bio field first (BuddyBoss)
        if (function_exists('xprofile_get_field_data')) {
            $bio = xprofile_get_field_data('Bio', $user_id);
            if ($bio) {
                return wp_trim_words(strip_tags($bio), 50);
            }

            // Try alternate field names
            $bio = xprofile_get_field_data('About Me', $user_id);
            if ($bio) {
                return wp_trim_words(strip_tags($bio), 50);
            }
        }

        // Fallback to WP user description
        $user = get_userdata($user_id);
        if ($user && !empty($user->description)) {
            return wp_trim_words($user->description, 50);
        }

        return null;
    }

    /**
     * Check if member is a friend
     */
    private function is_friend(int $user_id): bool {
        if (!function_exists('friends_check_friendship')) {
            return false;
        }

        if ($user_id === $this->user_id) {
            return false; // Can't be friends with yourself
        }

        return friends_check_friendship($this->user_id, $user_id);
    }

    /**
     * Get friendship status with a user
     */
    private function get_friendship_status(int $user_id): array {
        if ($user_id === $this->user_id) {
            return ['status' => 'self'];
        }

        if (!function_exists('friends_check_friendship_status')) {
            return ['status' => 'unknown'];
        }

        $status = friends_check_friendship_status($this->user_id, $user_id);

        return [
            'status' => $status ?: 'not_friends',
            'is_friend' => $status === 'is_friend',
        ];
    }

    /**
     * Get common groups between current user and target user
     */
    private function get_common_groups(int $user_id): array {
        if (!function_exists('groups_get_user_groups')) {
            return [];
        }

        // Get both users' groups
        $my_groups = groups_get_user_groups($this->user_id);
        $their_groups = groups_get_user_groups($user_id);

        $my_group_ids = $my_groups['groups'] ?? [];
        $their_group_ids = $their_groups['groups'] ?? [];

        // Find intersection
        $common_ids = array_intersect($my_group_ids, $their_group_ids);

        if (empty($common_ids)) {
            return [];
        }

        // Get group details (only public ones)
        $common = [];
        foreach (array_slice($common_ids, 0, 5) as $group_id) { // Limit to 5
            $group = groups_get_group($group_id);
            if ($group && $group->status === 'public') {
                $common[] = [
                    'id' => (int) $group->id,
                    'name' => $group->name,
                    'url' => bp_get_group_permalink($group),
                ];
            }
        }

        return $common;
    }
}
