<?php
/**
 * Baseline Command - Capture et compare les resultats ml_recommend
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\CLI;

use MCP_No_Headless\Services\Recommendation_Service;
use WP_CLI;

class Baseline_Command {

    private const TEST_QUERIES = [
        'lettre commerciale',
        'compte rendu reunion',
        'traduire en anglais',
        'reecrire professionnel',
        'resumer document',
        'email client mecontent',
        'analyse risques',
        'proposition commerciale',
        'synthese rapport',
        'reponse appel offres',
    ];

    /**
     * Dump current ml_recommend results for baseline comparison
     *
     * ## OPTIONS
     *
     * [--user=<id>]
     * : User ID to run as (default: 1)
     *
     * [--output=<path>]
     * : Output directory (default: plugin_dir/baseline/)
     *
     * [--space=<id>]
     * : Limit to a specific space (optional)
     *
     * ## EXAMPLES
     *     wp marylink baseline:dump
     *     wp marylink baseline:dump --user=5
     *     wp marylink baseline:dump --space=123
     *
     * @when after_wp_load
     */
    public function dump($args, $assoc_args) {
        $user_id = (int) ($assoc_args['user'] ?? 135);
        $output_dir = $assoc_args['output'] ?? MCPNH_PLUGIN_DIR . 'baseline';
        $space_id = isset($assoc_args['space']) ? (int) $assoc_args['space'] : null;

        if (!is_dir($output_dir)) {
            mkdir($output_dir, 0755, true);
        }

        // Check user exists
        $user = get_user_by('id', $user_id);
        if (!$user) {
            WP_CLI::error("User ID {$user_id} not found");
        }

        // Set current user for permission checks - must be done BEFORE any service instantiation
        wp_set_current_user($user_id);

        // Ensure global user is set
        global $current_user;
        $current_user = $user;

        $service = new Recommendation_Service($user_id);
        $results = [];

        WP_CLI::log("Running baseline with " . count(self::TEST_QUERIES) . " queries...");
        WP_CLI::log("User ID: {$user_id}" . ($space_id ? ", Space: {$space_id}" : ""));
        WP_CLI::log("");

        foreach (self::TEST_QUERIES as $query) {
            $start = microtime(true);

            $options = ['limit' => 10];
            $result = $service->recommend($query, $space_id, $options);

            $latency = (int) ((microtime(true) - $start) * 1000);

            $recommendations = $result['recommendations'] ?? [];
            $top_ids = array_map(
                fn($r) => $r['prompt']['id'] ?? $r['id'] ?? 0,
                array_slice($recommendations, 0, 5)
            );

            $results[] = [
                'query' => $query,
                'results_count' => count($recommendations),
                'total_candidates' => $result['total_candidates'] ?? 0,
                'top_5_ids' => $top_ids,
                'intent_detected' => $result['intent']['detected'] ?? 'unknown',
                'intent_confidence' => $result['intent']['confidence'] ?? 0,
                'latency_ms' => $latency,
            ];

            $count = count($recommendations);
            $status = $count > 0 ? WP_CLI::colorize('%G+%n') : WP_CLI::colorize('%Rx%n');
            WP_CLI::log("  {$status} \"{$query}\" -> {$count} results ({$latency}ms)");
        }

        $filename = sprintf('dump_%s.json', date('Ymd_His'));
        $filepath = $output_dir . '/' . $filename;

        $dump = [
            'version' => '1.0',
            'timestamp' => date('c'),
            'user_id' => $user_id,
            'space_id' => $space_id,
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'plugin_version' => MCPNH_VERSION ?? 'unknown',
            'results' => $results,
            'summary' => [
                'total_queries' => count($results),
                'zero_results' => count(array_filter($results, fn($r) => $r['results_count'] === 0)),
                'avg_latency_ms' => count($results) > 0 ? round(array_sum(array_column($results, 'latency_ms')) / count($results)) : 0,
                'avg_results' => count($results) > 0 ? round(array_sum(array_column($results, 'results_count')) / count($results), 1) : 0,
            ],
        ];

        file_put_contents($filepath, json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        WP_CLI::log("");
        WP_CLI::success("Baseline saved to: {$filepath}");
        WP_CLI::log("Summary: {$dump['summary']['zero_results']}/{$dump['summary']['total_queries']} queries returned 0 results");
        WP_CLI::log("Average: {$dump['summary']['avg_results']} results/query, {$dump['summary']['avg_latency_ms']}ms latency");
    }

    /**
     * Compare two baseline dumps
     *
     * ## OPTIONS
     *
     * <file_before>
     * : Path to baseline before changes
     *
     * <file_after>
     * : Path to baseline after changes
     *
     * ## EXAMPLES
     *     wp marylink baseline:compare baseline/dump_before.json baseline/dump_after.json
     *
     * @when after_wp_load
     */
    public function compare($args, $assoc_args) {
        if (count($args) < 2) {
            WP_CLI::error("Usage: wp marylink baseline:compare <file_before> <file_after>");
        }

        $file_before = $args[0];
        $file_after = $args[1];

        // Handle relative paths
        if (!file_exists($file_before) && file_exists(MCPNH_PLUGIN_DIR . $file_before)) {
            $file_before = MCPNH_PLUGIN_DIR . $file_before;
        }
        if (!file_exists($file_after) && file_exists(MCPNH_PLUGIN_DIR . $file_after)) {
            $file_after = MCPNH_PLUGIN_DIR . $file_after;
        }

        if (!file_exists($file_before)) {
            WP_CLI::error("File not found: {$file_before}");
        }
        if (!file_exists($file_after)) {
            WP_CLI::error("File not found: {$file_after}");
        }

        $before = json_decode(file_get_contents($file_before), true);
        $after = json_decode(file_get_contents($file_after), true);

        if (!$before || !$after) {
            WP_CLI::error("Invalid JSON files");
        }

        WP_CLI::log("\n=== Baseline Comparison ===\n");
        WP_CLI::log("Before: {$args[0]} ({$before['timestamp']})");
        WP_CLI::log("After:  {$args[1]} ({$after['timestamp']})\n");

        $regression = false;
        $improvements = 0;

        foreach ($before['results'] as $i => $b) {
            $a = $after['results'][$i] ?? null;
            if (!$a) continue;

            $delta = $a['results_count'] - $b['results_count'];
            $delta_str = $delta > 0 ? WP_CLI::colorize("%G+{$delta}%n") : ($delta < 0 ? WP_CLI::colorize("%R{$delta}%n") : "=");

            // Check for regression (was >0, now 0)
            if ($b['results_count'] > 0 && $a['results_count'] === 0) {
                $regression = true;
                $status = WP_CLI::colorize('%R!! REGRESSION%n');
            } elseif ($b['results_count'] === 0 && $a['results_count'] > 0) {
                $improvements++;
                $status = WP_CLI::colorize('%G** FIXED%n');
            } elseif ($a['results_count'] > $b['results_count']) {
                $improvements++;
                $status = WP_CLI::colorize('%G^ IMPROVED%n');
            } else {
                $status = '';
            }

            // Common IDs
            $common = count(array_intersect($b['top_5_ids'], $a['top_5_ids']));

            WP_CLI::log(sprintf(
                "  %-30s %2d -> %2d (%s) [%d/5 common] %s",
                "\"{$b['query']}\"",
                $b['results_count'],
                $a['results_count'],
                $delta_str,
                $common,
                $status
            ));
        }

        WP_CLI::log("\n=== Summary ===");
        WP_CLI::log(sprintf(
            "Zero results: %d -> %d (%s)",
            $before['summary']['zero_results'],
            $after['summary']['zero_results'],
            $after['summary']['zero_results'] < $before['summary']['zero_results']
                ? WP_CLI::colorize('%GIMPROVED%n')
                : ($after['summary']['zero_results'] > $before['summary']['zero_results'] ? WP_CLI::colorize('%RWORSE%n') : 'SAME')
        ));
        WP_CLI::log(sprintf(
            "Avg latency: %dms -> %dms",
            $before['summary']['avg_latency_ms'],
            $after['summary']['avg_latency_ms']
        ));
        WP_CLI::log("Improvements: {$improvements}");

        if ($regression) {
            WP_CLI::error("REGRESSION DETECTED: Some queries that worked before now return 0");
        }

        WP_CLI::success("No regressions detected");
    }

    /**
     * List available baseline dumps
     *
     * ## EXAMPLES
     *     wp marylink baseline:list
     *
     * @when after_wp_load
     */
    public function list($args, $assoc_args) {
        $output_dir = MCPNH_PLUGIN_DIR . 'baseline';

        if (!is_dir($output_dir)) {
            WP_CLI::warning("No baseline directory found");
            return;
        }

        $files = glob($output_dir . '/dump_*.json');

        if (empty($files)) {
            WP_CLI::warning("No baseline dumps found");
            return;
        }

        WP_CLI::log("\n=== Available Baselines ===\n");

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            $filename = basename($file);
            $zero = $data['summary']['zero_results'] ?? '?';
            $total = $data['summary']['total_queries'] ?? '?';
            $timestamp = $data['timestamp'] ?? 'unknown';

            WP_CLI::log("  {$filename} - {$zero}/{$total} zeros - {$timestamp}");
        }
    }
}
