<?php
/**
 * ml_me - User context and profile tool
 *
 * Provides comprehensive user context: profile, spaces, feedback, audit, stats, jobs, quotas.
 * Supports multiple aspects in single call.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Tools;

use MCP_No_Headless\MCP\Core\Tool_Response;
use MCP_No_Headless\MCP\Core\Services\Job_Manager;
use MCP_No_Headless\Ops\Audit_Logger;

class Me {

    const TOOL_NAME = 'ml_me';
    const VERSION = '3.2.7';

    const ASPECT_PROFILE = 'profile';
    const ASPECT_SPACES = 'spaces';
    const ASPECT_CONTEXT = 'context';
    const ASPECT_FEEDBACK = 'feedback';
    const ASPECT_AUDIT = 'audit';
    const ASPECT_STATS = 'stats';
    const ASPECT_JOBS = 'jobs';
    const ASPECT_QUOTAS = 'quotas';
    const ASPECT_LABELS = 'labels';
    const ASPECT_ALL = 'all';

    const VALID_ASPECTS = [
        self::ASPECT_PROFILE,
        self::ASPECT_SPACES,
        self::ASPECT_CONTEXT,
        self::ASPECT_FEEDBACK,
        self::ASPECT_AUDIT,
        self::ASPECT_STATS,
        self::ASPECT_JOBS,
        self::ASPECT_QUOTAS,
        self::ASPECT_LABELS,
        self::ASPECT_ALL,
    ];

    /**
     * Execute ml_me
     *
     * @param array $args Tool arguments
     * @param int $user_id Current user ID
     * @return array User context data
     */
    public static function execute(array $args, int $user_id): array {
        if ($user_id <= 0) {
            return Tool_Response::auth_error('Authentification requise pour ml_me');
        }

        // Accept both 'action' (catalog) and 'aspect' (legacy)
        $aspects = $args['action'] ?? $args['aspect'] ?? [self::ASPECT_PROFILE];
        if (is_string($aspects)) {
            $aspects = [$aspects];
        }

        // Alias: accept action=job (singulier) -> jobs + job_id from id
        if (in_array('job', $aspects, true)) {
            if (empty($args['job_id']) && !empty($args['id'])) {
                $args['job_id'] = $args['id'];
            }
            $aspects = array_map(function($a) {
                return ($a === 'job') ? self::ASPECT_JOBS : $a;
            }, $aspects);
        }


        // Handle "all" aspect
        if (in_array(self::ASPECT_ALL, $aspects)) {
            $aspects = array_diff(self::VALID_ASPECTS, [self::ASPECT_ALL, self::ASPECT_FEEDBACK]);
        }

        // Validate aspects
        foreach ($aspects as $aspect) {
            if (!in_array($aspect, self::VALID_ASPECTS)) {
                return Tool_Response::validation_error(
                    "Aspect invalide: $aspect",
                    ['aspect' => "Valeurs valides: " . implode(', ', self::VALID_ASPECTS)]
                );
            }
        }

        // Handle feedback submission separately
        if (in_array(self::ASPECT_FEEDBACK, $aspects)) {
            // Contract compat: accept 'comment' as alias for 'feedback_text'
            if (empty($args['feedback_text']) && !empty($args['comment'])) {
                $args['feedback_text'] = $args['comment'];
            }

            // If caller provided nothing, do NOT fail hard: return usage guidance (keeps agents stable)
            if (empty($args['feedback_text']) && empty($args['feedback_type']) && empty($args['execution_id'])) {
                return [
                    'success' => true,
                    'message' => 'Feedback endpoint ready. Provide feedback_type (positive|negative) and optional comment/feedback_text. Optionally provide execution_id.',
                    'usage' => [
                        'action' => 'feedback',
                        'feedback_type' => 'positive|negative',
                        'comment' => '(optional) short explanation',
                        'execution_id' => '(optional) execution identifier to attach feedback to',
                    ],
                ];
            }

            // If only a thumb is provided, accept it (no minimum length required)
            if (empty($args['feedback_text']) && !empty($args['feedback_type'])) {
                $args['feedback_text'] = ($args['feedback_type'] === 'positive') ? '👍' : '👎';
            }

            return self::submit_feedback($user_id, $args);
        }

        $result = [
            'success' => true,
            'user_id' => $user_id,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        // Fetch requested aspects
        foreach ($aspects as $aspect) {
            $result[$aspect] = match ($aspect) {
                self::ASPECT_PROFILE => self::get_profile($user_id),
                self::ASPECT_SPACES => self::get_spaces($user_id, $args),
                self::ASPECT_CONTEXT => self::get_context($user_id, $args),
                self::ASPECT_AUDIT => self::get_audit($user_id, $args),
                self::ASPECT_STATS => self::get_stats($user_id, $args),
                self::ASPECT_JOBS => self::get_jobs($user_id, $args),
                                self::ASPECT_QUOTAS => self::get_quotas($user_id),
                self::ASPECT_LABELS => self::get_labels($args),
                default => null,
            };
        }

        return $result;
    }

    // =========================================================================
    // PROFILE
    // =========================================================================

    private static function get_profile(int $user_id): array {
        $user = get_userdata($user_id);

        if (!$user) {
            return ['error' => 'User not found'];
        }

        $profile = [
            'id' => $user_id,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'registered' => $user->user_registered,
            'roles' => $user->roles,
            'avatar_url' => get_avatar_url($user_id),
            'locale' => get_user_locale($user_id),
        ];

        // Capabilities
        $profile['capabilities'] = [
            'is_admin' => user_can($user_id, 'manage_options'),
            'can_publish' => user_can($user_id, 'publish_posts'),
            'mcp_access' => self::has_mcp_access($user_id),
        ];

        // BuddyPress profile fields
        if (function_exists('bp_get_profile_field_data')) {
            $profile['bp_fields'] = [];
            $fields = ['Bio', 'Location', 'Job Title', 'Company'];
            foreach ($fields as $field) {
                $value = bp_get_profile_field_data(['field' => $field, 'user_id' => $user_id]);
                if ($value) {
                    $profile['bp_fields'][$field] = $value;
                }
            }
        }

        // User preferences
        $profile['preferences'] = [
            'default_space' => get_user_meta($user_id, 'ml_default_space', true),
            'notification_email' => get_user_meta($user_id, 'ml_notification_email', true) !== 'no',
            'theme' => get_user_meta($user_id, 'ml_theme', true) ?: 'auto',
        ];

        // Space allowed labels (for default space)
        $default_space = (int) get_user_meta($user_id, 'ml_default_space', true);
        if ($default_space) {
            $label_ids = get_post_meta($default_space, '_space_labels', true) ?: [];
            $default_label_id = get_post_meta($default_space, '_space_default_label', true);
            $space_labels = [];
            foreach ($label_ids as $lid) {
                $term = get_term($lid, 'publication_label');
                if ($term && !is_wp_error($term)) {
                    $space_labels[] = [
                        'slug' => $term->slug,
                        'name' => $term->name,
                        'is_default' => ($lid == $default_label_id),
                    ];
                }
            }
            $profile['default_space_info'] = [
                'id' => $default_space,
                'name' => get_the_title($default_space),
                'allowed_labels' => $space_labels,
            ];
        }

        return $profile;
    }

    private static function has_mcp_access(int $user_id): bool {
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        $allowed_roles = get_option('mcpnh_allowed_roles', ['administrator', 'editor']);
        $user = get_userdata($user_id);

        return $user && !empty(array_intersect($user->roles, $allowed_roles));
    }

    // =========================================================================
    // SPACES (Marylink spaces - WordPress post type 'space')
    // =========================================================================

    private static function get_spaces(int $user_id, array $args): array {
        global $wpdb;

        $limit = min((int) ($args['limit'] ?? 20), 50);
        $filter = $args['filter'] ?? 'subscribed';

        // Build query args for WordPress post type 'space'
        $query_args = [
            'post_type' => 'space',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        switch ($filter) {
            case 'all':
                // All public spaces
                break;

            case 'owned':
                // Spaces created by user
                $query_args['author'] = $user_id;
                break;

            case 'recent':
                // Recently modified spaces user has access to
                $query_args['posts_per_page'] = min($limit, 10);
                $query_args['orderby'] = 'modified';
                // TODO: Filter by user membership when membership system is implemented
                break;

            case 'subscribed':
            default:
                // Spaces user is member of (moderator, champion, expert, or creator)
                // Get space IDs where user is in any role
                $space_ids = self::get_user_space_ids($user_id);
                if (empty($space_ids)) {
                    return [
                        'filter' => $filter,
                        'count' => 0,
                        'total' => 0,
                        'items' => [],
                    ];
                }
                $query_args['post__in'] = $space_ids;
                break;
        }

        $query = new \WP_Query($query_args);

        $spaces = [];
        foreach ($query->posts as $post) {
            $category = get_post_meta($post->ID, '_space_category', true) ?: 'partage';
            $moderators = get_post_meta($post->ID, '_space_moderators', true) ?: [];
            $champions = get_post_meta($post->ID, '_space_champions', true) ?: [];
            $experts = get_post_meta($post->ID, '_space_experts', true) ?: [];

            $spaces[] = [
                'id' => (int) $post->ID,
                'name' => $post->post_title,
                'slug' => $post->post_name,
                'category' => $category,
                'description' => $post->post_excerpt ?: wp_trim_words($post->post_content, 20),
                'role' => self::get_user_space_role($user_id, $post->ID, $post->post_author, $moderators, $champions, $experts),
                'is_creator' => ((int) $post->post_author === $user_id),
                'created_at' => $post->post_date,
                'modified_at' => $post->post_modified,
            ];
        }

        return [
            'filter' => $filter,
            'count' => count($spaces),
            'total' => (int) $query->found_posts,
            'items' => $spaces,
        ];
    }

    /**
     * Get space IDs where user has a role
     */
    private static function get_user_space_ids(int $user_id): array {
        global $wpdb;

        // Admins see all spaces
        $user = get_user_by('ID', $user_id);
        if ($user && user_can($user, 'manage_options')) {
            return $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'space' AND post_status = 'publish'"
            );
        }

        // Get spaces where user is author
        $owned = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'space' AND post_status = 'publish' AND post_author = %d",
            $user_id
        ));

        // Get spaces where user is in moderators, champions, or experts meta
        // Meta values are serialized arrays, so we search for the user ID pattern
        $user_pattern = sprintf('%%i:%d;%%', $user_id); // Serialized array pattern
        $user_pattern_alt = sprintf('%%"%d"%%', $user_id); // JSON array pattern

        $member_spaces = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_space_moderators', '_space_champions', '_space_experts')
             AND (meta_value LIKE %s OR meta_value LIKE %s)",
            $user_pattern, $user_pattern_alt
        ));

        return array_unique(array_merge($owned, $member_spaces));
    }

    /**
     * Get user's role in a space
     */
    private static function get_user_space_role(int $user_id, int $space_id, int $author_id, array $moderators, array $champions, array $experts): string {
        if ($user_id === $author_id) {
            return 'owner';
        }
        if (in_array($user_id, $moderators)) {
            return 'moderator';
        }
        if (in_array($user_id, $champions)) {
            return 'champion';
        }
        if (in_array($user_id, $experts)) {
            return 'expert';
        }
        return 'member';
    }

    // =========================================================================
    // CONTEXT
    // =========================================================================

    private static function get_context(int $user_id, array $args): array {
        $context = [
            'current_space' => null,
            'recent_publications' => [],
            'recent_tools' => [],
            'active_conversations' => [],
        ];

        // Current space from session or args (Marylink space post type)
        $space_id = $args['space_id'] ?? get_user_meta($user_id, 'ml_current_space', true);
        if ($space_id) {
            $space = get_post($space_id);
            if ($space && $space->post_type === 'space' && $space->post_status === 'publish') {
                $moderators = get_post_meta($space_id, '_space_moderators', true) ?: [];
                $champions = get_post_meta($space_id, '_space_champions', true) ?: [];
                $experts = get_post_meta($space_id, '_space_experts', true) ?: [];

                $context['current_space'] = [
                    'id' => (int) $space->ID,
                    'name' => $space->post_title,
                    'category' => get_post_meta($space_id, '_space_category', true) ?: 'partage',
                    'role' => self::get_user_space_role($user_id, $space_id, $space->post_author, $moderators, $champions, $experts),
                ];
            }
        }

        // Recent publications
        global $wpdb;
        $recent_pubs = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_date FROM {$wpdb->posts}
             WHERE post_author = %d AND post_type = 'ml_publication' AND post_status = 'publish'
             ORDER BY post_date DESC LIMIT 5",
            $user_id
        ));

        foreach ($recent_pubs as $pub) {
            $context['recent_publications'][] = [
                'id' => (int) $pub->ID,
                'title' => $pub->post_title,
                'date' => $pub->post_date,
            ];
        }

        // Recent tools used
        $recent_tools = get_user_meta($user_id, 'ml_recent_tools', true) ?: [];
        $context['recent_tools'] = array_slice($recent_tools, 0, 5);

        // Active conversations (chatbot)
        $active_convs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, chatbot_id, title, updated_at FROM {$wpdb->prefix}ml_conversations
             WHERE user_id = %d AND status = 'active'
             ORDER BY updated_at DESC LIMIT 3",
            $user_id
        ));

        foreach ($active_convs as $conv) {
            $context['active_conversations'][] = [
                'id' => (int) $conv->id,
                'chatbot_id' => (int) $conv->chatbot_id,
                'title' => $conv->title,
                'updated_at' => $conv->updated_at,
            ];
        }

        return $context;
    }

    // =========================================================================
    // FEEDBACK
    // =========================================================================

    private static function submit_feedback(int $user_id, array $args): array {
        // PR6: Flywheel - if tool_id is provided, record as rating
        if (!empty($args['tool_id'])) {
            $tool_id = (int) $args['tool_id'];
            $rating = isset($args['rating']) ? (int) $args['rating'] : null;
            $thumbs = ($args['feedback_type'] ?? '') === 'positive' ? 'up' : (($args['feedback_type'] ?? '') === 'negative' ? 'down' : null);
            $comment = $args['feedback_text'] ?? $args['comment'] ?? null;
            
            if (class_exists(\MCP_No_Headless\Services\Feedback_Service::class)) {
                $result = \MCP_No_Headless\Services\Feedback_Service::record_rating($tool_id, $user_id, $rating, $thumbs, $comment);
                return $result;
            }
        }

        $feedback_text = sanitize_textarea_field($args['feedback_text']);
        $feedback_type = sanitize_text_field($args['feedback_type'] ?? 'general');
        $context_data = $args['context'] ?? [];

        // Accept short feedback (e.g., 👍/👎). Do not enforce minimum length here.
        if (strlen(trim($feedback_text)) === 0 && !empty($feedback_type)) {
            $feedback_text = ($feedback_type === 'positive') ? '👍' : (($feedback_type === 'negative') ? '👎' : '');
        }

        // Attach execution_id into context if provided
        if (!empty($args['execution_id'])) {
            $context_data['execution_id'] = sanitize_text_field($args['execution_id']);
        }
global $wpdb;
        $table = $wpdb->prefix . 'ml_feedback';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            // Fallback: send email
            $admin_email = get_option('admin_email');
            $subject = "[MaryLink Feedback] $feedback_type from user #$user_id";
            $message = "User ID: $user_id\nType: $feedback_type\n\nFeedback:\n$feedback_text\n\nContext: " . wp_json_encode($context_data);
            wp_mail($admin_email, $subject, $message);

            return [
                'success' => true,
                'message' => 'Feedback submitted via email',
            ];
        }

        $result = $wpdb->insert($table, [
            'user_id' => $user_id,
            'type' => $feedback_type,
            'content' => $feedback_text,
            'context' => wp_json_encode($context_data),
            'created_at' => current_time('mysql'),
        ]);

        if ($result === false) {
            // Fallback: send email rather than failing hard (keeps agent tests stable)
            $admin_email = get_option('admin_email');
            $subject = "[MaryLink Feedback:FALLBACK] $feedback_type from user #$user_id";
            $message = "User ID: $user_id
Type: $feedback_type

Feedback:
$feedback_text

Context: " . wp_json_encode($context_data) . "

DB error: " . ($wpdb->last_error ?? '');
            wp_mail($admin_email, $subject, $message);

            return [
                'success' => true,
                'message' => 'Feedback submitted via email (DB insert failed)',
                'warning' => 'db_insert_failed',
            ];
        }

        return [
            'success' => true,
            'feedback_id' => $wpdb->insert_id,
            'message' => 'Thank you for your feedback!',
        ];
    }

    // =========================================================================
    // AUDIT
    // =========================================================================

    private static function get_audit(int $user_id, array $args): array {
        // Check if user can view audit logs
        if (!user_can($user_id, 'manage_options')) {
            // Regular users can only see their own logs
            $args['filters']['user_id'] = $user_id;
        }

        $filters = $args['filters'] ?? [];
        $filters['user_id'] = $filters['user_id'] ?? $user_id;

        $limit = min((int) ($args['limit'] ?? 20), 100);

        if (class_exists(Audit_Logger::class)) {
            $logs = Audit_Logger::get_logs($filters, $limit);

            return [
                'count' => count($logs),
                'items' => array_map(function ($log) {
                    return [
                        'debug_id' => $log['debug_id'],
                        'timestamp' => $log['timestamp'],
                        'tool_name' => $log['tool_name'],
                        'result' => $log['result'],
                        'latency_ms' => (int) $log['latency_ms'],
                        'error_code' => $log['error_code'],
                    ];
                }, $logs),
            ];
        }

        return [
            'count' => 0,
            'items' => [],
            'notice' => 'Audit logging not enabled',
        ];
    }

    // =========================================================================
    // STATS
    // =========================================================================

    private static function get_stats(int $user_id, array $args): array {
        global $wpdb;

        $period = $args['period'] ?? '30d';
        $since = match ($period) {
            '24h' => date('Y-m-d H:i:s', strtotime('-24 hours')),
            '7d' => date('Y-m-d H:i:s', strtotime('-7 days')),
            '30d' => date('Y-m-d H:i:s', strtotime('-30 days')),
            '90d' => date('Y-m-d H:i:s', strtotime('-90 days')),
            default => date('Y-m-d H:i:s', strtotime('-30 days')),
        };

        $stats = [
            'period' => $period,
            'since' => $since,
        ];

        // Publications count
        $stats['publications'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_author = %d AND post_type = 'ml_publication' AND post_status = 'publish'
             AND post_date >= %s",
            $user_id, $since
        ));

        // AI usage
        $ai_table = $wpdb->prefix . 'marylink_ia_usage';
        if ($wpdb->get_var("SHOW TABLES LIKE '$ai_table'") === $ai_table) {
            $ai_usage = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) as calls, SUM(tokens_used) as tokens, SUM(cost) as cost
                 FROM $ai_table WHERE user_id = %d AND created_at >= %s",
                $user_id, $since
            ));

            $stats['ai_usage'] = [
                'calls' => (int) ($ai_usage->calls ?? 0),
                'tokens' => (int) ($ai_usage->tokens ?? 0),
                'cost' => round((float) ($ai_usage->cost ?? 0), 4),
            ];
        }

        // Tool usage breakdown
        $audit_table = $wpdb->prefix . 'mcpnh_audit';
        if ($wpdb->get_var("SHOW TABLES LIKE '$audit_table'") === $audit_table) {
            $tool_usage = $wpdb->get_results($wpdb->prepare(
                "SELECT tool_name, COUNT(*) as count, AVG(latency_ms) as avg_latency
                 FROM $audit_table WHERE user_id = %d AND timestamp >= %s
                 GROUP BY tool_name ORDER BY count DESC LIMIT 10",
                $user_id, $since
            ), ARRAY_A);

            $stats['tool_usage'] = $tool_usage;
        }

        // Activity stats (BuddyPress)
        if (function_exists('bp_activity_get')) {
            $activity_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bp_activity
                 WHERE user_id = %d AND date_recorded >= %s",
                $user_id, $since
            ));
            $stats['activities'] = (int) $activity_count;
        }

        // Comments
        $stats['comments'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments}
             WHERE user_id = %d AND comment_date >= %s AND comment_approved = '1'",
            $user_id, $since
        ));

        return $stats;
    }

    // =========================================================================
    // JOBS
    // =========================================================================

    private static function get_jobs(int $user_id, array $args): array {
        // PR4: Use Job_Manager service if available
        if (class_exists(Job_Manager::class)) {
            $job_id = $args['job_id'] ?? null;

            // Single job lookup by ID
            if ($job_id) {
                $job = Job_Manager::get_status($job_id, $user_id);
                if (!$job) {
                    return [
                        'count' => 0,
                        'items' => [],
                        'error' => 'Job not found',
                    ];
                }
                return [
                    'count' => 1,
                    'items' => [$job],
                    'job' => $job,
                ];
            }

            // List user's jobs
            $status_filter = $args['status'] ?? null;
            $limit = min((int) ($args['limit'] ?? 10), 50);

            $jobs = Job_Manager::get_user_jobs($user_id, $status_filter, $limit);

            $summary = Job_Manager::get_user_summary($user_id);

            return [
                'count' => count($jobs),
                'summary' => $summary,
                'items' => $jobs,
            ];
        }

        // Fallback: legacy implementation
        global $wpdb;

        $table = $wpdb->prefix . 'mcpnh_jobs';

        // Check if jobs table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return [
                'count' => 0,
                'items' => [],
                'notice' => 'Async jobs not enabled',
            ];
        }

        $status_filter = $args['status'] ?? null;
        $limit = min((int) ($args['limit'] ?? 10), 50);

        $where = "user_id = %d";
        $params = [$user_id];

        if ($status_filter) {
            $where .= " AND status = %s";
            $params[] = $status_filter;
        }

        $params[] = $limit;

        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, job_id, tool_name, status, progress, created_at, started_at, completed_at, error
             FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d",
            $params
        ), ARRAY_A);

        $items = array_map(function ($job) {
            return [
                'id' => (int) $job['id'],
                'job_id' => $job['job_id'],
                'tool_name' => $job['tool_name'],
                'status' => $job['status'],
                'progress' => (int) $job['progress'],
                'created_at' => $job['created_at'],
                'started_at' => $job['started_at'],
                'completed_at' => $job['completed_at'],
                'error' => $job['error'],
            ];
        }, $jobs);

        // Summary
        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(status = 'pending') as pending,
                SUM(status = 'running') as running,
                SUM(status = 'completed') as completed,
                SUM(status = 'failed') as failed
             FROM $table WHERE user_id = %d",
            $user_id
        ), ARRAY_A);

        return [
            'count' => count($items),
            'summary' => [
                'total' => (int) ($summary['total'] ?? 0),
                'pending' => (int) ($summary['pending'] ?? 0),
                'running' => (int) ($summary['running'] ?? 0),
                'completed' => (int) ($summary['completed'] ?? 0),
                'failed' => (int) ($summary['failed'] ?? 0),
            ],
            'items' => $items,
        ];
    }

    // =========================================================================
    // QUOTAS
    // =========================================================================

    private static function get_quotas(int $user_id): array {
        $quotas = [
            'ai' => self::get_ai_quota($user_id),
            'rate_limit' => self::get_rate_limit_status($user_id),
            'storage' => self::get_storage_quota($user_id),
        ];

        // Overall status
        $warnings = [];
        foreach ($quotas as $name => $quota) {
            if (isset($quota['used_percent']) && $quota['used_percent'] > 80) {
                $warnings[] = $name;
            }
        }

        $quotas['status'] = empty($warnings) ? 'ok' : 'warning';
        $quotas['warnings'] = $warnings;

        return $quotas;
    }

    private static function get_ai_quota(int $user_id): array {
        global $wpdb;

        $daily_limit = (int) get_option('mcpnh_daily_token_limit', 100000);
        $monthly_limit = (int) get_option('mcpnh_monthly_token_limit', 2000000);

        $table = $wpdb->prefix . 'marylink_ia_usage';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return [
                'daily_limit' => $daily_limit,
                'monthly_limit' => $monthly_limit,
                'daily_used' => 0,
                'monthly_used' => 0,
                'used_percent' => 0,
            ];
        }

        $today = date('Y-m-d');
        $month_start = date('Y-m-01');

        $daily_used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(tokens_used), 0) FROM $table
             WHERE user_id = %d AND DATE(created_at) = %s",
            $user_id, $today
        ));

        $monthly_used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(tokens_used), 0) FROM $table
             WHERE user_id = %d AND created_at >= %s",
            $user_id, $month_start
        ));

        return [
            'daily_limit' => $daily_limit,
            'monthly_limit' => $monthly_limit,
            'daily_used' => $daily_used,
            'monthly_used' => $monthly_used,
            'daily_remaining' => max(0, $daily_limit - $daily_used),
            'monthly_remaining' => max(0, $monthly_limit - $monthly_used),
            'used_percent' => $monthly_limit > 0 ? round(($monthly_used / $monthly_limit) * 100, 1) : 0,
        ];
    }

    private static function get_rate_limit_status(int $user_id): array {
        // Prefer Rate_Limiter if available (newer installs)
        if (class_exists('\\MCP_No_Headless\\Ops\\Rate_Limiter')) {
            if (method_exists('\\MCP_No_Headless\\Ops\\Rate_Limiter', 'get_user_status')) {
                return \MCP_No_Headless\Ops\Rate_Limiter::get_user_status($user_id);
            }
            if (method_exists('\\MCP_No_Headless\\Ops\\Rate_Limiter', 'get_user_stats')) {
                return \MCP_No_Headless\Ops\Rate_Limiter::get_user_stats($user_id);
            }
        }

        // Fallback defaults
        $window = 60; // 1 minute
        $max_requests = (int) get_option('mcpnh_rate_limit_requests', 60);

        return [
            'success' => true,
            'window_seconds' => $window,
            'max_requests' => $max_requests,
            'remaining' => $max_requests,
            'reset_at' => gmdate('c', time() + $window),
            'used_percent' => 0,
        ];
    }


    private static function get_storage_quota(int $user_id): array {
        global $wpdb;

        // Calculate user's media storage
        $storage_used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(meta_value), 0) FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_author = %d AND p.post_type = 'attachment' AND pm.meta_key = '_wp_attachment_metadata'",
            $user_id
        ));

        // In bytes, convert from serialized metadata
        // This is approximate - actual implementation would parse the metadata
        $storage_limit = (int) get_option('mcpnh_user_storage_limit', 100 * 1024 * 1024); // 100MB default

        $attachments_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'attachment'",
            $user_id
        ));

        return [
            'limit_bytes' => $storage_limit,
            'used_bytes' => $storage_used,
            'attachments_count' => $attachments_count,
            'used_percent' => $storage_limit > 0 ? round(($storage_used / $storage_limit) * 100, 1) : 0,
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Return error response (delegates to Tool_Response)
     */
    private static function error(string $code, string $message): array {
        return Tool_Response::error($code, $message);
    }

    // =========================================================================
    // LABELS (publication_label taxonomy)
    // =========================================================================

    /**
     * List available publication labels.
     * Helps agents avoid inventing labels and polluting taxonomies.
     */
    private static function get_labels(array $args = []): array {
        $space_id = isset($args['space_id']) ? (int) $args['space_id'] : null;

        $terms = get_terms([
            'taxonomy' => 'publication_label',
            'hide_empty' => false,
        ]);

        $labels = [];
        foreach (($terms ?: []) as $t) {
            $labels[] = [
                'id' => (int) $t->term_id,
                'slug' => (string) $t->slug,
                'name' => (string) $t->name,
                'count' => (int) ($t->count ?? 0),
            ];
        }

        usort($labels, function($a, $b) {
            return strcmp($a['slug'], $b['slug']);
        });

        $core = ['prompt', 'tool', 'content', 'style', 'client', 'projet'];
        $blocked = ['data', 'doc'];

        $policy = [
            'mode' => 'open',
            'space_id' => $space_id,
            'meta_key' => null,
            'allowed_labels' => null,
        ];

        if ($space_id) {
            $raw = null;

            if (function_exists('groups_get_groupmeta')) {
                $raw = groups_get_groupmeta($space_id, 'ml_allowed_labels', true);
                $policy['meta_key'] = 'ml_allowed_labels';
                if (empty($raw)) {
                    $raw = groups_get_groupmeta($space_id, 'ml_space_allowed_labels', true);
                    $policy['meta_key'] = 'ml_space_allowed_labels';
                }
            } else {
                $raw = get_option('ml_space_allowed_labels_' . (int) $space_id);
                $policy['meta_key'] = 'option:ml_space_allowed_labels_{space_id}';
            }

            $vals = [];
            if (is_array($raw)) {
                $vals = $raw;
            } elseif (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $vals = $decoded;
                } else {
                    $vals = preg_split('/\s*,\s*/', $raw);
                }
            }

            $allowed = [];
            foreach (($vals ?: []) as $v) {
                $slug = sanitize_text_field((string) $v);
                $slug = strtolower(trim($slug));
                if ($slug === '') { continue; }
                $allowed[] = $slug;
            }

            $allowed = array_values(array_unique($allowed));
            if (!empty($allowed)) {
                $policy['mode'] = 'restricted';
                $policy['allowed_labels'] = array_values(array_unique(array_merge($core, $allowed)));
            }
        }

        return [
            'labels' => $labels,
            'core_labels' => $core,
            'blocked_labels' => $blocked,
            'space_policy' => $policy,
        ];
    }

}