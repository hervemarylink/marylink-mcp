<?php
/**
 * Tests for Publication_Schema::get_quality_metrics()
 *
 * @package MCP_No_Headless\Tests\Unit
 */

namespace MCP_No_Headless\Tests\Unit;

use PHPUnit\Framework\TestCase;
use MCP_No_Headless\Schema\Publication_Schema;

class PublicationSchemaQualityMetricsTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        global $mcpnh_test_meta, $mcpnh_test_posts;
        $mcpnh_test_meta = [];
        $mcpnh_test_posts = [];
    }

    public function test_quality_metrics_defaults_are_safe(): void {
        global $mcpnh_test_meta;
        $mcpnh_test_meta[123] = [];

        $m = Publication_Schema::get_quality_metrics(123);

        $this->assertEquals(0.0, $m['rating']['average']);
        $this->assertEquals(0, $m['rating']['count']);
        $this->assertEquals(0, $m['favorites_count']);
        $this->assertNull($m['quality_score']);
        $this->assertNull($m['engagement_score']);
    }

    public function test_quality_metrics_parses_distribution_json(): void {
        global $mcpnh_test_meta;
        $mcpnh_test_meta[555] = [
            '_ml_average_rating' => '4.666',
            '_ml_rating_count' => '3',
            '_ml_favorites_count' => '7',
            '_ml_quality_score' => '4.2',
            '_ml_engagement_score' => '40',
            '_ml_rating_distribution' => '{"5":2,"4":1}',
        ];

        $m = Publication_Schema::get_quality_metrics(555);

        $this->assertEquals(4.67, $m['rating']['average']);
        $this->assertEquals(3, $m['rating']['count']);
        $this->assertEquals(7, $m['favorites_count']);
        $this->assertEquals(4.2, $m['quality_score']);
        $this->assertEquals(40, $m['engagement_score']);
        $this->assertEquals([1=>0,2=>0,3=>0,4=>1,5=>2], $m['rating']['distribution']);
    }
}
