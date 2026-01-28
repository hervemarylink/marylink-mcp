<?php
/**
 * Favorites Tools - MCP tools for user favorites (bookmarks)
 *
 * Tools:
 * - ml_favorites_list: List user's bookmarked publications
 * - ml_favorites_set: Add or remove a favorite
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Favorite_Service;
use MCP_No_Headless\Services\Query_Service;

class Favorites_Tools {

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_favorites_list' => [
                'name' => 'ml_favorites_list',
                'description' => 'List your bookmarked/favorited publications.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
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
            'ml_favorites_set' => [
                'name' => 'ml_favorites_set',
                'description' => 'Add or remove a publication from your favorites.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'The publication ID to favorite/unfavorite',
                        ],
                        'favorited' => [
                            'type' => 'boolean',
                            'description' => 'True to add to favorites, false to remove. If not specified, toggles the current state.',
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
            ],
        ];
    }

    /**
     * Execute a favorites tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();

        if ($user_id <= 0) {
            return Tool_Response::error(
                'auth_required',
                'You must be logged in to manage favorites.',
                $request_id
            );
        }

        $service = new Favorite_Service($user_id);

        switch ($tool) {
            case 'ml_favorites_list':
                return self::handle_list($service, $args, $request_id);

            case 'ml_favorites_set':
                return self::handle_set($service, $args, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_favorites_list
     */
    private static function handle_list(Favorite_Service $service, array $args, string $request_id): array {
        $pagination = Query_Service::parse_pagination($args);
        $result = $service->list_favorites($pagination['offset'], $pagination['limit']);

        if (empty($result['favorites'])) {
            return Tool_Response::empty_list($request_id, 'You have no favorites yet.');
        }

        $pagination_response = Query_Service::build_pagination_response(
            $pagination['offset'],
            $pagination['limit'],
            count($result['favorites']),
            $result['total_count']
        );

        return Tool_Response::list(
            $result['favorites'],
            $pagination_response,
            $request_id
        );
    }

    /**
     * Handle ml_favorites_set
     */
    private static function handle_set(Favorite_Service $service, array $args, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];

        // If favorited is specified, use set; otherwise toggle
        if (isset($args['favorited'])) {
            $favorited = (bool) $args['favorited'];
            $result = $service->set_favorite($publication_id, $favorited);
        } else {
            $result = $service->toggle_favorite($publication_id);
        }

        if (isset($result['error'])) {
            if ($result['error'] === 'publication_not_accessible') {
                return Tool_Response::not_found('publication', $request_id);
            }
            return Tool_Response::error($result['error'], 'Failed to update favorite status.', $request_id);
        }

        return Tool_Response::ok([
            'publication_id' => $result['publication_id'],
            'is_favorited' => $result['is_favorited'],
            'action' => $result['is_favorited'] ? 'added' : 'removed',
        ], $request_id);
    }
}
