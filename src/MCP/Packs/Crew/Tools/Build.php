<?php
/**
 * ml_build - Tool Assembly Tool (Pack CREW)
 *
 * Creates tools by assembling existing components (prompt + content(s) + optional style)
 * with CREW workflow: retrieval → selection → compat → assembly → (optional) creation.
 *
 * Modes:
 * - suggest: proposes blueprint + candidates (default if auto_create=false)
 * - dry-run: simulates creation, returns exact payload for ml_save
 * - apply: creates the tool (and missing components if auto_create=true)
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Packs\Crew\Tools;

use MCP_No_Headless\MCP\Core\Tool_Response;
use MCP_No_Headless\MCP\Core\Services\Compatibility_Service;
use MCP_No_Headless\MCP\Core\Services\Permission_Service;
use MCP_No_Headless\MCP\Core\Tools\Find;
use MCP_No_Headless\MCP\Core\Tools\Save;
use MCP_No_Headless\MCP\Packs\Crew\Services\QueryRewriteService;
use MCP_No_Headless\MCP\Packs\Crew\Services\RerankService;
use MCP_No_Headless\MCP\Packs\Crew\Services\BlueprintBuilder;

class Build {

    const TOOL_NAME = 'ml_build';
    const VERSION = '1.0.0';

    // Modes
    const MODE_SUGGEST = 'suggest';
    const MODE_DRY_RUN = 'dry-run';
    const MODE_APPLY = 'apply';

    // Assembly metadata keys
    const META_ASSEMBLED = '_ml_assembled';
    const META_ASSEMBLED_FROM = '_ml_assembled_from';
    const META_ASSEMBLY_CONTEXT = '_ml_assembly_context';
    const META_ASSEMBLY_MODE = '_ml_assembly_mode';
    const META_ASSEMBLY_CREATED_BY = '_ml_assembly_created_by';
    const META_ASSEMBLY_VERSION = '_ml_assembly_version';
    const META_COMPAT_SCORE = '_ml_compat_score';
    const META_COMPAT_EXPLAIN = '_ml_compat_explain';
    const META_PINNED = '_ml_pinned';

    // Defaults
    const DEFAULT_MAX_CANDIDATES = 10;
    const DEFAULT_TOP_K = 5;
    const DEFAULT_LANGUAGE = 'fr';
    const COMPAT_THRESHOLD = 0.4;

    /**
     * Get tool definition for Tool_Catalog
     *
     * @return array Tool definition
     */
    public static function get_definition(): array {
        return [
            'name' => self::TOOL_NAME,
            'strate' => 'pack',
            'pack' => 'crew',
            'description' => <<<'DESC'
■ UTILISER QUAND : L'utilisateur veut créer un nouvel outil en assemblant des composants existants (prompt, contenus, style).
■ NE PAS UTILISER SI : L'utilisateur veut juste exécuter un outil existant (→ ml_run).

Crée un "tool" par assemblage de composants avec workflow CREW:
1. Analyse du contexte et expansion de la requête
2. Recherche et sélection des candidats (prompt, contenus, style)
3. Scoring de compatibilité
4. Assemblage et création (optionnel)

Modes: suggest (défaut), dry-run, apply
DESC,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'context' => [
                        'type' => 'string',
                        'description' => 'Besoin en langage naturel (REQUIS)',
                    ],
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['suggest', 'dry-run', 'apply'],
                        'description' => 'Mode: suggest (propose), dry-run (simule), apply (crée)',
                    ],
                    'prompt_id' => [
                        'type' => 'integer',
                        'description' => 'ID du prompt si connu',
                    ],
                    'content_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'IDs des contenus si connus',
                    ],
                    'style_id' => [
                        'type' => 'integer',
                        'description' => 'ID du style si connu (optionnel)',
                    ],
                    'space_id' => [
                        'type' => 'integer',
                        'description' => 'Espace cible (défaut: espace personnel)',
                    ],
                    'auto_create' => [
                        'type' => 'boolean',
                        'description' => 'Créer composants manquants si autorisé (défaut: false)',
                    ],
                    'max_candidates' => [
                        'type' => 'integer',
                        'description' => 'Candidats retournés par type (défaut: 10)',
                    ],
                    'top_k' => [
                        'type' => 'integer',
                        'description' => 'Nombre retenu après rerank (défaut: 5)',
                    ],
                    'ai_rerank' => [
                        'type' => 'boolean',
                        'description' => 'Rerank IA sur top-K lexical (défaut: true)',
                    ],
                    'query_rewrite' => [
                        'type' => 'boolean',
                        'description' => 'Expansions + entités (défaut: true)',
                    ],
                    'pin_components' => [
                        'type' => 'boolean',
                        'description' => 'Snapshot texte dans l\'outil (défaut: false)',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Langue pour rewrite/rerank (défaut: fr)',
                    ],
                    'strict' => [
                        'type' => 'boolean',
                        'description' => 'Erreur si prompt/contenus obligatoires manquants (défaut: false)',
                    ],
                ],
                'required' => ['context'],
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
        ];
    }

    /**
     * Execute ml_build
     *
     * @param array $args Tool arguments
     * @param int $user_id Current user ID
     * @return array Tool response
     */
    public static function execute(array $args, int $user_id): array {
        $start_time = microtime(true);

        // 1. Parse and validate arguments
        $context = trim($args['context'] ?? '');
        if (empty($context)) {
            return Tool_Response::validation_error(
                'Le paramètre context est requis.',
                ['context' => 'Décrivez le besoin en langage naturel.']
            );
        }

        // Parse parameters with defaults
        $auto_create = (bool) ($args['auto_create'] ?? false);
        $mode = self::normalize_mode($args['mode'] ?? null, $auto_create);
        $prompt_id = isset($args['prompt_id']) ? (int) $args['prompt_id'] : null;
        $content_ids = isset($args['content_ids']) ? array_map('intval', (array) $args['content_ids']) : null;
        $style_id = isset($args['style_id']) ? (int) $args['style_id'] : null;
        $space_id = isset($args['space_id']) ? (int) $args['space_id'] : null;
        $max_candidates = (int) ($args['max_candidates'] ?? self::DEFAULT_MAX_CANDIDATES);
        $top_k = (int) ($args['top_k'] ?? self::DEFAULT_TOP_K);
        $ai_rerank = (bool) ($args['ai_rerank'] ?? true);
        $query_rewrite = (bool) ($args['query_rewrite'] ?? true);
        $pin_components = (bool) ($args['pin_components'] ?? false);
        $language = $args['language'] ?? self::DEFAULT_LANGUAGE;
        $strict = (bool) ($args['strict'] ?? false);

        $warnings = [];
        $created = [
            'tool' => false,
            'prompt' => ['created' => false, 'id' => null],
            'contents' => [],
            'style' => ['created' => false, 'id' => null],
        ];

        // 2. Determine target space
        $space_id = self::resolve_space_id($space_id, $user_id);

        // 3. Check write permission for apply mode
        $original_mode = $mode;
        if ($mode === self::MODE_APPLY || $auto_create) {
            if (!self::can_write_to_space($user_id, $space_id)) {
                $mode = self::MODE_SUGGEST;
                $warnings[] = [
                    'code' => 'insufficient_write_permission',
                    'message' => 'Permission d\'écriture insuffisante, basculement en mode suggest.',
                ];
            }
        }

        // 4. Query rewrite (expand context)
        $query_expanded = $context;
        $query_data = null;
        if ($query_rewrite) {
            $query_data = QueryRewriteService::rewrite($context, $language);
            $query_expanded = $query_data['expanded_query'] ?? $context;
        }

        // 5. Select/validate prompt
        $prompt_result = self::select_prompt(
            $prompt_id,
            $query_expanded,
            $max_candidates,
            $top_k,
            $ai_rerank,
            $user_id,
            $auto_create,
            $mode,
            $strict,
            $context
        );

        if (!$prompt_result['success']) {
            return $prompt_result['response'];
        }

        $selected_prompt = $prompt_result['selected'];
        $prompt_candidates = $prompt_result['candidates'];
        if ($prompt_result['created']) {
            $created['prompt'] = ['created' => true, 'id' => $selected_prompt['id']];
        } else {
            $created['prompt'] = ['created' => false, 'id' => $selected_prompt['id']];
        }
        if (!empty($prompt_result['warnings'])) {
            $warnings = array_merge($warnings, $prompt_result['warnings']);
        }

        // 6. Select/validate contents
        $content_result = self::select_contents(
            $content_ids,
            $query_expanded,
            $max_candidates,
            $top_k,
            $ai_rerank,
            $user_id,
            $strict
        );

        $selected_contents = $content_result['selected'];
        $content_candidates = $content_result['candidates'];
        if (!empty($content_result['warnings'])) {
            $warnings = array_merge($warnings, $content_result['warnings']);
        }

        // 7. Select/validate style (optional)
        $style_result = self::select_style(
            $style_id,
            $query_expanded,
            $max_candidates,
            $top_k,
            $ai_rerank,
            $user_id
        );

        $selected_style = $style_result['selected'];
        $style_candidates = $style_result['candidates'];
        if ($selected_style) {
            $created['style'] = ['created' => false, 'id' => $selected_style['id']];
        }

        // 8. Calculate compatibility score
        $compat_result = self::calculate_compatibility(
            $selected_prompt,
            $selected_contents,
            $selected_style
        );

        $compat_score = $compat_result['score'];
        $compat_explain = $compat_result['explain'] ?? null;

        // Check compat threshold
        if ($compat_score < self::COMPAT_THRESHOLD) {
            if ($strict && $mode === self::MODE_APPLY) {
                return Tool_Response::error(
                    'low_compatibility',
                    'Score de compatibilité trop bas pour créer l\'outil.',
                    [
                        'details' => [
                            'score' => $compat_score,
                            'threshold' => self::COMPAT_THRESHOLD,
                        ],
                        'suggestion' => 'Ajustez les composants ou désactivez strict mode.',
                    ]
                );
            }
            $warnings[] = [
                'code' => 'low_compatibility',
                'message' => "Score de compatibilité bas: {$compat_score}",
            ];
        }

        // 9. Build blueprint
        $blueprint = BlueprintBuilder::build(
            $selected_prompt,
            $selected_contents,
            $selected_style,
            $space_id,
            $compat_score
        );

        // 10. Handle mode-specific actions
        $tool = null;
        $next_action = null;

        if ($mode === self::MODE_APPLY) {
            // Create the tool
            $create_result = self::create_tool(
                $context,
                $blueprint,
                $selected_prompt,
                $selected_contents,
                $selected_style,
                $pin_components,
                $user_id,
                $compat_score,
                $compat_explain
            );

            if (!$create_result['success']) {
                return $create_result['response'];
            }

            $tool = $create_result['tool'];
            $created['tool'] = true;
        } else {
            // suggest or dry-run: prepare next_action
            $tool = [
                'id' => null,
                'title' => self::generate_tool_title($context),
                'url' => null,
            ];

            $save_payload = self::build_save_payload(
                $context,
                $blueprint,
                $selected_prompt,
                $selected_contents,
                $selected_style,
                $pin_components,
                $compat_score,
                $compat_explain
            );

            $next_action = [
                'tool' => 'ml_save',
                'params' => $save_payload,
            ];
        }

        // 11. Build response
        $latency_ms = round((microtime(true) - $start_time) * 1000);

        $response_data = [
            'mode' => $mode,
            'tool' => $tool,
            'blueprint' => $blueprint,
            'created' => $created,
            'candidates' => [
                'prompt' => $prompt_candidates,
                'contents' => $content_candidates,
                'style' => $style_candidates,
            ],
            'latency_ms' => $latency_ms,
        ];

        if (!empty($warnings)) {
            $response_data['warnings'] = $warnings;
        }

        if ($next_action) {
            $response_data['next_action'] = $next_action;
        }

        if ($query_data) {
            $response_data['query_rewrite'] = [
                'original' => $context,
                'expanded' => $query_expanded,
                'keywords' => $query_data['keywords'] ?? [],
                'entities' => $query_data['entities'] ?? [],
            ];
        }

        return Tool_Response::ok($response_data);
    }

    /**
     * Normalize mode based on auto_create flag
     */
    private static function normalize_mode(?string $mode, bool $auto_create): string {
        if ($mode !== null) {
            return in_array($mode, [self::MODE_SUGGEST, self::MODE_DRY_RUN, self::MODE_APPLY])
                ? $mode
                : self::MODE_SUGGEST;
        }

        return $auto_create ? self::MODE_APPLY : self::MODE_SUGGEST;
    }

    /**
     * Resolve target space ID
     */
    private static function resolve_space_id(?int $space_id, int $user_id): int {
        if ($space_id) {
            return $space_id;
        }

        // Get user's personal space
        $personal_space = get_user_meta($user_id, '_ml_personal_space_id', true);
        if ($personal_space) {
            return (int) $personal_space;
        }

        // Fallback: first space user is member of
        if (function_exists('groups_get_user_groups')) {
            $groups = groups_get_user_groups($user_id);
            if (!empty($groups['groups'])) {
                return (int) $groups['groups'][0];
            }
        }

        return 0; // No space - will be personal/draft
    }

    /**
     * Check if user can write to space
     */
    private static function can_write_to_space(int $user_id, int $space_id): bool {
        if (class_exists(Permission_Service::class)) {
            $result = Permission_Service::check(
                $user_id,
                Permission_Service::ACTION_CREATE,
                Permission_Service::RESOURCE_PUBLICATION,
                null,
                ['space_id' => $space_id]
            );
            return $result['allowed'] ?? false;
        }

        // Fallback: check if user can edit posts
        return user_can($user_id, 'edit_posts');
    }

    /**
     * Select prompt - either validate provided ID or search for candidates
     */
    private static function select_prompt(
        ?int $prompt_id,
        string $query,
        int $max_candidates,
        int $top_k,
        bool $ai_rerank,
        int $user_id,
        bool $auto_create,
        string $mode,
        bool $strict,
        string $context
    ): array {
        $candidates = [];
        $warnings = [];
        $created = false;

        // Case A: prompt_id provided
        if ($prompt_id !== null) {
            $prompt = self::get_publication_by_id($prompt_id, 'prompt', $user_id);
            if (!$prompt) {
                return [
                    'success' => false,
                    'response' => Tool_Response::error(
                        'invalid_prompt_id',
                        "Prompt ID {$prompt_id} invalide ou inaccessible.",
                        ['suggestion' => 'Vérifiez l\'ID ou omettez-le pour une recherche automatique.']
                    ),
                ];
            }

            return [
                'success' => true,
                'selected' => $prompt,
                'candidates' => [$prompt],
                'created' => false,
                'warnings' => [],
            ];
        }

        // Case B: search for prompts
        $search_result = Find::execute([
            'query' => $query,
            'type' => 'prompt',
            'limit' => $max_candidates,
            'include' => ['content', 'metadata'],
        ], $user_id);

        if ($search_result['success'] && !empty($search_result['data']['items'])) {
            $candidates = $search_result['data']['items'];

            // AI Rerank if enabled
            if ($ai_rerank && count($candidates) > 1) {
                $candidates = RerankService::rerank($candidates, $query, $top_k);
            } else {
                $candidates = array_slice($candidates, 0, $top_k);
            }

            // Format candidates with scores
            $candidates = array_map(function ($item, $index) {
                return [
                    'id' => $item['id'],
                    'title' => $item['name'] ?? $item['title'] ?? '',
                    'score' => $item['_rerank_score'] ?? (1 - ($index * 0.1)),
                ];
            }, $candidates, array_keys($candidates));

            // Select top candidate
            $selected = self::get_publication_by_id($candidates[0]['id'], 'prompt', $user_id);
            if ($selected) {
                return [
                    'success' => true,
                    'selected' => $selected,
                    'candidates' => $candidates,
                    'created' => false,
                    'warnings' => [],
                ];
            }
        }

        // No prompt found
        if ($auto_create && $mode === self::MODE_APPLY) {
            // Create minimal prompt
            $create_result = self::create_minimal_prompt($context, $user_id);
            if ($create_result['success']) {
                $warnings[] = [
                    'code' => 'prompt_auto_created',
                    'message' => 'Prompt minimal créé automatiquement.',
                ];
                return [
                    'success' => true,
                    'selected' => $create_result['prompt'],
                    'candidates' => [['id' => $create_result['prompt']['id'], 'title' => $create_result['prompt']['title'], 'score' => 1.0]],
                    'created' => true,
                    'warnings' => $warnings,
                ];
            }
        }

        if ($strict) {
            return [
                'success' => false,
                'response' => Tool_Response::error(
                    'prompt_missing',
                    'Aucun prompt pertinent trouvé.',
                    [
                        'details' => ['query' => $query],
                        'suggestion' => 'Créez un prompt (auto_create=true) ou fournissez prompt_id.',
                        'fallback_used' => false,
                    ]
                ),
            ];
        }

        // Non-strict: return error but with candidates for suggestion
        return [
            'success' => false,
            'response' => Tool_Response::error(
                'prompt_missing',
                'Aucun prompt pertinent trouvé.',
                [
                    'details' => ['query' => $query],
                    'suggestion' => 'Créez un prompt (auto_create=true) ou fournissez prompt_id.',
                ]
            ),
        ];
    }

    /**
     * Select contents - either validate provided IDs or search for candidates
     */
    private static function select_contents(
        ?array $content_ids,
        string $query,
        int $max_candidates,
        int $top_k,
        bool $ai_rerank,
        int $user_id,
        bool $strict
    ): array {
        $candidates = [];
        $selected = [];
        $warnings = [];

        // Case A: content_ids provided
        if ($content_ids !== null && !empty($content_ids)) {
            foreach ($content_ids as $content_id) {
                $content = self::get_publication_by_id($content_id, 'content', $user_id);
                if ($content) {
                    $selected[] = $content;
                } else {
                    $warnings[] = [
                        'code' => 'content_inaccessible',
                        'message' => "Contenu ID {$content_id} inaccessible, ignoré.",
                    ];
                }
            }

            if (empty($selected) && $strict) {
                return [
                    'selected' => [],
                    'candidates' => [],
                    'warnings' => [[
                        'code' => 'content_missing',
                        'message' => 'Aucun contenu accessible parmi les IDs fournis.',
                    ]],
                ];
            }

            $candidates = array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'title' => $item['title'] ?? '',
                    'score' => 1.0,
                ];
            }, $selected);

            return [
                'selected' => $selected,
                'candidates' => $candidates,
                'warnings' => $warnings,
            ];
        }

        // Case B: search for contents
        $search_result = Find::execute([
            'query' => $query,
            'type' => 'content',
            'limit' => $max_candidates,
            'include' => ['content', 'metadata'],
        ], $user_id);

        if ($search_result['success'] && !empty($search_result['data']['items'])) {
            $candidates = $search_result['data']['items'];

            // AI Rerank if enabled
            if ($ai_rerank && count($candidates) > 1) {
                $candidates = RerankService::rerank($candidates, $query, $top_k);
            } else {
                $candidates = array_slice($candidates, 0, $top_k);
            }

            // Format candidates
            $formatted_candidates = array_map(function ($item, $index) {
                return [
                    'id' => $item['id'],
                    'title' => $item['name'] ?? $item['title'] ?? '',
                    'score' => $item['_rerank_score'] ?? (1 - ($index * 0.1)),
                ];
            }, $candidates, array_keys($candidates));

            // Get full content for selected items
            foreach (array_slice($candidates, 0, $top_k) as $item) {
                $content = self::get_publication_by_id($item['id'], 'content', $user_id);
                if ($content) {
                    $selected[] = $content;
                }
            }

            return [
                'selected' => $selected,
                'candidates' => $formatted_candidates,
                'warnings' => $warnings,
            ];
        }

        // No content found
        if ($strict) {
            return [
                'selected' => [],
                'candidates' => [],
                'warnings' => [[
                    'code' => 'content_missing',
                    'message' => 'Aucun contenu référentiel trouvé.',
                ]],
            ];
        }

        $warnings[] = [
            'code' => 'no_reference_content',
            'message' => 'Aucun contenu référentiel sélectionné.',
        ];

        return [
            'selected' => [],
            'candidates' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * Select style - either validate provided ID or search for candidates
     */
    private static function select_style(
        ?int $style_id,
        string $query,
        int $max_candidates,
        int $top_k,
        bool $ai_rerank,
        int $user_id
    ): array {
        $candidates = [];

        // Case A: style_id provided
        if ($style_id !== null) {
            $style = self::get_publication_by_id($style_id, 'style', $user_id);
            if ($style) {
                return [
                    'selected' => $style,
                    'candidates' => [['id' => $style['id'], 'title' => $style['title'] ?? '', 'score' => 1.0]],
                ];
            }
        }

        // Case B: search for styles
        $search_result = Find::execute([
            'query' => $query,
            'type' => 'style',
            'limit' => $max_candidates,
            'include' => ['content', 'metadata'],
        ], $user_id);

        if ($search_result['success'] && !empty($search_result['data']['items'])) {
            $candidates = $search_result['data']['items'];

            // AI Rerank if enabled
            if ($ai_rerank && count($candidates) > 1) {
                $candidates = RerankService::rerank($candidates, $query, $top_k);
            } else {
                $candidates = array_slice($candidates, 0, $top_k);
            }

            // Format candidates
            $formatted_candidates = array_map(function ($item, $index) {
                return [
                    'id' => $item['id'],
                    'title' => $item['name'] ?? $item['title'] ?? '',
                    'score' => $item['_rerank_score'] ?? (1 - ($index * 0.1)),
                ];
            }, $candidates, array_keys($candidates));

            // Select top candidate
            if (!empty($candidates)) {
                $selected = self::get_publication_by_id($candidates[0]['id'], 'style', $user_id);
                if ($selected) {
                    return [
                        'selected' => $selected,
                        'candidates' => $formatted_candidates,
                    ];
                }
            }

            return [
                'selected' => null,
                'candidates' => $formatted_candidates,
            ];
        }

        // No style found - that's OK, style is optional
        return [
            'selected' => null,
            'candidates' => [],
        ];
    }

    /**
     * Get publication by ID with label validation
     */
    private static function get_publication_by_id(int $id, string $expected_label, int $user_id): ?array {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'publication') {
            return null;
        }

        // Verify label
        $label_map = [
            'prompt' => 'prompt',
            'content' => 'data',
            'style' => 'style',
            'tool' => 'tool',
        ];
        $label_slug = $label_map[$expected_label] ?? $expected_label;

        if (!has_term($label_slug, 'publication_label', $post->ID)) {
            // Also check 'content' -> 'data' alias
            if ($expected_label === 'content' && !has_term('content', 'publication_label', $post->ID)) {
                return null;
            }
        }

        // Check access permission
        if (class_exists(Permission_Service::class)) {
            $result = Permission_Service::check(
                $user_id,
                Permission_Service::ACTION_READ,
                Permission_Service::RESOURCE_PUBLICATION,
                $post->ID
            );
            if (!$result['allowed']) {
                return null;
            }
        }

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => wp_trim_words($post->post_content, 30),
            'author_id' => (int) $post->post_author,
            'label' => $expected_label,
            'prompt_text' => get_post_meta($post->ID, '_ml_tool_prompt', true) ?: $post->post_content,
        ];
    }

    /**
     * Create minimal prompt when auto_create is enabled
     */
    private static function create_minimal_prompt(string $context, int $user_id): array {
        $title = 'Outil - ' . wp_trim_words($context, 6, '...');

        $prompt_content = <<<PROMPT
Tu es un assistant spécialisé.

## Objectif
{$context}

## Instructions
1. Analyse le contexte fourni
2. Produis une réponse structurée et professionnelle
3. Adapte le ton au contexte

## Entrée
{{input}}

## Format de sortie
Réponds de manière claire et structurée.
PROMPT;

        $post_data = [
            'post_title' => sanitize_text_field($title),
            'post_content' => $prompt_content,
            'post_status' => 'publish',
            'post_type' => 'publication',
            'post_author' => $user_id,
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return ['success' => false, 'error' => $post_id->get_error_message()];
        }

        // Set label to 'prompt'
        wp_set_object_terms($post_id, 'prompt', 'publication_label');

        // Set meta
        update_post_meta($post_id, '_ml_tool_prompt', $prompt_content);
        update_post_meta($post_id, '_ml_auto_created', true);
        update_post_meta($post_id, '_ml_auto_created_context', $context);

        return [
            'success' => true,
            'prompt' => [
                'id' => $post_id,
                'title' => $title,
                'content' => $prompt_content,
                'excerpt' => wp_trim_words($prompt_content, 30),
                'author_id' => $user_id,
                'label' => 'prompt',
                'prompt_text' => $prompt_content,
            ],
        ];
    }

    /**
     * Calculate compatibility score between components
     */
    private static function calculate_compatibility(
        array $prompt,
        array $contents,
        ?array $style
    ): array {
        if (!class_exists(Compatibility_Service::class)) {
            return ['score' => 0.75, 'explain' => 'Compatibility service not available'];
        }

        $components = [
            'prompt' => $prompt['prompt_text'] ?? $prompt['content'] ?? '',
            'style' => $style ? ($style['content'] ?? 'formal') : 'formal',
            'model' => 'gpt-4o-mini',
            'task' => 'simple_qa',
            'content_type' => 'text',
            'output_format' => 'text',
        ];

        $result = Compatibility_Service::calculate($components);

        return [
            'score' => $result['score'] ?? 0.75,
            'explain' => !empty($result['issues']) ? implode('; ', $result['issues']) : null,
        ];
    }

    /**
     * Generate tool title from context
     */
    private static function generate_tool_title(string $context): string {
        // Simple heuristic: first 8 words capitalized
        $words = preg_split('/\s+/', trim($context));
        $title_words = array_slice($words, 0, 8);
        $title = implode(' ', $title_words);

        if (count($words) > 8) {
            $title .= '...';
        }

        return ucfirst($title);
    }

    /**
     * Build save payload for dry-run/suggest modes
     */
    private static function build_save_payload(
        string $context,
        array $blueprint,
        array $prompt,
        array $contents,
        ?array $style,
        bool $pin_components,
        float $compat_score,
        ?string $compat_explain
    ): array {
        $title = self::generate_tool_title($context);

        // Build content
        $content = '';
        if ($pin_components) {
            $content = self::build_pinned_content($prompt, $contents, $style);
        } else {
            $content = "Outil assemblé à partir du contexte: {$context}";
        }

        $meta = [
            self::META_ASSEMBLED => true,
            self::META_ASSEMBLED_FROM => wp_json_encode([
                'prompt_id' => $blueprint['prompt_id'],
                'content_ids' => $blueprint['content_ids'],
                'style_id' => $blueprint['style_id'],
            ]),
            self::META_ASSEMBLY_CONTEXT => $context,
            self::META_ASSEMBLY_VERSION => 'crew-1.0',
            self::META_COMPAT_SCORE => $compat_score,
            self::META_PINNED => $pin_components,
        ];

        if ($compat_explain) {
            $meta[self::META_COMPAT_EXPLAIN] = $compat_explain;
        }

        return [
            'type' => 'tool',
            'title' => $title,
            'content' => $content,
            'space_id' => $blueprint['space_id'],
            'status' => 'draft',
            'labels' => ['tool'],
            'meta' => $meta,
        ];
    }

    /**
     * Build pinned content snapshot
     */
    private static function build_pinned_content(array $prompt, array $contents, ?array $style): string {
        $sections = [];

        // Prompt section
        $sections[] = "## Instruction (Prompt)\n\n" . ($prompt['prompt_text'] ?? $prompt['content'] ?? '');

        // Contents section
        if (!empty($contents)) {
            $sections[] = "## Contenus de référence";
            foreach ($contents as $index => $content) {
                $num = $index + 1;
                $sections[] = "### Référence {$num}: {$content['title']}\n\n" . ($content['content'] ?? $content['excerpt'] ?? '');
            }
        }

        // Style section
        if ($style) {
            $sections[] = "## Style\n\n" . ($style['content'] ?? '');
        }

        return implode("\n\n---\n\n", $sections);
    }

    /**
     * Create the tool (apply mode)
     */
    private static function create_tool(
        string $context,
        array $blueprint,
        array $prompt,
        array $contents,
        ?array $style,
        bool $pin_components,
        int $user_id,
        float $compat_score,
        ?string $compat_explain
    ): array {
        $title = self::generate_tool_title($context);

        // Build content
        $content = '';
        if ($pin_components) {
            $content = self::build_pinned_content($prompt, $contents, $style);
        } else {
            $content = "Outil assemblé à partir du contexte: {$context}";
        }

        // Create publication
        $post_data = [
            'post_title' => sanitize_text_field($title),
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'publication',
            'post_author' => $user_id,
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return [
                'success' => false,
                'response' => Tool_Response::internal_error(
                    'Échec de création de l\'outil: ' . $post_id->get_error_message()
                ),
            ];
        }

        // Set label to 'tool'
        wp_set_object_terms($post_id, 'tool', 'publication_label');

        // Set space if provided
        if ($blueprint['space_id']) {
            update_post_meta($post_id, '_ml_space_id', $blueprint['space_id']);
        }

        // Set assembly metadata
        update_post_meta($post_id, self::META_ASSEMBLED, true);
        update_post_meta($post_id, self::META_ASSEMBLED_FROM, wp_json_encode([
            'prompt_id' => $blueprint['prompt_id'],
            'content_ids' => $blueprint['content_ids'],
            'style_id' => $blueprint['style_id'],
        ]));
        update_post_meta($post_id, self::META_ASSEMBLY_CONTEXT, $context);
        update_post_meta($post_id, self::META_ASSEMBLY_MODE, self::MODE_APPLY);
        update_post_meta($post_id, self::META_ASSEMBLY_CREATED_BY, $user_id);
        update_post_meta($post_id, self::META_ASSEMBLY_VERSION, 'crew-1.0');
        update_post_meta($post_id, self::META_COMPAT_SCORE, $compat_score);
        update_post_meta($post_id, self::META_PINNED, $pin_components);

        if ($compat_explain) {
            update_post_meta($post_id, self::META_COMPAT_EXPLAIN, $compat_explain);
        }

        // Copy prompt configuration to tool
        update_post_meta($post_id, '_ml_tool_prompt', $prompt['prompt_text'] ?? $prompt['content']);
        update_post_meta($post_id, '_ml_tool_model', 'gpt-4o-mini');
        update_post_meta($post_id, '_ml_tool_requires_input', true);

        return [
            'success' => true,
            'tool' => [
                'id' => $post_id,
                'title' => $title,
                'url' => get_permalink($post_id),
            ],
        ];
    }
}
