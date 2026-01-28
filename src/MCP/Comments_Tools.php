<?php
/**
 * Comments Tools - MCP tools for publication comments
 *
 * Tools:
 * - ml_comments_list: List comments on a publication
 * - ml_comment_add: Add a comment to a publication
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Comment_Service;
use MCP_No_Headless\Services\Query_Service;

class Comments_Tools {

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_comments_list' => [
                'name' => 'ml_comments_list',
                'description' => 'List comments on a publication. Can filter by visibility (public/private/all).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'The publication ID',
                        ],
                        'visibility' => [
                            'type' => 'string',
                            'enum' => ['all', 'public', 'private'],
                            'description' => 'Filter by comment visibility (default: all)',
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
                    'required' => ['publication_id'],
                ],
            ],
            'ml_comment_add' => [
                'name' => 'ml_comment_add',
                'description' => 'Add a comment to a publication.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'The publication ID',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'The comment content (markdown supported)',
                        ],
                        'visibility' => [
                            'type' => 'string',
                            'enum' => ['public', 'private'],
                            'description' => 'Comment visibility (default: public)',
                            'default' => 'public',
                        ],
                        'parent_id' => [
                            'type' => 'integer',
                            'description' => 'Parent comment ID for replies',
                        ],
                    ],
                    'required' => ['publication_id', 'content'],
                ],
            ],
        ];
    }

    /**
     * Execute a comments tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();

        if ($user_id <= 0) {
            return Tool_Response::error(
                'auth_required',
                'You must be logged in to access comments.',
                $request_id
            );
        }

        $service = new Comment_Service($user_id);

        switch ($tool) {
            case 'ml_comments_list':
                return self::handle_list($service, $args, $request_id);

            case 'ml_comment_add':
                return self::handle_add($service, $args, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_comments_list
     */
    private static function handle_list(Comment_Service $service, array $args, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];
        $visibility = $args['visibility'] ?? 'all';
        $pagination = Query_Service::parse_pagination($args);

        $result = $service->list_comments($publication_id, $visibility, $pagination['offset'], $pagination['limit']);

        if ($result === null) {
            return Tool_Response::not_found('publication', $request_id);
        }

        if (empty($result['comments'])) {
            return Tool_Response::empty_list($request_id, 'No comments found on this publication.');
        }

        $pagination_response = Query_Service::build_pagination_response(
            $pagination['offset'],
            $pagination['limit'],
            count($result['comments']),
            $result['total_count']
        );

        return Tool_Response::list(
            $result['comments'],
            $pagination_response,
            $request_id
        );
    }

    /**
     * Handle ml_comment_add
     */
    private static function handle_add(Comment_Service $service, array $args, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id', 'content']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];
        $content = $args['content'];
        $visibility = $args['visibility'] ?? 'public';
        $parent_id = isset($args['parent_id']) ? (int) $args['parent_id'] : null;

        $result = $service->add_comment($publication_id, $content, $visibility, $parent_id);

        if (!$result['ok']) {
            if ($result['error'] === 'publication_not_accessible') {
                return Tool_Response::not_found('publication', $request_id);
            }
            return Tool_Response::error(
                $result['error'],
                $result['message'] ?? 'Failed to add comment.',
                $request_id
            );
        }

        return Tool_Response::committed([
            'comment' => $result['comment'],
        ], $request_id, 'Comment added successfully.');
    }
}
