<?php
/**
 * Assembly Service - Dynamic tool assembly
 *
 * Assembles tools from components (prompts, styles, templates) with
 * compatibility validation. Supports ephemeral and persistent tool creation.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Services;

use MCP_No_Headless\MCP\Core\Tools\Run;

class Assembly_Service {

    const VERSION = '3.0.0';

    // Assembly modes
    const MODE_EPHEMERAL = 'ephemeral';  // One-time use, not saved
    const MODE_PERSISTENT = 'persistent'; // Save as new tool
    const MODE_TEMPLATE = 'template';     // Create from template

    // Template variable patterns
    const VAR_PATTERN = '/\{\{(\w+)\}\}/';

    // Default settings
    const DEFAULT_MODEL = 'gpt-4o-mini';
    const DEFAULT_MAX_TOKENS = 2000;
    const DEFAULT_TEMPERATURE = 0.7;

    /**
     * Assemble a tool from components
     *
     * @param array $spec Assembly specification
     * @param int $user_id User ID
     * @return array Assembly result
     */
    public static function assemble(array $spec, int $user_id): array {
        // Validate spec
        $validation = self::validate_spec($spec);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'invalid_spec',
                    'message' => 'Invalid assembly specification',
                    'details' => $validation['errors'],
                ],
            ];
        }

        // Check compatibility
        $compatibility = self::check_compatibility($spec);
        if (!$compatibility['compatible'] && !($spec['force'] ?? false)) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'incompatible',
                    'message' => 'Components are not compatible',
                    'compatibility' => $compatibility,
                ],
            ];
        }

        // Build tool configuration
        $tool_config = self::build_config($spec, $compatibility);

        // Handle mode
        $mode = $spec['mode'] ?? self::MODE_EPHEMERAL;

        return match ($mode) {
            self::MODE_PERSISTENT => self::create_persistent_tool($tool_config, $spec, $user_id),
            self::MODE_TEMPLATE => self::create_from_template($tool_config, $spec, $user_id),
            default => self::create_ephemeral_tool($tool_config, $spec, $user_id),
        };
    }

    /**
     * Execute an assembled tool directly
     *
     * @param array $spec Assembly specification with input
     * @param int $user_id User ID
     * @return array Execution result
     */
    public static function execute(array $spec, int $user_id): array {
        // Assemble the tool
        $assembly = self::assemble($spec, $user_id);

        if (!$assembly['success']) {
            return $assembly;
        }

        // Get input
        $input = $spec['input'] ?? null;
        $source_id = $spec['source_id'] ?? null;

        if (!$input && !$source_id) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'missing_input',
                    'message' => 'Input or source_id required for execution',
                ],
            ];
        }

        // Build Run args
        $run_args = [
            'prompt' => $assembly['tool']['prompt'],
            'model' => $assembly['tool']['model'],
            'options' => [
                'temperature' => $assembly['tool']['temperature'],
                'max_tokens' => $assembly['tool']['max_tokens'],
            ],
        ];

        if ($input) {
            $run_args['input'] = $input;
        }
        if ($source_id) {
            $run_args['source_id'] = $source_id;
        }

        // Execute via Run tool
        $result = Run::execute($run_args, $user_id);

        return [
            'success' => $result['success'],
            'assembly' => $assembly,
            'result' => $result,
        ];
    }

    /**
     * Create tool from a template
     *
     * @param string $template_id Template ID
     * @param array $variables Variable values
     * @param int $user_id User ID
     * @return array Assembly result
     */
    public static function from_template(string $template_id, array $variables, int $user_id): array {
        $template = self::get_template($template_id);

        if (!$template) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'template_not_found',
                    'message' => "Template '$template_id' not found",
                ],
            ];
        }

        // Validate required variables
        $missing = self::get_missing_variables($template, $variables);
        if (!empty($missing)) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'missing_variables',
                    'message' => 'Missing required variables',
                    'missing' => $missing,
                ],
            ];
        }

        // Substitute variables
        $spec = self::substitute_variables($template, $variables);

        // Assemble with template mode
        $spec['mode'] = self::MODE_TEMPLATE;
        $spec['template_id'] = $template_id;

        return self::assemble($spec, $user_id);
    }

    /**
     * Get available templates
     *
     * @param string|null $category Optional category filter
     * @return array Templates
     */
    public static function get_templates(?string $category = null): array {
        $templates = [
            'summarizer' => [
                'id' => 'summarizer',
                'name' => 'Content Summarizer',
                'category' => 'transformation',
                'description' => 'Summarizes content to {{length}} length',
                'variables' => ['length', 'style', 'language'],
                'defaults' => [
                    'length' => 'concise',
                    'style' => 'professional',
                    'language' => 'auto',
                ],
                'spec' => [
                    'prompt' => "Summarize the following content in a {{style}} manner. Target length: {{length}}.\nLanguage: {{language}}\n\n{{input}}",
                    'style' => 'concise',
                    'model' => 'gpt-4o-mini',
                ],
            ],
            'translator' => [
                'id' => 'translator',
                'name' => 'Multi-language Translator',
                'category' => 'transformation',
                'description' => 'Translates content to {{target_language}}',
                'variables' => ['target_language', 'preserve_formatting', 'tone'],
                'defaults' => [
                    'target_language' => 'English',
                    'preserve_formatting' => 'yes',
                    'tone' => 'same',
                ],
                'spec' => [
                    'prompt' => "Translate the following text to {{target_language}}.\nPreserve formatting: {{preserve_formatting}}\nTone: {{tone}}\n\n{{input}}",
                    'model' => 'gpt-4o-mini',
                ],
            ],
            'analyzer' => [
                'id' => 'analyzer',
                'name' => 'Content Analyzer',
                'category' => 'analysis',
                'description' => 'Analyzes content for {{focus}}',
                'variables' => ['focus', 'depth', 'output_format'],
                'defaults' => [
                    'focus' => 'key points',
                    'depth' => 'moderate',
                    'output_format' => 'bullet points',
                ],
                'spec' => [
                    'prompt' => "Analyze the following content, focusing on {{focus}}.\nAnalysis depth: {{depth}}\nOutput format: {{output_format}}\n\n{{input}}",
                    'style' => 'detailed',
                    'model' => 'gpt-4o',
                ],
            ],
            'rewriter' => [
                'id' => 'rewriter',
                'name' => 'Style Rewriter',
                'category' => 'transformation',
                'description' => 'Rewrites content in {{target_style}} style',
                'variables' => ['target_style', 'audience', 'preserve_meaning'],
                'defaults' => [
                    'target_style' => 'professional',
                    'audience' => 'general',
                    'preserve_meaning' => 'strict',
                ],
                'spec' => [
                    'prompt' => "Rewrite the following content in a {{target_style}} style for a {{audience}} audience.\nMeaning preservation: {{preserve_meaning}}\n\n{{input}}",
                    'model' => 'gpt-4o-mini',
                ],
            ],
            'expander' => [
                'id' => 'expander',
                'name' => 'Content Expander',
                'category' => 'creation',
                'description' => 'Expands content with {{additions}}',
                'variables' => ['additions', 'target_length', 'style'],
                'defaults' => [
                    'additions' => 'examples and details',
                    'target_length' => '2x original',
                    'style' => 'same',
                ],
                'spec' => [
                    'prompt' => "Expand the following content by adding {{additions}}.\nTarget length: {{target_length}}\nMaintain {{style}} style.\n\n{{input}}",
                    'model' => 'gpt-4o',
                ],
            ],
            'qa_generator' => [
                'id' => 'qa_generator',
                'name' => 'Q&A Generator',
                'category' => 'creation',
                'description' => 'Generates {{count}} Q&A pairs',
                'variables' => ['count', 'difficulty', 'type'],
                'defaults' => [
                    'count' => '5',
                    'difficulty' => 'moderate',
                    'type' => 'comprehension',
                ],
                'spec' => [
                    'prompt' => "Based on the following content, generate {{count}} {{type}} questions with answers.\nDifficulty level: {{difficulty}}\n\n{{input}}",
                    'model' => 'gpt-4o-mini',
                ],
            ],
        ];

        // Filter by category if specified
        if ($category) {
            $templates = array_filter($templates, fn($t) => $t['category'] === $category);
        }

        return array_values($templates);
    }

    /**
     * Validate assembly specification
     */
    private static function validate_spec(array $spec): array {
        $errors = [];
        $warnings = [];

        // Require prompt or template
        if (empty($spec['prompt']) && empty($spec['template_id'])) {
            $errors[] = 'Either prompt or template_id is required';
        }

        // Validate prompt length
        if (!empty($spec['prompt']) && strlen($spec['prompt']) > 50000) {
            $errors[] = 'Prompt exceeds maximum length (50000 characters)';
        }

        // Validate model
        $valid_models = ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo', 'claude-3-opus', 'claude-3-sonnet', 'claude-3-haiku', 'claude-3-5-sonnet'];
        if (!empty($spec['model']) && !in_array($spec['model'], $valid_models)) {
            $warnings[] = "Unknown model '{$spec['model']}', will use default";
        }

        // Validate temperature
        if (isset($spec['temperature'])) {
            $temp = (float) $spec['temperature'];
            if ($temp < 0 || $temp > 2) {
                $errors[] = 'Temperature must be between 0 and 2';
            }
        }

        // Validate max_tokens
        if (isset($spec['max_tokens'])) {
            $tokens = (int) $spec['max_tokens'];
            if ($tokens < 1 || $tokens > 16000) {
                $errors[] = 'max_tokens must be between 1 and 16000';
            }
        }

        // Validate name for persistent mode
        if (($spec['mode'] ?? '') === self::MODE_PERSISTENT && empty($spec['name'])) {
            $errors[] = 'Name is required for persistent mode';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check component compatibility
     */
    private static function check_compatibility(array $spec): array {
        $components = [
            'prompt' => $spec['prompt'] ?? '',
            'style' => $spec['style'] ?? 'formal',
            'model' => $spec['model'] ?? self::DEFAULT_MODEL,
            'task' => $spec['task'] ?? 'simple_qa',
            'content_type' => $spec['input_type'] ?? 'text',
            'output_format' => $spec['output_format'] ?? 'text',
        ];

        return Compatibility_Service::calculate($components);
    }

    /**
     * Build tool configuration from spec
     */
    private static function build_config(array $spec, array $compatibility): array {
        // Get suggestions for missing components
        $suggestions = Compatibility_Service::suggest_components($spec);

        $config = [
            'prompt' => $spec['prompt'] ?? '',
            'model' => $spec['model'] ?? $suggestions['model'] ?? self::DEFAULT_MODEL,
            'style' => $spec['style'] ?? $suggestions['style'] ?? 'formal',
            'temperature' => (float) ($spec['temperature'] ?? self::DEFAULT_TEMPERATURE),
            'max_tokens' => (int) ($spec['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
            'input_type' => $spec['input_type'] ?? 'text',
            'output_format' => $spec['output_format'] ?? $suggestions['output_format'] ?? 'text',
            'compatibility_score' => $compatibility['score'],
        ];

        // Apply style to prompt if not already styled
        if (!empty($config['style']) && !str_contains($config['prompt'], 'Style:')) {
            $style_instruction = self::get_style_instruction($config['style']);
            if ($style_instruction) {
                $config['prompt'] .= "\n\n$style_instruction";
            }
        }

        return $config;
    }

    /**
     * Get style instruction text
     */
    private static function get_style_instruction(string $style): string {
        $instructions = [
            'formal' => 'Style: Formal, professional tone. Avoid colloquialisms.',
            'casual' => 'Style: Casual, friendly tone. Be approachable.',
            'technical' => 'Style: Technical, precise terminology. Include details.',
            'creative' => 'Style: Creative, engaging. Use vivid language.',
            'concise' => 'Style: Concise, to the point. No unnecessary words.',
            'detailed' => 'Style: Detailed, comprehensive. Cover all aspects.',
        ];

        return $instructions[$style] ?? '';
    }

    /**
     * Create ephemeral (one-time) tool
     */
    private static function create_ephemeral_tool(array $config, array $spec, int $user_id): array {
        return [
            'success' => true,
            'mode' => self::MODE_EPHEMERAL,
            'tool' => $config,
            'tool_id' => null,
            'message' => 'Ephemeral tool assembled. Use execute() to run.',
        ];
    }

    /**
     * Create and persist tool to database
     */
    private static function create_persistent_tool(array $config, array $spec, int $user_id): array {
        // Check user can create tools
        if (!user_can($user_id, 'edit_posts')) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'permission_denied',
                    'message' => 'User cannot create tools',
                ],
            ];
        }

        // Create post
        $post_data = [
            'post_title' => sanitize_text_field($spec['name']),
            'post_content' => sanitize_textarea_field($spec['description'] ?? ''),
            'post_status' => 'publish',
            'post_type' => 'ml_tool',
            'post_author' => $user_id,
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'create_failed',
                    'message' => $post_id->get_error_message(),
                ],
            ];
        }

        // Save tool meta
        update_post_meta($post_id, '_ml_tool_prompt', $config['prompt']);
        update_post_meta($post_id, '_ml_tool_model', $config['model']);
        update_post_meta($post_id, '_ml_tool_style', $config['style']);
        update_post_meta($post_id, '_ml_tool_temperature', $config['temperature']);
        update_post_meta($post_id, '_ml_tool_max_tokens', $config['max_tokens']);
        update_post_meta($post_id, '_ml_tool_input_type', $config['input_type']);
        update_post_meta($post_id, '_ml_tool_output_format', $config['output_format']);
        update_post_meta($post_id, '_ml_tool_category', $spec['category'] ?? 'custom');
        update_post_meta($post_id, '_ml_tool_requires_input', true);
        update_post_meta($post_id, '_ml_tool_assembled', true);
        update_post_meta($post_id, '_ml_tool_assembled_at', current_time('mysql'));

        if (!empty($spec['icon'])) {
            update_post_meta($post_id, '_ml_tool_icon', sanitize_text_field($spec['icon']));
        }

        return [
            'success' => true,
            'mode' => self::MODE_PERSISTENT,
            'tool' => $config,
            'tool_id' => $post_id,
            'url' => get_permalink($post_id),
            'message' => "Tool '{$spec['name']}' created successfully",
        ];
    }

    /**
     * Create from template with variable substitution
     */
    private static function create_from_template(array $config, array $spec, int $user_id): array {
        $result = [
            'success' => true,
            'mode' => self::MODE_TEMPLATE,
            'tool' => $config,
            'tool_id' => null,
            'template_id' => $spec['template_id'] ?? null,
            'variables_used' => $spec['_variables_used'] ?? [],
        ];

        // If persist flag is set, also save
        if (!empty($spec['persist'])) {
            $spec['mode'] = self::MODE_PERSISTENT;
            $persistent_result = self::create_persistent_tool($config, $spec, $user_id);

            if ($persistent_result['success']) {
                $result['tool_id'] = $persistent_result['tool_id'];
                $result['url'] = $persistent_result['url'];
            }
        }

        return $result;
    }

    /**
     * Get template by ID
     */
    private static function get_template(string $template_id): ?array {
        $templates = self::get_templates();

        foreach ($templates as $template) {
            if ($template['id'] === $template_id) {
                return $template;
            }
        }

        // Check for custom templates in database
        global $wpdb;
        $custom = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->posts} WHERE post_type = 'ml_tool_template' AND post_name = %s AND post_status = 'publish'",
            $template_id
        ));

        if ($custom) {
            return [
                'id' => $custom->post_name,
                'name' => $custom->post_title,
                'description' => $custom->post_content,
                'spec' => json_decode(get_post_meta($custom->ID, '_ml_template_spec', true), true),
                'variables' => json_decode(get_post_meta($custom->ID, '_ml_template_variables', true), true) ?: [],
                'defaults' => json_decode(get_post_meta($custom->ID, '_ml_template_defaults', true), true) ?: [],
            ];
        }

        return null;
    }

    /**
     * Get missing required variables
     */
    private static function get_missing_variables(array $template, array $provided): array {
        $required = $template['variables'] ?? [];
        $defaults = $template['defaults'] ?? [];

        $missing = [];
        foreach ($required as $var) {
            if (!isset($provided[$var]) && !isset($defaults[$var])) {
                $missing[] = $var;
            }
        }

        return $missing;
    }

    /**
     * Substitute variables in template
     */
    private static function substitute_variables(array $template, array $variables): array {
        $spec = $template['spec'];
        $defaults = $template['defaults'] ?? [];

        // Merge with defaults
        $all_vars = array_merge($defaults, $variables);

        // Substitute in prompt
        if (!empty($spec['prompt'])) {
            $spec['prompt'] = preg_replace_callback(
                self::VAR_PATTERN,
                function ($matches) use ($all_vars) {
                    $var = $matches[1];
                    return $all_vars[$var] ?? $matches[0];
                },
                $spec['prompt']
            );
        }

        // Substitute in other string fields
        foreach (['description', 'name'] as $field) {
            if (!empty($spec[$field])) {
                $spec[$field] = preg_replace_callback(
                    self::VAR_PATTERN,
                    function ($matches) use ($all_vars) {
                        return $all_vars[$matches[1]] ?? $matches[0];
                    },
                    $spec[$field]
                );
            }
        }

        $spec['_variables_used'] = $all_vars;

        return $spec;
    }
}
