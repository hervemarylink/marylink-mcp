<?php
/**
 * Apply Tool V2 - Execute tool/prompt with prepare/commit flow (tool-map v1)
 *
 * Separate tools for prepare and commit phases:
 * - ml_apply_tool_prepare: Build prompt with context bundle, return idempotency_key
 * - ml_apply_tool_commit: Save result with idempotency protection
 *
 * Output format: tool-map v1 envelope {ok, request_id, warnings, data, cursor}
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Picasso\Picasso_Adapter;
use MCP_No_Headless\Services\Approved_Steps_Resolver;

class Apply_Tool_V2 {

    /**
     * Session transient prefix
     */
    private const SESSION_PREFIX = 'mcpnh_apply_v2_';

    /**
     * Session TTL in seconds (5 minutes)
     */
    private const SESSION_TTL = 300;

    /**
     * Rate limit: max prepares per minute per user
     */
    private const RATE_LIMIT_PREPARES = 20;

    /**
     * Valid tool labels/types
     */
    private const VALID_TOOL_TYPES = ['tool', 'prompt', 'style', 'template'];

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_apply_tool_prepare' => [
                'name' => 'ml_apply_tool_prepare',
                'description' => 'Prepare a prompt/tool for execution. Returns the assembled prompt with context bundle and an idempotency_key for the commit phase.',
                'category' => 'MaryLink Tools',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'tool_id' => [
                            'type' => 'integer',
                            'description' => 'ID of the tool/prompt publication (from ml_recommend)',
                        ],
                        'input_text' => [
                            'type' => 'string',
                            'description' => 'User input text to apply the tool to',
                        ],
                        'options' => [
                            'type' => 'object',
                            'description' => 'Optional settings: style, language, tone, output_format',
                            'properties' => [
                                'style' => [
                                    'type' => 'string',
                                    'description' => 'Style variant name (from ml_recommend_styles)',
                                ],
                                'language' => [
                                    'type' => 'string',
                                    'description' => 'Output language (e.g., "Français", "English")',
                                ],
                                'tone' => [
                                    'type' => 'string',
                                    'description' => 'Tone of voice (e.g., professional, casual)',
                                ],
                                'output_format' => [
                                    'type' => 'string',
                                    'description' => 'Output format (e.g., markdown, plain)',
                                ],
                            ],
                        ],
                    ],
                    'required' => ['tool_id', 'input_text'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                ],
            ],

            'ml_apply_tool_commit' => [
                'name' => 'ml_apply_tool_commit',
                'description' => 'Save the generated result from a prepared tool execution. Requires idempotency_key from ml_apply_tool_prepare.',
                'category' => 'MaryLink Tools',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'idempotency_key' => [
                            'type' => 'string',
                            'description' => 'Key from ml_apply_tool_prepare (required)',
                        ],
                        'final_text' => [
                            'type' => 'string',
                            'description' => 'Generated result to save',
                        ],
                        'save_as' => [
                            'type' => 'string',
                            'enum' => ['none', 'publication', 'comment'],
                            'default' => 'none',
                            'description' => 'How to save: none (discard), publication, or comment',
                        ],
                        'target' => [
                            'type' => 'object',
                            'description' => 'Save target details',
                            'properties' => [
                                'space_id' => [
                                    'type' => 'integer',
                                    'description' => 'Space ID for publication',
                                ],
                                'title' => [
                                    'type' => 'string',
                                    'description' => 'Title for publication',
                                ],
                                'status' => [
                                    'type' => 'string',
                                    'enum' => ['draft', 'publish'],
                                    'default' => 'draft',
                                ],
                                'publication_id' => [
                                    'type' => 'integer',
                                    'description' => 'Publication ID for comment',
                                ],
                                'comment_type' => [
                                    'type' => 'string',
                                    'enum' => ['public', 'private'],
                                    'default' => 'public',
                                ],
                            ],
                        ],
                    ],
                    'required' => ['idempotency_key'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => true,
                ],
            ],
        ];
    }

    /**
     * Execute the tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();
        $permissions = new Permission_Checker($user_id);

        switch ($tool) {
            case 'ml_apply_tool_prepare':
                return self::handle_prepare($args, $user_id, $permissions, $request_id);

            case 'ml_apply_tool_commit':
                return self::handle_commit($args, $user_id, $permissions, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_apply_tool_prepare
     *
     * Returns:
     * - prepared_prompt: The assembled prompt with context
     * - context_bundle: Styles and data included
     * - idempotency_key: For the commit phase
     * - why: Why this tool was selected (if from ml_recommend)
     */
    private static function handle_prepare(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        // Rate limiting
        if (!self::check_rate_limit($user_id)) {
            return Tool_Response::error(
                'rate_limit_exceeded',
                'Rate limit exceeded. Please wait before trying again.',
                $request_id
            );
        }

        // Validate required fields
        $validation = Tool_Response::validate_required($args, ['tool_id', 'input_text']);
        if ($validation) {
            return $validation;
        }

        $tool_id = (int) $args['tool_id'];
        $input_text = $args['input_text'];
        $options = $args['options'] ?? [];

        // Get tool publication
        $tool_post = get_post($tool_id);
        if (!$tool_post || $tool_post->post_type !== 'publication') {
            return Tool_Response::error('not_found', 'Tool not found', $request_id);
        }

        // Permission check (anti-leak: same message for not found and forbidden)
        if (!$permissions->can_see_publication($tool_id)) {
            return Tool_Response::error('not_found', 'Tool not found', $request_id);
        }

        // Check if in approved step
        $space_id = (int) $tool_post->post_parent;
        $current_step = Picasso_Adapter::get_publication_step($tool_id);
        if ($current_step && !Approved_Steps_Resolver::is_step_approved($space_id, $current_step)) {
            return Tool_Response::error('not_found', 'Tool not found', $request_id);
        }

        // Get prompt template
        $template = self::get_tool_template($tool_post);

        // Build context bundle
        $context_bundle = self::build_context_bundle($tool_id, $permissions, $options);

        // Build the prepared prompt
        $prepared_prompt = self::build_prepared_prompt(
            $template,
            $input_text,
            $context_bundle,
            $options
        );

        // Create idempotency key (session)
        $idempotency_key = self::create_session([
            'user_id' => $user_id,
            'tool_id' => $tool_id,
            'space_id' => $space_id,
            'input_hash' => hash('sha256', $input_text),
            'prompt_hash' => hash('sha256', $prepared_prompt),
            'options' => $options,
            'created_at' => time(),
        ]);

        // Build WHY section
        $why = self::build_why_section($tool_post, $tool_id);

        // Input preview
        $input_preview = mb_strlen($input_text) > 100
            ? mb_substr($input_text, 0, 100) . '...'
            : $input_text;

        return Tool_Response::ok([
            'prepared_prompt' => $prepared_prompt,
            'context_bundle' => [
                'styles' => $context_bundle['styles'],
                'contents' => $context_bundle['contents'],
                'style_count' => count($context_bundle['styles']),
                'content_count' => count($context_bundle['contents']),
                'citations' => $context_bundle['citations'],
            ],
            'idempotency_key' => $idempotency_key,
            'tool_info' => [
                'id' => $tool_id,
                'title' => $tool_post->post_title,
                'space_id' => $space_id,
                'step' => $current_step,
            ],
            'input_preview' => $input_preview,
            'expires_in' => self::SESSION_TTL,
            'why' => $why,
            'next_action' => [
                'tool' => 'ml_apply_tool_commit',
                'args' => [
                    'idempotency_key' => $idempotency_key,
                    'final_text' => '{{generated_output}}',
                    'save_as' => 'publication',
                    'target' => [
                        'space_id' => $space_id,
                        'title' => '{{title}}',
                    ],
                ],
                'hint' => 'Execute the prepared_prompt with your LLM, then call ml_apply_tool_commit to save the result.',
            ],
        ], $request_id);
    }

    /**
     * Handle ml_apply_tool_commit
     */
    private static function handle_commit(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        // Validate required fields
        $validation = Tool_Response::validate_required($args, ['idempotency_key']);
        if ($validation) {
            return $validation;
        }

        $idempotency_key = $args['idempotency_key'];
        $final_text = $args['final_text'] ?? '';
        $save_as = $args['save_as'] ?? 'none';
        $target = $args['target'] ?? [];

        // Validate session
        $session = self::validate_session($idempotency_key, $user_id);
        if (!$session) {
            return Tool_Response::error(
                'session_expired',
                'Session expired or invalid. Please run ml_apply_tool_prepare again.',
                $request_id
            );
        }

        // Clean up session immediately (one-time use)
        self::cleanup_session($idempotency_key);

        // Handle save_as
        switch ($save_as) {
            case 'none':
                return Tool_Response::ok([
                    'saved' => false,
                    'message' => 'Session closed. No content saved.',
                    'tool_id' => $session['tool_id'],
                ], $request_id);

            case 'publication':
                return self::save_as_publication($final_text, $target, $session, $user_id, $permissions, $request_id);

            case 'comment':
                return self::save_as_comment($final_text, $target, $session, $user_id, $permissions, $request_id);

            default:
                return Tool_Response::error(
                    'validation_failed',
                    "Invalid save_as value. Use 'none', 'publication', or 'comment'.",
                    $request_id
                );
        }
    }

    /**
     * Build context bundle from linked publications
     */
    private static function build_context_bundle(int $tool_id, Permission_Checker $permissions, array $options): array {
        $styles = [];
        $contents = [];
        $citations = [];
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

                    $citations[] = [
                        'type' => 'style',
                        'id' => $style_id,
                        'title' => $style_post->post_title,
                        'url' => get_permalink($style_id),
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

                    $citations[] = [
                        'type' => $type,
                        'id' => $content_id,
                        'title' => $content_post->post_title,
                        'url' => get_permalink($content_id),
                    ];

                    $assembled_parts[] = "## {$type}: {$content_post->post_title}\n{$content_text}";
                }
            }
        }

        return [
            'styles' => $styles,
            'contents' => $contents,
            'citations' => $citations,
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

        if (!empty($options['output_format'])) {
            $format = sanitize_text_field($options['output_format']);
            $prompt = str_replace(['{{format}}', '{format}', '{{FORMAT}}', '{{output_format}}'], $format, $prompt);
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
     * Build WHY section
     */
    private static function build_why_section(\WP_Post $post, int $publication_id): array {
        $signals = [];

        // Rating
        $avg_rating = (float) get_post_meta($publication_id, '_ml_average_rating', true);
        $rating_count = (int) get_post_meta($publication_id, '_ml_rating_count', true);
        if ($rating_count > 0) {
            $signals[] = [
                'type' => 'rating',
                'value' => $avg_rating,
                'count' => $rating_count,
                'label' => sprintf('%.1f/5 (%d avis)', $avg_rating, $rating_count),
            ];
        }

        // Best-of
        $is_best = get_post_meta($publication_id, '_ml_is_best_of', true);
        if ($is_best) {
            $signals[] = ['type' => 'best_of', 'value' => true, 'label' => 'Meilleur exemple'];
        }

        // Usage
        $usage_count = (int) get_post_meta($publication_id, '_ml_usage_count', true);
        if ($usage_count > 0) {
            $signals[] = ['type' => 'usage', 'value' => $usage_count, 'label' => sprintf('Utilisé %d fois', $usage_count)];
        }

        return [
            'signals' => $signals,
            'summary' => !empty($signals) ? 'Outil recommandé basé sur: ' . implode(', ', array_column($signals, 'label')) : '',
        ];
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
     * Save result as publication
     */
    private static function save_as_publication(
        string $final_text,
        array $target,
        array $session,
        int $user_id,
        Permission_Checker $permissions,
        string $request_id
    ): array {
        $space_id = (int) ($target['space_id'] ?? $session['space_id'] ?? 0);
        $title = sanitize_text_field($target['title'] ?? '');
        $status = ($target['status'] ?? 'draft') === 'publish' ? 'publish' : 'draft';

        if (empty($title)) {
            return Tool_Response::error('validation_failed', 'Title is required for publication.', $request_id);
        }

        if ($space_id <= 0 || !$permissions->can_see_space($space_id)) {
            return Tool_Response::error('validation_failed', 'Valid space_id is required.', $request_id);
        }

        // Check publish permission
        if (!$permissions->can_publish_in_space($space_id)) {
            return Tool_Response::error('permission_denied', "You don't have permission to publish in this space.", $request_id);
        }

        $content = wp_kses_post($final_text);

        $post_data = [
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => $status,
            'post_author' => $user_id,
            'post_type' => 'publication',
            'post_parent' => $space_id,
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return Tool_Response::error('save_failed', $post_id->get_error_message(), $request_id);
        }

        // Set meta
        update_post_meta($post_id, '_publication_space', $space_id);
        update_post_meta($post_id, '_publication_step', 'submit');
        update_post_meta($post_id, '_generated_by_tool', $session['tool_id']);
        update_post_meta($post_id, '_generated_at', current_time('mysql'));

        // Increment tool usage count
        $current_usage = (int) get_post_meta($session['tool_id'], '_ml_usage_count', true);
        update_post_meta($session['tool_id'], '_ml_usage_count', $current_usage + 1);

        // Trigger Picasso hooks
        do_action('pb_post_saved', $post_id, false);

        return Tool_Response::ok([
            'saved' => true,
            'save_type' => 'publication',
            'post_id' => $post_id,
            'title' => $title,
            'status' => $status,
            'space_id' => $space_id,
            'url' => get_permalink($post_id),
            'tool_id' => $session['tool_id'],
            'message' => "Publication '{$title}' created successfully.",
        ], $request_id);
    }

    /**
     * Save result as comment
     */
    private static function save_as_comment(
        string $final_text,
        array $target,
        array $session,
        int $user_id,
        Permission_Checker $permissions,
        string $request_id
    ): array {
        $publication_id = (int) ($target['publication_id'] ?? 0);
        $comment_type = $target['comment_type'] ?? 'public';

        if ($publication_id <= 0) {
            return Tool_Response::error('validation_failed', 'Valid publication_id is required.', $request_id);
        }

        // Check publication access
        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication' || !$permissions->can_see_publication($publication_id)) {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        $user = get_userdata($user_id);
        $content = sanitize_textarea_field($final_text);
        $content .= "\n\n---\n*Généré avec MaryLink AI*";

        $comment_data = [
            'comment_post_ID' => $publication_id,
            'comment_content' => $content,
            'comment_author' => $user->display_name,
            'comment_author_email' => $user->user_email,
            'user_id' => $user_id,
            'comment_approved' => 1,
        ];

        $comment_id = wp_insert_comment($comment_data);

        if (!$comment_id) {
            return Tool_Response::error('save_failed', 'Failed to create comment.', $request_id);
        }

        // Set comment type meta
        if ($comment_type === 'private') {
            update_comment_meta($comment_id, '_comment_type', 'private');
        }

        update_comment_meta($comment_id, '_generated_by_tool', $session['tool_id']);

        // Increment tool usage count
        $current_usage = (int) get_post_meta($session['tool_id'], '_ml_usage_count', true);
        update_post_meta($session['tool_id'], '_ml_usage_count', $current_usage + 1);

        return Tool_Response::ok([
            'saved' => true,
            'save_type' => 'comment',
            'comment_id' => $comment_id,
            'publication_id' => $publication_id,
            'comment_type' => $comment_type,
            'tool_id' => $session['tool_id'],
            'message' => 'Comment added successfully.',
        ], $request_id);
    }

    /**
     * Create session and return idempotency key
     */
    private static function create_session(array $data): string {
        $key = 'apply_' . bin2hex(random_bytes(16));
        set_transient(self::SESSION_PREFIX . $key, $data, self::SESSION_TTL);
        return $key;
    }

    /**
     * Validate session
     */
    private static function validate_session(string $key, int $user_id): ?array {
        if (empty($key) || strpos($key, 'apply_') !== 0) {
            return null;
        }

        $session = get_transient(self::SESSION_PREFIX . $key);
        if (!$session || !is_array($session)) {
            return null;
        }

        // Verify session belongs to current user
        if (($session['user_id'] ?? 0) !== $user_id) {
            return null;
        }

        return $session;
    }

    /**
     * Clean up session
     */
    private static function cleanup_session(string $key): void {
        delete_transient(self::SESSION_PREFIX . $key);
    }

    /**
     * Check rate limit
     */
    private static function check_rate_limit(int $user_id): bool {
        $key = 'mcpnh_apply_v2_rate_' . $user_id;
        $count = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT_PREPARES) {
            return false;
        }

        set_transient($key, $count + 1, 60);
        return true;
    }

    /**
     * Check if available
     */
    public static function is_available(): bool {
        return post_type_exists('publication');
    }
}
