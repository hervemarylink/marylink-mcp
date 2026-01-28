<?php
/**
 * Token Manager - Generates and validates unique MCP tokens per user
 *
 * Phase 5 additions:
 * - Token scopes (read:content, write:content, read:social, write:social)
 * - Token revocation
 * - Token rotation with audit logging
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\User;

use MCP_No_Headless\Ops\Audit_Logger;

class Token_Manager {

    /**
     * User meta key for storing the MCP token (HASH only, post-migration)
     */
    const META_KEY = '_mlmcp_bearer_token';

    /**
     * User meta key for storing token hash (new secure storage)
     */
    const META_KEY_HASH = '_mlmcp_token_hash';

    /**
     * User meta key for token creation date
     */
    const META_KEY_CREATED = '_mlmcp_token_created';

    /**
     * User meta key for token scopes
     */
    const META_KEY_SCOPES = '_mlmcp_token_scopes';

    /**
     * User meta key for token revocation
     */
    const META_KEY_REVOKED = '_mlmcp_token_revoked';

    /**
     * Flag to indicate token has been migrated to hash
     */
    const META_KEY_MIGRATED = '_mlmcp_token_migrated';

    /**
     * Available scopes
     */
    const SCOPE_READ_CONTENT = 'read:content';
    const SCOPE_WRITE_CONTENT = 'write:content';
    const SCOPE_READ_SOCIAL = 'read:social';
    const SCOPE_WRITE_SOCIAL = 'write:social';

    /**
     * Default scopes for new tokens
     */
    const DEFAULT_SCOPES = [
        self::SCOPE_READ_CONTENT,
        self::SCOPE_READ_SOCIAL,
        // write scopes disabled by default for security
    ];

    /**
     * All available scopes
     */
    const ALL_SCOPES = [
        self::SCOPE_READ_CONTENT,
        self::SCOPE_WRITE_CONTENT,
        self::SCOPE_READ_SOCIAL,
        self::SCOPE_WRITE_SOCIAL,
    ];

    /**
     * Current validated user ID (set during authentication)
     */
    private static ?int $current_token_user = null;

    /**
     * Current token scopes (set during authentication)
     */
    private static array $current_token_scopes = [];

    /**
     * Current token hash (for rate limiting)
     */
    private static ?string $current_token_hash = null;

    /**
     * Constructor - Register hooks
     */
    public function __construct() {
        // Hook into AI Engine's MCP authentication
        add_filter('mwai_allow_mcp', [$this, 'authenticate_mcp_request'], 10, 2);

        // AJAX handlers for token management
        add_action('wp_ajax_mlmcp_regenerate_token', [$this, 'ajax_regenerate_token']);
        add_action('wp_ajax_mlmcp_revoke_token', [$this, 'ajax_revoke_token']);
        add_action('wp_ajax_mlmcp_update_scopes', [$this, 'ajax_update_scopes']);
    }

    /**
     * Get or create a token for a user
     *
     * @param int $user_id User ID
     * @return string The MCP bearer token
     */
    public function get_or_create_token(int $user_id): string {
        $token = get_user_meta($user_id, self::META_KEY, true);

        if (empty($token)) {
            $token = $this->generate_token($user_id);
        }

        return $token;
    }

    /**
     * Generate a new token for a user
     *
     * SECURITY: Token is returned ONLY at creation time.
     * We store the hash, never the plaintext.
     *
     * @param int $user_id User ID
     * @return string The new token (plaintext - only time it's shown)
     */
    public function generate_token(int $user_id): string {
        // Generate a secure random token
        $plain_token = $this->create_secure_token($user_id);

        // Store HASH only (never plaintext)
        $token_hash = hash('sha256', $plain_token);
        update_user_meta($user_id, self::META_KEY_HASH, $token_hash);
        update_user_meta($user_id, self::META_KEY_CREATED, current_time('mysql'));
        update_user_meta($user_id, self::META_KEY_MIGRATED, current_time('mysql'));

        // Delete any legacy plaintext token
        delete_user_meta($user_id, self::META_KEY);

        // Clear revoked flag
        delete_user_meta($user_id, self::META_KEY_REVOKED);

        return $plain_token; // Only time user sees this
    }

    /**
     * Regenerate token for a user (invalidates old token)
     *
     * @param int $user_id User ID
     * @return string The new token
     */
    public function regenerate_token(int $user_id): string {
        return $this->generate_token($user_id);
    }

    /**
     * Validate a token and return the associated user ID
     *
     * SECURITY: Supports hash-based validation with automatic migration
     * from legacy plaintext tokens.
     *
     * @param string $token The bearer token (plaintext from user)
     * @return int|null User ID if valid, null otherwise
     */
    public function validate_token(string $token): ?int {
        global $wpdb;

        // Calculate hash of incoming token
        $token_hash = hash('sha256', $token);

        // 1. First, try hash-based lookup (new secure method)
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            self::META_KEY_HASH,
            $token_hash
        ));

        if ($user_id) {
            return (int) $user_id;
        }

        // 2. Fallback: try legacy plaintext lookup
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            self::META_KEY,
            $token
        ));

        if ($user_id) {
            // 3. MIGRATE: First successful legacy validation → convert to hash
            $this->migrate_token_to_hash((int) $user_id, $token);
            return (int) $user_id;
        }

        return null;
    }

    /**
     * Migrate a legacy plaintext token to hash-based storage
     *
     * @param int $user_id User ID
     * @param string $plain_token The plaintext token
     */
    private function migrate_token_to_hash(int $user_id, string $plain_token): void {
        // Store hash
        $token_hash = hash('sha256', $plain_token);
        update_user_meta($user_id, self::META_KEY_HASH, $token_hash);

        // Delete plaintext (security)
        delete_user_meta($user_id, self::META_KEY);

        // Mark as migrated
        update_user_meta($user_id, self::META_KEY_MIGRATED, current_time('mysql'));

        // Log migration
        if (class_exists(Audit_Logger::class)) {
            Audit_Logger::log([
                'tool_name' => 'token_migrated_to_hash',
                'user_id' => $user_id,
                'result' => 'success',
            ]);
        }
    }

    /**
     * Delete token for a user
     *
     * @param int $user_id User ID
     * @return bool
     */
    public function delete_token(int $user_id): bool {
        delete_user_meta($user_id, self::META_KEY);      // Legacy plaintext
        delete_user_meta($user_id, self::META_KEY_HASH); // New hash
        delete_user_meta($user_id, self::META_KEY_CREATED);
        delete_user_meta($user_id, self::META_KEY_MIGRATED);
        return true;
    }

    /**
     * Create a secure token
     *
     * @param int $user_id User ID (used as salt)
     * @return string
     */
    private function create_secure_token(int $user_id): string {
        // Generate random bytes
        $random = bin2hex(random_bytes(32));

        // Add user-specific salt
        $salt = wp_salt('auth') . $user_id . time();

        // Create hash
        $token = hash('sha256', $random . $salt);

        // Prefix for easy identification
        return 'mlmcp_' . substr($token, 0, 48);
    }

    /**
     * Authenticate MCP requests using our custom tokens
     * Hooks into AI Engine's mwai_allow_mcp filter
     *
     * @param bool $allowed Current allowed status
     * @param array $params Request parameters
     * @return bool
     */
    public function authenticate_mcp_request($allowed, $params): bool {
        // If already allowed by AI Engine, check our token too
        $headers = getallheaders();
        $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (preg_match('/Bearer\s+(\S+)/i', $auth_header, $matches)) {
            $token = $matches[1];

            // Check if it's our custom token (starts with mlmcp_)
            if (strpos($token, 'mlmcp_') === 0) {
                $user_id = $this->validate_token($token);

                if ($user_id) {
                    // Set the current user for permission checks
                    wp_set_current_user($user_id);
                    return true;
                }

                return false;
            }
        }

        // Fall back to AI Engine's default auth
        return $allowed;
    }

    /**
     * AJAX handler to regenerate token
     */
    public function ajax_regenerate_token(): void {
        // Verify nonce
        if (!check_ajax_referer('mlmcp_regenerate_token', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'marylink-mcp-tools')]);
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Not logged in', 'marylink-mcp-tools')]);
        }

        $new_token = $this->regenerate_token($user_id);

        wp_send_json_success([
            'token' => $new_token,
            'message' => __('Token regenerated successfully', 'marylink-mcp-tools')
        ]);
    }

    /**
     * Get token creation date for a user
     *
     * @param int $user_id User ID
     * @return string|null Date string or null
     */
    public function get_token_created_date(int $user_id): ?string {
        return get_user_meta($user_id, self::META_KEY_CREATED, true) ?: null;
    }

    // ==========================================
    // SCOPES MANAGEMENT
    // ==========================================

    /**
     * Get scopes for a user's token
     *
     * @param int $user_id User ID
     * @return array Scopes array
     */
    public function get_token_scopes(int $user_id): array {
        $scopes = get_user_meta($user_id, self::META_KEY_SCOPES, true);
        return is_array($scopes) ? $scopes : self::DEFAULT_SCOPES;
    }

    /**
     * Set scopes for a user's token
     *
     * @param int $user_id User ID
     * @param array $scopes Scopes to set
     * @return bool
     */
    public function set_token_scopes(int $user_id, array $scopes): bool {
        // Validate scopes
        $valid_scopes = array_intersect($scopes, self::ALL_SCOPES);
        update_user_meta($user_id, self::META_KEY_SCOPES, $valid_scopes);

        // Log the scope change
        if (class_exists(Audit_Logger::class)) {
            Audit_Logger::log([
                'tool_name' => 'token_scopes_updated',
                'user_id' => $user_id,
                'result' => 'success',
                'extra' => ['scopes' => $valid_scopes],
            ]);
        }

        return true;
    }

    /**
     * Check if current token has a specific scope
     *
     * @param string $scope Scope to check
     * @return bool
     */
    public static function has_scope(string $scope): bool {
        return in_array($scope, self::$current_token_scopes, true);
    }

    /**
     * Check if current token can perform write operations on content
     */
    public static function can_write_content(): bool {
        return self::has_scope(self::SCOPE_WRITE_CONTENT);
    }

    /**
     * Check if current token can perform write operations on social
     */
    public static function can_write_social(): bool {
        return self::has_scope(self::SCOPE_WRITE_SOCIAL);
    }

    /**
     * Get current token hash (for rate limiting)
     */
    public static function get_current_token_hash(): ?string {
        return self::$current_token_hash;
    }

    /**
     * Get required scope for a tool
     *
     * @param string $tool Tool name
     * @param string|null $stage Stage (for apply_tool)
     * @return string|null Required scope or null if no scope required
     */
    public static function get_required_scope(string $tool, ?string $stage = null): ?string {
        // Write content tools
        $write_content_tools = [
            'ml_create_publication',
            'ml_create_publication_from_text',
            'ml_edit_publication',
            'ml_append_to_publication',
            'ml_add_comment',
            'ml_import_as_comment',
            'ml_create_review',
            'ml_move_to_step',
            'ml_apply_tool_commit', // Commit phase requires write
        ];

        // Write social tools
        $write_social_tools = [
            'ml_activity_post',
            'ml_activity_comment',
            'ml_group_join_request',
            'ml_activity_post_commit',
            'ml_activity_comment_commit',
        ];

        // Read social tools
        $read_social_tools = [
            'ml_groups_search',
            'ml_group_fetch',
            'ml_group_members',
            'ml_activity_list',
            'ml_activity_fetch',
            'ml_activity_comments',
            'ml_members_search',
            'ml_member_fetch',
            'ml_activity_post_prepare',
            'ml_activity_comment_prepare',
        ];

        // Read content tools (explicit)
        $read_content_tools = [
            'ml_recommend',
            'ml_recommend_styles',
            'ml_apply_tool_prepare', // Prepare phase is read-only
            'ml_context_bundle_build',
        ];

        // ml_apply_tool depends on stage (legacy)
        if ($tool === 'ml_apply_tool') {
            return $stage === 'commit' ? self::SCOPE_WRITE_CONTENT : self::SCOPE_READ_CONTENT;
        }

        if (in_array($tool, $write_content_tools, true)) {
            return self::SCOPE_WRITE_CONTENT;
        }

        if (in_array($tool, $write_social_tools, true)) {
            return self::SCOPE_WRITE_SOCIAL;
        }

        if (in_array($tool, $read_social_tools, true)) {
            return self::SCOPE_READ_SOCIAL;
        }

        if (in_array($tool, $read_content_tools, true)) {
            return self::SCOPE_READ_CONTENT;
        }

        // Default read content for other tools
        return self::SCOPE_READ_CONTENT;
    }

    // ==========================================
    // TOKEN REVOCATION
    // ==========================================

    /**
     * Revoke a user's token
     *
     * @param int $user_id User ID
     * @return bool
     */
    public function revoke_token(int $user_id): bool {
        // Mark as revoked (keep timestamp for audit trail)
        update_user_meta($user_id, self::META_KEY_REVOKED, current_time('mysql'));

        // Delete both legacy and new hash tokens
        delete_user_meta($user_id, self::META_KEY);      // Legacy
        delete_user_meta($user_id, self::META_KEY_HASH); // New

        // Log revocation
        if (class_exists(Audit_Logger::class)) {
            Audit_Logger::log([
                'tool_name' => 'token_revoked',
                'user_id' => $user_id,
                'result' => 'success',
            ]);
        }

        return true;
    }

    /**
     * Check if a user's token is revoked
     *
     * @param int $user_id User ID
     * @return bool
     */
    public function is_token_revoked(int $user_id): bool {
        $revoked = get_user_meta($user_id, self::META_KEY_REVOKED, true);
        $token_legacy = get_user_meta($user_id, self::META_KEY, true);
        $token_hash = get_user_meta($user_id, self::META_KEY_HASH, true);

        // Revoked if: has revocation date AND no current token (neither legacy nor hash)
        return !empty($revoked) && empty($token_legacy) && empty($token_hash);
    }

    /**
     * Get token info for admin display
     *
     * SECURITY: Never exposes the actual token or hash.
     *
     * @param int $user_id User ID
     * @return array Token info
     */
    public function get_token_info(int $user_id): array {
        $token_legacy = get_user_meta($user_id, self::META_KEY, true);
        $token_hash = get_user_meta($user_id, self::META_KEY_HASH, true);
        $created = get_user_meta($user_id, self::META_KEY_CREATED, true);
        $migrated = get_user_meta($user_id, self::META_KEY_MIGRATED, true);
        $scopes = $this->get_token_scopes($user_id);
        $revoked = get_user_meta($user_id, self::META_KEY_REVOKED, true);

        $has_token = !empty($token_legacy) || !empty($token_hash);

        return [
            'has_token' => $has_token,
            'token_hint' => $has_token ? 'mlmcp_***' . ($token_hash ? substr($token_hash, -4) : '****') : null,
            'storage_type' => $token_hash ? 'hash' : ($token_legacy ? 'legacy' : null),
            'created' => $created ?: null,
            'created_human' => $created ? human_time_diff(strtotime($created)) . ' ago' : null,
            'migrated_at' => $migrated ?: null,
            'scopes' => $scopes,
            'is_revoked' => $this->is_token_revoked($user_id),
            'last_revoked' => $revoked ?: null,
        ];
    }

    // ==========================================
    // AJAX HANDLERS
    // ==========================================

    /**
     * AJAX handler to revoke token
     */
    public function ajax_revoke_token(): void {
        if (!check_ajax_referer('mlmcp_revoke_token', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'mcp-no-headless')]);
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Not logged in', 'mcp-no-headless')]);
        }

        $this->revoke_token($user_id);

        wp_send_json_success([
            'message' => __('Token revoked successfully. Generate a new one when needed.', 'mcp-no-headless'),
        ]);
    }

    /**
     * AJAX handler to update scopes
     */
    public function ajax_update_scopes(): void {
        if (!check_ajax_referer('mlmcp_update_scopes', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'mcp-no-headless')]);
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('Not logged in', 'mcp-no-headless')]);
        }

        $scopes = isset($_POST['scopes']) ? (array) $_POST['scopes'] : [];
        $this->set_token_scopes($user_id, $scopes);

        wp_send_json_success([
            'scopes' => $this->get_token_scopes($user_id),
            'message' => __('Scopes updated successfully', 'mcp-no-headless'),
        ]);
    }

    // ==========================================
    // ENHANCED AUTHENTICATION
    // ==========================================

    /**
     * Validate a token with full checks (scopes, revocation)
     *
     * @param string $token The bearer token
     * @return array ['valid' => bool, 'user_id' => int|null, 'scopes' => array, 'error' => string|null]
     */
    public function validate_token_full(string $token): array {
        $user_id = $this->validate_token($token);

        if (!$user_id) {
            return ['valid' => false, 'user_id' => null, 'scopes' => [], 'error' => 'invalid_token'];
        }

        // Check if revoked
        if ($this->is_token_revoked($user_id)) {
            return ['valid' => false, 'user_id' => null, 'scopes' => [], 'error' => 'token_revoked'];
        }

        $scopes = $this->get_token_scopes($user_id);

        // Store for later use
        self::$current_token_user = $user_id;
        self::$current_token_scopes = $scopes;
        self::$current_token_hash = hash('sha256', $token);

        return ['valid' => true, 'user_id' => $user_id, 'scopes' => $scopes, 'error' => null];
    }
}
