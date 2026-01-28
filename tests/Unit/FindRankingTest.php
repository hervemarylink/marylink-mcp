<?php
/**
 * Tests for Find_Ranking sorting logic
 *
 * @package MCP_No_Headless\Tests\Unit
 */

namespace MCP_No_Headless\Tests\Unit;

use PHPUnit\Framework\TestCase;
use MCP_No_Headless\Services\Find_Ranking;

class FindRankingTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        global $mcpnh_test_meta, $mcpnh_test_posts;
        $mcpnh_test_meta = [];
        $mcpnh_test_posts = [];
    }

    private function makePost(int $id, string $date_gmt, int $comment_count = 0): object {
        return (object) [
            'ID' => $id,
            'post_date_gmt' => $date_gmt,
            'comment_count' => $comment_count,
        ];
    }

    public function test_best_rated_prefers_higher_avg_then_count(): void {
        global $mcpnh_test_meta;

        // Post 1: 5.0 avg but 1 rating
        $mcpnh_test_meta[1] = [
            '_ml_average_rating' => '5',
            '_ml_rating_count' => '1',
        ];

        // Post 2: 4.9 avg but 100 ratings
        $mcpnh_test_meta[2] = [
            '_ml_average_rating' => '4.9',
            '_ml_rating_count' => '100',
        ];

        $posts = [
            $this->makePost(1, '2026-01-26 00:00:00', 0),
            $this->makePost(2, '2026-01-26 00:00:00', 0),
        ];

        $sorted = Find_Ranking::sort_posts($posts, 'best_rated');
        $this->assertEquals(1, $sorted[0]->ID, 'best_rated should rank strictly by average first');
    }

    public function test_best_hybrid_dampens_low_count_five_star(): void {
        global $mcpnh_test_meta;

        // Post 10: 5★ but 1 rating
        $mcpnh_test_meta[10] = [
            '_ml_average_rating' => '5',
            '_ml_rating_count' => '1',
        ];

        // Post 11: 4.8★ but 60 ratings
        $mcpnh_test_meta[11] = [
            '_ml_average_rating' => '4.8',
            '_ml_rating_count' => '60',
        ];

        $posts = [
            $this->makePost(10, '2026-01-26 00:00:00', 0),
            $this->makePost(11, '2026-01-26 00:00:00', 0),
        ];

        $sorted = Find_Ranking::sort_posts($posts, 'best');

        // Hybrid should prefer higher-confidence 4.8★/60 over 5★/1
        $this->assertEquals(11, $sorted[0]->ID);
    }

    public function test_most_commented(): void {
        $posts = [
            $this->makePost(21, '2026-01-25 00:00:00', 1),
            $this->makePost(22, '2026-01-25 00:00:00', 9),
            $this->makePost(23, '2026-01-25 00:00:00', 3),
        ];

        $sorted = Find_Ranking::sort_posts($posts, 'most_commented');
        $this->assertEquals([22,23,21], array_map(fn($p) => $p->ID, $sorted));
    }
}
