<?php
/**
 * ml_ping - Health diagnostic tool
 *
 * Checks API status, DB connection, plugin version, authentication state, quota status.
 * First tool to call to validate environment.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Tools;

use MCP_No_Headless\MCP\Core\Tool_Response;
use MCP_No_Headless\MCP\Core\Router_V3;
use MCP_No_Headless\Ops\Rate_Limiter;

class Ping {

    const TOOL_NAME = 'ml_ping';
    const VERSION = '3.2.27';

    /**
     * Execute ping diagnostic
     *
     * @param array $args Tool arguments
     * @param int $user_id Current user ID
     * @return array Diagnostic result
     */
    
    /**
     * Provide deterministic version fingerprint for debugging deployments.
     */
    private static function get_tool_versions(): array {
        $out = [
            'ping' => self::VERSION,
        ];

        // Catalog version
        if (class_exists('\\MCP_No_Headless\\MCP\\Core\\Tool_Catalog_V3')) {
            $out['catalog'] = \MCP_No_Headless\MCP\Core\Tool_Catalog_V3::VERSION;
        }

        // Key tools
        if (class_exists('\\MCP_No_Headless\\MCP\\Core\\Tools\\Save')) {
            $out['save'] = \MCP_No_Headless\MCP\Core\Tools\Save::VERSION;
        }
        if (class_exists('\\MCP_No_Headless\\MCP\\Core\\Tools\\Me')) {
            $out['me'] = \MCP_No_Headless\MCP\Core\Tools\Me::VERSION;
        }

        return $out;
    }

public static function execute(array $args, int $user_id): array {
        $start_time = microtime(true);
        $checks = [];
        $warnings = [];
        $overall_status = 'healthy';

        // 1. API Status
        $checks['api'] = self::check_api();

        // 2. Database Connection
        $checks['database'] = self::check_database();

        // 3. Plugin Version
        $checks['plugin'] = self::check_plugin();

        // 4. Authentication State
        $checks['auth'] = self::check_auth($user_id);

        // 5. Quota Status
        $checks['quota'] = self::check_quota($user_id);

        // 6. Cache Status (Redis/Object Cache)
        $checks['cache'] = self::check_cache();

        // 7. Services Status
        $checks['services'] = self::check_services();

        // Determine overall status
        foreach ($checks as $name => $check) {
            if ($check['status'] === 'error') {
                $overall_status = 'unhealthy';
                break;
            }
            if ($check['status'] === 'warning') {
                $overall_status = 'degraded';
                $warnings[] = $name;
            }
        }

        $latency_ms = round((microtime(true) - $start_time) * 1000);

        // Get packs status from Router
        $packs_status = class_exists(Router_V3::class) ? Router_V3::get_packs_status() : [];

        return Tool_Response::ok([
            'status' => $overall_status,
            'latency_ms' => $latency_ms,
            'version' => defined('MLMCP_VERSION') ? MLMCP_VERSION : self::VERSION,
                        'tool_versions' => self::get_tool_versions(),
'checks' => $checks,
            'warnings' => $warnings,
            'packs_available' => $packs_status['available'] ?? [],
            'packs_active' => $packs_status['active'] ?? [],
            'summary' => self::generate_summary($overall_status, $checks, $warnings),
        ]);
    }

    /**
     * Check API status
     */
    private static function check_api(): array {
        $status = 'ok';
        $details = [];

        // Check REST API availability
        $rest_url = rest_url('marylink/v1/');
        $details['rest_url'] = $rest_url;
        $details['rest_available'] = !empty($rest_url);

        // Check MCP endpoint
        $mcp_endpoint = rest_url('mcp/v1/');
        $details['mcp_endpoint'] = $mcp_endpoint;

        // Check SSL
        $details['ssl'] = is_ssl();

        if (!$details['rest_available']) {
            $status = 'error';
        }

        return [
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * Check database connection
     */
    private static function check_database(): array {
        global $wpdb;

        $status = 'ok';
        $details = [];

        // Test connection
        $start = microtime(true);
        $result = $wpdb->get_var("SELECT 1");
        $query_time = round((microtime(true) - $start) * 1000, 2);

        $details['connected'] = ($result == 1);
        $details['query_time_ms'] = $query_time;
        $details['prefix'] = $wpdb->prefix;

        // Check critical tables
        $tables = [
            'posts' => $wpdb->posts,
            'users' => $wpdb->users,
            'bp_activity' => $wpdb->prefix . 'bp_activity',
            'ml_activities' => $wpdb->prefix . 'ml_activities',
        ];

        $details['tables'] = [];
        foreach ($tables as $name => $table) {
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
            $details['tables'][$name] = $exists;
            if (!$exists && in_array($name, ['posts', 'users'])) {
                $status = 'error';
            }
        }

        if (!$details['connected']) {
            $status = 'error';
        } elseif ($query_time > 100) {
            $status = 'warning';
            $details['warning'] = 'Slow database response';
        }

        return [
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * Check plugin version and state
     */
    private static function check_plugin(): array {
        $status = 'ok';
        $details = [];

        // Plugin version
        $details['version'] = defined('MCPNH_VERSION') ? MCPNH_VERSION : 'unknown';
        $details['v3_version'] = self::VERSION;

        // Check required plugins
        $required_plugins = [
            'buddypress/bp-loader.php' => 'BuddyPress',
            'marylink-api/marylink-api.php' => 'MaryLink API',
        ];

        $active_plugins = get_option('active_plugins', []);
        $details['plugins'] = [];

        foreach ($required_plugins as $plugin => $name) {
            $is_active = in_array($plugin, $active_plugins);
            $details['plugins'][$name] = $is_active;
            if (!$is_active) {
                $status = 'warning';
            }
        }

        // Check PHP version
        $details['php_version'] = PHP_VERSION;
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            $status = 'warning';
            $details['php_warning'] = 'PHP 8.0+ recommended';
        }

        return [
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * Check authentication state
     */
    private static function check_auth(int $user_id): array {
        $status = 'ok';
        $details = [];

        $details['authenticated'] = $user_id > 0;
        $details['user_id'] = $user_id;

        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if ($user) {
                $details['username'] = $user->user_login;
                $details['roles'] = $user->roles;
                $details['is_admin'] = user_can($user_id, 'manage_options');

                // Check MCP capabilities
                $details['mcp_enabled'] = self::user_has_mcp_access($user_id);
            } else {
                $status = 'error';
                $details['error'] = 'User not found';
            }
        } else {
            $status = 'warning';
            $details['warning'] = 'Not authenticated - limited access';
        }

        return [
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * Check user quota status
     */
    private static function check_quota(int $user_id): array {
        $status = 'ok';
        $details = [];

        if ($user_id <= 0) {
            return [
                'status' => 'warning',
                'details' => ['message' => 'No quota for unauthenticated users'],
            ];
        }

        // Get rate limiter info
        if (class_exists(Rate_Limiter::class)) {
            $rate_info = Rate_Limiter::get_user_stats($user_id);
            $details['rate_limit'] = [
                'plan' => $rate_info['plan'] ?? 'unknown',
                'read_remaining' => ($rate_info['read']['limit'] ?? 100) - ($rate_info['read']['current'] ?? 0),
                'write_remaining' => ($rate_info['write']['limit'] ?? 50) - ($rate_info['write']['current'] ?? 0),
            ];

            $read_remaining = $details['rate_limit']['read_remaining'];
            if ($read_remaining < 10) {
                $status = 'warning';
                $details['warning'] = 'Rate limit almost reached (' . $read_remaining . ' remaining)';
            }
        }

        // Get AI usage quota
        global $wpdb;
        $table = $wpdb->prefix . 'marylink_ia_usage';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $today = date('Y-m-d');
            $usage = $wpdb->get_row($wpdb->prepare(
                "SELECT SUM(tokens_used) as tokens, COUNT(*) as calls
                 FROM $table
                 WHERE user_id = %d AND DATE(created_at) = %s",
                $user_id, $today
            ));

            $details['ai_usage_today'] = [
                'tokens' => (int) ($usage->tokens ?? 0),
                'calls' => (int) ($usage->calls ?? 0),
            ];

            // Check against limits (configurable)
            $daily_token_limit = (int) get_option('mcpnh_daily_token_limit', 100000);
            if ($details['ai_usage_today']['tokens'] > $daily_token_limit * 0.9) {
                $status = 'warning';
                $details['warning'] = 'Approaching daily token limit';
            }
        }

        return [
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * Check cache status
     */
    private static function check_cache(): array {
        $status = 'ok';
        $details = [];

        // Check object cache
        $details['object_cache'] = wp_using_ext_object_cache();

        // Check Redis specifically
        $redis_available = false;
        if (class_exists('Redis')) {
            try {
                $redis = new \Redis();
                $redis_host = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1';
                $redis_port = defined('WP_REDIS_PORT') ? WP_REDIS_PORT : 6379;

                $connected = @$redis->connect($redis_host, $redis_port, 1);
                if ($connected) {
                    $redis_available = true;
                    $details['redis_info'] = [
                        'connected' => true,
                        'host' => $redis_host,
                        'memory_used' => $redis->info('memory')['used_memory_human'] ?? 'unknown',
                    ];
                    $redis->close();
                }
            } catch (\Exception $e) {
                $details['redis_error'] = $e->getMessage();
            }
        }

        $details['redis_available'] = $redis_available;

        // Transient cache test
        $test_key = 'mcpnh_ping_test_' . time();
        set_transient($test_key, 'test', 60);
        $details['transient_working'] = get_transient($test_key) === 'test';
        delete_transient($test_key);

        if (!$details['object_cache'] && !$redis_available) {
            $status = 'warning';
            $details['warning'] = 'No persistent object cache';
        }

        return [
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * Check external services status
     */
    private static function check_services(): array {
        $status = 'ok';
        $details = [];

        // Check OpenAI/Claude API availability (without making actual calls)
        $openai_key = get_option('openai_api_key') ?: get_option('mcpnh_openai_api_key');
        $anthropic_key = get_option('anthropic_api_key') ?: get_option('mcpnh_anthropic_api_key');

        $details['openai_configured'] = !empty($openai_key);
        $details['anthropic_configured'] = !empty($anthropic_key);

        if (!$details['openai_configured'] && !$details['anthropic_configured']) {
            $status = 'warning';
            $details['warning'] = 'No AI provider configured';
        }

        // Check BuddyPress
        $details['buddypress_active'] = function_exists('bp_is_active');
        if ($details['buddypress_active']) {
            $details['bp_components'] = [
                'activity' => bp_is_active('activity'),
                'groups' => bp_is_active('groups'),
                'members' => bp_is_active('members'),
            ];
        }

        return [
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * Check if user has MCP access
     */
    private static function user_has_mcp_access(int $user_id): bool {
        // Admin always has access
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        // Check MCP-specific capability
        if (user_can($user_id, 'use_mcp')) {
            return true;
        }

        // Check if user has any role that grants MCP access
        $mcp_roles = get_option('mcpnh_allowed_roles', ['administrator', 'editor']);
        $user = get_userdata($user_id);

        if ($user) {
            return !empty(array_intersect($user->roles, $mcp_roles));
        }

        return false;
    }

    /**
     * Generate human-readable summary
     */
    private static function generate_summary(string $status, array $checks, array $warnings): string {
        $emoji = match ($status) {
            'healthy' => '✅',
            'degraded' => '⚠️',
            'unhealthy' => '❌',
            default => '❓',
        };

        $summary = "$emoji MaryLink MCP v" . self::VERSION . " - ";

        switch ($status) {
            case 'healthy':
                $summary .= "All systems operational";
                break;
            case 'degraded':
                $summary .= "Degraded performance (" . implode(', ', $warnings) . ")";
                break;
            case 'unhealthy':
                $errors = [];
                foreach ($checks as $name => $check) {
                    if ($check['status'] === 'error') {
                        $errors[] = $name;
                    }
                }
                $summary .= "Critical issues: " . implode(', ', $errors);
                break;
        }

        return $summary;
    }
}
