<?php
/**
 * Publication Schema - Unified schema adapter for Wizard/Picasso compatibility
 *
 * Handles the translation between Wizard conventions (_ml_*) and Picasso conventions
 * (post_parent, _publication_step, taxonomies).
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Schema;

use MCP_No_Headless\Picasso\Meta_Keys;

class Publication_Schema {

    // Meta keys - Picasso (canonical target)
    public const META_STEP_PICASSO = '_publication_step';

    // Meta keys - Legacy Wizard
    public const META_STEP_LEGACY = '_ml_step';
    public const META_SPACE_LEGACY = '_ml_space_id';
    public const META_SPACE_ALT = 'space_id';
    public const META_TYPE_LEGACY = '_ml_publication_type';

    // Taxonomies
    public const TAX_STEP = 'publication_step';
    public const TAX_LABEL = 'publication_label';
    public const TAX_TAG = 'publication_tag';

    // Schema modes
    public const MODE_LEGACY = 'legacy';  // Read/write _ml_* only
    public const MODE_DUAL = 'dual';      // Read both, write both
    public const MODE_STRICT = 'strict';  // Read Picasso priority, write Picasso only

    /**
     * Get current schema mode
     */
    public static function get_mode(): string {
        return get_option('ml_schema_mode', self::MODE_DUAL);
    }

    /**
     * Set schema mode
     */
    public static function set_mode(string $mode): bool {
        if (!in_array($mode, [self::MODE_LEGACY, self::MODE_DUAL, self::MODE_STRICT], true)) {
            return false;
        }
        return update_option('ml_schema_mode', $mode);
    }

    /**
     * Get space ID for a publication (unified)
     *
     * Priority:
     * 1. post_parent (Picasso canonical)
     * 2. _ml_space_id (Wizard legacy)
     * 3. space_id (alt legacy)
     *
     * @param int $publication_id Publication ID
     * @return int|null Space ID or null
     */
    public static function get_space_id(int $publication_id): ?int {
        // Priority 1: post_parent (Picasso canonical)
        $post = get_post($publication_id);
        if ($post && $post->post_parent > 0) {
            // Verify parent is a space
            $parent = get_post($post->post_parent);
            if ($parent && $parent->post_type === 'space') {
                return (int) $post->post_parent;
            }
        }

        // Priority 2: _ml_space_id meta (Wizard legacy)
        $space_id = get_post_meta($publication_id, self::META_SPACE_LEGACY, true);
        if ($space_id && is_numeric($space_id)) {
            return (int) $space_id;
        }

        // Priority 3: space_id meta (alt)
        $space_id = get_post_meta($publication_id, self::META_SPACE_ALT, true);
        if ($space_id && is_numeric($space_id)) {
            return (int) $space_id;
        }

        return null;
    }

    /**
     * Get step for a publication (unified)
     *
     * Priority:
     * 1. _publication_step meta (Picasso canonical)
     * 2. _ml_step meta (Wizard legacy)
     * 3. publication_step taxonomy
     *
     * @param int $publication_id Publication ID
     * @return string|null Step name or null
     */
    public static function get_step(int $publication_id): ?string {
        // Priority 1: _publication_step (Picasso canonical)
        $step = get_post_meta($publication_id, self::META_STEP_PICASSO, true);
        if (!empty($step)) {
            return $step;
        }

        // Priority 2: _ml_step (Wizard legacy)
        $step = get_post_meta($publication_id, self::META_STEP_LEGACY, true);
        if (!empty($step)) {
            return $step;
        }

        // Priority 3: taxonomy
        $terms = wp_get_post_terms($publication_id, self::TAX_STEP, ['fields' => 'names']);
        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0];
        }

        return null;
    }

    /**
     * Get primary type for a publication (unified)
     *
     * Priority:
     * 1. publication_label taxonomy
     * 2. _ml_publication_type meta
     *
     * @param int $publication_id Publication ID
     * @return string|null Type (prompt, tool, style, data, etc.) or null
     */
    public static function get_type(int $publication_id): ?string {
        // Priority 1: taxonomy publication_label
        $terms = wp_get_post_terms($publication_id, self::TAX_LABEL, ['fields' => 'slugs']);
        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0];
        }

        // Priority 2: meta _ml_publication_type
        $type = get_post_meta($publication_id, self::META_TYPE_LEGACY, true);
        if (!empty($type)) {
            return $type;
        }

        return null;
    }

    /**
     * Get all types for a publication (unified, deduplicated)
     *
     * @param int $publication_id Publication ID
     * @return array Types array
     */
    public static function get_types(int $publication_id): array {
        $types = [];

        // From taxonomy
        $terms = wp_get_post_terms($publication_id, self::TAX_LABEL, ['fields' => 'slugs']);
        if (!empty($terms) && !is_wp_error($terms)) {
            $types = array_merge($types, $terms);
        }

        // From meta
        $meta_type = get_post_meta($publication_id, self::META_TYPE_LEGACY, true);
        if (!empty($meta_type)) {
            $types[] = $meta_type;
        }

        return array_unique($types);
    }

    /**
     * Build meta_query for step filtering (dual-read)
     *
     * Generates a WP_Query meta_query that matches EITHER:
     * - _publication_step (Picasso)
     * - _ml_step (Wizard legacy)
     *
     * @param array $approved_steps Array of step names
     * @return array WP_Query meta_query array
     */
    public static function build_step_meta_query(array $approved_steps): array {
        if (empty($approved_steps)) {
            return [];
        }

        // Optimize for single step
        $compare = count($approved_steps) === 1 ? '=' : 'IN';
        $value = count($approved_steps) === 1 ? $approved_steps[0] : $approved_steps;


        $query = [
            'relation' => 'OR',
            [
                'key' => self::META_STEP_PICASSO,
                'value' => $value,
                'compare' => $compare,
            ],
            [
                'key' => self::META_STEP_LEGACY,
                'value' => $value,
                'compare' => $compare,
            ],
        ];

        // If "published" is approved, also include publications WITHOUT step meta
        // (business logic: no step = published by default)
        if (in_array('published', $approved_steps, true)) {
            // NOT EXISTS for both step metas
            $query[] = [
                'relation' => 'AND',
                [
                    'key' => self::META_STEP_PICASSO,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => self::META_STEP_LEGACY,
                    'compare' => 'NOT EXISTS',
                ],
            ];
            // Also match empty string values
            $query[] = [
                'key' => self::META_STEP_PICASSO,
                'value' => '',
                'compare' => '=',
            ];
        }

        return $query;
    }

    /**
     * Build query for type filtering (taxonomy OR meta)
     *
     * @param array $types Types to filter (prompt, tool, style, etc.)
     * @return array Query arguments to merge into WP_Query
     */
    public static function build_type_query(array $types): array {
        if (empty($types)) {
            return [];
        }

        // For WP_Query, we need both tax_query and meta_query with OR
        // This is complex, so we return separate arrays
        return [
            'tax_query' => [
                [
                    'taxonomy' => self::TAX_LABEL,
                    'field' => 'slug',
                    'terms' => $types,
                ],
            ],
            'meta_query_type' => [
                [
                    'key' => self::META_TYPE_LEGACY,
                    'value' => $types,
                    'compare' => 'IN',
                ],
            ],
        ];
    }

    /**
     * Build combined type filter for WP_Query
     *
     * Returns a meta_query that works with OR against taxonomy
     * Note: For best results, run two queries and merge, or use custom SQL
     *
     * @param array $types Types to filter
     * @return array meta_query compatible array
     */
    public static function build_type_meta_query(array $types): array {
        if (empty($types)) {
            return [];
        }

        return [
            [
                'key' => self::META_TYPE_LEGACY,
                'value' => count($types) === 1 ? $types[0] : $types,
                'compare' => count($types) === 1 ? '=' : 'IN',
            ],
        ];
    }

    /**
     * Write space ID (respects current mode)
     *
     * @param int $publication_id Publication ID
     * @param int $space_id Space ID
     * @return bool Success
     */
    public static function set_space_id(int $publication_id, int $space_id): bool {
        $mode = self::get_mode();

        // In strict mode, only set post_parent
        if ($mode === self::MODE_STRICT) {
            return wp_update_post([
                'ID' => $publication_id,
                'post_parent' => $space_id,
            ]) !== 0;
        }

        // In dual mode, set both
        $result = wp_update_post([
            'ID' => $publication_id,
            'post_parent' => $space_id,
        ]);

        if ($result) {
            update_post_meta($publication_id, self::META_SPACE_LEGACY, $space_id);
        }

        return $result !== 0;
    }

    /**
     * Write step (respects current mode)
     *
     * @param int $publication_id Publication ID
     * @param string $step Step name
     * @return bool Success
     */
    public static function set_step(int $publication_id, string $step): bool {
        $mode = self::get_mode();

        // In strict mode, only set _publication_step
        if ($mode === self::MODE_STRICT) {
            return update_post_meta($publication_id, self::META_STEP_PICASSO, $step) !== false;
        }

        // In dual mode, set both
        $result1 = update_post_meta($publication_id, self::META_STEP_PICASSO, $step);
        $result2 = update_post_meta($publication_id, self::META_STEP_LEGACY, $step);

        return $result1 !== false || $result2 !== false;
    }

    /**
     * Get diagnostic info for a publication
     *
     * @param int $publication_id Publication ID
     * @return array Diagnostic data
     */
    public static function diagnose(int $publication_id): array {
        $post = get_post($publication_id);
        if (!$post) {
            return ['error' => 'Post not found'];
        }

        return [
            'publication_id' => $publication_id,
            'post_type' => $post->post_type,
            'post_parent' => $post->post_parent,
            'space_id' => [
                'resolved' => self::get_space_id($publication_id),
                'post_parent' => $post->post_parent,
                '_ml_space_id' => get_post_meta($publication_id, self::META_SPACE_LEGACY, true),
                'space_id_meta' => get_post_meta($publication_id, self::META_SPACE_ALT, true),
            ],
            'step' => [
                'resolved' => self::get_step($publication_id),
                '_publication_step' => get_post_meta($publication_id, self::META_STEP_PICASSO, true),
                '_ml_step' => get_post_meta($publication_id, self::META_STEP_LEGACY, true),
                'taxonomy' => wp_get_post_terms($publication_id, self::TAX_STEP, ['fields' => 'names']),
            ],
            'type' => [
                'resolved' => self::get_type($publication_id),
                'all' => self::get_types($publication_id),
                'taxonomy' => wp_get_post_terms($publication_id, self::TAX_LABEL, ['fields' => 'slugs']),
                '_ml_publication_type' => get_post_meta($publication_id, self::META_TYPE_LEGACY, true),
            ],
            'mode' => self::get_mode(),
        ];
    }


/**
 * Get unified quality metrics for a publication (single source of truth).
 *
 * Uses existing canonical meta fields already written by MaryLink/Picasso:
 * - _ml_average_rating (float)
 * - _ml_rating_count (int)
 * - _ml_rating_distribution (json: {1: n, 2: n, 3: n, 4: n, 5: n})
 * - _ml_favorites_count (int)
 * - _ml_quality_score (float|null)
 * - _ml_engagement_score (int|null)
 *
 * @param int $publication_id Publication ID
 * @return array{
 *   rating: array{average: float, count: int, distribution?: array<int,int>},
 *   favorites_count: int,
 *   quality_score: float|null,
 *   engagement_score: int|null
 * }
 */
public static function get_quality_metrics(int $publication_id): array {
    $avg = Meta_Keys::get_rating_avg($publication_id);
    $count = Meta_Keys::get_rating_count($publication_id);
    $favorites_count = Meta_Keys::get_favorites_count($publication_id);

    $dist = get_post_meta($publication_id, '_ml_rating_distribution', true);
    $distribution = null;
    if (!empty($dist)) {
        if (is_string($dist)) {
            $decoded = json_decode($dist, true);
            if (is_array($decoded)) {
                $distribution = [];
                foreach ([1,2,3,4,5] as $k) {
                    $distribution[$k] = (int) ($decoded[(string)$k] ?? $decoded[$k] ?? 0);
                }
            }
        } elseif (is_array($dist)) {
            $distribution = [];
            foreach ([1,2,3,4,5] as $k) {
                $distribution[$k] = (int) ($dist[(string)$k] ?? $dist[$k] ?? 0);
            }
        }
    }

    $quality_score = Meta_Keys::get_quality_score($publication_id);
    $engagement_score = Meta_Keys::get_engagement_score($publication_id);

    $out = [
        'rating' => [
            'average' => $avg !== null ? round($avg, 2) : null,
            'count' => $count,
        ],
        'favorites_count' => $favorites_count,
        'quality_score' => $quality_score,
        'engagement_score' => $engagement_score,
    ];

    if (is_array($distribution)) {
        $out['rating']['distribution'] = $distribution;
    }

    return $out;
}
}
