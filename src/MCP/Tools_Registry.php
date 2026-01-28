<?php
/**
 * Tools Registry - Registers MCP tools with AI Engine Pro
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\MCP\Core\Tool_Catalog_V3;
use MCP_No_Headless\MCP\Core\Router_V3;

class Tools_Registry {

    /**
     * Picasso tools handler
     *
     * @var Picasso_Tools
     */
    private Picasso_Tools $picasso_tools;

    /**
     * Search/Fetch tools handler
     *
     * @var Search_Fetch_Tools
     */
    private Search_Fetch_Tools $search_fetch_tools;

    /**
     * Constructor - Register hooks
     */
    public function __construct() {
        $this->picasso_tools = new Picasso_Tools();
        $this->search_fetch_tools = new Search_Fetch_Tools();

        // Register tools with AI Engine Pro MCP
        add_filter('mwai_mcp_tools', [$this, 'register_tools'], 10, 1);
        add_filter('mwai_mcp_callback', [$this, 'handle_callback'], 10, 4);
    }

    /**
     * Register all MCP tools
     *
     * @param array $tools Existing tools
     * @return array Modified tools array
     */
    public function register_tools(array $tools): array {
        // V3 ONLY: Clear all previous tools and use only 6 Core tools
        $tools = [];
        
        $v3_tools = Tool_Catalog_V3::build();
        foreach ($v3_tools as $tool) {
            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * Get user scopes based on capabilities
     *
     * @param int $user_id
     * @return array
     */
    private function get_user_scopes(int $user_id): array {
        $scopes = ['read:content'];

        if (user_can($user_id, 'edit_posts')) {
            $scopes[] = 'write:content';
        }

        if (user_can($user_id, 'manage_options')) {
            $scopes[] = 'admin';
        }

        return $scopes;
    }

    /**
     * Handle MCP tool callbacks
     *
     * @param mixed $existing Existing result (null if not handled)
     * @param string $tool Tool name
     * @param array $args Tool arguments
     * @param int $id Request ID
     * @return array|null Response or null to pass through
     */
    public function handle_callback($existing, string $tool, array $args, int $id) {
        // If already handled, pass through
        if ($existing !== null) {
            return $existing;
        }

        // ALIAS: Map new tool names to legacy names that work
        $tool_aliases = [
            "ml_spaces_list" => "ml_list_spaces",
            "ml_publications_list" => "ml_list_publications",
        ];
        if (isset($tool_aliases[$tool])) {
            $tool = $tool_aliases[$tool];
        }

        $user_id = get_current_user_id();
        // MCP Fallback: if user_id is 0 or has no admin role, use first admin
        if ($user_id === 0) {
            $admins = get_users(["role" => "administrator", "orderby" => "ID", "order" => "ASC", "number" => 1]);
            if (!empty($admins)) {
                $user_id = $admins[0]->ID;
                wp_set_current_user($user_id);
            }
        }

        // V3 Core tools - route to Router_V3
        $v3_tools = ['ml_ping', 'ml_find', 'ml_me', 'ml_save', 'ml_run', 'ml_assist'];
        if (in_array($tool, $v3_tools)) {
            try {
                $result = Router_V3::route($tool, $args, $user_id);
                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Handle MCP prompts methods (prompts/list, prompts/get)
        if (in_array($tool, ['prompts/list', 'prompts/get'])) {
            return $this->handle_prompts_method($tool, $args, $user_id, $id);
        }

        // Handle MCP completion/complete method
        if ($tool === 'completion/complete') {
            return $this->handle_completion_method($args, $user_id, $id);
        }

        // Handle OpenAI Connectors tools (search, fetch)
        if (in_array($tool, ['search', 'fetch'])) {
            try {
                $result = $this->search_fetch_tools->execute($tool, $args, $user_id);
                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Handle Apply Tool (prepare/commit flow)

        // Handle Feedback tool (v2.2.4+)
        if ($tool === 'ml_feedback') {
            try {
                $run_id = $args['run_id'] ?? 'mcp_' . uniqid();
                $tool_id = (int) ($args['tool_id'] ?? $args['publication_id'] ?? 0);
                $thumbs = $args['rating'] ?? 'up';
                $comment = $args['comment'] ?? null;

                $success = \MCP_No_Headless\Services\Feedback_Service::record(
                    $run_id,
                    $user_id,
                    $tool_id,
                    $thumbs,
                    $comment
                );

                return $this->success_response($id, [
                    'ok' => $success,
                    'run_id' => $run_id,
                    'message' => $success ? 'Feedback recorded' : 'Failed to record'
                ]);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }


                // Handle ml_get_taxonomies - Returns available tags, labels, steps, types
        if ($tool === 'ml_get_taxonomies') {
            $taxonomies = [
                'tags' => [
                    'description' => 'Tags for categorizing publications by action or function',
                    'values' => [
                        'actions' => [
                            'act-adapt' => 'Adaptation, traduction, correction',
                            'act-analyze' => 'Analyse, critique, SWOT',
                            'act-imagine' => 'Imagination, créativité',
                            'act-redact' => 'Rédaction, création de contenu',
                            'act-respond' => 'Réponse, dialogue',
                            'act-synthetize' => 'Synthèse, résumé',
                            'act-prompting' => 'Astuces et techniques IA',
                        ],
                        'functions' => [
                            'fct-communication' => 'Communication',
                            'fct-finance' => 'Finance',
                            'fct-learning' => 'Formation',
                            'fct-general' => 'Général',
                            'fct-legal' => 'Juridique',
                            'fct-marketing' => 'Marketing',
                            'fct-operations' => 'Opérations',
                            'fct-project' => 'Projet',
                            'fct-hr' => 'Ressources Humaines',
                            'fct-clientsupport' => 'Service Client',
                            'fct-is' => 'Systèmes d\'Information',
                            'fct-tech' => 'Technique',
                            'fct-sales' => 'Vente',
                            'fct-innovation' => 'Innovation',
                        ],
                        'appels_offres' => [
                            'ao-cahier-des-charges' => 'Cahier des charges',
                            'ao-qualification' => 'Qualification',
                            'ao-reponse' => 'Réponse',
                            'ao-ressources' => 'Ressources',
                        ],
                        'other' => [
                            'tuto' => 'Tutoriel',
                        ],
                    ],
                ],
                'labels' => [
                    'description' => 'Labels for publication type (CREW taxonomy)',
                    'values' => [
                        'data' => 'Contenu de référence',
                        'tool' => 'Outil (prompt + contenus + styles)',
                        'prompt' => 'Instructions pour l\'IA',
                        'style' => 'Style d\'écriture',
                        'doc' => 'Documentation',
                        'video' => 'Vidéo',
                        'follow-up' => 'Suivi',
                        'idee' => 'Idée',
                        'info' => 'Information',
                        'question' => 'Question',
                    ],
                ],
                'steps' => [
                    'description' => 'Workflow steps for publication lifecycle',
                    'values' => [
                        'draft' => 'Brouillon',
                        'submit' => 'Soumis/Publié',
                        'review' => 'En revue',
                        'approved' => 'Approuvé',
                        'published' => 'Publié',
                        'archived' => 'Archivé',
                    ],
                ],
                'types' => [
                    'description' => 'Publication types',
                    'values' => [
                        'prompt' => 'Prompt/Instructions',
                        'data' => 'Données/Contenu',
                        'style' => 'Style',
                        'template' => 'Template',
                        'tool' => 'Outil',
                        'guide' => 'Guide',
                    ],
                ],
            ];
        
            return $this->success_response($id, $taxonomies);
        }




                // Handle Publication CRUD (v2.2.11+ with metadata support)
        if ($tool === 'ml_publication_create') {
            try {
                $title = $args['title'] ?? '';
                $content_text = $args['content'] ?? '';
                $space_id = (int) ($args['space_id'] ?? 0);
                $type = $args['type'] ?? 'data';
                $excerpt = $args['excerpt'] ?? '';
                $step = $args['step'] ?? 'draft';
                $tags = $args['tags'] ?? [];
                $labels = $args['labels'] ?? [];
                // Decode JSON strings if needed (MCP client may send as string)
                if (is_string($tags)) $tags = json_decode($tags, true) ?? [];
                if (is_string($labels)) $labels = json_decode($labels, true) ?? [];

                if (empty($title) || empty($content_text) || !$space_id) {
                    return $this->error_response($id, -32602, 'Required: title, content, space_id');
                }

                // Check permission
                $checker = new Permission_Checker($user_id);
                if (!$checker->can_create_publication($space_id)) {
                    return $this->error_response($id, -32603, 'No permission to create in this space');
                }

                $post_id = wp_insert_post([
                    'post_title' => sanitize_text_field($title),
                    'post_content' => wp_kses_post($content_text),
                    'post_excerpt' => sanitize_text_field($excerpt),
                    'post_type' => 'publication',
                    'post_status' => 'publish',
                    'post_author' => $user_id,
                    'post_parent' => $space_id,
                ]);

                if (is_wp_error($post_id)) {
                    return $this->error_response($id, -32603, $post_id->get_error_message());
                }

                // Set metadata
                update_post_meta($post_id, '_publication_space', $space_id);
                update_post_meta($post_id, '_ml_publication_type', $type);
                update_post_meta($post_id, '_publication_step', sanitize_text_field($step));

                // Set labels if provided (use taxonomy for consistency with GET)
                if (!empty($labels) && is_array($labels)) {
                    if (taxonomy_exists('publication_label')) {
                        wp_set_object_terms($post_id, $labels, 'publication_label');
                    } else {
                        update_post_meta($post_id, '_publication_labels', array_map('sanitize_text_field', $labels));
                    }
                }

                // Set tags if provided
                if (!empty($tags) && is_array($tags)) {
                    if (taxonomy_exists('publication_tag')) {
                        wp_set_object_terms($post_id, $tags, 'publication_tag');
                    } else {
                        update_post_meta($post_id, '_publication_tags', array_map('sanitize_text_field', $tags));
                    }
                }

                return $this->success_response($id, [
                    'ok' => true,
                    'publication_id' => $post_id,
                    'space_id' => $space_id,
                    'step' => $step,
                    'type' => $type,
                    'message' => 'Publication created',
                    'url' => get_permalink($post_id)
                ]);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        if ($tool === 'ml_publication_update') {
            try {
                $pub_id = (int) ($args['publication_id'] ?? 0);
                if (!$pub_id) {
                    return $this->error_response($id, -32602, 'publication_id required');
                }

                $checker = new Permission_Checker($user_id);
                if (!$checker->can_edit_publication($pub_id)) {
                    return $this->error_response($id, -32603, 'No permission to edit this publication');
                }

                $update_data = ['ID' => $pub_id];
                if (isset($args['title'])) $update_data['post_title'] = sanitize_text_field($args['title']);
                if (isset($args['content'])) $update_data['post_content'] = wp_kses_post($args['content']);
                if (isset($args['excerpt'])) $update_data['post_excerpt'] = sanitize_text_field($args['excerpt']);

                // Update space_id
                if (isset($args['space_id'])) {
                    $new_space_id = (int) $args['space_id'];
                    if ($new_space_id > 0) {
                        $update_data['post_parent'] = $new_space_id;
                        update_post_meta($pub_id, '_publication_space', $new_space_id);
                    }
                }

                $result = wp_update_post($update_data);
                if (is_wp_error($result)) {
                    return $this->error_response($id, -32603, $result->get_error_message());
                }

                // Update metadata
                if (isset($args['type'])) {
                    update_post_meta($pub_id, '_ml_publication_type', sanitize_text_field($args['type']));
                }

                if (isset($args['step'])) {
                    update_post_meta($pub_id, '_publication_step', sanitize_text_field($args['step']));
                }

                // Decode JSON string if needed (MCP client may send as string)
                $labels_data = $args['labels'] ?? null;
                if (is_string($labels_data)) {
                    $labels_data = json_decode($labels_data, true);
                }
                if (!empty($labels_data) && is_array($labels_data)) {
                    if (taxonomy_exists('publication_label')) {
                        wp_set_object_terms($pub_id, $labels_data, 'publication_label');
                    } else {
                        update_post_meta($pub_id, '_publication_labels', array_map('sanitize_text_field', $labels_data));
                    }
                }

                // Decode JSON string if needed (MCP client may send as string)
                $tags_data = $args['tags'] ?? null;
                if (is_string($tags_data)) {
                    $tags_data = json_decode($tags_data, true);
                }
                if (!empty($tags_data) && is_array($tags_data)) {
                    if (taxonomy_exists('publication_tag')) {
                        wp_set_object_terms($pub_id, $tags_data, 'publication_tag');
                    } else {
                        update_post_meta($pub_id, '_publication_tags', array_map('sanitize_text_field', $tags_data));
                    }
                }

                return $this->success_response($id, [
                    'ok' => true,
                    'publication_id' => $pub_id,
                    'message' => 'Publication updated'
                ]);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        if ($tool === 'ml_publication_delete') {
            try {
                $pub_id = (int) ($args['publication_id'] ?? 0);
                if (!$pub_id) {
                    return $this->error_response($id, -32602, 'publication_id required');
                }

                $checker = new Permission_Checker($user_id);
                if (!$checker->can_delete_publication($pub_id)) {
                    return $this->error_response($id, -32603, 'No permission to delete this publication');
                }

                $result = wp_trash_post($pub_id);
                if (!$result) {
                    return $this->error_response($id, -32603, 'Failed to delete publication');
                }

                return $this->success_response($id, [
                    'ok' => true,
                    'publication_id' => $pub_id,
                    'message' => 'Publication moved to trash'
                ]);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }



        // Handle Help tool
        if ($tool === 'ml_help') {
            try {
                $help_tool = new Help_Tool($this->picasso_tools, $user_id);
                $result = $help_tool->execute($args);
                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        if ($tool === 'ml_apply_tool') {
            $start_time = microtime(true);
            try {
                $apply_tool = new Apply_Tool();
                $result = $apply_tool->execute($args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                // Determine stage from action
                $action = $args['action'] ?? '';
                $stage = str_contains($action, 'prepare') ? 'prepare' : (str_contains($action, 'commit') ? 'commit' : null);
                $audit_result = isset($result['error']) ? 'error' : 'success';

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    $audit_result,
                    $args,
                    $stage,
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && !isset($result['error'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    'error',
                    $args,
                    null,
                    $latency_ms,
                    'exception'
                );
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Handle BuddyBoss Group tools
        if (in_array($tool, ['ml_groups_search', 'ml_group_fetch', 'ml_group_members'])) {
            return $this->handle_buddyboss_group_tool($tool, $args, $user_id, $id);
        }

        // Handle BuddyBoss Activity tools (read and write)
        $activity_tools = [
            'ml_activity_list', 'ml_activity_fetch', 'ml_activity_comments',
            'ml_activity_post_prepare', 'ml_activity_post_commit',
            'ml_activity_comment_prepare', 'ml_activity_comment_commit'
        ];
        if (in_array($tool, $activity_tools)) {
            return $this->handle_buddyboss_activity_tool($tool, $args, $user_id, $id);
        }

        // Handle BuddyBoss Member tools
        if (in_array($tool, ['ml_members_search', 'ml_member_fetch'])) {
            return $this->handle_buddyboss_member_tool($tool, $args, $user_id, $id);
        }

        // Handle tool-map v1 tools (Epic 1-7)
        $toolmap_result = $this->handle_toolmap_callback($tool, $args, $user_id, $id);
        if ($toolmap_result !== null) {
            return $toolmap_result;
        }

        // Only handle ml_* tools
        if (strpos($tool, 'ml_') !== 0) {
            return null;
        }

        // Check permissions
        $permission_checker = new Permission_Checker($user_id);

        if (!$permission_checker->can_execute($tool, $args)) {
            return $this->error_response($id, -32603, __('Permission denied for this action', 'mcp-no-headless'));
        }

        // Rate limiting
        if (!$this->check_rate_limit($user_id)) {
            return $this->error_response($id, -32000, __('Rate limit exceeded. Please try again later.', 'mcp-no-headless'));
        }

        // Execute the tool
        try {
            $result = $this->picasso_tools->execute($tool, $args, $user_id);
            return $this->success_response($id, $result);
        } catch (\Exception $e) {
            return $this->error_response($id, -32603, $e->getMessage());
        }
    }

    /**
     * Get all MaryLink tool definitions
     *
     * @return array
     */
    public function get_tool_definitions(): array {
        return [
            // Read tools
            [
                'name' => 'ml_list_publications',
                'description' => 'Liste les publications avec filtres optionnels (espace, step, auteur, recherche)',
                'category' => 'MaryLink Publications',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'Filtrer par ID d\'espace'
                        ],
                        'step' => [
                            'type' => 'string',
                            'description' => 'Filtrer par nom de step'
                        ],
                        'author_id' => [
                            'type' => 'integer',
                            'description' => 'Filtrer par auteur'
                        ],
                        'search' => [
                            'type' => 'string',
                            'description' => 'Terme de recherche'
                        ],
'type' => [
    'type' => 'string',
    'description' => 'Filtrer par label/type (slug ou nom: contenu, prompt, style, outil, etc.)'
],
'tags' => [
    'type' => 'array',
    'items' => ['type' => 'string'],
    'description' => 'Filtrer par tags (slugs ou IDs)'
],
'sort' => [
    'type' => 'string',
    'enum' => ['newest', 'oldest', 'best_rated', 'worst_rated', 'most_rated', 'most_liked', 'most_commented', 'trending'],
    'description' => 'Tri des résultats'
],
'period' => [
    'type' => 'string',
    'enum' => ['7d', '30d', '90d', '1y', 'all'],
    'description' => 'Filtre temporel (défaut: all)'
],
'page' => [
    'type' => 'integer',
    'description' => 'Page (défaut: 1)'
],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Nombre max de résultats (défaut 10, max 50)'
                        ],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
            ],

            [
                'name' => 'ml_get_publication',
                'description' => 'Recupere les details complets d\'une publication',
                'category' => 'MaryLink Publications',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID de la publication'
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            [
                'name' => 'ml_list_spaces',
                'description' => 'Liste les espaces disponibles',
                'category' => 'MaryLink Spaces',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Nombre max de resultats'
                        ],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            [
                'name' => 'ml_get_space',
                'description' => 'Recupere les details d\'un espace',
                'category' => 'MaryLink Spaces',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'ID de l\'espace'
                        ],
                    ],
                    'required' => ['space_id'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            [
                'name' => 'ml_get_my_context',
                'description' => 'Recupere le contexte IA de l\'utilisateur (ce que l\'IA doit savoir sur lui)',
                'category' => 'MaryLink Context',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],

            // Write tools
            [
                'name' => 'ml_create_publication',
                'description' => 'Cree une nouvelle publication dans un espace',
                'category' => 'MaryLink Publications',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'description' => 'Titre de la publication'
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Contenu de la publication (Markdown supporte)'
                        ],
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'ID de l\'espace cible'
                        ],
                        'status' => [
                            'type' => 'string',
                            'enum' => ['draft', 'publish'],
                            'description' => 'Statut de la publication (defaut: draft)'
                        ],
                    ],
                    'required' => ['title', 'space_id'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
            ],

            [
                'name' => 'ml_create_publication_from_text',
                'description' => 'Cree une publication a partir d\'un texte (ideal pour importer du contenu externe)',
                'category' => 'MaryLink Publications',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'description' => 'Titre de la publication'
                        ],
                        'text' => [
                            'type' => 'string',
                            'description' => 'Texte brut ou Markdown a convertir en publication'
                        ],
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'ID de l\'espace cible'
                        ],
                        'source' => [
                            'type' => 'string',
                            'description' => 'Source du contenu (ex: Slack, Google Doc, etc.)'
                        ],
                    ],
                    'required' => ['title', 'text', 'space_id'],
                ],
                'annotations' => ['readOnlyHint' => false],
            ],

            [
                'name' => 'ml_edit_publication',
                'description' => 'Modifie une publication existante',
                'category' => 'MaryLink Publications',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID de la publication'
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Nouveau titre'
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Nouveau contenu'
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => ['destructiveHint' => true],
            ],

            [
                'name' => 'ml_append_to_publication',
                'description' => 'Ajoute du contenu a la fin d\'une publication existante',
                'category' => 'MaryLink Publications',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID de la publication'
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Contenu a ajouter'
                        ],
                        'separator' => [
                            'type' => 'string',
                            'description' => 'Separateur avant le nouveau contenu (defaut: \\n\\n---\\n\\n)'
                        ],
                    ],
                    'required' => ['publication_id', 'content'],
                ],
            ],

            [
                'name' => 'ml_add_comment',
                'description' => 'Ajoute un commentaire a une publication',
                'category' => 'MaryLink Comments',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID de la publication'
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Contenu du commentaire'
                        ],
                        'type' => [
                            'type' => 'string',
                            'enum' => ['public', 'private'],
                            'description' => 'Type de commentaire (defaut: public)'
                        ],
                        'parent_id' => [
                            'type' => 'integer',
                            'description' => 'ID du commentaire parent (pour les reponses)'
                        ],
                    ],
                    'required' => ['publication_id', 'content'],
                ],
            ],

            [
                'name' => 'ml_import_as_comment',
                'description' => 'Importe du contenu externe comme commentaire sur une publication',
                'category' => 'MaryLink Comments',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID de la publication'
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Contenu a importer'
                        ],
                        'source' => [
                            'type' => 'string',
                            'description' => 'Source du contenu (ex: Slack, Email)'
                        ],
                        'type' => [
                            'type' => 'string',
                            'enum' => ['public', 'private'],
                            'description' => 'Type de commentaire'
                        ],
                    ],
                    'required' => ['publication_id', 'content'],
                ],
            ],

            [
                'name' => 'ml_create_review',
                'description' => 'Cree une review pour une publication',
                'category' => 'MaryLink Reviews',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID de la publication'
                        ],
                        'rating' => [
                            'type' => 'integer',
                            'description' => 'Note de 1 a 5'
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Contenu de la review'
                        ],
                        'type' => [
                            'type' => 'string',
                            'enum' => ['user', 'expert'],
                            'description' => 'Type de review (defaut: user)'
                        ],
                        'criteria' => [
                            'type' => 'object',
                            'description' => 'Notes par critere (optionnel)'
                        ],
                    ],
                    'required' => ['publication_id', 'rating'],
                ],
            ],

            [
                'name' => 'ml_move_to_step',
                'description' => 'Deplace une publication vers un autre step du workflow',
                'category' => 'MaryLink Workflow',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID de la publication'
                        ],
                        'step_name' => [
                            'type' => 'string',
                            'description' => 'Nom du step cible'
                        ],
                    ],
                    'required' => ['publication_id', 'step_name'],
                ],
                'annotations' => ['destructiveHint' => true],
            ],
        ];
    }

    /**
     * Handle BuddyBoss Group tools
     */
    private function handle_buddyboss_group_tool(string $tool, array $args, int $user_id, int $id) {
        if (!\MCP_No_Headless\BuddyBoss\Group_Service::is_available()) {
            return $this->error_response($id, -32603, 'BuddyBoss Groups not available');
        }

        try {
            $service = new \MCP_No_Headless\BuddyBoss\Group_Service($user_id);

            switch ($tool) {
                case 'ml_groups_search':
                    $result = $service->search_groups(
                        $args['query'] ?? '',
                        $args['filters'] ?? [],
                        $args['limit'] ?? 10,
                        $args['page'] ?? 1
                    );
                    break;

                case 'ml_group_fetch':
                    $result = $service->get_group($args['group_id'] ?? 0);
                    break;

                case 'ml_group_members':
                    $result = $service->list_members(
                        $args['group_id'] ?? 0,
                        $args['page'] ?? 1,
                        $args['per_page'] ?? 10
                    );
                    break;

                default:
                    return $this->error_response($id, -32601, 'Unknown tool');
            }

            return $this->success_response($id, $result);
        } catch (\Exception $e) {
            return $this->error_response($id, -32603, $e->getMessage());
        }
    }

    /**
     * Handle BuddyBoss Activity tools (read and write)
     */
    private function handle_buddyboss_activity_tool(string $tool, array $args, int $user_id, int $id) {
        if (!\MCP_No_Headless\BuddyBoss\Activity_Service::is_available()) {
            return $this->error_response($id, -32603, 'BuddyBoss Activity not available');
        }

        $start_time = microtime(true);
        $is_write = str_contains($tool, '_prepare') || str_contains($tool, '_commit');

        try {
            $service = new \MCP_No_Headless\BuddyBoss\Activity_Service($user_id);

            switch ($tool) {
                // Read tools
                case 'ml_activity_list':
                    $result = $service->list_activity([
                        'group_id' => $args['group_id'] ?? null,
                        'scope' => $args['scope'] ?? 'all',
                        'page' => $args['page'] ?? 1,
                        'per_page' => $args['per_page'] ?? 10,
                        'search' => $args['search'] ?? '',
                    ]);
                    break;

                case 'ml_activity_fetch':
                    $result = $service->get_activity($args['activity_id'] ?? 0);
                    break;

                case 'ml_activity_comments':
                    $result = $service->list_comments(
                        $args['activity_id'] ?? 0,
                        $args['page'] ?? 1,
                        $args['per_page'] ?? 10
                    );
                    break;

                // Write tools (prepare/commit flow)
                case 'ml_activity_post_prepare':
                    $result = $service->prepare_post_activity(
                        $args['content'] ?? '',
                        $args['group_id'] ?? null
                    );
                    break;

                case 'ml_activity_post_commit':
                    $result = $service->commit_post_activity(
                        $args['idempotency_key'] ?? ''
                    );
                    break;

                case 'ml_activity_comment_prepare':
                    $result = $service->prepare_comment_activity(
                        $args['activity_id'] ?? 0,
                        $args['content'] ?? ''
                    );
                    break;

                case 'ml_activity_comment_commit':
                    $result = $service->commit_comment_activity(
                        $args['idempotency_key'] ?? ''
                    );
                    break;

                default:
                    return $this->error_response($id, -32601, 'Unknown tool');
            }

            $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

            // Audit log write operations
            if ($is_write) {
                $stage = str_contains($tool, '_prepare') ? 'prepare' : 'commit';
                $audit_result = isset($result['error']) ? 'error' : 'success';
                $error_code = $result['error'] ?? null;

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    $audit_result,
                    $args,
                    $stage,
                    $latency_ms,
                    $error_code
                );

                // Add debug_id to result for traceability
                if ($debug_id && !isset($result['error'])) {
                    $result['debug_id'] = $debug_id;
                }
            }

            return $this->success_response($id, $result);
        } catch (\Exception $e) {
            $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

            // Log exceptions for write tools
            if ($is_write) {
                $stage = str_contains($tool, '_prepare') ? 'prepare' : 'commit';
                \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    'error',
                    $args,
                    $stage,
                    $latency_ms,
                    'exception'
                );
            }

            return $this->error_response($id, -32603, $e->getMessage());
        }
    }

    /**
     * Handle BuddyBoss Member tools
     */
    private function handle_buddyboss_member_tool(string $tool, array $args, int $user_id, int $id) {
        if (!\MCP_No_Headless\BuddyBoss\Member_Service::is_available()) {
            return $this->error_response($id, -32603, 'BuddyBoss Members not available');
        }

        try {
            $service = new \MCP_No_Headless\BuddyBoss\Member_Service($user_id);

            switch ($tool) {
                case 'ml_members_search':
                    $result = $service->search_members(
                        $args['query'] ?? '',
                        $args['filters'] ?? [],
                        $args['limit'] ?? 10,
                        $args['page'] ?? 1
                    );
                    break;

                case 'ml_member_fetch':
                    $result = $service->get_member($args['user_id'] ?? 0);
                    break;

                default:
                    return $this->error_response($id, -32601, 'Unknown tool');
            }

            return $this->success_response($id, $result);
        } catch (\Exception $e) {
            return $this->error_response($id, -32603, $e->getMessage());
        }
    }

    /**
     * Get tool-map v1 tool definitions (Epics 1-7)
     *
     * @return array
     */
    private function get_toolmap_definitions(): array {
        $tools = [];

        // Epic 1: Spaces tools
        if (\MCP_No_Headless\Services\Space_Service::is_available()) {
            $tools = array_merge($tools, Spaces_Tools::get_definitions());
        }

        // Epic 2: Publications tools
        if (\MCP_No_Headless\Services\Publication_Service::is_available()) {
            $tools = array_merge($tools, Publications_Tools::get_definitions());
        }

        // Epic 3: Tool resolve/validate
        if (\MCP_No_Headless\Services\Tool_Service::is_available()) {
            $tools = array_merge($tools, Tool_Tools::get_definitions());
        }

        // Epic 4: Favorites
        if (\MCP_No_Headless\Services\Favorite_Service::is_available()) {
            $tools = array_merge($tools, Favorites_Tools::get_definitions());
        }

        // Epic 5: Comments
        if (\MCP_No_Headless\Services\Comment_Service::is_available()) {
            $tools = array_merge($tools, Comments_Tools::get_definitions());
        }

        // Epic 6: Best-of
        if (\MCP_No_Headless\Services\Bestof_Service::is_available()) {
            $tools = array_merge($tools, Bestof_Tools::get_definitions());
        }

        // Epic 7: Ratings
        if (\MCP_No_Headless\Services\Rating_Service::is_available()) {
            $tools = array_merge($tools, Ratings_Tools::get_definitions());
        }

        // T1.4: Subscriptions
        if (Subscription_Tools::is_available()) {
            $tools = array_merge($tools, Subscription_Tools::get_definitions());
        }

        // T2.1: Chain resolution
        if (Chain_Tools::is_available()) {
            $tools = array_merge($tools, Chain_Tools::get_definitions());
        }

        // T2.2: Duplication
        if (Duplicate_Tools::is_available()) {
            $tools = array_merge($tools, Duplicate_Tools::get_definitions());
        }

        // T3.1: Bulk operations
        if (Bulk_Tools::is_available()) {
            $tools = array_merge($tools, Bulk_Tools::get_definitions());
        }

        // T3.2: Comparison
        if (Compare_Tools::is_available()) {
            $tools = array_merge($tools, Compare_Tools::get_definitions());
        }

        // T4.1: Team management
        if (Team_Tools::is_available()) {
            $tools = array_merge($tools, Team_Tools::get_definitions());
        }

        // T4.2: Export bundle
        if (Export_Tools::is_available()) {
            $tools = array_merge($tools, Export_Tools::get_definitions());
        }

        // T5: Auto-Improve (Intelligence Layer)
        if (Auto_Improve_Tools::is_available()) {
            $tools = array_merge($tools, Auto_Improve_Tools::get_definitions());
        }

        // Recommendations (smart prompt selection)
        if (\MCP_No_Headless\Services\Recommendation_Service::is_available()) {
            $tools = array_merge($tools, Recommend_Tools::get_definitions());
        }

        // Context Bundle
        if (Context_Bundle_Tools::is_available()) {
            $tools = array_merge($tools, Context_Bundle_Tools::get_definitions());
        }

        // Dependency management tools
        if (Dependency_Tools::is_available()) {
            $tools = array_merge($tools, Dependency_Tools::get_definitions());
        }

        // Apply Tool V2 (prepare/commit flow)
        if (Apply_Tool_V2::is_available()) {
            $tools = array_merge($tools, Apply_Tool_V2::get_definitions());
        }

        // Assist Tool (1-call demo orchestrator)
        // Bootstrap Wizard Tool
        if (class_exists(Bootstrap_Wizard_Tool::class)) {
            $tools[] = Bootstrap_Wizard_Tool::get_definition();
        }

        if (Assist_Tool::is_available()) {
            $tools[] = Assist_Tool::get_definition();
        }

        return $tools;
    }

    /**
     * Handle tool-map v1 tool callbacks
     *
     * @param string $tool Tool name
     * @param array $args Tool arguments
     * @param int $user_id User ID
     * @param int $id Request ID
     * @return array|null Response or null if not handled
     */
    private function handle_toolmap_callback(string $tool, array $args, int $user_id, int $id) {
        // Epic 1: Spaces tools
        $spaces_tools = ['ml_spaces_list', 'ml_space_get', 'ml_space_steps_list', 'ml_space_permissions_summary'];
        if (in_array($tool, $spaces_tools)) {
            try {
                $result = Spaces_Tools::execute($tool, $args, $user_id);
                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Epic 2: Publications tools
        $publications_tools = ['ml_publications_list', 'ml_publication_get', 'ml_publication_dependencies'];
        if (in_array($tool, $publications_tools)) {
            try {
                $result = Publications_Tools::execute($tool, $args, $user_id);
                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Epic 3: Tool resolve/validate
        $tool_tools = ['ml_tool_resolve', 'ml_tool_validate'];
        if (in_array($tool, $tool_tools)) {
            try {
                $result = Tool_Tools::execute($tool, $args, $user_id);
                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Epic 4: Favorites
        $favorites_tools = ['ml_favorites_list', 'ml_favorites_set'];
        if (in_array($tool, $favorites_tools)) {
            try {
                $result = Favorites_Tools::execute($tool, $args, $user_id);
                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Epic 5: Comments
        $comments_tools = ['ml_comments_list', 'ml_comment_add'];
        if (in_array($tool, $comments_tools)) {
            try {
                $result = Comments_Tools::execute($tool, $args, $user_id);
                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Epic 6: Best-of
        $bestof_tools = ['ml_best_list'];
        if (in_array($tool, $bestof_tools)) {
            try {
                $result = Bestof_Tools::execute($tool, $args, $user_id);
                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Epic 7: Ratings + Workflow Steps (T1.1, T1.2, T1.3)
        $ratings_tools = ['ml_ratings_get', 'ml_rate_publication', 'ml_get_ratings_summary', 'ml_list_workflow_steps'];
        if (in_array($tool, $ratings_tools)) {
            $start_time = microtime(true);
            try {
                $result = Ratings_Tools::execute($tool, $args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                // Determine if write operation
                $is_write = ($tool === 'ml_rate_publication' && ($args['stage'] ?? 'prepare') === 'commit');
                $stage = $is_write ? 'commit' : 'read';

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    isset($result['ok']) && $result['ok'] ? 'success' : 'error',
                    $args,
                    $stage,
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && (!isset($result['ok']) || $result['ok'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                \MCP_No_Headless\Ops\Audit_Logger::log_tool($tool, $user_id, 'error', $args, 'read', $latency_ms, 'exception');
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // T1.4: Subscriptions
        $subscription_tools = ['ml_subscribe_space', 'ml_get_subscriptions'];
        if (in_array($tool, $subscription_tools)) {
            $start_time = microtime(true);
            try {
                $result = Subscription_Tools::execute($tool, $args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                // Determine if write operation
                $is_write = ($tool === 'ml_subscribe_space' && ($args['stage'] ?? 'prepare') === 'commit');
                $stage = $is_write ? 'commit' : 'read';

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    isset($result['ok']) && $result['ok'] ? 'success' : 'error',
                    $args,
                    $stage,
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && (!isset($result['ok']) || $result['ok'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                \MCP_No_Headless\Ops\Audit_Logger::log_tool($tool, $user_id, 'error', $args, 'read', $latency_ms, 'exception');
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // T2.1: Chain resolution
        $chain_tools = ['ml_get_publication_chain'];
        if (in_array($tool, $chain_tools)) {
            $start_time = microtime(true);
            try {
                $result = Chain_Tools::execute($tool, $args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    isset($result['ok']) && $result['ok'] ? 'success' : 'error',
                    $args,
                    'read',
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && (!isset($result['ok']) || $result['ok'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                \MCP_No_Headless\Ops\Audit_Logger::log_tool($tool, $user_id, 'error', $args, 'read', $latency_ms, 'exception');
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // T2.2: Duplication
        $duplicate_tools = ['ml_duplicate_publication'];
        if (in_array($tool, $duplicate_tools)) {
            $start_time = microtime(true);
            try {
                $result = Duplicate_Tools::execute($tool, $args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                // Determine if write operation
                $is_write = ($args['stage'] ?? 'prepare') === 'commit';
                $stage = $is_write ? 'commit' : 'prepare';

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    isset($result['ok']) && $result['ok'] ? 'success' : 'error',
                    $args,
                    $stage,
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && (!isset($result['ok']) || $result['ok'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                $stage = ($args['stage'] ?? 'prepare') === 'commit' ? 'commit' : 'prepare';
                \MCP_No_Headless\Ops\Audit_Logger::log_tool($tool, $user_id, 'error', $args, $stage, $latency_ms, 'exception');
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // T3.1: Bulk operations
        $bulk_tools = ['ml_bulk_apply_tool'];
        if (in_array($tool, $bulk_tools)) {
            $start_time = microtime(true);
            try {
                $result = Bulk_Tools::execute($tool, $args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                $is_write = ($args['stage'] ?? 'prepare') === 'commit';
                $stage = $is_write ? 'commit' : 'prepare';

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    isset($result['ok']) && $result['ok'] ? 'success' : 'error',
                    $args,
                    $stage,
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && (!isset($result['ok']) || $result['ok'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                $stage = ($args['stage'] ?? 'prepare') === 'commit' ? 'commit' : 'prepare';
                \MCP_No_Headless\Ops\Audit_Logger::log_tool($tool, $user_id, 'error', $args, $stage, $latency_ms, 'exception');
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // T3.2: Comparison
        $compare_tools = ['ml_compare_publications'];
        if (in_array($tool, $compare_tools)) {
            $start_time = microtime(true);
            try {
                $result = Compare_Tools::execute($tool, $args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    isset($result['ok']) && $result['ok'] ? 'success' : 'error',
                    $args,
                    'read',
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && (!isset($result['ok']) || $result['ok'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                \MCP_No_Headless\Ops\Audit_Logger::log_tool($tool, $user_id, 'error', $args, 'read', $latency_ms, 'exception');
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // T4.1: Team management
        $team_tools = ['ml_get_team', 'ml_manage_team'];
        if (in_array($tool, $team_tools)) {
            $start_time = microtime(true);
            try {
                $result = Team_Tools::execute($tool, $args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                $is_write = ($tool === 'ml_manage_team' && ($args['stage'] ?? 'prepare') === 'commit');
                $stage = $is_write ? 'commit' : ($tool === 'ml_manage_team' ? 'prepare' : 'read');

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    isset($result['ok']) && $result['ok'] ? 'success' : 'error',
                    $args,
                    $stage,
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && (!isset($result['ok']) || $result['ok'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                \MCP_No_Headless\Ops\Audit_Logger::log_tool($tool, $user_id, 'error', $args, 'read', $latency_ms, 'exception');
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // T4.2: Export bundle
        $export_tools = ['ml_export_bundle'];
        if (in_array($tool, $export_tools)) {
            $start_time = microtime(true);
            try {
                $result = Export_Tools::execute($tool, $args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    isset($result['ok']) && $result['ok'] ? 'success' : 'error',
                    $args,
                    'read',
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && (!isset($result['ok']) || $result['ok'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                \MCP_No_Headless\Ops\Audit_Logger::log_tool($tool, $user_id, 'error', $args, 'read', $latency_ms, 'exception');
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // T5: Auto-Improve (Intelligence Layer)
        $auto_improve_tools = ['ml_auto_improve', 'ml_prompt_health_check', 'ml_auto_improve_batch'];
        if (in_array($tool, $auto_improve_tools)) {
            try {
                $result = Auto_Improve_Tools::execute($tool, $args, $user_id);
                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Recommendations
        $recommend_tools = ['ml_recommend', 'ml_recommend_styles'];
        if (in_array($tool, $recommend_tools)) {
            try {
                $result = Recommend_Tools::execute($tool, $args, $user_id);
                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Context Bundle
        $context_tools = ['ml_context_bundle_build'];
        if (in_array($tool, $context_tools)) {
            try {
                $result = Context_Bundle_Tools::execute($tool, $args, $user_id);
                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Apply Tool V2 (prepare/commit)
        $apply_tools = ['ml_apply_tool_prepare', 'ml_apply_tool_commit'];
        if (in_array($tool, $apply_tools)) {
            $start_time = microtime(true);
            try {
                $result = Apply_Tool_V2::execute($tool, $args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                // Determine stage for audit
                $stage = str_contains($tool, 'prepare') ? 'prepare' : 'commit';
                $audit_result = isset($result['ok']) && $result['ok'] ? 'success' : 'error';

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    $audit_result,
                    $args,
                    $stage,
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && (!isset($result['ok']) || $result['ok'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                $stage = str_contains($tool, 'prepare') ? 'prepare' : 'commit';
                \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    'error',
                    $args,
                    $stage,
                    $latency_ms,
                    'exception'
                );
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Assist Tool (1-call demo orchestrator)
        // Bootstrap Wizard Tool
        if ($tool === 'ml_assist_prepare') {
            $start_time = microtime(true);
            try {
                $result = Assist_Tool::execute($args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                $audit_result = isset($result['ok']) && $result['ok'] ? 'success' : 'error';

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    $audit_result,
                    $args,
                    'prepare',
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && (!isset($result['ok']) || $result['ok'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    'error',
                    $args,
                    'prepare',
                    $latency_ms,
                    'exception'
                );
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Dependency management tools
        $dependency_tools = ['ml_get_dependencies', 'ml_add_dependency', 'ml_remove_dependency', 'ml_set_dependencies'];
        if (in_array($tool, $dependency_tools)) {
            $start_time = microtime(true);
            try {
                $result = Dependency_Tools::handle($tool, $args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                // Determine if write operation
                $is_write = in_array($tool, ['ml_add_dependency', 'ml_remove_dependency', 'ml_set_dependencies']);
                $stage = $is_write ? 'write' : 'read';

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    isset($result['ok']) && $result['ok'] ? 'success' : 'error',
                    $args,
                    $stage,
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && (!isset($result['ok']) || $result['ok'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                \MCP_No_Headless\Ops\Audit_Logger::log_tool($tool, $user_id, 'error', $args, 'read', $latency_ms, 'exception');
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        // Bootstrap Wizard Tool
        if ($tool === 'ml_bootstrap_wizard') {
            $start_time = microtime(true);
            try {
                $result = Bootstrap_Wizard_Tool::handle($args, $user_id);
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

                $stage = $args['stage'] ?? 'analyze';
                $audit_result = isset($result['ok']) && $result['ok'] ? 'success' : 'error';

                $debug_id = \MCP_No_Headless\Ops\Audit_Logger::log_tool(
                    $tool,
                    $user_id,
                    $audit_result,
                    $args,
                    $stage,
                    $latency_ms,
                    $result['error'] ?? null
                );

                if ($debug_id && (!isset($result['ok']) || $result['ok'])) {
                    $result['debug_id'] = $debug_id;
                }

                return $this->success_response($id, $result);
            } catch (\Exception $e) {
                $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
                \MCP_No_Headless\Ops\Audit_Logger::log_tool($tool, $user_id, 'error', $args, 'analyze', $latency_ms, 'exception');
                return $this->error_response($id, -32603, $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Handle MCP prompts methods (prompts/list, prompts/get)
     *
     * @param string $method Method name
     * @param array $args Method arguments
     * @param int $user_id User ID
     * @param int $id Request ID
     * @return array JSON-RPC response
     */
    private function handle_prompts_method(string $method, array $args, int $user_id, int $id) {
        if (!Prompts_Handler::is_available()) {
            return $this->error_response($id, -32603, 'Prompts capability not available');
        }

        try {
            $handler = new Prompts_Handler($user_id);

            switch ($method) {
                case 'prompts/list':
                    $result = $handler->list_prompts($args);
                    break;

                case 'prompts/get':
                    $result = $handler->get_prompt($args);
                    // Check for error in result
                    if (isset($result['error'])) {
                        return [
                            'jsonrpc' => '2.0',
                            'error' => $result['error'],
                            'id' => $id,
                        ];
                    }
                    break;

                default:
                    return $this->error_response($id, -32601, 'Unknown method');
            }

            return $this->success_response($id, $result);
        } catch (\Exception $e) {
            return $this->error_response($id, -32603, $e->getMessage());
        }
    }

    /**
     * Handle MCP completion/complete method
     *
     * @param array $args Method arguments
     * @param int $user_id User ID
     * @param int $id Request ID
     * @return array JSON-RPC response
     */
    private function handle_completion_method(array $args, int $user_id, int $id) {
        if (!Completion_Handler::is_available()) {
            return $this->error_response($id, -32603, 'Completion capability not available');
        }

        try {
            $handler = new Completion_Handler($user_id);
            $result = $handler->complete($args);
            return $this->success_response($id, $result);
        } catch (\Exception $e) {
            return $this->error_response($id, -32603, $e->getMessage());
        }
    }

    /**
     * Get BuddyBoss tool definitions (conditionally added)
     */
    private function get_buddyboss_tool_definitions(): array {
        $tools = [];

        // Group tools
        if (\MCP_No_Headless\BuddyBoss\Group_Service::is_available()) {
            $tools[] = [
                'name' => 'ml_groups_search',
                'description' => 'Recherche des groupes BuddyBoss avec filtres (my_only, status)',
                'category' => 'BuddyBoss Groups',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Terme de recherche'],
                        'limit' => ['type' => 'integer', 'default' => 10, 'maximum' => 20],
                        'page' => ['type' => 'integer', 'default' => 1],
                        'filters' => [
                            'type' => 'object',
                            'properties' => [
                                'my_only' => ['type' => 'boolean', 'description' => 'Uniquement mes groupes'],
                                'status' => ['type' => 'string', 'enum' => ['public', 'private'], 'description' => 'Filtrer par statut'],
                            ],
                        ],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true],
            ];

            $tools[] = [
                'name' => 'ml_group_fetch',
                'description' => 'Recupere les details complets d\'un groupe BuddyBoss',
                'category' => 'BuddyBoss Groups',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'group_id' => ['type' => 'integer', 'description' => 'ID du groupe'],
                    ],
                    'required' => ['group_id'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ];

            $tools[] = [
                'name' => 'ml_group_members',
                'description' => 'Liste les membres d\'un groupe BuddyBoss',
                'category' => 'BuddyBoss Groups',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'group_id' => ['type' => 'integer', 'description' => 'ID du groupe'],
                        'page' => ['type' => 'integer', 'default' => 1],
                        'per_page' => ['type' => 'integer', 'default' => 10, 'maximum' => 20],
                    ],
                    'required' => ['group_id'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ];
        }

        // Activity tools
        if (\MCP_No_Headless\BuddyBoss\Activity_Service::is_available()) {
            $tools[] = [
                'name' => 'ml_activity_list',
                'description' => 'Liste le flux d\'activite BuddyBoss avec filtres (group, friends, my, public)',
                'category' => 'BuddyBoss Activity',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'group_id' => ['type' => 'integer', 'description' => 'Filtrer par groupe'],
                        'scope' => ['type' => 'string', 'enum' => ['all', 'group', 'friends', 'my', 'public'], 'default' => 'all'],
                        'search' => ['type' => 'string', 'description' => 'Terme de recherche'],
                        'page' => ['type' => 'integer', 'default' => 1],
                        'per_page' => ['type' => 'integer', 'default' => 10, 'maximum' => 20],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true],
            ];

            $tools[] = [
                'name' => 'ml_activity_fetch',
                'description' => 'Recupere les details complets d\'une activite BuddyBoss',
                'category' => 'BuddyBoss Activity',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'activity_id' => ['type' => 'integer', 'description' => 'ID de l\'activite'],
                    ],
                    'required' => ['activity_id'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ];

            $tools[] = [
                'name' => 'ml_activity_comments',
                'description' => 'Liste les commentaires d\'une activite BuddyBoss',
                'category' => 'BuddyBoss Activity',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'activity_id' => ['type' => 'integer', 'description' => 'ID de l\'activite'],
                        'page' => ['type' => 'integer', 'default' => 1],
                        'per_page' => ['type' => 'integer', 'default' => 10, 'maximum' => 20],
                    ],
                    'required' => ['activity_id'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ];

            // Write tools (prepare/commit flow)
            $tools[] = [
                'name' => 'ml_activity_post_prepare',
                'description' => 'Prepare une publication dans le flux d\'activite (retourne un preview et idempotency_key). Appelez ml_activity_post_commit pour valider.',
                'category' => 'BuddyBoss Activity',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Contenu de la publication'],
                        'group_id' => ['type' => 'integer', 'description' => 'ID du groupe (optionnel, si omis: publication globale)'],
                    ],
                    'required' => ['content'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
            ];

            $tools[] = [
                'name' => 'ml_activity_post_commit',
                'description' => 'Valide et publie une activite preparee. Necessite l\'idempotency_key obtenu via ml_activity_post_prepare.',
                'category' => 'BuddyBoss Activity',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'idempotency_key' => ['type' => 'string', 'description' => 'Cle obtenue de ml_activity_post_prepare'],
                    ],
                    'required' => ['idempotency_key'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
            ];

            $tools[] = [
                'name' => 'ml_activity_comment_prepare',
                'description' => 'Prepare un commentaire sur une activite (retourne un preview et idempotency_key). Appelez ml_activity_comment_commit pour valider.',
                'category' => 'BuddyBoss Activity',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'activity_id' => ['type' => 'integer', 'description' => 'ID de l\'activite a commenter'],
                        'content' => ['type' => 'string', 'description' => 'Contenu du commentaire'],
                    ],
                    'required' => ['activity_id', 'content'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
            ];

            $tools[] = [
                'name' => 'ml_activity_comment_commit',
                'description' => 'Valide et publie un commentaire d\'activite prepare. Necessite l\'idempotency_key obtenu via ml_activity_comment_prepare.',
                'category' => 'BuddyBoss Activity',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'idempotency_key' => ['type' => 'string', 'description' => 'Cle obtenue de ml_activity_comment_prepare'],
                    ],
                    'required' => ['idempotency_key'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
            ];
        }

        // Member tools
        if (\MCP_No_Headless\BuddyBoss\Member_Service::is_available()) {
            $tools[] = [
                'name' => 'ml_members_search',
                'description' => 'Recherche des membres BuddyBoss avec filtres (friends_only, group_id)',
                'category' => 'BuddyBoss Members',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Terme de recherche'],
                        'limit' => ['type' => 'integer', 'default' => 10, 'maximum' => 20],
                        'page' => ['type' => 'integer', 'default' => 1],
                        'filters' => [
                            'type' => 'object',
                            'properties' => [
                                'friends_only' => ['type' => 'boolean', 'description' => 'Uniquement mes amis'],
                                'group_id' => ['type' => 'integer', 'description' => 'Membres d\'un groupe specifique'],
                            ],
                        ],
                    ],
                ],
                'annotations' => ['readOnlyHint' => true],
            ];

            $tools[] = [
                'name' => 'ml_member_fetch',
                'description' => 'Recupere le profil minimal d\'un membre BuddyBoss (data minimization)',
                'category' => 'BuddyBoss Members',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer', 'description' => 'ID du membre'],
                    ],
                    'required' => ['user_id'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ];
        }

        return $tools;
    }

    /**
     * Check rate limit for user
     *
     * @param int $user_id User ID
     * @return bool True if within limit
     */
    private function check_rate_limit(int $user_id): bool {
        $key = 'mcpnh_rate_' . $user_id;
        $count = (int) get_transient($key);

        // Allow 100 calls per hour
        if ($count >= 100) {
            return false;
        }

        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return true;
    }

    /**
     * Create success response
     *
     * @param int $id Request ID
     * @param mixed $result Result data
     * @return array
     */
    private function success_response(int $id, $result): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]
                ],
                'data' => $result,
            ],
        ];
    }

    /**
     * Create error response
     *
     * @param int $id Request ID
     * @param int $code Error code
     * @param string $message Error message
     * @return array
     */
    private function error_response(int $id, int $code, string $message): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
