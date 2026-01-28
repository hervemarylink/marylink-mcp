<?php
/**
 * Help Tool - Interactive menu pivot for MaryLink MCP (Phase 3)
 *
 * Provides a conversational interface with menu navigation:
 * - menu: Main navigation with expert commands
 * - search: Search publications/spaces
 * - for_me: User's recent publications
 * - best: Top publications sorted by quality score
 * - settings: User settings
 * - reco: Contextual recommendations with keyword extraction
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Picasso\Meta_Keys;

use MCP_No_Headless\Services\Scoring_Service;

class Help_Tool {

    private Picasso_Tools $tools;
    private int $user_id;
    private ?Permission_Checker $permission_checker = null;

    /**
     * Stopwords for keyword extraction (FR/EN)
     */
    private const STOPWORDS = [
        // French
        'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'au', 'aux',
        'ce', 'cette', 'ces', 'mon', 'ma', 'mes', 'ton', 'ta', 'tes',
        'son', 'sa', 'ses', 'notre', 'nos', 'votre', 'vos', 'leur', 'leurs',
        'je', 'tu', 'il', 'elle', 'on', 'nous', 'vous', 'ils', 'elles',
        'et', 'ou', 'mais', 'donc', 'car', 'ni', 'que', 'qui', 'quoi',
        'dans', 'sur', 'sous', 'avec', 'sans', 'pour', 'par', 'entre',
        'est', 'sont', 'etre', 'avoir', 'fait', 'faire', 'peut', 'plus',
        'aussi', 'bien', 'tout', 'tous', 'toute', 'toutes', 'autre', 'autres',
        'comme', 'comment', 'quand', 'pourquoi', 'quel', 'quelle', 'quels', 'quelles',
        // English
        'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'were', 'been',
        'be', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
        'should', 'may', 'might', 'must', 'shall', 'can', 'need', 'dare',
        'it', 'its', 'this', 'that', 'these', 'those', 'i', 'you', 'he', 'she',
        'we', 'they', 'what', 'which', 'who', 'whom', 'how', 'when', 'where', 'why',
        'all', 'each', 'every', 'both', 'few', 'more', 'most', 'other', 'some', 'such',
        'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very', 'just',
    ];

    /**
     * Expert mode commands
     */
    private const EXPERT_COMMANDS = [
        ['command' => 'trouve <texte>', 'action' => 'ml_help(mode:"search", query:"<texte>")', 'description' => 'Rechercher'],
        ['command' => 'ouvre <N>', 'action' => 'ml_get_publication(publication_id:<id item N>)', 'description' => 'Ouvrir un resultat'],
        ['command' => 'applique <N>', 'action' => 'ml_apply_tool(stage:"prepare", tool_id:<id item N>)', 'description' => 'Appliquer un outil'],
        ['command' => 'best', 'action' => 'ml_help(mode:"best")', 'description' => 'Publications populaires'],
        ['command' => 'mes pubs', 'action' => 'ml_help(mode:"for_me")', 'description' => 'Mes publications'],
        ['command' => 'reco', 'action' => 'ml_help(mode:"reco")', 'description' => 'Suggestions'],
    ];

    public function __construct(Picasso_Tools $tools, int $user_id) {
        $this->tools = $tools;
        $this->user_id = $user_id;
        $this->permission_checker = new Permission_Checker($user_id);
    }

    public function execute(array $args): array {
        $mode = $args['mode'] ?? 'menu';

        switch ($mode) {
            case 'menu':
                return $this->mode_menu($args);
            case 'search':
                return $this->mode_search($args);
            case 'for_me':
                return $this->mode_for_me($args);
            case 'best':
                return $this->mode_best($args);
            case 'settings':
                return $this->mode_settings($args);
            case 'reco':
                return $this->mode_reco($args);
            default:
                return ['error' => 'invalid_mode', 'message' => "Unknown mode: {$mode}"];
        }
    }

    /**
     * Menu mode - Main navigation
     */
    private function mode_menu(array $args): array {
        $user = get_userdata($this->user_id);
        $user_name = $user ? $user->display_name : 'Guest';
        $accessible_spaces = count($this->permission_checker->get_user_spaces());

        // Get user settings for expert mode
        $expert_mode = $this->get_user_setting('expert_mode', false);

        $response = [
            'view' => 'menu',
            'menu' => [
                'title' => 'MaryLink Assistant',
                'greeting' => "Bonjour {$user_name}!",
                'stats' => [
                    'accessible_spaces' => $accessible_spaces,
                ],
                'options' => [
                    ['key' => '1', 'label' => 'Aide contextuelle', 'action' => 'reco', 'icon' => 'lightbulb'],
                    ['key' => '2', 'label' => 'Mes publications', 'action' => 'for_me', 'icon' => 'user'],
                    ['key' => '3', 'label' => 'Best-of', 'action' => 'best', 'icon' => 'star'],
                    ['key' => '4', 'label' => 'Rechercher', 'action' => 'search', 'icon' => 'search'],
                    ['key' => '5', 'label' => 'Mon profil IA', 'action' => 'settings', 'icon' => 'cog'],
                ],
            ],
            'help' => 'Choisissez une option (1-5) ou posez une question directement.',
        ];

        // Add expert commands if expert mode enabled
        if ($expert_mode) {
            $response['expert_commands'] = self::EXPERT_COMMANDS;
        }

        return $response;
    }

    /**
     * Search mode - Search publications/spaces
     */
    private function mode_search(array $args): array {
        $query = $args['query'] ?? '';
        $limit = min(50, max(1, (int) ($args['limit'] ?? 10)));
        $space_id = $args['space_id'] ?? null;

        if (empty($query)) {
            return [
                'view' => 'prompt',
                'prompt' => [
                    'message' => 'Que recherchez-vous?',
                    'placeholder' => 'Entrez votre recherche...',
                    'action' => 'search',
                ],
            ];
        }

        $search_args = [
            'search' => $query,
            'limit' => $limit,
        ];

        if ($space_id) {
            $search_args['space_id'] = (int) $space_id;
        }

        $result = $this->tools->execute('ml_list_publications', $search_args, $this->user_id);

        $items = $this->format_list_items($result['publications'] ?? [], 'publication');

        return [
            'view' => 'list',
            'title' => "Recherche: \"{$query}\"",
            'count' => count($items),
            'items' => $items,
            'actions' => [
                ['label' => 'Nouvelle recherche', 'action' => 'search'],
                ['label' => 'Retour menu', 'action' => 'menu'],
            ],
            'next_actions' => [
                'Tapez "ouvre N" pour voir le detail du resultat N',
                'Tapez "applique N" si le resultat N est un outil/prompt',
            ],
        ];
    }

    /**
     * For Me mode - User's publications
     */
    private function mode_for_me(array $args): array {
        $limit = min(50, max(1, (int) ($args['limit'] ?? 10)));

        $result = $this->tools->execute('ml_list_publications', [
            'author_id' => $this->user_id,
            'limit' => $limit,
        ], $this->user_id);

        $items = $this->format_list_items($result['publications'] ?? [], 'publication', true);

        return [
            'view' => 'list',
            'title' => 'Mes publications',
            'count' => count($items),
            'items' => $items,
            'actions' => [
                ['label' => 'Nouvelle publication', 'action' => 'create_publication'],
                ['label' => 'Retour menu', 'action' => 'menu'],
            ],
            'next_actions' => [
                'Tapez "ouvre N" pour voir/editer la publication N',
            ],
        ];
    }

    /**
     * Best mode - Top publications sorted by quality score
     */
    private function mode_best(array $args): array {
        $limit = min(50, max(1, (int) ($args['limit'] ?? 10)));
        $space_id = $args['space_id'] ?? null;
        $period = $args['period'] ?? '30d';

        // Query publications sorted by quality score
        $items = $this->get_best_publications($limit, $space_id, $period);

        return [
            'view' => 'list',
            'title' => 'Publications populaires',
            'subtitle' => $this->get_period_label($period),
            'count' => count($items),
            'items' => $items,
            'filters' => [
                'period' => $period,
                'space_id' => $space_id,
            ],
            'actions' => [
                ['label' => 'Filtrer par espace', 'action' => 'list_spaces'],
                ['label' => 'Changer periode', 'action' => 'best', 'params' => ['period' => '7d']],
                ['label' => 'Retour menu', 'action' => 'menu'],
            ],
            'next_actions' => [
                'Tapez "ouvre N" pour voir le detail',
            ],
        ];
    }

    /**
     * Settings mode - User profile and settings
     */
    private function mode_settings(array $args): array {
        $action = $args['action'] ?? 'get';
        $setting = $args['setting'] ?? null;
        $value = $args['value'] ?? null;

        // Handle settings update
        if ($action === 'set' && $setting !== null) {
            $this->set_user_setting($setting, $value);
            return [
                'view' => 'settings',
                'message' => "Setting '{$setting}' updated.",
                'settings' => $this->get_all_user_settings(),
            ];
        }

        $context = $this->tools->execute('ml_get_my_context', [], $this->user_id);

        return [
            'view' => 'settings',
            'title' => 'Mon profil IA',
            'user' => [
                'id' => $context['user_id'],
                'name' => $context['display_name'],
                'email' => $context['email'],
                'roles' => $context['roles'],
                'member_since' => $context['member_since'],
            ],
            'ai_context' => $context['ai_context'],
            'stats' => [
                'accessible_spaces' => $context['accessible_spaces'],
                'spaces_with_publications' => $context['spaces_with_publications'],
            ],
            'settings' => $this->get_all_user_settings(),
            'available_settings' => [
                ['key' => 'expert_mode', 'type' => 'boolean', 'description' => 'Enable expert commands'],
                ['key' => 'default_limit', 'type' => 'integer', 'description' => 'Default results limit'],
            ],
            'actions' => [
                ['label' => 'Modifier mon contexte IA', 'action' => 'edit_ai_context'],
                ['label' => 'Retour menu', 'action' => 'menu'],
            ],
        ];
    }

    /**
     * Reco mode - Contextual recommendations with keyword extraction
     */
    private function mode_reco(array $args): array {
        $context_text = $args['context_text'] ?? $args['context'] ?? '';
        $limit = min(20, max(1, (int) ($args['limit'] ?? 5)));
        $filters = $args['filters'] ?? [];

        // If no context, fallback to for_me
        if (empty(trim($context_text))) {
            return $this->mode_reco_fallback($limit);
        }

        // Extract keywords from context
        $keywords = $this->extract_keywords($context_text);

        if (empty($keywords)) {
            return $this->mode_reco_fallback($limit);
        }

        // Search using extracted keywords
        $query = implode(' ', array_slice($keywords, 0, 5));
        $search_args = [
            'search' => $query,
            'limit' => $limit * 3, // Over-sample for filtering
        ];

        if (!empty($filters['space_id'])) {
            $search_args['space_id'] = (int) $filters['space_id'];
        }

        $result = $this->tools->execute('ml_list_publications', $search_args, $this->user_id);

        // Filter and score results
        $scored_items = $this->score_reco_results($result['publications'] ?? [], $keywords, $context_text);

        // Take top N
        $items = array_slice($scored_items, 0, $limit);

        // Format with index
        $items = $this->format_list_items($items, 'recommendation');

        return [
            'view' => 'recommendations',
            'title' => 'Suggestions pour ce contexte',
            'keywords_detected' => $keywords,
            'count' => count($items),
            'items' => $items,
            'next_actions' => [
                'Tapez "ouvre N" pour voir le detail du resultat N',
                'Tapez "applique N" pour utiliser un outil/prompt',
                'Tapez "trouve <autre terme>" pour affiner la recherche',
            ],
            'actions' => [
                ['label' => 'Nouvelle recherche', 'action' => 'search'],
                ['label' => 'Retour menu', 'action' => 'menu'],
            ],
        ];
    }

    /**
     * Fallback for reco mode when no context provided
     */
    private function mode_reco_fallback(int $limit): array {
        // Get user's spaces
        $spaces = $this->tools->execute('ml_list_spaces', ['limit' => 5], $this->user_id);

        // Get recent publications
        $recent = $this->tools->execute('ml_list_publications', [
            'limit' => $limit,
        ], $this->user_id);

        $space_items = [];
        $index = 1;
        foreach (($spaces['spaces'] ?? []) as $space) {
            $space_items[] = [
                'index' => $index++,
                'id' => $space['id'],
                'title' => $space['title'],
                'type' => 'space',
                'url' => $space['url'] ?? '',
            ];
        }

        $pub_items = $this->format_list_items($recent['publications'] ?? [], 'publication');

        return [
            'view' => 'recommendations',
            'title' => 'Suggestions',
            'message' => 'Fournissez du contexte (context_text) pour des suggestions personnalisees.',
            'sections' => [
                [
                    'title' => 'Vos espaces',
                    'items' => $space_items,
                ],
                [
                    'title' => 'Publications recentes',
                    'items' => $pub_items,
                ],
            ],
            'suggestions' => [
                'Rechercher une publication specifique',
                'Creer une nouvelle publication',
                'Consulter les publications populaires',
            ],
            'actions' => [
                ['label' => 'Retour menu', 'action' => 'menu'],
            ],
        ];
    }

    // ==========================================
    // Helper methods
    // ==========================================

    /**
     * Extract keywords from text
     */
    private function extract_keywords(string $text): array {
        // Normalize text
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        // Tokenize
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Filter: min length, not stopword
        $filtered = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= 4 && !in_array($word, self::STOPWORDS, true)) {
                $filtered[] = $word;
            }
        }

        // Count frequency
        $freq = array_count_values($filtered);
        arsort($freq);

        // Extract bigrams
        $bigrams = [];
        for ($i = 0; $i < count($words) - 1; $i++) {
            $w1 = $words[$i];
            $w2 = $words[$i + 1];
            if (mb_strlen($w1) >= 3 && mb_strlen($w2) >= 3 &&
                !in_array($w1, self::STOPWORDS, true) &&
                !in_array($w2, self::STOPWORDS, true)) {
                $bigram = $w1 . ' ' . $w2;
                $bigrams[$bigram] = ($bigrams[$bigram] ?? 0) + 1;
            }
        }

        // Combine: top unigrams + frequent bigrams
        $keywords = array_slice(array_keys($freq), 0, 8);

        // Add frequent bigrams
        arsort($bigrams);
        foreach (array_slice(array_keys($bigrams), 0, 3) as $bigram) {
            if ($bigrams[$bigram] >= 2) {
                array_unshift($keywords, $bigram);
            }
        }

        return array_slice(array_unique($keywords), 0, 10);
    }

    /**
     * Score recommendation results based on keyword matching
     */
    private function score_reco_results(array $publications, array $keywords, string $context): array {
        $scored = [];

        foreach ($publications as $pub) {
            $score = 0;
            $reasons = [];

            $title_lower = mb_strtolower($pub['title'] ?? '');
            $excerpt_lower = mb_strtolower($pub['excerpt'] ?? '');
            $content = $title_lower . ' ' . $excerpt_lower;

            // Score based on keyword matches
            foreach ($keywords as $kw) {
                if (strpos($title_lower, $kw) !== false) {
                    $score += 3;
                    $reasons[] = "titre contient '{$kw}'";
                } elseif (strpos($excerpt_lower, $kw) !== false) {
                    $score += 1;
                    $reasons[] = "contenu contient '{$kw}'";
                }
            }

            // Bonus for tools/prompts
            $type = $pub['type'] ?? '';
            if (in_array($type, ['tool', 'prompt', 'style'], true)) {
                $score += 2;
                $reasons[] = 'outil/prompt applicable';
            }

            if ($score > 0) {
                $pub['_reco_score'] = $score;
                $pub['reason'] = implode(', ', array_slice(array_unique($reasons), 0, 3));
                $scored[] = $pub;
            }
        }

        // Sort by score descending
        usort($scored, function($a, $b) {
            return ($b['_reco_score'] ?? 0) - ($a['_reco_score'] ?? 0);
        });

        return $scored;
    }

    /**
     * Get best publications sorted by quality score
     */
    private function get_best_publications(int $limit, ?int $space_id, string $period): array {
        global $wpdb;

        // Date filter
        $date_limit = $this->get_date_from_period($period);

        // Build query for publications with quality score
        $query_args = [
            'post_type' => 'publication',
            'post_status' => 'publish',
            'posts_per_page' => $limit * 3, // Over-sample for permission filtering
            'orderby' => 'meta_value_num',
            'meta_key' => '_ml_quality_score',
            'order' => 'DESC',
            'date_query' => [
                ['after' => $date_limit],
            ],
            'suppress_filters' => true,
        ];

        if ($space_id) {
            $query_args['post_parent'] = $space_id;
        }

        // Fallback: if no quality scores exist, sort by date
        $has_scores = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_ml_quality_score'");
        if (!$has_scores) {
            $query_args['orderby'] = 'date';
            unset($query_args['meta_key']);
        }

        $query = new \WP_Query($query_args);
        $items = [];

        foreach ($query->posts as $post) {
            // Permission check (anti-leak)
            if (!$this->permission_checker->can_see_publication($post->ID)) {
                continue;
            }

            $score = Meta_Keys::get_quality_score($post->ID) ?? 0;
            $user_rating = (float) get_post_meta($post->ID, '_ml_avg_user_rating', true);
            $user_count = (int) get_post_meta($post->ID, '_ml_user_rating_count', true);
            $favorites = Meta_Keys::get_favorites_count($post->ID);

            $items[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'excerpt' => wp_trim_words($post->post_content, 30),
                'author_id' => $post->post_author,
                'author_name' => get_the_author_meta('display_name', $post->post_author),
                'date' => $post->post_date,
                'space_id' => $post->post_parent,
                'space_title' => $post->post_parent ? get_the_title($post->post_parent) : null,
                'url' => get_permalink($post->ID),
                'rating' => [
                    'score' => round($score, 2),
                    'avg_rating' => round($user_rating, 1),
                    'rating_count' => $user_count,
                    'favorites' => $favorites,
                ],
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $this->format_list_items($items, 'best');
    }

    /**
     * Format list items with index for expert commands
     */
    private function format_list_items(array $items, string $type, bool $include_status = false): array {
        $formatted = [];
        $index = 1;

        foreach ($items as $item) {
            $formatted_item = [
                'index' => $index++,
                'id' => $item['id'],
                'type' => $type,
                'title' => $item['title'],
                'url' => $item['url'] ?? '',
            ];

            // Add optional fields based on type
            if (isset($item['excerpt'])) {
                $formatted_item['excerpt'] = $item['excerpt'];
            }
            if (isset($item['author_name'])) {
                $formatted_item['author'] = $item['author_name'];
            }
            if (isset($item['space_title'])) {
                $formatted_item['space'] = $item['space_title'];
            }
            if (isset($item['reason'])) {
                $formatted_item['reason'] = $item['reason'];
            }
            if (isset($item['rating'])) {
                $formatted_item['rating'] = $item['rating'];
            }
            if ($include_status) {
                $formatted_item['status'] = $item['status'] ?? 'unknown';
                $formatted_item['step'] = $item['step'] ?? '';
                $formatted_item['date'] = $item['date'] ?? '';
            }

            $formatted[] = $formatted_item;
        }

        return $formatted;
    }

    /**
     * Get period label for display
     */
    private function get_period_label(string $period): string {
        $labels = [
            '7d' => '7 derniers jours',
            '30d' => '30 derniers jours',
            '90d' => '3 derniers mois',
            '1y' => 'Annee en cours',
        ];
        return $labels[$period] ?? $period;
    }

    /**
     * Get date from period string
     */
    private function get_date_from_period(string $period): string {
        $map = [
            '7d' => '-7 days',
            '30d' => '-30 days',
            '90d' => '-90 days',
            '1y' => '-1 year',
        ];
        return date('Y-m-d', strtotime($map[$period] ?? '-30 days'));
    }

    /**
     * Get user setting
     */
    private function get_user_setting(string $key, $default = null) {
        $settings = get_user_meta($this->user_id, '_mcpnh_settings', true);
        if (!is_array($settings)) {
            $settings = [];
        }
        return $settings[$key] ?? $default;
    }

    /**
     * Set user setting
     */
    private function set_user_setting(string $key, $value): void {
        $settings = get_user_meta($this->user_id, '_mcpnh_settings', true);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings[$key] = $value;
        update_user_meta($this->user_id, '_mcpnh_settings', $settings);
    }

    /**
     * Get all user settings
     */
    private function get_all_user_settings(): array {
        $settings = get_user_meta($this->user_id, '_mcpnh_settings', true);
        if (!is_array($settings)) {
            $settings = [];
        }
        return array_merge([
            'expert_mode' => false,
            'default_limit' => 10,
        ], $settings);
    }

    /**
     * Tool definition for MCP registration
     */
    public static function get_tool_definition(): array {
        return [
            'name' => 'ml_help',
            'description' => 'Interactive MaryLink assistant with expert commands. Navigation, search, best-of, and contextual recommendations.',
            'category' => 'MaryLink Assistant',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['menu', 'search', 'for_me', 'best', 'settings', 'reco'],
                        'default' => 'menu',
                        'description' => 'Navigation mode',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search query (for mode=search)',
                    ],
                    'context_text' => [
                        'type' => 'string',
                        'description' => 'Chat context for recommendations (mode=reco)',
                    ],
                    'space_id' => [
                        'type' => 'integer',
                        'description' => 'Filter by space ID',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'default' => 10,
                        'description' => 'Max results',
                    ],
                    'period' => [
                        'type' => 'string',
                        'enum' => ['7d', '30d', '90d', '1y'],
                        'default' => '30d',
                        'description' => 'Time period for best-of',
                    ],
                    'filters' => [
                        'type' => 'object',
                        'description' => 'Additional filters for reco mode',
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'space_id' => ['type' => 'integer'],
                            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                    // Settings mode
                    'action' => [
                        'type' => 'string',
                        'enum' => ['get', 'set'],
                        'description' => 'Settings action (mode=settings)',
                    ],
                    'setting' => [
                        'type' => 'string',
                        'description' => 'Setting key to update',
                    ],
                    'value' => [
                        'description' => 'Setting value',
                    ],
                ],
            ],
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
        ];
    }
}
