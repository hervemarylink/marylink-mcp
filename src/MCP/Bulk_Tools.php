<?php
/**
 * Bulk Tools - MCP tools for bulk operations (tool-map v1)
 *
 * Tools:
 * - ml_bulk_apply_tool: Apply a tool/prompt to multiple publications
 *
 * TICKET T3.1: Bulk operations
 * Features:
 * - Prepare/commit flow for bulk safety
 * - Plan-based limits (max items, delays)
 * - Progress tracking
 * - Partial success handling
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Ops\Rate_Limiter;
use MCP_No_Headless\Services\Render_Service;
use MCP_No_Headless\Picasso\Picasso_Adapter;

class Bulk_Tools {

    /**
     * Session prefix for bulk operations
     */
    private const SESSION_PREFIX = 'mcpnh_bulk_';

    /**
     * Session TTL in seconds (10 minutes for bulk)
     */
    private const SESSION_TTL = 600;

    /**
     * Delay between items in milliseconds
     */
    private const ITEM_DELAY_MS = 500;

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_bulk_apply_tool' => [
                'name' => 'ml_bulk_apply_tool',
                'description' => 'Apply a tool/prompt to multiple publications in batch. Use stage=prepare to preview, stage=commit to execute.',
                'category' => 'MaryLink Bulk',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'tool_id' => [
                            'type' => 'integer',
                            'description' => 'ID of the tool/prompt publication to apply',
                        ],
                        'publication_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Array of publication IDs to apply the tool to',
                        ],
                        'stage' => [
                            'type' => 'string',
                            'enum' => ['prepare', 'commit'],
                            'description' => 'Stage: prepare (validate & preview) or commit (execute)',
                            'default' => 'prepare',
                        ],
                        'session_id' => [
                            'type' => 'string',
                            'description' => 'Session ID from prepare stage (required for commit)',
                        ],
                        'options' => [
                            'type' => 'object',
                            'description' => 'Bulk operation options',
                            'properties' => [
                                'stop_on_error' => [
                                    'type' => 'boolean',
                                    'description' => 'Stop processing on first error (default: false)',
                                    'default' => false,
                                ],
                                'dry_run' => [
                                    'type' => 'boolean',
                                    'description' => 'Simulate without making changes (default: false)',
                                    'default' => false,
                                ],
                                'output_format' => [
                                    'type' => 'string',
                                    'enum' => ['full', 'summary'],
                                    'description' => 'Result format: full (all details) or summary (counts only)',
                                    'default' => 'summary',
                                ],
                            ],
                        ],
                    ],
                    'required' => ['tool_id', 'publication_ids'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => true,
                ],
            ],
        ];
    }

    /**
     * Execute a bulk tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();
        $permissions = new Permission_Checker($user_id);

        if ($user_id <= 0) {
            return Tool_Response::error('authentication_required', 'You must be logged in for bulk operations.', $request_id);
        }

        switch ($tool) {
            case 'ml_bulk_apply_tool':
                return self::handle_bulk_apply_tool($args, $user_id, $permissions, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_bulk_apply_tool
     */
    private static function handle_bulk_apply_tool(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['tool_id', 'publication_ids']);
        if ($validation) {
            return $validation;
        }

        $tool_id = (int) $args['tool_id'];
        $publication_ids = array_map('intval', $args['publication_ids']);
        $stage = $args['stage'] ?? 'prepare';
        $options = $args['options'] ?? [];

        // Validate publication_ids is not empty
        if (empty($publication_ids)) {
            return Tool_Response::error('validation_failed', 'publication_ids cannot be empty.', $request_id);
        }

        // Check tool access
        if (!$permissions->can_use_tool($tool_id)) {
            return Tool_Response::error('access_denied', 'Cannot access the specified tool.', $request_id);
        }

        // Check bulk limits
        $bulk_check = Rate_Limiter::check_bulk_limits($user_id, count($publication_ids));
        if (!$bulk_check['allowed']) {
            return Tool_Response::error(
                'bulk_limit_exceeded',
                $bulk_check['message'] ?? 'Bulk operation limit exceeded for your plan.',
                $request_id,
                ['limit' => $bulk_check['limit'] ?? 0, 'requested' => count($publication_ids)]
            );
        }

        if ($stage === 'prepare') {
            return self::prepare_bulk_apply($tool_id, $publication_ids, $options, $user_id, $permissions, $request_id);
        } elseif ($stage === 'commit') {
            return self::commit_bulk_apply($tool_id, $publication_ids, $args, $user_id, $permissions, $request_id);
        } else {
            return Tool_Response::error('validation_failed', 'Invalid stage. Use "prepare" or "commit".', $request_id);
        }
    }

    /**
     * Prepare bulk apply - validate and preview
     */
    private static function prepare_bulk_apply(int $tool_id, array $publication_ids, array $options, int $user_id, Permission_Checker $permissions, string $request_id): array {
        // Get tool info
        $tool_post = get_post($tool_id);
        if (!$tool_post || $tool_post->post_type !== 'publication') {
            return Tool_Response::error('not_found', 'Tool not found.', $request_id);
        }

        // Validate each publication
        $valid_items = [];
        $invalid_items = [];

        foreach ($publication_ids as $pub_id) {
            $pub = get_post($pub_id);

            if (!$pub || $pub->post_type !== 'publication') {
                $invalid_items[] = [
                    'id' => $pub_id,
                    'reason' => 'not_found',
                    'message' => 'Publication not found',
                ];
                continue;
            }

            if (!$permissions->can_see_publication($pub_id)) {
                $invalid_items[] = [
                    'id' => $pub_id,
                    'reason' => 'access_denied',
                    'message' => 'No access to publication',
                ];
                continue;
            }

            $valid_items[] = [
                'id' => $pub_id,
                'title' => $pub->post_title,
                'type' => self::get_publication_type($pub_id),
                'space_id' => (int) $pub->post_parent,
            ];
        }

        // Create session
        $session_data = [
            'user_id' => $user_id,
            'tool_id' => $tool_id,
            'publication_ids' => array_column($valid_items, 'id'),
            'options' => $options,
            'created_at' => time(),
        ];

        $session_id = self::create_session($session_data);

        // Estimate time
        $estimated_time_sec = count($valid_items) * (self::ITEM_DELAY_MS / 1000 + 2); // 500ms delay + ~2s per item

        return Tool_Response::ok([
            'stage' => 'prepare',
            'session_id' => $session_id,
            'expires_in' => self::SESSION_TTL,
            'preview' => [
                'tool' => [
                    'id' => $tool_id,
                    'title' => $tool_post->post_title,
                    'type' => self::get_publication_type($tool_id),
                ],
                'items' => [
                    'valid' => $valid_items,
                    'invalid' => $invalid_items,
                    'valid_count' => count($valid_items),
                    'invalid_count' => count($invalid_items),
                ],
                'options' => [
                    'stop_on_error' => (bool) ($options['stop_on_error'] ?? false),
                    'dry_run' => (bool) ($options['dry_run'] ?? false),
                    'output_format' => $options['output_format'] ?? 'summary',
                ],
                'estimated_time_seconds' => (int) $estimated_time_sec,
            ],
            'next_action' => count($valid_items) > 0 ? [
                'tool' => 'ml_bulk_apply_tool',
                'args' => [
                    'tool_id' => $tool_id,
                    'publication_ids' => array_column($valid_items, 'id'),
                    'stage' => 'commit',
                    'session_id' => $session_id,
                ],
                'hint' => sprintf('Call commit to apply tool to %d publications.', count($valid_items)),
            ] : null,
            'warnings' => count($invalid_items) > 0 ? [
                sprintf('%d publications will be skipped due to access or not found errors.', count($invalid_items)),
            ] : [],
        ], $request_id);
    }

    /**
     * Commit bulk apply - execute the bulk operation
     */
    private static function commit_bulk_apply(int $tool_id, array $publication_ids, array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        // Validate session
        $session_id = $args['session_id'] ?? '';
        $session = self::validate_session($session_id, $user_id);

        if (!$session) {
            return Tool_Response::error(
                'session_expired',
                'Session expired or invalid. Please run prepare stage again.',
                $request_id
            );
        }

        // Validate session matches
        if (($session['tool_id'] ?? 0) !== $tool_id) {
            return Tool_Response::error(
                'session_mismatch',
                'Session tool_id does not match request.',
                $request_id
            );
        }

        $options = $session['options'] ?? [];
        $stop_on_error = (bool) ($options['stop_on_error'] ?? false);
        $dry_run = (bool) ($options['dry_run'] ?? false);
        $output_format = $options['output_format'] ?? 'summary';

        // Use validated IDs from session
        $valid_ids = $session['publication_ids'] ?? [];

        // Execute bulk operation
        $results = [
            'success' => [],
            'failed' => [],
            'skipped' => [],
        ];

        $start_time = microtime(true);
        $processed_count = 0;

        foreach ($valid_ids as $index => $pub_id) {
            // Add delay between items (except first)
            if ($index > 0) {
                usleep(self::ITEM_DELAY_MS * 1000);
            }

            $processed_count++;

            try {
                if ($dry_run) {
                    // Simulate success for dry run
                    $results['success'][] = [
                        'id' => $pub_id,
                        'status' => 'simulated',
                        'message' => 'Dry run - no changes made',
                    ];
                } else {
                    // Apply the tool
                    $apply_result = self::apply_tool_to_publication($tool_id, $pub_id, $user_id);

                    if ($apply_result['success']) {
                        $results['success'][] = [
                            'id' => $pub_id,
                            'status' => 'completed',
                            'output' => $output_format === 'full' ? ($apply_result['output'] ?? null) : null,
                        ];
                    } else {
                        $results['failed'][] = [
                            'id' => $pub_id,
                            'status' => 'error',
                            'error' => $apply_result['error'] ?? 'Unknown error',
                        ];

                        if ($stop_on_error) {
                            // Mark remaining as skipped
                            $remaining = array_slice($valid_ids, $index + 1);
                            foreach ($remaining as $skip_id) {
                                $results['skipped'][] = [
                                    'id' => $skip_id,
                                    'reason' => 'stopped_on_error',
                                ];
                            }
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'id' => $pub_id,
                    'status' => 'exception',
                    'error' => $e->getMessage(),
                ];

                if ($stop_on_error) {
                    $remaining = array_slice($valid_ids, $index + 1);
                    foreach ($remaining as $skip_id) {
                        $results['skipped'][] = [
                            'id' => $skip_id,
                            'reason' => 'stopped_on_error',
                        ];
                    }
                    break;
                }
            }
        }

        $total_time_ms = (int) ((microtime(true) - $start_time) * 1000);

        // Clean up session
        self::cleanup_session($session_id);

        // Build response
        $response = [
            'stage' => 'commit',
            'success' => true,
            'summary' => [
                'total_items' => count($valid_ids),
                'processed' => $processed_count,
                'success_count' => count($results['success']),
                'failed_count' => count($results['failed']),
                'skipped_count' => count($results['skipped']),
                'total_time_ms' => $total_time_ms,
                'dry_run' => $dry_run,
            ],
            'tool' => [
                'id' => $tool_id,
                'title' => get_the_title($tool_id),
            ],
        ];

        // Include details based on format
        if ($output_format === 'full') {
            $response['results'] = $results;
        } else {
            // Summary format - just counts and failed IDs
            if (!empty($results['failed'])) {
                $response['failed_ids'] = array_column($results['failed'], 'id');
                $response['failed_errors'] = array_map(function ($item) {
                    return ['id' => $item['id'], 'error' => $item['error'] ?? $item['status']];
                }, $results['failed']);
            }
        }

        return Tool_Response::ok($response, $request_id);
    }

    /**
     * Apply a tool to a single publication
     */
    private static function apply_tool_to_publication(int $tool_id, int $publication_id, int $user_id): array {
        // Get tool content/instruction
        $tool_instruction = Picasso_Adapter::get_tool_instruction($tool_id);
        if (empty($tool_instruction)) {
            return [
                'success' => false,
                'error' => 'Tool has no instruction content',
            ];
        }

        // Get publication content
        $publication = get_post($publication_id);
        if (!$publication) {
            return [
                'success' => false,
                'error' => 'Publication not found',
            ];
        }

        // For now, we simulate applying the tool by creating a meta record
        // In production, this would call an AI endpoint or process the content
        $apply_record = [
            'tool_id' => $tool_id,
            'applied_at' => current_time('mysql'),
            'applied_by' => $user_id,
        ];

        // Store application record
        $existing = get_post_meta($publication_id, '_ml_tool_applications', true);
        $applications = is_array($existing) ? $existing : [];
        $applications[] = $apply_record;
        update_post_meta($publication_id, '_ml_tool_applications', $applications);

        return [
            'success' => true,
            'output' => [
                'publication_id' => $publication_id,
                'tool_applied' => $tool_id,
                'timestamp' => $apply_record['applied_at'],
            ],
        ];
    }

    /**
     * Get publication type
     */
    private static function get_publication_type(int $publication_id): string {
        $type = get_post_meta($publication_id, '_ml_publication_type', true);
        return $type ?: 'publication';
    }

    /**
     * Create session for prepare/commit flow
     */
    private static function create_session(array $data): string {
        $session_id = 'bulk_' . bin2hex(random_bytes(16));
        set_transient(self::SESSION_PREFIX . $session_id, $data, self::SESSION_TTL);
        return $session_id;
    }

    /**
     * Validate session
     */
    private static function validate_session(string $session_id, int $user_id): ?array {
        if (empty($session_id) || strpos($session_id, 'bulk_') !== 0) {
            return null;
        }

        $session = get_transient(self::SESSION_PREFIX . $session_id);
        if (!$session || !is_array($session)) {
            return null;
        }

        if (($session['user_id'] ?? 0) !== $user_id) {
            return null;
        }

        return $session;
    }

    /**
     * Clean up session
     */
    private static function cleanup_session(string $session_id): void {
        delete_transient(self::SESSION_PREFIX . $session_id);
    }

    /**
     * Check if available
     */
    public static function is_available(): bool {
        return post_type_exists('publication');
    }
}
