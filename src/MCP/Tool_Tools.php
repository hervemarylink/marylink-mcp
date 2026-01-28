<?php
/**
 * Tool Tools - MCP tools for tool resolution and validation
 *
 * Tools:
 * - ml_tool_resolve: Resolve a tool with its full context
 * - ml_tool_validate: Validate input against tool schema
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Tool_Service;

class Tool_Tools {

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_tool_resolve' => [
                'name' => 'ml_tool_resolve',
                'description' => 'Resolve a tool publication with its full context tree (instruction, styles, data, docs).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'tool_id' => [
                            'type' => 'integer',
                            'description' => 'The tool publication ID to resolve',
                        ],
                    ],
                    'required' => ['tool_id'],
                ],
            ],
            'ml_tool_validate' => [
                'name' => 'ml_tool_validate',
                'description' => 'Validate input data against a tool\'s schema before execution.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'tool_id' => [
                            'type' => 'integer',
                            'description' => 'The tool publication ID',
                        ],
                        'input' => [
                            'type' => 'object',
                            'description' => 'The input data to validate',
                        ],
                    ],
                    'required' => ['tool_id', 'input'],
                ],
            ],
        ];
    }

    /**
     * Execute a tool tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();

        // Check if tool publications are available
        if (!Tool_Service::is_available()) {
            return Tool_Response::error(
                'feature_unavailable',
                'Tool publications feature is not available on this site.',
                $request_id
            );
        }

        $service = new Tool_Service($user_id);

        switch ($tool) {
            case 'ml_tool_resolve':
                return self::handle_resolve($service, $args, $request_id);

            case 'ml_tool_validate':
                return self::handle_validate($service, $args, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_tool_resolve
     */
    private static function handle_resolve(Tool_Service $service, array $args, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['tool_id']);
        if ($validation) {
            return $validation;
        }

        $tool_id = (int) $args['tool_id'];
        $resolved = $service->resolve_tool($tool_id);

        if (!$resolved) {
            return Tool_Response::not_found('tool', $request_id);
        }

        // Count context items
        $context_count = 0;
        foreach ($resolved['context'] as $type => $items) {
            $context_count += count($items);
        }

        return Tool_Response::ok([
            'tool' => $resolved,
            'context_items_count' => $context_count,
        ], $request_id, $context_count > 0 ? null : ['This tool has no linked context items.']);
    }

    /**
     * Handle ml_tool_validate
     */
    private static function handle_validate(Tool_Service $service, array $args, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['tool_id', 'input']);
        if ($validation) {
            return $validation;
        }

        $tool_id = (int) $args['tool_id'];
        $input = $args['input'];

        if (!is_array($input)) {
            return Tool_Response::error(
                'invalid_input',
                'Input must be an object.',
                $request_id
            );
        }

        $result = $service->validate_input($tool_id, $input);

        if ($result['ok'] === false && isset($result['errors']) && in_array('Tool not accessible.', $result['errors'])) {
            return Tool_Response::not_found('tool', $request_id);
        }

        return Tool_Response::ok([
            'valid' => $result['ok'],
            'errors' => $result['errors'] ?? [],
            'warnings' => $result['warnings'] ?? [],
        ], $request_id);
    }
}
