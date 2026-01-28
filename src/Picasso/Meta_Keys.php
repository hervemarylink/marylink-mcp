<?php
namespace MCP_No_Headless\Picasso;

/**
 * Centralized Picasso meta key mapping - Single source of truth
 *
 * Based on actual JAN26 database analysis:
 * - _average_user_reviews: 221 entries
 * - _most_reviews: 221 entries
 * - _most_votes: 221 entries
 * - _publication_favorites: 15 entries
 * - _publication_view: 371 entries
 * - _ml_quality_score: 156 entries (MCP computed)
 * - _ml_engagement_score: 156 entries (MCP computed)
 */
class Meta_Keys {

    // === PICASSO RATINGS ===
    const RATING_USER_AVG = '_average_user_reviews';
    const RATING_EXPERT_AVG = '_average_expert_reviews';
    const RATING_COUNT = '_most_reviews';

    // === PICASSO ENGAGEMENT ===
    const VOTES = '_most_votes';
    const FAVORITES = '_publication_favorites';
    const VIEWS = '_publication_view';
    const SEEN_BY = '_publication_seen_by';
    const MEMBERS = '_most_members';

    // === PICASSO WORKFLOW ===
    const STEP = '_publication_step';
    const STEP_NAME = '_publication_step_name';
    const CO_AUTHORS = '_publication_co_authors';
    const EXPERT = '_publication_expert';

    // === MCP-ONLY (computed, 156 entries exist) ===
    const QUALITY_SCORE = '_ml_quality_score';
    const ENGAGEMENT_SCORE = '_ml_engagement_score';

    // === HELPER METHODS ===

    /**
     * Get average USER rating (0-5)
     */
    public static function get_rating_avg(int $id): ?float {
        $v = get_post_meta($id, self::RATING_USER_AVG, true);
        return ($v !== '' && $v !== null) ? round((float)$v, 2) : null;
    }

    /**
     * Get average EXPERT rating (0-5)
     */
    public static function get_expert_rating_avg(int $id): ?float {
        $v = get_post_meta($id, self::RATING_EXPERT_AVG, true);
        return ($v !== '' && $v !== null) ? round((float)$v, 2) : null;
    }

    /**
     * Get total reviews count
     */
    public static function get_rating_count(int $id): int {
        return (int) get_post_meta($id, self::RATING_COUNT, true);
    }

    /**
     * Get favorites count (Picasso stores as multiple meta entries)
     */
    public static function get_favorites_count(int $id): int {
        $favs = get_post_meta($id, self::FAVORITES, false);
        return is_array($favs) ? count($favs) : 0;
    }

    /**
     * Get votes/likes count
     */
    public static function get_votes_count(int $id): int {
        return (int) get_post_meta($id, self::VOTES, true);
    }

    /**
     * Get views count
     */
    public static function get_views_count(int $id): int {
        // Picasso stores one meta entry per unique viewer (user_id)
        $views = get_post_meta($id, self::VIEWS, false);
        return is_array($views) ? count($views) : 0;
    }

    /**
     * Get members count
     */
    public static function get_members_count(int $id): int {
        return (int) get_post_meta($id, self::MEMBERS, true);
    }

    /**
     * Get quality score (MCP computed)
     */
    public static function get_quality_score(int $id): ?float {
        $v = get_post_meta($id, self::QUALITY_SCORE, true);
        return ($v !== '' && $v !== null) ? (float)$v : null;
    }

    /**
     * Get engagement score (MCP computed)
     */
    public static function get_engagement_score(int $id): ?int {
        $v = get_post_meta($id, self::ENGAGEMENT_SCORE, true);
        return ($v !== '' && $v !== null) ? (int)$v : null;
    }

    /**
     * Get workflow step
     */
    public static function get_step(int $id): ?string {
        $v = get_post_meta($id, self::STEP, true);
        return ($v !== '' && $v !== null) ? (string)$v : null;
    }
}
