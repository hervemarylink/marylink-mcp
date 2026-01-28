<?php
/**
 * Mission Token Manager - B2B2B tokens scoped to specific spaces
 *
 * A "mission token" is a token that:
 * - Is owned by a user (the issuer)
 * - Has a label (e.g., "Client A", "Project X")
 * - Is restricted to specific space IDs
 * - Has specific scopes
 * - Can be revoked independently
 * - Tracks usage for audit
 *
 * Use case: Cabinet gives a "Client A" token that only accesses space:A
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\User;

use MCP_No_Headless\Ops\Audit_Logger;

class Mission_Token_Manager {

    /**
     * Table name (without prefix)
     */
    const TABLE_NAME = 'mcpnh_mission_tokens';

    /**
     * Token prefix for identification
     */
    const TOKEN_PREFIX = 'mlmis_';

    /**
     * Current mission token data (set during authentication)
     */
    private static ?array $current_mission_token = null;

    /**
     * Get table name with prefix
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create the mission tokens table
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(64) NOT NULL,
            owner_user_id BIGINT(20) UNSIGNED NOT NULL,
            label VARCHAR(255) NOT NULL,
            scopes TEXT NOT NULL,
            allowed_space_ids TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at DATETIME DEFAULT NULL,
            last_used_at DATETIME DEFAULT NULL,
            usage_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY owner_user_id (owner_user_id),
            KEY revoked_at (revoked_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create a new mission token
     *
     * SECURITY: Token is returned ONLY at creation time.
     * We store a SHA-256 hash in the database, never the plain token.
     *
     * @param int $owner_user_id Owner user ID
     * @param string $label Label for the token
     * @param array $scopes Allowed scopes
     * @param array $allowed_space_ids Allowed space IDs
     * @param array $options Optional: expires_at, notes
     * @return array Token data including the full token (ONLY TIME IT'S SHOWN)
     */
    public function create_token(
        int $owner_user_id,
        string $label,
        array $scopes,
        array $allowed_space_ids,
        array $options = []
    ): array {
        global $wpdb;

        // Generate secure token
        $plain_token = $this->generate_token();

        // Store HASH only (security: plain token never stored)
        $token_hash = hash('sha256', $plain_token);

        // Prepare data
        $data = [
            'token' => $token_hash,
            'owner_user_id' => $owner_user_id,
            'label' => sanitize_text_field($label),
            'scopes' => json_encode(array_values(array_filter($scopes))),
            'allowed_space_ids' => json_encode(array_map('intval', $allowed_space_ids)),
            'created_at' => current_time('mysql'),
            'expires_at' => $options['expires_at'] ?? null,
            'notes' => isset($options['notes']) ? sanitize_textarea_field($options['notes']) : null,
        ];

        $wpdb->insert(self::get_table_name(), $data);
        $token_id = $wpdb->insert_id;

        if (!$token_id) {
            throw new \Exception('Failed to create mission token');
        }

        // Log creation
        if (class_exists(Audit_Logger::class)) {
            Audit_Logger::log([
                'tool_name' => 'mission_token_created',
                'user_id' => $owner_user_id,
                'result' => 'success',
                'extra' => [
                    'token_id' => $token_id,
                    'label' => $label,
                    'scopes' => $scopes,
                    'space_count' => count($allowed_space_ids),
                ],
            ]);
        }

        // Return plain token ONLY at creation (never again)
        return [
            'id' => $token_id,
            'token' => $plain_token,  // ONLY TIME USER SEES THIS
            'label' => $label,
            'scopes' => $scopes,
            'allowed_space_ids' => $allowed_space_ids,
            'created_at' => $data['created_at'],
            'expires_at' => $data['expires_at'],
            'warning' => 'Store this token securely. It will NOT be shown again.',
        ];
    }

    /**
     * Generate a secure mission token
     */
    private function generate_token(): string {
        $random = bin2hex(random_bytes(32));
        $salt = wp_salt('auth') . time();
        $hash = hash('sha256', $random . $salt);
        return self::TOKEN_PREFIX . substr($hash, 0, 48);
    }

    /**
     * Validate a mission token
     *
     * SECURITY: We hash the incoming token and compare with stored hash.
     *
     * @param string $token The plain token to validate
     * @return array|null Token data or null if invalid
     */
    public function validate_token(string $token): ?array {
        global $wpdb;

        // Must have mission token prefix
        if (strpos($token, self::TOKEN_PREFIX) !== 0) {
            return null;
        }

        // Hash the incoming token to compare with stored hash
        $token_hash = hash('sha256', $token);

        $table = self::get_table_name();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE token = %s AND revoked_at IS NULL",
            $token_hash
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        // Check expiration
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return null;
        }

        // Update usage
        $wpdb->update(
            $table,
            [
                'last_used_at' => current_time('mysql'),
                'usage_count' => $row['usage_count'] + 1,
            ],
            ['id' => $row['id']]
        );

        // Store for later use
        self::$current_mission_token = [
            'id' => (int) $row['id'],
            'owner_user_id' => (int) $row['owner_user_id'],
            'label' => $row['label'],
            'scopes' => json_decode($row['scopes'], true) ?: [],
            'allowed_space_ids' => json_decode($row['allowed_space_ids'], true) ?: [],
        ];

        return self::$current_mission_token;
    }

    /**
     * Get current mission token data (set during authentication)
     */
    public static function get_current_mission_token(): ?array {
        return self::$current_mission_token;
    }

    /**
     * Check if current request is using a mission token
     */
    public static function is_mission_token_request(): bool {
        return self::$current_mission_token !== null;
    }

    /**
     * Get allowed space IDs for current mission token
     *
     * @return array|null Space IDs or null if not a mission token
     */
    public static function get_allowed_space_ids(): ?array {
        if (self::$current_mission_token === null) {
            return null;
        }
        return self::$current_mission_token['allowed_space_ids'];
    }

    /**
     * Check if a space is allowed for current mission token
     *
     * @param int $space_id Space ID to check
     * @return bool True if allowed (or not a mission token), false otherwise
     */
    public static function is_space_allowed(int $space_id): bool {
        if (self::$current_mission_token === null) {
            return true; // Not a mission token, no restriction
        }

        $allowed = self::$current_mission_token['allowed_space_ids'];
        return in_array($space_id, $allowed, true);
    }

    /**
     * Revoke a mission token
     *
     * @param int $token_id Token ID
     * @param int $revoking_user_id User revoking the token
     * @return bool
     */
    public function revoke_token(int $token_id, int $revoking_user_id): bool {
        global $wpdb;

        $table = self::get_table_name();

        // Get token info for audit
        $token_info = $this->get_token_by_id($token_id);
        if (!$token_info) {
            return false;
        }

        // Only owner can revoke
        if ((int) $token_info['owner_user_id'] !== $revoking_user_id && !current_user_can('manage_options')) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            ['revoked_at' => current_time('mysql')],
            ['id' => $token_id]
        );

        if ($result !== false) {
            // Log revocation
            if (class_exists(Audit_Logger::class)) {
                Audit_Logger::log([
                    'tool_name' => 'mission_token_revoked',
                    'user_id' => $revoking_user_id,
                    'result' => 'success',
                    'extra' => [
                        'token_id' => $token_id,
                        'label' => $token_info['label'],
                    ],
                ]);
            }
            return true;
        }

        return false;
    }

    /**
     * Get token by ID
     *
     * @param int $token_id Token ID
     * @return array|null Token data
     */
    public function get_token_by_id(int $token_id): ?array {
        global $wpdb;
        $table = self::get_table_name();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $token_id
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        return $this->format_token_row($row);
    }

    /**
     * List tokens for a user
     *
     * @param int $user_id User ID
     * @param bool $include_revoked Include revoked tokens
     * @return array Tokens
     */
    public function list_user_tokens(int $user_id, bool $include_revoked = false): array {
        global $wpdb;
        $table = self::get_table_name();

        $where = $include_revoked ? '' : ' AND revoked_at IS NULL';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE owner_user_id = %d {$where} ORDER BY created_at DESC",
            $user_id
        ), ARRAY_A);

        return array_map([$this, 'format_token_row'], $rows ?: []);
    }

    /**
     * Format token row for output (NEVER expose hash or token)
     */
    private function format_token_row(array $row): array {
        return [
            'id' => (int) $row['id'],
            'token_hint' => 'mlmis_***' . substr($row['token'], -4),  // Only last 4 chars of HASH (not token)
            'owner_user_id' => (int) $row['owner_user_id'],
            'label' => $row['label'],
            'scopes' => json_decode($row['scopes'], true) ?: [],
            'allowed_space_ids' => json_decode($row['allowed_space_ids'], true) ?: [],
            'created_at' => $row['created_at'],
            'revoked_at' => $row['revoked_at'],
            'last_used_at' => $row['last_used_at'],
            'usage_count' => (int) $row['usage_count'],
            'expires_at' => $row['expires_at'],
            'is_active' => empty($row['revoked_at']) && (empty($row['expires_at']) || strtotime($row['expires_at']) > time()),
        ];
    }

    /**
     * Get audit logs for a mission token
     *
     * @param int $token_id Token ID
     * @param int $limit Max results
     * @return array Audit logs
     */
    public function get_token_audit(int $token_id, int $limit = 100): array {
        // Get token info
        $token_info = $this->get_token_by_id($token_id);
        if (!$token_info) {
            return [];
        }

        // Query audit logs by token_id in extra
        if (!class_exists(Audit_Logger::class)) {
            return [];
        }

        return Audit_Logger::get_logs([
            'extra_like' => '"mission_token_id":' . $token_id,
        ], $limit);
    }

    /**
     * Export audit logs for a token as CSV
     *
     * @param int $token_id Token ID
     * @param string|null $since Start date
     * @param string|null $until End date
     * @return string CSV content
     */
    public function export_token_audit_csv(int $token_id, ?string $since = null, ?string $until = null): string {
        $logs = $this->get_token_audit($token_id, 10000);

        // Filter by date if provided
        if ($since || $until) {
            $logs = array_filter($logs, function ($log) use ($since, $until) {
                $log_time = strtotime($log['created_at']);
                if ($since && $log_time < strtotime($since)) {
                    return false;
                }
                if ($until && $log_time > strtotime($until)) {
                    return false;
                }
                return true;
            });
        }

        // Build CSV
        $csv = "Date,Tool,User,Result,Latency(ms),Details\n";
        foreach ($logs as $log) {
            $csv .= sprintf(
                '"%s","%s",%d,"%s",%d,"%s"' . "\n",
                $log['created_at'],
                $log['tool_name'],
                $log['user_id'],
                $log['result'],
                $log['latency_ms'] ?? 0,
                str_replace('"', '""', json_encode($log['extra'] ?? []))
            );
        }

        return $csv;
    }

    /**
     * Check if table exists
     */
    public static function table_exists(): bool {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
    }
}
