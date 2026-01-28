<?php
/**
 * Publication Service - Business logic for publications
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\MCP\Permission_Checker;
use MCP_No_Headless\Picasso\Picasso_Adapter;
use MCP_No_Headless\Picasso\Meta_Keys;

class Publication_Service {

    private int $user_id;
    private Permission_Checker $permissions;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
    }

    public function list_publications(array $filters = [], int $offset = 0, int $limit = 20): array {
        $query_args = [
            'post_type' => 'publication',
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        if (!empty($filters['space_id'])) {
            $space_id = (int) $filters['space_id'];
            if (!$this->permissions->can_see_space($space_id)) {
                return ['publications' => [], 'has_more' => false, 'total_count' => 0];
            }
            $query_args['post_parent'] = $space_id;
        }

        if (!empty($filters['step'])) {
            $step = sanitize_text_field($filters['step']);
            $query_args['meta_query'] = $query_args['meta_query'] ?? [];
            $query_args['meta_query'][] = [
                'relation' => 'OR',
                ['key' => '_publication_step', 'value' => $step, 'compare' => '='],
                ['key' => '_ml_step', 'value' => $step, 'compare' => '='],
            ];
        }

        if (!empty($filters['author_id'])) {
            $query_args['author'] = (int) $filters['author_id'];
        }

        if (!empty($filters['search'])) {
            $query_args['s'] = sanitize_text_field($filters['search']);
        }

        if (!empty($filters['type'])) {
            $type_terms = $this->resolve_term_ids($filters['type'], 'publication_label');
            if (!empty($type_terms)) {
                $query_args['tax_query'] = $query_args['tax_query'] ?? [];
                $query_args['tax_query'][] = ['taxonomy' => 'publication_label', 'field' => 'term_id', 'terms' => $type_terms];
            }
        }

        if (!empty($filters['tags'])) {
            $tag_terms = $this->resolve_term_ids($filters['tags'], 'publication_tag');
            if (!empty($tag_terms)) {
                $query_args['tax_query'] = $query_args['tax_query'] ?? [];
                $query_args['tax_query'][] = ['taxonomy' => 'publication_tag', 'field' => 'term_id', 'terms' => $tag_terms];
            }
        }

        if (!empty($filters['period']) && $filters['period'] !== 'all') {
            $date_query = $this->build_date_query($filters['period']);
            if ($date_query) {
                $query_args['date_query'] = $date_query;
            }
        }

        $sort = $filters['sort'] ?? 'newest';
        switch ($sort) {
            case 'oldest':
                $query_args['orderby'] = 'date';
                $query_args['order'] = 'ASC';
                break;
            case 'best_rated':
                $query_args['meta_key'] = Meta_Keys::RATING_USER_AVG;
                $query_args['orderby'] = 'meta_value_num';
                $query_args['order'] = 'DESC';
                $query_args['meta_query'] = $query_args['meta_query'] ?? [];
                $query_args['meta_query'][] = ['key' => Meta_Keys::RATING_COUNT, 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC'];
                break;
            case 'worst_rated':
                $query_args['meta_key'] = Meta_Keys::RATING_USER_AVG;
                $query_args['orderby'] = 'meta_value_num';
                $query_args['order'] = 'ASC';
                $query_args['meta_query'] = $query_args['meta_query'] ?? [];
                $query_args['meta_query'][] = ['key' => Meta_Keys::RATING_COUNT, 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC'];
                break;
            case 'most_rated':
                $query_args['meta_key'] = Meta_Keys::RATING_COUNT;
                $query_args['orderby'] = 'meta_value_num';
                $query_args['order'] = 'DESC';
                break;
            case 'most_liked':
                $query_args['meta_key'] = Meta_Keys::VOTES;
                $query_args['orderby'] = 'meta_value_num';
                $query_args['order'] = 'DESC';
                break;
            case 'most_commented':
                $query_args['orderby'] = 'comment_count';
                $query_args['order'] = 'DESC';
                break;
            case 'trending':
                $query_args['orderby'] = 'modified';
                $query_args['order'] = 'DESC';
                break;
            default:
                $query_args['orderby'] = 'date';
                $query_args['order'] = 'DESC';
                break;
        }

        $result = Query_Service::query_posts($query_args, $offset, $limit);
        $publications = [];
        foreach ($result['posts'] as $post) {
            if ($this->permissions->can_see_publication($post->ID)) {
                $publications[] = $this->format_publication_summary($post);
            }
        }
        return ['publications' => $publications, 'has_more' => $result['has_more'], 'total_count' => $result['total']];
    }

    public function get_publication(int $publication_id): ?array {
        if (!$this->permissions->can_see_publication($publication_id)) return null;
        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') return null;
        return $this->format_publication_full($post);
    }

    public function get_dependencies(int $publication_id): ?array {
        if (!$this->permissions->can_see_publication($publication_id)) return null;
        $deps = Picasso_Adapter::get_publication_dependencies($publication_id);
        $formatted = ['uses' => [], 'used_by' => []];
        foreach ($deps['dependencies'] as $dep_id) {
            if ($this->permissions->can_see_publication($dep_id)) {
                $dep_post = get_post($dep_id);
                if ($dep_post) $formatted['uses'][] = ['id' => $dep_id, 'title' => $dep_post->post_title, 'type' => $this->get_publication_type($dep_id), 'url' => get_permalink($dep_id)];
            }
        }
        foreach ($deps['dependents'] as $dep_id) {
            if ($this->permissions->can_see_publication($dep_id)) {
                $dep_post = get_post($dep_id);
                if ($dep_post) $formatted['used_by'][] = ['id' => $dep_id, 'title' => $dep_post->post_title, 'type' => $this->get_publication_type($dep_id), 'url' => get_permalink($dep_id)];
            }
        }
        return $formatted;
    }

    private function resolve_term_ids($input, string $taxonomy): array {
        $terms = is_array($input) ? $input : [$input];
        $ids = [];
        foreach ($terms as $t) {
            if ($t === null) continue;
            if (is_int($t) || (is_string($t) && ctype_digit($t))) { $ids[] = (int) $t; continue; }
            $t = trim((string) $t);
            if ($t === '') continue;
            $term = get_term_by('slug', $t, $taxonomy);
            if (!$term) $term = get_term_by('slug', sanitize_title($t), $taxonomy);
            if (!$term) $term = get_term_by('name', $t, $taxonomy);
            if ($term && !is_wp_error($term)) $ids[] = (int) $term->term_id;
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private function build_date_query(string $period): ?array {
        $map = ['7d' => '-7 days', '30d' => '-30 days', '90d' => '-90 days', '1y' => '-1 year'];
        if (!isset($map[$period])) return null;
        return [['after' => $map[$period], 'inclusive' => true]];
    }

    private function format_publication_summary(\WP_Post $post): array {
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        $space_id = $this->get_space_id($post);
        $labels = wp_get_post_terms($post->ID, 'publication_label', ['fields' => 'slugs']);
        if (is_wp_error($labels)) $labels = [];
        $tags = wp_get_post_terms($post->ID, 'publication_tag', ['fields' => 'slugs']);
        if (is_wp_error($tags)) $tags = [];

        $avg_rating = Meta_Keys::get_rating_avg($post->ID);
        $rating_count = Meta_Keys::get_rating_count($post->ID);
        $favorites_count = Meta_Keys::get_favorites_count($post->ID);
        $quality_score = Meta_Keys::get_quality_score($post->ID);
        $engagement_score = Meta_Keys::get_engagement_score($post->ID);

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'excerpt' => Render_Service::excerpt_from_html(!empty($post->post_excerpt) ? $post->post_excerpt : $post->post_content, 160),
            'url' => get_permalink($post->ID),
            'space_id' => $space_id,
            'step' => Picasso_Adapter::get_publication_step($post->ID),
            'labels' => $labels,
            'type' => !empty($labels) ? $labels[0] : null,
            'tags' => $tags,
            'rating' => ['average' => $avg_rating !== null ? round($avg_rating, 2) : null, 'count' => $rating_count],
            'favorites_count' => $favorites_count,
            'comment_count' => (int) $post->comment_count,
            'quality_score' => $quality_score,
            'engagement_score' => $engagement_score,
            'author' => $this->format_author($post->post_author),
            'thumbnail' => $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'thumbnail') : null,
            'date' => Render_Service::format_date($post->post_date),
            'date_modified' => Render_Service::format_date($post->post_modified),
        ];
    }

    private function format_publication_full(\WP_Post $post): array {
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        $content = Render_Service::prepare_content($post->post_content);
        $space_id = $this->get_space_id($post);
        $labels = wp_get_post_terms($post->ID, 'publication_label', ['fields' => 'slugs']);
        if (is_wp_error($labels)) $labels = [];
        $tags = wp_get_post_terms($post->ID, 'publication_tag', ['fields' => 'slugs']);
        if (is_wp_error($tags)) $tags = [];

        $avg_rating = Meta_Keys::get_rating_avg($post->ID);
        $rating_count = Meta_Keys::get_rating_count($post->ID);
        $favorites_count = Meta_Keys::get_favorites_count($post->ID);
        $quality_score = Meta_Keys::get_quality_score($post->ID);
        $engagement_score = Meta_Keys::get_engagement_score($post->ID);

        $publication = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content_html' => $content['content_html'],
            'content_text' => $content['content_text'],
            'excerpt' => Render_Service::excerpt_from_html(!empty($post->post_excerpt) ? $post->post_excerpt : $post->post_content, 240),
            'url' => get_permalink($post->ID),
            'space_id' => $space_id,
            'step' => Picasso_Adapter::get_publication_step($post->ID),
            'labels' => $labels,
            'type' => !empty($labels) ? $labels[0] : null,
            'tags' => $tags,
            'rating' => ['average' => $avg_rating !== null ? round($avg_rating, 2) : null, 'count' => $rating_count],
            'favorites_count' => $favorites_count,
            'quality_score' => $quality_score,
            'engagement_score' => $engagement_score,
            'authors' => Picasso_Adapter::get_publication_authors($post->ID),
            'thumbnail' => $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'medium') : null,
            'date' => Render_Service::format_date($post->post_date),
            'date_modified' => Render_Service::format_date($post->post_modified),
        ];

        $meta_keys = ['_ml_publication_type' => 'type', '_ml_visibility' => 'visibility'];
        foreach ($meta_keys as $key => $name) {
            $value = get_post_meta($post->ID, $key, true);
            if ($value !== '') $publication[$name] = $value;
        }

        $categories = wp_get_post_categories($post->ID, ['fields' => 'names']);
        if (!empty($categories) && !is_wp_error($categories)) $publication['categories'] = $categories;
        $post_tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
        if (!empty($post_tags) && !is_wp_error($post_tags)) $publication['tags'] = $post_tags;

        $publication['my_permissions'] = [
            'can_edit' => $this->permissions->can_edit_publication($post->ID),
            'can_comment_public' => $this->permissions->can_comment_publication($post->ID, 'public'),
            'can_comment_private' => $this->permissions->can_comment_publication($post->ID, 'private'),
        ];
        $publication['comment_count'] = (int) $post->comment_count;
        return $publication;
    }

    private function get_publication_type(int $publication_id): string {
        $type = get_post_meta($publication_id, '_ml_publication_type', true);
        return $type ?: 'publication';
    }

    private function get_space_id(\WP_Post $post): ?int {
        if ($post->post_parent > 0) return (int) $post->post_parent;
        return Picasso_Adapter::get_publication_space($post->ID);
    }

    private function format_author(int $author_id): array {
        $user = get_userdata($author_id);
        if (!$user) return ['id' => $author_id, 'name' => __('Unknown', 'mcp-no-headless')];
        return ['id' => $user->ID, 'name' => $user->display_name, 'avatar' => get_avatar_url($user->ID, ['size' => 48])];
    }

    public static function is_available(): bool {
        return post_type_exists('publication');
    }
}
