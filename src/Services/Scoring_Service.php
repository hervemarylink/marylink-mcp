<?php
/**
 * Scoring Service - Quality score calculation for publications
 *
 * Calculates and maintains quality scores for publications based on:
 * - User ratings (avg)
 * - Favorites count
 * - Views count
 * - Comment count
 * - Freshness (time decay)
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

class Scoring_Service {

    /**
     * Meta keys for scoring data
     */
    private const META_QUALITY_SCORE = '_ml_quality_score';
    private const META_AVG_USER_RATING = '_ml_avg_user_rating';
    private const META_USER_RATING_COUNT = '_ml_user_rating_count';
    private const META_FAVORITES_COUNT = '_ml_favorites_count';
    private const META_VIEWS_COUNT = '_publication_view'; // Picasso key
    private const META_COMMENT_COUNT = '_ml_comment_count';
    private const META_LAST_SCORE_UPDATE = '_ml_last_score_update';

    /**
     * Scoring weights (must sum to 1.0)
     */
    private const WEIGHT_RATING = 0.35;      // User ratings weight
    private const WEIGHT_FAVORITES = 0.25;   // Favorites weight
    private const WEIGHT_ENGAGEMENT = 0.20;  // Views + comments weight
    private const WEIGHT_FRESHNESS = 0.20;   // Freshness weight

    /**
     * Decay parameters
     */
    private const FRESHNESS_HALF_LIFE_DAYS = 30; // Score halves every N days

    /**
     * Recalculate scores for all publications (batch)
     *
     * @param int $batch_size Number of publications per batch
     * @param int $offset Starting offset
     * @return array Statistics about the recalculation
     */
    public function recalculate_scores(int $batch_size = 100, int $offset = 0): array {
        global $wpdb;

        $start_time = microtime(true);
        $updated = 0;
        $errors = 0;

        // Get publications to process
        $query_args = [
            'post_type' => 'publication',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'suppress_filters' => true,
        ];

        $query = new \WP_Query($query_args);
        $publication_ids = $query->posts;
        $total_found = $query->found_posts;

        foreach ($publication_ids as $pub_id) {
            try {
                $this->calculate_quality_score($pub_id, true);
                $updated++;
            } catch (\Exception $e) {
                $errors++;
                error_log("Scoring_Service: Error calculating score for pub {$pub_id}: " . $e->getMessage());
            }
        }

        $elapsed = round(microtime(true) - $start_time, 3);

        return [
            'success' => true,
            'batch_size' => $batch_size,
            'offset' => $offset,
            'processed' => count($publication_ids),
            'updated' => $updated,
            'errors' => $errors,
            'total_publications' => $total_found,
            'has_more' => ($offset + $batch_size) < $total_found,
            'next_offset' => $offset + $batch_size,
            'elapsed_seconds' => $elapsed,
        ];
    }

    /**
     * Calculate quality score for a single publication
     *
     * @param int $publication_id Publication ID
     * @param bool $save Whether to save the score to meta
     * @return float Score between 0 and 5
     */
    public function calculate_quality_score(int $publication_id, bool $save = true): float {
        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            throw new \InvalidArgumentException("Invalid publication ID: {$publication_id}");
        }

        // Gather metrics
        $avg_rating = (float) get_post_meta($publication_id, self::META_AVG_USER_RATING, true);
        $rating_count = (int) get_post_meta($publication_id, self::META_USER_RATING_COUNT, true);

// Backward compatibility: older installs store rating meta under different keys
if ($avg_rating <= 0.0) {
    $legacy_avg = get_post_meta($publication_id, '_ml_average_rating', true);
    if ($legacy_avg !== '') {
        $avg_rating = (float) $legacy_avg;
    }
}
if ($rating_count <= 0) {
    $legacy_count = get_post_meta($publication_id, '_ml_rating_count', true);
    if ($legacy_count !== '') {
        $rating_count = (int) $legacy_count;
    }
}

        $favorites = (int) get_post_meta($publication_id, self::META_FAVORITES_COUNT, true);
        $views = (int) get_post_meta($publication_id, self::META_VIEWS_COUNT, true);
        $comments = (int) get_post_meta($publication_id, self::META_COMMENT_COUNT, true);

        // Fallback: count actual comments if meta not set
        if ($comments === 0) {
            $comments = (int) get_comments_number($publication_id);
        }

        // Calculate post age in days
        $post_date = strtotime($post->post_date);
        $age_days = max(1, (time() - $post_date) / DAY_IN_SECONDS);

        // =====================
        // Score components
        // =====================

        // 1. Rating score (0-5)
        // Use Bayesian average to handle low rating counts
        $prior_rating = 3.0;  // Prior mean
        $prior_count = 2;     // Prior sample size (k)
        $rating_score = $rating_count > 0
            ? ($prior_count * $prior_rating + $rating_count * $avg_rating) / ($prior_count + $rating_count)
            : $prior_rating;

        // 2. Favorites score (0-5)
        // Logarithmic scaling: more favorites = higher score, but diminishing returns
        $favorites_score = $this->log_scale($favorites, 10, 5.0); // 10 favorites = 5.0

        // 3. Engagement score (0-5)
        // Combine views and comments
        $views_normalized = $this->log_scale($views, 100, 2.5);    // 100 views = 2.5
        $comments_normalized = $this->log_scale($comments, 5, 2.5); // 5 comments = 2.5
        $engagement_score = min(5.0, $views_normalized + $comments_normalized);

        // 4. Freshness score (0-5)
        // Exponential decay based on age
        $decay = pow(0.5, $age_days / self::FRESHNESS_HALF_LIFE_DAYS);
        $freshness_score = 5.0 * $decay;

        // =====================
        // Combine scores
        // =====================
        $quality_score = (
            self::WEIGHT_RATING * $rating_score +
            self::WEIGHT_FAVORITES * $favorites_score +
            self::WEIGHT_ENGAGEMENT * $engagement_score +
            self::WEIGHT_FRESHNESS * $freshness_score
        );

        // Clamp to 0-5 range
        $quality_score = max(0.0, min(5.0, $quality_score));

        // Save if requested
if ($save) {
    update_post_meta($publication_id, self::META_QUALITY_SCORE, $quality_score);
    update_post_meta($publication_id, self::META_LAST_SCORE_UPDATE, time());

    // Persist computed comment count (scoring uses it; meta may be missing)
    update_post_meta($publication_id, self::META_COMMENT_COUNT, $comments);

    // Engagement score used for "trending" sorting (integer for fast meta queries)
    update_post_meta($publication_id, '_ml_engagement_score', (int) round($quality_score * 100));
}

        return round($quality_score, 4);
    }

    /**
     * Update a specific metric and recalculate score
     *
     * @param int $publication_id Publication ID
     * @param string $metric Metric name (rating, favorite, view, comment)
     * @param mixed $value New value or delta
     * @param bool $is_delta Whether value is a delta (+1/-1) or absolute
     */
    public function update_metric(int $publication_id, string $metric, $value, bool $is_delta = true): void {
        $meta_map = [
            'favorites' => self::META_FAVORITES_COUNT,
            'views' => self::META_VIEWS_COUNT,
            'comments' => self::META_COMMENT_COUNT,
        ];

        if (isset($meta_map[$metric])) {
            $meta_key = $meta_map[$metric];
            $current = (int) get_post_meta($publication_id, $meta_key, true);

            if ($is_delta) {
                $new_value = max(0, $current + (int) $value);
            } else {
                $new_value = max(0, (int) $value);
            }

            update_post_meta($publication_id, $meta_key, $new_value);
        } elseif ($metric === 'rating') {
            // Rating requires special handling (average + count)
            $this->add_rating($publication_id, (float) $value);
        }

        // Recalculate quality score
        $this->calculate_quality_score($publication_id, true);
    }

    /**
     * Add a new rating and update average
     *
     * @param int $publication_id Publication ID
     * @param float $rating Rating value (1-5)
     */
    public function add_rating(int $publication_id, float $rating): void {
        $rating = max(1.0, min(5.0, $rating));

        $current_avg = (float) get_post_meta($publication_id, self::META_AVG_USER_RATING, true);
        $current_count = (int) get_post_meta($publication_id, self::META_USER_RATING_COUNT, true);

        $new_count = $current_count + 1;
        $new_avg = (($current_avg * $current_count) + $rating) / $new_count;

        update_post_meta($publication_id, self::META_AVG_USER_RATING, $new_avg);
        update_post_meta($publication_id, self::META_USER_RATING_COUNT, $new_count);
    }

    /**
     * Get quality score for a publication
     *
     * @param int $publication_id Publication ID
     * @return float|null Score or null if not calculated
     */
    public function get_score(int $publication_id): ?float {
        $score = get_post_meta($publication_id, self::META_QUALITY_SCORE, true);
        return $score !== '' ? (float) $score : null;
    }

    /**
     * Get detailed scoring breakdown
     *
     * @param int $publication_id Publication ID
     * @return array Scoring details
     */
    public function get_scoring_details(int $publication_id): array {
        $post = get_post($publication_id);
        if (!$post) {
            return ['error' => 'Publication not found'];
        }

        $age_days = max(1, (time() - strtotime($post->post_date)) / DAY_IN_SECONDS);
        $decay = pow(0.5, $age_days / self::FRESHNESS_HALF_LIFE_DAYS);

        return [
            'publication_id' => $publication_id,
            'quality_score' => $this->get_score($publication_id),
            'last_updated' => get_post_meta($publication_id, self::META_LAST_SCORE_UPDATE, true),
            'metrics' => [
                'avg_user_rating' => (float) get_post_meta($publication_id, self::META_AVG_USER_RATING, true),
                'user_rating_count' => (int) get_post_meta($publication_id, self::META_USER_RATING_COUNT, true),
                'favorites_count' => (int) get_post_meta($publication_id, self::META_FAVORITES_COUNT, true),
                'views_count' => (int) get_post_meta($publication_id, self::META_VIEWS_COUNT, true),
                'comment_count' => (int) get_post_meta($publication_id, self::META_COMMENT_COUNT, true),
            ],
            'freshness' => [
                'age_days' => round($age_days, 1),
                'decay_factor' => round($decay, 4),
            ],
            'weights' => [
                'rating' => self::WEIGHT_RATING,
                'favorites' => self::WEIGHT_FAVORITES,
                'engagement' => self::WEIGHT_ENGAGEMENT,
                'freshness' => self::WEIGHT_FRESHNESS,
            ],
        ];
    }

    /**
     * Logarithmic scaling helper
     *
     * @param int $value Input value
     * @param int $reference Reference value that maps to max_score
     * @param float $max_score Maximum score for reference value
     * @return float Scaled score
     */
    private function log_scale(int $value, int $reference, float $max_score): float {
        if ($value <= 0) {
            return 0.0;
        }
        // log(1 + value) / log(1 + reference) * max_score
        return min($max_score, log1p($value) / log1p($reference) * $max_score);
    }

    /**
     * Register cron hook for batch recalculation
     */
    public static function register_cron(): void {
        if (!wp_next_scheduled('mcpnh_recalculate_scores')) {
            wp_schedule_event(time(), 'daily', 'mcpnh_recalculate_scores');
        }
        add_action('mcpnh_recalculate_scores', [self::class, 'cron_recalculate']);
    }

    /**
     * Unregister cron hook
     */
    public static function unregister_cron(): void {
        $timestamp = wp_next_scheduled('mcpnh_recalculate_scores');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'mcpnh_recalculate_scores');
        }
    }

    /**
     * Cron callback for batch recalculation
     */
    public static function cron_recalculate(): void {
        $service = new self();
        $batch_size = 100;
        $offset = 0;
        $total_updated = 0;
        $total_errors = 0;

        do {
            $result = $service->recalculate_scores($batch_size, $offset);
            $total_updated += $result['updated'];
            $total_errors += $result['errors'];
            $offset = $result['next_offset'];

            // Safety: limit to 10000 publications per cron run
            if ($offset >= 10000) {
                break;
            }
        } while ($result['has_more']);

        // Log summary
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Scoring_Service cron: Updated {$total_updated} publications, {$total_errors} errors.");
        }
    }
}
