<?php
/**
 * Find_Ranking - In-memory ranking helpers for ml_find results
 *
 * Keeps ranking logic deterministic and unit-testable (no WP_Query dependency).
 *
 * @package MCP_No_Headless
 * @since 3.2.9
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\Schema\Publication_Schema;
use MCP_No_Headless\Picasso\Meta_Keys;

class Find_Ranking {

    /** Sort options accepted by ml_find */
    public const VALID_SORTS = [
        'best',           // Hybrid: rating + quality + engagement + freshness
        'best_rated',     // rating average then count
        'top_rated',      // ONLY rated items (rating_count>0), sorted by avg then count
        'trending',       // engagement score then freshness
        'most_commented', // comment_count then freshness
        'most_liked',     // likes meta then freshness
        'most_favorited', // favorites_count then freshness
    ];

    /**
     * Sort an array of WP_Post-like objects (must have ->ID, ->post_date_gmt or ->post_date).
     *
     * @param array<int,object> $posts
     * @param string $sort
     * @return array<int,object>
     */
    public static function sort_posts(array $posts, string $sort): array {
        if (empty($posts)) return $posts;
        if (!in_array($sort, self::VALID_SORTS, true)) return $posts;

        // Pre-compute per-post metrics once (avoid repeated get_post_meta calls in comparator)
        $meta = [];
        foreach ($posts as $p) {
            $id = (int) ($p->ID ?? 0);
            if ($id <= 0) continue;

            $qm = Publication_Schema::get_quality_metrics($id);
            $likes = Meta_Keys::get_votes_count($id);

            $meta[$id] = [
                'rating_avg' => (float) ($qm['rating']['average'] ?? 0.0),
                'rating_count' => (int) ($qm['rating']['count'] ?? 0),
                'favorites' => (int) ($qm['favorites_count'] ?? 0),
                'quality' => $qm['quality_score'],
                'engagement' => $qm['engagement_score'],
                'likes' => $likes,
                'comments' => (int) ($p->comment_count ?? 0),
                'timestamp' => self::timestamp($p),
            ];
        }

        usort($posts, function ($a, $b) use ($sort, $meta) {
            $ida = (int) ($a->ID ?? 0);
            $idb = (int) ($b->ID ?? 0);

            $ma = $meta[$ida] ?? ['timestamp' => 0];
            $mb = $meta[$idb] ?? ['timestamp' => 0];

            // Comparators return DESC by default
            $cmp = 0;
            switch ($sort) {
                
                case 'top_rated':
                    // Same as best_rated but posts are pre-filtered (rating_count > 0)
                    $cmp = ($mb['rating_avg'] <=> $ma['rating_avg']);
                    if ($cmp !== 0) break;
                    $cmp = ($mb['rating_count'] <=> $ma['rating_count']);
                    if ($cmp !== 0) break;
                    $cmp = ($mb['timestamp'] <=> $ma['timestamp']);
                    break;

                case 'top_rated':
            case 'best_rated':
                    // rating average, then rating count, then freshness
                    $cmp = ($mb['rating_avg'] <=> $ma['rating_avg']);
                    if ($cmp !== 0) break;
                    $cmp = ($mb['rating_count'] <=> $ma['rating_count']);
                    if ($cmp !== 0) break;
                    $cmp = ($mb['timestamp'] <=> $ma['timestamp']);
                    break;

                case 'most_favorited':
                    $cmp = ($mb['favorites'] <=> $ma['favorites']);
                    if ($cmp !== 0) break;
                    $cmp = ($mb['timestamp'] <=> $ma['timestamp']);
                    break;

                case 'most_liked':
                    $cmp = ($mb['likes'] <=> $ma['likes']);
                    if ($cmp !== 0) break;
                    $cmp = ($mb['timestamp'] <=> $ma['timestamp']);
                    break;

                case 'most_commented':
                    $cmp = ($mb['comments'] <=> $ma['comments']);
                    if ($cmp !== 0) break;
                    $cmp = ($mb['timestamp'] <=> $ma['timestamp']);
                    break;

                case 'trending':
                    // engagement score (if present), else fallback to likes+comments, then freshness
                    $ea = $ma['engagement'] ?? null;
                    $eb = $mb['engagement'] ?? null;
                    // Both have engagement: compare by engagement
                    if ($ea !== null && $eb !== null) {
                        $cmp = ((int)$eb <=> (int)$ea);
                        if ($cmp !== 0) break;
                    }
                    // Only A has engagement: A wins (sort DESC, so -1)
                    elseif ($ea !== null && $eb === null) {
                        $cmp = -1;
                        break;
                    }
                    // Only B has engagement: B wins
                    elseif ($ea === null && $eb !== null) {
                        $cmp = 1;
                        break;
                    }
                    // Neither has engagement: compare likes+comments
                    else {
                        $score_a = (int) $ma['likes'] + (int) $ma['comments'];
                        $score_b = (int) $mb['likes'] + (int) $mb['comments'];
                        $cmp = ($score_b <=> $score_a);
                        if ($cmp !== 0) break;
                    }
                    $cmp = ($mb['timestamp'] <=> $ma['timestamp']);
                    break;

                case 'best':
                default:
                    // Hybrid: prioritize strong rating AND sufficient count; then quality & engagement; then freshness.
                    // This avoids "one 5★ review beats 200x 4.8★" cases.
                    $score_a = self::best_score($ma);
                    $score_b = self::best_score($mb);
                    $cmp = ($score_b <=> $score_a);
                    if ($cmp !== 0) break;
                    $cmp = ($mb['timestamp'] <=> $ma['timestamp']);
                    break;
            }

            // Stable fallback: ID DESC
            if ($cmp === 0) {
                $cmp = ($idb <=> $ida);
            }

            return $cmp;
        });

        return $posts;
    }

    private static function best_score(array $m): float {
        $avg = (float) ($m['rating_avg'] ?? 0);
        $count = (int) ($m['rating_count'] ?? 0);

        // Wilson-like dampening: weight the average by confidence in count
        $confidence = min(1.0, log(1 + $count) / log(1 + 50)); // saturates around 50 reviews
        $rating_component = $avg * $confidence;

        $quality = ($m['quality'] !== null) ? (float) $m['quality'] : 0.0;
        $engagement = ($m['engagement'] !== null) ? (float) $m['engagement'] : 0.0;

        // Normalize engagement into 0..5-ish range (coarse)
        $eng_norm = min(5.0, $engagement / 20.0);

        return ($rating_component * 10.0) + ($quality * 2.0) + ($eng_norm * 1.0);
    }

    
    /**
     * Filter posts to only include those with rating_count > 0
     * Used by top_rated sort.
     */
    public static function filter_for_top_rated(array $posts): array {
        return array_filter($posts, function($p) {
            $id = (int) ($p->ID ?? 0);
            if ($id <= 0) return false;
            $count = Meta_Keys::get_rating_count($id);
            return $count > 0;
        });
    }

    private static function timestamp($post): int {
        $date = $post->post_date_gmt ?? $post->post_date ?? null;
        if (empty($date)) return 0;
        $ts = strtotime($date);
        return $ts ? (int) $ts : 0;
    }


    /**
     * PR4: Get ranking explanation for a post
     */
    public static function get_ranking_reason(int $post_id, ?string $sort): array {
        if (empty($sort)) {
            return ['signal_used' => 'date', 'fallback_applied' => false, 'sort_type' => 'default'];
        }
        $qm = Publication_Schema::get_quality_metrics($post_id);
        $rating_count = (int) ($qm['rating']['count'] ?? 0);
        $engagement = $qm['engagement_score'];
        
        switch ($sort) {
            case 'best_rated':
                return $rating_count > 0 
                    ? ['signal_used' => 'rating', 'fallback_applied' => false, 'sort_type' => $sort]
                    : ['signal_used' => 'freshness', 'fallback_applied' => true, 'sort_type' => $sort];
            case 'trending':
                return $engagement !== null
                    ? ['signal_used' => 'engagement_score', 'fallback_applied' => false, 'sort_type' => $sort]
                    : ['signal_used' => 'likes+comments', 'fallback_applied' => true, 'sort_type' => $sort];
            case 'best':
                return ['signal_used' => 'hybrid', 'fallback_applied' => false, 'sort_type' => $sort];
            default:
                return ['signal_used' => $sort, 'fallback_applied' => false, 'sort_type' => $sort];
        }
    }
}
