<?php
/**
 * Spaces Tools - MCP tools for space operations
 *
 * Tools:
 * - ml_spaces_list: List accessible spaces with filters
 * - ml_space_get: Get space details
 * - ml_space_steps_list: Get workflow steps for a space
 * - ml_space_permissions_summary: Get user permissions on space/step
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Space_Service;
use MCP_No_Headless\Services\Query_Service;

class Spaces_Tools {

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_spaces_list' => [
                'name' => 'ml_spaces_list',
                'description' => 'List spaces accessible to the current user. Supports search filtering and pagination.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => [
                            'type' => 'string',
                            'description' => 'Search term to filter spaces by title/content',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of results (default 20, max 50)',
                            'default' => 20,
                        ],
                        'cursor' => [
                            'type' => 'string',
                            'description' => 'Pagination cursor from previous response',
                        ],
                    ],
                ],
            ],
            'ml_space_get' => [
                'name' => 'ml_space_get',
                'description' => 'Get detailed information about a specific space including content, steps, and your permissions.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'The space ID to retrieve',
                        ],
                    ],
                    'required' => ['space_id'],
                ],
            ],
            'ml_space_steps_list' => [
                'name' => 'ml_space_steps_list',
                'description' => 'Get the workflow steps configured for a space.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'The space ID',
                        ],
                    ],
                    'required' => ['space_id'],
                ],
            ],
            'ml_space_permissions_summary' => [
                'name' => 'ml_space_permissions_summary',
                'description' => 'Get a summary of what actions YOU can perform in a space (and optionally a specific step).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'The space ID',
                        ],
                        'step_name' => [
                            'type' => 'string',
                            'description' => 'Optional step name to check step-specific permissions',
                        ],
                    ],
                    'required' => ['space_id'],
                ],
            ],
        ];
    }

    /**
     * Execute a spaces tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();

        // Check if spaces are available
        if (!Space_Service::is_available()) {
            return Tool_Response::error(
                'feature_unavailable',
                'Spaces feature is not available on this site.',
                $request_id
            );
        }

        $service = new Space_Service($user_id);

        switch ($tool) {
            case 'ml_spaces_list':
                return self::handle_list($service, $args, $request_id);

            case 'ml_space_get':
                return self::handle_get($service, $args, $request_id);

            case 'ml_space_steps_list':
                return self::handle_steps_list($service, $args, $request_id);

            case 'ml_space_permissions_summary':
                return self::handle_permissions_summary($service, $args, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_spaces_list
     */
    private static function handle_list(Space_Service $service, array $args, string $request_id): array {
        $filters = [];
        if (!empty($args['search'])) {
            $filters['search'] = $args['search'];
        }

        $pagination = Query_Service::parse_pagination($args);
        $result = $service->list_spaces($filters, $pagination['offset'], $pagination['limit']);

        if (empty($result['spaces'])) {
            return Tool_Response::empty_list($request_id, 'No spaces found matching your criteria.');
        }

        $pagination_response = Query_Service::build_pagination_response(
            $pagination['offset'],
            $pagination['limit'],
            count($result['spaces']),
            $result['total_count']
        );

        return Tool_Response::list(
            $result['spaces'],
            $pagination_response,
            $request_id
        );
    }

    /**
     * Handle ml_space_get
     */
    private static function handle_get(Space_Service $service, array $args, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['space_id']);
        if ($validation) {
            return $validation;
        }

        $space_id = (int) $args['space_id'];
        $space = $service->get_space($space_id);

        if (!$space) {
            return Tool_Response::not_found('space', $request_id);
        }

        return Tool_Response::found($space, $request_id, [
            'View publications' => "ml_publications_list(space_id: {$space_id})",
            'View steps' => "ml_space_steps_list(space_id: {$space_id})",
        ]);
    }

    /**
     * Handle ml_space_steps_list
     */
    private static function handle_steps_list(Space_Service $service, array $args, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['space_id']);
        if ($validation) {
            return $validation;
        }

        $space_id = (int) $args['space_id'];
        $steps = $service->get_steps($space_id);

        if ($steps === null) {
            return Tool_Response::not_found('space', $request_id);
        }

        return Tool_Response::ok([
            'space_id' => $space_id,
            'steps' => Tool_Response::index_results($steps),
            'count' => count($steps),
        ], $request_id);
    }

    /**
     * Handle ml_space_permissions_summary
     */
    private static function handle_permissions_summary(Space_Service $service, array $args, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['space_id']);
        if ($validation) {
            return $validation;
        }

        $space_id = (int) $args['space_id'];
        $step_name = $args['step_name'] ?? null;

        $permissions = $service->get_permissions_summary($space_id, $step_name);

        if ($permissions === null) {
            return Tool_Response::not_found('space', $request_id);
        }

        return Tool_Response::ok([
            'space_id' => $space_id,
            'step_name' => $step_name,
            'permissions' => $permissions,
        ], $request_id);
    }
}
