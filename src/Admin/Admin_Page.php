<?php
/**
 * Admin Page - MCP Status Dashboard
 *
 * Provides:
 * - System health overview
 * - Audit log viewer
 * - Rate limit monitoring
 * - Token management
 * - Diagnostics
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Admin;

use MCP_No_Headless\Ops\Health_Check;
use MCP_No_Headless\Ops\Audit_Logger;
use MCP_No_Headless\Ops\Rate_Limiter;

class Admin_Page {

    const MENU_SLUG = 'marylink-mcp-status';
    const CAPABILITY = 'manage_options';

    /**
     * Register hooks
     */
    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    /**
     * Register admin menu
     */
    public static function register_menu(): void {
        add_menu_page(
            __('MaryLink MCP', 'mcp-no-headless'),
            __('MaryLink MCP', 'mcp-no-headless'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [self::class, 'render_page'],
            'dashicons-rest-api',
            80
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Status', 'mcp-no-headless'),
            __('Status', 'mcp-no-headless'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [self::class, 'render_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Audit Logs', 'mcp-no-headless'),
            __('Audit Logs', 'mcp-no-headless'),
            self::CAPABILITY,
            self::MENU_SLUG . '-audit',
            [self::class, 'render_audit_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'mcp-no-headless'),
            __('Settings', 'mcp-no-headless'),
            self::CAPABILITY,
            self::MENU_SLUG . '-settings',
            [self::class, 'render_settings_page']
        );
    }

    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets(string $hook): void {
        if (strpos($hook, self::MENU_SLUG) === false) {
            return;
        }

        wp_enqueue_style(
            'mcpnh-admin',
            MCPNH_PLUGIN_URL . 'assets/admin.css',
            [],
            MCPNH_VERSION
        );

        wp_enqueue_script(
            'mcpnh-admin',
            MCPNH_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            MCPNH_VERSION,
            true
        );

        wp_localize_script('mcpnh-admin', 'mcpnhAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('marylink-mcp/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Render main status page
     */
    public static function render_page(): void {
        $status = Health_Check::get_status();
        $stats = Health_Check::get_dashboard_stats();

        ?>
        <div class="wrap mcpnh-admin">
            <h1><?php esc_html_e('MaryLink MCP Status', 'mcp-no-headless'); ?></h1>

            <!-- Health Status -->
            <div class="mcpnh-card mcpnh-health <?php echo $status['ok'] ? 'mcpnh-health-ok' : 'mcpnh-health-error'; ?>">
                <h2>
                    <span class="dashicons <?php echo $status['ok'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                    <?php echo $status['ok'] ? esc_html__('System Healthy', 'mcp-no-headless') : esc_html__('Issues Detected', 'mcp-no-headless'); ?>
                </h2>
                <p class="mcpnh-timestamp">
                    <?php printf(esc_html__('Last check: %s', 'mcp-no-headless'), esc_html($status['timestamp'])); ?>
                    <span class="mcpnh-latency">(<?php echo esc_html($status['latency_ms']); ?>ms)</span>
                </p>
            </div>

            <div class="mcpnh-grid">
                <!-- Version Info -->
                <div class="mcpnh-card">
                    <h3><?php esc_html_e('Version Info', 'mcp-no-headless'); ?></h3>
                    <table class="mcpnh-table">
                        <tr>
                            <td><?php esc_html_e('Plugin', 'mcp-no-headless'); ?></td>
                            <td><code><?php echo esc_html($status['version']['plugin']); ?></code></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('WordPress', 'mcp-no-headless'); ?></td>
                            <td><code><?php echo esc_html($status['version']['wordpress']); ?></code></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('PHP', 'mcp-no-headless'); ?></td>
                            <td><code><?php echo esc_html($status['version']['php']); ?></code></td>
                        </tr>
                    </table>
                </div>

                <!-- Dependencies -->
                <div class="mcpnh-card">
                    <h3><?php esc_html_e('Dependencies', 'mcp-no-headless'); ?></h3>
                    <table class="mcpnh-table">
                        <tr>
                            <td><?php esc_html_e('BuddyBoss', 'mcp-no-headless'); ?></td>
                            <td>
                                <?php if ($status['dependencies']['buddyboss']['available']): ?>
                                    <span class="mcpnh-badge mcpnh-badge-ok"><?php esc_html_e('Available', 'mcp-no-headless'); ?></span>
                                <?php else: ?>
                                    <span class="mcpnh-badge mcpnh-badge-warn"><?php esc_html_e('Not Found', 'mcp-no-headless'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Picasso', 'mcp-no-headless'); ?></td>
                            <td>
                                <?php if ($status['dependencies']['picasso']['available']): ?>
                                    <span class="mcpnh-badge mcpnh-badge-ok"><?php esc_html_e('Available', 'mcp-no-headless'); ?></span>
                                <?php else: ?>
                                    <span class="mcpnh-badge mcpnh-badge-warn"><?php esc_html_e('Not Found', 'mcp-no-headless'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('AI Engine', 'mcp-no-headless'); ?></td>
                            <td>
                                <?php if ($status['dependencies']['ai_engine']['available']): ?>
                                    <span class="mcpnh-badge mcpnh-badge-ok"><?php esc_html_e('Available', 'mcp-no-headless'); ?></span>
                                <?php else: ?>
                                    <span class="mcpnh-badge mcpnh-badge-error"><?php esc_html_e('Required', 'mcp-no-headless'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Database -->
                <div class="mcpnh-card">
                    <h3><?php esc_html_e('Database', 'mcp-no-headless'); ?></h3>
                    <table class="mcpnh-table">
                        <tr>
                            <td><?php esc_html_e('Audit Table', 'mcp-no-headless'); ?></td>
                            <td>
                                <?php if ($status['database']['tables']['audit']['exists']): ?>
                                    <span class="mcpnh-badge mcpnh-badge-ok"><?php esc_html_e('OK', 'mcp-no-headless'); ?></span>
                                    <span class="mcpnh-small">(<?php echo number_format($status['database']['tables']['audit']['rows']); ?> rows)</span>
                                <?php else: ?>
                                    <span class="mcpnh-badge mcpnh-badge-error"><?php esc_html_e('Missing', 'mcp-no-headless'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Cron Status -->
                <div class="mcpnh-card">
                    <h3><?php esc_html_e('Cron Jobs', 'mcp-no-headless'); ?></h3>
                    <?php if ($status['cron']['wp_cron_disabled']): ?>
                        <p class="mcpnh-warning"><?php esc_html_e('WP Cron is disabled!', 'mcp-no-headless'); ?></p>
                    <?php endif; ?>
                    <table class="mcpnh-table">
                        <?php foreach ($status['cron']['jobs'] as $hook => $info): ?>
                        <tr>
                            <td><code><?php echo esc_html(str_replace('mcpnh_', '', $hook)); ?></code></td>
                            <td>
                                <?php if ($info['scheduled']): ?>
                                    <span class="mcpnh-badge mcpnh-badge-ok"><?php echo esc_html($info['next_run_human']); ?></span>
                                <?php else: ?>
                                    <span class="mcpnh-badge mcpnh-badge-warn"><?php esc_html_e('Not scheduled', 'mcp-no-headless'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <!-- Rate Limits -->
                <div class="mcpnh-card">
                    <h3><?php esc_html_e('Rate Limits', 'mcp-no-headless'); ?></h3>
                    <table class="mcpnh-table">
                        <tr>
                            <td><?php esc_html_e('Global Usage', 'mcp-no-headless'); ?></td>
                            <td>
                                <span class="mcpnh-progress">
                                    <span class="mcpnh-progress-bar" style="width: <?php echo esc_attr($status['rate_limits']['global']['utilization']); ?>"></span>
                                </span>
                                <?php echo esc_html($status['rate_limits']['global']['current']); ?> / <?php echo esc_html($status['rate_limits']['global']['limit']); ?>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" class="button mcpnh-btn-reset-rates" data-all="true">
                            <?php esc_html_e('Reset All Rate Limits', 'mcp-no-headless'); ?>
                        </button>
                    </p>
                </div>

                <!-- Cache -->
                <div class="mcpnh-card">
                    <h3><?php esc_html_e('Cache', 'mcp-no-headless'); ?></h3>
                    <table class="mcpnh-table">
                        <tr>
                            <td><?php esc_html_e('Object Cache', 'mcp-no-headless'); ?></td>
                            <td>
                                <?php if ($status['cache']['object_cache']): ?>
                                    <span class="mcpnh-badge mcpnh-badge-ok"><?php echo esc_html(ucfirst($status['cache']['object_cache_type'])); ?></span>
                                <?php else: ?>
                                    <span class="mcpnh-badge mcpnh-badge-warn"><?php esc_html_e('Database', 'mcp-no-headless'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Stats Cards -->
            <h2><?php esc_html_e('Activity (Last 24h)', 'mcp-no-headless'); ?></h2>
            <div class="mcpnh-stats-grid">
                <div class="mcpnh-stat-card">
                    <div class="mcpnh-stat-value"><?php echo number_format($stats['last_24h']['total_requests']); ?></div>
                    <div class="mcpnh-stat-label"><?php esc_html_e('Total Requests', 'mcp-no-headless'); ?></div>
                </div>
                <div class="mcpnh-stat-card">
                    <div class="mcpnh-stat-value"><?php echo number_format($stats['last_24h']['active_users']); ?></div>
                    <div class="mcpnh-stat-label"><?php esc_html_e('Active Users', 'mcp-no-headless'); ?></div>
                </div>
                <div class="mcpnh-stat-card">
                    <div class="mcpnh-stat-value"><?php echo esc_html($stats['last_24h']['error_rate']); ?>%</div>
                    <div class="mcpnh-stat-label"><?php esc_html_e('Error Rate', 'mcp-no-headless'); ?></div>
                </div>
                <div class="mcpnh-stat-card">
                    <div class="mcpnh-stat-value"><?php echo number_format($stats['last_24h']['avg_latency_ms']); ?>ms</div>
                    <div class="mcpnh-stat-label"><?php esc_html_e('Avg Latency', 'mcp-no-headless'); ?></div>
                </div>
            </div>

            <!-- Top Tools -->
            <?php if (!empty($stats['last_24h']['top_tools'])): ?>
            <div class="mcpnh-card">
                <h3><?php esc_html_e('Top Tools (24h)', 'mcp-no-headless'); ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Tool', 'mcp-no-headless'); ?></th>
                            <th><?php esc_html_e('Calls', 'mcp-no-headless'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['last_24h']['top_tools'] as $tool): ?>
                        <tr>
                            <td><code><?php echo esc_html($tool['tool_name']); ?></code></td>
                            <td><?php echo number_format($tool['count']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Recent Errors -->
            <?php if (!empty($stats['recent_errors'])): ?>
            <div class="mcpnh-card mcpnh-card-error">
                <h3><?php esc_html_e('Recent Errors', 'mcp-no-headless'); ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'mcp-no-headless'); ?></th>
                            <th><?php esc_html_e('Tool', 'mcp-no-headless'); ?></th>
                            <th><?php esc_html_e('Error', 'mcp-no-headless'); ?></th>
                            <th><?php esc_html_e('Debug ID', 'mcp-no-headless'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['recent_errors'] as $error): ?>
                        <tr>
                            <td><?php echo esc_html($error['timestamp']); ?></td>
                            <td><code><?php echo esc_html($error['tool_name']); ?></code></td>
                            <td><?php echo esc_html($error['error_code']); ?></td>
                            <td><code><?php echo esc_html($error['debug_id']); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="mcpnh-card">
                <h3><?php esc_html_e('Actions', 'mcp-no-headless'); ?></h3>
                <p>
                    <button type="button" class="button button-primary mcpnh-btn-recalc-scores">
                        <?php esc_html_e('Recalculate Scores', 'mcp-no-headless'); ?>
                    </button>
                    <button type="button" class="button mcpnh-btn-run-diagnostics">
                        <?php esc_html_e('Run Diagnostics', 'mcp-no-headless'); ?>
                    </button>
                </p>
                <div id="mcpnh-action-result"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render audit logs page
     */
    public static function render_audit_page(): void {
        $filters = [];
        if (!empty($_GET['user_id'])) {
            $filters['user_id'] = (int) $_GET['user_id'];
        }
        if (!empty($_GET['tool_name'])) {
            $filters['tool_name'] = sanitize_text_field($_GET['tool_name']);
        }
        if (!empty($_GET['result'])) {
            $filters['result'] = sanitize_text_field($_GET['result']);
        }

        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        $logs = Audit_Logger::get_logs($filters, $per_page, $offset);

        ?>
        <div class="wrap mcpnh-admin">
            <h1><?php esc_html_e('MCP Audit Logs', 'mcp-no-headless'); ?></h1>

            <!-- Filters -->
            <div class="mcpnh-card">
                <form method="get" class="mcpnh-filters">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>-audit">

                    <label>
                        <?php esc_html_e('User ID:', 'mcp-no-headless'); ?>
                        <input type="number" name="user_id" value="<?php echo esc_attr($filters['user_id'] ?? ''); ?>">
                    </label>

                    <label>
                        <?php esc_html_e('Tool:', 'mcp-no-headless'); ?>
                        <input type="text" name="tool_name" value="<?php echo esc_attr($filters['tool_name'] ?? ''); ?>" placeholder="ml_*">
                    </label>

                    <label>
                        <?php esc_html_e('Result:', 'mcp-no-headless'); ?>
                        <select name="result">
                            <option value=""><?php esc_html_e('All', 'mcp-no-headless'); ?></option>
                            <option value="success" <?php selected($filters['result'] ?? '', 'success'); ?>><?php esc_html_e('Success', 'mcp-no-headless'); ?></option>
                            <option value="error" <?php selected($filters['result'] ?? '', 'error'); ?>><?php esc_html_e('Error', 'mcp-no-headless'); ?></option>
                            <option value="denied" <?php selected($filters['result'] ?? '', 'denied'); ?>><?php esc_html_e('Denied', 'mcp-no-headless'); ?></option>
                            <option value="rate_limited" <?php selected($filters['result'] ?? '', 'rate_limited'); ?>><?php esc_html_e('Rate Limited', 'mcp-no-headless'); ?></option>
                        </select>
                    </label>

                    <button type="submit" class="button"><?php esc_html_e('Filter', 'mcp-no-headless'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '-audit')); ?>" class="button"><?php esc_html_e('Clear', 'mcp-no-headless'); ?></a>
                </form>
            </div>

            <!-- Logs Table -->
            <table class="widefat striped mcpnh-audit-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Timestamp', 'mcp-no-headless'); ?></th>
                        <th><?php esc_html_e('User', 'mcp-no-headless'); ?></th>
                        <th><?php esc_html_e('Tool', 'mcp-no-headless'); ?></th>
                        <th><?php esc_html_e('Target', 'mcp-no-headless'); ?></th>
                        <th><?php esc_html_e('Result', 'mcp-no-headless'); ?></th>
                        <th><?php esc_html_e('Latency', 'mcp-no-headless'); ?></th>
                        <th><?php esc_html_e('Debug ID', 'mcp-no-headless'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7"><?php esc_html_e('No logs found.', 'mcp-no-headless'); ?></td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr class="mcpnh-log-<?php echo esc_attr($log['result']); ?>">
                            <td><?php echo esc_html($log['timestamp']); ?></td>
                            <td>
                                <?php
                                $user = get_user_by('id', $log['user_id']);
                                echo esc_html($user ? $user->display_name : '#' . $log['user_id']);
                                ?>
                            </td>
                            <td><code><?php echo esc_html($log['tool_name']); ?></code></td>
                            <td>
                                <?php if ($log['target_type']): ?>
                                    <?php echo esc_html($log['target_type']); ?>#<?php echo esc_html($log['target_id']); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="mcpnh-badge mcpnh-badge-<?php echo esc_attr($log['result']); ?>">
                                    <?php echo esc_html($log['result']); ?>
                                </span>
                                <?php if ($log['error_code']): ?>
                                    <br><small><?php echo esc_html($log['error_code']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $log['latency_ms'] ? esc_html($log['latency_ms']) . 'ms' : '-'; ?>
                            </td>
                            <td><code class="mcpnh-debug-id"><?php echo esc_html($log['debug_id']); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if (count($logs) >= $per_page): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $base_url = admin_url('admin.php?page=' . self::MENU_SLUG . '-audit');
                    foreach ($filters as $key => $value) {
                        $base_url = add_query_arg($key, $value, $base_url);
                    }
                    ?>
                    <?php if ($page > 1): ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg('paged', $page - 1, $base_url)); ?>">&laquo; <?php esc_html_e('Previous', 'mcp-no-headless'); ?></a>
                    <?php endif; ?>
                    <span class="paging-input"><?php printf(esc_html__('Page %d', 'mcp-no-headless'), $page); ?></span>
                    <a class="button" href="<?php echo esc_url(add_query_arg('paged', $page + 1, $base_url)); ?>"><?php esc_html_e('Next', 'mcp-no-headless'); ?> &raquo;</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render settings page
     */
    public static function render_settings_page(): void {
        if (isset($_POST['mcpnh_settings_nonce']) && wp_verify_nonce($_POST['mcpnh_settings_nonce'], 'mcpnh_save_settings')) {
            update_option('mcpnh_audit_retention_days', (int) $_POST['audit_retention_days']);
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'mcp-no-headless') . '</p></div>';
        }

        $retention_days = (int) get_option('mcpnh_audit_retention_days', 30);

        ?>
        <div class="wrap mcpnh-admin">
            <h1><?php esc_html_e('MCP Settings', 'mcp-no-headless'); ?></h1>

            <form method="post">
                <?php wp_nonce_field('mcpnh_save_settings', 'mcpnh_settings_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="audit_retention_days"><?php esc_html_e('Audit Log Retention', 'mcp-no-headless'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="audit_retention_days" id="audit_retention_days"
                                   value="<?php echo esc_attr($retention_days); ?>" min="1" max="365" class="small-text">
                            <?php esc_html_e('days', 'mcp-no-headless'); ?>
                            <p class="description">
                                <?php esc_html_e('Audit logs older than this will be automatically deleted.', 'mcp-no-headless'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('REST API Endpoints', 'mcp-no-headless'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Endpoint', 'mcp-no-headless'); ?></th>
                            <th><?php esc_html_e('Method', 'mcp-no-headless'); ?></th>
                            <th><?php esc_html_e('Access', 'mcp-no-headless'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>/wp-json/marylink-mcp/v1/health</code></td>
                            <td>GET</td>
                            <td><?php esc_html_e('Public', 'mcp-no-headless'); ?></td>
                        </tr>
                        <tr>
                            <td><code>/wp-json/marylink-mcp/v1/health/full</code></td>
                            <td>GET</td>
                            <td><?php esc_html_e('Admin', 'mcp-no-headless'); ?></td>
                        </tr>
                        <tr>
                            <td><code>/wp-json/marylink-mcp/v1/audit</code></td>
                            <td>GET</td>
                            <td><?php esc_html_e('Admin', 'mcp-no-headless'); ?></td>
                        </tr>
                        <tr>
                            <td><code>/wp-json/marylink-mcp/v1/recalculate-scores</code></td>
                            <td>POST</td>
                            <td><?php esc_html_e('Admin', 'mcp-no-headless'); ?></td>
                        </tr>
                        <tr>
                            <td><code>/wp-json/marylink-mcp/v1/rate-limits</code></td>
                            <td>GET</td>
                            <td><?php esc_html_e('Logged in', 'mcp-no-headless'); ?></td>
                        </tr>
                        <tr>
                            <td><code>/wp-json/marylink-mcp/v1/token</code></td>
                            <td>GET</td>
                            <td><?php esc_html_e('Logged in', 'mcp-no-headless'); ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
