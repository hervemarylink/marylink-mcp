<?php
/**
 * Auto Improve Tools - MCP Handler for ml_auto_improve
 *
 * Exposes the auto-improvement capabilities via MCP protocol.
 *
 * @package MCP_No_Headless
 * @since 2.0.0
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Auto_Improve_Service;
use MCP_No_Headless\Ops\Audit_Logger;
use MCP_No_Headless\Ops\Rate_Limiter;

class Auto_Improve_Tools {
/**     * Check if auto-improve tools are available     *     * @return bool     */    public static function is_available(): bool {        return Auto_Improve_Service::is_available();    }

    /**
     * Get tool definitions for MCP registration
     *
     * @return array
     */
    public static function get_definitions(): array {
        if (!Auto_Improve_Service::is_available()) {
            return [];
        }

        return [
            [
                'name' => 'ml_auto_improve',
                'description' => 'Analyse et améliore automatiquement un prompt basé sur les patterns des top performers. Flow en 3 étapes: analyze → suggest → apply.',
                'category' => 'MaryLink Intelligence',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'stage' => [
                            'type' => 'string',
                            'enum' => ['analyze', 'suggest', 'apply'],
                            'description' => 'analyze=diagnostic, suggest=génère améliorations+session_id, apply=commit les changements',
                        ],
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID du prompt à améliorer',
                        ],
                        'benchmark_scope' => [
                            'type' => 'string',
                            'enum' => ['space', 'organization', 'global'],
                            'default' => 'space',
                            'description' => 'Scope pour trouver les références: space=même espace, organization=même type dans tous espaces, global=tous',
                        ],
                        'improvement_goals' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                                'enum' => ['clarity', 'specificity', 'output_quality', 'consistency', 'brevity', 'structure', 'completeness'],
                            ],
                            'description' => 'Axes d\'amélioration prioritaires (optionnel, auto-détecté sinon)',
                        ],
                        'session_id' => [
                            'type' => 'string',
                            'description' => 'Requis pour stage=apply. Obtenu via stage=suggest.',
                        ],
                        'options' => [
                            'type' => 'object',
                            'description' => 'Options pour stage=apply',
                            'properties' => [
                                'mode' => [
                                    'type' => 'string',
                                    'enum' => ['new_version', 'new_publication', 'update'],
                                    'default' => 'new_version',
                                    'description' => 'new_version=créer branche (défaut), new_publication=copie améliorée, update=modifier en place (admin only)',
                                ],
                                'selected_suggestions' => [
                                    'oneOf' => [
                                        ['type' => 'string', 'enum' => ['all']],
                                        ['type' => 'array', 'items' => ['type' => 'string']],
                                    ],
                                    'default' => 'all',
                                    'description' => '"all" ou liste des IDs de suggestions à appliquer ["sug_1", "sug_3"]',
                                ],
                                'target_space_id' => [
                                    'type' => 'integer',
                                    'description' => 'Espace cible si mode=new_publication',
                                ],
                            ],
                        ],
                    ],
                    'required' => ['stage', 'publication_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => false,
                    'idempotentHint' => false,
                ],
            ],

            // Shortcut tool for quick analysis
            [
                'name' => 'ml_prompt_health_check',
                'description' => 'Diagnostic rapide d\'un prompt: score actuel, problèmes détectés, potentiel d\'amélioration. Raccourci vers ml_auto_improve(stage=analyze).',
                'category' => 'MaryLink Intelligence',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID du prompt à analyser',
                        ],
                        'compare_to' => [
                            'type' => 'string',
                            'enum' => ['space', 'organization', 'global'],
                            'default' => 'space',
                            'description' => 'Benchmark de comparaison',
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                ],
            ],

            // Bulk improvement suggestion
            [
                'name' => 'ml_auto_improve_batch',
                'description' => 'Identifie les prompts sous-performants dans un espace et suggère des améliorations prioritaires.',
                'category' => 'MaryLink Intelligence',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'ID de l\'espace à analyser',
                        ],
                        'threshold' => [
                            'type' => 'number',
                            'default' => 3.5,
                            'description' => 'Score en-dessous duquel un prompt est considéré sous-performant',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'default' => 10,
                            'maximum' => 20,
                            'description' => 'Nombre max de prompts à analyser',
                        ],
                        'auto_suggest' => [
                            'type' => 'boolean',
                            'default' => false,
                            'description' => 'Générer automatiquement les suggestions pour chaque prompt',
                        ],
                    ],
                    'required' => ['space_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                ],
            ],
        ];
    }

    /**
     * Execute tool
     *
     * @param string $tool Tool name
     * @param array $args Tool arguments
     * @param int $user_id User ID
     * @return array Result
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $start_time = microtime(true);

        // PATCH 1: Set WP user context for permission checks
        wp_set_current_user($user_id);

        // Rate limit check (analyze=read, suggest/apply=write)
        $stage = $args['stage'] ?? 'analyze';
        $is_write = in_array($stage, ['suggest', 'apply']);
        
        $rate_check = Rate_Limiter::check($user_id, $tool, $is_write ? 'write' : null);
        if (!$rate_check['allowed']) {
            return [
                'ok' => false,
                'error' => 'rate_limited',
                'message' => Rate_Limiter::get_error_message($rate_check['reason'], $rate_check['retry_after']),
                'retry_after' => $rate_check['retry_after'],
            ];
        }

        try {
            $result = match ($tool) {
                'ml_auto_improve' => self::handle_auto_improve($args, $user_id),
                'ml_prompt_health_check' => self::handle_health_check($args, $user_id),
                'ml_auto_improve_batch' => self::handle_batch($args, $user_id),
                default => ['ok' => false, 'error' => 'unknown_tool'],
            };

            // Audit log
            $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
            $debug_id = Audit_Logger::log_tool(
                $tool,
                $user_id,
                ($result['ok'] ?? false) ? 'success' : 'error',
                self::sanitize_args_for_log($args),
                $stage,
                $latency_ms,
                $result['error'] ?? null
            );

            if ($debug_id && ($result['ok'] ?? false)) {
                $result['debug_id'] = $debug_id;
            }

            return $result;

        } catch (\Exception $e) {
            $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
            Audit_Logger::log_tool($tool, $user_id, 'error', [], $stage ?? null, $latency_ms, 'exception');

            return [
                'ok' => false,
                'error' => 'internal_error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle ml_auto_improve
     */
    private static function handle_auto_improve(array $args, int $user_id): array {
        $service = new Auto_Improve_Service($user_id);

        $stage = $args['stage'] ?? '';
        $publication_id = (int) ($args['publication_id'] ?? 0);

        if (!$publication_id) {
            return ['ok' => false, 'error' => 'missing_publication_id'];
        }

        $benchmark_scope = $args['benchmark_scope'] ?? 'space';
        $goals = $args['improvement_goals'] ?? [];
        $scope_warnings = [];

        // PATCH 5: Restrict benchmark_scope for non-admins (anti B2B2B leak)
        if ($benchmark_scope !== 'space' && !current_user_can('manage_options')) {
            $scope_warnings[] = 'benchmark_scope limité à "space" (non-admin).';
            $benchmark_scope = 'space';
        }

        switch ($stage) {
            case 'analyze':
                $result = $service->analyze($publication_id, $benchmark_scope, $goals);
                break;

            case 'suggest':
                $result = $service->suggest($publication_id, $benchmark_scope, $goals);
                break;

            case 'apply':
                $session_id = $args['session_id'] ?? '';
                if (empty($session_id)) {
                    return ['ok' => false, 'error' => 'missing_session_id', 'hint' => 'Appelez d\'abord stage=suggest pour obtenir un session_id'];
                }
                $options = $args['options'] ?? [];
                $result = $service->apply($session_id, $options);
                break;

            default:
                return ['ok' => false, 'error' => 'invalid_stage', 'valid_stages' => ['analyze', 'suggest', 'apply']];
        }

        // Add scope warnings if any
        if (!empty($scope_warnings)) {
            $result['warnings'] = array_merge($result['warnings'] ?? [], $scope_warnings);
        }

        return $result;
    }

    /**
     * Handle ml_prompt_health_check (shortcut)
     */
    private static function handle_health_check(array $args, int $user_id): array {
        $service = new Auto_Improve_Service($user_id);

        $publication_id = (int) ($args['publication_id'] ?? 0);
        if (!$publication_id) {
            return ['ok' => false, 'error' => 'missing_publication_id'];
        }

        $compare_to = $args['compare_to'] ?? 'space';
        $scope_warnings = [];

        // PATCH 5: Restrict scope for non-admins
        if ($compare_to !== 'space' && !current_user_can('manage_options')) {
            $scope_warnings[] = 'compare_to limité à "space" (non-admin).';
            $compare_to = 'space';
        }

        $analysis = $service->analyze($publication_id, $compare_to);

        if (!$analysis['ok']) {
            return $analysis;
        }

        // Simplified output
        $result = [
            'ok' => true,
            'publication_id' => $publication_id,
            'title' => $analysis['publication_title'],
            'health' => [
                'score' => $analysis['current_score'],
                'benchmark_avg' => $analysis['benchmark']['average_score_in_scope'],
                'status' => self::score_to_status($analysis['current_score'], $analysis['benchmark']['average_score_in_scope']),
            ],
            'issues_count' => count($analysis['diagnosis']['weaknesses'] ?? []),
            'top_issues' => array_slice(
                array_map(fn($w) => $w['issue'], $analysis['diagnosis']['weaknesses'] ?? []),
                0,
                3
            ),
            'improvement_potential' => $analysis['improvement_potential'],
            'quick_wins' => $analysis['diagnosis']['quick_wins'] ?? [],
            'next_step' => 'Appelez ml_auto_improve(stage="suggest", publication_id=' . $publication_id . ') pour obtenir des suggestions concrètes.',
        ];

        // Add warnings if any
        if (!empty($scope_warnings)) {
            $result['warnings'] = $scope_warnings;
        }

        return $result;
    }

    /**
     * Handle ml_auto_improve_batch
     */
    private static function handle_batch(array $args, int $user_id): array {
        $space_id = (int) ($args['space_id'] ?? 0);
        if (!$space_id) {
            return ['ok' => false, 'error' => 'missing_space_id'];
        }

        $threshold = (float) ($args['threshold'] ?? 3.5);
        $limit = min(20, (int) ($args['limit'] ?? 10));
        $auto_suggest = (bool) ($args['auto_suggest'] ?? false);

        // Find underperforming publications
        $query_args = [
            'post_type' => 'ai_publication',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_space_id',
                    'value' => $space_id,
                ],
                [
                    'key' => '_ml_quality_score',
                    'value' => $threshold,
                    'compare' => '<',
                    'type' => 'DECIMAL',
                ],
            ],
            'orderby' => 'meta_value_num',
            'meta_key' => '_ml_quality_score',
            'order' => 'ASC', // Worst first
        ];

        $query = new \WP_Query($query_args);

        $service = new Auto_Improve_Service($user_id);
        $publications = [];

        foreach ($query->posts as $post) {
            // PATCH 6: Filter by read_post permission (anti-leak)
            if (!current_user_can('read_post', $post->ID)) {
                continue;
            }

            $score = (float) get_post_meta($post->ID, '_ml_quality_score', true);

            $pub_data = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'current_score' => $score,
                'gap_to_threshold' => round($threshold - $score, 2),
            ];

            if ($auto_suggest) {
                // Generate analysis for each (already protected by can_analyze)
                $analysis = $service->analyze($post->ID, 'space');
                if ($analysis['ok']) {
                    $pub_data['diagnosis_summary'] = [
                        'weaknesses_count' => count($analysis['diagnosis']['weaknesses'] ?? []),
                        'patterns_missing' => count($analysis['diagnosis']['patterns_missing'] ?? []),
                        'improvement_potential' => $analysis['improvement_potential'],
                    ];
                    $pub_data['quick_wins'] = $analysis['diagnosis']['quick_wins'] ?? [];
                }
            }

            $publications[] = $pub_data;
        }

        // PATCH 7: Neutral response if no visible items (anti-leak)
        if (empty($publications)) {
            return [
                'ok' => true,
                'space_id' => $space_id,
                'publications' => [],
                'summary' => [
                    'total_analyzed' => 0,
                    'need_improvement' => 0,
                ],
            ];
        }

        // Calculate priority order
        usort($publications, function($a, $b) {
            // Prioritize by gap (biggest gap first)
            return $b['gap_to_threshold'] <=> $a['gap_to_threshold'];
        });

        return [
            'ok' => true,
            'space_id' => $space_id,
            'threshold' => $threshold,
            'publications' => $publications,
            'summary' => [
                'total_analyzed' => count($publications),
                'need_improvement' => count($publications),
                'average_score' => round(array_sum(array_column($publications, 'current_score')) / max(1, count($publications)), 2),
                'worst_performer' => $publications[0] ?? null,
            ],
            'recommendation' => count($publications) > 0 
                ? 'Commencez par "' . ($publications[0]['title'] ?? 'N/A') . '" (score: ' . ($publications[0]['current_score'] ?? 0) . ')'
                : null,
            'next_step' => 'Appelez ml_auto_improve(stage="suggest", publication_id=X) pour chaque prompt à améliorer.',
        ];
    }

    /**
     * Convert score to status label
     */
    private static function score_to_status(float $score, float $benchmark): string {
        $gap = $benchmark - $score;

        if ($score >= 4.5) return '🌟 Excellent';
        if ($score >= 4.0) return '✅ Bon';
        if ($score >= $benchmark) return '👍 Dans la moyenne';
        if ($gap <= 0.5) return '⚠️ Légèrement sous la moyenne';
        if ($gap <= 1.0) return '🔶 À améliorer';
        return '🔴 Nécessite attention';
    }

    /**
     * Sanitize args for audit log (remove large content)
     */
    private static function sanitize_args_for_log(array $args): array {
        $safe = $args;
        
        // Don't log full content
        if (isset($safe['options']['selected_suggestions']) && is_array($safe['options']['selected_suggestions'])) {
            $safe['options']['selected_suggestions_count'] = count($safe['options']['selected_suggestions']);
            unset($safe['options']['selected_suggestions']);
        }

        return $safe;
    }
}
