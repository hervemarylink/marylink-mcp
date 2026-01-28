<?php
/**
 * Best-of Service - Business logic for top publications
 *
 * Handles:
 * - Listing top-rated publications
 * - Filtering by space and time period
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\Picasso\Meta_Keys;

use MCP_No_Headless\MCP\Permission_Checker;

class Bestof_Service {

    private int $user_id;
    private Permission_Checker $permissions;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
    }

    /**
     * Get best publications based on ratings/popularity
     *
     * @param array $filters [space_id, period]
     * @param int $offset Pagination offset
     * @param int $limit Pagination limit
     * @return array [publications, has_more, total_count]
     */
    public function get_best(array $filters = [], int $offset = 0, int $limit = 20): array {
        $query_args = [
            'post_type' => 'publication',
            'post_status' => 'publish',
            'meta_key' => Meta_Keys::RATING_USER_AVG,
            'orderby' => 'meta_value_num',
            'order' => 'DESC',
        ];

        // Filter by space
        if (!empty($filters['space_id'])) {
            $space_id = (int) $filters['space_id'];
            if (!$this->permissions->can_see_space($space_id)) {
                return [
                    'publications' => [],
                    'has_more' => false,
                    'total_count' => 0,
                ];
            }
            $query_args['post_parent'] = $space_id;
        }

// Filter by label/type and tags
if (!empty($filters['type'])) {
    $type_terms = $this->resolve_term_ids($filters['type'], 'publication_label');
    if (!empty($type_terms)) {
        $query_args['tax_query'] = $query_args['tax_query'] ?? [];
        $query_args['tax_query'][] = [
            'taxonomy' => 'publication_label',
            'field' => 'term_id',
            'terms' => $type_terms,
        ];
    }
}

if (!empty($filters['tags'])) {
    $tag_terms = $this->resolve_term_ids($filters['tags'], 'publication_tag');
    if (!empty($tag_terms)) {
        $query_args['tax_query'] = $query_args['tax_query'] ?? [];
        $query_args['tax_query'][] = [
            'taxonomy' => 'publication_tag',
            'field' => 'term_id',
            'terms' => $tag_terms,
        ];
    }
}

// Filter by label/type and tags
if (!empty($filters['type'])) {
    $type_terms = $this->resolve_term_ids($filters['type'], 'publication_label');
    if (!empty($type_terms)) {
        $query_args['tax_query'] = $query_args['tax_query'] ?? [];
        $query_args['tax_query'][] = [
            'taxonomy' => 'publication_label',
            'field' => 'term_id',
            'terms' => $type_terms,
        ];
    }
}

if (!empty($filters['tags'])) {
    $tag_terms = $this->resolve_term_ids($filters['tags'], 'publication_tag');
    if (!empty($tag_terms)) {
        $query_args['tax_query'] = $query_args['tax_query'] ?? [];
        $query_args['tax_query'][] = [
            'taxonomy' => 'publication_tag',
            'field' => 'term_id',
            'terms' => $tag_terms,
        ];
    }
}

        // Filter by period
        if (!empty($filters['period'])) {
            $date_query = $this->get_date_query($filters['period']);
            if ($date_query) {
                $query_args['date_query'] = $date_query;
            }
        }

        // Only publications with ratings
        $query_args['meta_query'] = [
            [
                'key' => Meta_Keys::RATING_USER_AVG,
                'compare' => 'EXISTS',
            ],
            [
                'key' => Meta_Keys::RATING_COUNT,
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ],
        ];

        // Query with pagination
        $result = Query_Service::query_posts($query_args, $offset, $limit);

        // Filter by permission and format
        $publications = [];
        foreach ($result['posts'] as $post) {
            if ($this->permissions->can_see_publication($post->ID)) {
                $publications[] = $this->format_bestof_item($post);
            }
        }

        return [
            'publications' => $publications,
            'has_more' => $result['has_more'],
            'total_count' => $result['total'],
        ];
    }

    /**
     * Get trending publications (recent with high engagement)
     *
     * @param array $filters [space_id, period]
     * @param int $offset Pagination offset
     * @param int $limit Pagination limit
     * @return array [publications, has_more, total_count]
     */
    public function get_trending(array $filters = [], int $offset = 0, int $limit = 20): array {
        // Default to 7 days for trending
        if (empty($filters['period'])) {
            $filters['period'] = '7d';
        }

        $query_args = [
            'post_type' => 'publication',
            'post_status' => 'publish',
            'meta_key' => '_ml_engagement_score',
            'orderby' => 'meta_value_num',
            'order' => 'DESC',
        ];

        // Filter by space
        if (!empty($filters['space_id'])) {
            $space_id = (int) $filters['space_id'];
            if (!$this->permissions->can_see_space($space_id)) {
                return [
                    'publications' => [],
                    'has_more' => false,
                    'total_count' => 0,
                ];
            }
            $query_args['post_parent'] = $space_id;
        }

        // Apply date filter
        $date_query = $this->get_date_query($filters['period']);
        if ($date_query) {
            $query_args['date_query'] = $date_query;
        }

        // Query with pagination
        $result = Query_Service::query_posts($query_args, $offset, $limit);

        // Filter by permission and format
        $publications = [];
        foreach ($result['posts'] as $post) {
            if ($this->permissions->can_see_publication($post->ID)) {
                $publications[] = $this->format_bestof_item($post);
            }
        }

        return [
            'publications' => $publications,
            'has_more' => $result['has_more'],
            'total_count' => $result['total'],
        ];
    }

    /**
     * Build date query for period filter
     */
    private function get_date_query(string $period): ?array {
        $periods = [
            '7d' => '-7 days',
            '30d' => '-30 days',
            '90d' => '-90 days',
            '1y' => '-1 year',
            'all' => null,
        ];

        if (!isset($periods[$period]) || $periods[$period] === null) {
            return null;
        }

        return [
            [
                'after' => $periods[$period],
                'inclusive' => true,
            ],
        ];
    }


/**
 * Resolve a term (or list of terms) into term IDs for a taxonomy.
 * Accepts: term_id, slug, or name.
 */
private function resolve_term_ids($input, string $taxonomy): array {
    $terms = is_array($input) ? $input : [$input];
    $ids = [];

    foreach ($terms as $t) {
        if ($t === null) {
            continue;
        }

        if (is_int($t) || (is_string($t) && ctype_digit($t))) {
            $ids[] = (int) $t;
            continue;
        }

        $t = trim((string) $t);
        if ($t === '') {
            continue;
        }

        $term = get_term_by('slug', $t, $taxonomy);
        if (!$term) {
            $term = get_term_by('slug', sanitize_title($t), $taxonomy);
        }
        if (!$term) {
            $term = get_term_by('name', $t, $taxonomy);
        }

        if ($term && !is_wp_error($term)) {
            $ids[] = (int) $term->term_id;
        }
    }

    $ids = array_values(array_unique(array_filter($ids)));
    return $ids;
}

    /**
     * Format a publication for best-of output
     */
    private function format_bestof_item(\WP_Post $post): array {
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        $avg_rating = Meta_Keys::get_rating_avg($post->ID);
        $rating_count = Meta_Keys::get_rating_count($post->ID);
        $engagement = (int) get_post_meta($post->ID, '_ml_engagement_score', true);

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'excerpt' => Render_Service::excerpt_from_html($post->post_content, 120),
            'url' => get_permalink($post->ID),
            'space_id' => (int) $post->post_parent ?: null,
            'author' => [
                'id' => (int) $post->post_author,
                'name' => get_the_author_meta('display_name', $post->post_author),
            ],
            'thumbnail' => $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'thumbnail') : null,
            'rating' => [
                'average' => round($avg_rating, 2),
                'count' => $rating_count,
            ],
            'engagement_score' => $engagement,
            'date' => Render_Service::format_date($post->post_date),
        ];
    }

    /**
     * Check if best-of feature is available
     */
    public static function is_available(): bool {
        return post_type_exists('publication');
    }
}
