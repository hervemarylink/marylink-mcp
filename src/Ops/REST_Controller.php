<?php
/**
 * REST Controller - Ops endpoints for monitoring and admin actions
 *
 * Endpoints:
 * - GET  /wp-json/marylink-mcp/v1/health       - Public health check
 * - GET  /wp-json/marylink-mcp/v1/health/full  - Admin full diagnostics
 * - POST /wp-json/marylink-mcp/v1/recalculate-scores - Trigger score recalc
 * - GET  /wp-json/marylink-mcp/v1/audit        - Admin audit logs
 * - GET  /wp-json/marylink-mcp/v1/rate-limits  - User rate limit status
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Ops;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use MCP_No_Headless\User\Mission_Token_Manager;

class REST_Controller {

    const NAMESPACE = 'marylink-mcp/v1';

    /**
     * Register REST routes
     */
    public static function register_routes(): void {
        // Public health endpoint
        register_rest_route(self::NAMESPACE, '/health', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_health'],
            'permission_callback' => '__return_true',
        ]);

        // Full health (admin only)
        register_rest_route(self::NAMESPACE, '/health/full', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_health_full'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        // Diagnostics (admin only)
        register_rest_route(self::NAMESPACE, '/diagnostics', [
            'methods' => 'GET',
            'callback' => [self::class, 'run_diagnostics'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        // Recalculate scores (admin only)
        register_rest_route(self::NAMESPACE, '/recalculate-scores', [
            'methods' => 'POST',
            'callback' => [self::class, 'recalculate_scores'],
            'permission_callback' => [self::class, 'can_manage'],
        ]);

        // Audit logs (admin only)
        register_rest_route(self::NAMESPACE, '/audit', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_audit_logs'],
            'permission_callback' => [self::class, 'can_manage'],
            'args' => [
                'user_id' => ['type' => 'integer'],
                'tool_name' => ['type' => 'string'],
                'result' => ['type' => 'string', 'enum' => ['success', 'error', 'denied', 'rate_limited']],
                'since' => ['type' => 'string', 'format' => 'date-time'],
                'until' => ['type' => 'string', 'format' => 'date-time'],
                'limit' => ['type' => 'integer', 'default' => 50, 'maximum' => 200],
                'offset' => ['type' => 'integer', 'default' => 0],
            ],
        ]);

        // Audit stats (admin only)
        register_rest_route(self::NAMESPACE, '/audit/stats', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_audit_stats'],
            'permission_callback' => [self::class, 'can_manage'],
            'args' => [
                'period' => ['type' => 'string', 'default' => '24h', 'enum' => ['1h', '24h', '7d', '30d']],
            ],
        ]);

        // Rate limits for current user
        register_rest_route(self::NAMESPACE, '/rate-limits', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_rate_limits'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // Reset rate limits (admin only)
        register_rest_route(self::NAMESPACE, '/rate-limits/reset', [
            'methods' => 'POST',
            'callback' => [self::class, 'reset_rate_limits'],
            'permission_callback' => [self::class, 'can_manage'],
            'args' => [
                'user_id' => ['type' => 'integer', 'required' => false],
                'all' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        // Token info for current user
        register_rest_route(self::NAMESPACE, '/token', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_token_info'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // Regenerate token
        register_rest_route(self::NAMESPACE, '/token/regenerate', [
            'methods' => 'POST',
            'callback' => [self::class, 'regenerate_token'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // Revoke token
        register_rest_route(self::NAMESPACE, '/token/revoke', [
            'methods' => 'POST',
            'callback' => [self::class, 'revoke_token'],
            'permission_callback' => 'is_user_logged_in',
        ]);

        // Update token scopes
        register_rest_route(self::NAMESPACE, '/token/scopes', [
            'methods' => 'PUT',
            'callback' => [self::class, 'update_token_scopes'],
            'permission_callback' => 'is_user_logged_in',
            'args' => [
                'scopes' => ['type' => 'array', 'required' => true],
            ],
        ]);

        // ==========================================
        // MISSION TOKENS (B2B2B)
        // ==========================================

        // List mission tokens for current user
        register_rest_route(self::NAMESPACE, '/mission-tokens', [
            'methods' => 'GET',
            'callback' => [self::class, 'list_mission_tokens'],
            'permission_callback' => 'is_user_logged_in',
            'args' => [
                'include_revoked' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        // Create mission token
        register_rest_route(self::NAMESPACE, '/mission-tokens', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_mission_token'],
            'permission_callback' => 'is_user_logged_in',
            'args' => [
                'label' => ['type' => 'string', 'required' => true],
                'scopes' => ['type' => 'array', 'required' => true],
                'space_ids' => ['type' => 'array', 'required' => true],
                'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                'notes' => ['type' => 'string'],
            ],
        ]);

        // Get mission token by ID
        register_rest_route(self::NAMESPACE, '/mission-tokens/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_mission_token'],
            'permission_callback' => 'is_user_logged_in',
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        // Revoke mission token
        register_rest_route(self::NAMESPACE, '/mission-tokens/(?P<id>\d+)/revoke', [
            'methods' => 'POST',
            'callback' => [self::class, 'revoke_mission_token'],
            'permission_callback' => 'is_user_logged_in',
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        // Export mission token audit (CSV)
        register_rest_route(self::NAMESPACE, '/mission-tokens/(?P<id>\d+)/audit', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_mission_token_audit'],
            'permission_callback' => 'is_user_logged_in',
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
                'format' => ['type' => 'string', 'enum' => ['json', 'csv'], 'default' => 'json'],
                'since' => ['type' => 'string', 'format' => 'date-time'],
                'until' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ]);
    }

    /**
     * Permission check: can manage options
     */
    public static function can_manage(): bool {
        return current_user_can('manage_options');
    }

    // ==========================================
    // HEALTH ENDPOINTS
    // ==========================================

    /**
     * GET /health - Simple health check
     *
     * SECURITY: Returns minimal info if not authenticated.
     * Full details only for authenticated admin users.
     */
    public static function get_health(): WP_REST_Response {
        // If not authenticated as admin, return minimal response only
        if (!current_user_can('manage_options')) {
            $simple = [
                'status' => 'ok',
                'timestamp' => current_time('c'),
            ];
            return new WP_REST_Response($simple, 200);
        }

        // Admin gets full status
        $status = Health_Check::get_simple_status();
        $http_status = $status['ok'] ? 200 : 503;

        return new WP_REST_Response($status, $http_status);
    }

    /**
     * GET /health/full - Full health status (admin)
     */
    public static function get_health_full(): WP_REST_Response {
        $status = Health_Check::get_status();
        $http_status = $status['ok'] ? 200 : 503;

        return new WP_REST_Response($status, $http_status);
    }

    /**
     * GET /diagnostics - Run diagnostics (admin)
     */
    public static function run_diagnostics(): WP_REST_Response {
        $results = Health_Check::run_diagnostics();

        return new WP_REST_Response([
            'ok' => true,
            'diagnostics' => $results,
            'timestamp' => current_time('c'),
        ]);
    }

    // ==========================================
    // SCORING ENDPOINTS
    // ==========================================

    /**
     * POST /recalculate-scores - Trigger score recalculation
     */
    public static function recalculate_scores(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists(\MCP_No_Headless\Services\Scoring_Service::class)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'Scoring service not available',
            ], 503);
        }

        $start = microtime(true);
        $updated = \MCP_No_Headless\Services\Scoring_Service::recalculate_all_scores();
        $elapsed = round((microtime(true) - $start) * 1000);

        // Log the action
        Audit_Logger::log([
            'tool_name' => 'admin_recalculate_scores',
            'user_id' => get_current_user_id(),
            'result' => 'success',
            'latency_ms' => $elapsed,
            'extra' => ['updated_count' => $updated],
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'updated' => $updated,
            'elapsed_ms' => $elapsed,
            'message' => "Recalculated scores for {$updated} publications",
        ]);
    }

    // ==========================================
    // AUDIT ENDPOINTS
    // ==========================================

    /**
     * GET /audit - Get audit logs
     */
    public static function get_audit_logs(WP_REST_Request $request): WP_REST_Response {
        $filters = [];

        if ($request->get_param('user_id')) {
            $filters['user_id'] = $request->get_param('user_id');
        }
        if ($request->get_param('tool_name')) {
            $filters['tool_name'] = $request->get_param('tool_name');
        }
        if ($request->get_param('result')) {
            $filters['result'] = $request->get_param('result');
        }
        if ($request->get_param('since')) {
            $filters['since'] = $request->get_param('since');
        }
        if ($request->get_param('until')) {
            $filters['until'] = $request->get_param('until');
        }

        $limit = min((int) $request->get_param('limit') ?: 50, 200);
        $offset = (int) $request->get_param('offset') ?: 0;

        $logs = Audit_Logger::get_logs($filters, $limit, $offset);

        return new WP_REST_Response([
            'ok' => true,
            'count' => count($logs),
            'limit' => $limit,
            'offset' => $offset,
            'logs' => $logs,
        ]);
    }

    /**
     * GET /audit/stats - Get audit statistics
     */
    public static function get_audit_stats(WP_REST_Request $request): WP_REST_Response {
        $period = $request->get_param('period') ?: '24h';
        $stats = Audit_Logger::get_stats($period);

        return new WP_REST_Response([
            'ok' => true,
            'stats' => $stats,
        ]);
    }

    // ==========================================
    // RATE LIMIT ENDPOINTS
    // ==========================================

    /**
     * GET /rate-limits - Get current user's rate limit status
     */
    public static function get_rate_limits(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $stats = Rate_Limiter::get_user_stats($user_id);

        return new WP_REST_Response([
            'ok' => true,
            'user_id' => $user_id,
            'limits' => $stats,
        ]);
    }

    /**
     * POST /rate-limits/reset - Reset rate limits (admin)
     */
    public static function reset_rate_limits(WP_REST_Request $request): WP_REST_Response {
        if ($request->get_param('all')) {
            Rate_Limiter::reset_all();

            Audit_Logger::log([
                'tool_name' => 'admin_reset_all_rate_limits',
                'user_id' => get_current_user_id(),
                'result' => 'success',
            ]);

            return new WP_REST_Response([
                'ok' => true,
                'message' => 'All rate limits have been reset',
            ]);
        }

        $target_user_id = $request->get_param('user_id');
        if (!$target_user_id) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'Specify user_id or set all=true',
            ], 400);
        }

        Rate_Limiter::reset_user($target_user_id);

        Audit_Logger::log([
            'tool_name' => 'admin_reset_user_rate_limits',
            'user_id' => get_current_user_id(),
            'result' => 'success',
            'extra' => ['target_user_id' => $target_user_id],
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'message' => "Rate limits reset for user {$target_user_id}",
        ]);
    }

    // ==========================================
    // TOKEN ENDPOINTS
    // ==========================================

    /**
     * GET /token - Get token info for current user
     */
    public static function get_token_info(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $token_manager = new \MCP_No_Headless\User\Token_Manager();
        $info = $token_manager->get_token_info($user_id);

        return new WP_REST_Response([
            'ok' => true,
            'token' => $info,
        ]);
    }

    /**
     * POST /token/regenerate - Regenerate token
     */
    public static function regenerate_token(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $token_manager = new \MCP_No_Headless\User\Token_Manager();
        $new_token = $token_manager->regenerate_token($user_id);

        Audit_Logger::log([
            'tool_name' => 'token_regenerated',
            'user_id' => $user_id,
            'result' => 'success',
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'token' => $new_token,
            'message' => 'Token regenerated successfully',
        ]);
    }

    /**
     * POST /token/revoke - Revoke token
     */
    public static function revoke_token(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $token_manager = new \MCP_No_Headless\User\Token_Manager();
        $token_manager->revoke_token($user_id);

        return new WP_REST_Response([
            'ok' => true,
            'message' => 'Token revoked successfully',
        ]);
    }

    /**
     * PUT /token/scopes - Update token scopes
     */
    public static function update_token_scopes(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $scopes = $request->get_param('scopes');

        if (!is_array($scopes)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'scopes must be an array',
            ], 400);
        }

        $token_manager = new \MCP_No_Headless\User\Token_Manager();
        $token_manager->set_token_scopes($user_id, $scopes);
        $updated_scopes = $token_manager->get_token_scopes($user_id);

        return new WP_REST_Response([
            'ok' => true,
            'scopes' => $updated_scopes,
            'message' => 'Scopes updated successfully',
        ]);
    }

    // ==========================================
    // MISSION TOKEN ENDPOINTS (B2B2B)
    // ==========================================

    /**
     * GET /mission-tokens - List mission tokens for current user
     */
    public static function list_mission_tokens(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $include_revoked = (bool) $request->get_param('include_revoked');

        $manager = new Mission_Token_Manager();
        $tokens = $manager->list_user_tokens($user_id, $include_revoked);

        return new WP_REST_Response([
            'ok' => true,
            'count' => count($tokens),
            'tokens' => $tokens,
        ]);
    }

    /**
     * POST /mission-tokens - Create a new mission token
     */
    public static function create_mission_token(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $label = sanitize_text_field($request->get_param('label'));
        $scopes = (array) $request->get_param('scopes');
        $space_ids = array_map('intval', (array) $request->get_param('space_ids'));

        if (empty($label)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'label is required',
            ], 400);
        }

        if (empty($space_ids)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'space_ids is required (at least one space)',
            ], 400);
        }

        // Verify user has access to these spaces
        $permission_checker = new \MCP_No_Headless\MCP\Permission_Checker($user_id);
        $user_spaces = $permission_checker->get_user_spaces();
        $invalid_spaces = array_diff($space_ids, $user_spaces);

        if (!empty($invalid_spaces)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'You do not have access to some requested spaces',
                'invalid_space_ids' => array_values($invalid_spaces),
            ], 403);
        }

        $options = [];
        if ($request->get_param('expires_at')) {
            $options['expires_at'] = $request->get_param('expires_at');
        }
        if ($request->get_param('notes')) {
            $options['notes'] = $request->get_param('notes');
        }

        try {
            $manager = new Mission_Token_Manager();
            $result = $manager->create_token($user_id, $label, $scopes, $space_ids, $options);

            return new WP_REST_Response([
                'ok' => true,
                'message' => 'Mission token created successfully',
                'token' => $result,
            ]);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /mission-tokens/{id} - Get mission token by ID
     */
    public static function get_mission_token(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $token_id = (int) $request->get_param('id');

        $manager = new Mission_Token_Manager();
        $token = $manager->get_token_by_id($token_id);

        if (!$token) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'Token not found',
            ], 404);
        }

        // Only owner or admin can view
        if ((int) $token['owner_user_id'] !== $user_id && !current_user_can('manage_options')) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'Token not found',
            ], 404);
        }

        return new WP_REST_Response([
            'ok' => true,
            'token' => $token,
        ]);
    }

    /**
     * POST /mission-tokens/{id}/revoke - Revoke a mission token
     */
    public static function revoke_mission_token(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $token_id = (int) $request->get_param('id');

        $manager = new Mission_Token_Manager();
        $result = $manager->revoke_token($token_id, $user_id);

        if (!$result) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'Unable to revoke token (not found or not authorized)',
            ], 404);
        }

        return new WP_REST_Response([
            'ok' => true,
            'message' => 'Mission token revoked successfully',
        ]);
    }

    /**
     * GET /mission-tokens/{id}/audit - Get audit logs for a mission token
     */
    public static function get_mission_token_audit(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $token_id = (int) $request->get_param('id');
        $format = $request->get_param('format') ?: 'json';

        $manager = new Mission_Token_Manager();
        $token = $manager->get_token_by_id($token_id);

        if (!$token) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'Token not found',
            ], 404);
        }

        // Only owner or admin can view audit
        if ((int) $token['owner_user_id'] !== $user_id && !current_user_can('manage_options')) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'Token not found',
            ], 404);
        }

        $since = $request->get_param('since');
        $until = $request->get_param('until');

        if ($format === 'csv') {
            $csv = $manager->export_token_audit_csv($token_id, $since, $until);

            $response = new WP_REST_Response($csv);
            $response->header('Content-Type', 'text/csv');
            $response->header('Content-Disposition', 'attachment; filename="mission_token_' . $token_id . '_audit.csv"');
            return $response;
        }

        // JSON format
        $logs = $manager->get_token_audit($token_id);

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
            $logs = array_values($logs);
        }

        return new WP_REST_Response([
            'ok' => true,
            'token_id' => $token_id,
            'token_label' => $token['label'],
            'count' => count($logs),
            'logs' => $logs,
        ]);
    }
}
