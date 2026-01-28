<?php
/**
 * Feedback Service - Collect user feedback on tool usage
 *
 * This is the "moat data" system - collecting 👍/👎 feedback after
 * tool execution to improve recommendations over time.
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

class Feedback_Service {

    private const TABLE_NAME = 'ml_feedback';

    /**
     * Record feedback
     *
     * @param string $run_id The execution run_id
     * @param int $user_id User who gave feedback
     * @param int $tool_id The tool/prompt publication ID
     * @param string $thumbs 'up' or 'down'
     * @param string|null $comment Optional feedback comment
     * @param string|null $context_hash Hash of input context (for deduplication)
     * @return bool Success
     */
    public static function record(
        string $run_id,
        int $user_id,
        int $tool_id,
        string $thumbs,
        ?string $comment = null,
        ?string $context_hash = null
    ): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Validate thumbs
        if (!in_array($thumbs, ['up', 'down'], true)) {
            return false;
        }

        $result = $wpdb->insert($table, [
            'run_id' => $run_id,
            'user_id' => $user_id,
            'tool_id' => $tool_id,
            'thumbs' => $thumbs,
            'comment' => $comment,
            'context_hash' => $context_hash,
            'created_at' => current_time('mysql', true),
        ]) !== false;

        // Emit metrics (v2.2.0+)
        if ($result) {
            do_action('ml_metrics', 'feedback_recorded', [
                'user_id' => $user_id,
                'tool_id' => $tool_id,
                'thumbs' => $thumbs,
                'has_comment' => !empty($comment),
                'run_id' => $run_id,
            ]);

            // Update aggregated feedback meta for faster ranking
            self::update_aggregated_meta($tool_id, $thumbs);
        }

        return $result;
    }

    /**
     * Update aggregated feedback meta on publication
     * Used by Recommendation_Service for ranking boost
     *
     * @param int $tool_id
     * @param string $thumbs
     */
    private static function update_aggregated_meta(int $tool_id, string $thumbs): void {
        $count_key = '_ml_feedback_count';
        $score_key = '_ml_feedback_score';

        $current_count = (int) get_post_meta($tool_id, $count_key, true);
        $current_score = (float) get_post_meta($tool_id, $score_key, true);

        // Increment count
        update_post_meta($tool_id, $count_key, $current_count + 1);

        // Update score (thumbs up = +1, thumbs down = -1, then average)
        $delta = ($thumbs === 'up') ? 1 : -1;
        $new_score = (($current_score * $current_count) + $delta) / ($current_count + 1);
        update_post_meta($tool_id, $score_key, round($new_score, 3));
    }

    /**
     * Get feedback stats for a tool
     *
     * @param int $tool_id Tool publication ID
     * @return array Stats with total, up, down, score
     */
    public static function get_tool_stats(int $tool_id): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN thumbs = 'up' THEN 1 ELSE 0 END) as up,
                SUM(CASE WHEN thumbs = 'down' THEN 1 ELSE 0 END) as down
             FROM $table
             WHERE tool_id = %d",
            $tool_id
        ), ARRAY_A);

        $total = (int) ($stats['total'] ?? 0);
        $up = (int) ($stats['up'] ?? 0);

        return [
            'total' => $total,
            'up' => $up,
            'down' => (int) ($stats['down'] ?? 0),
            'score' => $total > 0 ? round($up / $total, 2) : null,
        ];
    }

    /**
     * Get feedback score modifier for recommendation scoring
     *
     * Returns a value between -0.2 and +0.2 based on feedback ratio.
     * This is used to boost or penalize tools in recommendation scoring.
     *
     * @param int $tool_id Tool publication ID
     * @return float Score modifier
     */
    public static function get_score_modifier(int $tool_id): float {
        $stats = self::get_tool_stats($tool_id);

        // Need minimum 5 feedbacks to influence score
        if ($stats['total'] < 5) {
            return 0.0;
        }

        // Score ranges from 0 (all down) to 1 (all up)
        // Modifier ranges from -0.2 to +0.2
        $score = $stats['score'] ?? 0.5;
        return ($score - 0.5) * 0.4;  // Maps 0-1 to -0.2 to +0.2
    }

    /**
     * Get user's recent feedback
     *
     * @param int $user_id User ID
     * @param int $limit Max results
     * @return array Recent feedback entries
     */
    public static function get_user_feedback(int $user_id, int $limit = 10): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $user_id,
            $limit
        ), ARRAY_A);
    }

    /**
     * Check if user already gave feedback for a run
     *
     * @param string $run_id Run ID
     * @param int $user_id User ID
     * @return bool True if already has feedback
     */
    public static function has_feedback(string $run_id, int $user_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $table WHERE run_id = %s AND user_id = %d LIMIT 1",
            $run_id,
            $user_id
        ));
    }

    /**
     * Create the feedback table
     */
    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            run_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            tool_id BIGINT UNSIGNED NOT NULL,
            thumbs ENUM('up', 'down') NOT NULL,
            comment TEXT DEFAULT NULL,
            context_hash VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_tool (tool_id),
            KEY idx_user (user_id),
            KEY idx_run (run_id),
            KEY idx_created (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Check if table exists
     */
    public static function table_exists(): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        return $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    }


    /**
     * PR6: Flywheel - Record feedback as rating
     * Converts thumbs up/down or explicit rating to the SSoT rating fields
     *
     * @param int $tool_id Tool/publication ID
     * @param int $user_id User ID
     * @param int|null $rating Explicit rating 1-5 (optional)
     * @param string|null $thumbs 'up' or 'down' (converted to 5 or 2)
     * @param string|null $comment Optional comment
     * @return array Result with success and new rating averages
     */
    public static function record_rating(
        int $tool_id,
        int $user_id,
        ?int $rating = null,
        ?string $thumbs = null,
        ?string $comment = null
    ): array {
        // Determine rating value
        if ($rating !== null) {
            $rating = max(1, min(5, $rating));
        } elseif ($thumbs !== null) {
            // Convert thumbs: up=5, down=2
            $rating = ($thumbs === 'up') ? 5 : 2;
        } else {
            return ['success' => false, 'error' => 'rating or thumbs required'];
        }

        // Get current values
        $current_avg = (float) get_post_meta($tool_id, '_ml_average_rating', true);
        $current_count = (int) get_post_meta($tool_id, '_ml_rating_count', true);
        $dist_raw = get_post_meta($tool_id, '_ml_rating_distribution', true);
        $distribution = is_array($dist_raw) ? $dist_raw : (json_decode($dist_raw, true) ?: []);

        // Update distribution
        $distribution[$rating] = (int) ($distribution[$rating] ?? 0) + 1;

        // Calculate new average
        $new_count = $current_count + 1;
        $new_avg = (($current_avg * $current_count) + $rating) / $new_count;
        $new_avg = round($new_avg, 2);

        // Update SSoT metas
        update_post_meta($tool_id, '_ml_average_rating', $new_avg);
        update_post_meta($tool_id, '_ml_rating_count', $new_count);
        update_post_meta($tool_id, '_ml_rating_distribution', wp_json_encode($distribution));

        // Store individual rating in ratings table if exists
        global $wpdb;
        $ratings_table = $wpdb->prefix . 'ml_ratings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$ratings_table'") === $ratings_table) {
            $wpdb->insert($ratings_table, [
                'post_id' => $tool_id,
                'user_id' => $user_id,
                'rating' => $rating,
                'comment' => $comment,
                'created_at' => current_time('mysql', true),
            ]);
        }

        // Emit metrics
        do_action('ml_metrics', 'rating_recorded', [
            'tool_id' => $tool_id,
            'user_id' => $user_id,
            'rating' => $rating,
            'new_avg' => $new_avg,
            'new_count' => $new_count,
        ]);

        return [
            'success' => true,
            'rating' => $rating,
            'new_average' => $new_avg,
            'new_count' => $new_count,
        ];
    }

    /**
     * PR6: Create ratings table for storing individual reviews
     */
    public static function create_ratings_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ml_ratings';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            comment TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_post (post_id),
            KEY idx_user (user_id),
            KEY idx_created (created_at),
            UNIQUE KEY unique_user_post (user_id, post_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
