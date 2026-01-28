<?php
/**
 * ml_run - Polyvalent execution tool
 *
 * Executes AI tools with flexible input sources (text, publication, batch).
 * Supports chaining, auto-context injection, and save-to-space.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Tools;

use MCP_No_Headless\MCP\Core\Tool_Response;
use MCP_No_Headless\MCP\Core\Services\Entity_Detector;
use MCP_No_Headless\MCP\Core\Services\Business_Context_Service;
use MCP_No_Headless\MCP\Core\Services\Job_Manager;
use MCP_No_Headless\Picasso\Picasso_Adapter;

class Run {

    const TOOL_NAME = 'ml_run';
    const VERSION = '3.2.25';

    const MODE_SYNC = 'sync';
    const MODE_ASYNC = 'async';
    const MODE_DELEGATE = 'delegate';

    const INPUT_TYPE_TEXT = 'text';
    const INPUT_TYPE_PUBLICATION = 'publication';
    const INPUT_TYPE_BATCH = 'batch';

    const DEFAULT_MODEL = 'gpt-4o-mini';
    const MAX_CHAIN_DEPTH = 5;
    const MAX_BATCH_SIZE = 10;

    /**
     * Execute run operation
     *
     * @param array $args Tool arguments
     * @param int $user_id Current user ID
     * @return array Execution result or job info
     */
    public static function execute(array $args, int $user_id): array {
        $start_time = microtime(true);

        if ($user_id <= 0) {
            return Tool_Response::auth_error('Authentification requise pour ml_run');
        }

        // Parse arguments
        $tool_id = isset($args['tool_id']) ? (int) $args['tool_id'] : null;
        $prompt = $args['prompt'] ?? null;
        $input = $args['input'] ?? null;
        $source_id = isset($args['source_id']) ? (int) $args['source_id'] : null;
        $source_ids = $args['source_ids'] ?? null;
        $chain = $args['chain'] ?? null;
        $save_to = isset($args['save_to']) ? (int) $args['save_to'] : null;
        $mode = $args['mode'] ?? self::MODE_SYNC;
        $model = $args['model'] ?? null;
        $context = $args['context'] ?? [];
        $options = $args['options'] ?? [];

        // Validate: need tool_id or prompt
        if (!$tool_id && !$prompt) {
            return Tool_Response::validation_error(
                'tool_id ou prompt requis',
                ['tool_id' => 'Un des deux est obligatoire', 'prompt' => 'Un des deux est obligatoire']
            );
        }

        // Validate: need some input
        if (!$input && !$source_id && !$source_ids) {
            return Tool_Response::validation_error(
                'Input requis',
                ['input' => 'Un parmi input, source_id, source_ids est obligatoire']
            );
        }

        // Determine input type and resolve content
        $resolved_input = self::resolve_input($input, $source_id, $source_ids, $user_id);
        if (!$resolved_input['success']) {
            return $resolved_input;
        }

        // Check quotas before execution
        $quota_check = self::check_quota($user_id);
        if (!$quota_check['allowed']) {
            return Tool_Response::quota_exceeded('ai_tokens', $quota_check['used'] ?? 0, $quota_check['limit'] ?? 0);
        }

        // Handle delegate mode - returns assembled prompt for Claude to execute
        if ($mode === self::MODE_DELEGATE) {
            return self::execute_delegate(
                $tool_id,
                $prompt,
                $resolved_input,
                $model,
                $context,
                $options,
                $user_id
            );
        }

        // Handle async mode
        if ($mode === self::MODE_ASYNC) {
            return self::queue_async_job($args, $user_id);
        }

        // Execute synchronously
        $result = self::execute_sync(
            $tool_id,
            $prompt,
            $resolved_input,
            $model,
            $context,
            $options,
            $user_id
        );

        if (!$result['success']) {
            return $result;
        }

        // Handle chaining
        if ($chain && !empty($result['output'])) {
            $result = self::execute_chain($chain, $result['output'], $user_id, $context);
            if (!$result['success']) {
                return $result;
            }
        }

        // Auto-save if requested
        if ($save_to && !empty($result['output'])) {
            $save_result = self::auto_save($result['output'], $save_to, $tool_id, $user_id);
            $result['saved'] = $save_result;
        }

        $latency_ms = round((microtime(true) - $start_time) * 1000);
        $result['latency_ms'] = $latency_ms;

        return $result;
    }

    /**
     * Resolve input from various sources
     */
    private static function resolve_input(?string $input, ?int $source_id, ?array $source_ids, int $user_id): array {
        // Direct text input
        if ($input !== null) {
            return [
                'success' => true,
                'type' => self::INPUT_TYPE_TEXT,
                'content' => $input,
                'source' => null,
            ];
        }

        // Single publication
        if ($source_id !== null) {
            $publication = self::get_publication_content($source_id, $user_id);
            if (!$publication) {
                return self::error('source_not_found', "Publication #$source_id not found or inaccessible");
            }

            return [
                'success' => true,
                'type' => self::INPUT_TYPE_PUBLICATION,
                'content' => $publication['content'],
                'source' => $publication,
            ];
        }

        // Batch publications
        if ($source_ids !== null) {
            if (!is_array($source_ids)) {
                return self::error('invalid_source_ids', 'source_ids must be an array');
            }

            if (count($source_ids) > self::MAX_BATCH_SIZE) {
                return self::error('batch_too_large', 'Maximum ' . self::MAX_BATCH_SIZE . ' sources allowed');
            }

            $contents = [];
            $sources = [];

            foreach ($source_ids as $id) {
                $publication = self::get_publication_content((int) $id, $user_id);
                if ($publication) {
                    $contents[] = "=== Publication #{$id}: {$publication['title']} ===\n{$publication['content']}";
                    $sources[] = $publication;
                }
            }

            if (empty($contents)) {
                return self::error('no_valid_sources', 'No valid publications found');
            }

            return [
                'success' => true,
                'type' => self::INPUT_TYPE_BATCH,
                'content' => implode("\n\n---\n\n", $contents),
                'source' => $sources,
                'count' => count($sources),
            ];
        }

        return self::error('no_input', 'No input provided');
    }

    /**
     * Get publication content with access check
     */
    private static function get_publication_content(int $id, int $user_id): ?array {
        $post = get_post($id);

        if (!$post) {
            return null;
        }

        // Check access
        $visibility = get_post_meta($id, '_ml_visibility', true) ?: 'public';

        if ($visibility === 'private' && (int) $post->post_author !== $user_id && !user_can($user_id, 'manage_options')) {
            return null;
        }

        if ($visibility === 'space') {
            $space_id = get_post_meta($id, '_ml_space_id', true);
            if ($space_id && function_exists('groups_is_user_member') && !groups_is_user_member($user_id, $space_id)) {
                return null;
            }
        }

        return [
            'id' => $id,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'author_id' => (int) $post->post_author,
            'space_id' => (int) get_post_meta($id, '_ml_space_id', true),
        ];
    }

    /**
     * Execute in delegate mode - returns assembled prompt for Claude to execute natively
     * No external API call is made - Claude will process the prompt directly
     *
     * @since 3.2.25
     */
    private static function execute_delegate(
        ?int $tool_id,
        ?string $prompt,
        array $resolved_input,
        ?string $model,
        array $context,
        array $options,
        int $user_id
    ): array {
        $content = $resolved_input['content'];

        // PR3: Auto-inject business context if not disabled
        $auto_context = $options['auto_context'] ?? true;

        if ($auto_context && class_exists(Entity_Detector::class) && class_exists(Business_Context_Service::class)) {
            $detected = Entity_Detector::detect($content, $user_id);
            if (!empty($detected['entities'])) {
                $biz_context = Business_Context_Service::build_context($detected, $user_id);
                if (!empty($biz_context)) {
                    $injection_style = $options['context_style'] ?? Business_Context_Service::STYLE_PREFIX;
                    $content = Business_Context_Service::inject_context($content, $biz_context, $injection_style);
                }
            }
        }

        // Build execution prompt
        $assembly_meta = [];

        if ($tool_id) {
            $tool = get_post($tool_id);

            // Picasso model: tools/prompts are publications with publication_label taxonomy
            $is_valid_tool = $tool && $tool->post_type === 'publication' && (
                has_term('tool', 'publication_label', $tool->ID) ||
                has_term('prompt', 'publication_label', $tool->ID)
            );

            if (!$is_valid_tool) {
                return self::error('tool_not_found', "Tool/Prompt #$tool_id not found or not executable");
            }

            // Get tool instruction (from meta or post_content)
            $tool_prompt = Picasso_Adapter::get_tool_instruction($tool_id) ?? $tool->post_content;
            $tool_model = get_post_meta($tool_id, '_ml_tool_model', true);

            // Load linked contents and styles (composite tool support)
            $linked_content_ids = Picasso_Adapter::get_tool_linked_contents($tool_id);
            $linked_style_ids = Picasso_Adapter::get_tool_linked_styles($tool_id);

            $resolved_contents = self::resolve_linked_publications($linked_content_ids, $user_id);
            $resolved_styles = self::resolve_linked_publications($linked_style_ids, $user_id);

            $model = $model ?? $tool_model ?? 'delegate';
            $final_prompt = self::build_composite_prompt(
                $tool_prompt,
                $resolved_contents,
                $resolved_styles,
                $content,
                $context
            );

            $assembly_meta = [
                'tool_id' => $tool_id,
                'tool_title' => $tool->post_title,
                'content_ids' => $linked_content_ids,
                'style_ids' => $linked_style_ids,
                'resolved_contents' => count($resolved_contents),
                'resolved_styles' => count($resolved_styles),
            ];
        } else {
            // Direct prompt execution
            $model = $model ?? 'delegate';
            $final_prompt = self::build_direct_prompt($prompt, $content, $context);
        }

        // Inject business context
        $final_prompt = self::inject_business_context($final_prompt, $user_id, $context);

        // Return assembled prompt for Claude to execute
        return Tool_Response::ok([
            'mode' => 'delegate',
            'ready_to_execute' => true,
            'assembled_prompt' => $final_prompt,
            'input_type' => $resolved_input['type'],
            'input_preview' => mb_substr($content, 0, 200) . (mb_strlen($content) > 200 ? '...' : ''),
            'suggested_model' => $model,
            'assembly' => $assembly_meta,
            'instructions' => 'Execute ce prompt en utilisant ta capacite native. Le prompt complet est dans assembled_prompt.',
        ]);
    }

    /**
     * Execute synchronously
     */
    private static function execute_sync(
        ?int $tool_id,
        ?string $prompt,
        array $resolved_input,
        ?string $model,
        array $context,
        array $options,
        int $user_id
    ): array {
        $content = $resolved_input['content'];

        // PR3: Auto-inject business context if not disabled
        $auto_context = $options['auto_context'] ?? true;
        $injected_context = null;

        if ($auto_context && class_exists(Entity_Detector::class) && class_exists(Business_Context_Service::class)) {
            // Detect entities in input content
            $detected = Entity_Detector::detect($content, $user_id);

            if (!empty($detected['entities'])) {
                // Build and inject context
                $biz_context = Business_Context_Service::build_context($detected, $user_id);

                if (!empty($biz_context)) {
                    // Inject context prefix into content
                    $injection_style = $options['context_style'] ?? Business_Context_Service::STYLE_PREFIX;
                    $content = Business_Context_Service::inject_context($content, $biz_context, $injection_style);

                    // Store for response metadata
                    $injected_context = [
                        'entities_detected' => array_keys(array_filter($detected['entities'])),
                        'context_injected' => true,
                        'style' => $injection_style,
                    ];
                }
            }
        }

        // Build execution prompt
        if ($tool_id) {
            $tool = get_post($tool_id);

            // Picasso model: tools/prompts are publications with publication_label taxonomy
            $is_valid_tool = $tool && $tool->post_type === 'publication' && (
                has_term('tool', 'publication_label', $tool->ID) ||
                has_term('prompt', 'publication_label', $tool->ID)
            );

            if (!$is_valid_tool) {
                return self::error('tool_not_found', "Tool/Prompt #$tool_id not found or not executable");
            }

            // Get tool instruction (from meta or post_content)
            $tool_prompt = Picasso_Adapter::get_tool_instruction($tool_id) ?? $tool->post_content;
            $tool_model = get_post_meta($tool_id, '_ml_tool_model', true);

            // Load linked contents and styles (composite tool support)
            $linked_content_ids = Picasso_Adapter::get_tool_linked_contents($tool_id);
            $linked_style_ids = Picasso_Adapter::get_tool_linked_styles($tool_id);

            $resolved_contents = self::resolve_linked_publications($linked_content_ids, $user_id);
            $resolved_styles = self::resolve_linked_publications($linked_style_ids, $user_id);

            $model = $model ?? $tool_model ?? self::DEFAULT_MODEL;
            $final_prompt = self::build_composite_prompt(
                $tool_prompt,
                $resolved_contents,
                $resolved_styles,
                $content,
                $context
            );

            // Track assembly metadata for debug
            $assembly_meta = [
                'tool_id' => $tool_id,
                'content_ids' => $linked_content_ids,
                'style_ids' => $linked_style_ids,
                'resolved_contents' => count($resolved_contents),
                'resolved_styles' => count($resolved_styles),
            ];
        } else {
            // Direct prompt execution
            $model = $model ?? self::DEFAULT_MODEL;
            $final_prompt = self::build_direct_prompt($prompt, $content, $context);
        }

        // Inject business context
        $final_prompt = self::inject_business_context($final_prompt, $user_id, $context);

        // Call AI
        $ai_result = self::call_ai($model, $final_prompt, $options, $user_id);

        if (!$ai_result['success']) {
            return $ai_result;
        }

        $result = [
            'success' => true,
            'output' => $ai_result['content'],
            'input_type' => $resolved_input['type'],
            'model' => $model,
            'tool_id' => $tool_id,
            'tokens' => $ai_result['tokens'] ?? null,
        ];

        // PR3: Add context metadata if context was injected
        if ($injected_context) {
            $result['context'] = $injected_context;
        }

        return $result;
    }

    /**
     * Build prompt from tool configuration
     */
    private static function build_tool_prompt(string $tool_prompt, ?string $style, string $content, array $context): string {
        $prompt = $tool_prompt;

        // Replace placeholders
        $prompt = str_replace('{{input}}', $content, $prompt);
        $prompt = str_replace('{{content}}', $content, $prompt);

        // Add style instructions
        if ($style) {
            $prompt .= "\n\nStyle: $style";
        }

        // Add context if provided
        if (!empty($context['instructions'])) {
            $prompt .= "\n\nAdditional instructions: " . $context['instructions'];
        }

        return $prompt;
    }

    /**
     * Build direct prompt
     */
    private static function build_direct_prompt(string $prompt, string $content, array $context): string {
        $full_prompt = $prompt;

        if (!empty($content)) {
            $full_prompt .= "\n\n---\nContent to process:\n\n$content";
        }

        if (!empty($context['instructions'])) {
            $full_prompt .= "\n\nAdditional instructions: " . $context['instructions'];
        }

        return $full_prompt;
    }

    /**
     * Inject business context into prompt
     */
    private static function inject_business_context(string $prompt, int $user_id, array $context): string {
        // Get user's business context
        $business_context = [];

        // Client context
        if (!empty($context['client_id'])) {
            $client = self::get_entity_context('client', $context['client_id']);
            if ($client) {
                $business_context[] = "Client: {$client['name']}";
                if (!empty($client['industry'])) {
                    $business_context[] = "Industry: {$client['industry']}";
                }
            }
        }

        // Project context
        if (!empty($context['project_id'])) {
            $project = self::get_entity_context('project', $context['project_id']);
            if ($project) {
                $business_context[] = "Project: {$project['name']}";
            }
        }

        // Space context
        if (!empty($context['space_id'])) {
            if (function_exists('groups_get_group')) {
                $group = groups_get_group($context['space_id']);
                if ($group && $group->id) {
                    $business_context[] = "Context: {$group->name}";
                }
            }
        }

        if (!empty($business_context)) {
            $context_text = implode("\n", $business_context);
            $prompt = "=== Business Context ===\n$context_text\n\n=== Task ===\n$prompt";
        }

        return $prompt;
    }

    /**
     * Get entity context (stub for Entity_Detector)
     */
    private static function get_entity_context(string $type, int $id): ?array {
        // This will be replaced by Entity_Detector service
        $meta_key = "_ml_{$type}_data";
        $data = get_option("ml_{$type}_{$id}", null);

        if (!$data) {
            // Try post meta
            $post = get_post($id);
            if ($post) {
                return [
                    'id' => $id,
                    'name' => $post->post_title,
                    'type' => $type,
                ];
            }
        }

        return $data;
    }

    /**
     * Call AI provider
     */
    private static function call_ai(string $model, string $prompt, array $options, int $user_id): array {
        // Determine provider
        $is_claude = str_starts_with($model, 'claude');

        if ($is_claude) {
            return self::call_anthropic($model, $prompt, $options, $user_id);
        }

        return self::call_openai($model, $prompt, $options, $user_id);
    }

    /**
     * Call OpenAI API
     */
    private static function call_openai(string $model, string $prompt, array $options, int $user_id): array {
        $api_key = get_option('openai_api_key') ?: get_option('mcpnh_openai_api_key');

        if (!$api_key) {
            return self::error('no_api_key', 'OpenAI API key not configured');
        }

        $temperature = $options['temperature'] ?? 0.7;
        $max_tokens = $options['max_tokens'] ?? 2000;

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => "Bearer $api_key",
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => $temperature,
                'max_tokens' => $max_tokens,
            ]),
        ]);

        if (is_wp_error($response)) {
            return self::error('api_error', $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return self::error('openai_error', $body['error']['message'] ?? 'Unknown error');
        }

        $content = $body['choices'][0]['message']['content'] ?? '';
        $tokens = $body['usage']['total_tokens'] ?? 0;

        // Track usage
        self::track_ai_usage($user_id, $model, $tokens);

        return [
            'success' => true,
            'content' => $content,
            'tokens' => $tokens,
            'model' => $model,
        ];
    }

    /**
     * Call Anthropic API
     */
    private static function call_anthropic(string $model, string $prompt, array $options, int $user_id): array {
        $api_key = get_option('anthropic_api_key') ?: get_option('mcpnh_anthropic_api_key');

        if (!$api_key) {
            return self::error('no_api_key', 'Anthropic API key not configured');
        }

        $max_tokens = $options['max_tokens'] ?? 2000;

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 120,
            'headers' => [
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'max_tokens' => $max_tokens,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return self::error('api_error', $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return self::error('anthropic_error', $body['error']['message'] ?? 'Unknown error');
        }

        $content = $body['content'][0]['text'] ?? '';
        $tokens = ($body['usage']['input_tokens'] ?? 0) + ($body['usage']['output_tokens'] ?? 0);

        // Track usage
        self::track_ai_usage($user_id, $model, $tokens);

        return [
            'success' => true,
            'content' => $content,
            'tokens' => $tokens,
            'model' => $model,
        ];
    }

    /**
     * Execute tool chain
     */
    private static function execute_chain(array $chain, string $input, int $user_id, array $context, int $depth = 0): array {
        if ($depth >= self::MAX_CHAIN_DEPTH) {
            return self::error('chain_too_deep', 'Maximum chain depth exceeded');
        }

        foreach ($chain as $step) {
            $tool_id = $step['tool_id'] ?? null;
            $prompt = $step['prompt'] ?? null;

            if (!$tool_id && !$prompt) {
                continue;
            }

            $resolved_input = [
                'success' => true,
                'type' => self::INPUT_TYPE_TEXT,
                'content' => $input,
                'source' => null,
            ];

            $result = self::execute_sync(
                $tool_id,
                $prompt,
                $resolved_input,
                $step['model'] ?? null,
                $context,
                $step['options'] ?? [],
                $user_id
            );

            if (!$result['success']) {
                return $result;
            }

            $input = $result['output'];
        }

        return [
            'success' => true,
            'output' => $input,
            'chain_steps' => count($chain),
        ];
    }

    /**
     * Auto-save result to space
     */
    private static function auto_save(string $output, int $space_id, ?int $tool_id, int $user_id): array {
        $save_args = [
            'content' => $output,
            'space_id' => $space_id,
            'visibility' => 'space',
            'tool_id' => $tool_id,
        ];

        return Save::execute($save_args, $user_id);
    }

    /**
     * Queue async job
     * PR4: Uses Job_Manager service for real async execution
     */
    private static function queue_async_job(array $args, int $user_id): array {
        // PR4: Use Job_Manager service if available
        if (class_exists(Job_Manager::class)) {
            $job_data = [
                'tool' => self::TOOL_NAME,
                'args' => $args,
                'webhook_url' => $args['webhook_url'] ?? null,
                'timeout' => $args['timeout'] ?? Job_Manager::DEFAULT_TIMEOUT,
            ];

            $job_id = Job_Manager::create($user_id, $job_data);

            if (!$job_id) {
                return Tool_Response::error(
                    Tool_Response::ERROR_INTERNAL,
                    'Impossible de créer le job async'
                );
            }

            // Return standardized async response
            return Tool_Response::async_job(
                $job_id,
                'pending',
                'Le job sera traité en arrière-plan'
            );
        }

        // Fallback: legacy implementation
        global $wpdb;

        $table = $wpdb->prefix . 'mcpnh_jobs';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return self::error('async_not_available', 'Async execution not configured');
        }

        $job_id = wp_generate_uuid4();

        $result = $wpdb->insert($table, [
            'job_id' => $job_id,
            'user_id' => $user_id,
            'tool_name' => self::TOOL_NAME,
            'args' => wp_json_encode($args),
            'status' => 'pending',
            'progress' => 0,
            'created_at' => current_time('mysql'),
        ]);

        if ($result === false) {
            return self::error('job_queue_failed', 'Failed to queue job');
        }

        // Schedule job processing
        wp_schedule_single_event(time(), 'mcpnh_process_job', [$job_id]);

        return Tool_Response::async_job($job_id, 'pending');
    }

    /**
     * Check user quota
     */
    private static function check_quota(int $user_id): array {
        global $wpdb;

        $daily_limit = (int) get_option('mcpnh_daily_token_limit', 100000);

        if ($daily_limit <= 0) {
            return ['allowed' => true];
        }

        $table = $wpdb->prefix . 'marylink_ia_usage';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return ['allowed' => true];
        }

        $today = date('Y-m-d');
        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(tokens_used), 0) FROM $table WHERE user_id = %d AND DATE(created_at) = %s",
            $user_id, $today
        ));

        if ($used >= $daily_limit) {
            return [
                'allowed' => false,
                'message' => "Daily token limit reached ($used / $daily_limit)",
            ];
        }

        return [
            'allowed' => true,
            'remaining' => $daily_limit - $used,
        ];
    }

    /**
     * Track AI usage
     */
    private static function track_ai_usage(int $user_id, string $model, int $tokens): void {
        global $wpdb;

        $table = $wpdb->prefix . 'marylink_ia_usage';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }

        // Calculate approximate cost
        $cost = self::estimate_cost($model, $tokens);

        $wpdb->insert($table, [
            'user_id' => $user_id,
            'model' => $model,
            'tokens_used' => $tokens,
            'cost' => $cost,
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * Estimate cost based on model and tokens
     */
    private static function estimate_cost(string $model, int $tokens): float {
        // Approximate costs per 1K tokens (as of 2024)
        $rates = [
            'gpt-4o' => 0.005,
            'gpt-4o-mini' => 0.00015,
            'gpt-4-turbo' => 0.01,
            'gpt-4' => 0.03,
            'gpt-3.5-turbo' => 0.0005,
            'claude-3-opus' => 0.015,
            'claude-3-sonnet' => 0.003,
            'claude-3-haiku' => 0.00025,
            'claude-3-5-sonnet' => 0.003,
        ];

        $rate = $rates[$model] ?? 0.001;

        return round(($tokens / 1000) * $rate, 6);
    }

    /**
     * Return error response (delegates to Tool_Response)
     */
    private static function error(string $code, string $message): array {
        return Tool_Response::error($code, $message);
    }

    /**
     * Resolve linked publications with permission check
     */
    private static function resolve_linked_publications(array $pub_ids, int $user_id): array {
        $resolved = [];

        foreach ($pub_ids as $pub_id) {
            // Check permission
            if (!Picasso_Adapter::can_access_publication($user_id, $pub_id)) {
                continue;
            }

            $post = get_post($pub_id);
            if (!$post || $post->post_type !== 'publication') {
                continue;
            }

            $resolved[] = [
                'id' => $pub_id,
                'title' => $post->post_title,
                'content' => trim($post->post_content) ?: '[Document en attente de contenu]',
            ];
        }

        return $resolved;
    }

    /**
     * Build composite prompt from tool instruction + contents + styles
     */
    private static function build_composite_prompt(
        string $instruction,
        array $contents,
        array $styles,
        string $user_input,
        array $context
    ): string {
        $parts = [];

        // 1. Tool instruction
        $parts[] = $instruction;

        // 2. Reference contents
        if (!empty($contents)) {
            $parts[] = self::render_reference_blocks($contents, 'REFERENCE');
        }

        // 3. Styles
        if (!empty($styles)) {
            $parts[] = self::render_reference_blocks($styles, 'STYLE');
        }

        // 4. User input
        $prompt = implode("\n\n", $parts);

        // Handle {{input}} placeholder or append
        if (strpos($prompt, '{{input}}') !== false) {
            $prompt = str_replace('{{input}}', $user_input, $prompt);
        } elseif (!empty($user_input)) {
            $prompt .= "\n\n---\nCONTENT TO PROCESS:\n\n" . $user_input;
        }

        return $prompt;
    }

    /**
     * Render reference/style blocks for injection
     */
    private static function render_reference_blocks(array $refs, string $tag): string {
        $blocks = [];

        foreach ($refs as $ref) {
            $title = $ref['title'] ?? ('#' . $ref['id']);
            $body = $ref['content'];

            $blocks[] = "=== BEGIN {$tag}: {$title} ===\n{$body}\n=== END {$tag} ===";
        }

        return implode("\n\n", $blocks);
    }

}
