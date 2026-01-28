<?php
/**
 * ml_assist - Intelligent orchestrator tool
 *
 * Analyzes user intent, detects entities, suggests tools with scoring,
 * and can auto-execute best match. Main entry point for AI assistance.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Tools;

use MCP_No_Headless\MCP\Core\Tool_Response;
use MCP_No_Headless\MCP\Core\Services\Entity_Detector;
use MCP_No_Headless\MCP\Core\Services\Business_Context_Service;

class Assist {

    const TOOL_NAME = 'ml_assist';
    const VERSION = '3.0.0';

    const MODE_SUGGEST = 'suggest';
    const MODE_EXECUTE = 'execute';
    const MODE_AUTO = 'auto'; // Execute if confidence > threshold, otherwise suggest

    const CONFIDENCE_THRESHOLD = 0.75;
    const MAX_SUGGESTIONS = 5;

    // Intent categories
    const INTENT_CREATE = 'create';
    const INTENT_TRANSFORM = 'transform';
    const INTENT_ANALYZE = 'analyze';
    const INTENT_SEARCH = 'search';
    const INTENT_MANAGE = 'manage';
    const INTENT_UNKNOWN = 'unknown';

    /**
     * Execute ml_assist
     *
     * @param array $args Tool arguments
     * @param int $user_id Current user ID
     * @return array Suggestions or execution result
     */
    public static function execute(array $args, int $user_id): array {
        $start_time = microtime(true);

        if ($user_id <= 0) {
            return Tool_Response::auth_error('Authentification requise pour ml_assist');
        }

        // Parse arguments - accept catalog names (context, action) and legacy names (query, mode)
        $query = trim($args['context'] ?? $args['query'] ?? '');
        $content = $args['content'] ?? null;
        $source_id = isset($args['source_id']) ? (int) $args['source_id'] : null;
        $space_id = isset($args['space_id']) ? (int) $args['space_id'] : null;
        $mode = $args['action'] ?? $args['mode'] ?? self::MODE_SUGGEST;
        // Catalog compat: action=apply => execute; action=create => create blueprint
        $wants_create = false;
        $wants_labels = false;
        if ($mode === 'apply') { $mode = self::MODE_EXECUTE; }
        if ($mode === 'create') { $wants_create = true; $mode = self::MODE_SUGGEST; }
        if ($mode === 'labels') { $wants_labels = true; }
        $context = $args['extra_context'] ?? [];
        $limit = min((int) ($args['limit'] ?? self::MAX_SUGGESTIONS), 10);

        if (empty($query) && !$content && !$source_id) {
            return Tool_Response::validation_error(
                'Input requis',
                ['query' => 'Un parmi query, content, source_id est obligatoire']
            );
        }

        // Analyze intent
        $intent = self::analyze_intent($query, $content);

        // Detect entities in query/content
        $entities = self::detect_entities($query . ' ' . ($content ?? ''), $user_id);

        // Get user context
        $user_context = self::get_user_context($user_id, $space_id);

        // Find relevant tools
        $suggestions = self::find_relevant_tools($intent, $query, $entities, $user_context, $limit);

        // Also search prompt publications (label=prompt)
        $prompt_suggestions = self::find_relevant_prompts($query, $limit);
        $suggestions = array_merge($suggestions, $prompt_suggestions);

        // Calculate confidence scores
        $suggestions = self::score_suggestions($suggestions, $intent, $query, $entities, $user_context);

        // Sort by score
        usort($suggestions, fn($a, $b) => $b['score']['total'] <=> $a['score']['total']);

        // Limit results
        $suggestions = array_slice($suggestions, 0, $limit);


        // If user asked for labels, return available labels for the context
        if ($wants_labels) {
            $labels = get_terms([
                'taxonomy' => 'publication_label',
                'hide_empty' => false,
            ]);
            $tags = get_terms([
                'taxonomy' => 'publication_tag', 
                'hide_empty' => false,
            ]);
            
            return [
                'success' => true,
                'mode' => 'labels',
                'labels' => array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count], $labels),
                'tags' => array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count], $tags),
                'hint' => 'Use labels for content type (content, tool, prompt, style) and tags for categorization',
            ];
        }

        // If user asked for create, return a blueprint they can save as a prompt/tool
        if ($wants_create) {
            return [
                'success' => true,
                'mode' => 'create',
                'blueprint' => [
                    'kind' => 'prompt',
                    'title' => $query ? wp_trim_words($query, 6, '') : 'Nouveau prompt',
                    'prompt_skeleton' => "Rôle : ...\nObjectif : ...\nEntrées : ...\nContraintes : ...\nSortie attendue : ...",
                    'next_step' => "Sauvegarder via ml_save(type=prompt, status=draft/publish) puis exécuter via ml_run(prompt=...) ou ml_run(tool_id=...)",
                ],
            ];
        }

        // Determine if we should auto-execute
        $best_match = $suggestions[0] ?? null;
        $should_execute = false;

        if ($mode === self::MODE_EXECUTE) {
            $should_execute = true;
        } elseif ($mode === self::MODE_AUTO && $best_match) {
            $should_execute = $best_match['score']['total'] >= self::CONFIDENCE_THRESHOLD;
        }

        // Execute if requested and confident
        if ($should_execute && $best_match) {
            $execution_result = self::execute_suggestion($best_match, $query, $content, $source_id, $space_id, $user_id, $context);

            $latency_ms = round((microtime(true) - $start_time) * 1000);

            return [
                'success' => true,
                'mode' => 'executed',
                'intent' => $intent,
                'entities' => $entities,
                'executed_tool' => $best_match,
                'result' => $execution_result,
                'alternatives' => array_slice($suggestions, 1, 3),
                'latency_ms' => $latency_ms,
            ];
        }

        // Return suggestions
        $latency_ms = round((microtime(true) - $start_time) * 1000);

        return [
            'success' => true,
            'mode' => 'suggestions',
            'intent' => $intent,
            'entities' => $entities,
            'suggestions' => $suggestions,
            'best_match' => $best_match,
            'auto_execute_available' => $best_match && $best_match['score']['total'] >= self::CONFIDENCE_THRESHOLD,
            'hint' => self::generate_hint($intent, $suggestions, $entities),
            'latency_ms' => $latency_ms,
        ];
    }

    /**
     * Analyze user intent from query
     */
    private static function analyze_intent(string $query, ?string $content): array {
        $query_lower = mb_strtolower($query);

        $intent = [
            'primary' => self::INTENT_UNKNOWN,
            'confidence' => 0.5,
            'keywords' => [],
        ];

        // Create intent patterns
        $create_patterns = ['créer', 'create', 'écrire', 'write', 'rédiger', 'générer', 'generate', 'nouveau', 'new'];
        $transform_patterns = ['transformer', 'transform', 'convertir', 'convert', 'reformuler', 'rephrase', 'résumer', 'summarize', 'traduire', 'translate', 'améliorer', 'improve'];
        $analyze_patterns = ['analyser', 'analyze', 'évaluer', 'evaluate', 'vérifier', 'check', 'comprendre', 'understand', 'expliquer', 'explain'];
        $search_patterns = ['chercher', 'search', 'trouver', 'find', 'rechercher', 'look for', 'où', 'where', 'qui', 'who', 'quoi', 'what'];
        $manage_patterns = ['gérer', 'manage', 'modifier', 'edit', 'supprimer', 'delete', 'organiser', 'organize', 'déplacer', 'move'];

        // Check patterns
        foreach ($create_patterns as $pattern) {
            if (str_contains($query_lower, $pattern)) {
                $intent['primary'] = self::INTENT_CREATE;
                $intent['keywords'][] = $pattern;
                $intent['confidence'] = 0.8;
                break;
            }
        }

        if ($intent['primary'] === self::INTENT_UNKNOWN) {
            foreach ($transform_patterns as $pattern) {
                if (str_contains($query_lower, $pattern)) {
                    $intent['primary'] = self::INTENT_TRANSFORM;
                    $intent['keywords'][] = $pattern;
                    $intent['confidence'] = 0.85;
                    break;
                }
            }
        }

        if ($intent['primary'] === self::INTENT_UNKNOWN) {
            foreach ($analyze_patterns as $pattern) {
                if (str_contains($query_lower, $pattern)) {
                    $intent['primary'] = self::INTENT_ANALYZE;
                    $intent['keywords'][] = $pattern;
                    $intent['confidence'] = 0.8;
                    break;
                }
            }
        }

        if ($intent['primary'] === self::INTENT_UNKNOWN) {
            foreach ($search_patterns as $pattern) {
                if (str_contains($query_lower, $pattern)) {
                    $intent['primary'] = self::INTENT_SEARCH;
                    $intent['keywords'][] = $pattern;
                    $intent['confidence'] = 0.75;
                    break;
                }
            }
        }

        if ($intent['primary'] === self::INTENT_UNKNOWN) {
            foreach ($manage_patterns as $pattern) {
                if (str_contains($query_lower, $pattern)) {
                    $intent['primary'] = self::INTENT_MANAGE;
                    $intent['keywords'][] = $pattern;
                    $intent['confidence'] = 0.7;
                    break;
                }
            }
        }

        // If content is provided, likely transform intent
        if ($content && $intent['primary'] === self::INTENT_UNKNOWN) {
            $intent['primary'] = self::INTENT_TRANSFORM;
            $intent['confidence'] = 0.6;
        }

        return $intent;
    }

    /**
     * Detect business entities in text
     * PR3: Uses Entity_Detector service for comprehensive detection
     */
    private static function detect_entities(string $text, int $user_id): array {
        // PR3: Use Entity_Detector service if available
        if (class_exists(Entity_Detector::class)) {
            $detected = Entity_Detector::detect($text, $user_id);

            // Normalize format for backward compatibility
            return [
                'clients' => $detected['entities']['clients'] ?? [],
                'projects' => $detected['entities']['projects'] ?? [],
                'products' => [],
                'mentions' => $detected['entities']['users'] ?? [],
                'tags' => array_column($detected['entities']['tags'] ?? [], 'value'),
                'dates' => $detected['entities']['dates'] ?? [],
                'amounts' => $detected['entities']['amounts'] ?? [],
                '_raw' => $detected,
            ];
        }

        // Fallback: legacy detection
        $entities = [
            'clients' => [],
            'projects' => [],
            'products' => [],
            'mentions' => [],
            'tags' => [],
        ];

        // Extract @mentions
        preg_match_all('/@(\w+)/', $text, $mentions);
        if (!empty($mentions[1])) {
            foreach ($mentions[1] as $mention) {
                $user = get_user_by('login', $mention);
                if ($user) {
                    $entities['mentions'][] = [
                        'type' => 'user',
                        'id' => $user->ID,
                        'name' => $user->display_name,
                    ];
                }
            }
        }

        // Extract #tags
        preg_match_all('/#(\w+)/', $text, $tags);
        if (!empty($tags[1])) {
            $entities['tags'] = $tags[1];
        }

        // Detect clients (from user's accessible clients)
        $user_clients = self::get_user_clients($user_id);
        foreach ($user_clients as $client) {
            if (stripos($text, $client['name']) !== false) {
                $entities['clients'][] = $client;
            }
        }

        // Detect projects
        $user_projects = self::get_user_projects($user_id);
        foreach ($user_projects as $project) {
            if (stripos($text, $project['name']) !== false) {
                $entities['projects'][] = $project;
            }
        }

        return $entities;
    }

    /**
     * Get user's clients
     */
    private static function get_user_clients(int $user_id): array {
        // This will be enhanced by Entity_Detector service
        global $wpdb;

        $clients = $wpdb->get_results($wpdb->prepare(
            "SELECT ID as id, post_title as name FROM {$wpdb->posts}
             WHERE post_type = 'ml_client' AND post_status = 'publish'
             AND (post_author = %d OR ID IN (
                 SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_ml_accessible_users' AND meta_value LIKE %s
             ))
             LIMIT 50",
            $user_id, '%' . $user_id . '%'
        ), ARRAY_A);

        return $clients ?: [];
    }

    /**
     * Get user's projects
     */
    private static function get_user_projects(int $user_id): array {
        global $wpdb;

        $projects = $wpdb->get_results($wpdb->prepare(
            "SELECT ID as id, post_title as name FROM {$wpdb->posts}
             WHERE post_type = 'ml_project' AND post_status = 'publish'
             AND post_author = %d
             LIMIT 50",
            $user_id
        ), ARRAY_A);

        return $projects ?: [];
    }

    /**
     * Get user context for tool matching
     */
    private static function get_user_context(int $user_id, ?int $space_id): array {
        $context = [
            'user_id' => $user_id,
            'space_id' => $space_id,
            'recent_tools' => [],
            'frequent_tools' => [],
            'preferences' => [],
        ];

        // Recent tools
        $recent = get_user_meta($user_id, 'ml_recent_tools', true) ?: [];
        $context['recent_tools'] = array_slice($recent, 0, 5);

        // User preferences
        $context['preferences'] = [
            'language' => get_user_locale($user_id),
            'default_space' => get_user_meta($user_id, 'ml_default_space', true),
        ];

        // Space-specific tools if space_id provided
        if ($space_id) {
            $space_tools = get_option("ml_space_{$space_id}_tools", []);
            $context['space_tools'] = $space_tools;
        }

        return $context;
    }

    /**
     * Find relevant tools based on intent
     */
    private static function find_relevant_tools(array $intent, string $query, array $entities, array $user_context, int $limit): array {
        $tools = [];

        // Map intent to tool categories
        $category_map = [
            self::INTENT_CREATE => ['redaction', 'generation', 'creation'],
            self::INTENT_TRANSFORM => ['transformation', 'reformulation', 'traduction'],
            self::INTENT_ANALYZE => ['analyse', 'evaluation', 'verification'],
            self::INTENT_SEARCH => ['recherche'],
            self::INTENT_MANAGE => ['gestion', 'organisation'],
        ];

        $categories = $category_map[$intent['primary']] ?? [];

        // Build query
        $args = [
            'post_type' => 'ml_tool',
            'post_status' => 'publish',
            'posts_per_page' => $limit * 3, // Get more, will filter
            'orderby' => 'meta_value_num',
            'meta_key' => '_ml_tool_usage_count',
            'order' => 'DESC',
        ];

        // Search by query
        if (!empty($query)) {
            $args['s'] = $query;
        }

        // Filter by categories
        if (!empty($categories)) {
            $args['meta_query'][] = [
                'key' => '_ml_tool_category',
                'value' => $categories,
                'compare' => 'IN',
            ];
        }

        $wp_query = new \WP_Query($args);

        foreach ($wp_query->posts as $post) {
            $tools[] = self::format_tool_suggestion($post);
        }


        // Find prompts (publication_label=prompt) as first-class suggestions
        $prompt_args = [
            'post_type' => 'publication',
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => $limit * 2,
            'orderby' => 'date',
            'order' => 'DESC',
            'tax_query' => [
                [
                    'taxonomy' => 'publication_label',
                    'field' => 'slug',
                    'terms' => ['prompt'],
                ],
            ],
        ];

        if (!empty($query)) {
            $prompt_args['s'] = $query;
        }

        $prompt_query = new \WP_Query($prompt_args);
        foreach ($prompt_query->posts as $p) {
            $tools[] = [
                'id' => $p->ID,
                'kind' => 'prompt',
                'name' => $p->post_title,
                'slug' => $p->post_name,
                'description' => wp_trim_words($p->post_content, 20),
                'category' => 'prompt',
                'icon' => '✨',
                'usage_count' => 0,
                'avg_rating' => 0,
                'rating_count' => 0,
                'requires_input' => true,
                'space_id' => (int) get_post_meta($p->ID, '_ml_space_id', true),
                'prompt_text' => get_post_field('post_content', $p->ID, 'raw'),
            ];
        }

        // Add space-specific tools
        if (!empty($user_context['space_tools'])) {
            foreach ($user_context['space_tools'] as $tool_id) {
                $post = get_post($tool_id);
                if ($post && !in_array($tool_id, array_column($tools, 'id'))) {
                    array_unshift($tools, self::format_tool_suggestion($post));
                }
            }
        }

        // Add recently used tools
        foreach ($user_context['recent_tools'] as $recent) {
            $tool_id = $recent['tool_id'] ?? $recent;
            if (!in_array($tool_id, array_column($tools, 'id'))) {
                $post = get_post($tool_id);
                if ($post) {
                    $tools[] = self::format_tool_suggestion($post);
                }
            }
        }

        return $tools;
    }


    /**
     * Find prompt publications (publication_label=prompt) as first-class suggestions.
     */
    private static function find_relevant_prompts(string $query, int $limit = 10, ?int $space_id = null): array {
        $suggestions = [];

        $args = [
            'post_type' => 'publication',
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => max(1, $limit),
            'orderby' => 'date',
            'order' => 'DESC',
            'tax_query' => [
                [
                    'taxonomy' => 'publication_label',
                    'field' => 'slug',
                    'terms' => ['prompt'],
                ],
            ],
        ];

        if (!empty($query)) {
            $args['s'] = $query;
        }

        if (!empty($space_id)) {
            $args['post_parent'] = (int) $space_id;
        }

        $q = new \WP_Query($args);
        foreach (($q->posts ?: []) as $p) {
            $sid = (int) ($p->post_parent ?? 0);
            if ($sid <= 0) {
                $sid = (int) get_post_meta($p->ID, '_ml_space_id', true);
            }

            $suggestions[] = [
                'id' => $p->ID,
                'kind' => 'prompt',
                'name' => $p->post_title,
                'slug' => $p->post_name,
                'description' => wp_trim_words($p->post_content, 20),
                'category' => 'prompt',
                'icon' => '✨',
                'usage_count' => 0,
                'avg_rating' => 0,
                'rating_count' => 0,
                'requires_input' => true,
                'space_id' => $sid,
                'prompt_text' => get_post_field('post_content', $p->ID, 'raw'),
            ];
        }

        return $suggestions;
    }

    /**
     * Format tool as suggestion
     */
    private static function format_tool_suggestion($post): array {
        return [
            'id' => $post->ID,
            'name' => $post->post_title,
            'slug' => $post->post_name,
            'description' => wp_trim_words($post->post_content, 20),
            'category' => get_post_meta($post->ID, '_ml_tool_category', true),
            'icon' => get_post_meta($post->ID, '_ml_tool_icon', true),
            'usage_count' => (int) get_post_meta($post->ID, '_ml_tool_usage_count', true),
            'avg_rating' => (float) get_post_meta($post->ID, '_ml_tool_avg_rating', true),
            'requires_input' => (bool) get_post_meta($post->ID, '_ml_tool_requires_input', true),
        ];
    }

    /**
     * Score suggestions based on multiple factors
     */
    private static function score_suggestions(array $suggestions, array $intent, string $query, array $entities, array $user_context): array {
        $query_lower = mb_strtolower($query);

        foreach ($suggestions as &$tool) {
            $scores = [
                'relevance' => 0,      // How relevant to query
                'quality' => 0,        // Tool quality (ratings, usage)
                'recency' => 0,        // Recently used by user
                'context' => 0,        // Context fit (space, entities)
            ];

            // Relevance score (0-1)
            $name_lower = mb_strtolower($tool['name']);
            $desc_lower = mb_strtolower($tool['description']);

            if (str_contains($name_lower, $query_lower)) {
                $scores['relevance'] = 0.9;
            } elseif (str_contains($desc_lower, $query_lower)) {
                $scores['relevance'] = 0.6;
            } else {
                // Partial match
                $query_words = explode(' ', $query_lower);
                $matches = 0;
                foreach ($query_words as $word) {
                    if (strlen($word) > 2 && (str_contains($name_lower, $word) || str_contains($desc_lower, $word))) {
                        $matches++;
                    }
                }
                $scores['relevance'] = min(0.5, $matches * 0.15);
            }

            // Intent match bonus
            if (!empty($intent['keywords'])) {
                foreach ($intent['keywords'] as $keyword) {
                    if (str_contains($name_lower, $keyword) || str_contains($desc_lower, $keyword)) {
                        $scores['relevance'] = min(1.0, $scores['relevance'] + 0.2);
                        break;
                    }
                }
            }

            // Quality score (0-1)
            $rating = $tool['avg_rating'] ?? 0;
            $usage = $tool['usage_count'] ?? 0;

            // Bayesian average for quality
            $global_avg_rating = 3.5;
            $min_votes = 5;
            $bayesian_rating = (($rating * $usage) + ($global_avg_rating * $min_votes)) / ($usage + $min_votes);
            $scores['quality'] = $bayesian_rating / 5;

            // Recency score (0-1)
            $recent_tool_ids = array_column($user_context['recent_tools'], 'tool_id');
            if (in_array($tool['id'], $recent_tool_ids)) {
                $position = array_search($tool['id'], $recent_tool_ids);
                $scores['recency'] = 1 - ($position * 0.2);
            }

            // Context score (0-1)
            if (!empty($user_context['space_tools']) && in_array($tool['id'], $user_context['space_tools'])) {
                $scores['context'] = 0.8;
            }

            // Entity match bonus
            if (!empty($entities['clients']) || !empty($entities['projects'])) {
                $scores['context'] = min(1.0, $scores['context'] + 0.2);
            }

            // Calculate total score (weighted average)
            $weights = [
                'relevance' => 0.4,
                'quality' => 0.25,
                'recency' => 0.2,
                'context' => 0.15,
            ];

            $total = 0;
            foreach ($scores as $key => $value) {
                $total += $value * $weights[$key];
            }

            $tool['score'] = [
                'total' => round($total, 3),
                'breakdown' => array_map(fn($v) => round($v, 2), $scores),
            ];
        }

        return $suggestions;
    }

    /**
     * Execute the best suggestion
     */
    private static function execute_suggestion(
        array $suggestion,
        string $query,
        ?string $content,
        ?int $source_id,
        ?int $space_id,
        int $user_id,
        array $context
    ): array {
        $run_args = [
            'context' => $context,
        ];

        // Execute tool or prompt
        if (($suggestion['kind'] ?? 'tool') === 'prompt') {
            $run_args['prompt'] = $suggestion['prompt_text'] ?? '';
        } else {
            $run_args['tool_id'] = $suggestion['id'];
        }

        // Determine input
        if ($content) {
            $run_args['input'] = $content;
        } elseif ($source_id) {
            $run_args['source_id'] = $source_id;
        } elseif ($suggestion['requires_input']) {
            // Use query as input if tool requires input
            $run_args['input'] = $query;
        }

        // Add space context
        if ($space_id) {
            $run_args['context']['space_id'] = $space_id;
        }

        return Run::execute($run_args, $user_id);
    }

    /**
     * Generate helpful hint for the user
     */
    private static function generate_hint(array $intent, array $suggestions, array $entities): string {
        if (empty($suggestions)) {
            return "No tools found matching your request. Try being more specific or browse available tools with ml_find type:tool";
        }

        $best = $suggestions[0];
        $hint = "Best match: \"{$best['name']}\"";

        if ($best['score']['total'] >= self::CONFIDENCE_THRESHOLD) {
            $hint .= " (high confidence). Use mode:'execute' to run automatically.";
        } else {
            $hint .= ". Review suggestions and use ml_run with tool_id:{$best['id']} to execute.";
        }

        if (!empty($entities['clients'])) {
            $client = $entities['clients'][0];
            $hint .= " Context: {$client['name']} detected.";
        }

        return $hint;
    }

    /**
     * Return error response (delegates to Tool_Response)
     */
    private static function error(string $code, string $message): array {
        return Tool_Response::error($code, $message);
    }
}
