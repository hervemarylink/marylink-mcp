<?php
/**
 * Health Check - System health diagnostics
 *
 * Provides:
 * - REST endpoint for monitoring
 * - Admin dashboard data
 * - Cron status
 * - Dependencies check
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Ops;

class Health_Check {

    /**
     * Get full health status
     */
    public static function get_status(): array {
        $start = microtime(true);

        $status = [
            'ok' => true,
            'timestamp' => current_time('c'),
            'version' => [
                'plugin' => defined('MCPNH_VERSION') ? MCPNH_VERSION : 'unknown',
                'wordpress' => get_bloginfo('version'),
                'php' => PHP_VERSION,
            ],
            'dependencies' => self::check_dependencies(),
            'database' => self::check_database(),
            'cron' => self::check_cron_status(),
            'cache' => self::check_cache(),
            'rate_limits' => self::check_rate_limits(),
        ];

        // Determine overall health
        $status['ok'] = self::is_healthy($status);
        $status['latency_ms'] = round((microtime(true) - $start) * 1000, 2);

        return $status;
    }

    /**
     * Get simplified health status (for quick checks)
     */
    public static function get_simple_status(): array {
        return [
            'ok' => self::is_operational(),
            'timestamp' => current_time('c'),
            'version' => defined('MCPNH_VERSION') ? MCPNH_VERSION : 'unknown',
        ];
    }

    /**
     * Check if system is operational
     */
    public static function is_operational(): bool {
        // Quick checks only
        return Audit_Logger::table_exists() && self::check_database()['ok'];
    }

    /**
     * Check dependencies
     */
    private static function check_dependencies(): array {
        return [
            'buddyboss' => [
                'available' => \MCP_No_Headless\BuddyBoss\Group_Service::is_available(),
                'groups' => function_exists('groups_get_groups'),
                'activity' => function_exists('bp_activity_get'),
                'members' => function_exists('bp_core_get_users'),
            ],
            'picasso' => [
                'available' => class_exists('Picasso_Backend\\Permission\\User'),
                'publications' => post_type_exists('publication'),
                'spaces' => post_type_exists('space'),
            ],
            'ai_engine' => [
                'available' => class_exists('Meow_MWAI_Core'),
                'mcp_filter' => has_filter('mwai_mcp_tools'),
            ],
        ];
    }

    /**
     * Check database tables
     */
    private static function check_database(): array {
        global $wpdb;

        $audit_table = $wpdb->prefix . 'mcpnh_audit';
        $audit_exists = $wpdb->get_var("SHOW TABLES LIKE '$audit_table'") === $audit_table;

        $audit_count = 0;
        if ($audit_exists) {
            $audit_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $audit_table");
        }

        return [
            'ok' => $audit_exists,
            'tables' => [
                'audit' => [
                    'exists' => $audit_exists,
                    'rows' => $audit_count,
                ],
            ],
        ];
    }

    /**
     * Check cron status
     */
    private static function check_cron_status(): array {
        $crons = [
            'mcpnh_recalculate_scores' => [
                'scheduled' => false,
                'next_run' => null,
                'last_run' => get_option('mcpnh_last_scoring_run', null),
            ],
            'mcpnh_purge_audit_logs' => [
                'scheduled' => false,
                'next_run' => null,
                'last_run' => get_option('mcpnh_last_audit_purge', null),
            ],
        ];

        foreach ($crons as $hook => &$info) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                $info['scheduled'] = true;
                $info['next_run'] = date('Y-m-d H:i:s', $timestamp);
                $info['next_run_human'] = human_time_diff(time(), $timestamp) . ' from now';
            }
        }

        // Check if WP Cron is working
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        return [
            'ok' => !$cron_disabled && $crons['mcpnh_recalculate_scores']['scheduled'],
            'wp_cron_disabled' => $cron_disabled,
            'jobs' => $crons,
        ];
    }

    /**
     * Check cache status
     */
    private static function check_cache(): array {
        $has_object_cache = wp_using_ext_object_cache();

        return [
            'object_cache' => $has_object_cache,
            'object_cache_type' => $has_object_cache ? self::detect_cache_type() : 'database',
        ];
    }

    /**
     * Detect object cache type
     */
    private static function detect_cache_type(): string {
        global $wp_object_cache;

        if (class_exists('Redis')) {
            return 'redis';
        }
        if (class_exists('Memcached')) {
            return 'memcached';
        }
        if (function_exists('apcu_fetch')) {
            return 'apcu';
        }

        return 'unknown';
    }

    /**
     * Check rate limits status
     */
    private static function check_rate_limits(): array {
        $global_current = (int) get_transient('mcpnh_rate_global');
        $global_limit = 2000; // From Rate_Limiter

        return [
            'global' => [
                'current' => $global_current,
                'limit' => $global_limit,
                'utilization' => round(($global_current / $global_limit) * 100, 2) . '%',
            ],
        ];
    }

    /**
     * Determine if system is healthy based on checks
     */
    private static function is_healthy(array $status): bool {
        // Database must be OK
        if (!$status['database']['ok']) {
            return false;
        }

        // Cron should be scheduled (warning if not, but not critical)
        // At least one dependency should be available
        $has_dependency = $status['dependencies']['buddyboss']['available']
            || $status['dependencies']['picasso']['available']
            || $status['dependencies']['ai_engine']['available'];

        return $has_dependency;
    }

    /**
     * Get recent errors from audit log
     */
    public static function get_recent_errors(int $limit = 10): array {
        return Audit_Logger::get_logs([
            'result' => 'error',
            'since' => date('Y-m-d H:i:s', strtotime('-24 hours')),
        ], $limit);
    }

    /**
     * Get audit stats for dashboard
     */
    public static function get_dashboard_stats(): array {
        $stats_24h = Audit_Logger::get_stats('24h');
        $stats_7d = Audit_Logger::get_stats('7d');

        return [
            'last_24h' => $stats_24h,
            'last_7d' => $stats_7d,
            'recent_errors' => self::get_recent_errors(5),
        ];
    }

    /**
     * Run manual diagnostics (for admin)
     */
    public static function run_diagnostics(): array {
        $results = [];

        // Test audit logging
        $results['audit_write'] = [
            'test' => 'Write to audit log',
            'ok' => false,
        ];
        try {
            $debug_id = Audit_Logger::log([
                'tool_name' => '_diagnostic_test',
                'user_id' => get_current_user_id(),
                'result' => 'success',
            ]);
            $results['audit_write']['ok'] = !empty($debug_id);
            $results['audit_write']['debug_id'] = $debug_id;
        } catch (\Exception $e) {
            $results['audit_write']['error'] = $e->getMessage();
        }

        // Test rate limiter
        $results['rate_limiter'] = [
            'test' => 'Rate limiter check',
            'ok' => true,
        ];
        $rate_check = Rate_Limiter::check(get_current_user_id(), '_diagnostic_test');
        $results['rate_limiter']['result'] = $rate_check;

        // Test BuddyBoss if available
        if (\MCP_No_Headless\BuddyBoss\Group_Service::is_available()) {
            $results['buddyboss'] = [
                'test' => 'BuddyBoss groups query',
                'ok' => false,
            ];
            try {
                $service = new \MCP_No_Headless\BuddyBoss\Group_Service(get_current_user_id());
                $groups = $service->search_groups('', [], 1);
                $results['buddyboss']['ok'] = isset($groups['groups']);
            } catch (\Exception $e) {
                $results['buddyboss']['error'] = $e->getMessage();
            }
        }

        return $results;
    }
}
