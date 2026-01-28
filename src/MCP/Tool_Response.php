<?php
/**
 * Tool Response - Standardized response helpers for MCP tools
 *
 * Ensures consistent format across all tools:
 * - ok: bool
 * - request_id: string (for correlation)
 * - warnings: array (optional)
 * - Neutral "not found" (same response for inaccessible vs non-existent)
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

class Tool_Response {

    /**
     * Generate a unique request ID for correlation
     */
    public static function generate_request_id(): string {
        return sprintf(
            'req_%s_%s',
            date('Ymd_His'),
            bin2hex(random_bytes(4))
        );
    }

    /**
     * Create a successful response
     *
     * @param array $data Response data
     * @param string|null $request_id Optional request ID (auto-generated if null)
     * @param array $warnings Optional warnings
     * @return array
     */
    public static function ok(array $data, ?string $request_id = null, ?array $warnings = [], array $meta = []): array {
        $response = [
            'ok' => true,
            'request_id' => $request_id ?? self::generate_request_id(),
        ];

        // Merge data into response
        foreach ($data as $key => $value) {
            $response[$key] = $value;
        }

        if (!empty($warnings)) {
            $response['warnings'] = $warnings;
        }

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return $response;
    }

    /**
     * Create an error response
     *
     * @param string $error_code Error code
     * @param string $message User-safe message
     * @param string|null $request_id Optional request ID
     * @param array $warnings Optional warnings
     * @return array
     */
    public static function error(string $error_code, string $message, ?string $request_id = null, array $warnings = []): array {
        $response = [
            'ok' => false,
            'request_id' => $request_id ?? self::generate_request_id(),
            'error_code' => $error_code,
            'message' => $message,
        ];

        if (!empty($warnings)) {
            $response['warnings'] = $warnings;
        }

        return $response;
    }

    /**
     * Neutral "not found" response - SAME for inaccessible AND non-existent
     * This prevents information leakage about resource existence
     *
     * @param string $resource_type Type of resource (publication, space, group, etc.)
     * @param string|null $request_id Optional request ID
     * @return array
     */
    public static function not_found(string $resource_type = 'resource', ?string $request_id = null): array {
        return [
            'ok' => true,
            'request_id' => $request_id ?? self::generate_request_id(),
            'found' => false,
            'message' => sprintf('The requested %s was not found or is not accessible.', $resource_type),
        ];
    }

    /**
     * Create a list response with indexed items
     *
     * @param array $items Items to return
     * @param array $pagination Pagination info (has_more, next_cursor, total_count)
     * @param string|null $request_id Optional request ID
     * @param array $extra Extra fields to include
     * @return array
     */
    public static function list(array $items, array $pagination = [], ?string $request_id = null, array $extra = []): array {
        $response = [
            'ok' => true,
            'request_id' => $request_id ?? self::generate_request_id(),
            'count' => count($items),
            'items' => self::index_results($items),
        ];

        // Add pagination info
        if (!empty($pagination)) {
            if (isset($pagination['has_more'])) {
                $response['has_more'] = $pagination['has_more'];
            }
            if (isset($pagination['next_cursor'])) {
                $response['next_cursor'] = $pagination['next_cursor'];
            }
            if (isset($pagination['total_count'])) {
                $response['total_count'] = $pagination['total_count'];
            }
        }

        // Merge extra fields
        foreach ($extra as $key => $value) {
            $response[$key] = $value;
        }

        return $response;
    }

    /**
     * Create an empty list response (neutral - could be no results OR no access)
     *
     * @param string|null $request_id Optional request ID
     * @param string|null $message Optional message
     * @param array $extra Extra fields to merge (suggestions, etc.)
     * @return array
     */
    public static function empty_list(?string $request_id = null, ?string $message = null, array $extra = []): array {
        $response = [
            'ok' => true,
            'request_id' => $request_id ?? self::generate_request_id(),
            'count' => 0,
            'items' => [],
            'has_more' => false,
        ];

        if ($message) {
            $response['message'] = $message;
        }

        // Merge extra fields (v2.2.0+)
        foreach ($extra as $key => $value) {
            $response[$key] = $value;
        }

        return $response;
    }

    /**
     * Add 1-based index to each item in results
     *
     * @param array $items Items to index
     * @return array Items with 'index' field added
     */
    public static function index_results(array $items): array {
        $indexed = [];
        $i = 1;

        foreach ($items as $item) {
            if (is_array($item)) {
                $item['index'] = $i;
            } elseif (is_object($item)) {
                $item->index = $i;
            }
            $indexed[] = $item;
            $i++;
        }

        return $indexed;
    }

    /**
     * Create a single item response (found)
     *
     * @param array $item Item data
     * @param string|null $request_id Optional request ID
     * @param array $next_actions Optional suggested next actions
     * @return array
     */
    public static function found(array $item, ?string $request_id = null, array $next_actions = []): array {
        $response = [
            'ok' => true,
            'request_id' => $request_id ?? self::generate_request_id(),
            'found' => true,
        ];

        // Merge item data
        foreach ($item as $key => $value) {
            $response[$key] = $value;
        }

        if (!empty($next_actions)) {
            $response['next_actions'] = $next_actions;
        }

        return $response;
    }

    /**
     * Create a prepare stage response (for two-phase commit)
     *
     * @param string $session_id Session ID for commit
     * @param array $preview Preview data
     * @param int $ttl_seconds TTL in seconds
     * @param string|null $request_id Optional request ID
     * @return array
     */
    public static function prepared(string $session_id, array $preview, int $ttl_seconds = 300, ?string $request_id = null): array {
        return [
            'ok' => true,
            'request_id' => $request_id ?? self::generate_request_id(),
            'stage' => 'prepared',
            'session_id' => $session_id,
            'expires_in' => $ttl_seconds,
            'preview' => $preview,
            'next_action' => 'Call with stage=commit and session_id to finalize',
        ];
    }

    /**
     * Create a commit stage response (for two-phase commit)
     *
     * @param array $result Result data (created_id, url, etc.)
     * @param string|null $request_id Optional request ID
     * @return array
     */
    public static function committed(array $result, ?string $request_id = null): array {
        $response = [
            'ok' => true,
            'request_id' => $request_id ?? self::generate_request_id(),
            'stage' => 'committed',
        ];

        foreach ($result as $key => $value) {
            $response[$key] = $value;
        }

        return $response;
    }

    /**
     * Create a "not allowed" response (permission denied, but neutral)
     *
     * @param string $reason User-safe reason
     * @param string|null $request_id Optional request ID
     * @return array
     */
    public static function not_allowed(string $reason, ?string $request_id = null): array {
        return [
            'ok' => true,
            'request_id' => $request_id ?? self::generate_request_id(),
            'allowed' => false,
            'reason' => $reason,
        ];
    }

    /**
     * Create an "allowed" response (permission granted)
     *
     * @param array $data Additional data
     * @param string|null $request_id Optional request ID
     * @return array
     */
    public static function allowed(array $data = [], ?string $request_id = null): array {
        $response = [
            'ok' => true,
            'request_id' => $request_id ?? self::generate_request_id(),
            'allowed' => true,
        ];

        foreach ($data as $key => $value) {
            $response[$key] = $value;
        }

        return $response;
    }

    /**
     * Create a rate limited response
     *
     * @param int $retry_after Seconds to wait
     * @param string|null $request_id Optional request ID
     * @return array
     */
    public static function rate_limited(int $retry_after, ?string $request_id = null): array {
        return [
            'ok' => false,
            'request_id' => $request_id ?? self::generate_request_id(),
            'error_code' => 'rate_limited',
            'message' => 'Rate limit exceeded. Please try again later.',
            'retry_after' => $retry_after,
        ];
    }

    /**
     * Add canonical URL to an item
     *
     * @param array $item Item to add URL to
     * @param string $post_type Post type (publication, space, etc.)
     * @param int $id Post ID
     * @return array Item with url field
     */
    public static function with_url(array $item, string $post_type, int $id): array {
        $permalink = get_permalink($id);
        if ($permalink) {
            $item['url'] = $permalink;
        }
        return $item;
    }

    /**
     * Validate required parameters
     *
     * @param array $args Arguments to validate
     * @param array $required Required parameter names
     * @return array|null Null if valid, error response if invalid
     */
    public static function validate_required(array $args, array $required): ?array {
        $missing = [];

        foreach ($required as $param) {
            if (!isset($args[$param]) || $args[$param] === '') {
                $missing[] = $param;
            }
        }

        if (!empty($missing)) {
            return self::error(
                'missing_parameter',
                'Missing required parameter(s): ' . implode(', ', $missing)
            );
        }

        return null;
    }

    /**
     * Clamp limit to safe range
     *
     * @param int|null $limit Requested limit
     * @param int $default Default limit
     * @param int $max Maximum allowed limit
     * @return int Clamped limit
     */
    public static function clamp_limit(?int $limit, int $default = 20, int $max = 50): int {
        if ($limit === null || $limit <= 0) {
            return $default;
        }
        return min($limit, $max);
    }
}
