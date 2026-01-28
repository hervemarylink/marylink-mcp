<?php
/**
 * MCP Controller - JSON-RPC dispatcher for MCP protocol
 *
 * Implements standard MCP protocol methods:
 * - initialize: Return server capabilities
 * - tools/list: List available tools
 * - tools/call: Execute a tool
 * - prompts/list: List available prompts
 * - prompts/get: Get a prompt
 * - completion/complete: Autocomplete arguments
 *
 * Authentication via Bearer token (Token_Manager)
 *
 * @package MCP_No_Headless
 * @see https://modelcontextprotocol.info/specification/
 */

namespace MCP_No_Headless\MCP\Http;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use MCP_No_Headless\User\Token_Manager;
use MCP_No_Headless\User\Mission_Token_Manager;
use MCP_No_Headless\MCP\Tools_Registry;
use MCP_No_Headless\MCP\Tool_Catalog;
use MCP_No_Headless\MCP\Core\Tool_Catalog_V3;
use MCP_No_Headless\MCP\Prompts_Handler;
use MCP_No_Headless\MCP\Completion_Handler;
use MCP_No_Headless\MCP\Permission_Checker;
use MCP_No_Headless\Ops\Audit_Logger;
use MCP_No_Headless\Ops\Rate_Limiter;

class MCP_Controller {

    /**
     * REST namespace
     */
    const NAMESPACE = 'mcp/v1';

    /**
     * MCP protocol version
     */
    const PROTOCOL_VERSION = '2024-11-05';

    /**
     * Server info
     */
    const SERVER_NAME = 'MaryLink MCP Server';
    const SERVER_VERSION = '1.0.0';

    /**
     * Token manager instance
     */
    private Token_Manager $token_manager;

    /**
     * Current user ID (authenticated)
     */
    private int $user_id = 0;

    /**
     * Current token scopes
     */
    private array $scopes = [];

    /**
     * Current token hash (for rate limiting + audit)
     */
    private ?string $token_hash = null;

    /**
     * Token type: 'user' or 'mission'
     */
    private string $token_type = 'user';

    /**
     * Mission token info (if applicable)
     */
    private ?array $mission_token_info = null;

    /**
     * Register REST routes
     */
    public static function register_routes(): void {
        // Main MCP JSON-RPC endpoint
        register_rest_route(self::NAMESPACE, '/mcp', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle_request'],
            'permission_callback' => '__return_true',
        ]);

        // SSE endpoint DISABLED - AI Engine Pro handles SSE connections
        // MaryLink tools are registered via mwai_mcp_tools filter in Tools_Registry

        // Discovery endpoint
        register_rest_route(self::NAMESPACE, '/discover', [
            'methods' => 'GET',
            'callback' => [self::class, 'handle_discover'],
            'permission_callback' => '__return_true',
        ]);

        // Direct tools/list endpoint (GET) - for clients not using JSON-RPC
        register_rest_route(self::NAMESPACE, '/tools', [
            'methods' => 'GET',
            'callback' => [self::class, 'handle_tools_list_direct'],
            'permission_callback' => '__return_true',
        ]);

        // Claude Web compatible routes DISABLED - AI Engine Pro handles these
    }

    /**
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_request(WP_REST_Request $request): WP_REST_Response {
        $controller = new self();
        return $controller->process_request($request);
    }

    /**
     * Process the JSON-RPC request
     */
    private function process_request(WP_REST_Request $request): WP_REST_Response {
        $this->token_manager = new Token_Manager();

        // Get JSON body
        $body = $request->get_json_params();

        if (empty($body)) {
            return $this->json_rpc_error(null, -32700, 'Parse error: Invalid JSON');
        }

        // Extract JSON-RPC fields
        $jsonrpc = $body['jsonrpc'] ?? '';
        $method = $body['method'] ?? '';
        $params = $body['params'] ?? [];
        $id = $body['id'] ?? null;

        // Validate JSON-RPC version
        if ($jsonrpc !== '2.0') {
            return $this->json_rpc_error($id, -32600, 'Invalid Request: jsonrpc must be "2.0"');
        }

        // Initialize doesn't require auth
        if ($method === 'initialize') {
            return $this->handle_initialize($params, $id);
        }

        // All other methods require authentication
        $auth_result = $this->authenticate($request);
        if ($auth_result instanceof WP_REST_Response) {
            return $auth_result;
        }

        // Route to method handler
        return $this->route_method($method, $params, $id);
    }

    /**
     * Authenticate request via Bearer token (user token or mission token)
     *
     * @return true|WP_REST_Response True if authenticated, error response otherwise
     */
    private function authenticate(WP_REST_Request $request) {
        $headers = $request->get_headers();
        $auth_header = $headers['authorization'][0] ?? '';

        // Also check X-MCP-Token header
        $mcp_token = $headers['x_mcp_token'][0] ?? '';

        $token = '';
        if (preg_match('/Bearer\s+(\S+)/i', $auth_header, $matches)) {
            $token = $matches[1];
        } elseif (!empty($mcp_token)) {
            $token = $mcp_token;
        }

        if (empty($token)) {
            return $this->json_rpc_error(null, -32001, 'Authentication required: Bearer token missing');
        }

        // Try mission token first (prefix: mlmis_)
        if (strpos($token, Mission_Token_Manager::TOKEN_PREFIX) === 0) {
            $mission_manager = new Mission_Token_Manager();
            $mission_result = $mission_manager->validate_token($token);

            if (!$mission_result) {
                return $this->json_rpc_error(null, -32001, 'Invalid or expired mission token');
            }

            // Use owner's user ID for permissions
            $this->user_id = $mission_result['owner_user_id'];
            $this->scopes = $mission_result['scopes'];

            // Store token context for rate limiting + audit
            $this->token_hash = hash('sha256', $token);
            $this->token_type = 'mission';
            $this->mission_token_info = [
                'id' => $mission_result['id'],
                'label' => $mission_result['label'],
            ];

            // Set WordPress current user
            wp_set_current_user($this->user_id);

            return true;
        }

        // Try regular user token (prefix: mlmcp_)
        $result = $this->token_manager->validate_token_full($token);

        if (!$result['valid']) {
            $error_msg = match ($result['error']) {
                'token_revoked' => 'Token has been revoked',
                'invalid_token' => 'Invalid token',
                default => 'Authentication failed',
            };
            return $this->json_rpc_error(null, -32001, $error_msg);
        }

        $this->user_id = $result['user_id'];
        $this->scopes = $result['scopes'];

        // Store token context for rate limiting + audit
        $this->token_hash = Token_Manager::get_current_token_hash();
        $this->token_type = 'user';
        $this->mission_token_info = null;

        // Set WordPress current user
        wp_set_current_user($this->user_id);

        return true;
    }

    /**
     * Route method to handler
     */
    private function route_method(string $method, array $params, $id): WP_REST_Response {
        $start_time = microtime(true);

        try {
            $result = match ($method) {
                'tools/list' => $this->handle_tools_list($params),
                'tools/call' => $this->handle_tools_call($params),
                'prompts/list' => $this->handle_prompts_list($params),
                'prompts/get' => $this->handle_prompts_get($params),
                'completion/complete' => $this->handle_completion_complete($params),
                'notifications/initialized' => $this->handle_initialized($params),
                'ping' => $this->handle_ping($params),
                default => throw new \InvalidArgumentException("Method not found: {$method}"),
            };

            // Log successful call
            $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
            $this->log_request($method, 'success', $params, $latency_ms);

            return $this->json_rpc_success($id, $result);

        } catch (\InvalidArgumentException $e) {
            return $this->json_rpc_error($id, -32601, $e->getMessage());
        } catch (\Exception $e) {
            $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
            $this->log_request($method, 'error', $params, $latency_ms, $e->getMessage());

            return $this->json_rpc_error($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    // ==========================================
    // MCP METHOD HANDLERS
    // ==========================================

    /**
     * Handle initialize method
     */
    private function handle_initialize(array $params, $id): WP_REST_Response {
        $client_info = $params['clientInfo'] ?? [];

        // Build capabilities
        $capabilities = [
            'tools' => new \stdClass(), // Tools capability supported
        ];

        // Add prompts capability if available
        if (Prompts_Handler::is_available()) {
            $capabilities['prompts'] = Prompts_Handler::get_capability()['prompts'];
        }

        // Add completions capability
        if (Completion_Handler::is_available()) {
            $capabilities['completions'] = Completion_Handler::get_capability()['completions'];
        }

        $result = [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'capabilities' => $capabilities,
            'instructions' => $this->get_system_instructions(),
        ];

        return $this->json_rpc_success($id, $result);
    }
    /**
     * Get system instructions for MCP clients (v2.2.10+)
     */
    private function get_system_instructions(): string {
        return <<<'INSTRUCTIONS'
## MaryLink MCP Server - Instructions

Bienvenue sur MaryLink MCP. Ce serveur expose des outils pour gérer les publications MaryLink.

### Outils disponibles

**Lecture:**
- ml_spaces_list : Lister les espaces
- ml_publications_list : Lister les publications
- ml_publication_get : Détails d'une publication

**Création/Modification:**
- ml_publication_create : Créer une publication (space_id, title, content requis)
- ml_publication_update : Modifier une publication
- ml_publication_delete : Supprimer une publication

**Assistance:**
- ml_recommend : Trouver le meilleur prompt pour une tâche
- ml_assist_prepare : Préparer une exécution complète

### Règles pour la création de publications

1. **space_id obligatoire** : Utiliser ml_spaces_list pour voir les espaces disponibles

2. **Types** : prompt, data, style, template, tool

3. **Contenu** : Écrire du contenu directement exploitable, sans placeholders [À compléter]

4. **Bonnes pratiques** : Titre clair, contenu actionnable
INSTRUCTIONS;
    }

    /**
     * Handle notifications/initialized (acknowledgment)
     */
    private function handle_initialized(array $params): array {
        return []; // Empty response for notification acknowledgment
    }

    /**
     * Handle ping
     */
    private function handle_ping(array $params): array {
        return [
            'status' => 'ok',
            'timestamp' => current_time('c'),
        ];
    }

    /**
     * Handle tools/list
     */
    private function handle_tools_list(array $params): array {
        // Check scope
        if (!$this->has_scope('read:content')) {
            throw new \Exception('Scope read:content required');
        }

        // V3: Use Tool_Catalog_V3 as single source of truth
        $tools = Tool_Catalog_V3::build();

        return ['tools' => $tools];
    }

    /**
     * Handle tools/call
     */
    private function handle_tools_call(array $params): array {
        $tool_name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];
        $start_time = microtime(true);

        if (empty($tool_name)) {
            throw new \InvalidArgumentException('Tool name required');
        }

        // Resolve legacy aliases (v2.2.0+)
        $canonical_name = Tool_Catalog::resolve_name($tool_name);
        if ($canonical_name !== $tool_name) {
            // Log legacy alias usage for migration tracking
            $arguments['_legacy_alias'] = $tool_name;
            $tool_name = $canonical_name;
        }

        // Check rate limit (FIX: check() returns array, not bool)
        $rate = Rate_Limiter::check($this->user_id, $tool_name, null, $this->token_hash);
        if (!$rate['allowed']) {
            $latency = (int) ((microtime(true) - $start_time) * 1000);
            Audit_Logger::log_tool($tool_name, $this->user_id, 'rate_limited', $arguments, null, $latency, $rate['reason'] ?? 'rate_limit');
            $this->emit_metrics('mcp_tool_call', [
                'tool_name' => $tool_name,
                'result' => 'rate_limited',
                'latency_ms' => $latency,
            ]);

            $retry = $rate['retry_after'] ?? 10;
            throw new \Exception("Rate limit exceeded ({$rate['reason']}). Retry after {$retry}s");
        }

        // Check scope
        $required_scope = Token_Manager::get_required_scope($tool_name);
        if (!$this->has_scope($required_scope)) {
            $latency = (int) ((microtime(true) - $start_time) * 1000);
            Audit_Logger::log_tool($tool_name, $this->user_id, 'denied', $arguments, null, $latency, 'scope_denied');
            $this->emit_metrics('mcp_tool_call', [
                'tool_name' => $tool_name,
                'result' => 'denied',
                'latency_ms' => $latency,
            ]);
            throw new \Exception("Scope {$required_scope} required for tool {$tool_name}");
        }

        // Check permission
        $permissions = new Permission_Checker($this->user_id);
        if (!$permissions->can_execute($tool_name, $arguments)) {
            $latency = (int) ((microtime(true) - $start_time) * 1000);
            Audit_Logger::log_tool($tool_name, $this->user_id, 'denied', $arguments, null, $latency, 'permission_denied');
            $this->emit_metrics('mcp_tool_call', [
                'tool_name' => $tool_name,
                'result' => 'denied',
                'latency_ms' => $latency,
            ]);
            throw new \Exception('Tool execution not permitted');
        }

        // Execute tool
        $registry = new Tools_Registry();
        $error_code = null;
        $result_status = 'success';

        try {
            $result = $registry->handle_callback(null, $tool_name, $arguments, 1);

            if ($result === null) {
                $error_code = 'tool_not_found';
                $result_status = 'error';
                throw new \Exception("Tool not found: {$tool_name}");
            }

            // Extract result from JSON-RPC response format
            if (isset($result['result'])) {
                $tool_result = $result['result'];
            } else {
                $tool_result = $result;
            }

            // Check for error in result
            if (isset($tool_result['ok']) && !$tool_result['ok']) {
                $result_status = 'error';
                $error_code = $tool_result['error_code'] ?? 'tool_error';
            }

        } catch (\Exception $e) {
            $latency = (int) ((microtime(true) - $start_time) * 1000);
            $error_code = $error_code ?? 'execution_error';
            Audit_Logger::log_tool($tool_name, $this->user_id, 'error', $arguments, null, $latency, $error_code);
            $this->emit_metrics('mcp_tool_call', [
                'tool_name' => $tool_name,
                'result' => 'error',
                'error_code' => $error_code,
                'latency_ms' => $latency,
            ]);
            throw $e;
        }

        // Log successful execution
        $latency = (int) ((microtime(true) - $start_time) * 1000);
        Audit_Logger::log_tool($tool_name, $this->user_id, $result_status, $arguments, null, $latency, $error_code);
        $this->emit_metrics('mcp_tool_call', [
            'tool_name' => $tool_name,
            'result' => $result_status,
            'latency_ms' => $latency,
            'user_id' => $this->user_id,
            'token_type' => $this->token_type,
        ]);

        // Format as MCP tool result
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => is_array($tool_result) ? json_encode($tool_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string) $tool_result,
                ],
            ],
            'isError' => $result_status === 'error',
        ];
    }

    /**
     * Emit metrics event (v2.2.0+)
     */
    private function emit_metrics(string $event, array $data): void {
        do_action('ml_metrics', $event, array_merge($data, [
            'timestamp' => time(),
            'token_hash' => $this->token_hash,
        ]));
    }

    /**
     * Handle prompts/list
     */
    private function handle_prompts_list(array $params): array {
        if (!Prompts_Handler::is_available()) {
            return ['prompts' => []];
        }

        if (!$this->has_scope('read:content')) {
            throw new \Exception('Scope read:content required');
        }

        $handler = new Prompts_Handler($this->user_id);
        return $handler->list_prompts($params);
    }

    /**
     * Handle prompts/get
     */
    private function handle_prompts_get(array $params): array {
        if (!Prompts_Handler::is_available()) {
            throw new \Exception('Prompts not available');
        }

        if (!$this->has_scope('read:content')) {
            throw new \Exception('Scope read:content required');
        }

        $handler = new Prompts_Handler($this->user_id);
        $result = $handler->get_prompt($params);

        // Check for error in result
        if (isset($result['error'])) {
            throw new \Exception($result['error']['message']);
        }

        return $result;
    }

    /**
     * Handle completion/complete
     */
    private function handle_completion_complete(array $params): array {
        if (!Completion_Handler::is_available()) {
            return ['completion' => ['values' => [], 'hasMore' => false]];
        }

        if (!$this->has_scope('read:content')) {
            throw new \Exception('Scope read:content required');
        }

        $handler = new Completion_Handler($this->user_id);
        return $handler->complete($params);
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    /**
     * Check if current token has a scope
     */
    private function has_scope(string $scope): bool {
        // read:content is implicit if any read scope
        if ($scope === 'read:content' && !empty($this->scopes)) {
            return true;
        }
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Create JSON-RPC success response
     */
    private function json_rpc_success($id, $result): WP_REST_Response {
        $response = [
            'jsonrpc' => '2.0',
            'result' => $result,
        ];

        if ($id !== null) {
            $response['id'] = $id;
        }

        return new WP_REST_Response($response, 200);
    }

    /**
     * Create JSON-RPC error response
     */
    private function json_rpc_error($id, int $code, string $message, $data = null): WP_REST_Response {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        $response = [
            'jsonrpc' => '2.0',
            'error' => $error,
        ];

        if ($id !== null) {
            $response['id'] = $id;
        }

        // Map JSON-RPC error codes to HTTP status
        $http_status = match (true) {
            $code === -32700 => 400, // Parse error
            $code === -32600 => 400, // Invalid request
            $code === -32601 => 404, // Method not found
            $code === -32602 => 400, // Invalid params
            $code === -32001 => 401, // Auth error
            default => 500,
        };

        return new WP_REST_Response($response, $http_status);
    }

    /**
     * Log request to audit with B2B2B token context
     */
    private function log_request(string $method, string $result, array $params, int $latency_ms, ?string $error = null): void {
        if (!class_exists(Audit_Logger::class)) {
            return;
        }

        // Sanitize params (remove sensitive data)
        $safe_params = $params;
        unset($safe_params['arguments']); // Don't log full arguments

        // Build token context for B2B2B proof
        $extra = [
            'method' => $method,
            'error' => $error,
            // Token context (TICKET 2: B2B2B proof)
            'token_type' => $this->token_type,
            'token_hash_prefix' => $this->token_hash ? substr($this->token_hash, 0, 8) : null,
        ];

        // Add mission token info if applicable
        if ($this->mission_token_info) {
            $extra['mission_token_id'] = $this->mission_token_info['id'];
            $extra['mission_label'] = $this->mission_token_info['label'];
        }

        Audit_Logger::log([
            'tool_name' => 'mcp_' . str_replace('/', '_', $method),
            'user_id' => $this->user_id,
            'result' => $result,
            'latency_ms' => $latency_ms,
            'extra' => $extra,
        ]);
    }

    // ==========================================
    // DISCOVERY ENDPOINT
    // ==========================================

    /**
     * Handle discovery request (returns server info without auth)
     */
    public static function handle_discover(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'name' => self::SERVER_NAME,
            'version' => self::SERVER_VERSION,
            'protocol_version' => self::PROTOCOL_VERSION,
            'endpoint' => rest_url(self::NAMESPACE . '/mcp'),
            'sse_endpoint' => rest_url(self::NAMESPACE . '/sse'),
            'auth_type' => 'bearer',
            'auth_header' => 'Authorization: Bearer <token>',
            'capabilities' => ['tools', 'prompts', 'completions'],
            'documentation' => 'https://marylink.io/docs/mcp',
        ], 200);
    }

    // ==========================================

    /**
     * Handle direct GET /tools request (without JSON-RPC wrapper)
     * Useful for clients that expect REST-style API
     */
    public static function handle_tools_list_direct(WP_REST_Request $request): WP_REST_Response {
        $controller = new self();
        $controller->token_manager = new Token_Manager();

        // Get token from Authorization header
        $headers = $request->get_headers();
        $auth_header = $headers['authorization'][0] ?? '';
        $token = '';

        if (preg_match('/Bearer\s+(\S+)/i', $auth_header, $matches)) {
            $token = $matches[1];
        }

        if (empty($token)) {
            return new WP_REST_Response([
                'error' => 'Authentication required: Bearer token missing',
                'tools' => [],
            ], 401);
        }

        // Validate token
        $result = $controller->token_manager->validate_token_full($token);
        if (!$result['valid']) {
            return new WP_REST_Response([
                'error' => 'Invalid or expired token',
                'tools' => [],
            ], 401);
        }

        $controller->user_id = $result['user_id'];
        $controller->scopes = $result['scopes'] ?? ['read:content'];
        wp_set_current_user($controller->user_id);

        // Get tools
        // V3: Use Tool_Catalog_V3 as single source of truth
        $tools = Tool_Catalog_V3::build();

        return new WP_REST_Response([
            'tools' => $tools,
            'count' => count($tools),
            'profile' => $ctx['profile'],
        ], 200);
    }

    // SSE ENDPOINT (OPTIONAL)
    // ==========================================

    /**
     * Handle SSE connection for notifications
     */
    public static function handle_sse(WP_REST_Request $request): void {
        // Check auth
        $controller = new self();
        $controller->token_manager = new Token_Manager();

        $token = $request->get_param('token') ?? '';
        if (empty($token)) {
            http_response_code(401);
            echo "data: {\"error\": \"Token required\"}\n\n";
            exit;
        }

        $result = $controller->token_manager->validate_token_full($token);
        if (!$result['valid']) {
            http_response_code(401);
            echo "data: {\"error\": \"Invalid token\"}\n\n";
            exit;
        }

        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Send initial connected event
        echo "event: connected\n";
        echo "data: " . json_encode(['status' => 'connected', 'user_id' => $result['user_id']]) . "\n\n";

        if (ob_get_level()) {
            ob_flush();
        }
        flush();

        // Keep connection alive (heartbeat)
        $start = time();
        $timeout = 300; // 5 minutes

        while ((time() - $start) < $timeout) {
            // Send heartbeat
            echo ": heartbeat\n\n";

            if (ob_get_level()) {
                ob_flush();
            }
            flush();

            // Check for notifications (could be from transient/cache)
            // For now, just heartbeat
            sleep(30);

            if (connection_aborted()) {
                break;
            }
        }

        exit;
    }

    /**
     * Handle SSE for Claude Web (token in path, MCP 2024-11-05 spec)
     */
    public static function handle_sse_claude(WP_REST_Request $request): void {
        $token = $request->get_param("token") ?? "";

        // Disable all output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }

        // CORS headers
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Authorization, Content-Type");

        if (empty($token)) {
            http_response_code(401);
            header("Content-Type: text/event-stream");
            echo "event: error\ndata: Token required\n\n";
            exit;
        }

        $controller = new self();
        $controller->token_manager = new Token_Manager();
        $result = $controller->token_manager->validate_token_full($token);

        if (!$result["valid"]) {
            http_response_code(401);
            header("Content-Type: text/event-stream");
            echo "event: error\ndata: Invalid token\n\n";
            exit;
        }

        // SSE headers
        header("Content-Type: text/event-stream");
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Connection: keep-alive");
        header("X-Accel-Buffering: no");

        // Generate session ID (like mcpfullpower does)
        $session_id = bin2hex(random_bytes(16));

        // Send padding to force flush (like mcpfullpower)
        echo ":" . str_repeat(" ", 2048) . "\n\n";
        flush();

        // Send session ID
        echo "id: {$session_id}\n\n";
        flush();

        // MCP spec: endpoint event with session_id in URL
        $endpoint_url = rest_url(self::NAMESPACE . "/" . $token . "/messages") . "?session_id=" . $session_id;
        echo "event: endpoint\n";
        echo "data: {$endpoint_url}\n\n";
        flush();

        // Heartbeat loop
        $start = time();
        $timeout = 300;

        while ((time() - $start) < $timeout) {
            echo ": heartbeat " . time() . "\n\n";
            flush();
            sleep(30);

            if (connection_aborted()) {
                break;
            }
        }
        exit;
    }

    /**
     * Handle messages for Claude Web (token in path)
     */
    public static function handle_messages_claude(WP_REST_Request $request): WP_REST_Response {
        // Handle OPTIONS preflight
        if ($request->get_method() === "OPTIONS") {
            $response = new WP_REST_Response(null, 200);
            $response->header("Access-Control-Allow-Origin", "*");
            $response->header("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
            $response->header("Access-Control-Allow-Headers", "Authorization, Content-Type");
            return $response;
        }

        $token = $request->get_param("token");

        // Create internal request with Bearer token
        $internal_request = new WP_REST_Request("POST", "/" . self::NAMESPACE . "/mcp");
        $internal_request->set_header("authorization", "Bearer " . $token);
        $internal_request->set_header("content-type", "application/json");
        $internal_request->set_body($request->get_body());

        // Forward to main handler
        $response = self::handle_request($internal_request);

        // Add CORS headers
        $response->header("Access-Control-Allow-Origin", "*");

        return $response;
    }

}
