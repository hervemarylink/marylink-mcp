<?php
/**
 * Audit Logger - Structured audit logging for MCP operations
 *
 * Logs all tool executions (read/write) for compliance and debugging.
 * Stores in custom table with configurable retention.
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Ops;

class Audit_Logger {

    private const TABLE_NAME = 'mcpnh_audit';
    private const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Log an operation
     *
     * @param array $data Log data
     * @return string|false Debug ID or false on failure
     */
    public static function log(array $data): string|false {
        global $wpdb;

        $debug_id = self::generate_debug_id();

        $record = [
            'debug_id' => $debug_id,
            'timestamp' => current_time('mysql', true),
            'user_id' => $data['user_id'] ?? get_current_user_id(),
            'tool_name' => $data['tool_name'] ?? 'unknown',
            'stage' => $data['stage'] ?? null,
            'target_type' => $data['target_type'] ?? null,
            'target_id' => $data['target_id'] ?? null,
            'result' => $data['result'] ?? 'unknown', // success, denied, error, rate_limited
            'error_code' => $data['error_code'] ?? null,
            'latency_ms' => $data['latency_ms'] ?? null,
            'request_hash' => $data['request_hash'] ?? self::hash_request($data['request_data'] ?? []),
            'ip_address' => self::get_client_ip(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'extra' => isset($data['extra']) ? wp_json_encode($data['extra']) : null,
        ];

        $table = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->insert($table, $record, [
            '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s'
        ]);

        if ($result === false) {
            error_log('Audit_Logger: Failed to insert log - ' . $wpdb->last_error);
            return false;
        }

        return $debug_id;
    }

    /**
     * Log a tool execution (convenience method)
     */
    public static function log_tool(
        string $tool_name,
        int $user_id,
        string $result,
        array $args = [],
        ?string $stage = null,
        ?int $latency_ms = null,
        ?string $error_code = null
    ): string|false {
        // Extract target info from args
        $target_type = null;
        $target_id = null;

        if (isset($args['publication_id'])) {
            $target_type = 'publication';
            $target_id = (int) $args['publication_id'];
        } elseif (isset($args['space_id'])) {
            $target_type = 'space';
            $target_id = (int) $args['space_id'];
        } elseif (isset($args['group_id'])) {
            $target_type = 'group';
            $target_id = (int) $args['group_id'];
        } elseif (isset($args['activity_id'])) {
            $target_type = 'activity';
            $target_id = (int) $args['activity_id'];
        } elseif (isset($args['user_id'])) {
            $target_type = 'user';
            $target_id = (int) $args['user_id'];
        } elseif (isset($args['tool_id'])) {
            $target_type = 'tool';
            $target_id = (int) $args['tool_id'];
        }

        return self::log([
            'user_id' => $user_id,
            'tool_name' => $tool_name,
            'stage' => $stage,
            'target_type' => $target_type,
            'target_id' => $target_id,
            'result' => $result,
            'error_code' => $error_code,
            'latency_ms' => $latency_ms,
            'request_data' => $args,
        ]);
    }

    /**
     * Get audit logs with filters
     */
    public static function get_logs(array $filters = [], int $limit = 100, int $offset = 0): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        if (!empty($filters['tool_name'])) {
            $where[] = 'tool_name = %s';
            $params[] = $filters['tool_name'];
        }

        if (!empty($filters['result'])) {
            $where[] = 'result = %s';
            $params[] = $filters['result'];
        }

        if (!empty($filters['debug_id'])) {
            $where[] = 'debug_id = %s';
            $params[] = $filters['debug_id'];
        }

        if (!empty($filters['target_type'])) {
            $where[] = 'target_type = %s';
            $params[] = $filters['target_type'];
        }

        if (!empty($filters['target_id'])) {
            $where[] = 'target_id = %d';
            $params[] = (int) $filters['target_id'];
        }

        if (!empty($filters['since'])) {
            $where[] = 'timestamp >= %s';
            $params[] = $filters['since'];
        }

        if (!empty($filters['until'])) {
            $where[] = 'timestamp <= %s';
            $params[] = $filters['until'];
        }

        $where_sql = implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT * FROM $table WHERE $where_sql ORDER BY timestamp DESC LIMIT %d OFFSET %d";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get a single log by debug_id
     */
    public static function get_by_debug_id(string $debug_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE debug_id = %s", $debug_id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get statistics for admin dashboard
     */
    public static function get_stats(string $period = '24h'): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $since = match($period) {
            '1h' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            '24h' => date('Y-m-d H:i:s', strtotime('-24 hours')),
            '7d' => date('Y-m-d H:i:s', strtotime('-7 days')),
            '30d' => date('Y-m-d H:i:s', strtotime('-30 days')),
            default => date('Y-m-d H:i:s', strtotime('-24 hours')),
        };

        // Total requests
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE timestamp >= %s",
            $since
        ));

        // By result
        $by_result = $wpdb->get_results($wpdb->prepare(
            "SELECT result, COUNT(*) as count FROM $table WHERE timestamp >= %s GROUP BY result",
            $since
        ), ARRAY_A);

        // Top tools
        $top_tools = $wpdb->get_results($wpdb->prepare(
            "SELECT tool_name, COUNT(*) as count FROM $table WHERE timestamp >= %s GROUP BY tool_name ORDER BY count DESC LIMIT 10",
            $since
        ), ARRAY_A);

        // Errors count
        $errors = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE timestamp >= %s AND result IN ('error', 'denied', 'rate_limited')",
            $since
        ));

        // Avg latency
        $avg_latency = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(latency_ms) FROM $table WHERE timestamp >= %s AND latency_ms IS NOT NULL",
            $since
        ));

        // Active users
        $active_users = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM $table WHERE timestamp >= %s",
            $since
        ));

        return [
            'period' => $period,
            'since' => $since,
            'total_requests' => $total,
            'errors' => $errors,
            'error_rate' => $total > 0 ? round(($errors / $total) * 100, 2) : 0,
            'avg_latency_ms' => round($avg_latency, 2),
            'active_users' => $active_users,
            'by_result' => array_column($by_result, 'count', 'result'),
            'top_tools' => $top_tools,
        ];
    }

    /**
     * Purge old logs (for cron)
     */
    public static function purge_old_logs(?int $retention_days = null): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $retention_days = $retention_days ?? (int) get_option('mcpnh_audit_retention_days', self::DEFAULT_RETENTION_DAYS);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE timestamp < %s",
            $cutoff
        ));

        return $deleted !== false ? $deleted : 0;
    }

    /**
     * Create the audit table (called on plugin activation)
     */
    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            debug_id VARCHAR(36) NOT NULL,
            timestamp DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            tool_name VARCHAR(100) NOT NULL,
            stage VARCHAR(20) DEFAULT NULL,
            target_type VARCHAR(50) DEFAULT NULL,
            target_id BIGINT UNSIGNED DEFAULT NULL,
            result VARCHAR(20) NOT NULL DEFAULT 'unknown',
            error_code VARCHAR(50) DEFAULT NULL,
            latency_ms INT UNSIGNED DEFAULT NULL,
            request_hash VARCHAR(64) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            extra TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY debug_id (debug_id),
            KEY idx_user_id (user_id),
            KEY idx_tool_name (tool_name),
            KEY idx_result (result),
            KEY idx_timestamp (timestamp),
            KEY idx_target (target_type, target_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Check if table exists
     */
    public static function table_exists(): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        return $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    }

    /**
     * Generate a unique debug ID
     */
    private static function generate_debug_id(): string {
        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6))
        );
    }

    /**
     * Hash request data (for correlation without storing sensitive data)
     */
    private static function hash_request(array $data): string {
        // Remove potentially sensitive fields
        unset($data['content'], $data['text'], $data['input_text'], $data['final_text']);
        return hash('sha256', wp_json_encode($data));
    }

    /**
     * Get client IP address
     */
    private static function get_client_ip(): ?string {
        // Check for proxy headers
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated list (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Register cron for purging old logs
     */
    public static function register_cron(): void {
        if (!wp_next_scheduled('mcpnh_purge_audit_logs')) {
            wp_schedule_event(time(), 'daily', 'mcpnh_purge_audit_logs');
        }
        add_action('mcpnh_purge_audit_logs', [self::class, 'purge_old_logs']);
    }

    /**
     * Unregister cron
     */
    public static function unregister_cron(): void {
        $timestamp = wp_next_scheduled('mcpnh_purge_audit_logs');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'mcpnh_purge_audit_logs');
        }
    }
}
