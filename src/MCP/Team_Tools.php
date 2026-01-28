<?php
/**
 * Team Tools - MCP tools for publication team management (tool-map v1)
 *
 * Tools:
 * - ml_get_team: Get team members for a publication
 * - ml_manage_team: Add/remove team members (prepare/commit)
 *
 * TICKET T4.1: Team management
 * Features:
 * - View team composition (author, co-authors, experts, reviewers)
 * - Add/remove team members with prepare/commit flow
 * - Role-based permissions
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Picasso\Picasso_Adapter;

class Team_Tools {

    /**
     * Session prefix for team operations
     */
    private const SESSION_PREFIX = 'mcpnh_team_';

    /**
     * Session TTL in seconds (5 minutes)
     */
    private const SESSION_TTL = 300;

    /**
     * Team roles and their meta keys
     */
    private const ROLE_META_KEYS = [
        'co_author' => '_publication_co_author',
        'team_member' => '_in_publication_team',
        'expert' => '_publication_expert',
        'invited' => '_user_invited_to_team',
    ];

    /**
     * Get tool definitions for registration
     */
    public static function get_definitions(): array {
        return [
            'ml_get_team' => [
                'name' => 'ml_get_team',
                'description' => 'Get team members for a publication (author, co-authors, experts, reviewers).',
                'category' => 'MaryLink Team',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID of the publication',
                        ],
                        'include_permissions' => [
                            'type' => 'boolean',
                            'description' => 'Include each member\'s permissions (default: false)',
                            'default' => false,
                        ],
                    ],
                    'required' => ['publication_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                ],
            ],

            'ml_manage_team' => [
                'name' => 'ml_manage_team',
                'description' => 'Add or remove team members from a publication. Use stage=prepare to preview, stage=commit to execute.',
                'category' => 'MaryLink Team',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'publication_id' => [
                            'type' => 'integer',
                            'description' => 'ID of the publication',
                        ],
                        'action' => [
                            'type' => 'string',
                            'enum' => ['add', 'remove'],
                            'description' => 'Action to perform',
                        ],
                        'user_id' => [
                            'type' => 'integer',
                            'description' => 'User ID to add/remove',
                        ],
                        'role' => [
                            'type' => 'string',
                            'enum' => ['co_author', 'team_member', 'expert', 'invited'],
                            'description' => 'Team role for the user',
                            'default' => 'team_member',
                        ],
                        'stage' => [
                            'type' => 'string',
                            'enum' => ['prepare', 'commit'],
                            'description' => 'Stage: prepare (preview) or commit (execute)',
                            'default' => 'prepare',
                        ],
                        'session_id' => [
                            'type' => 'string',
                            'description' => 'Session ID from prepare stage (required for commit)',
                        ],
                    ],
                    'required' => ['publication_id', 'action', 'user_id'],
                ],
                'annotations' => [
                    'readOnlyHint' => false,
                    'destructiveHint' => false,
                ],
            ],
        ];
    }

    /**
     * Execute a team tool
     */
    public static function execute(string $tool, array $args, int $user_id): array {
        $request_id = Tool_Response::generate_request_id();
        $permissions = new Permission_Checker($user_id);

        if ($user_id <= 0) {
            return Tool_Response::error('authentication_required', 'You must be logged in to manage teams.', $request_id);
        }

        switch ($tool) {
            case 'ml_get_team':
                return self::handle_get_team($args, $user_id, $permissions, $request_id);

            case 'ml_manage_team':
                return self::handle_manage_team($args, $user_id, $permissions, $request_id);

            default:
                return Tool_Response::error('unknown_tool', 'Unknown tool: ' . $tool, $request_id);
        }
    }

    /**
     * Handle ml_get_team
     */
    private static function handle_get_team(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];
        $include_permissions = (bool) ($args['include_permissions'] ?? false);

        // Check access
        if (!$permissions->can_see_publication($publication_id)) {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        // Build team data
        $team = [
            'publication' => [
                'id' => $publication_id,
                'title' => $post->post_title,
            ],
            'members' => [],
            'summary' => [
                'total' => 0,
                'by_role' => [],
            ],
        ];

        // Primary author
        $author = get_userdata($post->post_author);
        if ($author) {
            $member = self::format_team_member($author, 'author', $publication_id, $include_permissions);
            $team['members'][] = $member;
            $team['summary']['by_role']['author'] = 1;
        }

        // Get other team members by role
        foreach (self::ROLE_META_KEYS as $role => $meta_key) {
            $user_ids = self::get_users_by_meta($publication_id, $meta_key);

            foreach ($user_ids as $member_user_id) {
                // Skip if same as author
                if ((int) $member_user_id === (int) $post->post_author) {
                    continue;
                }

                $member_user = get_userdata($member_user_id);
                if ($member_user) {
                    $member = self::format_team_member($member_user, $role, $publication_id, $include_permissions);
                    $team['members'][] = $member;
                    $team['summary']['by_role'][$role] = ($team['summary']['by_role'][$role] ?? 0) + 1;
                }
            }
        }

        $team['summary']['total'] = count($team['members']);

        // Check if current user can manage team
        $team['my_permissions'] = [
            'can_manage_team' => self::can_manage_team($publication_id, $user_id, $permissions),
            'is_member' => self::is_team_member($publication_id, $user_id),
            'my_role' => self::get_user_role($publication_id, $user_id, $post->post_author),
        ];

        return Tool_Response::ok($team, $request_id);
    }

    /**
     * Handle ml_manage_team
     */
    private static function handle_manage_team(array $args, int $user_id, Permission_Checker $permissions, string $request_id): array {
        $validation = Tool_Response::validate_required($args, ['publication_id', 'action', 'user_id']);
        if ($validation) {
            return $validation;
        }

        $publication_id = (int) $args['publication_id'];
        $action = $args['action'];
        $target_user_id = (int) $args['user_id'];
        $role = $args['role'] ?? 'team_member';
        $stage = $args['stage'] ?? 'prepare';

        // Check publication access
        if (!$permissions->can_see_publication($publication_id)) {
            return Tool_Response::error('not_found', 'Publication not found', $request_id);
        }

        // Check team management permission
        if (!self::can_manage_team($publication_id, $user_id, $permissions)) {
            return Tool_Response::error('access_denied', 'You do not have permission to manage this team.', $request_id);
        }

        // Validate target user exists
        $target_user = get_userdata($target_user_id);
        if (!$target_user) {
            return Tool_Response::error('not_found', 'User not found.', $request_id);
        }

        // Validate role
        if (!isset(self::ROLE_META_KEYS[$role])) {
            return Tool_Response::error('validation_failed', 'Invalid role specified.', $request_id);
        }

        $post = get_post($publication_id);

        // Cannot remove the primary author
        if ($action === 'remove' && $target_user_id === (int) $post->post_author) {
            return Tool_Response::error('validation_failed', 'Cannot remove the primary author.', $request_id);
        }

        if ($stage === 'prepare') {
            return self::prepare_manage_team($publication_id, $post, $action, $target_user, $role, $user_id, $request_id);
        } elseif ($stage === 'commit') {
            return self::commit_manage_team($publication_id, $action, $target_user_id, $role, $args, $user_id, $request_id);
        } else {
            return Tool_Response::error('validation_failed', 'Invalid stage. Use "prepare" or "commit".', $request_id);
        }
    }

    /**
     * Prepare team management - preview
     */
    private static function prepare_manage_team(int $publication_id, \WP_Post $post, string $action, \WP_User $target_user, string $role, int $user_id, string $request_id): array {
        $meta_key = self::ROLE_META_KEYS[$role];
        $current_members = self::get_users_by_meta($publication_id, $meta_key);
        $is_already_member = in_array($target_user->ID, $current_members, true);

        // Validate action makes sense
        if ($action === 'add' && $is_already_member) {
            return Tool_Response::ok([
                'stage' => 'prepare',
                'already_member' => true,
                'publication_id' => $publication_id,
                'user' => [
                    'id' => $target_user->ID,
                    'name' => $target_user->display_name,
                ],
                'role' => $role,
                'message' => 'User is already a team member with this role.',
            ], $request_id);
        }

        if ($action === 'remove' && !$is_already_member) {
            // Check if user has the role as primary author
            if ($target_user->ID === (int) $post->post_author && $role === 'author') {
                return Tool_Response::error('validation_failed', 'Cannot remove the primary author.', $request_id);
            }

            return Tool_Response::ok([
                'stage' => 'prepare',
                'not_member' => true,
                'publication_id' => $publication_id,
                'user' => [
                    'id' => $target_user->ID,
                    'name' => $target_user->display_name,
                ],
                'role' => $role,
                'message' => 'User is not a team member with this role.',
            ], $request_id);
        }

        // Create session
        $session_data = [
            'user_id' => $user_id,
            'publication_id' => $publication_id,
            'action' => $action,
            'target_user_id' => $target_user->ID,
            'role' => $role,
            'created_at' => time(),
        ];

        $session_id = self::create_session($session_data);

        return Tool_Response::ok([
            'stage' => 'prepare',
            'session_id' => $session_id,
            'expires_in' => self::SESSION_TTL,
            'preview' => [
                'publication' => [
                    'id' => $publication_id,
                    'title' => $post->post_title,
                ],
                'action' => $action,
                'user' => [
                    'id' => $target_user->ID,
                    'name' => $target_user->display_name,
                    'email' => $target_user->user_email,
                ],
                'role' => $role,
                'role_label' => self::get_role_label($role),
                'current_team_size' => count($current_members) + 1, // +1 for author
            ],
            'next_action' => [
                'tool' => 'ml_manage_team',
                'args' => [
                    'publication_id' => $publication_id,
                    'action' => $action,
                    'user_id' => $target_user->ID,
                    'role' => $role,
                    'stage' => 'commit',
                    'session_id' => $session_id,
                ],
                'hint' => $action === 'add'
                    ? sprintf('Call commit to add %s as %s.', $target_user->display_name, self::get_role_label($role))
                    : sprintf('Call commit to remove %s from %s role.', $target_user->display_name, self::get_role_label($role)),
            ],
        ], $request_id);
    }

    /**
     * Commit team management - execute
     */
    private static function commit_manage_team(int $publication_id, string $action, int $target_user_id, string $role, array $args, int $user_id, string $request_id): array {
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
        if (($session['publication_id'] ?? 0) !== $publication_id ||
            ($session['target_user_id'] ?? 0) !== $target_user_id ||
            ($session['action'] ?? '') !== $action ||
            ($session['role'] ?? '') !== $role) {
            return Tool_Response::error(
                'session_mismatch',
                'Session does not match request.',
                $request_id
            );
        }

        $meta_key = self::ROLE_META_KEYS[$role];

        if ($action === 'add') {
            // Add user to role
            add_post_meta($publication_id, $meta_key, $target_user_id);
            $message = sprintf('Successfully added user to %s role.', self::get_role_label($role));
        } else {
            // Remove user from role
            delete_post_meta($publication_id, $meta_key, $target_user_id);
            $message = sprintf('Successfully removed user from %s role.', self::get_role_label($role));
        }

        // Clean up session
        self::cleanup_session($session_id);

        // Get updated team count
        $current_members = self::get_users_by_meta($publication_id, $meta_key);

        return Tool_Response::ok([
            'stage' => 'commit',
            'success' => true,
            'publication_id' => $publication_id,
            'action' => $action,
            'user_id' => $target_user_id,
            'role' => $role,
            'current_role_count' => count($current_members),
            'message' => $message,
        ], $request_id);
    }

    /**
     * Get users by meta key (handles multiple meta values)
     */
    private static function get_users_by_meta(int $publication_id, string $meta_key): array {
        global $wpdb;

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key = %s",
            $publication_id,
            $meta_key
        ));

        return array_map('intval', $results);
    }

    /**
     * Format team member for output
     */
    private static function format_team_member(\WP_User $user, string $role, int $publication_id, bool $include_permissions): array {
        $member = [
            'id' => $user->ID,
            'name' => $user->display_name,
            'role' => $role,
            'role_label' => self::get_role_label($role),
            'avatar' => get_avatar_url($user->ID, ['size' => 48]),
        ];

        if ($include_permissions) {
            $permissions = new Permission_Checker($user->ID);
            $member['permissions'] = [
                'can_edit' => Picasso_Adapter::can_edit_publication($user->ID, $publication_id),
                'can_comment_public' => Picasso_Adapter::can_comment($user->ID, $publication_id, 'public'),
                'can_comment_private' => Picasso_Adapter::can_comment($user->ID, $publication_id, 'private'),
            ];
        }

        return $member;
    }

    /**
     * Get role label
     */
    private static function get_role_label(string $role): string {
        $labels = [
            'author' => 'Author',
            'co_author' => 'Co-Author',
            'team_member' => 'Team Member',
            'expert' => 'Expert',
            'invited' => 'Invited',
        ];

        return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }

    /**
     * Check if user can manage team
     */
    private static function can_manage_team(int $publication_id, int $user_id, Permission_Checker $permissions): bool {
        // Admin can always manage
        if ($permissions->is_admin()) {
            return true;
        }

        $post = get_post($publication_id);
        if (!$post) {
            return false;
        }

        // Author can manage
        if ((int) $post->post_author === $user_id) {
            return true;
        }

        // Co-author can manage
        $co_authors = self::get_users_by_meta($publication_id, self::ROLE_META_KEYS['co_author']);
        if (in_array($user_id, $co_authors, true)) {
            return true;
        }

        return false;
    }

    /**
     * Check if user is team member
     */
    private static function is_team_member(int $publication_id, int $user_id): bool {
        $post = get_post($publication_id);
        if (!$post) {
            return false;
        }

        // Author is member
        if ((int) $post->post_author === $user_id) {
            return true;
        }

        // Check all roles
        foreach (self::ROLE_META_KEYS as $meta_key) {
            $members = self::get_users_by_meta($publication_id, $meta_key);
            if (in_array($user_id, $members, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user's role in publication
     */
    private static function get_user_role(int $publication_id, int $user_id, int $author_id): ?string {
        if ($user_id === $author_id) {
            return 'author';
        }

        foreach (self::ROLE_META_KEYS as $role => $meta_key) {
            $members = self::get_users_by_meta($publication_id, $meta_key);
            if (in_array($user_id, $members, true)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Create session for prepare/commit flow
     */
    private static function create_session(array $data): string {
        $session_id = 'team_' . bin2hex(random_bytes(16));
        set_transient(self::SESSION_PREFIX . $session_id, $data, self::SESSION_TTL);
        return $session_id;
    }

    /**
     * Validate session
     */
    private static function validate_session(string $session_id, int $user_id): ?array {
        if (empty($session_id) || strpos($session_id, 'team_') !== 0) {
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
