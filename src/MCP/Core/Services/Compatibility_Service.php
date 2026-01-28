<?php
/**
 * Compatibility Service - Component compatibility scoring
 *
 * Calculates compatibility scores between tool components (prompts, styles,
 * content types, models) for dynamic tool assembly.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Services;

class Compatibility_Service {

    const VERSION = '3.0.0';

    // Component types
    const COMPONENT_PROMPT = 'prompt';
    const COMPONENT_STYLE = 'style';
    const COMPONENT_CONTENT = 'content';
    const COMPONENT_MODEL = 'model';
    const COMPONENT_OUTPUT = 'output';

    // Content types
    const CONTENT_TEXT = 'text';
    const CONTENT_HTML = 'html';
    const CONTENT_MARKDOWN = 'markdown';
    const CONTENT_JSON = 'json';
    const CONTENT_CODE = 'code';
    const CONTENT_IMAGE = 'image';

    // Style categories
    const STYLE_FORMAL = 'formal';
    const STYLE_CASUAL = 'casual';
    const STYLE_TECHNICAL = 'technical';
    const STYLE_CREATIVE = 'creative';
    const STYLE_CONCISE = 'concise';
    const STYLE_DETAILED = 'detailed';

    // Model capabilities
    private static array $model_capabilities = [
        'gpt-4o' => ['text', 'code', 'analysis', 'creative', 'vision', 'long_context'],
        'gpt-4o-mini' => ['text', 'code', 'analysis', 'creative'],
        'gpt-4-turbo' => ['text', 'code', 'analysis', 'creative', 'vision', 'long_context'],
        'gpt-3.5-turbo' => ['text', 'code', 'basic_analysis'],
        'claude-3-opus' => ['text', 'code', 'analysis', 'creative', 'vision', 'long_context', 'reasoning'],
        'claude-3-sonnet' => ['text', 'code', 'analysis', 'creative', 'vision'],
        'claude-3-haiku' => ['text', 'code', 'basic_analysis'],
        'claude-3-5-sonnet' => ['text', 'code', 'analysis', 'creative', 'vision', 'long_context'],
    ];

    // Compatibility matrices
    private static array $style_prompt_compatibility = [
        'formal' => ['professional', 'business', 'legal', 'academic', 'technical'],
        'casual' => ['social', 'personal', 'blog', 'chat', 'friendly'],
        'technical' => ['code', 'documentation', 'api', 'specs', 'engineering'],
        'creative' => ['story', 'marketing', 'ad', 'creative', 'artistic'],
        'concise' => ['summary', 'brief', 'tl;dr', 'bullet', 'short'],
        'detailed' => ['analysis', 'report', 'explanation', 'comprehensive', 'in-depth'],
    ];

    /**
     * Calculate overall compatibility score
     *
     * @param array $components Components to check
     * @return array Compatibility result
     */
    public static function calculate(array $components): array {
        $scores = [];
        $issues = [];
        $recommendations = [];

        // Check prompt-style compatibility
        if (isset($components['prompt']) && isset($components['style'])) {
            $result = self::check_prompt_style_compatibility(
                $components['prompt'],
                $components['style']
            );
            $scores['prompt_style'] = $result['score'];
            if (!empty($result['issues'])) {
                $issues = array_merge($issues, $result['issues']);
            }
            if (!empty($result['recommendations'])) {
                $recommendations = array_merge($recommendations, $result['recommendations']);
            }
        }

        // Check model-task compatibility
        if (isset($components['model']) && isset($components['task'])) {
            $result = self::check_model_task_compatibility(
                $components['model'],
                $components['task']
            );
            $scores['model_task'] = $result['score'];
            if (!empty($result['issues'])) {
                $issues = array_merge($issues, $result['issues']);
            }
            if (!empty($result['recommendations'])) {
                $recommendations = array_merge($recommendations, $result['recommendations']);
            }
        }

        // Check content-output compatibility
        if (isset($components['content_type']) && isset($components['output_format'])) {
            $result = self::check_content_output_compatibility(
                $components['content_type'],
                $components['output_format']
            );
            $scores['content_output'] = $result['score'];
            if (!empty($result['issues'])) {
                $issues = array_merge($issues, $result['issues']);
            }
        }

        // Check model-content compatibility
        if (isset($components['model']) && isset($components['content_type'])) {
            $result = self::check_model_content_compatibility(
                $components['model'],
                $components['content_type']
            );
            $scores['model_content'] = $result['score'];
            if (!empty($result['issues'])) {
                $issues = array_merge($issues, $result['issues']);
            }
        }

        // Calculate overall score (weighted average)
        $weights = [
            'prompt_style' => 0.3,
            'model_task' => 0.35,
            'content_output' => 0.2,
            'model_content' => 0.15,
        ];

        $total_weight = 0;
        $weighted_sum = 0;

        foreach ($scores as $key => $score) {
            if (isset($weights[$key])) {
                $weighted_sum += $score * $weights[$key];
                $total_weight += $weights[$key];
            }
        }

        $overall_score = $total_weight > 0 ? $weighted_sum / $total_weight : 0;

        return [
            'success' => true,
            'compatible' => $overall_score >= 0.6,
            'score' => round($overall_score, 3),
            'breakdown' => $scores,
            'issues' => $issues,
            'recommendations' => $recommendations,
            'confidence' => self::calculate_confidence($scores),
        ];
    }

    /**
     * Check prompt-style compatibility
     */
    public static function check_prompt_style_compatibility(string $prompt, string $style): array {
        $prompt_lower = mb_strtolower($prompt);
        $score = 0.5; // Default neutral score
        $issues = [];
        $recommendations = [];

        // Get compatible keywords for this style
        $compatible_keywords = self::$style_prompt_compatibility[$style] ?? [];

        // Check for keyword matches
        $matches = 0;
        foreach ($compatible_keywords as $keyword) {
            if (str_contains($prompt_lower, $keyword)) {
                $matches++;
            }
        }

        if ($matches > 0) {
            $score = min(1.0, 0.6 + ($matches * 0.1));
        }

        // Check for incompatible combinations
        $incompatible = self::get_incompatible_styles($style);
        foreach ($incompatible as $bad_keyword) {
            if (str_contains($prompt_lower, $bad_keyword)) {
                $score = max(0.2, $score - 0.2);
                $issues[] = "Style '$style' may not match prompt intent (found '$bad_keyword')";
            }
        }

        // Recommendations
        if ($score < 0.6) {
            $recommendations[] = "Consider using a " . self::suggest_style($prompt_lower) . " style instead";
        }

        return [
            'score' => $score,
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Check model-task compatibility
     */
    public static function check_model_task_compatibility(string $model, string $task): array {
        $capabilities = self::$model_capabilities[$model] ?? ['text'];
        $score = 0.5;
        $issues = [];
        $recommendations = [];

        // Map tasks to required capabilities
        $task_requirements = [
            'summarize' => ['text'],
            'translate' => ['text'],
            'analyze' => ['analysis'],
            'code' => ['code'],
            'creative_writing' => ['creative'],
            'image_analysis' => ['vision'],
            'long_document' => ['long_context'],
            'reasoning' => ['reasoning', 'analysis'],
            'simple_qa' => ['text'],
        ];

        $required = $task_requirements[$task] ?? ['text'];

        // Check if model has required capabilities
        $has_all = true;
        foreach ($required as $req) {
            if (!in_array($req, $capabilities)) {
                $has_all = false;
                $issues[] = "Model '$model' lacks '$req' capability required for '$task'";
            }
        }

        if ($has_all) {
            $score = 0.9;

            // Bonus for having extra relevant capabilities
            $extra_useful = array_intersect($capabilities, ['analysis', 'reasoning', 'long_context']);
            if (count($extra_useful) > 0) {
                $score = min(1.0, $score + 0.05 * count($extra_useful));
            }
        } else {
            $score = 0.3;

            // Recommend better model
            $better_model = self::suggest_model_for_task($task);
            if ($better_model && $better_model !== $model) {
                $recommendations[] = "Consider using '$better_model' for better '$task' performance";
            }
        }

        return [
            'score' => $score,
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Check content-output compatibility
     */
    public static function check_content_output_compatibility(string $content_type, string $output_format): array {
        $score = 0.5;
        $issues = [];

        // Compatibility matrix
        $compatibility = [
            'text' => ['text' => 1.0, 'markdown' => 0.9, 'html' => 0.8, 'json' => 0.6],
            'html' => ['html' => 1.0, 'markdown' => 0.8, 'text' => 0.7, 'json' => 0.5],
            'markdown' => ['markdown' => 1.0, 'html' => 0.9, 'text' => 0.8, 'json' => 0.6],
            'json' => ['json' => 1.0, 'text' => 0.5, 'markdown' => 0.4, 'html' => 0.3],
            'code' => ['code' => 1.0, 'markdown' => 0.9, 'text' => 0.7, 'html' => 0.6],
        ];

        $score = $compatibility[$content_type][$output_format] ?? 0.5;

        if ($score < 0.5) {
            $issues[] = "Converting '$content_type' to '$output_format' may lose formatting or structure";
        }

        return [
            'score' => $score,
            'issues' => $issues,
        ];
    }

    /**
     * Check model-content compatibility
     */
    public static function check_model_content_compatibility(string $model, string $content_type): array {
        $capabilities = self::$model_capabilities[$model] ?? ['text'];
        $score = 0.7;
        $issues = [];

        // Check specific content type requirements
        if ($content_type === 'image' && !in_array('vision', $capabilities)) {
            $score = 0.1;
            $issues[] = "Model '$model' does not support image processing";
        }

        if ($content_type === 'code' && !in_array('code', $capabilities)) {
            $score = 0.4;
            $issues[] = "Model '$model' has limited code understanding";
        }

        // Long content check
        if (in_array('long_context', $capabilities)) {
            $score = min(1.0, $score + 0.1);
        }

        return [
            'score' => $score,
            'issues' => $issues,
        ];
    }

    /**
     * Validate assembly configuration
     */
    public static function validate_assembly(array $config): array {
        $errors = [];
        $warnings = [];

        // Required fields
        if (empty($config['prompt'])) {
            $errors[] = 'Prompt is required for assembly';
        }

        // Validate model if specified
        if (!empty($config['model'])) {
            if (!isset(self::$model_capabilities[$config['model']])) {
                $warnings[] = "Unknown model '{$config['model']}', using defaults";
            }
        }

        // Validate style
        $valid_styles = array_keys(self::$style_prompt_compatibility);
        if (!empty($config['style']) && !in_array($config['style'], $valid_styles)) {
            $warnings[] = "Unknown style '{$config['style']}'";
        }

        // Check prompt length
        if (!empty($config['prompt']) && strlen($config['prompt']) > 10000) {
            $warnings[] = 'Very long prompt may impact performance';
        }

        // Calculate compatibility if all components present
        $compatibility = null;
        if (empty($errors)) {
            $components = [
                'prompt' => $config['prompt'] ?? '',
                'style' => $config['style'] ?? 'formal',
                'model' => $config['model'] ?? 'gpt-4o-mini',
                'task' => self::infer_task($config['prompt'] ?? ''),
                'content_type' => $config['input_type'] ?? 'text',
                'output_format' => $config['output_format'] ?? 'text',
            ];
            $compatibility = self::calculate($components);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'compatibility' => $compatibility,
        ];
    }

    /**
     * Suggest compatible components
     */
    public static function suggest_components(array $partial_config): array {
        $suggestions = [];

        // Suggest style based on prompt
        if (!empty($partial_config['prompt']) && empty($partial_config['style'])) {
            $suggestions['style'] = self::suggest_style($partial_config['prompt']);
        }

        // Suggest model based on task/prompt
        if (!empty($partial_config['prompt']) && empty($partial_config['model'])) {
            $task = self::infer_task($partial_config['prompt']);
            $suggestions['model'] = self::suggest_model_for_task($task);
        }

        // Suggest output format based on task
        if (!empty($partial_config['prompt']) && empty($partial_config['output_format'])) {
            $task = self::infer_task($partial_config['prompt']);
            $suggestions['output_format'] = self::suggest_output_format($task);
        }

        return $suggestions;
    }

    /**
     * Get incompatible styles
     */
    private static function get_incompatible_styles(string $style): array {
        $incompatible = [
            'formal' => ['lol', 'hey', 'sup', 'yo', 'emoji', 'slang'],
            'casual' => ['hereby', 'pursuant', 'whereas', 'therein', 'aforementioned'],
            'technical' => ['maybe', 'kind of', 'sort of', 'basically', 'like'],
            'creative' => ['strictly', 'precisely', 'exactly', 'factually'],
            'concise' => ['furthermore', 'additionally', 'moreover', 'in other words'],
            'detailed' => ['briefly', 'quickly', 'just', 'only'],
        ];

        return $incompatible[$style] ?? [];
    }

    /**
     * Suggest style based on prompt content
     */
    private static function suggest_style(string $prompt): string {
        $prompt_lower = mb_strtolower($prompt);

        foreach (self::$style_prompt_compatibility as $style => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($prompt_lower, $keyword)) {
                    return $style;
                }
            }
        }

        return 'formal'; // Default
    }

    /**
     * Suggest model for task
     */
    private static function suggest_model_for_task(string $task): string {
        $recommendations = [
            'analyze' => 'gpt-4o',
            'summarize' => 'gpt-4o-mini',
            'translate' => 'gpt-4o-mini',
            'code' => 'gpt-4o',
            'creative_writing' => 'claude-3-5-sonnet',
            'image_analysis' => 'gpt-4o',
            'long_document' => 'claude-3-opus',
            'reasoning' => 'claude-3-opus',
            'simple_qa' => 'gpt-3.5-turbo',
        ];

        return $recommendations[$task] ?? 'gpt-4o-mini';
    }

    /**
     * Infer task from prompt
     */
    private static function infer_task(string $prompt): string {
        $prompt_lower = mb_strtolower($prompt);

        $task_keywords = [
            'summarize' => ['résume', 'summary', 'résumé', 'synthèse', 'tl;dr'],
            'translate' => ['tradui', 'translate', 'translation'],
            'analyze' => ['analyse', 'analyze', 'évalue', 'evaluate', 'examine'],
            'code' => ['code', 'program', 'function', 'script', 'api'],
            'creative_writing' => ['story', 'creative', 'histoire', 'write a', 'écris'],
            'simple_qa' => ['what is', "qu'est-ce", 'how do', 'comment'],
        ];

        foreach ($task_keywords as $task => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($prompt_lower, $keyword)) {
                    return $task;
                }
            }
        }

        return 'simple_qa';
    }

    /**
     * Suggest output format based on task
     */
    private static function suggest_output_format(string $task): string {
        $formats = [
            'summarize' => 'text',
            'translate' => 'text',
            'analyze' => 'markdown',
            'code' => 'code',
            'creative_writing' => 'text',
            'simple_qa' => 'text',
        ];

        return $formats[$task] ?? 'text';
    }

    /**
     * Calculate confidence based on score variance
     */
    private static function calculate_confidence(array $scores): float {
        if (empty($scores)) {
            return 0.5;
        }

        $values = array_values($scores);
        $mean = array_sum($values) / count($values);

        // Calculate variance
        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        $variance /= count($values);

        // Lower variance = higher confidence
        $confidence = 1 - min(1, sqrt($variance));

        // Adjust based on number of scores
        $confidence *= min(1, count($scores) / 3);

        return round($confidence, 3);
    }
}
