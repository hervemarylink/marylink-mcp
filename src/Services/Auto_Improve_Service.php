<?php
/**
 * Auto Improve Service - L'IA qui améliore l'IA
 *
 * Analyse les prompts sous-performants et génère des améliorations
 * basées sur les patterns des top performers.
 *
 * @package MCP_No_Headless
 * @since 2.0.0
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\Ops\Audit_Logger;
use MCP_No_Headless\Picasso\Meta_Keys;

class Auto_Improve_Service {

    /**
     * User ID for permission checks
     */
    private int $user_id;

    /**
     * Session storage key prefix
     */
    private const SESSION_PREFIX = 'ml_improve_';

    /**
     * Session TTL (5 minutes)
     */
    private const SESSION_TTL = 300;

    /**
     * Minimum top performers to analyze
     */
    private const MIN_BENCHMARK_SIZE = 3;

    /**
     * Constructor
     */
    public function __construct(int $user_id) {
        $this->user_id = $user_id;
    }

    /**
     * Check if service is available
     */
    public static function is_available(): bool {
        // Requires Picasso + AI Engine
        return class_exists('Picasso_Backend\Utils\Publication') 
            && class_exists('Meow_MWAI_Core');
    }

    /**
     * Stage 1: Analyze - Diagnostic du prompt vs benchmark
     *
     * @param int $publication_id Publication to analyze
     * @param string $benchmark_scope Scope: space|organization|global
     * @param array $improvement_goals Optional specific goals
     * @return array Analysis result
     */
    public function analyze(int $publication_id, string $benchmark_scope = 'space', array $improvement_goals = []): array {
        // 1. Get the publication
        $publication = $this->get_publication($publication_id);
        if (!$publication) {
            return ['ok' => false, 'error' => 'publication_not_found'];
        }

        // 2. Check permissions (PATCH 2: anti-leak - neutral response)
        if (!$this->can_analyze($publication_id)) {
            return ['ok' => false, 'error' => 'publication_not_found'];
        }

        // 3. Get current metrics
        $current_metrics = $this->get_publication_metrics($publication_id);

        // 4. Find benchmark (top performers in scope)
        $benchmark = $this->find_benchmark($publication, $benchmark_scope);
        if (count($benchmark['top_performers']) < self::MIN_BENCHMARK_SIZE) {
            return [
                'ok' => false,
                'error' => 'insufficient_benchmark',
                'message' => 'Pas assez de publications de référence dans ce scope. Essayez "organization" ou "global".',
                'found' => count($benchmark['top_performers']),
                'required' => self::MIN_BENCHMARK_SIZE,
            ];
        }

        // 5. AI Analysis - Compare with top performers
        $diagnosis = $this->ai_diagnose($publication, $benchmark);

        // 6. Calculate improvement potential
        $improvement_potential = $this->calculate_improvement_potential(
            $current_metrics['quality_score'],
            $benchmark['average_score']
        );

        return [
            'ok' => true,
            'publication_id' => $publication_id,
            'publication_title' => $publication['title'],
            'current_score' => $current_metrics['quality_score'],
            'current_metrics' => $current_metrics,
            'benchmark' => [
                'scope' => $benchmark_scope,
                'top_performers' => array_map(function($p) {
                    return [
                        'id' => $p['id'],
                        'title' => $p['title'],
                        'score' => $p['quality_score'],
                    ];
                }, array_slice($benchmark['top_performers'], 0, 5)),
                'average_score_in_scope' => $benchmark['average_score'],
                'total_in_scope' => $benchmark['total_count'],
            ],
            'diagnosis' => $diagnosis,
            'improvement_potential' => $improvement_potential,
            'recommended_goals' => $this->recommend_goals($diagnosis),
        ];
    }

    /**
     * Stage 2: Suggest - Generate improvement suggestions
     *
     * @param int $publication_id Publication to improve
     * @param string $benchmark_scope Scope for reference
     * @param array $improvement_goals Specific improvement axes
     * @return array Suggestions with session_id
     */
    public function suggest(int $publication_id, string $benchmark_scope = 'space', array $improvement_goals = []): array {
        // 1. Run analysis first
        $analysis = $this->analyze($publication_id, $benchmark_scope, $improvement_goals);
        if (!$analysis['ok']) {
            return $analysis;
        }

        // 2. Get the publication content
        $publication = $this->get_publication($publication_id);
        $content = $publication['content'];

        // 3. Get patterns from top performers
        $benchmark = $this->find_benchmark($publication, $benchmark_scope);
        $patterns = $this->extract_patterns($benchmark['top_performers']);

        // 4. Generate suggestions using AI
        $suggestions = $this->ai_generate_suggestions(
            $publication,
            $analysis['diagnosis'],
            $patterns,
            $improvement_goals ?: $analysis['recommended_goals']
        );

        // 5. Generate improved version preview
        $improved_content = $this->apply_suggestions_to_content($content, $suggestions);
        $estimated_new_score = $this->estimate_new_score(
            $analysis['current_score'],
            $suggestions
        );

        // 6. Create session for commit
        $session_id = $this->create_session([
            'publication_id' => $publication_id,
            'original_content' => $content,
            'improved_content' => $improved_content,
            'suggestions' => $suggestions,
            'analysis' => $analysis,
        ]);

        return [
            'ok' => true,
            'session_id' => $session_id,
            'expires_at' => gmdate('c', time() + self::SESSION_TTL),
            'publication_id' => $publication_id,
            'current_score' => $analysis['current_score'],
            'suggestions' => $suggestions,
            'preview' => [
                'improved_content' => $improved_content,
                'estimated_new_score' => $estimated_new_score,
                'changes_summary' => $this->summarize_changes($content, $improved_content),
            ],
            'patterns_applied' => array_column($suggestions, 'pattern_source'),
        ];
    }

    /**
     * Stage 3: Apply - Commit the improvements
     *
     * @param string $session_id Session from suggest stage
     * @param array $options Apply options
     * @return array Result
     */
    public function apply(string $session_id, array $options = []): array {
        // 1. Retrieve session
        $session = $this->get_session($session_id);
        if (!$session) {
            return ['ok' => false, 'error' => 'session_expired_or_invalid'];
        }

        $publication_id = $session['publication_id'];

        // 2. Check permissions again (PATCH 2: anti-leak - neutral response)
        if (!$this->can_edit($publication_id)) {
            return ['ok' => false, 'error' => 'publication_not_found'];
        }

        // 3. Determine what to apply
        // PATCH 3: Default mode = new_version (gouvernance)
        $apply_mode = $options['mode'] ?? 'new_version';
        $selected_suggestions = $options['selected_suggestions'] ?? 'all';
        $warnings = [];

        // PATCH 4a: Update reserved for admins only
        if ($apply_mode === 'update' && !current_user_can('manage_options')) {
            $apply_mode = 'new_version';
            $warnings[] = 'Mode update non autorisé (non-admin): new_version appliqué.';
        }

        // PATCH 4b: Never overwrite approved publications
        if ($apply_mode === 'update' && $this->is_approved_publication($publication_id)) {
            $apply_mode = 'new_version';
            $warnings[] = 'Publication approuvée: update interdit, new_version créée.';
        }

        // 4. Build final content
        if ($selected_suggestions === 'all') {
            $final_content = $session['improved_content'];
        } else {
            // Apply only selected suggestions
            $final_content = $this->apply_selected_suggestions(
                $session['original_content'],
                $session['suggestions'],
                $selected_suggestions
            );
        }

        // 5. Apply based on mode
        switch ($apply_mode) {
            case 'update':
                $result = $this->update_publication($publication_id, $final_content, $session);
                break;

            case 'new_version':
                $result = $this->create_version($publication_id, $final_content, $session);
                break;

            case 'new_publication':
                $target_space = $options['target_space_id'] ?? null;
                $result = $this->create_new_publication($publication_id, $final_content, $session, $target_space);
                break;

            default:
                return ['ok' => false, 'error' => 'invalid_apply_mode'];
        }

        // 6. Cleanup session
        $this->delete_session($session_id);

        // 7. Log the improvement
        Audit_Logger::log([
            'tool_name' => 'ml_auto_improve',
            'user_id' => $this->user_id,
            'result' => $result['ok'] ? 'success' : 'error',
            'extra' => [
                'stage' => 'apply',
                'publication_id' => $publication_id,
                'mode' => $apply_mode,
                'suggestions_applied' => is_array($selected_suggestions) ? count($selected_suggestions) : 'all',
            ],
        ]);

        // Add warnings if any governance rules were triggered
        if (!empty($warnings)) {
            $result['warnings'] = $warnings;
        }

        return $result;
    }

    // =========================================================================
    // PRIVATE METHODS - Analysis
    // =========================================================================

    /**
     * Get publication with content
     */
    private function get_publication(int $publication_id): ?array {
        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'ai_publication') {
            return null;
        }

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'space_id' => get_post_meta($post->ID, '_space_id', true),
            'publication_type' => get_post_meta($post->ID, '_publication_type', true),
            'canvas_type' => get_post_meta($post->ID, '_canvas_type', true),
            'author_id' => $post->post_author,
        ];
    }

    /**
     * Get publication metrics
     */
    private function get_publication_metrics(int $publication_id): array {
        return [
            'quality_score' => (float) get_post_meta($publication_id, '_ml_quality_score', true) ?: 0,
            'user_rating' => (float) get_post_meta($publication_id, '_ml_avg_user_rating', true) ?: 0,
            'user_rating_count' => (int) get_post_meta($publication_id, '_ml_user_rating_count', true),
            'expert_rating' => (float) get_post_meta($publication_id, '_average_expert_reviews', true) ?: 0,
            'favorites_count' => (int) get_post_meta($publication_id, '_ml_favorites_count', true),
            'usage_count' => (int) get_post_meta($publication_id, '_usage_count', true),
            'views_count' => Meta_Keys::get_views_count($publication_id),
        ];
    }

    /**
     * Find benchmark publications (top performers)
     */
    private function find_benchmark(array $publication, string $scope): array {
        $args = [
            'post_type' => 'ai_publication',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'meta_key' => '_ml_quality_score',
            'orderby' => 'meta_value_num',
            'order' => 'DESC',
            'post__not_in' => [$publication['id']],
        ];

        // Apply scope filters
        switch ($scope) {
            case 'space':
                $args['meta_query'] = [
                    [
                        'key' => '_space_id',
                        'value' => $publication['space_id'],
                    ],
                ];
                break;

            case 'organization':
                // Same publication type across all spaces
                $args['meta_query'] = [
                    [
                        'key' => '_publication_type',
                        'value' => $publication['publication_type'],
                    ],
                ];
                break;

            case 'global':
                // All publications of same type
                $args['meta_query'] = [
                    [
                        'key' => '_publication_type',
                        'value' => $publication['publication_type'],
                    ],
                ];
                break;
        }

        // Filter by minimum score (top 25%)
        $args['meta_query'][] = [
            'key' => '_ml_quality_score',
            'value' => 3.5,
            'compare' => '>=',
            'type' => 'DECIMAL',
        ];

        $query = new \WP_Query($args);
        $top_performers = [];
        $total_score = 0;

        foreach ($query->posts as $post) {
            $score = (float) get_post_meta($post->ID, '_ml_quality_score', true);
            $top_performers[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'quality_score' => $score,
                'publication_type' => get_post_meta($post->ID, '_publication_type', true),
            ];
            $total_score += $score;
        }

        $count = count($top_performers);

        return [
            'top_performers' => $top_performers,
            'average_score' => $count > 0 ? round($total_score / $count, 2) : 0,
            'total_count' => $count,
        ];
    }

    /**
     * AI Diagnosis - Analyze prompt vs top performers
     */
    private function ai_diagnose(array $publication, array $benchmark): array {
        $top_contents = array_map(function($p) {
            return "### " . $p['title'] . " (Score: " . $p['quality_score'] . ")\n" . substr($p['content'], 0, 1500);
        }, array_slice($benchmark['top_performers'], 0, 3));

        $prompt = <<<PROMPT
Tu es un expert en prompt engineering. Analyse ce prompt et compare-le aux top performers.

## PROMPT À ANALYSER
Titre: {$publication['title']}
Score actuel: {$publication['quality_score']}

Contenu:
{$publication['content']}

## TOP PERFORMERS (références)
{$this->implode_contents($top_contents)}

## ANALYSE DEMANDÉE
Réponds en JSON strict avec cette structure:
{
  "strengths": ["point fort 1", "point fort 2"],
  "weaknesses": [
    {"issue": "description du problème", "location": "ligne X ou section Y", "severity": "high|medium|low"}
  ],
  "patterns_missing": [
    {"pattern": "nom du pattern", "description": "ce que font les top performers", "example": "exemple concret"}
  ],
  "quick_wins": ["amélioration rapide 1", "amélioration rapide 2"],
  "structural_issues": ["problème de structure 1"]
}
PROMPT;

        $response = $this->call_ai($prompt);
        
        try {
            $diagnosis = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Fallback parsing
                $diagnosis = $this->parse_diagnosis_fallback($response);
            }
        } catch (\Exception $e) {
            $diagnosis = [
                'strengths' => ['Analyse automatique non disponible'],
                'weaknesses' => [],
                'patterns_missing' => [],
                'quick_wins' => [],
                'structural_issues' => [],
            ];
        }

        return $diagnosis;
    }

    /**
     * Extract patterns from top performers
     */
    private function extract_patterns(array $top_performers): array {
        $patterns = [];

        // Analyze structural patterns
        foreach ($top_performers as $pub) {
            $content = $pub['content'];

            // Check for common patterns
            if (preg_match('/<output_format>|## Output|### Format/i', $content)) {
                $patterns['output_format'] = ($patterns['output_format'] ?? 0) + 1;
            }
            if (preg_match('/<constraints>|## Contraintes|### À éviter/i', $content)) {
                $patterns['constraints'] = ($patterns['constraints'] ?? 0) + 1;
            }
            if (preg_match('/<examples?>|## Exemples?|### Exemple/i', $content)) {
                $patterns['examples'] = ($patterns['examples'] ?? 0) + 1;
            }
            if (preg_match('/<context>|## Contexte|### Background/i', $content)) {
                $patterns['context_section'] = ($patterns['context_section'] ?? 0) + 1;
            }
            if (preg_match('/\d+\s*(mots?|words?|caractères?|lignes?)/i', $content)) {
                $patterns['length_constraint'] = ($patterns['length_constraint'] ?? 0) + 1;
            }
            if (preg_match('/step\s*\d|étape\s*\d|1\.|2\.|3\./i', $content)) {
                $patterns['step_by_step'] = ($patterns['step_by_step'] ?? 0) + 1;
            }
            if (preg_match('/Tu es|You are|Act as|Agis comme/i', $content)) {
                $patterns['role_definition'] = ($patterns['role_definition'] ?? 0) + 1;
            }
        }

        $total = count($top_performers);
        $common_patterns = [];

        foreach ($patterns as $pattern => $count) {
            $percentage = ($count / $total) * 100;
            if ($percentage >= 60) { // Pattern présent dans 60%+ des top performers
                $common_patterns[] = [
                    'name' => $pattern,
                    'prevalence' => round($percentage),
                    'description' => $this->get_pattern_description($pattern),
                ];
            }
        }

        return $common_patterns;
    }

    /**
     * Get pattern description
     */
    private function get_pattern_description(string $pattern): string {
        $descriptions = [
            'output_format' => 'Section explicite définissant le format de sortie attendu',
            'constraints' => 'Liste de contraintes et choses à éviter',
            'examples' => 'Exemples concrets de bon/mauvais output',
            'context_section' => 'Section de contexte pour cadrer la tâche',
            'length_constraint' => 'Contrainte explicite de longueur (mots, caractères)',
            'step_by_step' => 'Instructions décomposées en étapes numérotées',
            'role_definition' => 'Définition claire du rôle/persona de l\'IA',
        ];

        return $descriptions[$pattern] ?? $pattern;
    }

    /**
     * AI Generate Suggestions
     */
    private function ai_generate_suggestions(array $publication, array $diagnosis, array $patterns, array $goals): array {
        $patterns_text = implode("\n", array_map(function($p) {
            return "- {$p['name']}: {$p['description']} ({$p['prevalence']}% des top performers)";
        }, $patterns));

        $weaknesses_text = implode("\n", array_map(function($w) {
            return "- [{$w['severity']}] {$w['issue']} ({$w['location']})";
        }, $diagnosis['weaknesses'] ?? []));

        $goals_text = implode(', ', $goals);

        $prompt = <<<PROMPT
Tu es un expert en prompt engineering. Génère des suggestions d'amélioration concrètes.

## PROMPT ACTUEL
Titre: {$publication['title']}
Contenu:
{$publication['content']}

## PROBLÈMES IDENTIFIÉS
{$weaknesses_text}

## PATTERNS DES TOP PERFORMERS À APPLIQUER
{$patterns_text}

## OBJECTIFS D'AMÉLIORATION
{$goals_text}

## GÉNÈRE DES SUGGESTIONS
Réponds en JSON strict avec cette structure:
{
  "suggestions": [
    {
      "type": "add_section|rewrite|restructure|remove",
      "priority": 1,
      "location": "beginning|end|after:section_name|replace:lines X-Y",
      "original": "texte original si rewrite (null sinon)",
      "suggested": "nouveau contenu à ajouter/remplacer",
      "rationale": "pourquoi cette amélioration",
      "pattern_source": "nom du pattern appliqué ou null",
      "expected_impact": "+0.X points"
    }
  ]
}

Génère 3 à 6 suggestions, triées par impact décroissant.
PROMPT;

        $response = $this->call_ai($prompt);

        try {
            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE || !isset($result['suggestions'])) {
                return $this->generate_fallback_suggestions($diagnosis, $patterns);
            }

            // Add IDs and validate
            $suggestions = [];
            foreach ($result['suggestions'] as $i => $suggestion) {
                $suggestion['id'] = 'sug_' . ($i + 1);
                $suggestion['priority'] = $suggestion['priority'] ?? ($i + 1);
                $suggestions[] = $suggestion;
            }

            return $suggestions;

        } catch (\Exception $e) {
            return $this->generate_fallback_suggestions($diagnosis, $patterns);
        }
    }

    /**
     * Generate fallback suggestions when AI fails
     */
    private function generate_fallback_suggestions(array $diagnosis, array $patterns): array {
        $suggestions = [];

        // Add missing patterns
        foreach ($patterns as $pattern) {
            if ($pattern['name'] === 'output_format') {
                $suggestions[] = [
                    'id' => 'sug_' . (count($suggestions) + 1),
                    'type' => 'add_section',
                    'priority' => 1,
                    'location' => 'end',
                    'original' => null,
                    'suggested' => "\n\n<output_format>\nFormat attendu: [à définir]\nLongueur: [X mots/lignes]\nStructure: [description]\n</output_format>",
                    'rationale' => "Présent dans {$pattern['prevalence']}% des top performers",
                    'pattern_source' => 'output_format',
                    'expected_impact' => '+0.4 points',
                ];
            }

            if ($pattern['name'] === 'constraints') {
                $suggestions[] = [
                    'id' => 'sug_' . (count($suggestions) + 1),
                    'type' => 'add_section',
                    'priority' => 2,
                    'location' => 'end',
                    'original' => null,
                    'suggested' => "\n\n<constraints>\n❌ À éviter:\n- [contrainte 1]\n- [contrainte 2]\n</constraints>",
                    'rationale' => "Les contraintes négatives améliorent la consistance",
                    'pattern_source' => 'constraints',
                    'expected_impact' => '+0.3 points',
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Apply suggestions to content
     */
    private function apply_suggestions_to_content(string $content, array $suggestions): string {
        $improved = $content;

        // Sort by priority
        usort($suggestions, fn($a, $b) => ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99));

        foreach ($suggestions as $suggestion) {
            switch ($suggestion['type']) {
                case 'add_section':
                    if ($suggestion['location'] === 'end') {
                        $improved .= $suggestion['suggested'];
                    } elseif ($suggestion['location'] === 'beginning') {
                        $improved = $suggestion['suggested'] . "\n\n" . $improved;
                    }
                    break;

                case 'rewrite':
                    if (!empty($suggestion['original'])) {
                        $improved = str_replace($suggestion['original'], $suggestion['suggested'], $improved);
                    }
                    break;

                case 'remove':
                    if (!empty($suggestion['original'])) {
                        $improved = str_replace($suggestion['original'], '', $improved);
                    }
                    break;
            }
        }

        return trim($improved);
    }

    /**
     * Apply only selected suggestions
     */
    private function apply_selected_suggestions(string $content, array $all_suggestions, array $selected_ids): string {
        $selected = array_filter($all_suggestions, fn($s) => in_array($s['id'], $selected_ids));
        return $this->apply_suggestions_to_content($content, $selected);
    }

    /**
     * Estimate new score after improvements
     */
    private function estimate_new_score(float $current_score, array $suggestions): float {
        $total_impact = 0;
        foreach ($suggestions as $suggestion) {
            if (preg_match('/\+?([\d.]+)/', $suggestion['expected_impact'] ?? '', $matches)) {
                $total_impact += (float) $matches[1];
            }
        }

        // Cap at 5.0 and apply diminishing returns
        $new_score = $current_score + ($total_impact * 0.8); // 80% of estimated
        return min(5.0, round($new_score, 2));
    }

    /**
     * Calculate improvement potential
     */
    private function calculate_improvement_potential(float $current, float $benchmark_avg): string {
        $gap = $benchmark_avg - $current;
        if ($gap <= 0) {
            return 'Déjà au niveau des top performers';
        }
        return '+' . round($gap, 1) . ' points possible (benchmark: ' . $benchmark_avg . ')';
    }

    /**
     * Recommend improvement goals based on diagnosis
     */
    private function recommend_goals(array $diagnosis): array {
        $goals = [];

        if (!empty($diagnosis['structural_issues'])) {
            $goals[] = 'structure';
        }
        if (!empty($diagnosis['patterns_missing'])) {
            $goals[] = 'completeness';
        }

        $high_severity = array_filter($diagnosis['weaknesses'] ?? [], fn($w) => ($w['severity'] ?? '') === 'high');
        if (count($high_severity) > 0) {
            $goals[] = 'clarity';
        }

        if (empty($goals)) {
            $goals = ['polish', 'optimization'];
        }

        return array_slice($goals, 0, 3);
    }

    /**
     * Summarize changes between original and improved
     */
    private function summarize_changes(string $original, string $improved): array {
        $orig_lines = count(explode("\n", $original));
        $new_lines = count(explode("\n", $improved));
        $orig_words = str_word_count($original);
        $new_words = str_word_count($improved);

        return [
            'lines_added' => max(0, $new_lines - $orig_lines),
            'lines_removed' => max(0, $orig_lines - $new_lines),
            'words_added' => max(0, $new_words - $orig_words),
            'words_removed' => max(0, $orig_words - $new_words),
            'size_change_percent' => round((($new_words - $orig_words) / max(1, $orig_words)) * 100),
        ];
    }

    // =========================================================================
    // PRIVATE METHODS - Apply
    // =========================================================================

    /**
     * Update publication content
     */
    private function update_publication(int $publication_id, string $content, array $session): array {
        // Store previous version
        $this->store_version($publication_id);

        // Update
        $result = wp_update_post([
            'ID' => $publication_id,
            'post_content' => $content,
        ], true);

        if (is_wp_error($result)) {
            return ['ok' => false, 'error' => $result->get_error_message()];
        }

        // Add improvement meta
        update_post_meta($publication_id, '_last_auto_improved', current_time('mysql'));
        update_post_meta($publication_id, '_auto_improve_version', 
            ((int) get_post_meta($publication_id, '_auto_improve_version', true)) + 1
        );

        return [
            'ok' => true,
            'publication_id' => $publication_id,
            'action' => 'updated',
            'previous_score' => $session['analysis']['current_score'],
            'suggestions_applied' => count($session['suggestions']),
            'url' => get_permalink($publication_id),
        ];
    }

    /**
     * Store version before update
     */
    private function store_version(int $publication_id): void {
        $post = get_post($publication_id);
        if (!$post) return;

        $versions = get_post_meta($publication_id, '_content_versions', true) ?: [];
        
        // Keep last 10 versions
        $versions[] = [
            'content' => $post->post_content,
            'saved_at' => current_time('mysql'),
            'saved_by' => $this->user_id,
        ];

        $versions = array_slice($versions, -10);
        update_post_meta($publication_id, '_content_versions', $versions);
    }

    /**
     * Create new version (branch)
     */
    private function create_version(int $publication_id, string $content, array $session): array {
        $original = get_post($publication_id);
        
        $new_id = wp_insert_post([
            'post_title' => $original->post_title . ' (v' . (time() % 10000) . ')',
            'post_content' => $content,
            'post_status' => 'draft',
            'post_type' => 'ai_publication',
            'post_author' => $this->user_id,
        ]);

        if (is_wp_error($new_id)) {
            return ['ok' => false, 'error' => $new_id->get_error_message()];
        }

        // Copy meta
        $meta_keys = ['_space_id', '_publication_type', '_canvas_type'];
        foreach ($meta_keys as $key) {
            $value = get_post_meta($publication_id, $key, true);
            if ($value) {
                update_post_meta($new_id, $key, $value);
            }
        }

        // Link to original
        update_post_meta($new_id, '_improved_from', $publication_id);
        update_post_meta($new_id, '_auto_improved', true);

        return [
            'ok' => true,
            'publication_id' => $new_id,
            'original_id' => $publication_id,
            'action' => 'new_version',
            'url' => get_permalink($new_id),
        ];
    }

    /**
     * Create new publication in target space
     */
    private function create_new_publication(int $source_id, string $content, array $session, ?int $target_space_id): array {
        $original = get_post($source_id);
        $space_id = $target_space_id ?: get_post_meta($source_id, '_space_id', true);

        $new_id = wp_insert_post([
            'post_title' => $original->post_title . ' (amélioré)',
            'post_content' => $content,
            'post_status' => 'draft',
            'post_type' => 'ai_publication',
            'post_author' => $this->user_id,
        ]);

        if (is_wp_error($new_id)) {
            return ['ok' => false, 'error' => $new_id->get_error_message()];
        }

        // Set meta
        update_post_meta($new_id, '_space_id', $space_id);
        update_post_meta($new_id, '_publication_type', get_post_meta($source_id, '_publication_type', true));
        update_post_meta($new_id, '_canvas_type', get_post_meta($source_id, '_canvas_type', true));
        update_post_meta($new_id, '_improved_from', $source_id);
        update_post_meta($new_id, '_auto_improved', true);

        return [
            'ok' => true,
            'publication_id' => $new_id,
            'original_id' => $source_id,
            'space_id' => $space_id,
            'action' => 'new_publication',
            'url' => get_permalink($new_id),
        ];
    }

    // =========================================================================
    // PRIVATE METHODS - Session Management
    // =========================================================================

    /**
     * Create session
     */
    private function create_session(array $data): string {
        $session_id = self::SESSION_PREFIX . bin2hex(random_bytes(16));
        $data['user_id'] = $this->user_id;
        $data['created_at'] = time();

        set_transient($session_id, $data, self::SESSION_TTL);

        return $session_id;
    }

    /**
     * Get session
     */
    private function get_session(string $session_id): ?array {
        if (strpos($session_id, self::SESSION_PREFIX) !== 0) {
            return null;
        }

        $data = get_transient($session_id);
        if (!$data || ($data['user_id'] ?? 0) !== $this->user_id) {
            return null;
        }

        return $data;
    }

    /**
     * Delete session
     */
    private function delete_session(string $session_id): void {
        delete_transient($session_id);
    }

    // =========================================================================
    // PRIVATE METHODS - Permissions
    // =========================================================================

    /**
     * Check if user can analyze publication
     */
    private function can_analyze(int $publication_id): bool {
        // Must be able to view
        return current_user_can('read_post', $publication_id);
    }

    /**
     * Check if user can edit publication
     */
    private function can_edit(int $publication_id): bool {
        return current_user_can('edit_post', $publication_id);
    }

    /**
     * PATCH 4b: Check if publication is approved (gouvernance)
     * Uses a filter so Picasso can provide real "approved steps" logic
     */
    private function is_approved_publication(int $publication_id): bool {
        // Default: consider 'publish' status as approved
        $default = (get_post_status($publication_id) === 'publish');

        // Allow Picasso or other plugins to override with real approval logic
        return (bool) apply_filters(
            'ml_auto_improve_is_approved_publication',
            $default,
            $publication_id,
            $this->user_id
        );
    }

    // =========================================================================
    // PRIVATE METHODS - AI
    // =========================================================================

    /**
     * Call AI Engine for completion
     */
    private function call_ai(string $prompt): string {
        if (!class_exists('Meow_MWAI_Core')) {
            return '{}';
        }

        try {
            $ai = \Meow_MWAI_Core::instance();
            $query = new \Meow_MWAI_Query_Text($prompt);
            $query->set_max_tokens(2000);
            
            $response = $ai->run_query($query);
            return $response->result;

        } catch (\Exception $e) {
            error_log('Auto_Improve AI error: ' . $e->getMessage());
            return '{}';
        }
    }

    /**
     * Helper to implode contents
     */
    private function implode_contents(array $contents): string {
        return implode("\n\n---\n\n", $contents);
    }

    /**
     * Fallback parsing for diagnosis
     */
    private function parse_diagnosis_fallback(string $response): array {
        return [
            'strengths' => ['Analyse en cours...'],
            'weaknesses' => [],
            'patterns_missing' => [],
            'quick_wins' => [],
            'structural_issues' => [],
            'raw_response' => substr($response, 0, 500),
        ];
    }
}
