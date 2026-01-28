<?php
/**
 * Apply Tool - Execute tool/prompt with prepare/commit flow
 *
 * Phase 2: Allows agents to apply a MaryLink tool/prompt/style to user input
 * - prepare: Build prompt from template + input, return session_id
 * - commit: Save result as publication or comment (optional)
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

class Apply_Tool {

    /**
     * Session transient prefix
     */
    private const SESSION_PREFIX = 'mcpnh_session_';

    /**
     * Session TTL in seconds (5 minutes)
     */
    private const SESSION_TTL = 300;

    /**
     * Rate limit: max prepares per minute per user
     */
    private const RATE_LIMIT_PREPARES = 20;

    /**
     * Permission checker instance
     */
    private ?Permission_Checker $permission_checker = null;

    /**
     * Current user ID
     */
    private int $user_id = 0;

    /**
     * Valid tool labels/types
     */
    private const VALID_TOOL_TYPES = ['tool', 'prompt', 'style', 'template'];

    /**
     * Execute the apply_tool action
     *
     * @param array $args Tool arguments
     * @param int $user_id Current user ID
     * @return array Result
     */
    public function execute(array $args, int $user_id): array {
        $this->user_id = $user_id;
        $this->permission_checker = new Permission_Checker($user_id);

        $stage = $args['stage'] ?? $args['action'] ?? 'prepare';

        switch ($stage) {
            case 'help':
            case 'list_actions':
                return $this->stage_help();
            case 'prepare':
                return $this->stage_prepare($args);
            case 'commit':
                return $this->stage_commit($args);
            default:
                throw new \Exception("Invalid stage: {$stage}. Use 'help', 'prepare' or 'commit'.");
        }
    }


    /**
     * Stage: Help - Return usage documentation
     */
    private function stage_help(): array {
        return [
            'stage' => 'help',
            'tool_name' => 'ml_apply_tool',
            'description' => 'Apply a MaryLink tool (prompt/style/template) to your text',
            'usage' => [
                'prepare' => [
                    'description' => 'Builds the prompt with your input, returns session_id',
                    'params' => [
                        'tool_id' => '(required) ID of the tool/prompt/style to apply',
                        'input_text' => '(required) Your text to process',
                        'options' => '(optional) Additional options',
                        'stage' => '"prepare"'
                    ],
                    'returns' => ['session_id', 'prepared_prompt', 'tool_title']
                ],
                'commit' => [
                    'description' => 'Save the LLM output after processing',
                    'params' => [
                        'session_id' => '(required) From prepare stage',
                        'llm_output' => '(required) The generated text',
                        'save_as' => '"none" | "publication" | "comment"',
                        'publication_id' => '(if save_as=comment) Target publication',
                        'space_id' => '(if save_as=publication) Target space',
                        'stage' => '"commit"'
                    ]
                ]
            ],
            'example' => [
                'step1' => '{"tool_id": 12345, "input_text": "My text", "stage": "prepare"}',
                'step2' => 'Use prepared_prompt with your LLM',
                'step3' => '{"session_id": "xxx", "llm_output": "Generated text", "save_as": "publication", "space_id": 13745, "stage": "commit"}'
            ],
            'note' => 'This tool applies existing MaryLink prompts/styles to text. For CRUD operations, use ml_publication_create, ml_publication_update, etc.'
        ];
    }

    /**
     * Stage 1: Prepare - Build prompt and create session
     *
     * @param array $args
     * @return array
     */
    private function stage_prepare(array $args): array {
        // Rate limiting
        if (!$this->check_rate_limit()) {
            throw new \Exception("Rate limit exceeded. Please wait before trying again.");
        }

        $tool_id = (int) ($args['tool_id'] ?? 0);
        $input_text = $args['input_text'] ?? '';
        $options = $args['options'] ?? [];

        // Validate tool exists and is accessible
        $tool = $this->get_tool($tool_id);
        if (!$tool) {
            // Neutral response - don't leak existence
            throw new \Exception("Tool not found or not accessible.");
        }

        // Validate it's actually a tool/prompt/style
        if (!$this->is_valid_tool_type($tool_id)) {
            throw new \Exception("Tool not found or not accessible.");
        }

        // Build the prepared prompt
        $template = $this->get_tool_template($tool);
        $prepared_prompt = $this->build_prompt($template, $input_text, $options);

        // Create session
        $session_id = $this->create_session($tool_id, $input_text, $prepared_prompt);

        // Input preview (first 100 chars)
        $input_preview = mb_strlen($input_text) > 100
            ? mb_substr($input_text, 0, 100) . '...'
            : $input_text;

        return [
            'stage' => 'prepare',
            'success' => true,
            'prepared_prompt' => $prepared_prompt,
            'session_id' => $session_id,
            'tool_title' => $tool->post_title,
            'input_preview' => $input_preview,
            'expires_in' => self::SESSION_TTL,
            'next_actions' => [
                'Execute this prompt with your LLM, then call ml_apply_tool with stage=commit to save the result.',
                'Use save_as=none to discard, save_as=publication to create a new publication, or save_as=comment to add a comment.',
            ],
        ];
    }

    /**
     * Stage 2: Commit - Save the result or discard
     *
     * @param array $args
     * @return array
     */
    private function stage_commit(array $args): array {
        $session_id = $args['session_id'] ?? '';
        $final_text = $args['final_text'] ?? '';
        $save_as = $args['save_as'] ?? 'none';
        $target = $args['target'] ?? [];

        // Validate session
        $session = $this->validate_session($session_id);
        if (!$session) {
            throw new \Exception("Session expired or invalid. Please run prepare again.");
        }

        // Clean up session immediately
        $this->cleanup_session($session_id);

        // Handle save_as
        switch ($save_as) {
            case 'none':
                return [
                    'stage' => 'commit',
                    'success' => true,
                    'saved' => false,
                    'message' => 'Session closed. No content saved.',
                ];

            case 'publication':
                return $this->save_as_publication($final_text, $target, $session);

            case 'comment':
                return $this->save_as_comment($final_text, $target, $session);

            default:
                throw new \Exception("Invalid save_as value. Use 'none', 'publication', or 'comment'.");
        }
    }

    /**
     * Get tool publication if accessible
     *
     * @param int $tool_id
     * @return \WP_Post|null
     */
    private function get_tool(int $tool_id): ?\WP_Post {
        if ($tool_id <= 0) {
            return null;
        }

        $post = get_post($tool_id);
        if (!$post || $post->post_type !== 'publication') {
            return null;
        }

        // Check permission using neutral approach
        if (!$this->permission_checker->can_see_publication($tool_id)) {
            return null;
        }

        return $post;
    }

    /**
     * Check if publication is a valid tool type
     *
     * @param int $tool_id
     * @return bool
     */
    private function is_valid_tool_type(int $tool_id): bool {
        // Check via taxonomy (publication_label or similar)
        $terms = wp_get_post_terms($tool_id, 'publication_label', ['fields' => 'slugs']);
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $slug) {
                if (in_array(strtolower($slug), self::VALID_TOOL_TYPES, true)) {
                    return true;
                }
            }
        }

        // Fallback: check post meta
        $tool_type = get_post_meta($tool_id, '_publication_type', true);
        if (!empty($tool_type) && in_array(strtolower($tool_type), self::VALID_TOOL_TYPES, true)) {
            return true;
        }

        // Fallback: check if has _tool_prompt meta
        $has_prompt = get_post_meta($tool_id, '_tool_prompt', true);
        if (!empty($has_prompt)) {
            return true;
        }

        // Generous fallback: any publication with content can be used as template
        return true;
    }

    /**
     * Get the template from a tool publication
     *
     * @param \WP_Post $tool
     * @return string
     */
    private function get_tool_template(\WP_Post $tool): string {
        // Priority 1: _tool_prompt meta
        $prompt = get_post_meta($tool->ID, '_tool_prompt', true);
        if (!empty($prompt)) {
            return $prompt;
        }

        // Priority 2: post_content
        return $tool->post_content;
    }

    /**
     * Build the final prompt from template + input + options
     *
     * @param string $template
     * @param string $input_text
     * @param array $options
     * @return string
     */
    private function build_prompt(string $template, string $input_text, array $options): string {
        $prompt = $template;

        // Replace common placeholders
        $placeholders = [
            '{{input}}' => $input_text,
            '{{INPUT}}' => $input_text,
            '{input}' => $input_text,
            '{{text}}' => $input_text,
            '{{TEXT}}' => $input_text,
            '{text}' => $input_text,
            '{{content}}' => $input_text,
            '{{CONTENT}}' => $input_text,
        ];

        foreach ($placeholders as $placeholder => $value) {
            $prompt = str_replace($placeholder, $value, $prompt);
        }

        // Handle options
        if (!empty($options['language'])) {
            $lang = sanitize_text_field($options['language']);
            $prompt = str_replace(['{{language}}', '{language}', '{{LANGUAGE}}'], $lang, $prompt);
            // Append language instruction if not in template
            if (strpos($template, '{{language}}') === false && strpos($template, '{language}') === false) {
                $prompt .= "\n\nRespond in {$lang}.";
            }
        }

        if (!empty($options['tone'])) {
            $tone = sanitize_text_field($options['tone']);
            $prompt = str_replace(['{{tone}}', '{tone}', '{{TONE}}'], $tone, $prompt);
        }

        if (!empty($options['output_format'])) {
            $format = sanitize_text_field($options['output_format']);
            $prompt = str_replace(['{{format}}', '{format}', '{{FORMAT}}', '{{output_format}}'], $format, $prompt);
        }

        // If template has no placeholders at all, append input at the end
        $has_placeholder = false;
        foreach (array_keys($placeholders) as $p) {
            if (strpos($template, $p) !== false) {
                $has_placeholder = true;
                break;
            }
        }
        if (!$has_placeholder && !empty($input_text)) {
            $prompt .= "\n\n---\n\n" . $input_text;
        }

        return trim($prompt);
    }

    /**
     * Create a session for prepare → commit flow
     *
     * @param int $tool_id
     * @param string $input_text
     * @param string $prepared_prompt
     * @return string Session ID
     */
    private function create_session(int $tool_id, string $input_text, string $prepared_prompt): string {
        $session_id = 'sess_' . bin2hex(random_bytes(16));

        $session_data = [
            'user_id' => $this->user_id,
            'tool_id' => $tool_id,
            'input_hash' => hash('sha256', $input_text),
            'prompt_hash' => hash('sha256', $prepared_prompt),
            'created_at' => time(),
        ];

        set_transient(self::SESSION_PREFIX . $session_id, $session_data, self::SESSION_TTL);

        return $session_id;
    }

    /**
     * Validate a session
     *
     * @param string $session_id
     * @return array|null Session data or null if invalid
     */
    private function validate_session(string $session_id): ?array {
        if (empty($session_id) || strpos($session_id, 'sess_') !== 0) {
            return null;
        }

        $session = get_transient(self::SESSION_PREFIX . $session_id);
        if (!$session || !is_array($session)) {
            return null;
        }

        // Verify session belongs to current user
        if (($session['user_id'] ?? 0) !== $this->user_id) {
            return null;
        }

        return $session;
    }

    /**
     * Clean up a session
     *
     * @param string $session_id
     */
    private function cleanup_session(string $session_id): void {
        delete_transient(self::SESSION_PREFIX . $session_id);
    }

    /**
     * Save result as a new publication
     *
     * @param string $final_text
     * @param array $target
     * @param array $session
     * @return array
     */
    private function save_as_publication(string $final_text, array $target, array $session): array {
        $space_id = (int) ($target['space_id'] ?? 0);
        $title = sanitize_text_field($target['title'] ?? '');
        $status = ($target['status'] ?? 'draft') === 'publish' ? 'publish' : 'draft';

        if (empty($title)) {
            throw new \Exception("Title is required for publication.");
        }

        if ($space_id <= 0) {
            throw new \Exception("Valid space_id is required.");
        }

        // Check permission to publish in this space
        if (!$this->permission_checker->can_see_space($space_id)) {
            throw new \Exception("You don't have permission to publish in this space.");
        }

        // Sanitize content
        $content = wp_kses_post($final_text);

        // Create publication
        $post_data = [
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => $status,
            'post_author' => $this->user_id,
            'post_type' => 'publication',
            'post_parent' => $space_id,
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            throw new \Exception($post_id->get_error_message());
        }

        // Set meta
        update_post_meta($post_id, '_publication_space', $space_id);
        update_post_meta($post_id, '_publication_step', 'submit');
        update_post_meta($post_id, '_generated_by_tool', $session['tool_id']);
        update_post_meta($post_id, '_generated_at', current_time('mysql'));

        // Trigger Picasso hooks
        do_action('pb_post_saved', $post_id, false);

        return [
            'stage' => 'commit',
            'success' => true,
            'saved' => true,
            'save_type' => 'publication',
            'post_id' => $post_id,
            'title' => $title,
            'status' => $status,
            'space_id' => $space_id,
            'url' => get_permalink($post_id),
            'message' => "Publication '{$title}' created successfully.",
        ];
    }

    /**
     * Save result as a comment on a publication
     *
     * @param string $final_text
     * @param array $target
     * @param array $session
     * @return array
     */
    private function save_as_comment(string $final_text, array $target, array $session): array {
        $publication_id = (int) ($target['publication_id'] ?? 0);
        $comment_type = $target['comment_type'] ?? 'public';
        $parent_id = (int) ($target['parent_comment_id'] ?? 0);

        if ($publication_id <= 0) {
            throw new \Exception("Valid publication_id is required.");
        }

        // Check publication exists and is accessible
        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            throw new \Exception("Publication not found.");
        }

        if (!$this->permission_checker->can_see_publication($publication_id)) {
            throw new \Exception("Publication not found.");
        }

        // Check comment permission
        if (!$this->can_post_comment($publication_id, $comment_type)) {
            throw new \Exception("You don't have permission to post this type of comment.");
        }

        $user = get_userdata($this->user_id);
        $content = sanitize_textarea_field($final_text);

        // Add generated marker
        $content .= "\n\n---\n*Generated with MaryLink AI Tool*";

        $comment_data = [
            'comment_post_ID' => $publication_id,
            'comment_content' => $content,
            'comment_author' => $user->display_name,
            'comment_author_email' => $user->user_email,
            'user_id' => $this->user_id,
            'comment_parent' => $parent_id,
            'comment_approved' => 1,
        ];

        $comment_id = wp_insert_comment($comment_data);

        if (!$comment_id) {
            throw new \Exception("Failed to create comment.");
        }

        // Set comment type meta (for Picasso private comments)
        if ($comment_type === 'private') {
            update_comment_meta($comment_id, '_comment_type', 'private');
        }

        // Track generation source
        update_comment_meta($comment_id, '_generated_by_tool', $session['tool_id']);

        return [
            'stage' => 'commit',
            'success' => true,
            'saved' => true,
            'save_type' => 'comment',
            'comment_id' => $comment_id,
            'publication_id' => $publication_id,
            'comment_type' => $comment_type,
            'message' => "Comment added successfully.",
        ];
    }

    /**
     * Check if user can post a comment
     *
     * @param int $publication_id
     * @param string $comment_type
     * @return bool
     */
    private function can_post_comment(int $publication_id, string $comment_type): bool {
        // Use Permission_Checker if available
        if (method_exists($this->permission_checker, 'can_add_comment')) {
            // Try using the method if it exists
        }

        // Fallback: if user can see publication, they can comment (public)
        if ($comment_type === 'public') {
            return $this->permission_checker->can_see_publication($publication_id);
        }

        // Private comments: need higher permission (author, team, moderator)
        $post = get_post($publication_id);
        if (!$post) {
            return false;
        }

        // Author can always post private
        if ((int) $post->post_author === $this->user_id) {
            return true;
        }

        // Check if user is in team
        $team = get_post_meta($publication_id, '_in_publication_team', false);
        if (is_array($team) && in_array((string) $this->user_id, $team, true)) {
            return true;
        }

        // Check if co-author
        $co_author = get_post_meta($publication_id, '_publication_co_author', true);
        if ((int) $co_author === $this->user_id) {
            return true;
        }

        // Check if expert
        $experts = get_post_meta($publication_id, '_publication_expert', false);
        if (is_array($experts) && in_array((string) $this->user_id, $experts, true)) {
            return true;
        }

        // Admin can always
        if ($this->permission_checker->is_admin()) {
            return true;
        }

        return false;
    }

    /**
     * Check rate limit for prepares
     *
     * @return bool True if within limit
     */
    private function check_rate_limit(): bool {
        $key = 'mcpnh_apply_rate_' . $this->user_id;
        $count = (int) get_transient($key);

        if ($count >= self::RATE_LIMIT_PREPARES) {
            return false;
        }

        set_transient($key, $count + 1, 60); // 1 minute window
        return true;
    }

    /**
     * Get tool definition for MCP registration
     *
     * @return array
     */
    public static function get_tool_definition(): array {
        return [
            'name' => 'ml_apply_tool',
            'description' => 'Apply a MaryLink tool/prompt/style to user input. Use stage=prepare to build prompt, then stage=commit to save result.',
            'category' => 'MaryLink Tools',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'stage' => [
                        'type' => 'string',
                        'enum' => ['prepare', 'commit'],
                        'default' => 'prepare',
                        'description' => 'Stage: prepare builds prompt, commit saves result',
                    ],
                    // Prepare stage params
                    'tool_id' => [
                        'type' => 'integer',
                        'description' => 'ID of the tool/prompt/style publication (required for prepare)',
                    ],
                    'input_text' => [
                        'type' => 'string',
                        'description' => 'User input text to apply the tool to (required for prepare)',
                    ],
                    'options' => [
                        'type' => 'object',
                        'description' => 'Optional settings: language, tone, output_format',
                        'properties' => [
                            'language' => ['type' => 'string'],
                            'tone' => ['type' => 'string'],
                            'output_format' => ['type' => 'string'],
                        ],
                    ],
                    // Commit stage params
                    'session_id' => [
                        'type' => 'string',
                        'description' => 'Session ID from prepare stage (required for commit)',
                    ],
                    'final_text' => [
                        'type' => 'string',
                        'description' => 'Generated result to save (required for commit if save_as != none)',
                    ],
                    'save_as' => [
                        'type' => 'string',
                        'enum' => ['none', 'publication', 'comment'],
                        'default' => 'none',
                        'description' => 'How to save: none (discard), publication, or comment',
                    ],
                    'target' => [
                        'type' => 'object',
                        'description' => 'Save target details',
                        'properties' => [
                            // For publication
                            'space_id' => ['type' => 'integer', 'description' => 'Space ID for publication'],
                            'title' => ['type' => 'string', 'description' => 'Title for publication'],
                            'status' => ['type' => 'string', 'enum' => ['draft', 'publish']],
                            // For comment
                            'publication_id' => ['type' => 'integer', 'description' => 'Publication ID for comment'],
                            'comment_type' => ['type' => 'string', 'enum' => ['public', 'private']],
                            'parent_comment_id' => ['type' => 'integer', 'description' => 'Parent comment ID for reply'],
                        ],
                    ],
                ],
                'required' => ['stage'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => true,
            ],
        ];
    }
}
