<?php
/**
 * Publications Tools - MCP tools for publication operations
 *
 * Tools:
 * - ml_publications_list: List publications with filters
 * - ml_publication_get: Get publication details
 * - ml_publication_dependencies: Get dependencies tree
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Publication_Service;
use MCP_No_Headless\Services\Query_Service;

class Publications_Tools {

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_publications_list' => [
                'name' => 'ml_publications_list',
                'description' => 'List publications accessible to the current user. Supports filtering by space, step, author, and search term.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'Filter by space ID',
                        ],
                        'step' => [
                            'type' => 'string',
                            'description' => 'Filter by workflow step name',
                        ],
                        'author_id' => [
                            'type' => 'integer',
                            'description' => 'Filter by author user ID',
                        ],
                        'search' => [
                            'type' => 'string',
                            'description' => 'Search term to filter by title/content',
                        ],
'type' => [
    'type' => 'string',
    'description' => 'Filter by publication label/type (slug or name: contenu, prompt, style, outil, etc.)',
],
'tags' => [
    'type' => 'array',
    'items' => ['type' => 'string'],
    'description' => 'Filter by tags (slugs or IDs)',
],
'sort' => [
    'type' => 'string',
    'enum' => ['newest', 'oldest', 'best_rated', 'worst_rated', 'most_rated', 'most_liked', 'most_commented', 'trending'],
    'description' => 'Sort mode',
    'default' => 'newest',
],
'period' => [
    'type' => 'string',
    'enum' => ['7d', '30d', '90d', '1y', 'all'],
    'description' => 'Time window filter',
    'default' => 'all',
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
            'ml_publication_get' => [
                'name' => 'ml_publication_get',
                'description' => 'Get detailed information about a specific publication including content, authors, and your permissions.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'The publication ID to retrieve',
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
            ],
            'ml_publication_dependencies' => [
                'name' => 'ml_publication_dependencies',
                'description' => 'Get the dependency tree for a publication (what it uses and what uses it).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'The publication ID',
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
            ],
        ];
    }

    /**
     * Execute a publications tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();

        // Check if publications are available
        if (!Publication_Service::is_available()) {
            return Tool_Response::error(
                'feature_unavailable',
                'Publications feature is not available on this site.',
                $request_id
            );
        }

        $service = new Publication_Service($user_id);

        switch ($tool) {
            case 'ml_publications_list':
                return self::handle_list($service, $args, $request_id);

            case 'ml_publication_get':
                return self::handle_get($service, $args, $request_id);

            case 'ml_publication_dependencies':
                return self::handle_dependencies($service, $args, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_publications_list
     */
    private static function handle_list(Publication_Service $service, array $args, string $request_id): array {
        $filters = [];

        if (!empty($args['space_id'])) {
            $filters['space_id'] = (int) $args['space_id'];
        }
        if (!empty($args['step'])) {
            $filters['step'] = $args['step'];
        }
        if (!empty($args['author_id'])) {
            $filters['author_id'] = (int) $args['author_id'];
        }
        if (!empty($args['search'])) {
            $filters['search'] = $args['search'];
        }
if (!empty($args['type'])) {
    $filters['type'] = $args['type'];
}
if (!empty($args['tags'])) {
    $filters['tags'] = $args['tags'];
}
if (!empty($args['sort'])) {
    $filters['sort'] = $args['sort'];
}
if (!empty($args['period'])) {
    $filters['period'] = $args['period'];
}


        $pagination = Query_Service::parse_pagination($args);
        $result = $service->list_publications($filters, $pagination['offset'], $pagination['limit']);

        if (empty($result['publications'])) {
            return Tool_Response::empty_list($request_id, 'No publications found matching your criteria.');
        }

        $pagination_response = Query_Service::build_pagination_response(
            $pagination['offset'],
            $pagination['limit'],
            count($result['publications']),
            $result['total_count']
        );

        return Tool_Response::list(
            $result['publications'],
            $pagination_response,
            $request_id
        );
    }

    /**
     * Handle ml_publication_get
     */
    private static function handle_get(Publication_Service $service, array $args, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];
        $publication = $service->get_publication($publication_id);

        if (!$publication) {
            return Tool_Response::not_found('publication', $request_id);
        }

        return Tool_Response::found($publication, $request_id, [
            'View dependencies' => "ml_publication_dependencies(publication_id: {$publication_id})",
            'View space' => $publication['space_id'] ? "ml_space_get(space_id: {$publication['space_id']})" : null,
        ]);
    }

    /**
     * Handle ml_publication_dependencies
     */
    private static function handle_dependencies(Publication_Service $service, array $args, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];
        $dependencies = $service->get_dependencies($publication_id);

        if ($dependencies === null) {
            return Tool_Response::not_found('publication', $request_id);
        }

        return Tool_Response::ok([
            'publication_id' => $publication_id,
            'uses' => Tool_Response::index_results($dependencies['uses']),
            'uses_count' => count($dependencies['uses']),
            'used_by' => Tool_Response::index_results($dependencies['used_by']),
            'used_by_count' => count($dependencies['used_by']),
        ], $request_id);
    }
}
