<?php
/**
 * Activity Service - BuddyBoss/BuddyPress activity feed operations
 *
 * Provides read-only access to activity feed with strict permission checks.
 * Uses BuddyBoss functions directly for reliability.
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\BuddyBoss;

class Activity_Service {

    private int $user_id;
    private Group_Service $group_service;
    private const CACHE_TTL = 30; // 30 seconds cache (activity changes frequently)
    private const MAX_LIMIT = 20;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->group_service = new Group_Service($user_id);
    }

    /**
     * Check if BuddyBoss/BuddyPress activity is available
     */
    public static function is_available(): bool {
        return function_exists('bp_activity_get') && function_exists('bp_is_active') && bp_is_active('activity');
    }

    /**
     * List activity feed
     *
     * @param array $args Arguments: group_id, scope, page, per_page, search
     * @return array
     */
    public function list_activity(array $args = []): array {
        if (!self::is_available()) {
            return ['error' => 'buddyboss_not_available', 'activities' => []];
        }

        $group_id = $args['group_id'] ?? null;
        $scope = $args['scope'] ?? 'all';
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = min(self::MAX_LIMIT, max(1, (int) ($args['per_page'] ?? 10)));
        $search = $args['search'] ?? '';

        // If group_id specified, check permission first
        if ($group_id) {
            if (!$this->group_service->can_see_group($group_id)) {
                // Anti-leak: return empty, not error
                return [
                    'activities' => [],
                    'total' => 0,
                    'page' => $page,
                    'per_page' => $per_page,
                    'has_more' => false,
                ];
            }
        }

        // Cache key
        $cache_key = 'mcpnh_activity_' . md5($this->user_id . $group_id . $scope . $page . $per_page . $search);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Build BP activity query
        $bp_args = [
            'per_page' => $per_page,
            'page' => $page,
            'display_comments' => false, // Get comments separately
            'show_hidden' => false,
        ];

        // Scope handling
        switch ($scope) {
            case 'group':
                if ($group_id) {
                    $bp_args['filter'] = [
                        'object' => 'groups',
                        'primary_id' => $group_id,
                    ];
                }
                break;

            case 'friends':
                // Only activities from friends
                if (function_exists('friends_get_friend_user_ids')) {
                    $friend_ids = friends_get_friend_user_ids($this->user_id);
                    if (!empty($friend_ids)) {
                        $bp_args['filter'] = ['user_id' => $friend_ids];
                    } else {
                        // No friends, return empty
                        return ['activities' => [], 'total' => 0, 'page' => $page, 'has_more' => false];
                    }
                }
                break;

            case 'my':
                $bp_args['filter'] = ['user_id' => $this->user_id];
                break;

            case 'public':
                // Only public (non-group or public group activities)
                $bp_args['scope'] = 'sitewide';
                break;

            default:
                // 'all' - get all visible to user
                break;
        }

        // Group filter overrides scope
        if ($group_id && $scope !== 'group') {
            $bp_args['filter'] = [
                'object' => 'groups',
                'primary_id' => $group_id,
            ];
        }

        // Search filter
        if (!empty($search)) {
            $bp_args['search_terms'] = $search;
        }

        // Execute query
        $activities = bp_activity_get($bp_args);

        // Format results with permission filtering
        $formatted = [];
        $index = 1;
        foreach ($activities['activities'] ?? [] as $activity) {
            // Permission check
            if (!$this->can_see_activity($activity)) {
                continue;
            }

            $formatted[] = $this->format_activity($activity, $index++);
        }

        $total = $activities['total'] ?? 0;

        $result = [
            'activities' => $formatted,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'has_more' => ($page * $per_page) < $total,
            'scope' => $scope,
            'next_actions' => [
                'Tapez "ouvre N" pour voir l\'activite N en detail',
                'Tapez "comments N" pour voir les commentaires de N',
            ],
        ];

        if ($group_id) {
            $result['group_id'] = $group_id;
        }

        // Cache result
        set_transient($cache_key, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Fetch a single activity by ID
     *
     * @param int $activity_id Activity ID
     * @return array Activity data or error
     */
    public function get_activity(int $activity_id): array {
        if (!self::is_available()) {
            return ['error' => 'buddyboss_not_available'];
        }

        $activities = bp_activity_get([
            'in' => [$activity_id],
            'display_comments' => 'threaded',
            'show_hidden' => true, // Get it even if hidden, we'll check permissions
        ]);

        $activity = $activities['activities'][0] ?? null;

        if (!$activity) {
            return ['error' => 'not_found', 'message' => 'Activity not found'];
        }

        // Permission check - CRITICAL anti-leak
        if (!$this->can_see_activity($activity)) {
            return ['error' => 'not_found', 'message' => 'Activity not found'];
        }

        return [
            'activity' => $this->format_activity_full($activity),
        ];
    }

    /**
     * List comments for an activity
     *
     * @param int $activity_id Activity ID
     * @param int $page Page number
     * @param int $per_page Results per page
     * @return array
     */
    public function list_comments(int $activity_id, int $page = 1, int $per_page = 10): array {
        if (!self::is_available()) {
            return ['error' => 'buddyboss_not_available'];
        }

        // First, check if we can see the parent activity
        $activities = bp_activity_get([
            'in' => [$activity_id],
            'display_comments' => 'threaded',
            'show_hidden' => true,
        ]);

        $activity = $activities['activities'][0] ?? null;

        if (!$activity || !$this->can_see_activity($activity)) {
            return ['error' => 'not_found', 'message' => 'Activity not found'];
        }

        $per_page = min($per_page, self::MAX_LIMIT);

        // Get comments (they are children in BP)
        $comments = bp_activity_get([
            'filter' => [
                'object' => 'activity',
                'action' => 'activity_comment',
                'secondary_id' => $activity_id,
            ],
            'per_page' => $per_page,
            'page' => $page,
            'display_comments' => false,
        ]);

        $formatted = [];
        $index = 1;
        foreach ($comments['activities'] ?? [] as $comment) {
            $formatted[] = $this->format_comment($comment, $index++);
        }

        return [
            'activity_id' => $activity_id,
            'comments' => $formatted,
            'total' => $comments['total'] ?? 0,
            'page' => $page,
            'per_page' => $per_page,
            'has_more' => ($page * $per_page) < ($comments['total'] ?? 0),
        ];
    }

    /**
     * Check if current user can see an activity
     */
    public function can_see_activity($activity): bool {
        if (!$activity) {
            return false;
        }

        // If activity is hidden
        if (!empty($activity->hide_sitewide) && $activity->hide_sitewide) {
            // Only author and admins can see hidden activities
            if ($activity->user_id != $this->user_id && !current_user_can('manage_options')) {
                return false;
            }
        }

        // If activity is in a group, check group permissions
        if ($activity->component === 'groups' && !empty($activity->item_id)) {
            $group_id = (int) $activity->item_id;
            if (!$this->group_service->can_see_group($group_id)) {
                return false;
            }
        }

        // Check privacy setting (BuddyBoss has activity privacy)
        if (function_exists('bp_activity_user_can_read')) {
            return bp_activity_user_can_read($activity, $this->user_id);
        }

        return true;
    }

    /**
     * Format activity for list view
     */
    private function format_activity($activity, int $index): array {
        $user = get_userdata($activity->user_id);

        return [
            'index' => $index,
            'id' => (int) $activity->id,
            'type' => $activity->type,
            'component' => $activity->component,
            'action' => strip_tags($activity->action),
            'content' => wp_trim_words(strip_tags($activity->content), 30),
            'date' => $activity->date_recorded,
            'date_human' => bp_core_time_since($activity->date_recorded),
            'user' => [
                'id' => (int) $activity->user_id,
                'name' => $user ? $user->display_name : 'Unknown',
            ],
            'url' => bp_activity_get_permalink($activity->id),
            'comment_count' => (int) ($activity->comment_count ?? 0),
            'group_id' => $activity->component === 'groups' ? (int) $activity->item_id : null,
        ];
    }

    /**
     * Format activity for full view
     */
    private function format_activity_full($activity): array {
        $data = $this->format_activity($activity, 1);
        unset($data['index']);

        // Full content
        $data['content_full'] = $activity->content;

        // User details
        $data['user']['avatar'] = bp_core_fetch_avatar([
            'item_id' => $activity->user_id,
            'type' => 'thumb',
            'html' => false,
        ]);
        $data['user']['url'] = bp_core_get_user_domain($activity->user_id);

        // If in group, add group info
        if ($activity->component === 'groups' && !empty($activity->item_id)) {
            $group = groups_get_group($activity->item_id);
            if ($group) {
                $data['group'] = [
                    'id' => (int) $group->id,
                    'name' => $group->name,
                    'url' => bp_get_group_permalink($group),
                ];
            }
        }

        // Reactions/Likes (if BuddyBoss reactions are active)
        if (function_exists('bb_activity_get_reactions_count')) {
            $data['reactions_count'] = bb_activity_get_reactions_count($activity->id);
        }

        // Inline comments preview (first 3)
        if (!empty($activity->children)) {
            $comments_preview = [];
            $count = 0;
            foreach ($activity->children as $comment) {
                if ($count >= 3) break;
                $comments_preview[] = [
                    'id' => (int) $comment->id,
                    'content' => wp_trim_words(strip_tags($comment->content), 15),
                    'user_name' => bp_core_get_user_displayname($comment->user_id),
                ];
                $count++;
            }
            $data['comments_preview'] = $comments_preview;
        }

        $data['next_actions'] = [
            'ml_activity_comments(' . $activity->id . ') pour tous les commentaires',
        ];

        if ($activity->component === 'groups') {
            $data['next_actions'][] = 'ml_group_fetch(' . $activity->item_id . ') pour voir le groupe';
        }

        return $data;
    }

    /**
     * Format comment for list view
     */
    private function format_comment($comment, int $index): array {
        $user = get_userdata($comment->user_id);

        return [
            'index' => $index,
            'id' => (int) $comment->id,
            'content' => strip_tags($comment->content),
            'date' => $comment->date_recorded,
            'date_human' => bp_core_time_since($comment->date_recorded),
            'user' => [
                'id' => (int) $comment->user_id,
                'name' => $user ? $user->display_name : 'Unknown',
                'avatar' => bp_core_fetch_avatar([
                    'item_id' => $comment->user_id,
                    'type' => 'thumb',
                    'html' => false,
                ]),
            ],
            'parent_id' => (int) $comment->secondary_item_id,
        ];
    }

    // ============================================
    // WRITE OPERATIONS (Prepare/Commit Flow)
    // ============================================

    /**
     * Check if user can post activity in a group or globally
     *
     * @param int|null $group_id Optional group ID
     * @return bool
     */
    public function can_post_activity(?int $group_id = null): bool {
        if (!self::is_available()) {
            return false;
        }

        // User must be logged in
        if ($this->user_id <= 0) {
            return false;
        }

        // Global activity posting
        if (!$group_id) {
            // Check if user can post updates (BuddyBoss capability)
            if (function_exists('bp_current_user_can')) {
                return bp_current_user_can('bp_moderate') || bp_current_user_can('bp_activity_post');
            }
            return true; // Default: logged in users can post
        }

        // Group activity posting
        return $this->group_service->is_member($group_id);
    }

    /**
     * Check if user can comment on an activity
     *
     * @param int $activity_id Activity ID
     * @return bool
     */
    public function can_comment_activity(int $activity_id): bool {
        if (!self::is_available()) {
            return false;
        }

        if ($this->user_id <= 0) {
            return false;
        }

        // Get the activity
        $activities = bp_activity_get(['in' => [$activity_id], 'show_hidden' => true]);
        $activity = $activities['activities'][0] ?? null;

        if (!$activity) {
            return false;
        }

        // Must be able to see the activity
        if (!$this->can_see_activity($activity)) {
            return false;
        }

        // Check if comments are allowed (some activity types don't allow comments)
        if (function_exists('bp_activity_can_comment')) {
            return bp_activity_can_comment($activity);
        }

        return true;
    }

    /**
     * Prepare activity post (dry run, returns preview)
     *
     * @param string $content Content to post
     * @param int|null $group_id Optional group ID
     * @return array Prepared data or error
     */
    public function prepare_post_activity(string $content, ?int $group_id = null): array {
        // Permission check
        if (!$this->can_post_activity($group_id)) {
            if ($group_id && !$this->group_service->can_see_group($group_id)) {
                return ['error' => 'not_found', 'message' => 'Group not found'];
            }
            return ['error' => 'permission_denied', 'message' => 'You cannot post activity here'];
        }

        // Validate content
        $content = trim($content);
        if (empty($content)) {
            return ['error' => 'validation_failed', 'message' => 'Content cannot be empty'];
        }

        if (mb_strlen($content) > 10000) {
            return ['error' => 'validation_failed', 'message' => 'Content too long (max 10000 characters)'];
        }

        // Sanitize content
        $sanitized = wp_kses_post($content);

        // Generate idempotency key
        $idempotency_key = $this->generate_idempotency_key('post', $content, $group_id);

        // Check for duplicate
        if ($this->is_duplicate($idempotency_key)) {
            return ['error' => 'duplicate', 'message' => 'This activity was already posted'];
        }

        $user = get_userdata($this->user_id);

        $preview = [
            'stage' => 'prepared',
            'idempotency_key' => $idempotency_key,
            'action' => 'post_activity',
            'preview' => [
                'content' => $sanitized,
                'content_length' => mb_strlen($sanitized),
                'author' => [
                    'id' => $this->user_id,
                    'name' => $user ? $user->display_name : 'Unknown',
                ],
                'target' => $group_id ? 'group' : 'sitewide',
                'group_id' => $group_id,
            ],
            'warnings' => [],
        ];

        if ($group_id) {
            $group = groups_get_group($group_id);
            if ($group) {
                $preview['preview']['group_name'] = $group->name;
            }
        }

        // Check for @mentions
        if (preg_match_all('/@([a-zA-Z0-9_-]+)/', $content, $matches)) {
            $preview['preview']['mentions'] = $matches[1];
        }

        // Store prepared data in transient for commit
        set_transient('mcpnh_prep_' . $idempotency_key, $preview, 300); // 5 min expiry

        $preview['next_action'] = "ml_activity_post_commit(idempotency_key: \"{$idempotency_key}\")";

        return $preview;
    }

    /**
     * Commit activity post
     *
     * @param string $idempotency_key Key from prepare stage
     * @return array Result with activity data or error
     */
    public function commit_post_activity(string $idempotency_key): array {
        // Retrieve prepared data
        $prepared = get_transient('mcpnh_prep_' . $idempotency_key);

        if (!$prepared) {
            return ['error' => 'expired', 'message' => 'Prepared action expired. Please prepare again.'];
        }

        if ($prepared['action'] !== 'post_activity') {
            return ['error' => 'invalid_action', 'message' => 'Invalid action type'];
        }

        // Re-check permission
        $group_id = $prepared['preview']['group_id'] ?? null;
        if (!$this->can_post_activity($group_id)) {
            return ['error' => 'permission_denied', 'message' => 'Permission denied'];
        }

        // Check duplicate again
        if ($this->is_duplicate($idempotency_key, true)) {
            return ['error' => 'duplicate', 'message' => 'This activity was already posted'];
        }

        // Create the activity
        $activity_args = [
            'user_id' => $this->user_id,
            'content' => $prepared['preview']['content'],
            'component' => $group_id ? 'groups' : 'activity',
            'type' => $group_id ? 'activity_update' : 'activity_update',
        ];

        if ($group_id) {
            $activity_args['item_id'] = $group_id;
        }

        $activity_id = bp_activity_add($activity_args);

        if (!$activity_id) {
            return ['error' => 'creation_failed', 'message' => 'Failed to create activity'];
        }

        // Mark idempotency key as used
        $this->mark_used($idempotency_key, $activity_id);

        // Delete the prepared transient
        delete_transient('mcpnh_prep_' . $idempotency_key);

        // Get the created activity
        $activities = bp_activity_get(['in' => [$activity_id]]);
        $activity = $activities['activities'][0] ?? null;

        return [
            'stage' => 'committed',
            'success' => true,
            'activity' => $activity ? $this->format_activity($activity, 1) : ['id' => $activity_id],
            'message' => 'Activity posted successfully',
        ];
    }

    /**
     * Prepare activity comment (dry run)
     *
     * @param int $activity_id Activity ID to comment on
     * @param string $content Comment content
     * @return array Prepared data or error
     */
    public function prepare_comment_activity(int $activity_id, string $content): array {
        // Permission check
        if (!$this->can_comment_activity($activity_id)) {
            // Anti-leak: check if can see first
            $activities = bp_activity_get(['in' => [$activity_id], 'show_hidden' => true]);
            $activity = $activities['activities'][0] ?? null;

            if (!$activity || !$this->can_see_activity($activity)) {
                return ['error' => 'not_found', 'message' => 'Activity not found'];
            }
            return ['error' => 'permission_denied', 'message' => 'You cannot comment on this activity'];
        }

        // Validate content
        $content = trim($content);
        if (empty($content)) {
            return ['error' => 'validation_failed', 'message' => 'Comment cannot be empty'];
        }

        if (mb_strlen($content) > 5000) {
            return ['error' => 'validation_failed', 'message' => 'Comment too long (max 5000 characters)'];
        }

        $sanitized = wp_kses_post($content);
        $idempotency_key = $this->generate_idempotency_key('comment', $content, $activity_id);

        if ($this->is_duplicate($idempotency_key)) {
            return ['error' => 'duplicate', 'message' => 'This comment was already posted'];
        }

        $user = get_userdata($this->user_id);

        $preview = [
            'stage' => 'prepared',
            'idempotency_key' => $idempotency_key,
            'action' => 'comment_activity',
            'preview' => [
                'activity_id' => $activity_id,
                'content' => $sanitized,
                'content_length' => mb_strlen($sanitized),
                'author' => [
                    'id' => $this->user_id,
                    'name' => $user ? $user->display_name : 'Unknown',
                ],
            ],
            'warnings' => [],
        ];

        // Store for commit
        set_transient('mcpnh_prep_' . $idempotency_key, $preview, 300);

        $preview['next_action'] = "ml_activity_comment_commit(idempotency_key: \"{$idempotency_key}\")";

        return $preview;
    }

    /**
     * Commit activity comment
     *
     * @param string $idempotency_key Key from prepare stage
     * @return array Result with comment data or error
     */
    public function commit_comment_activity(string $idempotency_key): array {
        $prepared = get_transient('mcpnh_prep_' . $idempotency_key);

        if (!$prepared) {
            return ['error' => 'expired', 'message' => 'Prepared action expired. Please prepare again.'];
        }

        if ($prepared['action'] !== 'comment_activity') {
            return ['error' => 'invalid_action', 'message' => 'Invalid action type'];
        }

        $activity_id = $prepared['preview']['activity_id'];

        // Re-check permission
        if (!$this->can_comment_activity($activity_id)) {
            return ['error' => 'permission_denied', 'message' => 'Permission denied'];
        }

        if ($this->is_duplicate($idempotency_key, true)) {
            return ['error' => 'duplicate', 'message' => 'This comment was already posted'];
        }

        // Create the comment
        $comment_id = bp_activity_new_comment([
            'activity_id' => $activity_id,
            'content' => $prepared['preview']['content'],
            'user_id' => $this->user_id,
        ]);

        if (!$comment_id) {
            return ['error' => 'creation_failed', 'message' => 'Failed to create comment'];
        }

        $this->mark_used($idempotency_key, $comment_id);
        delete_transient('mcpnh_prep_' . $idempotency_key);

        return [
            'stage' => 'committed',
            'success' => true,
            'comment' => [
                'id' => $comment_id,
                'activity_id' => $activity_id,
                'content' => $prepared['preview']['content'],
            ],
            'message' => 'Comment posted successfully',
        ];
    }

    /**
     * Generate idempotency key
     */
    private function generate_idempotency_key(string $action, string $content, ?int $target_id = null): string {
        $data = $this->user_id . '|' . $action . '|' . $target_id . '|' . hash('sha256', $content);
        return hash('sha256', $data);
    }

    /**
     * Check if idempotency key was already used
     */
    private function is_duplicate(string $key, bool $check_committed = false): bool {
        if ($check_committed) {
            return get_transient('mcpnh_used_' . $key) !== false;
        }
        // Check if recently prepared (within 5 min window)
        return false; // Allow multiple prepares
    }

    /**
     * Mark idempotency key as used
     */
    private function mark_used(string $key, int $created_id): void {
        set_transient('mcpnh_used_' . $key, $created_id, 3600); // 1 hour
    }
}
