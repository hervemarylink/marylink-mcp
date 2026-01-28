<?php
/**
 * Migration Service - V2 to V3 compatibility layer
 *
 * Handles backward compatibility by translating V2 tool calls to V3 equivalents.
 * Provides deprecation warnings and migration guidance.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Services;

use MCP_No_Headless\MCP\Core\Tools\{Find, Run, Save, Me, Ping, Assist};

class Migration_Service {

    const VERSION = '3.0.0';

    // Migration mode
    const MODE_STRICT = 'strict';       // Reject V2 calls
    const MODE_COMPAT = 'compat';       // Translate V2 to V3
    const MODE_WARN = 'warn';           // Translate with warnings
    const MODE_SILENT = 'silent';       // Silent translation

    // Current mode (configurable via option)
    private static ?string $mode = null;

    // V2 to V3 tool mappings
    private static array $tool_mappings = [
        // Search/Read tools → ml_find
        'ml_search' => [
            'v3_tool' => 'ml_find',
            'transform' => 'search_to_find',
            'deprecated' => true,
        ],
        'ml_get' => [
            'v3_tool' => 'ml_find',
            'transform' => 'get_to_find',
            'deprecated' => true,
        ],
        'ml_publication_get' => [
            'v3_tool' => 'ml_find',
            'transform' => 'publication_get_to_find',
            'deprecated' => true,
        ],
        'ml_space_info' => [
            'v3_tool' => 'ml_find',
            'transform' => 'space_info_to_find',
            'deprecated' => true,
        ],
        'ml_tool_read' => [
            'v3_tool' => 'ml_find',
            'transform' => 'tool_read_to_find',
            'deprecated' => true,
        ],
        'ml_list_publications' => [
            'v3_tool' => 'ml_find',
            'transform' => 'list_publications_to_find',
            'deprecated' => true,
        ],
        'ml_list_spaces' => [
            'v3_tool' => 'ml_find',
            'transform' => 'list_spaces_to_find',
            'deprecated' => true,
        ],

        // Execution tools → ml_run
        'ml_apply_tool' => [
            'v3_tool' => 'ml_run',
            'transform' => 'apply_tool_to_run',
            'deprecated' => true,
        ],
        'ml_generate' => [
            'v3_tool' => 'ml_run',
            'transform' => 'generate_to_run',
            'deprecated' => true,
        ],
        'ml_transform' => [
            'v3_tool' => 'ml_run',
            'transform' => 'transform_to_run',
            'deprecated' => true,
        ],
        'ml_batch_apply' => [
            'v3_tool' => 'ml_run',
            'transform' => 'batch_apply_to_run',
            'deprecated' => true,
        ],

        // Save tools → ml_save
        'ml_publication_create' => [
            'v3_tool' => 'ml_save',
            'transform' => 'publication_create_to_save',
            'deprecated' => true,
        ],
        'ml_publication_update' => [
            'v3_tool' => 'ml_save',
            'transform' => 'publication_update_to_save',
            'deprecated' => true,
        ],
        'ml_save_draft' => [
            'v3_tool' => 'ml_save',
            'transform' => 'save_draft_to_save',
            'deprecated' => true,
        ],

        // User tools → ml_me
        'ml_user_profile' => [
            'v3_tool' => 'ml_me',
            'transform' => 'user_profile_to_me',
            'deprecated' => true,
        ],
        'ml_my_spaces' => [
            'v3_tool' => 'ml_me',
            'transform' => 'my_spaces_to_me',
            'deprecated' => true,
        ],
        'ml_user_stats' => [
            'v3_tool' => 'ml_me',
            'transform' => 'user_stats_to_me',
            'deprecated' => true,
        ],
        'ml_feedback' => [
            'v3_tool' => 'ml_me',
            'transform' => 'feedback_to_me',
            'deprecated' => true,
        ],

        // Diagnostic tools → ml_ping
        'ml_health' => [
            'v3_tool' => 'ml_ping',
            'transform' => 'health_to_ping',
            'deprecated' => true,
        ],
        'ml_status' => [
            'v3_tool' => 'ml_ping',
            'transform' => 'status_to_ping',
            'deprecated' => true,
        ],

        // Assist tools → ml_assist
        'ml_suggest' => [
            'v3_tool' => 'ml_assist',
            'transform' => 'suggest_to_assist',
            'deprecated' => true,
        ],
        'ml_recommend' => [
            'v3_tool' => 'ml_assist',
            'transform' => 'recommend_to_assist',
            'deprecated' => true,
        ],
    ];

    /**
     * Check if a tool is a V2 legacy tool
     */
    public static function is_legacy_tool(string $tool_name): bool {
        return isset(self::$tool_mappings[$tool_name]);
    }

    /**
     * Get V3 equivalent for a V2 tool
     */
    public static function get_v3_equivalent(string $v2_tool): ?string {
        return self::$tool_mappings[$v2_tool]['v3_tool'] ?? null;
    }

    /**
     * Translate V2 call to V3
     *
     * @param string $tool_name V2 tool name
     * @param array $args V2 arguments
     * @param int $user_id User ID
     * @return array Translated result or execution result
     */
    public static function translate(string $tool_name, array $args, int $user_id): array {
        $mode = self::get_mode();

        // Check if this is a legacy tool
        if (!self::is_legacy_tool($tool_name)) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'unknown_tool',
                    'message' => "Tool '$tool_name' is not a recognized V2 tool",
                ],
            ];
        }

        $mapping = self::$tool_mappings[$tool_name];

        // Strict mode rejects V2 calls
        if ($mode === self::MODE_STRICT) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'deprecated_tool',
                    'message' => "Tool '$tool_name' is deprecated. Use '{$mapping['v3_tool']}' instead.",
                    'migration_hint' => self::get_migration_hint($tool_name, $args),
                ],
            ];
        }

        // Transform arguments
        $transform_method = $mapping['transform'];
        $v3_args = self::$transform_method($args);

        // Add deprecation warning
        $warning = null;
        if ($mode === self::MODE_WARN) {
            $warning = [
                'type' => 'deprecation',
                'message' => "Tool '$tool_name' is deprecated and will be removed in a future version.",
                'recommendation' => "Use '{$mapping['v3_tool']}' instead.",
                'migration_hint' => self::get_migration_hint($tool_name, $args),
            ];

            // Log deprecation
            self::log_deprecation($tool_name, $mapping['v3_tool'], $user_id);
        }

        // Execute V3 tool
        $result = self::execute_v3_tool($mapping['v3_tool'], $v3_args, $user_id);

        // Add warning to result if in warn mode
        if ($warning) {
            $result['_deprecation_warning'] = $warning;
        }

        // Transform result back to V2 format if needed
        $result = self::transform_result($tool_name, $result);

        return $result;
    }

    /**
     * Execute V3 tool
     */
    private static function execute_v3_tool(string $tool, array $args, int $user_id): array {
        return match ($tool) {
            'ml_find' => Find::execute($args, $user_id),
            'ml_run' => Run::execute($args, $user_id),
            'ml_save' => Save::execute($args, $user_id),
            'ml_me' => Me::execute($args, $user_id),
            'ml_ping' => Ping::execute($args, $user_id),
            'ml_assist' => Assist::execute($args, $user_id),
            default => ['success' => false, 'error' => ['message' => "Unknown V3 tool: $tool"]],
        };
    }

    // =========================================================================
    // TRANSFORMATION METHODS
    // =========================================================================

    private static function search_to_find(array $args): array {
        return [
            'query' => $args['query'] ?? $args['search_term'] ?? '',
            'type' => $args['type'] ?? null,
            'space_id' => $args['space_id'] ?? null,
            'limit' => $args['limit'] ?? $args['per_page'] ?? 10,
            'offset' => $args['offset'] ?? (($args['page'] ?? 1) - 1) * ($args['per_page'] ?? 10),
        ];
    }

    private static function get_to_find(array $args): array {
        return [
            'id' => $args['id'] ?? $args['item_id'] ?? null,
            'type' => $args['type'] ?? null,
            'include' => ['content', 'metadata'],
        ];
    }

    private static function publication_get_to_find(array $args): array {
        return [
            'id' => $args['publication_id'] ?? $args['id'] ?? null,
            'type' => 'publication',
            'include' => $args['with_comments'] ?? false ? ['content', 'metadata', 'comments'] : ['content', 'metadata'],
        ];
    }

    private static function space_info_to_find(array $args): array {
        return [
            'id' => $args['space_id'] ?? $args['group_id'] ?? null,
            'type' => 'space',
            'include' => ['content', 'metadata'],
        ];
    }

    private static function tool_read_to_find(array $args): array {
        return [
            'id' => $args['tool_id'] ?? null,
            'type' => 'tool',
            'include' => ['content', 'metadata'],
        ];
    }

    private static function list_publications_to_find(array $args): array {
        return [
            'type' => 'publication',
            'space_id' => $args['space_id'] ?? null,
            'filters' => [
                'author_id' => $args['author_id'] ?? null,
                'visibility' => $args['visibility'] ?? null,
            ],
            'limit' => $args['limit'] ?? $args['per_page'] ?? 10,
            'offset' => $args['offset'] ?? 0,
        ];
    }

    private static function list_spaces_to_find(array $args): array {
        return [
            'type' => 'space',
            'filters' => [
                'user_id' => $args['user_id'] ?? null,
                'status' => $args['status'] ?? null,
            ],
            'limit' => $args['limit'] ?? 20,
        ];
    }

    private static function apply_tool_to_run(array $args): array {
        return [
            'tool_id' => $args['tool_id'] ?? null,
            'input' => $args['input'] ?? $args['text'] ?? $args['content'] ?? null,
            'source_id' => $args['publication_id'] ?? $args['source_id'] ?? null,
            'context' => [
                'space_id' => $args['space_id'] ?? null,
            ],
        ];
    }

    private static function generate_to_run(array $args): array {
        return [
            'prompt' => $args['prompt'] ?? $args['instruction'] ?? null,
            'input' => $args['context'] ?? $args['input'] ?? null,
            'model' => $args['model'] ?? null,
        ];
    }

    private static function transform_to_run(array $args): array {
        return [
            'tool_id' => $args['transformation_id'] ?? $args['tool_id'] ?? null,
            'input' => $args['text'] ?? $args['content'] ?? null,
            'source_id' => $args['source_id'] ?? null,
        ];
    }

    private static function batch_apply_to_run(array $args): array {
        return [
            'tool_id' => $args['tool_id'] ?? null,
            'source_ids' => $args['publication_ids'] ?? $args['source_ids'] ?? [],
            'mode' => 'async',
        ];
    }

    private static function publication_create_to_save(array $args): array {
        return [
            'mode' => 'create',
            'title' => $args['title'] ?? null,
            'content' => $args['content'] ?? $args['text'] ?? null,
            'space_id' => $args['space_id'] ?? $args['group_id'] ?? null,
            'visibility' => $args['visibility'] ?? $args['status'] ?? 'public',
            'tool_id' => $args['tool_id'] ?? null,
            'tags' => $args['tags'] ?? [],
        ];
    }

    private static function publication_update_to_save(array $args): array {
        return [
            'mode' => 'update',
            'publication_id' => $args['publication_id'] ?? $args['id'] ?? null,
            'title' => $args['title'] ?? null,
            'content' => $args['content'] ?? null,
            'space_id' => $args['space_id'] ?? null,
            'visibility' => $args['visibility'] ?? null,
        ];
    }

    private static function save_draft_to_save(array $args): array {
        return [
            'mode' => 'create',
            'title' => $args['title'] ?? 'Draft',
            'content' => $args['content'] ?? null,
            'visibility' => 'private',
            'metadata' => ['is_draft' => true],
        ];
    }

    private static function user_profile_to_me(array $args): array {
        return [
            'aspect' => ['profile'],
        ];
    }

    private static function my_spaces_to_me(array $args): array {
        return [
            'aspect' => ['spaces'],
            'limit' => $args['limit'] ?? 20,
        ];
    }

    private static function user_stats_to_me(array $args): array {
        return [
            'aspect' => ['stats'],
            'period' => $args['period'] ?? '30d',
        ];
    }

    private static function feedback_to_me(array $args): array {
        return [
            'aspect' => ['feedback'],
            'feedback_text' => $args['message'] ?? $args['feedback'] ?? $args['text'] ?? '',
            'feedback_type' => $args['type'] ?? 'general',
            'context' => $args['context'] ?? [],
        ];
    }

    private static function health_to_ping(array $args): array {
        return []; // ml_ping takes no arguments
    }

    private static function status_to_ping(array $args): array {
        return [];
    }

    private static function suggest_to_assist(array $args): array {
        return [
            'query' => $args['query'] ?? $args['text'] ?? '',
            'content' => $args['content'] ?? null,
            'mode' => 'suggest',
        ];
    }

    private static function recommend_to_assist(array $args): array {
        return [
            'query' => $args['task'] ?? $args['query'] ?? '',
            'space_id' => $args['space_id'] ?? null,
            'mode' => 'suggest',
        ];
    }

    // =========================================================================
    // RESULT TRANSFORMATION
    // =========================================================================

    /**
     * Transform V3 result back to V2 format
     */
    private static function transform_result(string $v2_tool, array $result): array {
        // Most results can pass through, but some V2 tools expected different formats

        // Example: V2 ml_search returned 'results' instead of 'items'
        if (in_array($v2_tool, ['ml_search', 'ml_list_publications', 'ml_list_spaces'])) {
            if (isset($result['items'])) {
                $result['results'] = $result['items'];
            }
        }

        // V2 ml_publication_get returned flat structure
        if ($v2_tool === 'ml_publication_get' && isset($result['item'])) {
            $result = array_merge($result, $result['item']);
            unset($result['item']);
        }

        return $result;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get current migration mode
     */
    private static function get_mode(): string {
        if (self::$mode === null) {
            self::$mode = get_option('mcpnh_migration_mode', self::MODE_WARN);
        }
        return self::$mode;
    }

    /**
     * Set migration mode
     */
    public static function set_mode(string $mode): void {
        self::$mode = $mode;
        update_option('mcpnh_migration_mode', $mode);
    }

    /**
     * Get migration hint for a tool
     */
    public static function get_migration_hint(string $v2_tool, array $args): array {
        $mapping = self::$tool_mappings[$v2_tool] ?? null;

        if (!$mapping) {
            return [];
        }

        $transform_method = $mapping['transform'];
        $v3_args = self::$transform_method($args);

        return [
            'v2_tool' => $v2_tool,
            'v3_tool' => $mapping['v3_tool'],
            'v3_args_example' => $v3_args,
            'documentation' => "https://docs.marylink.io/mcp/v3/tools/{$mapping['v3_tool']}",
        ];
    }

    /**
     * Log deprecation for analytics
     */
    private static function log_deprecation(string $v2_tool, string $v3_tool, int $user_id): void {
        global $wpdb;

        $table = $wpdb->prefix . 'mcpnh_deprecation_log';

        // Only log if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }

        $wpdb->insert($table, [
            'v2_tool' => $v2_tool,
            'v3_tool' => $v3_tool,
            'user_id' => $user_id,
            'logged_at' => current_time('mysql'),
        ]);
    }

    /**
     * Get deprecation statistics
     */
    public static function get_deprecation_stats(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'mcpnh_deprecation_log';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return ['available' => false];
        }

        $stats = $wpdb->get_results(
            "SELECT v2_tool, COUNT(*) as count, MAX(logged_at) as last_used
             FROM $table
             GROUP BY v2_tool
             ORDER BY count DESC",
            ARRAY_A
        );

        return [
            'available' => true,
            'tools' => $stats,
            'total_calls' => array_sum(array_column($stats, 'count')),
        ];
    }

    /**
     * Get all deprecated tools
     */
    public static function get_deprecated_tools(): array {
        $deprecated = [];

        foreach (self::$tool_mappings as $v2_tool => $mapping) {
            if ($mapping['deprecated'] ?? false) {
                $deprecated[$v2_tool] = [
                    'replacement' => $mapping['v3_tool'],
                    'transform' => $mapping['transform'],
                ];
            }
        }

        return $deprecated;
    }

    /**
     * Create deprecation log table
     */
    public static function create_deprecation_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'mcpnh_deprecation_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            v2_tool VARCHAR(100) NOT NULL,
            v3_tool VARCHAR(100) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            logged_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_v2_tool (v2_tool),
            KEY idx_logged_at (logged_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
