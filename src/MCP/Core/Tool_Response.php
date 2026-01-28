<?php
/**
 * Tool Response - Standardized response factory for MCP V3
 *
 * Ensures all tool responses follow the spec-compliant format with:
 * - request_id for tracing
 * - Consistent error format with code, message, details, suggestion, fallback_used
 * - Same envelope structure for success and error responses
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core;

class Tool_Response {

    const VERSION = '3.0.0';

    // Standard error codes (aligned with HTTP semantics)
    const ERROR_VALIDATION = 'validation_error';      // 400 - Bad request
    const ERROR_AUTH = 'authentication_error';        // 401 - Not authenticated
    const ERROR_PERMISSION = 'permission_denied';     // 403 - Not authorized
    const ERROR_NOT_FOUND = 'not_found';              // 404 - Resource not found
    const ERROR_CONFLICT = 'conflict';                // 409 - State conflict
    const ERROR_RATE_LIMIT = 'rate_limit_exceeded';   // 429 - Too many requests
    const ERROR_INTERNAL = 'internal_error';          // 500 - Server error
    const ERROR_UNAVAILABLE = 'service_unavailable';  // 503 - Service down
    const ERROR_TIMEOUT = 'timeout';                  // 504 - Operation timeout
    const ERROR_QUOTA = 'quota_exceeded';             // 429 - Quota limit
    const ERROR_UNKNOWN_TOOL = 'unknown_tool';        // 404 - Tool not found

    /**
     * Current request ID (set by Router, propagated everywhere)
     */
    private static ?string $current_request_id = null;

    /**
     * Generate a new request ID
     */
    public static function generate_request_id(): string {
        return sprintf(
            'mcp_%s_%s',
            gmdate('Ymd_His'),
            substr(bin2hex(random_bytes(4)), 0, 8)
        );
    }

    /**
     * Set the current request ID (called by Router at start)
     */
    public static function set_request_id(string $request_id): void {
        self::$current_request_id = $request_id;
    }

    /**
     * Get the current request ID
     */
    public static function get_request_id(): string {
        if (self::$current_request_id === null) {
            self::$current_request_id = self::generate_request_id();
        }
        return self::$current_request_id;
    }

    /**
     * Create a successful response
     *
     * @param array $data Response data
     * @param array $meta Optional metadata
     * @return array Standardized success response
     */
    public static function ok(array $data, array $meta = []): array {
        $response = [
            'success' => true,
            'request_id' => self::get_request_id(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => $data,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return $response;
    }

    /**
     * Create an error response
     *
     * @param string $code Error code (use class constants)
     * @param string $message Human-readable message
     * @param array $options Additional options:
     *   - details: array of specific error details
     *   - suggestion: string with actionable suggestion
     *   - fallback_used: bool if fallback was attempted
     *   - fallback_result: mixed result from fallback
     *   - retry_after: int seconds until retry (for rate limits)
     *   - http_status: int HTTP status code override
     * @return array Standardized error response
     */
    public static function error(string $code, string $message, array $options = []): array {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        // Add optional error fields
        if (isset($options['details'])) {
            $error['details'] = $options['details'];
        }

        if (isset($options['suggestion'])) {
            $error['suggestion'] = $options['suggestion'];
        }

        if (isset($options['fallback_used'])) {
            $error['fallback_used'] = (bool) $options['fallback_used'];
            if (isset($options['fallback_result'])) {
                $error['fallback_result'] = $options['fallback_result'];
            }
        }

        if (isset($options['retry_after'])) {
            $error['retry_after'] = (int) $options['retry_after'];
        }

        $response = [
            'success' => false,
            'request_id' => self::get_request_id(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'error' => $error,
        ];

        // Store HTTP status for REST response
        if (isset($options['http_status'])) {
            $response['_http_status'] = (int) $options['http_status'];
        } else {
            $response['_http_status'] = self::code_to_http_status($code);
        }

        return $response;
    }

    /**
     * Create a validation error response
     */
    public static function validation_error(string $message, array $fields = [], ?string $suggestion = null): array {
        return self::error(
            self::ERROR_VALIDATION,
            $message,
            [
                'details' => ['fields' => $fields],
                'suggestion' => $suggestion ?? 'Vérifiez les paramètres requis et leur format.',
                'http_status' => 400,
            ]
        );
    }

    /**
     * Create an authentication error response
     */
    public static function auth_error(?string $message = null): array {
        return self::error(
            self::ERROR_AUTH,
            $message ?? 'Authentification requise.',
            [
                'suggestion' => 'Fournissez un token valide via le header Authorization.',
                'http_status' => 401,
            ]
        );
    }

    /**
     * Create a permission denied error response
     */
    public static function permission_error(string $action, ?string $resource = null): array {
        $message = "Permission refusée pour l'action: {$action}";
        if ($resource) {
            $message .= " sur {$resource}";
        }

        return self::error(
            self::ERROR_PERMISSION,
            $message,
            [
                'details' => [
                    'action' => $action,
                    'resource' => $resource,
                ],
                'suggestion' => 'Vérifiez vos droits ou contactez un administrateur.',
                'http_status' => 403,
            ]
        );
    }

    /**
     * Create a not found error response
     */
    public static function not_found(string $resource_type, $identifier): array {
        return self::error(
            self::ERROR_NOT_FOUND,
            "{$resource_type} non trouvé: {$identifier}",
            [
                'details' => [
                    'type' => $resource_type,
                    'identifier' => $identifier,
                ],
                'suggestion' => "Vérifiez que l'identifiant est correct.",
                'http_status' => 404,
            ]
        );
    }

    /**
     * Create a rate limit error response
     */
    public static function rate_limit(int $retry_after = 60, ?int $limit = null): array {
        $message = 'Trop de requêtes.';
        if ($limit) {
            $message = "Limite de {$limit} requêtes atteinte.";
        }

        return self::error(
            self::ERROR_RATE_LIMIT,
            $message,
            [
                'retry_after' => $retry_after,
                'suggestion' => "Réessayez dans {$retry_after} secondes.",
                'http_status' => 429,
            ]
        );
    }

    /**
     * Create a quota exceeded error response
     */
    public static function quota_exceeded(string $quota_type, int $used, int $limit): array {
        return self::error(
            self::ERROR_QUOTA,
            "Quota {$quota_type} dépassé: {$used}/{$limit}",
            [
                'details' => [
                    'quota_type' => $quota_type,
                    'used' => $used,
                    'limit' => $limit,
                ],
                'suggestion' => 'Attendez le renouvellement du quota ou passez à un plan supérieur.',
                'http_status' => 429,
            ]
        );
    }

    /**
     * Create an internal error response
     */
    public static function internal_error(?string $message = null, ?\Throwable $exception = null): array {
        $options = [
            'suggestion' => 'Si le problème persiste, contactez le support.',
            'http_status' => 500,
        ];

        if ($exception && defined('WP_DEBUG') && WP_DEBUG) {
            $options['details'] = [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        return self::error(
            self::ERROR_INTERNAL,
            $message ?? 'Erreur interne du serveur.',
            $options
        );
    }

    /**
     * Create an unknown tool error response
     */
    public static function unknown_tool(string $tool_name, array $available_tools = []): array {
        $options = [
            'details' => ['requested_tool' => $tool_name],
            'http_status' => 404,
        ];

        if (!empty($available_tools)) {
            // Find similar tools for suggestion
            $similar = self::find_similar_tools($tool_name, $available_tools);
            if (!empty($similar)) {
                $options['suggestion'] = 'Outils similaires disponibles: ' . implode(', ', $similar);
            } else {
                $options['suggestion'] = 'Utilisez ml_ping pour voir la liste des outils disponibles.';
            }
        }

        return self::error(
            self::ERROR_UNKNOWN_TOOL,
            "Outil inconnu: {$tool_name}",
            $options
        );
    }

    /**
     * Create a timeout error response
     */
    public static function timeout(string $operation, int $timeout_seconds): array {
        return self::error(
            self::ERROR_TIMEOUT,
            "Timeout: {$operation} a dépassé {$timeout_seconds}s",
            [
                'details' => [
                    'operation' => $operation,
                    'timeout' => $timeout_seconds,
                ],
                'suggestion' => 'Utilisez le mode async pour les opérations longues.',
                'http_status' => 504,
            ]
        );
    }

    /**
     * Create an async job response (special case of success)
     */
    public static function async_job(string $job_id, string $status = 'pending', ?string $poll_hint = null): array {
        return self::ok([
            'async' => true,
            'job_id' => $job_id,
            'status' => $status,
            'poll_endpoint' => 'ml_me',
            'poll_params' => ['action' => 'job', 'job_id' => $job_id],
        ], [
            'hint' => $poll_hint ?? 'Utilisez ml_me(action="job", job_id="' . $job_id . '") pour suivre la progression.',
        ]);
    }

    /**
     * Create a partial success response (some items succeeded, some failed)
     */
    public static function partial(array $succeeded, array $failed, string $message = ''): array {
        return [
            'success' => true,
            'partial' => true,
            'request_id' => self::get_request_id(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => [
                'succeeded' => $succeeded,
                'failed' => $failed,
                'succeeded_count' => count($succeeded),
                'failed_count' => count($failed),
            ],
            'meta' => [
                'message' => $message ?: 'Opération partiellement réussie.',
            ],
        ];
    }

    /**
     * Wrap legacy response in standard format
     *
     * Used by Migration_Service to standardize V2 responses
     */
    public static function wrap_legacy(array $legacy_response): array {
        // Already in V3 format?
        if (isset($legacy_response['request_id'])) {
            return $legacy_response;
        }

        // Detect if error
        if (isset($legacy_response['error']) ||
            (isset($legacy_response['success']) && $legacy_response['success'] === false)) {

            $error_message = $legacy_response['error']
                ?? $legacy_response['message']
                ?? 'Erreur inconnue';

            return self::error(
                self::ERROR_INTERNAL,
                $error_message,
                ['fallback_used' => true, 'fallback_result' => $legacy_response]
            );
        }

        // Success - wrap data
        $data = $legacy_response['data'] ?? $legacy_response;

        return self::ok($data, ['legacy_wrapped' => true]);
    }

    /**
     * Convert error code to HTTP status
     */
    private static function code_to_http_status(string $code): int {
        return match ($code) {
            self::ERROR_VALIDATION => 400,
            self::ERROR_AUTH => 401,
            self::ERROR_PERMISSION => 403,
            self::ERROR_NOT_FOUND => 404,
            self::ERROR_UNKNOWN_TOOL => 404,
            self::ERROR_CONFLICT => 409,
            self::ERROR_RATE_LIMIT => 429,
            self::ERROR_QUOTA => 429,
            self::ERROR_INTERNAL => 500,
            self::ERROR_UNAVAILABLE => 503,
            self::ERROR_TIMEOUT => 504,
            default => 500,
        };
    }

    /**
     * Find similar tool names (for suggestions)
     */
    private static function find_similar_tools(string $input, array $tools, int $max = 3): array {
        $similar = [];
        $input_lower = strtolower($input);

        foreach ($tools as $tool) {
            $tool_lower = strtolower($tool);

            // Exact substring match
            if (str_contains($tool_lower, $input_lower) || str_contains($input_lower, $tool_lower)) {
                $similar[$tool] = 0;
                continue;
            }

            // Levenshtein distance
            $distance = levenshtein($input_lower, $tool_lower);
            if ($distance <= 3) {
                $similar[$tool] = $distance;
            }
        }

        asort($similar);
        return array_slice(array_keys($similar), 0, $max);
    }

    /**
     * Log error for audit (called automatically on error responses)
     */
    public static function log_error(array $error_response, ?int $user_id = null): void {
        if (!isset($error_response['error'])) {
            return;
        }

        $log_data = [
            'request_id' => $error_response['request_id'] ?? 'unknown',
            'error_code' => $error_response['error']['code'] ?? 'unknown',
            'message' => $error_response['error']['message'] ?? '',
            'user_id' => $user_id ?? get_current_user_id(),
            'timestamp' => $error_response['timestamp'] ?? gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if (function_exists('do_action')) {
            do_action('mcp_error_logged', $log_data);
        }

        // Also log to WordPress debug if enabled
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[MCP Error] ' . wp_json_encode($log_data));
        }
    }

    /**
     * Reset request ID (for testing or new request context)
     */
    public static function reset(): void {
        self::$current_request_id = null;
    }
}
