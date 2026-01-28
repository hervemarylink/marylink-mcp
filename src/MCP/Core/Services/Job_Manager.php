<?php
/**
 * Job Manager - Async job handling
 *
 * Manages async job queuing, processing, and status tracking.
 * Supports webhooks for completion notification.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Services;

use MCP_No_Headless\MCP\Core\Tools\Run;

class Job_Manager {

    const VERSION = '3.0.0';
    const TABLE_NAME = 'mcpnh_jobs';

    // Job statuses
    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    // Job types
    const TYPE_RUN = 'run';
    const TYPE_BATCH = 'batch';
    const TYPE_CHAIN = 'chain';
    const TYPE_EXPORT = 'export';

    // Default settings
    const DEFAULT_TIMEOUT = 300;     // 5 minutes
    const DEFAULT_RETRIES = 3;
    const CLEANUP_DAYS = 7;

    /**
     * Create a new job
     *
     * @param string $type Job type
     * @param array $args Job arguments
     * @param int $user_id User ID
     * @param array $options Job options
     * @return array Job info
     */
    public static function create(string $type, array $args, int $user_id, array $options = []): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists
        if (!self::table_exists()) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'table_missing',
                    'message' => 'Jobs table not found. Run plugin activation.',
                ],
            ];
        }

        $job_id = wp_generate_uuid4();

        $job_data = [
            'job_id' => $job_id,
            'user_id' => $user_id,
            'type' => $type,
            'tool_name' => $args['tool_name'] ?? $type,
            'args' => wp_json_encode($args),
            'status' => self::STATUS_PENDING,
            'progress' => 0,
            'priority' => (int) ($options['priority'] ?? 5),
            'timeout' => (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT),
            'max_retries' => (int) ($options['max_retries'] ?? self::DEFAULT_RETRIES),
            'retries' => 0,
            'webhook_url' => $options['webhook_url'] ?? null,
            'created_at' => current_time('mysql'),
            'scheduled_at' => $options['scheduled_at'] ?? current_time('mysql'),
        ];

        $result = $wpdb->insert($table, $job_data);

        if ($result === false) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'insert_failed',
                    'message' => 'Failed to create job: ' . $wpdb->last_error,
                ],
            ];
        }

        // Schedule processing
        if (empty($options['scheduled_at']) || strtotime($options['scheduled_at']) <= time()) {
            self::schedule_processing();
        } else {
            wp_schedule_single_event(strtotime($options['scheduled_at']), 'mcpnh_process_scheduled_job', [$job_id]);
        }

        return [
            'success' => true,
            'job_id' => $job_id,
            'status' => self::STATUS_PENDING,
            'message' => 'Job queued for processing',
        ];
    }

    /**
     * Get job status
     *
     * @param string $job_id Job ID
     * @param int $user_id User ID (for access check)
     * @return array Job status
     */
    public static function get_status(string $job_id, int $user_id): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE job_id = %s",
            $job_id
        ), ARRAY_A);

        if (!$job) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'not_found',
                    'message' => "Job '$job_id' not found",
                ],
            ];
        }

        // Check access
        if ((int) $job['user_id'] !== $user_id && !user_can($user_id, 'manage_options')) {
            return [
                'success' => false,
                'error' => [
                    'code' => 'access_denied',
                    'message' => 'You cannot access this job',
                ],
            ];
        }

        $response = [
            'success' => true,
            'job_id' => $job['job_id'],
            'type' => $job['type'],
            'tool_name' => $job['tool_name'],
            'status' => $job['status'],
            'progress' => (int) $job['progress'],
            'created_at' => $job['created_at'],
            'started_at' => $job['started_at'],
            'completed_at' => $job['completed_at'],
        ];

        // Include result for completed jobs
        if ($job['status'] === self::STATUS_COMPLETED && !empty($job['result'])) {
            $response['result'] = json_decode($job['result'], true);
        }

        // Include error for failed jobs
        if ($job['status'] === self::STATUS_FAILED && !empty($job['error'])) {
            $response['error'] = $job['error'];
            $response['retries'] = (int) $job['retries'];
        }

        return $response;
    }

    /**
     * Get job result (waits if not ready)
     *
     * @param string $job_id Job ID
     * @param int $user_id User ID
     * @param int $timeout Max wait time in seconds
     * @return array Job result
     */
    public static function get_result(string $job_id, int $user_id, int $timeout = 30): array {
        $start = time();

        while (time() - $start < $timeout) {
            $status = self::get_status($job_id, $user_id);

            if (!$status['success']) {
                return $status;
            }

            if (in_array($status['status'], [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED])) {
                return $status;
            }

            // Wait 1 second before checking again
            sleep(1);
        }

        return [
            'success' => true,
            'job_id' => $job_id,
            'status' => 'still_running',
            'message' => 'Job is still running. Check back later.',
        ];
    }

    /**
     * Cancel a job
     *
     * @param string $job_id Job ID
     * @param int $user_id User ID
     * @return array Result
     */
    public static function cancel(string $job_id, int $user_id): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // Check ownership and status
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, status FROM $table WHERE job_id = %s",
            $job_id
        ));

        if (!$job) {
            return [
                'success' => false,
                'error' => ['code' => 'not_found', 'message' => 'Job not found'],
            ];
        }

        if ((int) $job->user_id !== $user_id && !user_can($user_id, 'manage_options')) {
            return [
                'success' => false,
                'error' => ['code' => 'access_denied', 'message' => 'Cannot cancel this job'],
            ];
        }

        if (!in_array($job->status, [self::STATUS_PENDING, self::STATUS_RUNNING])) {
            return [
                'success' => false,
                'error' => ['code' => 'invalid_status', 'message' => 'Job cannot be cancelled'],
            ];
        }

        $wpdb->update($table, [
            'status' => self::STATUS_CANCELLED,
            'completed_at' => current_time('mysql'),
        ], ['job_id' => $job_id]);

        return [
            'success' => true,
            'job_id' => $job_id,
            'status' => self::STATUS_CANCELLED,
        ];
    }

    /**
     * Process pending jobs (called by cron)
     *
     * @param int $limit Max jobs to process
     */
    public static function process_pending(int $limit = 5): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // Get pending jobs ordered by priority and creation time
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE status = %s AND scheduled_at <= %s
             ORDER BY priority DESC, created_at ASC
             LIMIT %d",
            self::STATUS_PENDING, current_time('mysql'), $limit
        ), ARRAY_A);

        foreach ($jobs as $job) {
            self::process_job($job);
        }
    }

    /**
     * Process a single job
     */
    private static function process_job(array $job): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $job_id = $job['job_id'];

        // Mark as running
        $wpdb->update($table, [
            'status' => self::STATUS_RUNNING,
            'started_at' => current_time('mysql'),
        ], ['job_id' => $job_id]);

        try {
            // Set timeout
            set_time_limit((int) $job['timeout']);

            $args = json_decode($job['args'], true);
            $user_id = (int) $job['user_id'];

            // Execute based on type
            $result = match ($job['type']) {
                self::TYPE_RUN => self::execute_run_job($args, $user_id, $job_id),
                self::TYPE_BATCH => self::execute_batch_job($args, $user_id, $job_id),
                self::TYPE_CHAIN => self::execute_chain_job($args, $user_id, $job_id),
                self::TYPE_EXPORT => self::execute_export_job($args, $user_id, $job_id),
                default => ['success' => false, 'error' => 'Unknown job type'],
            };

            // Mark as completed
            $wpdb->update($table, [
                'status' => $result['success'] ? self::STATUS_COMPLETED : self::STATUS_FAILED,
                'progress' => 100,
                'result' => wp_json_encode($result),
                'error' => $result['success'] ? null : ($result['error']['message'] ?? 'Unknown error'),
                'completed_at' => current_time('mysql'),
            ], ['job_id' => $job_id]);

            // Call webhook if configured
            if (!empty($job['webhook_url'])) {
                self::call_webhook($job['webhook_url'], $job_id, $result);
            }

        } catch (\Exception $e) {
            // Handle failure
            $retries = (int) $job['retries'] + 1;
            $max_retries = (int) $job['max_retries'];

            if ($retries < $max_retries) {
                // Retry later
                $wpdb->update($table, [
                    'status' => self::STATUS_PENDING,
                    'retries' => $retries,
                    'error' => $e->getMessage(),
                    'scheduled_at' => date('Y-m-d H:i:s', time() + (60 * pow(2, $retries))), // Exponential backoff
                ], ['job_id' => $job_id]);
            } else {
                // Mark as failed
                $wpdb->update($table, [
                    'status' => self::STATUS_FAILED,
                    'retries' => $retries,
                    'error' => $e->getMessage(),
                    'completed_at' => current_time('mysql'),
                ], ['job_id' => $job_id]);

                // Call webhook with failure
                if (!empty($job['webhook_url'])) {
                    self::call_webhook($job['webhook_url'], $job_id, [
                        'success' => false,
                        'error' => ['message' => $e->getMessage()],
                    ]);
                }
            }
        }
    }

    /**
     * Execute a run job
     */
    private static function execute_run_job(array $args, int $user_id, string $job_id): array {
        // Remove mode to execute synchronously
        unset($args['mode']);

        return Run::execute($args, $user_id);
    }

    /**
     * Execute a batch job
     */
    private static function execute_batch_job(array $args, int $user_id, string $job_id): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $source_ids = $args['source_ids'] ?? [];
        $tool_id = $args['tool_id'] ?? null;
        $prompt = $args['prompt'] ?? null;

        if (empty($source_ids)) {
            return ['success' => false, 'error' => ['message' => 'No source_ids provided']];
        }

        $results = [];
        $total = count($source_ids);

        foreach ($source_ids as $index => $source_id) {
            // Update progress
            $progress = (int) (($index / $total) * 100);
            $wpdb->update($table, ['progress' => $progress], ['job_id' => $job_id]);

            // Execute for this source
            $run_args = [
                'source_id' => (int) $source_id,
                'tool_id' => $tool_id,
                'prompt' => $prompt,
            ];

            $result = Run::execute($run_args, $user_id);

            $results[] = [
                'source_id' => $source_id,
                'success' => $result['success'],
                'output' => $result['output'] ?? null,
                'error' => $result['error'] ?? null,
            ];
        }

        $successful = count(array_filter($results, fn($r) => $r['success']));

        return [
            'success' => true,
            'batch_results' => $results,
            'summary' => [
                'total' => $total,
                'successful' => $successful,
                'failed' => $total - $successful,
            ],
        ];
    }

    /**
     * Execute a chain job
     */
    private static function execute_chain_job(array $args, int $user_id, string $job_id): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $chain = $args['chain'] ?? [];
        $input = $args['input'] ?? null;
        $source_id = $args['source_id'] ?? null;

        if (empty($chain)) {
            return ['success' => false, 'error' => ['message' => 'No chain provided']];
        }

        $current_input = $input;
        $current_source = $source_id;
        $total = count($chain);
        $step_results = [];

        foreach ($chain as $index => $step) {
            // Update progress
            $progress = (int) (($index / $total) * 100);
            $wpdb->update($table, ['progress' => $progress], ['job_id' => $job_id]);

            // Build step args
            $step_args = [
                'tool_id' => $step['tool_id'] ?? null,
                'prompt' => $step['prompt'] ?? null,
            ];

            if ($current_input !== null) {
                $step_args['input'] = $current_input;
            } elseif ($current_source !== null) {
                $step_args['source_id'] = $current_source;
            }

            $result = Run::execute($step_args, $user_id);

            $step_results[] = [
                'step' => $index + 1,
                'success' => $result['success'],
                'output_preview' => substr($result['output'] ?? '', 0, 200),
            ];

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => ['message' => "Chain failed at step " . ($index + 1)],
                    'step_results' => $step_results,
                ];
            }

            // Pass output to next step
            $current_input = $result['output'];
            $current_source = null;
        }

        return [
            'success' => true,
            'output' => $current_input,
            'chain_length' => $total,
            'step_results' => $step_results,
        ];
    }

    /**
     * Execute an export job
     */
    private static function execute_export_job(array $args, int $user_id, string $job_id): array {
        $export_type = $args['export_type'] ?? 'publications';
        $filters = $args['filters'] ?? [];
        $format = $args['format'] ?? 'json';

        // This would export data based on type
        // Placeholder implementation
        return [
            'success' => true,
            'message' => 'Export completed',
            'format' => $format,
            'export_type' => $export_type,
        ];
    }

    /**
     * Call webhook URL
     */
    private static function call_webhook(string $url, string $job_id, array $result): void {
        $payload = [
            'event' => 'job_completed',
            'job_id' => $job_id,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'result' => $result,
        ];

        wp_remote_post($url, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-MCP-Webhook' => 'job-notification',
            ],
            'body' => wp_json_encode($payload),
        ]);
    }

    /**
     * Schedule job processing
     */
    private static function schedule_processing(): void {
        if (!wp_next_scheduled('mcpnh_process_jobs')) {
            wp_schedule_single_event(time(), 'mcpnh_process_jobs');
        }
    }

    /**
     * Cleanup old jobs
     *
     * @param int $days Days to retain
     * @return int Number of deleted jobs
     */
    public static function cleanup(int $days = null): int {
        global $wpdb;

        $days = $days ?? self::CLEANUP_DAYS;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE status IN (%s, %s, %s) AND completed_at < %s",
            self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED, $cutoff
        ));

        return $deleted !== false ? $deleted : 0;
    }

    /**
     * Get user's jobs
     */
    public static function get_user_jobs(int $user_id, ?string $status = null, int $limit = 20): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $where = "user_id = %d";
        $params = [$user_id];

        if ($status) {
            $where .= " AND status = %s";
            $params[] = $status;
        }

        $params[] = $limit;

        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT job_id, type, tool_name, status, progress, created_at, started_at, completed_at, error
             FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d",
            $params
        ), ARRAY_A);

        return $jobs ?: [];
    }


    /**
     * Get a lightweight summary of a user's async jobs.
     *
     * Used by ml_me(action=jobs) for stable counters and quick diagnostics.
     * Must be defensive: never throw.
     */
    public static function get_user_summary(int $user_id): array {
        global $wpdb;

        $summary = [
            'total' => 0,
            'by_status' => [],
            'pending' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'last_days' => self::CLEANUP_DAYS,
            'last_days_total' => 0,
            'latest' => null,
        ];

        try {
            $table = $wpdb->prefix . self::TABLE_NAME;

            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT status, COUNT(*) AS c FROM $table WHERE user_id = %d GROUP BY status", $user_id),
                ARRAY_A
            );

            $total = 0;
            $by_status = [];
            foreach (($rows ?: []) as $r) {
                $st = (string) ($r['status'] ?? '');
                $c  = (int) ($r['c'] ?? 0);
                if ($st === '') { continue; }
                $by_status[$st] = $c;
                $total += $c;
            }

            $summary['total'] = $total;
            $summary['by_status'] = $by_status;
            $summary['pending'] = (int) ($by_status[self::STATUS_PENDING] ?? 0);
            $summary['running'] = (int) ($by_status[self::STATUS_RUNNING] ?? 0);
            $summary['completed'] = (int) ($by_status[self::STATUS_COMPLETED] ?? 0);
            $summary['failed'] = (int) ($by_status[self::STATUS_FAILED] ?? 0);
            $summary['cancelled'] = (int) ($by_status[self::STATUS_CANCELLED] ?? 0);

            $latest = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT job_id, status, type, tool_name, progress, retries, created_at, started_at, completed_at
                     FROM $table WHERE user_id = %d ORDER BY created_at DESC LIMIT 1",
                    $user_id
                ),
                ARRAY_A
            );
            if (!empty($latest)) {
                $summary['latest'] = $latest;
            }

            $days = max(1, (int) self::CLEANUP_DAYS);
            $cnt = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE user_id = %d AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                    $user_id, $days
                )
            );
            $summary['last_days_total'] = (int) ($cnt ?? 0);

        } catch (\Throwable $e) {
            $summary['error'] = $e->getMessage();
        }

        return $summary;
    }

    /**
     * Create jobs table
     */
    public static function create_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id VARCHAR(36) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(50) NOT NULL,
            tool_name VARCHAR(100) DEFAULT NULL,
            args LONGTEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
            priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
            timeout INT UNSIGNED NOT NULL DEFAULT 300,
            max_retries TINYINT UNSIGNED NOT NULL DEFAULT 3,
            retries TINYINT UNSIGNED NOT NULL DEFAULT 0,
            result LONGTEXT DEFAULT NULL,
            error TEXT DEFAULT NULL,
            webhook_url VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            scheduled_at DATETIME NOT NULL,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_id (job_id),
            KEY idx_user_status (user_id, status),
            KEY idx_status_scheduled (status, scheduled_at),
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
     * Register cron hooks
     */
    public static function register_hooks(): void {
        add_action('mcpnh_process_jobs', [self::class, 'process_pending']);
        add_action('mcpnh_process_scheduled_job', [self::class, 'process_scheduled_job']);
        add_action('mcpnh_cleanup_jobs', [self::class, 'cleanup']);

        // Schedule daily cleanup
        if (!wp_next_scheduled('mcpnh_cleanup_jobs')) {
            wp_schedule_event(time(), 'daily', 'mcpnh_cleanup_jobs');
        }
    }

    /**
     * Process a specific scheduled job
     */
    public static function process_scheduled_job(string $job_id): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE job_id = %s AND status = %s",
            $job_id, self::STATUS_PENDING
        ), ARRAY_A);

        if ($job) {
            self::process_job($job);
        }
    }
}
