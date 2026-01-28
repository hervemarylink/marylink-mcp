<?php
/**
 * Rate Limiter - Unified rate limiting for MCP operations
 *
 * Provides multi-level rate limiting:
 * - Per user (by user_id)
 * - Per token (by API key)
 * - Per plan (B2B2B tiers)
 * - Global (all requests)
 * - Burst protection
 * - Bulk operation limits
 *
 * SPRINT 0 HARDENING: Added plan-based limits and bulk operation support
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Ops;

use MCP_No_Headless\User\Mission_Token_Manager;

class Rate_Limiter {

    /**
     * Plan tiers for B2B2B
     */
    public const PLAN_FREE = 'free';
    public const PLAN_PRO = 'pro';
    public const PLAN_CABINET = 'cabinet';      // B2B
    public const PLAN_ENTERPRISE = 'enterprise'; // B2B2B

    /**
     * Rate limit configurations by plan
     *
     * LIMITS MATRIX (Sprint 0 Hardening):
     * ┌────────────┬──────────┬──────────┬──────────┬────────────┐
     * │ Plan       │ Read/min │ Write/min│ Bulk/hr  │ Chain depth│
     * ├────────────┼──────────┼──────────┼──────────┼────────────┤
     * │ Free       │ 60       │ 10       │ 0        │ 3          │
     * │ Pro        │ 120      │ 30       │ 5        │ 5          │
     * │ Cabinet    │ 300      │ 60       │ 20       │ 10         │
     * │ Enterprise │ 600      │ 120      │ 100      │ 20         │
     * └────────────┴──────────┴──────────┴──────────┴────────────┘
     */
    private const PLAN_LIMITS = [
        self::PLAN_FREE => [
            'read' => ['user_limit' => 60, 'user_window' => 60, 'burst_limit' => 10, 'burst_window' => 5],
            'write' => ['user_limit' => 10, 'user_window' => 60, 'burst_limit' => 3, 'burst_window' => 5],
            'bulk' => ['user_limit' => 0, 'user_window' => 3600, 'max_items' => 0],
            'chain_depth' => 3,
            'export_per_day' => 0,
        ],
        self::PLAN_PRO => [
            'read' => ['user_limit' => 120, 'user_window' => 60, 'burst_limit' => 15, 'burst_window' => 5],
            'write' => ['user_limit' => 30, 'user_window' => 60, 'burst_limit' => 5, 'burst_window' => 5],
            'bulk' => ['user_limit' => 5, 'user_window' => 3600, 'max_items' => 10],
            'chain_depth' => 5,
            'export_per_day' => 5,
        ],
        self::PLAN_CABINET => [
            'read' => ['user_limit' => 300, 'user_window' => 60, 'burst_limit' => 30, 'burst_window' => 5],
            'write' => ['user_limit' => 60, 'user_window' => 60, 'burst_limit' => 10, 'burst_window' => 5],
            'bulk' => ['user_limit' => 20, 'user_window' => 3600, 'max_items' => 50],
            'chain_depth' => 10,
            'export_per_day' => 20,
        ],
        self::PLAN_ENTERPRISE => [
            'read' => ['user_limit' => 600, 'user_window' => 60, 'burst_limit' => 50, 'burst_window' => 5],
            'write' => ['user_limit' => 120, 'user_window' => 60, 'burst_limit' => 20, 'burst_window' => 5],
            'bulk' => ['user_limit' => 100, 'user_window' => 3600, 'max_items' => 200],
            'chain_depth' => 20,
            'export_per_day' => 100,
        ],
    ];

    /**
     * Default (fallback) rate limit configurations
     */
    private const LIMITS = [
        'read' => [
            'user_limit' => 120,
            'user_window' => 60,
            'burst_limit' => 15,
            'burst_window' => 5,
        ],
        'write' => [
            'user_limit' => 20,
            'user_window' => 60,
            'burst_limit' => 5,
            'burst_window' => 5,
        ],
        'bulk' => [
            'user_limit' => 5,
            'user_window' => 3600,
            'max_items' => 10,
            'delay_ms' => 500,       // Delay between bulk items
        ],
        'global' => [
            'limit' => 2000,
            'window' => 300,
        ],
    ];

    /**
     * Operation timeouts (Sprint 0 Hardening)
     */
    public const TIMEOUTS = [
        'default' => 30,            // 30 seconds
        'chain_resolve' => 10,      // 10 seconds for chain resolution
        'bulk_item' => 5,           // 5 seconds per bulk item
        'bulk_total' => 300,        // 5 minutes max for bulk operations
        'export' => 60,             // 1 minute for exports
    ];

    /**
     * Tools classification (read vs write vs bulk)
     */
    private const WRITE_TOOLS = [
        'ml_create_publication',
        'ml_create_publication_from_text',
        'ml_edit_publication',
        'ml_append_to_publication',
        'ml_add_comment',
        'ml_import_as_comment',
        'ml_create_review',
        'ml_move_to_step',
        'ml_apply_tool',
        'ml_apply_tool_commit',
        'ml_activity_post_commit',
        'ml_activity_comment_commit',
        'ml_rate_publication',     // New S1
        'ml_subscribe_space',      // New S1
        'ml_duplicate_publication', // New S2
        'ml_manage_team',          // New S4 (write on commit stage)
        'ml_auto_improve',         // New T5 (write on apply stage)
    ];

    /**
     * Bulk operation tools (stricter limits)
     */
    private const BULK_TOOLS = [
        'ml_bulk_apply_tool',
        'ml_bulk_move_step',
        'ml_bulk_tag',
        'ml_export_crew_bundle',
    ];

    /**
     * Check if request is within rate limits
     *
     * @param int $user_id User ID
     * @param string $tool Tool name
     * @param string|null $stage Stage (for ml_apply_tool)
     * @param string|null $token_hash Hashed API token (for per-token limiting)
     * @return array ['allowed' => bool, 'reason' => string|null, 'retry_after' => int|null, 'limits' => array]
     */
    public static function check(int $user_id, string $tool, ?string $stage = null, ?string $token_hash = null): array {
        // Determine user's plan
        $plan = self::get_user_plan($user_id);
        $plan_limits = self::PLAN_LIMITS[$plan] ?? self::PLAN_LIMITS[self::PLAN_PRO];

        // Determine operation type
        $op_type = self::get_operation_type($tool, $stage);
        $config = $plan_limits[$op_type] ?? self::LIMITS[$op_type] ?? self::LIMITS['read'];

        // For bulk operations, check if allowed by plan
        if ($op_type === 'bulk' && ($config['user_limit'] ?? 0) === 0) {
            return [
                'allowed' => false,
                'reason' => 'bulk_not_allowed',
                'retry_after' => null,
                'limits' => ['plan' => $plan, 'op_type' => $op_type, 'bulk_allowed' => false],
            ];
        }

        // 1. Check global limit first
        $global_check = self::check_global_limit();
        if (!$global_check['allowed']) {
            $global_check['limits'] = ['plan' => $plan, 'op_type' => $op_type];
            return $global_check;
        }

        // 2. Check burst limit (skip for bulk - handled by delay)
        if ($op_type !== 'bulk' && isset($config['burst_limit'])) {
            $burst_key = self::get_key('burst', $user_id, $op_type);
            $burst_check = self::check_limit($burst_key, $config['burst_limit'], $config['burst_window']);
            if (!$burst_check['allowed']) {
                $burst_check['reason'] = 'burst_limit_exceeded';
                $burst_check['limits'] = ['plan' => $plan, 'op_type' => $op_type];
                return $burst_check;
            }
        }

        // 3. Check user limit
        $user_key = self::get_key('user', $user_id, $op_type);
        $user_check = self::check_limit($user_key, $config['user_limit'], $config['user_window']);
        if (!$user_check['allowed']) {
            $user_check['reason'] = 'user_limit_exceeded';
            $user_check['limits'] = ['plan' => $plan, 'op_type' => $op_type, 'limit' => $config['user_limit']];
            return $user_check;
        }

        // 4. Check token limit (if provided)
        if ($token_hash) {
            $token_key = self::get_key('token', $token_hash, $op_type);
            $token_check = self::check_limit($token_key, $config['user_limit'], $config['user_window']);
            if (!$token_check['allowed']) {
                $token_check['reason'] = 'token_limit_exceeded';
                $token_check['limits'] = ['plan' => $plan, 'op_type' => $op_type];
                return $token_check;
            }
        }

        // All checks passed - increment counters
        if ($op_type !== 'bulk' && isset($config['burst_limit'])) {
            self::increment(self::get_key('burst', $user_id, $op_type), $config['burst_window']);
        }
        self::increment($user_key, $config['user_window']);
        if ($token_hash) {
            self::increment(self::get_key('token', $token_hash, $op_type), $config['user_window']);
        }
        self::increment_global();

        return [
            'allowed' => true,
            'reason' => null,
            'retry_after' => null,
            'limits' => [
                'plan' => $plan,
                'op_type' => $op_type,
                'current' => (int) get_transient($user_key),
                'limit' => $config['user_limit'],
            ],
        ];
    }

    /**
     * Get user's plan tier
     *
     * @param int $user_id User ID
     * @return string Plan constant
     */
    public static function get_user_plan(int $user_id): string {
        // Check if using mission token (B2B2B)
        if (Mission_Token_Manager::is_mission_token_request()) {
            // Mission tokens get cabinet-level access by default
            return self::PLAN_CABINET;
        }

        // Check user meta for plan
        $plan = get_user_meta($user_id, '_ml_subscription_plan', true);
        if ($plan && isset(self::PLAN_LIMITS[$plan])) {
            return $plan;
        }

        // Check if admin
        if (user_can($user_id, 'manage_options')) {
            return self::PLAN_ENTERPRISE;
        }

        // Default to Pro for authenticated users
        return $user_id > 0 ? self::PLAN_PRO : self::PLAN_FREE;
    }

    /**
     * Get operation type (read, write, or bulk)
     *
     * @param string $tool Tool name
     * @param string|null $stage Stage
     * @return string Operation type
     */
    public static function get_operation_type(string $tool, ?string $stage = null): string {
        // Bulk tools
        if (in_array($tool, self::BULK_TOOLS, true)) {
            return 'bulk';
        }

        // Write tools
        if (self::is_write_operation($tool, $stage)) {
            return 'write';
        }

        return 'read';
    }

    /**
     * Get plan limits for a user (for display/documentation)
     *
     * @param int $user_id User ID
     * @return array Plan limits
     */
    public static function get_plan_limits(int $user_id): array {
        $plan = self::get_user_plan($user_id);
        $limits = self::PLAN_LIMITS[$plan] ?? self::PLAN_LIMITS[self::PLAN_PRO];

        return [
            'plan' => $plan,
            'read_per_minute' => $limits['read']['user_limit'],
            'write_per_minute' => $limits['write']['user_limit'],
            'bulk_per_hour' => $limits['bulk']['user_limit'],
            'bulk_max_items' => $limits['bulk']['max_items'],
            'chain_depth' => $limits['chain_depth'],
            'export_per_day' => $limits['export_per_day'],
            'timeouts' => self::TIMEOUTS,
        ];
    }

    /**
     * Check bulk operation limits
     *
     * @param int $user_id User ID
     * @param int $item_count Number of items in bulk operation
     * @return array ['allowed' => bool, 'reason' => string|null, 'max_items' => int]
     */
    public static function check_bulk_limits(int $user_id, int $item_count): array {
        $plan = self::get_user_plan($user_id);
        $limits = self::PLAN_LIMITS[$plan] ?? self::PLAN_LIMITS[self::PLAN_PRO];
        $bulk_limits = $limits['bulk'];

        if ($bulk_limits['user_limit'] === 0) {
            return [
                'allowed' => false,
                'reason' => 'bulk_not_allowed_for_plan',
                'max_items' => 0,
                'plan' => $plan,
            ];
        }

        if ($item_count > $bulk_limits['max_items']) {
            return [
                'allowed' => false,
                'reason' => 'bulk_item_limit_exceeded',
                'max_items' => $bulk_limits['max_items'],
                'requested' => $item_count,
                'plan' => $plan,
            ];
        }

        // Check hourly bulk operation limit
        $check = self::check($user_id, 'ml_bulk_apply_tool', null, null);
        if (!$check['allowed']) {
            $check['max_items'] = $bulk_limits['max_items'];
            return $check;
        }

        return [
            'allowed' => true,
            'reason' => null,
            'max_items' => $bulk_limits['max_items'],
            'delay_ms' => self::LIMITS['bulk']['delay_ms'],
            'plan' => $plan,
        ];
    }

    /**
     * Get chain depth limit for user
     *
     * @param int $user_id User ID
     * @return int Max chain depth
     */
    public static function get_chain_depth_limit(int $user_id): int {
        $plan = self::get_user_plan($user_id);
        $limits = self::PLAN_LIMITS[$plan] ?? self::PLAN_LIMITS[self::PLAN_PRO];
        return $limits['chain_depth'] ?? 5;
    }

    /**
     * Get export limit for user (per day)
     *
     * @param int $user_id User ID
     * @return int Max exports per day (0 = not allowed)
     */
    public static function get_export_limit(int $user_id): int {
        $plan = self::get_user_plan($user_id);
        $limits = self::PLAN_LIMITS[$plan] ?? self::PLAN_LIMITS[self::PLAN_PRO];
        return $limits['export_per_day'] ?? 0;
    }

    /**
     * Check if a tool/stage is a write operation
     */
    public static function is_write_operation(string $tool, ?string $stage = null): bool {
        // ml_apply_tool: only commit stage is write
        if ($tool === 'ml_apply_tool') {
            return $stage === 'commit';
        }

        return in_array($tool, self::WRITE_TOOLS, true);
    }

    /**
     * Check a single rate limit
     */
    private static function check_limit(string $key, int $limit, int $window): array {
        $count = (int) get_transient($key);

        if ($count >= $limit) {
            $ttl = self::get_ttl($key);
            return [
                'allowed' => false,
                'retry_after' => $ttl > 0 ? $ttl : $window,
            ];
        }

        return ['allowed' => true, 'retry_after' => null];
    }

    /**
     * Check global rate limit
     */
    private static function check_global_limit(): array {
        $key = 'mcpnh_rate_global';
        $config = self::LIMITS['global'];
        $count = (int) get_transient($key);

        if ($count >= $config['limit']) {
            return [
                'allowed' => false,
                'reason' => 'global_limit_exceeded',
                'retry_after' => self::get_ttl($key) ?: $config['window'],
            ];
        }

        return ['allowed' => true, 'reason' => null, 'retry_after' => null];
    }

    /**
     * Increment a counter
     */
    private static function increment(string $key, int $window): void {
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, $window);
    }

    /**
     * Increment global counter
     */
    private static function increment_global(): void {
        $key = 'mcpnh_rate_global';
        $config = self::LIMITS['global'];
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, $config['window']);
    }

    /**
     * Get remaining TTL for a transient
     */
    private static function get_ttl(string $key): int {
        global $wpdb;

        // For non-persistent cache, we can't get TTL easily
        // This works for database-stored transients
        $timeout_key = '_transient_timeout_' . $key;
        $timeout = get_option($timeout_key);

        if ($timeout) {
            $remaining = (int) $timeout - time();
            return max(0, $remaining);
        }

        return 0;
    }

    /**
     * Generate cache key
     *
     * @param string $type Key type (user, token, burst)
     * @param mixed $identifier User ID or token hash
     * @param string|bool $op_type Operation type string or boolean for backward compat
     */
    private static function get_key(string $type, $identifier, $op_type): string {
        // Backward compatibility: convert boolean to string
        if (is_bool($op_type)) {
            $op_type = $op_type ? 'write' : 'read';
        }
        return "mcpnh_rate_{$type}_{$op_type}_{$identifier}";
    }

    /**
     * Get current usage stats for a user
     */
    public static function get_user_stats(int $user_id): array {
        $plan = self::get_user_plan($user_id);
        $plan_limits = self::PLAN_LIMITS[$plan] ?? self::PLAN_LIMITS[self::PLAN_PRO];

        $read_config = $plan_limits['read'];
        $write_config = $plan_limits['write'];
        $bulk_config = $plan_limits['bulk'];

        return [
            'plan' => $plan,
            'read' => [
                'current' => (int) get_transient(self::get_key('user', $user_id, 'read')),
                'limit' => $read_config['user_limit'],
                'window_seconds' => $read_config['user_window'],
                'burst_current' => (int) get_transient(self::get_key('burst', $user_id, 'read')),
                'burst_limit' => $read_config['burst_limit'],
            ],
            'write' => [
                'current' => (int) get_transient(self::get_key('user', $user_id, 'write')),
                'limit' => $write_config['user_limit'],
                'window_seconds' => $write_config['user_window'],
                'burst_current' => (int) get_transient(self::get_key('burst', $user_id, 'write')),
                'burst_limit' => $write_config['burst_limit'],
            ],
            'bulk' => [
                'current' => (int) get_transient(self::get_key('user', $user_id, 'bulk')),
                'limit' => $bulk_config['user_limit'],
                'window_seconds' => $bulk_config['user_window'],
                'max_items' => $bulk_config['max_items'],
            ],
            'global' => [
                'current' => (int) get_transient('mcpnh_rate_global'),
                'limit' => self::LIMITS['global']['limit'],
                'window_seconds' => self::LIMITS['global']['window'],
            ],
            'chain_depth' => $plan_limits['chain_depth'],
            'export_per_day' => $plan_limits['export_per_day'],
        ];
    }

    /**
     * Reset rate limits for a user (admin action)
     */
    public static function reset_user(int $user_id): void {
        foreach (['read', 'write', 'bulk'] as $op_type) {
            delete_transient(self::get_key('user', $user_id, $op_type));
            delete_transient(self::get_key('burst', $user_id, $op_type));
        }
    }

    /**
     * Reset all rate limits (emergency action)
     */
    public static function reset_all(): void {
        global $wpdb;

        // Delete all rate limit transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mcpnh_rate_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mcpnh_rate_%'");

        // Clear object cache if available
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('transient');
        }
    }

    /**
     * Get error message for rate limit response
     */
    public static function get_error_message(string $reason, int $retry_after): string {
        $messages = [
            'burst_limit_exceeded' => 'Too many requests. Please slow down.',
            'user_limit_exceeded' => 'Rate limit exceeded for your account.',
            'token_limit_exceeded' => 'Rate limit exceeded for this API token.',
            'global_limit_exceeded' => 'Service is temporarily overloaded. Please try again later.',
        ];

        $base = $messages[$reason] ?? 'Rate limit exceeded.';
        return "{$base} Retry after {$retry_after} seconds.";
    }


    /**
     * Backward-compatible wrapper expected by ml_me(action=quotas)
     * Returns a compact status + detailed stats.
     */
    public static function get_user_status(int $user_id): array {
        $stats = self::get_user_stats($user_id);

        // Compact, MCP-friendly view
        $now = time();
        $reset_at = $stats['window_reset_ts'] ?? null;
        $remaining = null;
        $max = $stats['limit'] ?? null;
        $used = $stats['used'] ?? null;

        if (is_int($max) && is_int($used)) {
            $remaining = max(0, $max - $used);
        }

        return [
            'success' => true,
            'plan' => $stats['plan'] ?? null,
            'window_seconds' => $stats['window_seconds'] ?? null,
            'max_requests' => $max,
            'used' => $used,
            'remaining' => $remaining,
            'reset_at' => $reset_at ? gmdate('c', (int)$reset_at) : null,
            'details' => $stats,
            'generated_at' => gmdate('c', $now),
        ];
    }

}
