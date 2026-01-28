<?php
/**
 * Query Service - WP_Query wrappers with cursor pagination
 *
 * Provides:
 * - Cursor-based pagination (base64 encoded JSON)
 * - Safe limit clamping
 * - Common query patterns for publications, spaces, etc.
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

class Query_Service {

    /**
     * Default pagination limit
     */
    const DEFAULT_LIMIT = 20;

    /**
     * Maximum pagination limit
     */
    const MAX_LIMIT = 50;

    /**
     * Encode cursor from pagination data
     *
     * @param array $data Cursor data (offset, limit, last_id, etc.)
     * @return string Base64 encoded cursor
     */
    public static function encode_cursor(array $data): string {
        return base64_encode(wp_json_encode($data));
    }

    /**
     * Decode cursor to pagination data
     *
     * @param string|null $cursor Base64 encoded cursor
     * @return array|null Decoded data or null if invalid
     */
    public static function decode_cursor(?string $cursor): ?array {
        if (empty($cursor)) {
            return null;
        }

        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            return null;
        }

        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Parse pagination parameters from args
     *
     * @param array $args Tool arguments
     * @param int $default_limit Default limit
     * @param int $max_limit Maximum limit
     * @return array [offset, limit, cursor_data]
     */
    public static function parse_pagination(array $args, int $default_limit = self::DEFAULT_LIMIT, int $max_limit = self::MAX_LIMIT): array {
        $limit = self::clamp_limit($args['limit'] ?? null, $default_limit, $max_limit);
        $offset = 0;
        $cursor_data = null;

        // Check for cursor
        if (!empty($args['cursor'])) {
            $cursor_data = self::decode_cursor($args['cursor']);
            if ($cursor_data) {
                $offset = $cursor_data['offset'] ?? 0;
                // Cursor can override limit if needed
                if (isset($cursor_data['limit'])) {
                    $limit = self::clamp_limit($cursor_data['limit'], $default_limit, $max_limit);
                }
            }
        }

        // Legacy: direct offset parameter
        if (isset($args['offset']) && is_numeric($args['offset'])) {
            $offset = max(0, (int) $args['offset']);
        }

        return [
            'offset' => $offset,
            'limit' => $limit,
            'cursor_data' => $cursor_data,
        ];
    }

    /**
     * Build pagination response data
     *
     * @param int $offset Current offset
     * @param int $limit Current limit
     * @param int $returned_count Number of items returned
     * @param int|null $total_count Total count (optional, expensive)
     * @return array Pagination data for response
     */
    public static function build_pagination_response(int $offset, int $limit, int $returned_count, ?int $total_count = null): array {
        $has_more = $returned_count >= $limit;

        $pagination = [
            'has_more' => $has_more,
        ];

        if ($has_more) {
            $pagination['next_cursor'] = self::encode_cursor([
                'offset' => $offset + $limit,
                'limit' => $limit,
            ]);
        }

        if ($total_count !== null) {
            $pagination['total_count'] = $total_count;
        }

        return $pagination;
    }

    /**
     * Clamp limit to safe range
     *
     * @param int|null $limit Requested limit
     * @param int $default Default limit
     * @param int $max Maximum limit
     * @return int Clamped limit
     */
    public static function clamp_limit(?int $limit, int $default = self::DEFAULT_LIMIT, int $max = self::MAX_LIMIT): int {
        if ($limit === null || $limit <= 0) {
            return $default;
        }
        return min($limit, $max);
    }

    /**
     * Query posts with standard parameters
     *
     * @param array $query_args WP_Query args
     * @param int $offset Pagination offset
     * @param int $limit Pagination limit
     * @return array [posts, has_more]
     */
    public static function query_posts(array $query_args, int $offset = 0, int $limit = self::DEFAULT_LIMIT): array {
        $query_args = array_merge([
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'offset' => $offset,
            'posts_per_page' => $limit + 1, // Fetch one extra to check has_more
        ], $query_args);

        $query = new \WP_Query($query_args);
        $posts = $query->posts;

        // Check if there are more
        $has_more = count($posts) > $limit;
        if ($has_more) {
            array_pop($posts); // Remove the extra item
        }

        return [
            'posts' => $posts,
            'has_more' => $has_more,
            'total_found' => $query->found_posts,
        ];
    }

    /**
     * Query posts with meta sorting
     *
     * @param array $query_args Base query args
     * @param string $meta_key Meta key to sort by
     * @param string $order ASC or DESC
     * @param int $offset Pagination offset
     * @param int $limit Pagination limit
     * @return array [posts, has_more]
     */
    public static function query_posts_by_meta(array $query_args, string $meta_key, string $order = 'DESC', int $offset = 0, int $limit = self::DEFAULT_LIMIT): array {
        $query_args['meta_key'] = $meta_key;
        $query_args['orderby'] = 'meta_value_num';
        $query_args['order'] = strtoupper($order);

        return self::query_posts($query_args, $offset, $limit);
    }

    /**
     * Search posts by title/content
     *
     * @param string $search Search term
     * @param array $post_types Post types to search
     * @param int $offset Pagination offset
     * @param int $limit Pagination limit
     * @return array [posts, has_more]
     */
    public static function search_posts(string $search, array $post_types, int $offset = 0, int $limit = self::DEFAULT_LIMIT): array {
        return self::query_posts([
            'post_type' => $post_types,
            's' => $search,
        ], $offset, $limit);
    }

    /**
     * Get posts by IDs (preserving order)
     *
     * @param array $ids Post IDs
     * @param string $post_type Post type to verify
     * @return array Posts in same order as IDs
     */
    public static function get_posts_by_ids(array $ids, string $post_type = 'any'): array {
        if (empty($ids)) {
            return [];
        }

        $query_args = [
            'post_type' => $post_type,
            'post__in' => $ids,
            'orderby' => 'post__in',
            'posts_per_page' => count($ids),
            'post_status' => 'any', // Include drafts etc for permission check
        ];

        $query = new \WP_Query($query_args);
        return $query->posts;
    }

    /**
     * Get single post by ID
     *
     * @param int $id Post ID
     * @param string|null $post_type Expected post type (null for any)
     * @return \WP_Post|null
     */
    public static function get_post(int $id, ?string $post_type = null): ?\WP_Post {
        $post = get_post($id);

        if (!$post) {
            return null;
        }

        if ($post_type !== null && $post->post_type !== $post_type) {
            return null;
        }

        return $post;
    }

    /**
     * Count posts matching criteria
     *
     * @param array $query_args WP_Query args
     * @return int Count
     */
    public static function count_posts(array $query_args): int {
        $query_args['posts_per_page'] = 1;
        $query_args['fields'] = 'ids';

        $query = new \WP_Query($query_args);
        return $query->found_posts;
    }

    /**
     * Get post meta with default
     *
     * @param int $post_id Post ID
     * @param string $key Meta key
     * @param mixed $default Default value
     * @return mixed
     */
    public static function get_meta(int $post_id, string $key, $default = null) {
        $value = get_post_meta($post_id, $key, true);
        return $value !== '' ? $value : $default;
    }

    /**
     * Get multiple post meta at once
     *
     * @param int $post_id Post ID
     * @param array $keys Meta keys
     * @return array Key => value pairs
     */
    public static function get_metas(int $post_id, array $keys): array {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = get_post_meta($post_id, $key, true);
        }
        return $result;
    }

    /**
     * Get term names for a post
     *
     * @param int $post_id Post ID
     * @param string $taxonomy Taxonomy name
     * @return array Term names
     */
    public static function get_term_names(int $post_id, string $taxonomy): array {
        $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);
        return is_wp_error($terms) ? [] : $terms;
    }

    /**
     * Build sort parameters from args
     *
     * @param array $args Tool arguments
     * @param array $allowed_sorts Allowed sort fields
     * @param string $default_sort Default sort field
     * @param string $default_dir Default direction
     * @return array [orderby, order]
     */
    public static function parse_sort(array $args, array $allowed_sorts, string $default_sort = 'date', string $default_dir = 'DESC'): array {
        $sort = $args['sort'] ?? $default_sort;
        $dir = strtoupper($args['dir'] ?? $default_dir);

        // Validate sort field
        if (!in_array($sort, $allowed_sorts, true)) {
            $sort = $default_sort;
        }

        // Validate direction
        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            $dir = $default_dir;
        }

        return [
            'orderby' => $sort,
            'order' => $dir,
        ];
    }
}
