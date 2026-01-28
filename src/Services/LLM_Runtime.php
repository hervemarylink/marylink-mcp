<?php
/**
 * LLM Runtime - Execute prompts via AI Engine
 *
 * Provides a unified interface for LLM execution, allowing the MCP
 * system to move from "catalog" to "execution OS".
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\Ops\Audit_Logger;

class LLM_Runtime {

    private const DEFAULT_MODEL = 'gpt-4o-mini';
    private const DEFAULT_MAX_TOKENS = 4096;
    private const DEFAULT_TEMPERATURE = 0.7;

    /**
     * Check if AI Engine is available
     */
    public static function is_available(): bool {
        return class_exists('Meow_MWAI_Core');
    }

    /**
     * Execute a prompt via AI Engine
     *
     * @param string $prompt The full prompt to execute
     * @param array $options Execution options (model, max_tokens, temperature, system)
     * @return array Result with ok, text, usage, run_id, latency_ms
     */
    public static function execute(string $prompt, array $options = []): array {
        $run_id = self::generate_run_id();
        $start_time = microtime(true);

        if (!self::is_available()) {
            return [
                'ok' => false,
                'error' => 'ai_engine_unavailable',
                'message' => 'AI Engine is not installed or activated.',
                'run_id' => $run_id,
            ];
        }

        try {
            $ai = \Meow_MWAI_Core::instance();

            // Build query
            $query = new \Meow_MWAI_Query_Text($prompt);
            $query->set_model($options['model'] ?? self::DEFAULT_MODEL);
            $query->set_max_tokens($options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS);
            $query->set_temperature($options['temperature'] ?? self::DEFAULT_TEMPERATURE);

            // Add system message if provided
            if (!empty($options['system'])) {
                $query->set_instructions($options['system']);
            }

            // Execute
            $result = $ai->run_query($query);

            $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

            // Log execution
            if (class_exists('MCP_No_Headless\Ops\Audit_Logger')) {
                Audit_Logger::log_tool(
                    'llm_runtime_execute',
                    get_current_user_id(),
                    'success',
                    [
                        'run_id' => $run_id,
                        'model' => $options['model'] ?? self::DEFAULT_MODEL,
                        'prompt_len' => mb_strlen($prompt),
                        'result_len' => mb_strlen($result->result ?? ''),
                    ],
                    'execute',
                    $latency_ms
                );
            }

            return [
                'ok' => true,
                'run_id' => $run_id,
                'text' => $result->result ?? '',
                'model' => $result->model ?? ($options['model'] ?? self::DEFAULT_MODEL),
                'usage' => [
                    'prompt_tokens' => $result->usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $result->usage['completion_tokens'] ?? null,
                    'total_tokens' => $result->usage['total_tokens'] ?? null,
                ],
                'latency_ms' => $latency_ms,
            ];

        } catch (\Exception $e) {
            $latency_ms = (int) ((microtime(true) - $start_time) * 1000);

            if (class_exists('MCP_No_Headless\Ops\Audit_Logger')) {
                Audit_Logger::log_tool(
                    'llm_runtime_execute',
                    get_current_user_id(),
                    'error',
                    [
                        'run_id' => $run_id,
                        'error' => $e->getMessage(),
                    ],
                    'execute',
                    $latency_ms,
                    'execution_failed'
                );
            }

            return [
                'ok' => false,
                'run_id' => $run_id,
                'error' => 'execution_failed',
                'message' => $e->getMessage(),
                'latency_ms' => $latency_ms,
            ];
        }
    }

    /**
     * Execute with streaming (falls back to non-streaming for now)
     *
     * @param string $prompt The prompt to execute
     * @param callable $callback Callback to receive streamed text
     * @param array $options Execution options
     * @return array Result array
     */
    public static function execute_stream(string $prompt, callable $callback, array $options = []): array {
        // For now, fall back to non-streaming
        // TODO: Implement streaming when AI Engine supports it well
        $result = self::execute($prompt, $options);

        if ($result['ok'] && !empty($result['text'])) {
            $callback($result['text']);
        }

        return $result;
    }

    /**
     * Generate unique run ID
     */
    private static function generate_run_id(): string {
        return sprintf(
            'run_%s_%s',
            date('Ymd_His'),
            bin2hex(random_bytes(4))
        );
    }

    /**
     * Get available models
     *
     * @return array Model ID => Display name
     */
    public static function get_available_models(): array {
        if (!self::is_available()) {
            return [];
        }

        // AI Engine models - these may vary based on configuration
        return [
            'gpt-4o' => 'GPT-4o (Best)',
            'gpt-4o-mini' => 'GPT-4o Mini (Fast)',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Economy)',
            'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet',
        ];
    }

    /**
     * Get runtime status
     *
     * @return array Status information
     */
    public static function get_status(): array {
        $available = self::is_available();

        return [
            'available' => $available,
            'provider' => $available ? 'AI Engine' : null,
            'default_model' => self::DEFAULT_MODEL,
            'models' => self::get_available_models(),
        ];
    }
}
