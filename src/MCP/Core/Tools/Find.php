<?php
/**
 * ml_find - Unified search and read tool
 *
 * Searches across entity types (publications, spaces, tools, users, groups, activities)
 * and reads specific items by ID. Replaces: ml_search, ml_get, ml_publication_get,
 * ml_space_info, ml_tool_read.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Tools;

use MCP_No_Headless\Schema\Publication_Schema;
use MCP_No_Headless\Services\Rating_Service;
use MCP_No_Headless\Services\Find_Ranking;
use MCP_No_Headless\Picasso\Meta_Keys;

use MCP_No_Headless\MCP\Core\Tool_Response;
use MCP_No_Headless\MCP\Core\Services\Permission_Service;

class Find {

    const TOOL_NAME = 'ml_find';
    const VERSION = '3.2.10';

    const TYPE_PUBLICATION = 'publication';
    const TYPE_SPACE = 'space';
    const TYPE_TOOL = 'tool';
    const TYPE_USER = 'user';
    const TYPE_GROUP = 'group';
    const TYPE_ACTIVITY = 'activity';
    const TYPE_PROMPT = 'prompt';
    const TYPE_STYLE = 'style';
    const TYPE_DATA = 'data';
    const TYPE_CONTENT = 'content';  // Alias for data
    const TYPE_CLIENT = 'client';
    const TYPE_PROJET = 'projet';
    const TYPE_ALL = 'all';          // Alias for any
    const TYPE_ANY = 'any';

    const VALID_TYPES = [
        self::TYPE_PUBLICATION,
        self::TYPE_SPACE,
        self::TYPE_TOOL,
        self::TYPE_PROMPT,
        self::TYPE_STYLE,
        self::TYPE_DATA,
        self::TYPE_CONTENT,
        self::TYPE_CLIENT,
        self::TYPE_PROJET,
        self::TYPE_USER,
        self::TYPE_GROUP,
        self::TYPE_ACTIVITY,
        self::TYPE_ALL,
        self::TYPE_ANY,
    ];

    // Labels that map to publication_label taxonomy
    const LABEL_TYPES = [
        self::TYPE_TOOL => 'tool',
        self::TYPE_PROMPT => 'prompt',
        self::TYPE_STYLE => 'style',
        self::TYPE_CONTENT => 'content',
        self::TYPE_DATA => 'content',   // legacy 'data' label
        self::TYPE_CLIENT => 'client',  // Future: when term exists
        self::TYPE_PROJET => 'projet',  // Future: when term exists
    ];

    // Type aliases for normalization (spec -> internal)
    const TYPE_ALIASES = [
        'all' => self::TYPE_ANY,
        'content' => self::TYPE_CONTENT,
        'project' => self::TYPE_PROJET,  // EN -> FR
        'data' => self::TYPE_CONTENT,
    ];

    const DEFAULT_LIMIT = 10;
    const MAX_LIMIT = 50;

    /**
     * Default expansions when reading a single item by ID.
     * Rationale: agents often forget to request optional `include=['reviews']`.
     * Kept ONLY for read-by-id to avoid perf regressions on search.
     */
    const DEFAULT_INCLUDE_ON_ID = ['metadata', 'reviews'];

    /**
     * Normalize type input to internal type
     * Handles aliases: all->any, content->data, project->projet
     */
    

/**
 * Return label slugs to match for a given type.
 * For backward compatibility, 'content' matches BOTH 'content' and legacy 'data' labels.
 */
private static function label_terms_for_type(string $type): array|string {
    if ($type === self::TYPE_CONTENT || $type === self::TYPE_DATA) {
        return ['content', 'data'];
    }
    return self::LABEL_TYPES[$type] ?? $type;
}

/**
 * Canonicalize output label (avoid returning legacy 'data' to clients).
 */
private static function canonical_label(string $type): string {
    return ($type === self::TYPE_DATA) ? self::TYPE_CONTENT : $type;
}

/**
 * Avoid returning invalid MySQL dates like 0000-00-00 00:00:00
 */
private static function safe_datetime($post, string $field = 'post_date_gmt'): string {
    $value = $post->$field ?? '';
    if (empty($value) || (substr((string)$value, 0, 10) === '0000-00-00')) {
        // Fallbacks in order
        foreach (['post_date', 'post_modified_gmt', 'post_modified'] as $f) {
            $v = $post->$f ?? '';
            if (!empty($v) && !(substr((string)$v, 0, 10) === '0000-00-00')) {
                return $v;
            }
        }
        return current_time('mysql', true);
    }
    return $value;
}
private static function normalize_type(string $type): string {
        $t = strtolower(trim($type));

        // Apply aliases
        if (isset(self::TYPE_ALIASES[$t])) {
            return self::TYPE_ALIASES[$t];
        }

        return $t;
    }

    /**
     * Execute find operation
     *
     * @param array $args Tool arguments
     * @param int $user_id Current user ID
     * @return array Search/read result
     */
    public static function execute(array $args, int $user_id): array {
        $start_time = microtime(true);

        // Parse arguments
        $query = trim($args['query'] ?? '');
        // PR1: Normaliser query="*" en liste (cha�ne vide = mode liste)
        if ($query === '*') {
            $query = '';
        }
        $type_raw = $args['type'] ?? self::TYPE_ANY;
        $type = self::normalize_type($type_raw);
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $space_id = isset($args['space_id']) ? (int) $args['space_id'] : null;
        $filters = $args['filters'] ?? [];

// Validate limit (avoid negative/zero values leaking into WP_Query)
$limit_raw = (int) ($args['limit'] ?? self::DEFAULT_LIMIT);
if ($limit_raw < 1) {
    return Tool_Response::validation_error(
        "Paramètre 'limit' invalide",
        ['limit' => 'Doit être >= 1']
    );
}
$limit = min($limit_raw, self::MAX_LIMIT);

$offset = max(0, (int) ($args['offset'] ?? 0));

// Include controls output expansion. Backward compatible: default is [] (no content).
$include = $args['include'] ?? [];
if (is_string($include)) {
    $include = array_filter(array_map('trim', explode(',', $include)));
}
if (!is_array($include)) {
    $include = [];
}

// If reviews are requested, force metadata (reviews live under metadata.*)
if (in_array('reviews', $include, true) && !in_array('metadata', $include, true)) {
    $include[] = 'metadata';
}

// Optional ranking / sorting (only applied when provided)
$sort = null;
if (isset($args['sort']) && $args['sort'] !== '') {
    $sort = strtolower(trim((string) $args['sort']));
    if (!in_array($sort, Find_Ranking::VALID_SORTS, true)) {
        return Tool_Response::validation_error(
            "Paramètre 'sort' invalide",
            ['sort' => 'Valeurs autorisées: ' . implode(', ', Find_Ranking::VALID_SORTS)]
        );
    }
}

        // Validate type
        if (!in_array($type, self::VALID_TYPES)) {
            return Tool_Response::validation_error(
                "Type invalide: $type",
                ['type' => "Valeurs valides: " . implode(', ', self::VALID_TYPES)]
            );
        }

// Validate space_id when provided (avoid silent no-op filters)
if ($space_id !== null && $space_id > 0) {
    $space_post = get_post($space_id);
    if (!$space_post || $space_post->post_type !== 'space') {
        return Tool_Response::validation_error(
            "Espace introuvable: $space_id",
            ['space_id' => 'Aucun post_type=space ne correspond à cet ID']
        );
    }
}

        // If ID is provided, fetch specific item
        if ($id !== null) {
            // Agent-friendly default: when reading a single item by ID,
            // auto-expand reviews unless caller explicitly provided include.
            if (!array_key_exists('include', $args) || empty($include)) {
                $include = array_values(array_unique(array_merge($include, self::DEFAULT_INCLUDE_ON_ID)));
            }
            return self::fetch_by_id($type, $id, $user_id, $include);
        }

        // Otherwise, search
        $results = self::search($query, $type, $space_id, $filters, $limit, $offset, $user_id, $include, $sort);

        $latency_ms = round((microtime(true) - $start_time) * 1000);

        return Tool_Response::ok([
            'type' => $type,
            'query' => $query,
            'total' => $results['total'],
            'limit' => $limit,
            'offset' => $offset,
            'items' => $results['items'],
            'latency_ms' => $latency_ms,
        ]);
    }

    /**
     * Fetch a specific item by ID
     */
    private static function fetch_by_id(string $type, int $id, int $user_id, array $include): array {
        $item = null;

        switch ($type) {
            case self::TYPE_PUBLICATION:
            case self::TYPE_ANY:
                $item = self::get_publication($id, $user_id, $include);
                if ($item) {
                    $item['_type'] = 'publication';
                }
                break;

            case self::TYPE_SPACE:
                $item = self::get_space($id, $user_id, $include);
                if ($item) {
                    $item['_type'] = 'space';
                }
                break;

            case self::TYPE_TOOL:
            case self::TYPE_PROMPT:
            case self::TYPE_STYLE:
            case self::TYPE_DATA:
                $item = self::get_labeled_item($type, $id, $user_id, $include);
                if ($item) {
                    $item['_type'] = $type;
                }
                break;

            case self::TYPE_USER:
                $item = self::get_user($id, $user_id, $include);
                if ($item) {
                    $item['_type'] = 'user';
                }
                break;

            case self::TYPE_GROUP:
                $item = self::get_group($id, $user_id, $include);
                if ($item) {
                    $item['_type'] = 'group';
                }
                break;

            case self::TYPE_ACTIVITY:
                $item = self::get_activity($id, $user_id, $include);
                if ($item) {
                    $item['_type'] = 'activity';
                }
                break;
        }

        if (!$item) {
            return Tool_Response::not_found($type, $id);
        }

        return Tool_Response::ok([
            'type' => $item['_type'],
            'item' => $item,
        ]);
    }

    /**
     * Search across types
     */
    private static function search(
        string $query,
        string $type,
        ?int $space_id,
        array $filters,
        int $limit,
        int $offset,
        int $user_id,
        array $include,
        ?string $sort
    ): array {
        $all_items = [];
        $total = 0;

        $types_to_search = ($type === self::TYPE_ANY)
            ? [self::TYPE_PUBLICATION, self::TYPE_SPACE, self::TYPE_TOOL, self::TYPE_PROMPT, self::TYPE_STYLE, self::TYPE_CONTENT, self::TYPE_CLIENT, self::TYPE_PROJET]
            : [$type];

        foreach ($types_to_search as $search_type) {
            $result = match ($search_type) {
                self::TYPE_PUBLICATION => self::search_publications($query, $space_id, $filters, $limit, $offset, $user_id, $include, $sort),
                self::TYPE_SPACE => self::search_spaces($query, $filters, $limit, $offset, $user_id, $include),
                self::TYPE_TOOL, self::TYPE_PROMPT, self::TYPE_STYLE, self::TYPE_DATA, self::TYPE_CONTENT, self::TYPE_CLIENT, self::TYPE_PROJET => self::search_by_label($search_type, $query, $filters, $limit, $offset, $user_id, $include, $sort),
                self::TYPE_USER => self::search_users($query, $filters, $limit, $offset, $user_id, $include),
                self::TYPE_GROUP => self::search_groups($query, $filters, $limit, $offset, $user_id, $include),
                self::TYPE_ACTIVITY => self::search_activities($query, $space_id, $filters, $limit, $offset, $user_id, $include),
                default => ['items' => [], 'total' => 0],
            };

            foreach ($result['items'] as $item) {
                $item['_type'] = $search_type;
                $all_items[] = $item;
            }
            $total += $result['total'];
        }

        // Sort by relevance/date if searching "any"
        if ($type === self::TYPE_ANY && !empty($query)) {
            usort($all_items, function ($a, $b) use ($query) {
                $score_a = self::relevance_score($a, $query);
                $score_b = self::relevance_score($b, $query);
                return $score_b <=> $score_a;
            });
        }

        // Apply limit/offset for "any" type (already applied per-type otherwise)
        if ($type === self::TYPE_ANY) {
            $all_items = array_slice($all_items, 0, $limit);
        }

        return [
            'items' => $all_items,
            'total' => $total,
        ];
    }

    // =========================================================================
    // PUBLICATION METHODS
    // =========================================================================

    private static function get_publication(int $id, int $user_id, array $include): ?array {
        $post = get_post($id);

        if (!$post || $post->post_type !== 'publication') {
            // Try BuddyPress activity
            if (function_exists('bp_activity_get')) {
                $activity = bp_activity_get(['in' => [$id], 'display_comments' => false]);
                if (!empty($activity['activities'])) {
                    return self::format_activity($activity['activities'][0], $include);
                }
            }
            return null;
        }

        // Check access
        if (!self::can_view_publication($post, $user_id)) {
            return null;
        }

        $data = self::format_publication($post, $include);

        if (in_array('metadata', $include, true)) {
            $qm = Publication_Schema::get_quality_metrics($post->ID);
            $data['metadata']['rating'] = $qm['rating'];
            $data['metadata']['favorites_count'] = $qm['favorites_count'];
            $data['metadata']['quality_score'] = $qm['quality_score'];
            $data['metadata']['engagement_score'] = $qm['engagement_score'];
        }

        if (in_array('reviews', $include, true) && in_array('metadata', $include, true)) {
            $data['metadata']['reviews_sample'] = self::get_reviews_sample((int) $post->ID, $user_id, 5);
        }

        return $data;
    }

    private static function search_publications(
        string $query,
        ?int $space_id,
        array $filters,
        int $limit,
        int $offset,
        int $user_id,
        array $include,
        ?string $sort
    ): array {
        global $wpdb;

        // Default query: last modified publications
$args = [
    'post_type' => 'publication',
    'post_status' => 'publish',
    'posts_per_page' => $limit,
    'offset' => $offset,
    'orderby' => 'date',
    'order' => 'DESC',
    'suppress_filters' => true, // Bypass URE Pro for MCP
];

// If a ranking sort is requested, we pull a wider candidate set then sort in-memory.
$use_ranking = !empty($sort);
if ($use_ranking) {
    $candidate_count = min(200, max(50, ($offset + $limit) * 5));
    $args['posts_per_page'] = $candidate_count;
    $args['offset'] = 0;
}

        if (!empty($query)) {
            $args['s'] = $query;
        }

        if ($space_id) {
            // Picasso model: use post_parent for space filtering
            $args['post_parent'] = $space_id;
        }

        // Apply filters
        if (!empty($filters['author_id'])) {
            $args['author'] = (int) $filters['author_id'];
        }

        if (!empty($filters['date_from'])) {
            $args['date_query'][] = ['after' => $filters['date_from']];
        }

        if (!empty($filters['date_to'])) {
            $args['date_query'][] = ['before' => $filters['date_to']];
        }

        if (!empty($filters['visibility'])) {
            $args['meta_query'][] = [
                'key' => '_ml_visibility',
                'value' => $filters['visibility'],
            ];
        }

        $wp_query = new \WP_Query($args);
$posts = [];

foreach ($wp_query->posts as $post) {
    if (self::can_view_publication($post, $user_id)) {
        $posts[] = $post;
    }
}

// Optional ranking
if (!empty($sort)) {
    // Filter for top_rated (only rated items)
if ($sort === 'top_rated') {
    $posts = Find_Ranking::filter_for_top_rated($posts);
}

    $posts = Find_Ranking::sort_posts($posts, $sort);
}

// Slice after ranking (supports offset pagination for ranked views)
if (!empty($sort) && $offset > 0) {
    $posts = array_slice($posts, $offset, $limit);
} elseif (!empty($sort)) {
    $posts = array_slice($posts, 0, $limit);
}

$items = [];
$include_reviews = in_array('reviews', $include, true);

foreach ($posts as $i => $post) {
    $item = self::format_publication($post, $include);

    // Enrich metadata with existing rating/quality fields
    if (in_array('metadata', $include, true)) {
        $qm = Publication_Schema::get_quality_metrics($post->ID);
        $item['metadata']['rating'] = $qm['rating'];
        $item['metadata']['favorites_count'] = $qm['favorites_count'];
        $item['metadata']['quality_score'] = $qm['quality_score'];
        $item['metadata']['engagement_score'] = $qm['engagement_score'];
    }

    // Attach a small review sample only for the top items to avoid heavy DB calls
    if ($include_reviews && in_array('metadata', $include, true)) {
        if ($i < 5) {
            $item['metadata']['reviews_sample'] = self::get_reviews_sample((int) $post->ID, $user_id, 3);
        } else {
            $item['metadata']['reviews_sample'] = [];
        }
    }

        // PR4: Add ranking_reason if requested
    if (in_array('ranking_reason', $include, true)) {
        $item['ranking_reason'] = Find_Ranking::get_ranking_reason((int) $post->ID, $sort);
    }

    $items[] = $item;
}

return [
    'items' => $items,
    'total' => $wp_query->found_posts,
];

    }

    private static function format_publication($post, array $include): array {
        $data = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'excerpt' => wp_trim_words($post->post_content, 30),
            'author_id' => (int) $post->post_author,
            'author_name' => get_the_author_meta('display_name', $post->post_author),
            'date' => self::safe_datetime($post, 'post_date_gmt'),
            'modified' => self::safe_datetime($post, 'post_modified_gmt'),
            'space_id' => $post->post_parent ?: (int) get_post_meta($post->ID, '_ml_space_id', true),
            'visibility' => get_post_meta($post->ID, '_ml_visibility', true) ?: 'public',
            'labels' => wp_get_post_terms($post->ID, 'publication_label', ['fields' => 'names']) ?: [],
            'tags' => wp_get_post_terms($post->ID, 'publication_tag', ['fields' => 'names']) ?: [],
        ];

        if (in_array('content', $include)) {
            $data['content'] = $post->post_content;
        }

        if (in_array('metadata', $include)) {
            $data['metadata'] = [
                'likes' => Meta_Keys::get_votes_count($post->ID),
                'comments_count' => (int) get_comments_number($post->ID),
                'views' => Meta_Keys::get_views_count($post->ID),
                'tool_id' => get_post_meta($post->ID, '_ml_tool_id', true),
            ];
        }

        if (in_array('comments', $include)) {
            $data['comments'] = self::get_publication_comments($post->ID);
        }

        return $data;
    }

    private static function can_view_publication($post, int $user_id): bool {
        // Use Permission_Service for centralized permission check
        if (class_exists(Permission_Service::class)) {
            $result = Permission_Service::check(
                $user_id,
                Permission_Service::ACTION_READ,
                Permission_Service::RESOURCE_PUBLICATION,
                $post->ID
            );

            if (!$result['allowed']) {
                Permission_Service::log_denial($user_id, 'read', 'publication', $post->ID, $result['reason']);
            }

            return $result['allowed'];
        }

        // Fallback: legacy logic
        $visibility = get_post_meta($post->ID, '_ml_visibility', true) ?: 'public';

        if ($visibility === 'public') {
            return true;
        }

        if ($post->post_author == $user_id) {
            return true;
        }

        if ($visibility === 'private') {
            return false;
        }

        // Check space membership for space-restricted content (Picasso: post_parent first)
        $space_id = $post->post_parent ?: get_post_meta($post->ID, '_ml_space_id', true);
        if ($space_id) {
            // TODO: Implement Marylink space membership check
            // For now, allow access if user is logged in
            return $user_id > 0;
        }

        return true;
    }

    private static function get_publication_comments(int $post_id): array {
        $comments = get_comments([
            'post_id' => $post_id,
            'status' => 'approve',
            'number' => 20,
            'orderby' => 'comment_date',
            'order' => 'ASC',
        ]);

        return array_map(function ($comment) {
            return [
                'id' => (int) $comment->comment_ID,
                'author_id' => (int) $comment->user_id,
                'author_name' => $comment->comment_author,
                'content' => $comment->comment_content,
                'date' => $comment->comment_date_gmt,
            ];
        }, $comments);
    }

    // =========================================================================
    // SPACE METHODS
    // =========================================================================

    private static function get_space(int $id): array {
        // Canonical: Space is a WP post of type 'space' (Picasso).
        $post = get_post($id);
        if ($post && $post->post_type === 'space') {
            return $this->format_space_post($post);
        }

        // Backward compatibility: some installs still treat "space" as a BuddyBoss group id.
        if (function_exists('groups_get_group')) {
            $group = groups_get_group($id);
            if ($group && $group->id) {
                $out = $this->format_group($group);
                $out['_kind'] = 'group';
                $out['_compat'] = 'legacy_space_as_group';
                return $out;
            }
        }

        throw new \Exception('Space not found');
    }

    private static function search_spaces(
        ?string $query,
        array $filters,
        int $limit,
        int $offset,
        int $user_id,
        array $include
    ): array {
        // Canonical spaces: CPT 'space'
        $args = [
            'post_type' => 'space',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'offset' => $offset,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        if ($query) {
            $args['s'] = $query;
        }

        // If Permission_Service exists, restrict to spaces the user can see
        if (class_exists(\MCP_No_Headless\MCP\Core\Services\Permission_Service::class) && $user_id) {
            $ids = \MCP_No_Headless\MCP\Core\Services\Permission_Service::get_user_space_ids($user_id);
            if (!empty($ids)) {
                $args['post__in'] = $ids;
            }
        }

        $q = new \WP_Query($args);
        $spaces = [];
        foreach ($q->posts as $post) {
            $spaces[] = self::format_space_post($post, $user_id, $include);
        }
        $total = (int) $q->found_posts;

        // Backward compatibility: BuddyBoss groups as spaces
        if (empty($spaces) && function_exists('groups_get_groups')) {
            $results = groups_get_groups([
                'per_page' => $limit,
                'search_terms' => $query ?: '',
                'show_hidden' => true,
            ]);
            foreach ($results['groups'] ?? [] as $group) {
                $out = self::format_space($group, $user_id, $include);
                $out['_kind'] = 'group';
                $out['_compat'] = 'legacy_space_as_group';
                $spaces[] = $out;
            }
            $total = count($spaces);
        }

        return ['items' => $spaces, 'total' => $total];
    }

    /**
     * Format a CPT space post
     */
    private static function format_space_post(\WP_Post $post, int $user_id, array $include): array {
        $data = [
            'id' => (int) $post->ID,
            'name' => $post->post_title,
            'slug' => $post->post_name,
            'description' => wp_trim_words($post->post_excerpt ?: $post->post_content, 30),
            'status' => $post->post_status,
            'created' => $post->post_date,
            'author_id' => (int) $post->post_author,
            '_kind' => 'space',
        ];

        // Check membership
        if (class_exists(\MCP_No_Headless\MCP\Core\Services\Permission_Service::class)) {
            $data['is_member'] = \MCP_No_Headless\MCP\Core\Services\Permission_Service::is_space_member($user_id, $post->ID);
        }

        if (in_array('content', $include)) {
            $data['full_description'] = $post->post_content;
        }

        if (in_array('metadata', $include)) {
            $data['metadata'] = [
                'author_id' => (int) $post->post_author,
                'modified' => $post->post_modified,
            ];
        }

        return $data;
    }

    private static function format_space($group, int $user_id, array $include): array {
        $data = [
            'id' => (int) $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'description' => wp_trim_words($group->description, 30),
            'status' => $group->status,
            'created' => $group->date_created,
            'member_count' => (int) $group->total_member_count,
            'is_member' => function_exists('groups_is_user_member') ? groups_is_user_member($user_id, $group->id) : false,
        ];

        if (in_array('content', $include)) {
            $data['full_description'] = $group->description;
        }

        if (in_array('metadata', $include)) {
            $data['metadata'] = [
                'creator_id' => (int) $group->creator_id,
                'last_activity' => $group->last_activity,
                'enable_forum' => (bool) $group->enable_forum,
            ];

            // Get space tools if available
            $space_tools = get_option("ml_space_{$group->id}_tools", []);
            if (!empty($space_tools)) {
                $data['metadata']['tools'] = $space_tools;
            }
        }

        return $data;
    }

    // =========================================================================
    // TOOL METHODS
    // =========================================================================

    /**
     * Get a single labeled item (tool, prompt, style, data) by ID
     */
    private static function get_labeled_item(string $type, int $id, int $user_id, array $include): ?array {
        $post = get_post($id);

        if (!$post || $post->post_type !== 'publication') {
            return null;
        }

        $label_slug = self::label_terms_for_type($type);

        // Verify item has the correct label
        if (!has_term($label_slug, 'publication_label', $post->ID)) {
            return null;
        }

                $data = self::format_labeled_item($type, $post, $include);

        if (in_array('metadata', $include, true)) {
            $qm = Publication_Schema::get_quality_metrics($post->ID);
            $data['metadata']['rating'] = $qm['rating'];
            $data['metadata']['favorites_count'] = $qm['favorites_count'];
            $data['metadata']['quality_score'] = $qm['quality_score'];
            $data['metadata']['engagement_score'] = $qm['engagement_score'];
        }

        if (in_array('reviews', $include, true) && in_array('metadata', $include, true)) {
            $data['metadata']['reviews_sample'] = self::get_reviews_sample((int) $post->ID, $user_id, 5);
        }

        return $data;
    }

    /**
     * Search publications by label type (tool, prompt, style, data)
     */
    private static function search_by_label(
        string $type,
        string $query,
        array $filters,
        int $limit,
        int $offset,
        int $user_id,
        array $include,
        ?string $sort
    ): array {
        $label_slug = self::label_terms_for_type($type);

        $args = [
    'post_type' => 'publication',
    'suppress_filters' => true,
    'post_status' => 'publish',
    'posts_per_page' => $limit,
    'offset' => $offset,
    'orderby' => 'title',
    'order' => 'ASC',
    'tax_query' => [
        [
            'taxonomy' => 'publication_label',
            'field' => 'slug',
            'terms' => $label_slug,
        ],
    ],
];

// If a ranking sort is requested, pull wider candidate set then sort in-memory
if (!empty($sort)) {
    $candidate_count = min(200, max(50, ($offset + $limit) * 5));
    $args['posts_per_page'] = $candidate_count;
    $args['offset'] = 0;
    $args['orderby'] = 'date';
    $args['order'] = 'DESC';
}

        if (!empty($query)) {
            $args['s'] = $query;
        }

        if (!empty($filters['category'])) {
            $args['meta_query'][] = [
                'key' => '_ml_tool_category',
                'value' => $filters['category'],
            ];
        }

        $wp_query = new \WP_Query($args);

$posts = $wp_query->posts;

if (!empty($sort)) {
    // Filter for top_rated (only rated items)
    if ($sort === 'top_rated') {
        $posts = Find_Ranking::filter_for_top_rated($posts);
    }
    $posts = Find_Ranking::sort_posts($posts, $sort);
}

if (!empty($sort) && $offset > 0) {
    $posts = array_slice($posts, $offset, $limit);
} elseif (!empty($sort)) {
    $posts = array_slice($posts, 0, $limit);
}

$items = [];
$include_reviews = in_array('reviews', $include, true);

foreach ($posts as $i => $post) {
    $item = self::format_labeled_item($type, $post, $include);

    if (in_array('metadata', $include, true)) {
        $qm = Publication_Schema::get_quality_metrics($post->ID);
        $item['metadata']['rating'] = $qm['rating'];
        $item['metadata']['favorites_count'] = $qm['favorites_count'];
        $item['metadata']['quality_score'] = $qm['quality_score'];
        $item['metadata']['engagement_score'] = $qm['engagement_score'];
    }

    if ($include_reviews && in_array('metadata', $include, true)) {
        if ($i < 5) {
            $item['metadata']['reviews_sample'] = self::get_reviews_sample((int) $post->ID, $user_id, 3);
        } else {
            $item['metadata']['reviews_sample'] = [];
        }
    }

        // PR4: Add ranking_reason if requested
    if (in_array('ranking_reason', $include, true)) {
        $item['ranking_reason'] = Find_Ranking::get_ranking_reason((int) $post->ID, $sort);
    }

    $items[] = $item;
}

return [
    'items' => $items,
    'total' => $wp_query->found_posts,
];

    }

    /**
     * Format a labeled item (tool, prompt, style, data)
     */
    private static function format_labeled_item(string $type, $post, array $include): array {
        $data = [
            'id' => $post->ID,
            'name' => $post->post_title,
            'slug' => $post->post_name,
            'excerpt' => wp_trim_words($post->post_content, 20),
            'label' => self::canonical_label($type),
            'author_id' => (int) $post->post_author,
            'author_name' => get_the_author_meta('display_name', $post->post_author),
            'date' => self::safe_datetime($post, 'post_date_gmt'),
        ];

        if (in_array('content', $include)) {
            $data['content'] = $post->post_content;
            // Tool-specific fields
            if ($type === self::TYPE_TOOL || $type === self::TYPE_PROMPT) {
                $data['prompt_text'] = get_post_meta($post->ID, '_ml_tool_prompt', true);
                $data['model'] = get_post_meta($post->ID, '_ml_tool_model', true) ?: 'gpt-4o-mini';
            }
            if ($type === self::TYPE_STYLE) {
                $data['style_text'] = get_post_meta($post->ID, '_ml_style_content', true) ?: $post->post_content;
            }
        }

        if (in_array('metadata', $include)) {
            $data['metadata'] = [
                'usage_count' => (int) get_post_meta($post->ID, '_ml_usage_count', true),
                'likes' => Meta_Keys::get_votes_count($post->ID),
                'favorites_count' => Meta_Keys::get_favorites_count($post->ID),
                'rating_average' => Meta_Keys::get_rating_avg($post->ID),
                'rating_count' => Meta_Keys::get_rating_count($post->ID),
                'space_id' => $post->post_parent ?: (int) get_post_meta($post->ID, '_ml_space_id', true),
                'visibility' => get_post_meta($post->ID, '_ml_visibility', true) ?: 'public',
            ];
        }

        return $data;
    }

    // =========================================================================
    // USER METHODS
    // =========================================================================

    private static function get_user(int $id, int $user_id, array $include): ?array {
        $user = get_userdata($id);
        if (!$user) {
            return null;
        }

        return self::format_user($user, $include);
    }

    private static function search_users(
        string $query,
        array $filters,
        int $limit,
        int $offset,
        int $user_id,
        array $include
    ): array {
        $args = [
            'number' => $limit,
            'offset' => $offset,
            'orderby' => 'display_name',
            'order' => 'ASC',
        ];

        if (!empty($query)) {
            $args['search'] = "*{$query}*";
            $args['search_columns'] = ['user_login', 'user_nicename', 'display_name', 'user_email'];
        }

        if (!empty($filters['role'])) {
            $args['role'] = $filters['role'];
        }

        $user_query = new \WP_User_Query($args);
        $items = [];

        foreach ($user_query->get_results() as $user) {
            $items[] = self::format_user($user, $include);
        }

        return [
            'items' => $items,
            'total' => $user_query->get_total(),
        ];
    }

    private static function format_user($user, array $include): array {
        $data = [
            'id' => (int) $user->ID,
            'username' => $user->user_login,
            'display_name' => $user->display_name,
            'avatar_url' => get_avatar_url($user->ID),
            'registered' => $user->user_registered,
        ];

        if (in_array('metadata', $include)) {
            $data['metadata'] = [
                'roles' => $user->roles,
                'post_count' => count_user_posts($user->ID),
            ];

            // BuddyPress profile fields
            if (function_exists('bp_get_profile_field_data')) {
                $data['metadata']['bio'] = bp_get_profile_field_data([
                    'field' => 'Bio',
                    'user_id' => $user->ID,
                ]);
            }
        }

        return $data;
    }

    // =========================================================================
    // GROUP METHODS
    // =========================================================================

    private static function get_group(int $id, int $user_id, array $include): ?array {
        // Groups are same as spaces in BuddyPress context
        return self::get_space($id, $user_id, $include);
    }

    private static function search_groups(
        string $query,
        array $filters,
        int $limit,
        int $offset,
        int $user_id,
        array $include
    ): array {
        return self::search_spaces($query, $filters, $limit, $offset, $user_id, $include);
    }

    // =========================================================================
    // ACTIVITY METHODS
    // =========================================================================

    private static function get_activity(int $id, int $user_id, array $include): ?array {
        if (!function_exists('bp_activity_get')) {
            return null;
        }

        $result = bp_activity_get(['in' => [$id], 'display_comments' => in_array('comments', $include)]);

        if (empty($result['activities'])) {
            return null;
        }

        return self::format_activity($result['activities'][0], $include);
    }

    private static function search_activities(
        string $query,
        ?int $space_id,
        array $filters,
        int $limit,
        int $offset,
        int $user_id,
        array $include
    ): array {
        if (!function_exists('bp_activity_get')) {
            return ['items' => [], 'total' => 0];
        }

        $args = [
            'per_page' => $limit,
            'page' => floor($offset / $limit) + 1,
            'display_comments' => in_array('comments', $include),
        ];

        if (!empty($query)) {
            $args['search_terms'] = $query;
        }

        if ($space_id) {
            $args['filter'] = [
                'object' => 'groups',
                'primary_id' => $space_id,
            ];
        }

        if (!empty($filters['user_id'])) {
            $args['filter']['user_id'] = (int) $filters['user_id'];
        }

        if (!empty($filters['type'])) {
            $args['filter']['action'] = $filters['type'];
        }

        $result = bp_activity_get($args);
        $items = [];

        foreach ($result['activities'] as $activity) {
            $items[] = self::format_activity($activity, $include);
        }

        return [
            'items' => $items,
            'total' => $result['total'],
        ];
    }

    private static function format_activity($activity, array $include): array {
        $data = [
            'id' => (int) $activity->id,
            'user_id' => (int) $activity->user_id,
            'user_name' => bp_core_get_user_displayname($activity->user_id),
            'type' => $activity->type,
            'action' => strip_tags($activity->action),
            'date' => $activity->date_recorded,
            'item_id' => (int) $activity->item_id,
            'secondary_item_id' => (int) $activity->secondary_item_id,
        ];

        if (in_array('content', $include)) {
            $data['content'] = $activity->content;
        }

        if (in_array('metadata', $include)) {
            $data['metadata'] = [
                'component' => $activity->component,
                'is_spam' => (bool) $activity->is_spam,
                'favorite_count' => function_exists('bp_activity_get_meta')
                    ? (int) bp_activity_get_meta($activity->id, 'favorite_count', true)
                    : 0,
            ];
        }

        if (in_array('comments', $include) && !empty($activity->children)) {
            $data['comments'] = array_map(function ($child) {
                return [
                    'id' => (int) $child->id,
                    'user_id' => (int) $child->user_id,
                    'content' => $child->content,
                    'date' => $child->date_recorded,
                ];
            }, $activity->children);
        }

        return $data;
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Calculate relevance score for sorting
     */
    private static function relevance_score(array $item, string $query): float {
        $score = 0;
        $query_lower = strtolower($query);

        // Title/name match
        $title = strtolower($item['title'] ?? $item['name'] ?? '');
        if (str_contains($title, $query_lower)) {
            $score += 10;
            if ($title === $query_lower) {
                $score += 5;
            }
        }

        // Recent items get bonus
        $date = $item['date'] ?? $item['created'] ?? null;
        if ($date) {
            $age_days = (time() - strtotime($date)) / 86400;
            if ($age_days < 7) {
                $score += 3;
            } elseif ($age_days < 30) {
                $score += 1;
            }
        }

        // Type priority
        $type_scores = [
            'publication' => 2,
            'tool' => 1.5,
            'space' => 1,
            'user' => 0.5,
            'activity' => 0.3,
        ];
        $score += $type_scores[$item['_type'] ?? 'any'] ?? 0;

        return $score;
    }

    /**
     * Get a sample of reviews for a publication
     * PR5: include=reviews
     * 
     * @param int $post_id Publication ID
     * @param int $user_id Current user ID (for filtering)
     * @param int $limit Max reviews to return
     * @return array Array of review objects
     */
    private static function get_reviews_sample(int $post_id, int $user_id, int $limit = 3): array {
        global $wpdb;
        
        // === 1. Try Picasso CPT publication_review first ===
        $picasso_reviews = get_posts([
            'post_type' => 'publication_review',
            'post_parent' => $post_id,
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => 'publish',
        ]);

        if (!empty($picasso_reviews)) {
            return array_map(function($review) {
                $rating_data = get_post_meta($review->ID, '_rating', true);
                $avg_rating = 0;
                if (is_array($rating_data) && !empty($rating_data)) {
                    $avg_rating = round(array_sum($rating_data) / count($rating_data), 1);
                }

                $author = get_user_by('ID', $review->post_author);
                $review_type = get_post_meta($review->ID, '_review_type', true) ?: 'user';

                return [
                    'rating' => $avg_rating,
                    'comment' => mb_substr($review->post_content, 0, 300),
                    'created_at' => $review->post_date,
                    'user_name' => $author ? $author->display_name : 'Anonymous',
                    'review_type' => $review_type,
                ];
            }, $picasso_reviews);
        }

        // === 2. Fallback: MCP ml_ratings table ===
        if (!class_exists(\MCP_No_Headless\Services\Rating_Service::class)) {
            return [];
        }

        $table = $wpdb->prefix . 'ml_ratings';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }
        
        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT r.rating, r.comment, r.created_at, r.user_id, u.display_name as user_name
             FROM {$table} r
             LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
             WHERE r.post_id = %d AND r.comment IS NOT NULL AND r.comment != ''
             ORDER BY r.created_at DESC
             LIMIT %d",
            $post_id,
            $limit
        ), ARRAY_A);
        
        if (empty($reviews)) {
            return [];
        }
        
        return array_map(function($r) {
            return [
                'rating' => (int) $r['rating'],
                'comment' => mb_substr($r['comment'] ?? '', 0, 200),
                'created_at' => $r['created_at'],
                'user_name' => $r['user_name'] ?? 'Anonymous',
            ];
        }, $reviews);
    }

}