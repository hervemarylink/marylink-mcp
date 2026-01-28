<?php
/**
 * Rating Service - Business logic for publication ratings
 *
 * Handles:
 * - Getting rating statistics for publications
 * - Rating distribution
 * - Creating reviews with criteria scores (T1.1)
 * - Aggregated stats (T1.3)
 *
 * TICKET T1.1: ml_rate_publication support
 * TICKET T1.3: ml_get_ratings_summary support
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\MCP\Permission_Checker;

class Rating_Service {

    /**
     * Ratings table name
     */
    private const TABLE_NAME = 'ml_ratings';

    private int $user_id;
    private Permission_Checker $permissions;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
    }

    // =========================================
    // Static methods for tool integration
    // =========================================

    /**
     * Get user's existing rating for a publication (static)
     *
     * @param int $publication_id Publication ID
     * @param int $user_id User ID
     * @return array|null Rating data or null
     */
    public static function get_user_rating(int $publication_id, int $user_id): ?array {
        if ($user_id <= 0) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Check custom table first
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE publication_id = %d AND user_id = %d ORDER BY created_at DESC LIMIT 1",
                $publication_id,
                $user_id
            ), ARRAY_A);

            if ($row) {
                return [
                    'id' => (int) $row['id'],
                    'rating' => (int) $row['rating'],
                    'review_type' => $row['review_type'] ?? 'user',
                    'criteria_scores' => !empty($row['criteria_scores']) ? json_decode($row['criteria_scores'], true) : [],
                    'comment' => $row['comment'] ?? '',
                    'created_at' => $row['created_at'],
                ];
            }
        }

        // Fallback: check comments with rating meta
        $args = [
            'post_id' => $publication_id,
            'user_id' => $user_id,
            'meta_key' => '_ml_rating',
            'number' => 1,
            'orderby' => 'comment_date',
            'order' => 'DESC',
        ];

        $comments = get_comments($args);
        if (!empty($comments)) {
            $comment = $comments[0];
            $rating = (int) get_comment_meta($comment->comment_ID, '_ml_rating', true);
            $criteria = get_comment_meta($comment->comment_ID, '_ml_criteria_scores', true);

            return [
                'id' => (int) $comment->comment_ID,
                'rating' => $rating,
                'review_type' => get_comment_meta($comment->comment_ID, '_ml_review_type', true) ?: 'user',
                'criteria_scores' => is_array($criteria) ? $criteria : [],
                'comment' => $comment->comment_content,
                'created_at' => $comment->comment_date,
            ];
        }

        return null;
    }

    /**
     * Create or update a review with criteria scores (T1.1)
     *
     * @param int $publication_id Publication ID
     * @param int $user_id User ID
     * @param int $overall_rating Overall rating 1-5
     * @param array $criteria_scores Scores by criteria
     * @param string $review_type 'user' or 'expert'
     * @param string $comment Optional comment
     * @return array Result with success, review_id, is_update
     */
    public static function create_review_with_criteria(
        int $publication_id,
        int $user_id,
        int $overall_rating,
        array $criteria_scores,
        string $review_type = 'user',
        string $comment = ''
    ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Validate rating
        if ($overall_rating < 1 || $overall_rating > 5) {
            return ['success' => false, 'message' => 'Rating must be between 1 and 5'];
        }

        // Check if table exists, create if not
        self::ensure_table_exists();

        // Check for existing rating
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE publication_id = %d AND user_id = %d",
            $publication_id,
            $user_id
        ));

        $data = [
            'publication_id' => $publication_id,
            'user_id' => $user_id,
            'rating' => $overall_rating,
            'review_type' => $review_type,
            'criteria_scores' => json_encode($criteria_scores),
            'comment' => $comment,
            'updated_at' => current_time('mysql'),
        ];

        if ($existing) {
            // Update existing
            $wpdb->update($table, $data, ['id' => $existing->id]);
            $review_id = (int) $existing->id;
            $is_update = true;
        } else {
            // Insert new
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
            $review_id = (int) $wpdb->insert_id;
            $is_update = false;
        }

        if (!$review_id) {
            return ['success' => false, 'message' => 'Failed to save rating'];
        }

        // Update publication stats
        self::update_publication_stats($publication_id);

        return [
            'success' => true,
            'review_id' => $review_id,
            'is_update' => $is_update,
        ];
    }

    /**
     * Get average rating for a publication (static)
     */
    public static function get_average_rating(int $publication_id): float {
        $cached = get_post_meta($publication_id, '_ml_average_rating', true);
        if ($cached !== '') {
            return (float) $cached;
        }

        // Calculate from table
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $avg = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(rating) FROM $table WHERE publication_id = %d",
                $publication_id
            ));
            return $avg ? round((float) $avg, 2) : 0.0;
        }

        return 0.0;
    }

    /**
     * Get rating count for a publication (static)
     */
    public static function get_rating_count(int $publication_id): int {
        $cached = get_post_meta($publication_id, '_ml_rating_count', true);
        if ($cached !== '') {
            return (int) $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE publication_id = %d",
                $publication_id
            ));
        }

        return 0;
    }

    /**
     * Get rating distribution for a publication (static)
     */
    public static function get_rating_distribution(int $publication_id): array {
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT rating, COUNT(*) as count FROM $table WHERE publication_id = %d GROUP BY rating",
                $publication_id
            ));

            foreach ($results as $row) {
                $rating = (int) $row->rating;
                if ($rating >= 1 && $rating <= 5) {
                    $distribution[$rating] = (int) $row->count;
                }
            }
        }

        return $distribution;
    }

    /**
     * Get stats by review type (user/expert)
     */
    public static function get_stats_by_type(int $publication_id, string $type): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return ['average' => 0, 'count' => 0];
        }

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT AVG(rating) as average, COUNT(*) as count
             FROM $table
             WHERE publication_id = %d AND review_type = %s",
            $publication_id,
            $type
        ));

        return [
            'average' => $result ? (float) $result->average : 0,
            'count' => $result ? (int) $result->count : 0,
        ];
    }

    /**
     * Get criteria averages for a publication
     */
    public static function get_criteria_averages(int $publication_id): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT criteria_scores FROM $table WHERE publication_id = %d AND criteria_scores IS NOT NULL",
            $publication_id
        ));

        if (empty($rows)) {
            return [];
        }

        $totals = [];
        $counts = [];

        foreach ($rows as $row) {
            $scores = json_decode($row->criteria_scores, true);
            if (!is_array($scores)) continue;

            foreach ($scores as $key => $score) {
                if (!isset($totals[$key])) {
                    $totals[$key] = 0;
                    $counts[$key] = 0;
                }
                $totals[$key] += (int) $score;
                $counts[$key]++;
            }
        }

        $averages = [];
        foreach ($totals as $key => $total) {
            if ($counts[$key] > 0) {
                $averages[$key] = round($total / $counts[$key], 2);
            }
        }

        return $averages;
    }

    /**
     * Get reviews for a publication
     */
    public static function get_reviews(int $publication_id, int $limit = 10): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name as user_name
             FROM $table r
             LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
             WHERE r.publication_id = %d
             ORDER BY r.created_at DESC
             LIMIT %d",
            $publication_id,
            $limit
        ), ARRAY_A);

        return array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'user_name' => $row['user_name'] ?? 'Anonymous',
                'rating' => (int) $row['rating'],
                'review_type' => $row['review_type'] ?? 'user',
                'criteria_scores' => !empty($row['criteria_scores']) ? json_decode($row['criteria_scores'], true) : [],
                'comment' => $row['comment'] ?? '',
                'created_at' => $row['created_at'],
            ];
        }, $rows ?: []);
    }

    /**
     * Update publication stats after rating change
     */
    private static function update_publication_stats(int $publication_id): void {
        $average = self::get_average_rating($publication_id);
        $count = self::get_rating_count($publication_id);

update_post_meta($publication_id, '_ml_average_rating', $average);
update_post_meta($publication_id, '_ml_rating_count', $count);
update_post_meta($publication_id, '_ml_rating_distribution', self::get_rating_distribution($publication_id));

// Keep scoring meta keys in sync (used by Scoring_Service / best-of tools)
update_post_meta($publication_id, '_ml_avg_user_rating', $average);
update_post_meta($publication_id, '_ml_user_rating_count', $count);

// Recompute quality/trending score (non-blocking)
try {
    $scoring = new Scoring_Service();
    $scoring->calculate_quality_score($publication_id, true);
} catch (\Throwable $e) {
    // Non-blocking
}
    }

    /**
     * Ensure ratings table exists
     */
    private static function ensure_table_exists(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            publication_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            rating TINYINT(1) NOT NULL,
            review_type VARCHAR(20) DEFAULT 'user',
            criteria_scores TEXT,
            comment TEXT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME,
            PRIMARY KEY (id),
            UNIQUE KEY user_publication (user_id, publication_id),
            KEY publication_id (publication_id),
            KEY review_type (review_type)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // =========================================
    // Instance methods (legacy support)
    // =========================================

    /**
     * Get ratings for a publication
     *
     * @param int $publication_id Publication ID
     * @return array|null Ratings data or null if not accessible
     */
    public function get_ratings(int $publication_id): ?array {
        if (!$this->permissions->can_view_ratings($publication_id)) {
            return null;
        }

        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            return null;
        }

        $average = (float) get_post_meta($publication_id, '_ml_average_rating', true);
        $count = (int) get_post_meta($publication_id, '_ml_rating_count', true);
        $distribution = get_post_meta($publication_id, '_ml_rating_distribution', true);

        if (!is_array($distribution)) {
            $distribution = $this->calculate_distribution($publication_id);
        }

        // Get user's own rating if exists
        $my_rating = $this->get_current_user_rating($publication_id);

        return [
            'publication_id' => $publication_id,
            'average' => round($average, 2),
            'count' => $count,
            'distribution' => $distribution,
            'my_rating' => $my_rating,
        ];
    }

    /**
     * Calculate rating distribution from individual ratings
     */
    private function calculate_distribution(int $publication_id): array {
        global $wpdb;

        $distribution = [
            '5' => 0,
            '4' => 0,
            '3' => 0,
            '2' => 0,
            '1' => 0,
        ];

        // Check for ratings in comments
        $ratings = $wpdb->get_results($wpdb->prepare(
            "SELECT cm.meta_value as rating, COUNT(*) as count
             FROM {$wpdb->commentmeta} cm
             INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
             WHERE c.comment_post_ID = %d
             AND cm.meta_key = '_ml_rating'
             AND c.comment_approved = '1'
             GROUP BY cm.meta_value",
            $publication_id
        ));

        foreach ($ratings as $row) {
            $rating = (int) $row->rating;
            if ($rating >= 1 && $rating <= 5) {
                $distribution[(string) $rating] = (int) $row->count;
            }
        }

        // Also check for ratings in custom table if exists
        $table = $wpdb->prefix . 'ml_ratings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $custom_ratings = $wpdb->get_results($wpdb->prepare(
                "SELECT rating, COUNT(*) as count
                 FROM $table
                 WHERE publication_id = %d
                 GROUP BY rating",
                $publication_id
            ));

            foreach ($custom_ratings as $row) {
                $rating = (int) $row->rating;
                if ($rating >= 1 && $rating <= 5) {
                    $distribution[(string) $rating] += (int) $row->count;
                }
            }
        }

        return $distribution;
    }

    /**
     * Get current user's rating for a publication (instance method)
     */
    private function get_current_user_rating(int $publication_id): ?int {
        if ($this->user_id <= 0) {
            return null;
        }

        // Check comments first
        $args = [
            'post_id' => $publication_id,
            'user_id' => $this->user_id,
            'meta_key' => '_ml_rating',
            'number' => 1,
        ];

        $comments = get_comments($args);
        if (!empty($comments)) {
            $rating = get_comment_meta($comments[0]->comment_ID, '_ml_rating', true);
            if ($rating) {
                return (int) $rating;
            }
        }

        // Check custom table
        global $wpdb;
        $table = $wpdb->prefix . 'ml_ratings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $rating = $wpdb->get_var($wpdb->prepare(
                "SELECT rating FROM $table WHERE publication_id = %d AND user_id = %d",
                $publication_id,
                $this->user_id
            ));
            if ($rating) {
                return (int) $rating;
            }
        }

        return null;
    }

    /**
     * Get ratings by criteria (for expert vs user ratings)
     *
     * @param int $publication_id Publication ID
     * @param array $criteria Criteria names to get
     * @return array|null Criteria ratings or null if not accessible
     */
    public function get_criteria_ratings(int $publication_id, array $criteria = []): ?array {
        if (!$this->permissions->can_view_ratings($publication_id)) {
            return null;
        }

        $criteria_ratings = get_post_meta($publication_id, '_ml_criteria_ratings', true);

        if (!is_array($criteria_ratings)) {
            return [];
        }

        // Filter to requested criteria if specified
        if (!empty($criteria)) {
            $criteria_ratings = array_filter(
                $criteria_ratings,
                fn($key) => in_array($key, $criteria, true),
                ARRAY_FILTER_USE_KEY
            );
        }

        return $criteria_ratings;
    }

    /**
     * Check if ratings feature is available
     */
    public static function is_available(): bool {
        return post_type_exists('publication');
    }
}
