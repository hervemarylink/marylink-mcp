<?php
/**
 * Migration Command - Sync publication data between Picasso and Wizard schemas
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\CLI;

use MCP_No_Headless\Schema\Publication_Schema;
use WP_CLI;

class Migration_Command {

    /**
     * Migrate publications to sync schema data
     *
     * Ensures both Picasso (post_parent, _publication_step) and Wizard
     * (_ml_space_id, _ml_step) meta are set for all publications.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview changes without making them
     *
     * [--batch=<size>]
     * : Process publications in batches (default: 100)
     *
     * [--space=<id>]
     * : Only migrate publications in a specific space
     *
     * [--direction=<dir>]
     * : Migration direction: "to-wizard" (Picasso->Wizard), "to-picasso" (Wizard->Picasso), or "both" (default)
     *
     * ## EXAMPLES
     *     wp marylink migrate --dry-run
     *     wp marylink migrate --batch=50
     *     wp marylink migrate --direction=to-wizard
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);
        $batch_size = (int) ($assoc_args['batch'] ?? 100);
        $space_id = isset($assoc_args['space']) ? (int) $assoc_args['space'] : null;
        $direction = $assoc_args['direction'] ?? 'both';

        WP_CLI::log("\n=== Publication Schema Migration ===\n");
        WP_CLI::log("Mode: " . ($dry_run ? "DRY-RUN" : "LIVE"));
        WP_CLI::log("Direction: $direction");
        WP_CLI::log("Batch size: $batch_size");
        if ($space_id) {
            WP_CLI::log("Space filter: $space_id");
        }
        WP_CLI::log("");

        // Get total count
        $count_args = [
            'post_type' => 'publication',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ];
        if ($space_id) {
            $count_args['post_parent'] = $space_id;
        }
        $all_ids = get_posts($count_args);
        $total = count($all_ids);

        WP_CLI::log("Total publications to check: $total\n");

        if ($total === 0) {
            WP_CLI::success("No publications found to migrate.");
            return;
        }

        $stats = [
            'checked' => 0,
            'to_wizard' => 0,
            'to_picasso' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $progress = \WP_CLI\Utils\make_progress_bar('Migrating', $total);

        // Process in batches
        $offset = 0;
        while ($offset < $total) {
            $batch_ids = array_slice($all_ids, $offset, $batch_size);

            foreach ($batch_ids as $pub_id) {
                $result = $this->migrate_publication($pub_id, $direction, $dry_run);
                $stats[$result]++;
                $stats['checked']++;
                $progress->tick();
            }

            $offset += $batch_size;

            // Clear memory
            wp_cache_flush();
        }

        $progress->finish();

        WP_CLI::log("\n=== Migration Summary ===");
        WP_CLI::log("Checked: {$stats['checked']}");
        WP_CLI::log("Migrated to Wizard format: {$stats['to_wizard']}");
        WP_CLI::log("Migrated to Picasso format: {$stats['to_picasso']}");
        WP_CLI::log("Already synced (skipped): {$stats['skipped']}");
        WP_CLI::log("Errors: {$stats['errors']}");

        if ($dry_run) {
            WP_CLI::warning("DRY-RUN mode - no changes were made.");
        } else {
            WP_CLI::success("Migration completed.");
        }
    }

    /**
     * Migrate a single publication
     */
    private function migrate_publication(int $pub_id, string $direction, bool $dry_run): string {
        $post = get_post($pub_id);
        if (!$post) {
            return 'errors';
        }

        // Read current values
        $post_parent = (int) $post->post_parent;
        $ml_space_id = (int) get_post_meta($pub_id, '_ml_space_id', true);
        $pub_step = get_post_meta($pub_id, '_publication_step', true);
        $ml_step = get_post_meta($pub_id, '_ml_step', true);

        $needs_wizard = false;
        $needs_picasso = false;

        // Check what needs syncing
        if ($direction === 'both' || $direction === 'to-wizard') {
            if ($post_parent > 0 && $ml_space_id === 0) {
                $needs_wizard = true;
            }
            if (!empty($pub_step) && empty($ml_step)) {
                $needs_wizard = true;
            }
        }

        if ($direction === 'both' || $direction === 'to-picasso') {
            if ($ml_space_id > 0 && $post_parent === 0) {
                $needs_picasso = true;
            }
            if (!empty($ml_step) && empty($pub_step)) {
                $needs_picasso = true;
            }
        }

        if (!$needs_wizard && !$needs_picasso) {
            return 'skipped';
        }

        // Perform migration
        if (!$dry_run) {
            if ($needs_wizard) {
                if ($post_parent > 0 && $ml_space_id === 0) {
                    update_post_meta($pub_id, '_ml_space_id', $post_parent);
                }
                if (!empty($pub_step) && empty($ml_step)) {
                    update_post_meta($pub_id, '_ml_step', $pub_step);
                }
            }

            if ($needs_picasso) {
                if ($ml_space_id > 0 && $post_parent === 0) {
                    wp_update_post([
                        'ID' => $pub_id,
                        'post_parent' => $ml_space_id,
                    ]);
                }
                if (!empty($ml_step) && empty($pub_step)) {
                    update_post_meta($pub_id, '_publication_step', $ml_step);
                }
            }
        }

        return $needs_wizard ? 'to_wizard' : 'to_picasso';
    }

    /**
     * Show current schema status
     *
     * @subcommand status
     * @when after_wp_load
     */
    public function status($args, $assoc_args) {
        global $wpdb;

        WP_CLI::log("\n=== Schema Status ===\n");

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='publication'");
        WP_CLI::log("Total publications: $total");

        $with_parent = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='publication' AND post_parent > 0");
        WP_CLI::log("With post_parent (Picasso): $with_parent");

        $with_ml_space = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type='publication' AND pm.meta_key='_ml_space_id' AND pm.meta_value > 0");
        WP_CLI::log("With _ml_space_id (Wizard): $with_ml_space");

        $with_pub_step = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type='publication' AND pm.meta_key='_publication_step' AND pm.meta_value != ''");
        WP_CLI::log("With _publication_step: $with_pub_step");

        $with_ml_step = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type='publication' AND pm.meta_key='_ml_step' AND pm.meta_value != ''");
        WP_CLI::log("With _ml_step: $with_ml_step");

        $mode = Publication_Schema::get_mode();
        WP_CLI::log("\nCurrent schema mode: $mode");

        WP_CLI::log("\n=== Recommendations ===");
        if ($with_parent > $with_ml_space) {
            WP_CLI::log("Run 'wp marylink migrate --direction=to-wizard' to sync Wizard meta");
        }
        if ($with_ml_space > $with_parent) {
            WP_CLI::log("Run 'wp marylink migrate --direction=to-picasso' to sync Picasso format");
        }
        if ($with_parent === $with_ml_space && $with_parent === (int)$total) {
            WP_CLI::success("All publications are fully synced!");
        }
    }

    /**
     * Diagnose a specific publication
     *
     * @subcommand diagnose
     * @when after_wp_load
     */
    public function diagnose($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error("Please provide a publication ID");
        }

        $pub_id = (int) $args[0];
        $diag = Publication_Schema::diagnose($pub_id);

        if (isset($diag['error'])) {
            WP_CLI::error($diag['error']);
        }

        WP_CLI::log("\n=== Publication Diagnosis: {$pub_id} ===\n");
        WP_CLI::log(json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
