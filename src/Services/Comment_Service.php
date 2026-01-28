<?php
/**
 * Comment Service - Business logic for publication comments
 *
 * Handles:
 * - Listing comments with visibility filtering
 * - Adding public/private comments
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\MCP\Permission_Checker;

class Comment_Service {

    private int $user_id;
    private Permission_Checker $permissions;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
    }

    /**
     * List comments for a publication
     *
     * @param int $publication_id Publication ID
     * @param string $visibility Filter: 'all', 'public', 'private'
     * @param int $offset Pagination offset
     * @param int $limit Pagination limit
     * @return array|null Comments or null if not accessible
     */
    public function list_comments(int $publication_id, string $visibility = 'all', int $offset = 0, int $limit = 20): ?array {
        if (!$this->permissions->can_see_publication($publication_id)) {
            return null;
        }

        $can_see_private = $this->permissions->can_comment_publication($publication_id, 'private');

        $args = [
            'post_id' => $publication_id,
            'status' => 'approve',
            'orderby' => 'comment_date',
            'order' => 'ASC',
            'number' => $limit,
            'offset' => $offset,
        ];

        // Handle visibility filter
        $meta_query = [];
        if ($visibility === 'private') {
            if (!$can_see_private) {
                return [
                    'comments' => [],
                    'has_more' => false,
                    'total_count' => 0,
                ];
            }
            $meta_query[] = [
                'key' => '_ml_comment_visibility',
                'value' => 'private',
            ];
        } elseif ($visibility === 'public') {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => '_ml_comment_visibility',
                    'value' => 'public',
                ],
                [
                    'key' => '_ml_comment_visibility',
                    'compare' => 'NOT EXISTS',
                ],
            ];
        } else {
            // 'all' - only include private if user has access
            if (!$can_see_private) {
                $meta_query[] = [
                    'relation' => 'OR',
                    [
                        'key' => '_ml_comment_visibility',
                        'value' => 'public',
                    ],
                    [
                        'key' => '_ml_comment_visibility',
                        'compare' => 'NOT EXISTS',
                    ],
                ];
            }
        }

        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }

        $comments_query = new \WP_Comment_Query($args);
        $comments = $comments_query->comments;

        // Get total count
        $count_args = $args;
        $count_args['count'] = true;
        unset($count_args['number'], $count_args['offset']);
        $total = (new \WP_Comment_Query($count_args))->get_comments();

        // Format comments
        $formatted = [];
        foreach ($comments as $comment) {
            $formatted[] = $this->format_comment($comment, $can_see_private);
        }

        return [
            'comments' => $formatted,
            'has_more' => ($offset + count($comments)) < $total,
            'total_count' => (int) $total,
        ];
    }

    /**
     * Add a comment to a publication
     *
     * @param int $publication_id Publication ID
     * @param string $content Comment content
     * @param string $visibility 'public' or 'private'
     * @param int|null $parent_id Parent comment ID for replies
     * @return array Result with comment data or error
     */
    public function add_comment(int $publication_id, string $content, string $visibility = 'public', ?int $parent_id = null): array {
        if (!$this->permissions->can_see_publication($publication_id)) {
            return [
                'ok' => false,
                'error' => 'publication_not_accessible',
            ];
        }

        if (!$this->permissions->can_comment_publication($publication_id, $visibility)) {
            return [
                'ok' => false,
                'error' => 'cannot_comment',
                'message' => sprintf('You cannot post %s comments on this publication.', $visibility),
            ];
        }

        $user = get_userdata($this->user_id);
        if (!$user) {
            return [
                'ok' => false,
                'error' => 'user_not_found',
            ];
        }

        // Sanitize content
        $content = wp_kses_post($content);
        if (empty(trim($content))) {
            return [
                'ok' => false,
                'error' => 'empty_content',
                'message' => 'Comment content cannot be empty.',
            ];
        }

        $comment_data = [
            'comment_post_ID' => $publication_id,
            'comment_content' => $content,
            'comment_author' => $user->display_name,
            'comment_author_email' => $user->user_email,
            'user_id' => $this->user_id,
            'comment_approved' => 1,
        ];

        if ($parent_id) {
            $comment_data['comment_parent'] = $parent_id;
        }

        $comment_id = wp_insert_comment($comment_data);

        if (!$comment_id) {
            return [
                'ok' => false,
                'error' => 'insert_failed',
                'message' => 'Failed to create comment.',
            ];
        }

// Set visibility meta
update_comment_meta($comment_id, '_ml_comment_visibility', $visibility);

// Keep publication stats in sync (comment count + quality/trending score)
try {
    $scoring = new Scoring_Service();
    $scoring->update_metric($publication_id, 'comments', 1, true);
} catch (\Throwable $e) {
    // Non-blocking
}

$comment = get_comment($comment_id);

        return [
            'ok' => true,
            'comment' => $this->format_comment($comment, true),
        ];
    }

    /**
     * Format a comment for output
     */
    private function format_comment(\WP_Comment $comment, bool $include_visibility = false): array {
        $visibility = get_comment_meta($comment->comment_ID, '_ml_comment_visibility', true);
        if (empty($visibility)) {
            $visibility = 'public';
        }

        $formatted = [
            'id' => (int) $comment->comment_ID,
            'content' => $comment->comment_content,
            'author' => [
                'id' => (int) $comment->user_id,
                'name' => $comment->comment_author,
                'avatar' => get_avatar_url($comment->user_id, ['size' => 48]),
            ],
            'date' => Render_Service::format_date($comment->comment_date),
            'parent_id' => (int) $comment->comment_parent ?: null,
        ];

        if ($include_visibility) {
            $formatted['visibility'] = $visibility;
        }

        return $formatted;
    }

    /**
     * Check if comments feature is available
     */
    public static function is_available(): bool {
        return true;
    }
}
