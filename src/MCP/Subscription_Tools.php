<?php
/**
 * Subscription Tools - MCP tools for space subscriptions (tool-map v1)
 *
 * Tools:
 * - ml_subscribe_space: Subscribe/unsubscribe to a space (prepare/commit)
 * - ml_get_subscriptions: List user's space subscriptions
 *
 * TICKET T1.4: Space subscriptions for notifications
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

class Subscription_Tools {

    /**
     * Session prefix for prepare/commit
     */
    private const SESSION_PREFIX = 'mcpnh_sub_';

    /**
     * Session TTL in seconds (5 minutes)
     */
    private const SESSION_TTL = 300;

    /**
     * User meta key for subscriptions
     */
    private const META_KEY = '_ml_space_subscriptions';

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_subscribe_space' => [
                'name' => 'ml_subscribe_space',
                'description' => 'Subscribe or unsubscribe to a space for notifications. Use stage=prepare to preview, stage=commit to confirm.',
                'category' => 'MaryLink Subscriptions',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'ID of the space to subscribe/unsubscribe',
                        ],
                        'action' => [
                            'type' => 'string',
                            'enum' => ['subscribe', 'unsubscribe'],
                            'description' => 'Action to perform',
                            'default' => 'subscribe',
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
                        'notification_preferences' => [
                            'type' => 'object',
                            'description' => 'Notification settings (optional)',
                            'properties' => [
                                'new_publications' => [
                                    'type' => 'boolean',
                                    'description' => 'Notify on new publications',
                                    'default' => true,
                                ],
                                'comments' => [
                                    'type' => 'boolean',
                                    'description' => 'Notify on comments',
                                    'default' => false,
                                ],
                                'step_changes' => [
                                    'type' => 'boolean',
                                    'description' => 'Notify on workflow step changes',
                                    'default' => true,
                                ],
                            ],
                        ],
                    ],
                    'required' => ['space_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => false,
                ],
            ],

            'ml_get_subscriptions' => [
                'name' => 'ml_get_subscriptions',
                'description' => 'Get list of spaces the user is subscribed to.',
                'category' => 'MaryLink Subscriptions',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'include_stats' => [
                            'type' => 'boolean',
                            'description' => 'Include space stats (publication count, last activity)',
                            'default' => false,
                        ],
                        'include_unread' => [
                            'type' => 'boolean',
                            'description' => 'Include unread count since last visit',
                            'default' => true,
                        ],
                    ],
                    'required' => [],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                ],
            ],
        ];
    }

    /**
     * Execute a subscription tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();
        $permissions = new Permission_Checker($user_id);

        if ($user_id <= 0) {
            return Tool_Response::error('authentication_required', 'You must be logged in to manage subscriptions.', $request_id);
        }

        switch ($tool) {
            case 'ml_subscribe_space':
                return self::handle_subscribe_space($args, $user_id, $permissions, $request_id);

            case 'ml_get_subscriptions':
                return self::handle_get_subscriptions($args, $user_id, $permissions, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_subscribe_space
     */
    private static function handle_subscribe_space(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['space_id']);
        if ($validation) {
            return $validation;
        }

        $space_id = (int) $args['space_id'];
        $action = $args['action'] ?? 'subscribe';
        $stage = $args['stage'] ?? 'prepare';

        // Check space exists and user can access it
        $space = get_post($space_id);
        if (!$space || !in_array($space->post_type, ['space', 'bp-group', 'publication'])) {
            // Try as publication parent space
            if ($space && $space->post_type === 'publication') {
                $space_id = (int) $space->post_parent;
                $space = get_post($space_id);
            }
        }

        if (!$space || !$permissions->can_see_space($space_id)) {
            return Tool_Response::error('not_found', 'Space not found', $request_id);
        }

        if ($stage === 'prepare') {
            return self::prepare_subscription($space_id, $space, $action, $args, $user_id, $request_id);
        } elseif ($stage === 'commit') {
            return self::commit_subscription($space_id, $action, $args, $user_id, $request_id);
        } else {
            return Tool_Response::error('validation_failed', 'Invalid stage. Use "prepare" or "commit".', $request_id);
        }
    }

    /**
     * Prepare subscription - show preview
     */
    private static function prepare_subscription(int $space_id, \WP_Post $space, string $action, array $args, int $user_id, string $request_id): array {
        // Check current subscription status
        $subscriptions = self::get_user_subscriptions($user_id);
        $is_subscribed = isset($subscriptions[$space_id]);

        // Validate action makes sense
        if ($action === 'subscribe' && $is_subscribed) {
            return Tool_Response::ok([
                'stage' => 'prepare',
                'already_subscribed' => true,
                'space_id' => $space_id,
                'space_title' => $space->post_title,
                'current_preferences' => $subscriptions[$space_id]['preferences'] ?? [],
                'message' => 'You are already subscribed to this space. Use action=unsubscribe to remove.',
            ], $request_id);
        }

        if ($action === 'unsubscribe' && !$is_subscribed) {
            return Tool_Response::ok([
                'stage' => 'prepare',
                'not_subscribed' => true,
                'space_id' => $space_id,
                'space_title' => $space->post_title,
                'message' => 'You are not subscribed to this space.',
            ], $request_id);
        }

        // Create session
        $preferences = $args['notification_preferences'] ?? [
            'new_publications' => true,
            'comments' => false,
            'step_changes' => true,
        ];

        $session_id = self::create_session([
            'user_id' => $user_id,
            'space_id' => $space_id,
            'action' => $action,
            'preferences' => $preferences,
            'created_at' => time(),
        ]);

        $preview = [
            'space' => [
                'id' => $space_id,
                'title' => $space->post_title,
                'type' => $space->post_type,
            ],
            'current_status' => $is_subscribed ? 'subscribed' : 'not_subscribed',
            'action' => $action,
        ];

        if ($action === 'subscribe') {
            $preview['notification_preferences'] = $preferences;
        }

        return Tool_Response::ok([
            'stage' => 'prepare',
            'session_id' => $session_id,
            'expires_in' => self::SESSION_TTL,
            'preview' => $preview,
            'next_action' => [
                'tool' => 'ml_subscribe_space',
                'args' => [
                    'space_id' => $space_id,
                    'action' => $action,
                    'stage' => 'commit',
                    'session_id' => $session_id,
                ],
                'hint' => $action === 'subscribe'
                    ? 'Call commit to subscribe to this space.'
                    : 'Call commit to unsubscribe from this space.',
            ],
        ], $request_id);
    }

    /**
     * Commit subscription - execute action
     */
    private static function commit_subscription(int $space_id, string $action, array $args, int $user_id, string $request_id): array {
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
        if (($session['space_id'] ?? 0) !== $space_id || ($session['action'] ?? '') !== $action) {
            return Tool_Response::error(
                'session_mismatch',
                'Session does not match request.',
                $request_id
            );
        }

        // Execute action
        $subscriptions = self::get_user_subscriptions($user_id);

        if ($action === 'subscribe') {
            $subscriptions[$space_id] = [
                'subscribed_at' => current_time('mysql'),
                'preferences' => $session['preferences'] ?? [],
                'last_seen' => current_time('mysql'),
            ];
            $message = 'Successfully subscribed to space.';
        } else {
            unset($subscriptions[$space_id]);
            $message = 'Successfully unsubscribed from space.';
        }

        // Save
        update_user_meta($user_id, self::META_KEY, $subscriptions);

        // Clean up session
        self::cleanup_session($session_id);

        return Tool_Response::ok([
            'stage' => 'commit',
            'success' => true,
            'space_id' => $space_id,
            'action' => $action,
            'subscription_count' => count($subscriptions),
            'message' => $message,
        ], $request_id);
    }

    /**
     * Handle ml_get_subscriptions
     */
    private static function handle_get_subscriptions(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $include_stats = (bool) ($args['include_stats'] ?? false);
        $include_unread = (bool) ($args['include_unread'] ?? true);

        $subscriptions = self::get_user_subscriptions($user_id);

        if (empty($subscriptions)) {
            return Tool_Response::empty_list(
                $request_id,
                'You have no active subscriptions.'
            );
        }

        $result = [];
        foreach ($subscriptions as $space_id => $sub_data) {
            // Check space still exists and is accessible
            $space = get_post($space_id);
            if (!$space || !$permissions->can_see_space($space_id)) {
                continue;
            }

            $item = [
                'space_id' => $space_id,
                'space_title' => $space->post_title,
                'space_type' => $space->post_type,
                'subscribed_at' => $sub_data['subscribed_at'] ?? null,
                'preferences' => $sub_data['preferences'] ?? [],
            ];

            // Include stats
            if ($include_stats) {
                $item['stats'] = [
                    'publication_count' => self::get_space_publication_count($space_id),
                    'last_activity' => self::get_space_last_activity($space_id),
                ];
            }

            // Include unread count
            if ($include_unread) {
                $last_seen = $sub_data['last_seen'] ?? $sub_data['subscribed_at'] ?? null;
                $item['unread_count'] = self::get_unread_count($space_id, $last_seen);
            }

            $result[] = $item;
        }

        if (empty($result)) {
            return Tool_Response::empty_list(
                $request_id,
                'No accessible subscriptions found.'
            );
        }

        return Tool_Response::ok([
            'subscriptions' => $result,
            'count' => count($result),
        ], $request_id);
    }

    /**
     * Get user's subscriptions
     */
    private static function get_user_subscriptions(int $user_id): array {
        $subs = get_user_meta($user_id, self::META_KEY, true);
        return is_array($subs) ? $subs : [];
    }

    /**
     * Get publication count for a space
     */
    private static function get_space_publication_count(int $space_id): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_parent = %d
             AND post_type = 'publication'
             AND post_status = 'publish'",
            $space_id
        ));
    }

    /**
     * Get last activity date for a space
     */
    private static function get_space_last_activity(int $space_id): ?string {
        global $wpdb;

        $last = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(post_modified) FROM {$wpdb->posts}
             WHERE post_parent = %d
             AND post_type = 'publication'
             AND post_status = 'publish'",
            $space_id
        ));

        return $last ?: null;
    }

    /**
     * Get unread count since last seen
     */
    private static function get_unread_count(int $space_id, ?string $since): int {
        if (!$since) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_parent = %d
             AND post_type = 'publication'
             AND post_status = 'publish'
             AND post_date > %s",
            $space_id,
            $since
        ));
    }

    /**
     * Mark space as seen (update last_seen timestamp)
     */
    public static function mark_space_seen(int $user_id, int $space_id): void {
        $subscriptions = self::get_user_subscriptions($user_id);

        if (isset($subscriptions[$space_id])) {
            $subscriptions[$space_id]['last_seen'] = current_time('mysql');
            update_user_meta($user_id, self::META_KEY, $subscriptions);
        }
    }

    /**
     * Create session for prepare/commit flow
     */
    private static function create_session(array $data): string {
        $session_id = 'sub_' . bin2hex(random_bytes(16));
        set_transient(self::SESSION_PREFIX . $session_id, $data, self::SESSION_TTL);
        return $session_id;
    }

    /**
     * Validate session
     */
    private static function validate_session(string $session_id, int $user_id): ?array {
        if (empty($session_id) || strpos($session_id, 'sub_') !== 0) {
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
        return post_type_exists('publication') || post_type_exists('space');
    }
}
