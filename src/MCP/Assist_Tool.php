<?php
/**
 * Assist Tool - 1-call orchestrator for "wow" demo (TICKET 7)
 *
 * ml_assist_prepare: Single call that chains:
 *   1. ml_recommend (find best tool for intent)
 *   2. ml_apply_tool_prepare (build prompt with context)
 *   3. Returns everything ready for AI execution and commit
 *
 * Use case: "1 appel = wow" for sales demos
 * - Input: user's text + what they want to create
 * - Output: recommended tool + prepared prompt + citations + commit key
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Recommendation_Service;
use MCP_No_Headless\Picasso\Picasso_Adapter;
use MCP_No_Headless\Services\Approved_Steps_Resolver;

class Assist_Tool {

    /**
     * Session transient prefix (shared with Apply_Tool_V2)
     */
    private const SESSION_PREFIX = 'mcpnh_apply_v2_';

    /**
     * Session TTL in seconds (5 minutes)
     */
    private const SESSION_TTL = 300;

    /**
     * Rate limit: max assists per minute per user
     */
    private const RATE_LIMIT = 10;

    /**
     * Get tool definition for registration
     */
    public static function get_definition(): array {
        return [
            'name' => 'ml_assist_prepare',
            'description' => 'One-call AI assistant: analyzes your intent, selects the best tool, and prepares everything for execution. Returns recommended tool with WHY explanation, assembled prompt with citations, and idempotency_key for commit. Perfect for demos!',
            'category' => 'MaryLink Assistant',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'text' => [
                        'type' => 'string',
                        'description' => 'What you want to create (e.g., "une lettre commerciale pour Acme Corp qui vend des solutions cloud")',
                    ],
                    'space_id' => [
                        'type' => 'integer',
                        'description' => 'Optional: Limit search to a specific space',
                    ],
                    'options' => [
                        'type' => 'object',
                        'description' => 'Optional execution settings',
                        'properties' => [
                            'style' => [
                                'type' => 'string',
                                'description' => 'Style variant (e.g., premium, direct)',
                            ],
                            'language' => [
                                'type' => 'string',
                                'description' => 'Output language (e.g., Français, English)',
                            ],
                            'tone' => [
                                'type' => 'string',
                                'description' => 'Tone of voice (e.g., professional, casual)',
                            ],
                        ],
                    ],
                    'include' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Optional: ["debug"] to include diagnostic info (spaces_checked, candidates_scanned, top_scores, timing_ms)',
                    ],
                ],
                'required' => ['text'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
            ],
        ];
    }

    /**
     * Execute ml_assist_prepare
     *
     * Orchestrates the full flow:
     * 1. Detect intent and find best matching tool
     * 2. Build prepared prompt with context bundle
     * 3. Return everything in one response
     */
    public static function execute(array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();
        $start_time = microtime(true);

        // Rate limiting
        if (!self::check_rate_limit($user_id)) {
            return Tool_Response::error(
                'rate_limit_exceeded',
                'Rate limit exceeded. Please wait before trying again.',
                $request_id
            );
        }

        // Validate required fields
        // Accept both 'query' (schema) and 'text' (legacy)
        $args['text'] = $args['text'] ?? $args['query'] ?? '';
        $validation = Tool_Response::validate_required($args, ['text']);
        if ($validation) {
            return $validation;
        }

        $text = trim($args['text']);
        if (empty($text)) {
            return Tool_Response::error('validation_failed', 'Text cannot be empty', $request_id);
        }

        $space_id = isset($args['space_id']) ? (int) $args['space_id'] : null;
        $options = $args['options'] ?? [];

        // Handle include parameter for debug mode (PR ml_assist debug)
        $include = $args['include'] ?? [];
        $debug_mode = in_array('debug', $include, true);

        // Check if Recommendation Service is available
        if (!Recommendation_Service::is_available()) {
            return Tool_Response::error(
                'feature_unavailable',
                'AI Assistant is not available on this site.',
                $request_id
            );
        }

        $permissions = new Permission_Checker($user_id);

        // =========================================
        // STEP 1: Get recommendation (ml_recommend)
        // =========================================
        $service = new Recommendation_Service($user_id);
        $recommendation_result = $service->recommend($text, $space_id, [
            'limit' => 1,
            'debug' => $debug_mode,
        ]);

        if (empty($recommendation_result['recommendations'])) {
            return Tool_Response::error(
                'no_match',
                'No matching tool found for your request. Try being more specific.',
                $request_id,
                [
                    'intent' => $recommendation_result['intent'] ?? null,
                    'suggestions' => $recommendation_result['suggestions'] ?? [],
                ]
            );
        }

        $top_rec = $recommendation_result['recommendations'][0];
        $tool_id = (int) $top_rec['prompt']['id'];
        $tool_post = get_post($tool_id);

        if (!$tool_post) {
            return Tool_Response::error('not_found', 'Selected tool no longer available', $request_id);
        }

        // =============================================
        // STEP 2: Build prepared prompt (ml_apply_tool_prepare)
        // =============================================

        // Get prompt template
        $template = self::get_tool_template($tool_post);

        // Build context bundle
        $context_bundle = self::build_context_bundle($tool_id, $permissions, $options);

        // Build the prepared prompt
        $prepared_prompt = self::build_prepared_prompt(
            $template,
            $text,
            $context_bundle,
            $options
        );

        // Create idempotency key
        $tool_space_id = (int) $tool_post->post_parent;
        $idempotency_key = self::create_session([
            'user_id' => $user_id,
            'tool_id' => $tool_id,
            'space_id' => $tool_space_id,
            'input_hash' => hash('sha256', $text),
            'prompt_hash' => hash('sha256', $prepared_prompt),
            'options' => $options,
            'source' => 'ml_assist_prepare',
            'created_at' => time(),
        ]);

        // =========================================
        // STEP 3: Build comprehensive response
        // =========================================
        $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

        // Extract WHY signals from recommendation
        $why = $top_rec['why'] ?? [];
        $why_signals = $why['signals'] ?? [];

        // Build enhanced WHY explanation
        $why_explain = $why['explain'] ?? '';
        if (empty($why_explain) && !empty($why['summary'])) {
            $why_explain = $why['summary'];
        }

        // Build citations list
        $citations = [];
        foreach ($context_bundle['styles'] as $style) {
            $citations[] = [
                'type' => 'style',
                'id' => $style['id'],
                'title' => $style['title'],
                'variant' => $style['variant'],
            ];
        }
        foreach ($context_bundle['contents'] as $content) {
            $citations[] = [
                'type' => $content['type'],
                'id' => $content['id'],
                'title' => $content['title'],
            ];
        }

        // Build style options hint
        $style_options = array_map(fn($s) => $s['variant'], $context_bundle['styles']);

        return Tool_Response::ok([
            // The selected tool
            'recommended_tool' => [
                'id' => $tool_id,
                'title' => $tool_post->post_title,
                'excerpt' => \MCP_No_Headless\Services\Render_Service::excerpt_from_html($tool_post->post_content, 150),
                'space_id' => $tool_space_id,
                'step' => Picasso_Adapter::get_publication_step($tool_id),
            ],

            // WHY this tool was selected (B2B explainability)
            'why' => [
                'explain' => $why_explain,
                'signals' => $why_signals,
                'intent' => [
                    'detected' => $recommendation_result['intent']['detected'],
                    'confidence' => $recommendation_result['intent']['confidence'],
                ],
                'score' => round($top_rec['total_score'], 3),
            ],

            // The assembled prompt ready for AI execution
            'draft' => [
                'prepared_prompt' => $prepared_prompt,
                'prompt_length' => mb_strlen($prepared_prompt),
            ],

            // Citations for transparency
            'citations' => $citations,
            'citation_count' => count($citations),

            // For the commit phase
            'idempotency_key' => $idempotency_key,
            'expires_in' => self::SESSION_TTL,

            // Context bundle summary
            'bundle_summary' => [
                'style_count' => count($context_bundle['styles']),
                'content_count' => count($context_bundle['contents']),
                'style_options' => $style_options,
            ],

            // Next action (what to do after AI execution)
            'next_action' => [
                'instruction' => 'Execute the prepared_prompt with your LLM. Then call ml_apply_tool_commit to save the result.',
                'tool' => 'ml_apply_tool_commit',
                'args' => [
                    'idempotency_key' => $idempotency_key,
                    'final_text' => '{{LLM_OUTPUT}}',
                    'save_as' => 'publication',
                    'target' => [
                        'space_id' => $tool_space_id,
                        'title' => '{{GENERATED_TITLE}}',
                        'status' => 'draft',
                    ],
                ],
            ],

            // Performance
            'latency_ms' => $latency_ms,

            // Debug info (PR ml_assist debug)
        ] + ($debug_mode && isset($recommendation_result['debug']) ? ['debug' => $recommendation_result['debug']] : []), $request_id, null, [
            'message' => sprintf(
                'Ready! Using "%s" (score %.2f). Execute prepared_prompt, then commit.',
                $tool_post->post_title,
                $top_rec['total_score']
            ),
        ]);
    }

    /**
     * Build context bundle from linked publications
     */
    private static function build_context_bundle(int $tool_id, Permission_Checker $permissions, array $options): array {
        $styles = [];
        $contents = [];
        $assembled_parts = [];

        // Get requested style variant
        $requested_style = $options['style'] ?? null;

        // Get linked styles
        $linked_styles = Picasso_Adapter::get_tool_linked_styles($tool_id);
        foreach ($linked_styles as $style_id) {
            if ($permissions->can_see_publication($style_id)) {
                $style_post = get_post($style_id);
                if ($style_post) {
                    $variant = get_post_meta($style_id, '_ml_style_variant', true) ?: 'default';

                    // If specific style requested, filter
                    if ($requested_style && strtolower($variant) !== strtolower($requested_style)) {
                        continue;
                    }

                    $styles[] = [
                        'id' => $style_id,
                        'title' => $style_post->post_title,
                        'variant' => $variant,
                        'content' => strip_tags($style_post->post_content),
                    ];

                    // Add to assembled context
                    $assembled_parts[] = "## Style: {$style_post->post_title} ({$variant})\n" . strip_tags($style_post->post_content);
                }
            }
        }

        // Get linked contents (data, docs)
        $linked_contents = Picasso_Adapter::get_tool_linked_contents($tool_id);
        foreach ($linked_contents as $content_id) {
            if ($permissions->can_see_publication($content_id)) {
                $content_post = get_post($content_id);
                if ($content_post) {
                    $type = get_post_meta($content_id, '_ml_publication_type', true) ?: 'doc';

                    $content_text = strip_tags($content_post->post_content);
                    if (mb_strlen($content_text) > 1500) {
                        $content_text = mb_substr($content_text, 0, 1500) . '...';
                    }

                    $contents[] = [
                        'id' => $content_id,
                        'title' => $content_post->post_title,
                        'type' => $type,
                        'content' => $content_text,
                    ];

                    $assembled_parts[] = "## {$type}: {$content_post->post_title}\n{$content_text}";
                }
            }
        }

        return [
            'styles' => $styles,
            'contents' => $contents,
            'assembled' => implode("\n\n", $assembled_parts),
        ];
    }

    /**
     * Build the prepared prompt
     */
    private static function build_prepared_prompt(
        string $template,
        string $input_text,
        array $context_bundle,
        array $options
    ): string {
        $parts = [];

        // Add context bundle as preamble
        if (!empty($context_bundle['assembled'])) {
            $parts[] = "# Contexte\n\n" . $context_bundle['assembled'];
        }

        // Process template placeholders
        $prompt = $template;
        $placeholders = [
            '{{input}}' => $input_text,
            '{{INPUT}}' => $input_text,
            '{input}' => $input_text,
            '{{text}}' => $input_text,
            '{{TEXT}}' => $input_text,
            '{text}' => $input_text,
            '{{content}}' => $input_text,
            '{{CONTENT}}' => $input_text,
        ];

        foreach ($placeholders as $placeholder => $value) {
            $prompt = str_replace($placeholder, $value, $prompt);
        }

        // Handle options
        if (!empty($options['language'])) {
            $lang = sanitize_text_field($options['language']);
            $prompt = str_replace(['{{language}}', '{language}', '{{LANGUAGE}}'], $lang, $prompt);
            if (strpos($template, '{{language}}') === false && strpos($template, '{language}') === false) {
                $prompt .= "\n\nRéponds en {$lang}.";
            }
        }

        if (!empty($options['tone'])) {
            $tone = sanitize_text_field($options['tone']);
            $prompt = str_replace(['{{tone}}', '{tone}', '{{TONE}}'], $tone, $prompt);
        }

        // Check if template has placeholders
        $has_placeholder = false;
        foreach (array_keys($placeholders) as $p) {
            if (strpos($template, $p) !== false) {
                $has_placeholder = true;
                break;
            }
        }

        // If no placeholders, append input
        if (!$has_placeholder && !empty($input_text)) {
            $prompt .= "\n\n---\n\n" . $input_text;
        }

        $parts[] = "# Instructions\n\n" . $prompt;

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Get prompt template from tool publication
     */
    private static function get_tool_template(\WP_Post $tool): string {
        // Priority 1: _tool_prompt meta
        $prompt = get_post_meta($tool->ID, '_tool_prompt', true);
        if (!empty($prompt)) {
            return $prompt;
        }

        // Priority 2: Parse ## TEMPLATE section
        $content = $tool->post_content;
        if (preg_match('/## TEMPLATE\s*\n(.*?)(?=##|$)/si', $content, $matches)) {
            return trim($matches[1]);
        }

        // Priority 3: post_content
        return $content;
    }

    /**
     * Create session and return idempotency key
     */
    private static function create_session(array $data): string {
        $key = 'assist_' . bin2hex(random_bytes(16));
        set_transient(self::SESSION_PREFIX . $key, $data, self::SESSION_TTL);
        return $key;
    }

    /**
     * Check rate limit
     */
    private static function check_rate_limit(int $user_id): bool {
        $key = 'mcpnh_assist_rate_' . $user_id;
        $count = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT) {
            return false;
        }

        set_transient($key, $count + 1, 60);
        return true;
    }

    /**
     * Check if available
     */
    public static function is_available(): bool {
        return Recommendation_Service::is_available() && post_type_exists('publication');
    }
}
