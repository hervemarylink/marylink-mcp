<?php
/**
 * Ratings Tools - MCP tools for publication ratings and reviews (tool-map v1)
 *
 * Tools:
 * - ml_rate_publication: Rate a publication with criteria scores (prepare/commit)
 * - ml_get_ratings_summary: Get aggregated ratings for a publication
 * - ml_list_workflow_steps: List workflow steps for a space
 * - ml_ratings_get: Legacy - Get rating statistics (kept for backward compat)
 *
 * Output format: tool-map v1 envelope {ok, request_id, warnings, data, cursor}
 *
 * TICKET T1.1: Quick win visible - ratings with structured criteria
 * TICKET T1.2: Workflow steps listing
 * TICKET T1.3: Ratings summary with quality score
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Rating_Service;
use MCP_No_Headless\Picasso\Picasso_Adapter;

class Ratings_Tools {

    /**
     * Session prefix for prepare/commit
     */
    private const SESSION_PREFIX = 'mcpnh_rating_';

    /**
     * Session TTL in seconds (5 minutes)
     */
    private const SESSION_TTL = 300;

    /**
     * Default rating criteria
     */
    public const DEFAULT_CRITERIA = [
        'accuracy' => [
            'label' => 'Accuracy',
            'description' => 'How accurate and correct is the content?',
            'weight' => 1.0,
        ],
        'clarity' => [
            'label' => 'Clarity',
            'description' => 'How clear and understandable is the content?',
            'weight' => 1.0,
        ],
        'completeness' => [
            'label' => 'Completeness',
            'description' => 'How complete and thorough is the content?',
            'weight' => 1.0,
        ],
        'relevance' => [
            'label' => 'Relevance',
            'description' => 'How relevant is the content to its purpose?',
            'weight' => 1.0,
        ],
        'usefulness' => [
            'label' => 'Usefulness',
            'description' => 'How useful is this content in practice?',
            'weight' => 1.0,
        ],
    ];

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            // T1.1: ml_rate_publication (prepare/commit)
            'ml_rate_publication' => [
                'name' => 'ml_rate_publication',
                'description' => 'Rate a publication with structured criteria scores. Use stage=prepare to get criteria and preview, stage=commit to submit the rating.',
                'category' => 'MaryLink Ratings',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID of the publication to rate',
                        ],
                        'stage' => [
                            'type' => 'string',
                            'enum' => ['prepare', 'commit'],
                            'description' => 'Stage: prepare (get criteria) or commit (submit rating)',
                            'default' => 'prepare',
                        ],
                        'session_id' => [
                            'type' => 'string',
                            'description' => 'Session ID from prepare stage (required for commit)',
                        ],
                        'overall_rating' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 5,
                            'description' => 'Overall rating from 1-5 (required for commit)',
                        ],
                        'criteria_scores' => [
                            'type' => 'object',
                            'description' => 'Scores by criteria: {accuracy: 4, clarity: 5, ...}',
                            'additionalProperties' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'maximum' => 5,
                            ],
                        ],
                        'review_type' => [
                            'type' => 'string',
                            'enum' => ['user', 'expert'],
                            'description' => 'Type of review: user (standard) or expert (requires role)',
                            'default' => 'user',
                        ],
                        'comment' => [
                            'type' => 'string',
                            'description' => 'Optional review comment/feedback',
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => false,
                ],
            ],

            // T1.3: ml_get_ratings_summary
            'ml_get_ratings_summary' => [
                'name' => 'ml_get_ratings_summary',
                'description' => 'Get aggregated ratings summary for a publication including averages, distribution, and quality score.',
                'category' => 'MaryLink Ratings',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID of the publication',
                        ],
                        'include_reviews' => [
                            'type' => 'boolean',
                            'description' => 'Include individual reviews (default: false)',
                            'default' => false,
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max reviews to include (default: 10)',
                            'default' => 10,
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                ],
            ],

            // T1.2: ml_list_workflow_steps
            'ml_list_workflow_steps' => [
                'name' => 'ml_list_workflow_steps',
                'description' => 'List workflow steps for a space. Helps agents understand the workflow and automate transitions.',
                'category' => 'MaryLink Workflow',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'ID of the space',
                        ],
                        'include_permissions' => [
                            'type' => 'boolean',
                            'description' => 'Include permission details for each step',
                            'default' => false,
                        ],
                        'include_counts' => [
                            'type' => 'boolean',
                            'description' => 'Include publication counts per step',
                            'default' => true,
                        ],
                    ],
                    'required' => ['space_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                ],
            ],

            // Legacy: ml_ratings_get (backward compatibility)
            'ml_ratings_get' => [
                'name' => 'ml_ratings_get',
                'description' => 'Get rating statistics for a publication (average, count, distribution). Use ml_get_ratings_summary for richer data.',
                'category' => 'MaryLink Ratings',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'The publication ID',
                        ],
                        'include_criteria' => [
                            'type' => 'boolean',
                            'description' => 'Include per-criteria ratings if available',
                            'default' => false,
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                ],
            ],
        ];
    }

    /**
     * Execute a ratings tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();
        $permissions = new Permission_Checker($user_id);

        switch ($tool) {
            case 'ml_rate_publication':
                return self::handle_rate_publication($args, $user_id, $permissions, $request_id);

            case 'ml_get_ratings_summary':
                return self::handle_get_ratings_summary($args, $user_id, $permissions, $request_id);

            case 'ml_list_workflow_steps':
                return self::handle_list_workflow_steps($args, $user_id, $permissions, $request_id);

            case 'ml_ratings_get':
                return self::handle_ratings_get($args, $user_id, $permissions, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_rate_publication (T1.1)
     */
    private static function handle_rate_publication(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];
        $stage = $args['stage'] ?? 'prepare';

        // Check publication exists and user can see it
        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        if (!$permissions->can_see_publication($publication_id)) {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        if ($stage === 'prepare') {
            return self::prepare_rating($publication_id, $post, $args, $user_id, $request_id);
        } elseif ($stage === 'commit') {
            return self::commit_rating($publication_id, $args, $user_id, $permissions, $request_id);
        } else {
            return Tool_Response::error('validation_failed', 'Invalid stage. Use "prepare" or "commit".', $request_id);
        }
    }

    /**
     * Prepare rating - get criteria and preview
     */
    private static function prepare_rating(int $publication_id, \WP_Post $post, array $args, int $user_id, string $request_id): array {
        $review_type = $args['review_type'] ?? 'user';

        // Check if expert review requires role
        if ($review_type === 'expert' && !current_user_can('edit_others_posts')) {
            return Tool_Response::error(
                'permission_denied',
                'Expert reviews require reviewer role.',
                $request_id
            );
        }

        // Get existing user rating if any
        $existing_rating = Rating_Service::get_user_rating($publication_id, $user_id);

        // Get criteria (could be space-specific in future)
        $space_id = (int) $post->post_parent;
        $criteria = self::get_criteria_for_space($space_id);

        // Create session
        $session_id = self::create_session([
            'user_id' => $user_id,
            'publication_id' => $publication_id,
            'review_type' => $review_type,
            'created_at' => time(),
        ]);

        // Build preview
        $preview = [
            'publication' => [
                'id' => $publication_id,
                'title' => $post->post_title,
                'excerpt' => wp_trim_words(strip_tags($post->post_content), 25, '...'),
                'space_id' => $space_id,
                'step' => Picasso_Adapter::get_publication_step($publication_id),
            ],
            'current_average' => Rating_Service::get_average_rating($publication_id),
            'total_ratings' => Rating_Service::get_rating_count($publication_id),
        ];

        return Tool_Response::ok([
            'stage' => 'prepare',
            'session_id' => $session_id,
            'expires_in' => self::SESSION_TTL,
            'preview' => $preview,
            'criteria' => $criteria,
            'review_type' => $review_type,
            'existing_rating' => $existing_rating ? [
                'overall' => $existing_rating['rating'],
                'criteria_scores' => $existing_rating['criteria_scores'] ?? [],
                'created_at' => $existing_rating['created_at'],
            ] : null,
            'next_action' => [
                'tool' => 'ml_rate_publication',
                'args' => [
                    'publication_id' => $publication_id,
                    'stage' => 'commit',
                    'session_id' => $session_id,
                    'overall_rating' => '{{1-5}}',
                    'criteria_scores' => [
                        'accuracy' => '{{1-5}}',
                        'clarity' => '{{1-5}}',
                        'completeness' => '{{1-5}}',
                        'relevance' => '{{1-5}}',
                        'usefulness' => '{{1-5}}',
                    ],
                    'comment' => '{{optional_comment}}',
                ],
                'hint' => 'Fill in ratings 1-5 for each criterion and call commit.',
            ],
        ], $request_id);
    }

    /**
     * Commit rating - submit the review
     */
    private static function commit_rating(int $publication_id, array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        // Validate session
        $session_id = $args['session_id'] ?? '';
        $session = self::validate_session($session_id, $user_id);

        if (!$session) {
            return Tool_Response::error(
                'session_expired',
                'Session expired or invalid. Please run prepare stage again.',
                $request_id
            );
        }

        // Validate session matches publication
        if (($session['publication_id'] ?? 0) !== $publication_id) {
            return Tool_Response::error(
                'session_mismatch',
                'Session does not match publication.',
                $request_id
            );
        }

        // Validate overall rating
        $overall_rating = (int) ($args['overall_rating'] ?? 0);
        if ($overall_rating < 1 || $overall_rating > 5) {
            return Tool_Response::error(
                'validation_failed',
                'Overall rating must be between 1 and 5.',
                $request_id
            );
        }

        // Validate criteria scores
        $criteria_scores = $args['criteria_scores'] ?? [];
        $validated_scores = [];
        foreach ($criteria_scores as $key => $score) {
            $score = (int) $score;
            if ($score >= 1 && $score <= 5) {
                $validated_scores[$key] = $score;
            }
        }

        // Get review type from session
        $review_type = $session['review_type'] ?? 'user';

        // Validate expert permission
        if ($review_type === 'expert' && !current_user_can('edit_others_posts')) {
            return Tool_Response::error(
                'permission_denied',
                'Expert reviews require reviewer role.',
                $request_id
            );
        }

        // Create or update the review
        $comment = sanitize_textarea_field($args['comment'] ?? '');

        $result = Rating_Service::create_review_with_criteria(
            $publication_id,
            $user_id,
            $overall_rating,
            $validated_scores,
            $review_type,
            $comment
        );

        if (!$result['success']) {
            return Tool_Response::error(
                'save_failed',
                $result['message'] ?? 'Failed to save rating.',
                $request_id
            );
        }

        // Clean up session
        self::cleanup_session($session_id);

        // Get updated stats
        $new_average = Rating_Service::get_average_rating($publication_id);
        $new_count = Rating_Service::get_rating_count($publication_id);

        return Tool_Response::ok([
            'stage' => 'commit',
            'success' => true,
            'review_id' => $result['review_id'],
            'publication_id' => $publication_id,
            'rating' => [
                'overall' => $overall_rating,
                'criteria_scores' => $validated_scores,
                'review_type' => $review_type,
            ],
            'updated_stats' => [
                'average_rating' => round($new_average, 2),
                'total_ratings' => $new_count,
            ],
            'message' => $result['is_update']
                ? 'Rating updated successfully.'
                : 'Rating submitted successfully.',
        ], $request_id);
    }

    /**
     * Handle ml_get_ratings_summary (T1.3)
     */
    private static function handle_get_ratings_summary(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];
        $include_reviews = (bool) ($args['include_reviews'] ?? false);
        $limit = min(50, max(1, (int) ($args['limit'] ?? 10)));

        // Check permission
        if (!$permissions->can_see_publication($publication_id)) {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        // Get basic stats
        $average = Rating_Service::get_average_rating($publication_id);
        $count = Rating_Service::get_rating_count($publication_id);

        // Get distribution
        $distribution = Rating_Service::get_rating_distribution($publication_id);

        // Calculate quality score (weighted average considering count)
        $quality_score = self::calculate_quality_score($average, $count, $distribution);

        // Get user and expert averages separately
        $user_stats = Rating_Service::get_stats_by_type($publication_id, 'user');
        $expert_stats = Rating_Service::get_stats_by_type($publication_id, 'expert');

        // Get criteria averages
        $criteria_averages = Rating_Service::get_criteria_averages($publication_id);

        $output = [
            'publication_id' => $publication_id,
            'overall' => [
                'average' => round($average, 2),
                'count' => $count,
                'quality_score' => $quality_score,
            ],
            'user_ratings' => [
                'average' => round($user_stats['average'] ?? 0, 2),
                'count' => $user_stats['count'] ?? 0,
            ],
            'expert_ratings' => [
                'average' => round($expert_stats['average'] ?? 0, 2),
                'count' => $expert_stats['count'] ?? 0,
            ],
            'distribution' => [
                '5_stars' => $distribution[5] ?? 0,
                '4_stars' => $distribution[4] ?? 0,
                '3_stars' => $distribution[3] ?? 0,
                '2_stars' => $distribution[2] ?? 0,
                '1_star' => $distribution[1] ?? 0,
            ],
            'criteria_averages' => $criteria_averages,
            'favorites_count' => (int) get_post_meta($publication_id, '_ml_favorites_count', true),
        ];

        // Include reviews if requested
        if ($include_reviews) {
            $reviews = Rating_Service::get_reviews($publication_id, $limit);
            $output['reviews'] = array_map(function ($review) {
                return [
                    'id' => $review['id'],
                    'user_id' => $review['user_id'],
                    'user_name' => $review['user_name'] ?? 'Anonymous',
                    'rating' => $review['rating'],
                    'criteria_scores' => $review['criteria_scores'] ?? [],
                    'review_type' => $review['review_type'] ?? 'user',
                    'comment' => $review['comment'] ?? '',
                    'created_at' => $review['created_at'],
                ];
            }, $reviews);
        }

        return Tool_Response::ok($output, $request_id);
    }

    /**
     * Handle ml_list_workflow_steps (T1.2)
     */
    private static function handle_list_workflow_steps(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['space_id']);
        if ($validation) {
            return $validation;
        }

        $space_id = (int) $args['space_id'];
        $include_permissions = (bool) ($args['include_permissions'] ?? false);
        $include_counts = (bool) ($args['include_counts'] ?? true);

        // Check space permission
        if (!$permissions->can_see_space($space_id)) {
            return Tool_Response::error('not_found', 'Space not found', $request_id);
        }

        // Get workflow steps from Picasso
        $steps = Picasso_Adapter::get_space_workflow_steps($space_id);

        if (empty($steps)) {
            return Tool_Response::empty_list(
                $request_id,
                'No workflow steps configured for this space.'
            );
        }

        // Build step list
        $step_list = [];
        foreach ($steps as $index => $step) {
            $step_name = is_array($step) ? ($step['name'] ?? $step) : $step;
            $step_label = is_array($step) ? ($step['label'] ?? ucfirst($step_name)) : ucfirst($step_name);

            $step_data = [
                'name' => $step_name,
                'label' => $step_label,
                'order' => $index + 1,
                'is_initial' => is_array($step) ? ($step['is_initial'] ?? ($index === 0)) : ($index === 0),
                'is_final' => is_array($step) ? ($step['is_final'] ?? false) : false,
                'color' => is_array($step) ? ($step['color'] ?? '#666666') : '#666666',
            ];

            // Include counts
            if ($include_counts) {
                $step_data['publication_count'] = Picasso_Adapter::count_publications_in_step($space_id, $step_name);
            }

            // Include permissions
            if ($include_permissions) {
                $step_data['permissions'] = [
                    'can_view' => $permissions->can_view_step($space_id, $step_name),
                    'can_edit' => $permissions->can_edit_in_step($space_id, $step_name),
                    'can_move_from' => $permissions->can_move_from_step($space_id, $step_name),
                    'allowed_transitions' => is_array($step) ? ($step['transitions'] ?? []) : [],
                ];
            }

            $step_list[] = $step_data;
        }

        // Get workflow metadata
        $workflow_meta = Picasso_Adapter::get_space_workflow_meta($space_id);

        return Tool_Response::ok([
            'space_id' => $space_id,
            'workflow_name' => $workflow_meta['name'] ?? 'Default Workflow',
            'steps' => $step_list,
            'step_count' => count($step_list),
            'permissions_summary' => $include_permissions ? [
                'can_create' => $permissions->can_publish_in_space($space_id),
                'can_manage_workflow' => current_user_can('manage_options'),
            ] : null,
        ], $request_id);
    }

    /**
     * Handle ml_ratings_get (legacy)
     */
    private static function handle_ratings_get(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];

        if (!$permissions->can_see_publication($publication_id)) {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        $average = Rating_Service::get_average_rating($publication_id);
        $count = Rating_Service::get_rating_count($publication_id);
        $distribution = Rating_Service::get_rating_distribution($publication_id);

        $ratings = [
            'publication_id' => $publication_id,
            'average' => round($average, 2),
            'count' => $count,
            'distribution' => $distribution,
        ];

        // Include criteria ratings if requested
        if (!empty($args['include_criteria'])) {
            $criteria = Rating_Service::get_criteria_averages($publication_id);
            if (!empty($criteria)) {
                $ratings['criteria'] = $criteria;
            }
        }

        $warnings = [];
        if ($count === 0) {
            $warnings[] = 'This publication has no ratings yet.';
        }

        return Tool_Response::ok($ratings, $request_id, !empty($warnings) ? $warnings : null);
    }

    /**
     * Get criteria for a space (could be customized per space)
     */
    private static function get_criteria_for_space(int $space_id): array {
        // Check for space-specific criteria
        $custom_criteria = get_post_meta($space_id, '_ml_rating_criteria', true);
        if (!empty($custom_criteria) && is_array($custom_criteria)) {
            return $custom_criteria;
        }

        return self::DEFAULT_CRITERIA;
    }

    /**
     * Calculate quality score
     *
     * Quality score combines:
     * - Average rating
     * - Number of ratings (confidence)
     * - Expert vs user weight
     */
    private static function calculate_quality_score(float $average, int $count, array $distribution): float {
        if ($count === 0) {
            return 0.0;
        }

        // Bayesian average: q = (C * m + sum(ratings)) / (C + count)
        // C = confidence threshold, m = prior mean
        $C = 5;  // Need 5 ratings for full confidence
        $m = 3.0; // Prior mean (neutral)

        $sum_ratings = 0;
        foreach ($distribution as $stars => $cnt) {
            $sum_ratings += $stars * $cnt;
        }

        $bayesian_avg = ($C * $m + $sum_ratings) / ($C + $count);

        // Scale to 0-100
        return round(($bayesian_avg / 5) * 100, 1);
    }

    /**
     * Create session for prepare/commit flow
     */
    private static function create_session(array $data): string {
        $session_id = 'rate_' . bin2hex(random_bytes(16));
        set_transient(self::SESSION_PREFIX . $session_id, $data, self::SESSION_TTL);
        return $session_id;
    }

    /**
     * Validate session
     */
    private static function validate_session(string $session_id, int $user_id): ?array {
        if (empty($session_id) || strpos($session_id, 'rate_') !== 0) {
            return null;
        }

        $session = get_transient(self::SESSION_PREFIX . $session_id);
        if (!$session || !is_array($session)) {
            return null;
        }

        if (($session['user_id'] ?? 0) !== $user_id) {
            return null;
        }

        return $session;
    }

    /**
     * Clean up session
     */
    private static function cleanup_session(string $session_id): void {
        delete_transient(self::SESSION_PREFIX . $session_id);
    }

    /**
     * Check if available
     */
    public static function is_available(): bool {
        return post_type_exists('publication');
    }
}
