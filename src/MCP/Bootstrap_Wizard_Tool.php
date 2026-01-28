<?php
/**
 * Bootstrap Wizard Tool - Création automatique d'outils avec auto-sélection
 * 
 * VERSION AVEC MÉTRIQUES INTÉGRÉES
 *
 * Stages:
 *   analyze  → Détecte les besoins à partir du texte
 *   propose  → Auto-sélectionne les composants et propose un kit
 *   collect  → Permet de corriger une sélection (optionnel)
 *   validate → Vérifie la readiness avant exécution
 *   execute  → Crée les placeholders, outils et landing page
 *
 * Events émis:
 *   ml_metrics:bootstrap_analyze
 *   ml_metrics:bootstrap_select
 *   ml_metrics:bootstrap_override
 *   ml_metrics:tool_created
 *   ml_metrics:bootstrap_complete
 *   ml_metrics:bootstrap_error
 *
 * @package MCP_No_Headless
 * @since 2.6.0
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Component_Picker;
use MCP_No_Headless\Services\Instruction_Builder;

class Bootstrap_Wizard_Tool {

    // =========================================================================
    // CONSTANTES - PATTERNS DE DÉTECTION
    // =========================================================================

    private const DETECTION_PATTERNS = [
        'ao_response' => [
            'patterns' => ['/appel[s]?\s*(d\'|d\s)?offre/i', '/\bAO\b/', '/répondre.*marché/i', '/consultation/i'],
            'keywords' => ['ao', 'appel offre', 'marché public', 'rfp', 'tender'],
            'weight' => 1.0,
        ],
        'follow_up' => [
            'patterns' => ['/relance[r]?/i', '/follow.?up/i', '/recontact/i', '/rappel.*client/i'],
            'keywords' => ['relance', 'follow-up', 'rappel', 'prospect'],
            'weight' => 0.9,
        ],
        'proposal' => [
            'patterns' => ['/proposition?\s+commerciale/i', '/devis/i', '/offre\s+commerciale/i'],
            'keywords' => ['proposition', 'devis', 'offre commerciale', 'chiffrage'],
            'weight' => 0.85,
        ],
        'email_commercial' => [
            'patterns' => ['/email.*commercial/i', '/mail.*prospect/i', '/courrier/i'],
            'keywords' => ['email', 'mail', 'prospection'],
            'weight' => 0.8,
        ],
    ];

    // =========================================================================
    // CONSTANTES - DATA TYPES
    // =========================================================================

    private const DATA_TYPES = [
        'catalog' => [
            'id' => 'catalog',
            'label' => 'Catalogue produits/services',
            'keywords' => ['catalog', 'catalogue', 'offre', 'service', 'produit', 'prestation'],
            'required_for' => ['ao_response', 'follow_up', 'proposal'],
        ],
        'pricing' => [
            'id' => 'pricing',
            'label' => 'Grille tarifaire',
            'keywords' => ['tarif', 'prix', 'pricing', 'grille', 'tjm', 'forfait'],
            'required_for' => ['ao_response', 'proposal'],
        ],
        'references' => [
            'id' => 'references',
            'label' => 'Références clients',
            'keywords' => ['reference', 'référence', 'client', 'portfolio', 'cas', 'projet'],
            'required_for' => ['ao_response', 'proposal'],
        ],
        'company_info' => [
            'id' => 'company_info',
            'label' => 'Présentation entreprise',
            'keywords' => ['entreprise', 'société', 'cabinet', 'équipe', 'histoire'],
            'required_for' => ['ao_response'],
        ],
        'brand_guide' => [
            'id' => 'brand_guide',
            'label' => 'Charte éditoriale',
            'keywords' => ['charte', 'style', 'éditorial', 'brand', 'ton'],
            'required_for' => [],
            'is_style' => true,
        ],
    ];

    // =========================================================================
    // CONSTANTES - TOOL TEMPLATES
    // =========================================================================

    private const TOOL_TEMPLATES = [
        'ao_response' => [
            'id' => 'ao_response',
            'name' => 'Générateur de Réponse AO',
            'description' => 'Rédige des réponses structurées aux appels d\'offres',
            'instruction' => "Tu es un expert en réponse aux appels d'offres B2B avec 15 ans d'expérience.\nTu connais parfaitement les attentes des acheteurs publics et privés.\nTu structures tes réponses de manière claire, factuelle et persuasive.",
            'final_task' => "Analyse l'appel d'offres fourni et rédige une réponse complète, structurée et persuasive.\nMets en valeur les points forts et les références pertinentes.",
            'required_data' => ['catalog', 'pricing', 'references'],
            'style' => 'formal_b2b',
        ],
        'follow_up' => [
            'id' => 'follow_up',
            'name' => 'Assistant Relance Client',
            'description' => 'Rédige des emails de relance personnalisés',
            'instruction' => "Tu es un expert en relation client B2B.\nTu sais relancer avec tact, sans être insistant.\nTu personnalises chaque message.",
            'final_task' => "Rédige un email de relance professionnel et personnalisé.\nInclus un appel à l'action clair.",
            'required_data' => ['catalog'],
            'style' => 'professional_friendly',
        ],
        'proposal' => [
            'id' => 'proposal',
            'name' => 'Générateur de Proposition Commerciale',
            'description' => 'Crée des propositions commerciales personnalisées',
            'instruction' => "Tu es un expert en rédaction de propositions commerciales B2B.\nTu sais structurer une offre de manière claire et convaincante.",
            'final_task' => "Rédige une proposition commerciale complète avec contexte, approche, planning et budget.",
            'required_data' => ['catalog', 'pricing', 'company_info'],
            'style' => 'formal_b2b',
        ],
        'email_commercial' => [
            'id' => 'email_commercial',
            'name' => 'Rédacteur Email Commercial',
            'description' => 'Crée des emails de prospection',
            'instruction' => "Tu es un expert en copywriting B2B.\nTu écris des emails qui génèrent des réponses.",
            'final_task' => "Rédige un email commercial percutant avec objet accrocheur et CTA clair.",
            'required_data' => ['catalog'],
            'style' => 'professional_friendly',
        ],
    ];

    // =========================================================================
    // TOOL DEFINITION (pour le registre MCP)
    // =========================================================================

    public static function get_definition(): array {
        return [
            'name' => 'ml_bootstrap_wizard',
            'description' => 'Assistant de création automatique d\'outils. Analyse le besoin, sélectionne les contenus, génère les outils.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'stage' => [
                        'type' => 'string',
                        'enum' => ['analyze', 'propose', 'collect', 'validate', 'execute'],
                        'description' => 'Étape du wizard',
                    ],
                    'session_id' => [
                        'type' => 'string',
                        'description' => 'ID de session (requis après analyze)',
                    ],
                    'problems' => [
                        'type' => 'string',
                        'description' => 'Description du besoin (stage=analyze)',
                    ],
                    'target_space_id' => [
                        'type' => 'integer',
                        'description' => 'ID de l\'espace cible',
                    ],
                    'data_id' => [
                        'type' => 'string',
                        'description' => 'ID du type de données à corriger (stage=collect)',
                    ],
                    'publication_id' => [
                        'type' => 'integer',
                        'description' => 'ID de la publication à utiliser (stage=collect)',
                    ],
                    'confirmed' => [
                        'type' => 'boolean',
                        'description' => 'Confirmation d\'exécution (stage=execute)',
                    ],
                ],
                'required' => ['stage'],
            ],
        ];
    }

    // =========================================================================
    // HANDLER PRINCIPAL
    // =========================================================================

    public static function handle(array $args, int $user_id): array {
        $stage = $args['stage'] ?? 'analyze';

        switch ($stage) {
            case 'analyze':
                return self::stage_analyze($args, $user_id);
            case 'propose':
                return self::stage_propose($args, $user_id);
            case 'collect':
                return self::stage_collect($args, $user_id);
            case 'validate':
                return self::stage_validate($args, $user_id);
            case 'execute':
                return self::stage_execute($args, $user_id);
            default:
                return Tool_Response::error('invalid_stage', 'Stage inconnu: ' . $stage);
        }
    }

    // =========================================================================
    // STAGE: ANALYZE
    // =========================================================================

    private static function stage_analyze(array $args, int $user_id): array {
        $start_time = microtime(true);
        
        $problems = $args['problems'] ?? '';
        $target_space_id = $args['target_space_id'] ?? 0;

        if (empty($problems)) {
            return Tool_Response::error('missing_problems', 'Décrivez votre besoin dans le champ "problems".');
        }

        if (empty($target_space_id)) {
            return Tool_Response::error('missing_space', 'Spécifiez target_space_id.');
        }

        // Vérifier accès à l'espace
        $checker = new Permission_Checker($user_id);
        if (!$checker->can_create_in_space($target_space_id)) {
            return Tool_Response::error('access_denied', 'Vous n\'avez pas accès à cet espace.');
        }

        // Détecter les outils nécessaires (avec scores de confiance)
        $detection = self::detect_tools_with_confidence($problems);
        $detected_tools = $detection['tools'];
        $confidence = $detection['confidence'];

        if (empty($detected_tools)) {
            return Tool_Response::error('no_tools_detected', 'Impossible de déterminer les outils nécessaires. Reformulez votre besoin.');
        }

        // Déterminer les données requises
        $required_data = self::get_required_data($detected_tools);

        // Créer la session
        $session_id = 'boot_' . uniqid();
        $run_id = 'run_' . uniqid();
        
        $session = [
            'session_id' => $session_id,
            'run_id' => $run_id,
            'created_at' => time(),
            'expires_at' => time() + 3600,
            'user_id' => $user_id,
            'target_space_id' => $target_space_id,
            'problems' => $problems,
            'detected_tools' => $detected_tools,
            'detection_confidence' => $confidence,
            'required_data' => $required_data,
            'components' => [],
            'overrides' => [],
            'current_stage' => 'analyze',
        ];

        self::save_session($session);

        // =====================================================================
        // METRIC: bootstrap_analyze
        // =====================================================================
        $latency_ms = (microtime(true) - $start_time) * 1000;
        
        do_action('ml_metrics', 'bootstrap_analyze', [
            'run_id' => $run_id,
            'session_id' => $session_id,
            'user_id' => $user_id,
            'space_id' => $target_space_id,
            'problems_length' => strlen($problems),
            'detected_tools' => $detected_tools,
            'detected_tools_count' => count($detected_tools),
            'required_data' => $required_data,
            'required_data_count' => count($required_data),
            'confidence' => $confidence,
            'latency_ms' => round($latency_ms, 2),
        ]);

        return Tool_Response::ok([
            'session_id' => $session_id,
            'stage' => 'analyze',
            'detected_tools' => array_map(fn($id) => [
                'id' => $id,
                'name' => self::TOOL_TEMPLATES[$id]['name'] ?? $id,
                'description' => self::TOOL_TEMPLATES[$id]['description'] ?? '',
            ], $detected_tools),
            'confidence' => $confidence,
            'required_data' => array_map(fn($id) => [
                'id' => $id,
                'label' => self::DATA_TYPES[$id]['label'] ?? $id,
            ], $required_data),
            'next_stage' => 'propose',
            'prompt_for_user' => 'Besoin analysé. ' . count($detected_tools) . ' outil(s) identifié(s). Passez à stage=propose pour voir les composants sélectionnés.',
        ]);
    }

    // =========================================================================
    // STAGE: PROPOSE
    // =========================================================================

    private static function stage_propose(array $args, int $user_id): array {
        $start_time = microtime(true);
        
        $session = self::get_session($args['session_id'] ?? '');
        if (!$session) {
            return Tool_Response::error('session_expired', 'Session expirée ou invalide.');
        }

        $target_space_id = $session['target_space_id'];
        $required_data = $session['required_data'];
        $run_id = $session['run_id'];

        // Auto-sélection des composants
        $picker = new Component_Picker($user_id, $target_space_id);
        $components = [];
        $total_score = 0;

        foreach ($required_data as $data_id) {
            $result = $picker->pick($data_id, self::DATA_TYPES[$data_id]['keywords'] ?? []);
            $components[$data_id] = $result;
            $total_score += $result['score'] ?? 0;
        }

        // Mettre à jour la session
        $session['components'] = $components;
        $session['current_stage'] = 'propose';
        self::save_session($session);

        // Compter les manquants
        $missing = array_filter($components, fn($c) => !$c['found']);
        $found = array_filter($components, fn($c) => $c['found']);
        
        $coverage_rate = count($required_data) > 0 
            ? count($found) / count($required_data) 
            : 0;
        
        $placeholder_rate = count($required_data) > 0 
            ? count($missing) / count($required_data) 
            : 0;

        // =====================================================================
        // METRIC: bootstrap_select
        // =====================================================================
        $latency_ms = (microtime(true) - $start_time) * 1000;
        
        do_action('ml_metrics', 'bootstrap_select', [
            'run_id' => $run_id,
            'session_id' => $session['session_id'],
            'space_id' => $target_space_id,
            'required_count' => count($required_data),
            'found_count' => count($found),
            'missing_count' => count($missing),
            'coverage_rate' => round($coverage_rate, 3),
            'placeholder_rate' => round($placeholder_rate, 3),
            'avg_score' => count($required_data) > 0 ? round($total_score / count($required_data), 2) : 0,
            'components' => array_map(fn($c) => [
                'found' => $c['found'],
                'score' => $c['score'] ?? 0,
                'id' => $c['publication_id'] ?? null,
            ], $components),
            'latency_ms' => round($latency_ms, 2),
        ]);

        // Construire le kit proposé
        $proposed_kit = [
            'name' => 'Kit ' . ucfirst($session['detected_tools'][0] ?? 'Custom'),
            'tools' => array_map(fn($id) => self::TOOL_TEMPLATES[$id] ?? ['id' => $id], $session['detected_tools']),
        ];

        return Tool_Response::ok([
            'session_id' => $session['session_id'],
            'stage' => 'propose',
            'proposed_kit' => $proposed_kit,
            'components' => $components,
            'metrics' => [
                'coverage_rate' => round($coverage_rate * 100, 1) . '%',
                'placeholder_rate' => round($placeholder_rate * 100, 1) . '%',
            ],
            'summary' => [
                'found' => count($found),
                'missing' => count($missing),
            ],
            'next_stage' => count($missing) > 0 ? 'collect' : 'validate',
            'prompt_for_user' => count($missing) > 0
                ? count($missing) . ' contenu(s) manquant(s). Vous pouvez corriger avec stage=collect ou continuer vers validate.'
                : 'Tous les contenus trouvés ! Passez à stage=validate.',
        ]);
    }

    // =========================================================================
    // STAGE: COLLECT (correction manuelle)
    // =========================================================================

    private static function stage_collect(array $args, int $user_id): array {
        $session = self::get_session($args['session_id'] ?? '');
        if (!$session) {
            return Tool_Response::error('session_expired', 'Session expirée.');
        }

        $data_id = $args['data_id'] ?? '';
        $publication_id = $args['publication_id'] ?? 0;

        if (empty($data_id)) {
            return Tool_Response::error('missing_data_id', 'Spécifiez data_id.');
        }

        if (empty($publication_id)) {
            return Tool_Response::error('missing_publication_id', 'Spécifiez publication_id.');
        }

        // Vérifier que la publication existe et est accessible
        $checker = new Permission_Checker($user_id);
        if (!$checker->can_see_publication($publication_id)) {
            return Tool_Response::error('access_denied', 'Publication non accessible.');
        }

        $post = get_post($publication_id);
        if (!$post) {
            return Tool_Response::error('not_found', 'Publication introuvable.');
        }

        // Stocker l'ancien ID pour la métrique
        $old_id = $session['components'][$data_id]['publication_id'] ?? null;
        $was_found = $session['components'][$data_id]['found'] ?? false;

        // Mettre à jour le mapping
        $session['overrides'][$data_id] = $publication_id;
        $session['components'][$data_id] = [
            'found' => true,
            'publication_id' => $publication_id,
            'title' => $post->post_title,
            'score' => 1.0, // Score max car sélection manuelle
            'source' => 'manual',
        ];
        $session['current_stage'] = 'collect';
        self::save_session($session);

        // =====================================================================
        // METRIC: bootstrap_override (pour calculer replacement_rate)
        // =====================================================================
        do_action('ml_metrics', 'bootstrap_override', [
            'run_id' => $session['run_id'],
            'session_id' => $session['session_id'],
            'data_id' => $data_id,
            'old_publication_id' => $old_id,
            'new_publication_id' => $publication_id,
            'was_found' => $was_found,
            'is_replacement' => $was_found && $old_id !== null,
        ]);

        return Tool_Response::ok([
            'session_id' => $session['session_id'],
            'stage' => 'collect',
            'updated' => true,
            'data_id' => $data_id,
            'publication' => [
                'id' => $publication_id,
                'title' => $post->post_title,
            ],
            'components' => $session['components'],
            'next_stage' => 'validate',
        ]);
    }

    // =========================================================================
    // STAGE: VALIDATE
    // =========================================================================

    private static function stage_validate(array $args, int $user_id): array {
        $session = self::get_session($args['session_id'] ?? '');
        if (!$session) {
            return Tool_Response::error('session_expired', 'Session expirée.');
        }

        $components = $session['components'];
        $detected_tools = $session['detected_tools'];

        // Identifier les placeholders à créer
        $placeholders_needed = [];
        foreach ($components as $data_id => $comp) {
            if (!$comp['found']) {
                $placeholders_needed[] = $data_id;
            }
        }

        // Résumé
        $summary = [
            'tools_count' => count($detected_tools),
            'tools' => array_map(fn($id) => self::TOOL_TEMPLATES[$id]['name'] ?? $id, $detected_tools),
            'components_found' => count(array_filter($components, fn($c) => $c['found'])),
            'components_missing' => count($placeholders_needed),
            'placeholders_to_create' => $placeholders_needed,
        ];

        $warnings = [];
        if (!empty($placeholders_needed)) {
            $warnings[] = count($placeholders_needed) . ' contenu(s) manquant(s) seront créés comme placeholders à compléter.';
        }

        $session['current_stage'] = 'validate';
        $session['validated'] = true;
        self::save_session($session);

        return Tool_Response::ok([
            'session_id' => $session['session_id'],
            'stage' => 'validate',
            'ready' => true,
            'summary' => $summary,
            'warnings' => $warnings,
            'next_stage' => 'execute',
            'prompt_for_user' => 'Prêt à créer ! Passez à stage=execute avec confirmed=true.',
        ]);
    }

    // =========================================================================
    // STAGE: EXECUTE
    // =========================================================================

    private static function stage_execute(array $args, int $user_id): array {
        $start_time = microtime(true);
        
        $session = self::get_session($args['session_id'] ?? '');
        if (!$session) {
            return Tool_Response::error('session_expired', 'Session expirée.');
        }

        if (empty($args['confirmed'])) {
            return Tool_Response::ok([
                'session_id' => $session['session_id'],
                'stage' => 'execute',
                'status' => 'awaiting_confirmation',
                'prompt_for_user' => 'Confirmez avec confirmed=true pour lancer la création.',
            ]);
        }

        $target_space_id = $session['target_space_id'];
        $detected_tools = $session['detected_tools'];
        $components = $session['components'];
        $run_id = $session['run_id'];

        $created = [];
        $tool_ids = [];

        try {
            // 1. Créer les placeholders pour les composants manquants
            $content_ids = [];
            $style_ids = [];
            $placeholder_count = 0;

            foreach ($components as $data_id => $comp) {
                $data_type = self::DATA_TYPES[$data_id] ?? null;
                $is_style = $data_type['is_style'] ?? false;

                if ($comp['found']) {
                    // Composant existant
                    if ($is_style) {
                        $style_ids[] = $comp['publication_id'];
                    } else {
                        $content_ids[] = $comp['publication_id'];
                    }
                } else {
                    // Créer placeholder
                    $placeholder = self::create_placeholder($data_id, $target_space_id, $user_id, $session['session_id']);
                    $created[] = $placeholder;
                    $placeholder_count++;

                    if ($is_style) {
                        $style_ids[] = $placeholder['id'];
                    } else {
                        $content_ids[] = $placeholder['id'];
                    }
                }
            }

            // 2. Créer les outils
            foreach ($detected_tools as $tool_id) {
                $template = self::TOOL_TEMPLATES[$tool_id] ?? null;
                if (!$template) continue;

                $tool = self::create_tool(
                    $template,
                    $content_ids,
                    $style_ids,
                    $target_space_id,
                    $user_id,
                    $session['session_id']
                );
                $created[] = $tool;
                $tool_ids[] = $tool['id'];

                // =============================================================
                // METRIC: tool_created (pour chaque outil)
                // =============================================================
                do_action('ml_metrics', 'tool_created', [
                    'run_id' => $run_id,
                    'tool_id' => $tool['id'],
                    'tool_template' => $tool_id,
                    'space_id' => $target_space_id,
                    'content_count' => count($content_ids),
                    'style_count' => count($style_ids),
                    'url_count' => count($content_ids) + count($style_ids),
                    'placeholder_count' => $placeholder_count,
                ]);
            }

            // 3. Créer la landing page
            $landing = self::create_landing_page(
                $session,
                $created,
                $target_space_id,
                $user_id
            );
            $created[] = $landing;

        } catch (\Exception $e) {
            // METRIC: bootstrap_error
            do_action('ml_metrics', 'bootstrap_error', [
                'run_id' => $run_id,
                'stage' => 'execute',
                'error' => $e->getMessage(),
            ]);
            
            return Tool_Response::error('execution_failed', 'Erreur: ' . $e->getMessage());
        }

        // =====================================================================
        // METRIC: bootstrap_complete
        // =====================================================================
        $latency_ms = (microtime(true) - $start_time) * 1000;
        $total_latency_ms = (time() - $session['created_at']) * 1000;
        
        do_action('ml_metrics', 'bootstrap_complete', [
            'run_id' => $run_id,
            'session_id' => $session['session_id'],
            'space_id' => $target_space_id,
            'user_id' => $user_id,
            'tools_created' => count($tool_ids),
            'tool_ids' => $tool_ids,
            'placeholders_created' => $placeholder_count,
            'total_created' => count($created),
            'overrides_count' => count($session['overrides'] ?? []),
            'execute_latency_ms' => round($latency_ms, 2),
            'total_latency_ms' => round($total_latency_ms, 2),
            'success' => true,
        ]);

        // Supprimer la session
        self::delete_session($session['session_id']);

        return Tool_Response::ok([
            'session_id' => $session['session_id'],
            'stage' => 'execute',
            'status' => 'completed',
            'created' => $created,
            'summary' => [
                'total' => count($created),
                'tools' => count(array_filter($created, fn($c) => $c['type'] === 'tool')),
                'placeholders' => count(array_filter($created, fn($c) => $c['type'] === 'placeholder')),
            ],
            'prompt_for_user' => '✅ ' . count($created) . ' éléments créés ! Vos outils sont prêts.',
        ]);
    }

    // =========================================================================
    // HELPERS - DÉTECTION
    // =========================================================================

    private static function detect_tools_with_confidence(string $text): array {
        $text_lower = mb_strtolower($text);
        $detected = [];
        $max_score = 0;

        foreach (self::DETECTION_PATTERNS as $tool_id => $config) {
            $score = 0;

            // Patterns regex
            foreach ($config['patterns'] as $pattern) {
                if (preg_match($pattern, $text)) {
                    $score += 10;
                }
            }

            // Keywords
            foreach ($config['keywords'] as $keyword) {
                if (strpos($text_lower, $keyword) !== false) {
                    $score += 5;
                }
            }

            // Appliquer le poids du tool
            $score *= ($config['weight'] ?? 1.0);

            if ($score >= 5) {
                $detected[$tool_id] = $score;
                $max_score = max($max_score, $score);
            }
        }

        // Trier par score décroissant
        arsort($detected);

        // Calculer la confiance (0-1)
        $confidence = $max_score > 0 ? min(1.0, $max_score / 20) : 0;

        return [
            'tools' => array_keys($detected),
            'scores' => $detected,
            'confidence' => round($confidence, 2),
        ];
    }

    private static function get_required_data(array $tool_ids): array {
        $required = [];

        foreach ($tool_ids as $tool_id) {
            $template = self::TOOL_TEMPLATES[$tool_id] ?? null;
            if ($template && isset($template['required_data'])) {
                $required = array_merge($required, $template['required_data']);
            }
        }

        // Ajouter brand_guide par défaut (optionnel mais utile)
        $required[] = 'brand_guide';

        return array_unique($required);
    }

    // =========================================================================
    // HELPERS - CRÉATION
    // =========================================================================

    private static function create_placeholder(string $data_id, int $space_id, int $user_id, string $session_id): array {
        $data_type = self::DATA_TYPES[$data_id] ?? null;
        $label = $data_type['label'] ?? $data_id;

        $template_content = "## {$label}\n\n[Contenu à compléter par l'administrateur]\n\nCe document sera utilisé par vos outils IA.";

        $post_id = wp_insert_post([
            'post_type' => 'publication',
            'post_status' => 'draft',
            'post_parent' => $space_id,
            'post_author' => $user_id,
            'post_title' => "📝 {$label} à compléter",
            'post_content' => $template_content,
        ]);

        if (is_wp_error($post_id)) {
            throw new \Exception('Erreur création placeholder: ' . $post_id->get_error_message());
        }

        update_post_meta($post_id, '_ml_publication_type', $data_type['is_style'] ?? false ? 'style' : 'data');
        update_post_meta($post_id, '_ml_is_placeholder', true);
        update_post_meta($post_id, '_ml_bootstrap_data_id', $data_id);
        update_post_meta($post_id, '_ml_bootstrap_session', $session_id);
        update_post_meta($post_id, '_ml_space_id', $space_id);
        update_post_meta($post_id, '_ml_placeholder_created_at', time());

        wp_set_post_terms($post_id, ['contenu'], 'publication_label');

        return [
            'id' => $post_id,
            'type' => 'placeholder',
            'title' => "📝 {$label} à compléter",
            'data_id' => $data_id,
        ];
    }

    private static function create_tool(array $template, array $content_ids, array $style_ids, int $space_id, int $user_id, string $session_id): array {
        // Construire l'instruction avec URLs
        $instruction = Instruction_Builder::build(
            $template['instruction'],
            $content_ids,
            $style_ids,
            $template['final_task']
        );

        $post_id = wp_insert_post([
            'post_type' => 'publication',
            'post_status' => 'draft',
            'post_parent' => $space_id,
            'post_author' => $user_id,
            'post_title' => $template['name'],
            'post_content' => "# {$template['name']}\n\n{$template['description']}",
        ]);

        if (is_wp_error($post_id)) {
            throw new \Exception('Erreur création outil: ' . $post_id->get_error_message());
        }

        // Metas
        update_post_meta($post_id, '_ml_instruction', $instruction);
        update_post_meta($post_id, '_ml_publication_type', 'tool');
        update_post_meta($post_id, '_ml_tool_contents', $content_ids);
        update_post_meta($post_id, '_ml_linked_styles', $style_ids);
        update_post_meta($post_id, '_ml_bootstrap_session', $session_id);
        update_post_meta($post_id, '_ml_bootstrap_tool_id', $template['id']);
        update_post_meta($post_id, '_ml_space_id', $space_id);

        wp_set_post_terms($post_id, ['outil'], 'publication_label');

        return [
            'id' => $post_id,
            'type' => 'tool',
            'title' => $template['name'],
            'tool_id' => $template['id'],
        ];
    }

    private static function create_landing_page(array $session, array $created, int $space_id, int $user_id): array {
        $tools = array_filter($created, fn($c) => $c['type'] === 'tool');
        $placeholders = array_filter($created, fn($c) => $c['type'] === 'placeholder');

        $content = "# Guide - Kit créé automatiquement\n\n";
        $content .= "## Outils disponibles\n\n";

        foreach ($tools as $tool) {
            $content .= "- **{$tool['title']}** - [Ouvrir](/publication/{$tool['id']}/)\n";
        }

        if (!empty($placeholders)) {
            $content .= "\n## Contenus à compléter\n\n";
            $content .= "⚠️ Pour des résultats optimaux, complétez ces documents :\n\n";
            foreach ($placeholders as $ph) {
                $content .= "- **{$ph['title']}** - [Éditer](/publication/{$ph['id']}/)\n";
            }
        }

        $content .= "\n---\n*Créé le " . date('d/m/Y à H:i') . " par Bootstrap Wizard*";

        $post_id = wp_insert_post([
            'post_type' => 'publication',
            'post_status' => 'publish',
            'post_parent' => $space_id,
            'post_author' => $user_id,
            'post_title' => '📖 Guide - Kit ' . date('d/m/Y'),
            'post_content' => $content,
        ]);

        update_post_meta($post_id, '_ml_publication_type', 'doc');
        update_post_meta($post_id, '_ml_bootstrap_session', $session['session_id']);
        update_post_meta($post_id, '_ml_space_id', $space_id);

        return [
            'id' => $post_id,
            'type' => 'landing',
            'title' => '📖 Guide - Kit ' . date('d/m/Y'),
        ];
    }

    // =========================================================================
    // HELPERS - SESSIONS
    // =========================================================================

    private static function save_session(array $session): void {
        set_transient('ml_boot_' . $session['session_id'], $session, 3600);
    }

    private static function get_session(string $session_id): ?array {
        if (empty($session_id)) return null;
        $session = get_transient('ml_boot_' . $session_id);
        return $session ?: null;
    }

    private static function delete_session(string $session_id): void {
        delete_transient('ml_boot_' . $session_id);
    }
}
