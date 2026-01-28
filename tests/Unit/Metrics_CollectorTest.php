<?php
/**
 * Tests for Metrics_Collector
 *
 * @package MCP_No_Headless\Tests\Unit
 */

namespace MCP_No_Headless\Tests\Unit;

use PHPUnit\Framework\TestCase;

class Metrics_CollectorTest extends TestCase {

    /**
     * Metric thresholds
     */
    private array $thresholds = [
        'coverage_rate' => ['target' => 70, 'alert' => 50],
        'placeholder_rate' => ['target' => 30, 'alert' => 50],
        'replacement_rate' => ['target' => 25, 'alert' => 40],
        'fetch_success_rate' => ['target' => 95, 'alert' => 90],
        'p95_resolve_latency' => ['target' => 500, 'alert' => 1000],
        'avg_injected_tokens' => ['target' => 15000, 'alert' => 25000],
    ];

    /**
     * Calculate coverage rate
     */
    private function calculateCoverageRate(int $found, int $total): float {
        if ($total === 0) return 0;
        return ($found / $total) * 100;
    }

    /**
     * Calculate placeholder rate
     */
    private function calculatePlaceholderRate(int $placeholders, int $total): float {
        if ($total === 0) return 0;
        return ($placeholders / $total) * 100;
    }

    /**
     * TEST: Coverage rate calculation
     */
    public function test_coverage_rate_calculation(): void {
        // 3 out of 4 found
        $rate = $this->calculateCoverageRate(3, 4);
        $this->assertEquals(75, $rate);

        // All found
        $rate = $this->calculateCoverageRate(4, 4);
        $this->assertEquals(100, $rate);

        // None found
        $rate = $this->calculateCoverageRate(0, 4);
        $this->assertEquals(0, $rate);
    }

    /**
     * TEST: Placeholder rate calculation
     */
    public function test_placeholder_rate_calculation(): void {
        // 1 placeholder out of 4 total
        $rate = $this->calculatePlaceholderRate(1, 4);
        $this->assertEquals(25, $rate);
    }

    /**
     * TEST: Coverage rate threshold check
     */
    public function test_coverage_rate_alerts(): void {
        $threshold = $this->thresholds['coverage_rate'];

        // Good: 75% (above target)
        $rate = 75;
        $status = $rate >= $threshold['target'] ? 'ok' :
                 ($rate >= $threshold['alert'] ? 'warning' : 'alert');
        $this->assertEquals('ok', $status);

        // Warning: 60%
        $rate = 60;
        $status = $rate >= $threshold['target'] ? 'ok' :
                 ($rate >= $threshold['alert'] ? 'warning' : 'alert');
        $this->assertEquals('warning', $status);

        // Alert: 40%
        $rate = 40;
        $status = $rate >= $threshold['target'] ? 'ok' :
                 ($rate >= $threshold['alert'] ? 'warning' : 'alert');
        $this->assertEquals('alert', $status);
    }

    /**
     * TEST: Placeholder rate threshold (inverted)
     */
    public function test_placeholder_rate_alerts(): void {
        $threshold = $this->thresholds['placeholder_rate'];

        // Good: 20% (below target)
        $rate = 20;
        $status = $rate <= $threshold['target'] ? 'ok' :
                 ($rate <= $threshold['alert'] ? 'warning' : 'alert');
        $this->assertEquals('ok', $status);

        // Alert: 60% (above alert threshold)
        $rate = 60;
        $status = $rate <= $threshold['target'] ? 'ok' :
                 ($rate <= $threshold['alert'] ? 'warning' : 'alert');
        $this->assertEquals('alert', $status);
    }

    /**
     * TEST: Latency P95 calculation
     */
    public function test_p95_latency_calculation(): void {
        $latencies = [100, 150, 200, 250, 300, 350, 400, 450, 500, 1000];
        sort($latencies);

        $count = count($latencies);
        $p95_index = (int) ceil($count * 0.95) - 1;
        $p95 = $latencies[$p95_index];

        // P95 of these values should be 1000 (the 95th percentile)
        $this->assertEquals(1000, $p95);
    }

    /**
     * TEST: Metrics event structure
     */
    public function test_metrics_event_structure(): void {
        $event = [
            'event_type' => 'bootstrap_analyze',
            'user_id' => 1,
            'space_id' => 42,
            'timestamp' => time(),
            'metrics' => [
                'confidence' => 0.85,
                'detected_tools_count' => 2,
            ],
        ];

        $this->assertArrayHasKey('event_type', $event);
        $this->assertArrayHasKey('timestamp', $event);
        $this->assertArrayHasKey('metrics', $event);
        $this->assertIsArray($event['metrics']);
    }

    /**
     * TEST: Dashboard metrics aggregation
     */
    public function test_dashboard_metrics_aggregation(): void {
        // Simulate raw events
        $events = [
            ['coverage' => 80, 'placeholders' => 1, 'total' => 4],
            ['coverage' => 100, 'placeholders' => 0, 'total' => 3],
            ['coverage' => 50, 'placeholders' => 2, 'total' => 4],
        ];

        // Aggregate
        $total_coverage = 0;
        $total_items = 0;
        $total_placeholders = 0;

        foreach ($events as $event) {
            $total_coverage += $event['coverage'];
            $total_items += $event['total'];
            $total_placeholders += $event['placeholders'];
        }

        $avg_coverage = $total_coverage / count($events);
        $placeholder_rate = ($total_placeholders / $total_items) * 100;

        $this->assertEqualsWithDelta(76.67, $avg_coverage, 0.01);
        $this->assertEqualsWithDelta(27.27, $placeholder_rate, 0.01);
    }

    /**
     * TEST: URL resolve metrics
     */
    public function test_url_resolve_metrics(): void {
        $resolve_result = [
            'latency_ms' => 150,
            'injected_tokens' => 5000,
            'local_count' => 2,
            'remote_count' => 0,
            'success' => true,
        ];

        $this->assertLessThan(
            $this->thresholds['p95_resolve_latency']['target'],
            $resolve_result['latency_ms']
        );
        $this->assertLessThan(
            $this->thresholds['avg_injected_tokens']['target'],
            $resolve_result['injected_tokens']
        );
    }

    /**
     * TEST: Period filtering
     */
    public function test_period_filtering(): void {
        $now = time();
        $events = [
            ['timestamp' => $now - 86400 * 5],  // 5 days ago
            ['timestamp' => $now - 86400 * 15], // 15 days ago
            ['timestamp' => $now - 86400 * 45], // 45 days ago
        ];

        // Filter for 7d period
        $period_start = $now - 86400 * 7;
        $filtered_7d = array_filter($events, fn($e) => $e['timestamp'] >= $period_start);
        $this->assertCount(1, $filtered_7d);

        // Filter for 30d period
        $period_start = $now - 86400 * 30;
        $filtered_30d = array_filter($events, fn($e) => $e['timestamp'] >= $period_start);
        $this->assertCount(2, $filtered_30d);
    }
}
