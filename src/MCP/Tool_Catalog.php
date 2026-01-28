<?php
/**
 * Tool Catalog - Single source of truth for all MCP tools
 *
 * This class provides a unified catalog of all tools with:
 * - Canonical names (ml_spaces_list instead of ml_list_spaces)
 * - Profile classification (core|advanced|admin)
 * - Scope requirements
 * - Legacy alias mapping
 *
 * @package MCP_No_Headless
 * @since 2.2.0
 */

namespace MCP_No_Headless\MCP;

class Tool_Catalog {

    /**
     * Profile constants
     */
    const PROFILE_CORE = 'core';
    const PROFILE_ADVANCED = 'advanced';
    const PROFILE_ADMIN = 'admin';

    /**
     * Legacy name mappings (legacy => canonical)
     * Calls to legacy names are accepted but not exposed in tools/list
     */
    const LEGACY_ALIASES = [
        'ml_list_spaces' => 'ml_spaces_list',
        'ml_list_publications' => 'ml_publications_list',
        'ml_get_publication' => 'ml_publication_get',
        'ml_get_space' => 'ml_space_get',
        'ml_list_favorites' => 'ml_favorites_list',
        'ml_list_comments' => 'ml_comments_list',
        'ml_create_publication' => 'ml_publication_create',
        'ml_update_publication' => 'ml_publication_update',
        'ml_list_ratings' => 'ml_ratings_list',
    ];

    /**
     * Reverse alias mapping (canonical => legacy)
     */
    private static ?array $reverse_aliases = null;

    /**
     * Build the tool catalog based on context
     *
     * @param array $ctx Context array with keys:
     *   - user_id: int
     *   - profile: string (core|advanced|admin)
     *   - scopes: array of granted scopes
     *   - include_legacy: bool (default false)
     * @return array Tool definitions
     */
    public static function build(array $ctx = []): array {
        $user_id = $ctx['user_id'] ?? get_current_user_id();
        $profile = $ctx['profile'] ?? self::get_user_profile($user_id);
        $scopes = $ctx['scopes'] ?? ['read:content'];
        $include_legacy = $ctx['include_legacy'] ?? false;

        $all_tools = self::get_all_definitions();
        $filtered = [];

        foreach ($all_tools as $tool) {
            // Filter by profile
            if (!self::profile_allows($profile, $tool['profile'] ?? self::PROFILE_CORE)) {
                continue;
            }

            // Filter by scope
            $required_scope = $tool['scopes_required'][0] ?? 'read:content';
            if (!in_array($required_scope, $scopes) && !in_array('admin', $scopes)) {
                continue;
            }

            // Skip legacy tools unless explicitly requested
            if (!$include_legacy && isset($tool['alias_of'])) {
                continue;
            }

            $filtered[] = self::format_for_mcp($tool);
        }

        return $filtered;
    }

    /**
     * Get all tool definitions (internal, with metadata)
     *
     * @return array
     */
    public static function get_all_definitions(): array {
        return array_merge(
            self::get_core_tools(),
            self::get_advanced_tools(),
            self::get_admin_tools()
        );
    }

    /**
     * Get profile counts for display
     *
     * @return array ['core' => int, 'advanced' => int, 'admin' => int]
     */
    public static function get_profile_counts(): array {
        return [
            'core' => count(self::get_core_tools()),
            'advanced' => count(self::get_advanced_tools()),
            'admin' => count(self::get_admin_tools()),
        ];
    }

    /**
     * Core tools - exposed to Claude.ai web by default (7-9 tools)
     *
     * @return array
     */
    private static function get_core_tools(): array {
        return [
            // 1. Help
            [
                'name' => 'ml_help',
                'description' => 'Get help about MaryLink MCP tools, capabilities, and usage. Call this first to understand available features.',
                'profile' => self::PROFILE_CORE,
                'scopes_required' => ['read:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'topic' => [
                            'type' => 'string',
                            'description' => 'Topic to get help about (tools, spaces, publications, workflow)',
                        ],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            // 2. Spaces list
            [
                'name' => 'ml_spaces_list',
                'description' => 'List available spaces/workspaces. Each space contains publications.',
                'profile' => self::PROFILE_CORE,
                'scopes_required' => ['read:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results (default 20)',
                        ],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            // 3. Publications list
            [
                'name' => 'ml_publications_list',
                'description' => 'List publications with filters. Publications are content items (prompts, templates, guides).',
                'profile' => self::PROFILE_CORE,
                'scopes_required' => ['read:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'Filter by space ID',
                        ],
                        'search' => [
                            'type' => 'string',
                            'description' => 'Search term',
                        ],
                        'type' => [
                            'type' => 'string',
                            'description' => 'Filter by type (prompt, template, guide, tool)',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results (default 10, max 50)',
                        ],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            // 4. Publication get
            [
                'name' => 'ml_publication_get',
                'description' => 'Get full details of a publication by ID.',
                'profile' => self::PROFILE_CORE,
                'scopes_required' => ['read:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'Publication ID',
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            // 5. Assist prepare
            [
                'name' => 'ml_assist_prepare',
                'description' => 'Prepare assistance context for a task. Analyzes the request and suggests relevant publications.',
                'profile' => self::PROFILE_CORE,
                'scopes_required' => ['read:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'User query or task description',
                        ],
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'Space context (optional)',
                        ],
                        'max_suggestions' => [
                            'type' => 'integer',
                            'description' => 'Max suggestions (default 5)',
                        ],
                    ],
                    'required' => ['query'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            // 6. Apply tool (prepare + commit flow)
            [
                'name' => 'ml_apply_tool',
                'description' => 'Apply a tool/action. Use mode=prepare to preview changes, mode=commit to execute.',
                'profile' => self::PROFILE_CORE,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'mode' => [
                            'type' => 'string',
                            'enum' => ['prepare', 'commit'],
                            'description' => 'prepare=preview, commit=execute',
                        ],
                        'action' => [
                            'type' => 'string',
                            'description' => 'Action to perform',
                        ],
                        'target_id' => [
                            'type' => 'integer',
                            'description' => 'Target publication/space ID',
                        ],
                        'params' => [
                            'type' => 'object',
                            'description' => 'Action parameters',
                        ],
                        'session_id' => [
                            'type' => 'string',
                            'description' => 'Session ID from prepare (required for commit)',
                        ],
                    ],
                    'required' => ['mode', 'action'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
            ],

            // 7. Feedback
            [
                'name' => 'ml_feedback',
                'description' => 'Record feedback on a tool result or publication. Helps improve recommendations.',
                'profile' => self::PROFILE_CORE,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'target_type' => [
                            'type' => 'string',
                            'enum' => ['publication', 'tool_result', 'recommendation'],
                            'description' => 'What is being rated',
                        ],
                        'target_id' => [
                            'type' => 'integer',
                            'description' => 'ID of the target',
                        ],
                        'rating' => [
                            'type' => 'string',
                            'enum' => ['thumbs_up', 'thumbs_down'],
                            'description' => 'Rating',
                        ],
                        'comment' => [
                            'type' => 'string',
                            'description' => 'Optional feedback comment',
                        ],
                    ],
                    'required' => ['target_type', 'target_id', 'rating'],
                ],
                'annotations' => ['readOnlyHint' => false],
            ],

            // 8. Recommend (suggestions based on context)
            [
                'name' => 'ml_recommend',
                'description' => 'Get personalized recommendations based on current context and history.',
                'profile' => self::PROFILE_CORE,
                'scopes_required' => ['read:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'context' => [
                            'type' => 'string',
                            'description' => 'Current task context',
                        ],
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'Space to search in (optional)',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max recommendations (default 5)',
                        ],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],
        ];
    }

    /**
     * Advanced tools - for power users with advanced profile
     *
     * @return array
     */
    private static function get_advanced_tools(): array {
        return [
            // Bulk operations
            [
                'name' => 'ml_bulk_update',
                'description' => 'Bulk update multiple publications at once.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'IDs to update',
                        ],
                        'changes' => [
                            'type' => 'object',
                            'description' => 'Changes to apply',
                        ],
                    ],
                    'required' => ['publication_ids', 'changes'],
                ],
                'annotations' => ['destructiveHint' => true],
            ],

            // Auto-improve
            [
                'name' => 'ml_auto_improve',
                'description' => 'Automatically improve publication content using AI.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'Publication to improve',
                        ],
                        'aspect' => [
                            'type' => 'string',
                            'enum' => ['clarity', 'completeness', 'grammar', 'all'],
                            'description' => 'What to improve',
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => ['destructiveHint' => true],
            ],

            // Compare
            [
                'name' => 'ml_compare',
                'description' => 'Compare two or more publications side by side.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['read:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'IDs to compare (2-5)',
                        ],
                    ],
                    'required' => ['publication_ids'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            // Duplicate
            [
                'name' => 'ml_duplicate',
                'description' => 'Duplicate a publication with optional modifications.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'Source publication ID',
                        ],
                        'target_space_id' => [
                            'type' => 'integer',
                            'description' => 'Target space (optional)',
                        ],
                        'new_title' => [
                            'type' => 'string',
                            'description' => 'New title (optional)',
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => ['destructiveHint' => false],
            ],

            // Export
            [
                'name' => 'ml_export',
                'description' => 'Export publications to various formats.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['read:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'IDs to export',
                        ],
                        'format' => [
                            'type' => 'string',
                            'enum' => ['json', 'markdown', 'csv'],
                            'description' => 'Export format',
                        ],
                    ],
                    'required' => ['publication_ids', 'format'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            // Chain
            [
                'name' => 'ml_chain',
                'description' => 'Chain multiple tools together in a workflow.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'steps' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'tool' => ['type' => 'string'],
                                    'params' => ['type' => 'object'],
                                ],
                            ],
                            'description' => 'Steps to execute',
                        ],
                    ],
                    'required' => ['steps'],
                ],
                'annotations' => ['destructiveHint' => true],
            ],

            // Search advanced
            [
                'name' => 'ml_search_advanced',
                'description' => 'Advanced search with full-text, semantic, and faceted filters.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['read:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query',
                        ],
                        'mode' => [
                            'type' => 'string',
                            'enum' => ['fulltext', 'semantic', 'hybrid'],
                            'description' => 'Search mode',
                        ],
                        'filters' => [
                            'type' => 'object',
                            'description' => 'Faceted filters',
                        ],
                    ],
                    'required' => ['query'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            // Favorites
            [
                'name' => 'ml_favorites_list',
                'description' => 'List user favorites/bookmarks.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['read:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results',
                        ],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            // Favorites toggle
            [
                'name' => 'ml_favorite_toggle',
                'description' => 'Add or remove a publication from favorites.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'Publication ID',
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => ['readOnlyHint' => false],
            ],

            // Comments
            [
                'name' => 'ml_comments_list',
                'description' => 'List comments on a publication.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['read:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'Publication ID',
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            // Comment add
            [
                'name' => 'ml_comment_add',
                'description' => 'Add a comment to a publication.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'Publication ID',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Comment content',
                        ],
                    ],
                    'required' => ['publication_id', 'content'],
                ],
                'annotations' => ['readOnlyHint' => false],
            ],

            // Ratings
            [
                'name' => 'ml_rate',
                'description' => 'Rate a publication (1-5 stars).',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'Publication ID',
                        ],
                        'rating' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 5,
                            'description' => 'Rating (1-5)',
                        ],
                    ],
                    'required' => ['publication_id', 'rating'],
                ],
                'annotations' => ['readOnlyHint' => false],
            ],
        ];
    }

    /**
     * Admin tools - only for administrators
     *
     * @return array
     */
    private static function get_admin_tools(): array {
        return [
            // Publication create
            [
                'name' => 'ml_publication_create',
                'description' => 'Create a new publication.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'description' => 'Publication title',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Publication content',
                        ],
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'Target space ID',
                        ],
                        'type' => [
                            'type' => 'string',
                            'description' => 'Publication type',
                        ],
                    ],
                    'required' => ['title', 'content', 'space_id'],
                ],
                'annotations' => ['destructiveHint' => false],
            ],

            // Publication update
            [
                'name' => 'ml_publication_update',
                'description' => 'Update an existing publication.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'Publication ID',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'New title',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'New content',
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => ['destructiveHint' => true],
            ],

            // Publication delete
            [
                'name' => 'ml_publication_delete',
                'description' => 'Delete a publication (move to trash).',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'Publication ID',
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => ['destructiveHint' => true],
            ],

            // Space create
            [
                'name' => 'ml_space_create',
                'description' => 'Create a new space.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Space name',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Space description',
                        ],
                    ],
                    'required' => ['name'],
                ],
                'annotations' => ['destructiveHint' => false],
            ],

            // Audit logs
            [
                'name' => 'ml_audit_logs',
                'description' => 'View audit logs for MCP operations.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'period' => [
                            'type' => 'string',
                            'enum' => ['1h', '24h', '7d', '30d'],
                            'description' => 'Time period',
                        ],
                        'tool' => [
                            'type' => 'string',
                            'description' => 'Filter by tool name',
                        ],
                        'user_id' => [
                            'type' => 'integer',
                            'description' => 'Filter by user',
                        ],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            // Stats
            [
                'name' => 'ml_stats',
                'description' => 'Get MCP usage statistics.',
                'profile' => self::PROFILE_ADVANCED,
                'scopes_required' => ['write:content'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'period' => [
                            'type' => 'string',
                            'enum' => ['24h', '7d', '30d'],
                            'description' => 'Time period',
                        ],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],
        ];
    }

    /**
     * Check if a profile allows access to a tool profile level
     *
     * @param string $user_profile User's profile (core|advanced|admin)
     * @param string $tool_profile Tool's required profile
     * @return bool
     */
    private static function profile_allows(string $user_profile, string $tool_profile): bool {
        $hierarchy = [
            self::PROFILE_CORE => 1,
            self::PROFILE_ADVANCED => 2,
            self::PROFILE_ADMIN => 3,
        ];

        $user_level = $hierarchy[$user_profile] ?? 1;
        $tool_level = $hierarchy[$tool_profile] ?? 1;

        return $user_level >= $tool_level;
    }

    /**
     * Get user's exposure profile
     *
     * @param int $user_id
     * @return string
     */
    public static function get_user_profile(int $user_id): string {
        // Check user meta for explicit profile setting
        $profile = get_user_meta($user_id, '_mlmcp_exposure_profile', true);

        if ($profile && in_array($profile, [self::PROFILE_CORE, self::PROFILE_ADVANCED, self::PROFILE_ADMIN])) {
            // Validate admin profile requires admin capability
            if ($profile === self::PROFILE_ADMIN && !user_can($user_id, 'manage_options')) {
                return self::PROFILE_ADVANCED;
            }
            return $profile;
        }

        // Default: admin users get advanced, others get core
        if (user_can($user_id, 'manage_options')) {
            return self::PROFILE_ADVANCED;
        }

        return self::PROFILE_CORE;
    }

    /**
     * Set user's exposure profile
     *
     * @param int $user_id
     * @param string $profile
     * @return bool
     */
    public static function set_user_profile(int $user_id, string $profile): bool {
        if (!in_array($profile, [self::PROFILE_CORE, self::PROFILE_ADVANCED, self::PROFILE_ADMIN])) {
            return false;
        }

        // Admin profile requires admin capability
        if ($profile === self::PROFILE_ADMIN && !user_can($user_id, 'manage_options')) {
            return false;
        }

        return (bool) update_user_meta($user_id, '_mlmcp_exposure_profile', $profile);
    }

    /**
     * Format tool definition for MCP protocol
     *
     * @param array $tool Internal tool definition
     * @return array MCP-formatted tool
     */
    private static function format_for_mcp(array $tool): array {
        return [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'inputSchema' => $tool['inputSchema'] ?? ['type' => 'object', 'properties' => []],
            'annotations' => $tool['annotations'] ?? [],
        ];
    }

    /**
     * Resolve a tool name (handles legacy aliases)
     *
     * @param string $name Tool name (possibly legacy)
     * @return string Canonical tool name
     */
    public static function resolve_name(string $name): string {
        return self::LEGACY_ALIASES[$name] ?? $name;
    }

    /**
     * Check if a name is a legacy alias
     *
     * @param string $name
     * @return bool
     */
    public static function is_legacy_alias(string $name): bool {
        return isset(self::LEGACY_ALIASES[$name]);
    }

    /**
     * Get the legacy name for a canonical tool (if exists)
     *
     * @param string $canonical_name
     * @return string|null Legacy name or null
     */
    public static function get_legacy_name(string $canonical_name): ?string {
        if (self::$reverse_aliases === null) {
            self::$reverse_aliases = array_flip(self::LEGACY_ALIASES);
        }
        return self::$reverse_aliases[$canonical_name] ?? null;
    }

    /**
     * Get tool count by profile
     *
     * @return array ['core' => int, 'advanced' => int, 'admin' => int]
     */
    public static function get_counts(): array {
        return [
            'core' => count(self::get_core_tools()),
            'advanced' => count(self::get_advanced_tools()),
            'admin' => count(self::get_admin_tools()),
            'total' => count(self::get_all_definitions()),
        ];
    }
}
