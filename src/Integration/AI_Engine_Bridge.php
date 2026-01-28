<?php
/**
 * AI Engine Bridge - Integration with AI Engine Pro
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Integration;

class AI_Engine_Bridge {

    /**
     * Constructor - Register hooks
     */
    public function __construct() {
        // Add user context to AI queries
        add_filter('mwai_ai_context', [$this, 'add_user_context'], 10, 2);

        // Log MCP activity
        add_action('mwai_mcp_request', [$this, 'log_mcp_request'], 10, 2);
    }

    /**
     * Add user AI context to queries
     *
     * @param string $context Current context
     * @param array $params Query parameters
     * @return string Modified context
     */
    public function add_user_context(string $context, array $params): string {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return $context;
        }

        // Get user's AI context from profile
        $user_context = $this->get_user_ai_context($user_id);

        if (!empty($user_context)) {
            $context .= "\n\n--- Contexte Utilisateur ---\n" . $user_context;
        }

        // Add user info summary
        $user = get_userdata($user_id);
        if ($user) {
            $context .= "\n\nUtilisateur actuel: {$user->display_name}";
            $context .= "\nRôles: " . implode(', ', $user->roles);
        }

        return $context;
    }

    /**
     * Get user's AI context from profile
     *
     * @param int $user_id User ID
     * @return string
     */
    private function get_user_ai_context(int $user_id): string {
        // Try BuddyBoss xprofile field (field 186)
        if (function_exists('bp_get_profile_field_data')) {
            $context = bp_get_profile_field_data([
                'field' => 186, // "Ce que l'IA doit savoir sur vous"
                'user_id' => $user_id,
            ]);

            if (!empty($context)) {
                return $context;
            }
        }

        // Fallback to user meta
        return get_user_meta($user_id, '_mlai_user_context', true) ?: '';
    }

    /**
     * Log MCP request for analytics
     *
     * @param array $request Request data
     * @param int $user_id User ID
     */
    public function log_mcp_request(array $request, int $user_id): void {
        // Store basic analytics
        $log_key = 'mlmcp_requests_' . date('Y-m');
        $logs = get_option($log_key, []);

        $logs[] = [
            'user_id' => $user_id,
            'tool' => $request['method'] ?? 'unknown',
            'timestamp' => current_time('mysql'),
        ];

        // Keep only last 1000 entries per month
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }

        update_option($log_key, $logs, false);
    }
}
