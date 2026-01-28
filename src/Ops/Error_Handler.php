<?php
/**
 * Error Handler - Normalized error responses for MCP
 *
 * Ensures:
 * - No stack traces exposed to agents
 * - Consistent error format
 * - Debug IDs for support correlation
 * - Secure error messages (no info leak)
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Ops;

class Error_Handler {

    /**
     * Error codes and their user-safe messages
     */
    private const ERROR_MESSAGES = [
        'not_found' => 'The requested resource was not found.',
        'unauthorized' => 'Authentication required.',
        'forbidden' => 'You do not have permission to perform this action.',
        'invalid_input' => 'Invalid input provided.',
        'missing_parameter' => 'A required parameter is missing.',
        'rate_limited' => 'Rate limit exceeded. Please try again later.',
        'service_unavailable' => 'Service temporarily unavailable.',
        'internal_error' => 'An unexpected error occurred.',
        'validation_error' => 'Validation failed.',
        'conflict' => 'The request conflicts with current state.',
        'timeout' => 'The operation timed out.',
        'buddyboss_unavailable' => 'Social features are not available.',
        'picasso_unavailable' => 'Content management features are not available.',
    ];

    /**
     * JSON-RPC error codes
     */
    private const JSONRPC_CODES = [
        'not_found' => -32001,
        'unauthorized' => -32002,
        'forbidden' => -32003,
        'invalid_input' => -32602,
        'missing_parameter' => -32602,
        'rate_limited' => -32000,
        'service_unavailable' => -32603,
        'internal_error' => -32603,
        'validation_error' => -32602,
        'conflict' => -32004,
        'timeout' => -32005,
        'buddyboss_unavailable' => -32006,
        'picasso_unavailable' => -32007,
    ];

    /**
     * Create a normalized error response
     *
     * @param string $error_code Error code (from ERROR_MESSAGES keys)
     * @param string|null $custom_message Optional custom message (will be sanitized)
     * @param array $context Additional context (will NOT be exposed, only logged)
     * @param int|null $request_id JSON-RPC request ID
     * @return array Normalized error array
     */
    public static function error(
        string $error_code,
        ?string $custom_message = null,
        array $context = [],
        ?int $request_id = null
    ): array {
        $debug_id = self::generate_debug_id();

        // Log the error with full context
        self::log_error($debug_id, $error_code, $custom_message, $context);

        // Get safe message
        $message = $custom_message ?? self::ERROR_MESSAGES[$error_code] ?? self::ERROR_MESSAGES['internal_error'];

        // Ensure message doesn't leak sensitive info
        $safe_message = self::sanitize_message($message);

        // Build response
        $response = [
            'ok' => false,
            'error_code' => $error_code,
            'message' => $safe_message,
            'debug_id' => $debug_id,
        ];

        // Add retry_after for rate limiting
        if ($error_code === 'rate_limited' && isset($context['retry_after'])) {
            $response['retry_after'] = (int) $context['retry_after'];
        }

        return $response;
    }

    /**
     * Create a JSON-RPC formatted error response
     */
    public static function jsonrpc_error(
        int $id,
        string $error_code,
        ?string $custom_message = null,
        array $context = []
    ): array {
        $error = self::error($error_code, $custom_message, $context, $id);
        $jsonrpc_code = self::JSONRPC_CODES[$error_code] ?? -32603;

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $jsonrpc_code,
                'message' => $error['message'],
                'data' => [
                    'error_code' => $error_code,
                    'debug_id' => $error['debug_id'],
                ],
            ],
        ];
    }

    /**
     * Create a success response with consistent format
     */
    public static function success($data, ?string $debug_id = null): array {
        return [
            'ok' => true,
            'data' => $data,
            'debug_id' => $debug_id,
        ];
    }

    /**
     * Wrap an exception into a safe error response
     */
    public static function from_exception(\Throwable $e, ?int $request_id = null): array {
        $error_code = 'internal_error';

        // Map known exceptions to error codes
        if ($e instanceof \InvalidArgumentException) {
            $error_code = 'invalid_input';
        } elseif ($e instanceof \RuntimeException) {
            if (strpos($e->getMessage(), 'not found') !== false) {
                $error_code = 'not_found';
            } elseif (strpos($e->getMessage(), 'permission') !== false) {
                $error_code = 'forbidden';
            }
        }

        // Context includes full exception info (not exposed)
        $context = [
            'exception_class' => get_class($e),
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString(),
        ];

        if ($request_id !== null) {
            return self::jsonrpc_error($request_id, $error_code, null, $context);
        }

        return self::error($error_code, null, $context);
    }

    /**
     * Setup global error handlers
     */
    public static function setup_handlers(): void {
        // Catch PHP errors and convert to exceptions
        set_error_handler(function ($severity, $message, $file, $line) {
            // Don't catch @-suppressed errors
            if (!(error_reporting() & $severity)) {
                return false;
            }

            // Log but don't throw for notices/warnings during MCP calls
            if (defined('DOING_MCP_REQUEST') && DOING_MCP_REQUEST) {
                error_log("MCP PHP Error [{$severity}]: {$message} in {$file}:{$line}");
                return true; // Suppress the error
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        // Catch fatal errors
        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                error_log("MCP Fatal Error: {$error['message']} in {$error['file']}:{$error['line']}");
            }
        });
    }

    /**
     * Execute a callback with timeout
     */
    public static function with_timeout(callable $callback, int $timeout_seconds = 5, string $operation = 'operation') {
        $start = microtime(true);

        // Note: PHP doesn't have native async timeout, but we can check elapsed time
        // For true timeout, you'd need pcntl_alarm or async execution
        try {
            $result = $callback();
            $elapsed = (microtime(true) - $start) * 1000;

            // Log slow operations
            if ($elapsed > ($timeout_seconds * 1000)) {
                error_log("MCP Slow Operation [{$operation}]: {$elapsed}ms (limit: {$timeout_seconds}s)");
            }

            return $result;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Generate debug ID
     */
    private static function generate_debug_id(): string {
        return sprintf(
            'err_%s_%s',
            date('Ymd_His'),
            bin2hex(random_bytes(4))
        );
    }

    /**
     * Sanitize message to prevent info leak
     */
    private static function sanitize_message(string $message): string {
        // Remove file paths
        $message = preg_replace('#/[a-zA-Z0-9/_.-]+\.php#', '[file]', $message);

        // Remove line numbers
        $message = preg_replace('#on line \d+#i', '', $message);

        // Remove SQL queries
        $message = preg_replace('#(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE).*#i', '[query]', $message);

        // Remove stack traces
        $message = preg_replace('#Stack trace:.*#s', '', $message);

        // Truncate long messages
        if (strlen($message) > 200) {
            $message = substr($message, 0, 200) . '...';
        }

        return trim($message);
    }

    /**
     * Log error with full context
     */
    private static function log_error(string $debug_id, string $error_code, ?string $message, array $context): void {
        $log_entry = [
            'debug_id' => $debug_id,
            'error_code' => $error_code,
            'message' => $message,
            'context' => $context,
            'timestamp' => current_time('c'),
            'user_id' => get_current_user_id(),
        ];

        // Log to error log
        error_log('MCP Error [' . $debug_id . ']: ' . wp_json_encode($log_entry));

        // Also log to audit if available
        if (class_exists(Audit_Logger::class)) {
            Audit_Logger::log([
                'tool_name' => $context['tool_name'] ?? 'error_handler',
                'user_id' => get_current_user_id(),
                'result' => 'error',
                'error_code' => $error_code,
                'extra' => ['debug_id' => $debug_id],
            ]);
        }
    }

    /**
     * Get error message for a code
     */
    public static function get_message(string $error_code): string {
        return self::ERROR_MESSAGES[$error_code] ?? self::ERROR_MESSAGES['internal_error'];
    }

    /**
     * Check if an error code is valid
     */
    public static function is_valid_code(string $error_code): bool {
        return isset(self::ERROR_MESSAGES[$error_code]);
    }
}
