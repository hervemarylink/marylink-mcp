<?php
/**
 * Feedback Tools - MCP tools for collecting user feedback
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Feedback_Service;

class Feedback_Tools {

    /**
     * Get tool definitions for MCP
     */
    public static function get_definitions(): array {
        return [
            'ml_feedback' => [
                'name' => 'ml_feedback',
                'description' => 'Submit feedback after using a tool. Helps improve recommendations.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'run_id' => [
                            'type' => 'string',
                            'description' => 'The run_id from ml_apply_tool execute stage',
                        ],
                        'tool_id' => [
                            'type' => 'integer',
                            'description' => 'The tool/prompt publication ID',
                        ],
                        'thumbs' => [
                            'type' => 'string',
                            'enum' => ['up', 'down'],
                            'description' => 'Was this helpful? up = yes, down = no',
                        ],
                        'comment' => [
                            'type' => 'string',
                            'description' => 'Optional feedback comment',
                        ],
                    ],
                    'required' => ['run_id', 'tool_id', 'thumbs'],
                ],
            ],
            'ml_feedback_stats' => [
                'name' => 'ml_feedback_stats',
                'description' => 'Get feedback statistics for a tool.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'tool_id' => [
                            'type' => 'integer',
                            'description' => 'The tool/prompt publication ID',
                        ],
                    ],
                    'required' => ['tool_id'],
                ],
            ],
        ];
    }

    /**
     * Execute a feedback tool
     *
     * @param string $tool Tool name
     * @param array $args Tool arguments
     * @param int $user_id User ID
     * @return array Response
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        switch ($tool) {
            case 'ml_feedback':
                return self::handle_feedback($args, $user_id);

            case 'ml_feedback_stats':
                return self::handle_stats($args);

            default:
                return Tool_Response::error('unknown_tool', "Unknown feedback tool: $tool");
        }
    }

    /**
     * Handle feedback submission
     */
    private static function handle_feedback(array $args, int $user_id): array {
        $run_id = $args['run_id'] ?? '';
        $tool_id = (int) ($args['tool_id'] ?? 0);
        $thumbs = $args['thumbs'] ?? '';
        $comment = $args['comment'] ?? null;

        // Validate
        if (empty($run_id)) {
            return Tool_Response::error('missing_run_id', 'run_id is required');
        }

        if ($tool_id <= 0) {
            return Tool_Response::error('invalid_tool_id', 'Valid tool_id is required');
        }

        if (!in_array($thumbs, ['up', 'down'], true)) {
            return Tool_Response::error('invalid_thumbs', "thumbs must be 'up' or 'down'");
        }

        // Check for duplicate
        if (Feedback_Service::has_feedback($run_id, $user_id)) {
            return Tool_Response::error('duplicate_feedback', 'Feedback already submitted for this run');
        }

        // Record feedback
        $success = Feedback_Service::record(
            $run_id,
            $user_id,
            $tool_id,
            $thumbs,
            $comment
        );

        if (!$success) {
            return Tool_Response::error('save_failed', 'Failed to save feedback');
        }

        return Tool_Response::ok([
            'message' => 'Feedback recorded. Thank you!',
            'thumbs' => $thumbs,
            'tool_id' => $tool_id,
        ]);
    }

    /**
     * Handle stats request
     */
    private static function handle_stats(array $args): array {
        $tool_id = (int) ($args['tool_id'] ?? 0);

        if ($tool_id <= 0) {
            return Tool_Response::error('invalid_tool_id', 'Valid tool_id is required');
        }

        $stats = Feedback_Service::get_tool_stats($tool_id);

        return Tool_Response::ok([
            'tool_id' => $tool_id,
            'stats' => $stats,
        ]);
    }
}
