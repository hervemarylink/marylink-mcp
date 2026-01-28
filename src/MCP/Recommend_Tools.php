<?php
/**
 * Recommend Tools - MCP tools for smart recommendations (tool-map v1)
 *
 * Tools:
 * - ml_recommend: Get personalized prompt recommendations with explainability
 * - ml_recommend_styles: Get available style variants for a prompt
 *
 * Output format: tool-map v1 envelope {ok, request_id, warnings, data, cursor}
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Recommendation_Service;

class Recommend_Tools {

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_recommend' => [
                'name' => 'ml_recommend',
                'description' => 'Get personalized prompt recommendations based on your intent. Analyzes your request and suggests the best prompts with explanations (the "why"). Returns ranked recommendations with content bundles (styles, data).',
                'category' => 'MaryLink Recommendations',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => [
                            'type' => 'string',
                            'description' => 'What you want to create (e.g., "une lettre commerciale pour mon prospect Acme Corp")',
                        ],
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'Optional: Filter recommendations to a specific space',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum recommendations to return (default 5, max 10)',
                            'default' => 5,
                        ],
                    ],
                    'required' => ['text'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                ],
            ],

            'ml_recommend_styles' => [
                'name' => 'ml_recommend_styles',
                'description' => 'Get available style variants for a prompt (e.g., premium, direct, warm)',
                'category' => 'MaryLink Recommendations',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt_id' => [
                            'type' => 'integer',
                            'description' => 'The prompt/tool publication ID',
                        ],
                    ],
                    'required' => ['prompt_id'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],
        ];
    }

    /**
     * Execute a recommendation tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();

        if (!Recommendation_Service::is_available()) {
            return Tool_Response::error(
                'feature_unavailable',
                'Recommendation feature is not available on this site.',
                $request_id
            );
        }

        $service = new Recommendation_Service($user_id);

        switch ($tool) {
            case 'ml_recommend':
                return self::handle_recommend($service, $args, $user_id, $request_id);

            case 'ml_recommend_styles':
                return self::handle_styles($service, $args, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_recommend
     *
     * Returns tool-map v1 format with:
     * - recommended_tool: The best prompt recommendation
     * - prompt_publication_id: ID for ml_apply_tool_prepare
     * - styles: Available style variants
     * - content_bundle: Linked contents (data, docs)
     * - why: Explainability with signals
     */
    private static function handle_recommend(Recommendation_Service $service, array $args, int $user_id, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['text']);
        if ($validation) {
            return $validation;
        }

        $text = trim($args['text']);
        if (empty($text)) {
            return Tool_Response::error('validation_failed', 'Text cannot be empty', $request_id);
        }

        $space_id = isset($args['space_id']) ? (int) $args['space_id'] : null;
        $options = [
            'limit' => min(10, max(1, (int) ($args['limit'] ?? 5))),
        ];

        $result = $service->recommend($text, $space_id, $options);

        if (empty($result['recommendations'])) {
            return Tool_Response::empty_list(
                $request_id,
                $result['message'] ?? 'No recommendations found for your request.',
                [
                    'intent' => $result['intent'] ?? null,
                    'suggestions' => $result['suggestions'] ?? [],
                ]
            );
        }

        // Format output according to tool-map v1 spec
        $recommendations = [];

        foreach ($result['recommendations'] as $rec) {
            $prompt = $rec['prompt'];
            $bundle = $rec['bundle'];
            $scores = $rec['scores'];

            // Build WHY with structured signals
            $why_signals = [];

            // Rating signal
            if ($rec['rating']['count'] > 0) {
                $why_signals[] = [
                    'type' => 'rating',
                    'value' => $rec['rating']['average'],
                    'count' => $rec['rating']['count'],
                    'label' => sprintf('%.1f/5 (%d avis)', $rec['rating']['average'], $rec['rating']['count']),
                ];
            }

            // Best-of signal
            $is_best = get_post_meta($prompt['id'], '_ml_is_best_of', true);
            if ($is_best) {
                $why_signals[] = [
                    'type' => 'best_of',
                    'value' => true,
                    'label' => 'Marqué meilleur exemple',
                ];
            }

            // Favorites signal
            $favorites_count = (int) get_post_meta($prompt['id'], '_ml_favorites_count', true);
            if ($favorites_count > 0) {
                $why_signals[] = [
                    'type' => 'favorites',
                    'value' => $favorites_count,
                    'label' => sprintf('%d favori(s)', $favorites_count),
                ];
            }

            // Recency signal
            if ($scores['recency'] > 0.8) {
                $why_signals[] = [
                    'type' => 'recency',
                    'value' => $scores['recency'],
                    'label' => 'Mis à jour récemment',
                ];
            }

            // Usage signal
            $usage_count = (int) get_post_meta($prompt['id'], '_ml_usage_count', true);
            if ($usage_count > 0) {
                $why_signals[] = [
                    'type' => 'usage',
                    'value' => $usage_count,
                    'label' => sprintf('Utilisé %d fois', $usage_count),
                ];
            }

            // Comments signal
            $post = get_post($prompt['id']);
            if ($post && $post->comment_count > 0) {
                $why_signals[] = [
                    'type' => 'comments',
                    'value' => $post->comment_count,
                    'label' => sprintf('%d commentaire(s)', $post->comment_count),
                ];
            }

            // User favorite signal
            if ($scores['favorites'] > 0) {
                $why_signals[] = [
                    'type' => 'user_favorite',
                    'value' => true,
                    'label' => 'Dans vos favoris',
                ];
            }

            // Text match signal
            if ($scores['text_match'] > 0.5) {
                $why_signals[] = [
                    'type' => 'text_match',
                    'value' => round($scores['text_match'] * 100),
                    'label' => 'Correspond bien à votre demande',
                ];
            }

            $recommendations[] = [
                'rank' => $rec['rank'],
                'recommended_tool' => [
                    'id' => $prompt['id'],
                    'name' => Prompts_Handler::get_name_prefix() . $prompt['id'],
                    'title' => $prompt['title'],
                    'excerpt' => $prompt['excerpt'] ?? '',
                    'url' => $prompt['url'],
                    'space_id' => $prompt['space_id'],
                    'step' => $prompt['step'],
                ],
                'prompt_publication_id' => $prompt['id'],
                'styles' => array_map(function ($style) {
                    return [
                        'id' => $style['id'],
                        'name' => $style['title'],
                        'variant' => $style['variant'],
                    ];
                }, $bundle['styles']),
                'content_bundle' => [
                    'items' => array_map(function ($content) {
                        return [
                            'id' => $content['id'],
                            'title' => $content['title'],
                            'type' => $content['type'],
                        ];
                    }, $bundle['contents']),
                    'style_count' => $bundle['style_count'],
                    'content_count' => $bundle['content_count'],
                ],
                'score' => round($rec['total_score'], 3),
                'why' => [
                    'summary' => $rec['why']['summary'] ?? '',
                    'factors' => $rec['why']['factors'] ?? [],
                    'signals' => $why_signals,
                    'score_breakdown' => [
                        'text_match' => round($scores['text_match'] * 100) . '%',
                        'rating' => round($scores['rating'] * 100) . '%',
                        'favorites' => $scores['favorites'] > 0 ? 'Oui' : 'Non',
                        'recency' => round($scores['recency'] * 100) . '%',
                        'usage' => round($scores['usage'] * 100) . '%',
                        'comments' => round($scores['comments'] * 100) . '%',
                    ],
                ],
            ];
        }

        // Build output
        $output = [
            'intent' => [
                'detected' => $result['intent']['detected'],
                'confidence' => $result['intent']['confidence'],
                'is_fallback' => $result['intent']['fallback'],
            ],
            'recommendations' => $recommendations,
            'total_candidates' => $result['total_candidates'],
        ];

        // Add next action hint
        if (!empty($recommendations)) {
            $top_rec = $recommendations[0];
            $output['next_action'] = [
                'tool' => 'ml_apply_tool_prepare',
                'args' => [
                    'tool_id' => $top_rec['prompt_publication_id'],
                    'input_text' => '{{your_input_here}}',
                ],
                'hint' => sprintf(
                    "Pour utiliser '%s', appelez ml_apply_tool_prepare avec tool_id=%d",
                    $top_rec['recommended_tool']['title'],
                    $top_rec['prompt_publication_id']
                ),
            ];
        }

        return Tool_Response::ok($output, $request_id, null, [
            'message' => sprintf(
                'Found %d recommendation(s) for your "%s" intent.',
                count($recommendations),
                $result['intent']['detected']
            ),
        ]);
    }

    /**
     * Handle ml_recommend_styles
     */
    private static function handle_styles(Recommendation_Service $service, array $args, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['prompt_id']);
        if ($validation) {
            return $validation;
        }

        $prompt_id = (int) $args['prompt_id'];
        $variants = $service->get_style_variants($prompt_id);

        if (empty($variants)) {
            return Tool_Response::empty_list(
                $request_id,
                'No style variants available for this prompt.'
            );
        }

        return Tool_Response::ok([
            'prompt_id' => $prompt_id,
            'variants' => $variants,
            'count' => count($variants),
            'next_action' => [
                'hint' => 'Pass style variant name to ml_apply_tool_prepare in options.style',
            ],
        ], $request_id);
    }
}
