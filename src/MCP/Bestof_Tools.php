<?php
/**
 * Best-of Tools - MCP tools for top publications
 *
 * Tools:
 * - ml_best_list: List top-rated publications
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Bestof_Service;
use MCP_No_Headless\Services\Query_Service;

class Bestof_Tools {

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_best_list' => [
                'name' => 'ml_best_list',
                'description' => 'List the best (top-rated) publications. Can filter by space and time period.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'space_id' => [
                            'type' => 'integer',
                            'description' => 'Filter by space ID',
                        ],
                        'type' => [
                            'type' => 'string',
                            'description' => 'Filter by publication label/type (slug or name)',
                        ],
                        'tags' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Filter by publication tags (slugs or IDs)',
                        ],
                        'period' => [
                            'type' => 'string',
                            'enum' => ['7d', '30d', '90d', '1y', 'all'],
                            'description' => 'Time period filter (default: all)',
                            'default' => 'all',
                        ],
                        'sort' => [
                            'type' => 'string',
                            'enum' => ['rating', 'trending'],
                            'description' => 'Sort by: rating (best rated) or trending (recent popular)',
                            'default' => 'rating',
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
        ];
    }

    /**
     * Execute a best-of tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();

        if (!Bestof_Service::is_available()) {
            return Tool_Response::error(
                'feature_unavailable',
                'Best-of feature is not available on this site.',
                $request_id
            );
        }

        $service = new Bestof_Service($user_id);

        switch ($tool) {
            case 'ml_best_list':
                return self::handle_list($service, $args, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_best_list
     */
    private static function handle_list(Bestof_Service $service, array $args, string $request_id): array {
        $filters = [];

        if (!empty($args['space_id'])) {
            $filters['space_id'] = (int) $args['space_id'];
        }
        if (!empty($args['period'])) {
            $filters['period'] = $args['period'];
        }
        if (!empty($args['type'])) {
            $filters['type'] = $args['type'];
        }
        if (!empty($args['tags'])) {
            $filters['tags'] = $args['tags'];
        }

        $pagination = Query_Service::parse_pagination($args);
        $sort = $args['sort'] ?? 'rating';

        if ($sort === 'trending') {
            $result = $service->get_trending($filters, $pagination['offset'], $pagination['limit']);
        } else {
            $result = $service->get_best($filters, $pagination['offset'], $pagination['limit']);
        }

        if (empty($result['publications'])) {
            $message = $sort === 'trending'
                ? 'No trending publications found for this period.'
                : 'No rated publications found.';
            return Tool_Response::empty_list($request_id, $message);
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
}
