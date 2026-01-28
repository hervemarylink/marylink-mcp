<?php
/**
 * Business Context Service - Context injection for AI prompts
 *
 * Takes detected entities and injects relevant business context into prompts.
 * Supports different injection styles and context levels.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Core\Services;

class Business_Context_Service {

    const VERSION = '3.0.0';

    // Injection styles
    const STYLE_PREFIX = 'prefix';      // Context at the beginning
    const STYLE_SUFFIX = 'suffix';      // Context at the end
    const STYLE_INLINE = 'inline';      // Context inline with content
    const STYLE_STRUCTURED = 'structured'; // JSON-like structured context

    // Context levels
    const LEVEL_MINIMAL = 'minimal';    // Just names
    const LEVEL_STANDARD = 'standard';  // Names + key info
    const LEVEL_DETAILED = 'detailed';  // Full context

    // Cache TTL in seconds
    const CACHE_TTL = 300; // 5 minutes

    /**
     * Build context from detected entities
     *
     * @param array $entities Detected entities from Entity_Detector
     * @param int $user_id User ID
     * @param string $level Context detail level
     * @return array Context data
     */
    public static function build_context(array $entities, int $user_id, string $level = self::LEVEL_STANDARD): array {
        $context = [
            'user' => self::get_user_context($user_id, $level),
            'client' => null,
            'project' => null,
            'space' => null,
            'mentions' => [],
            'tags' => [],
            'dates' => [],
            'metadata' => [
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'level' => $level,
            ],
        ];

        // Extract from detected entities
        $detected = $entities['entities'] ?? $entities;

        // Client context
        if (!empty($detected['clients'])) {
            $client = $detected['clients'][0];
            $context['client'] = self::get_client_context($client['id'], $level);
        }

        // Project context
        if (!empty($detected['projects'])) {
            $project = $detected['projects'][0];
            $context['project'] = self::get_project_context($project['id'], $level);
        }

        // Space context
        if (!empty($detected['spaces'])) {
            $space = $detected['spaces'][0];
            $context['space'] = self::get_space_context($space['id'], $level);
        }

        // User mentions
        if (!empty($detected['users'])) {
            foreach ($detected['users'] as $mention) {
                if (!($mention['unresolved'] ?? false) && $mention['id']) {
                    $context['mentions'][] = [
                        'id' => $mention['id'],
                        'name' => $mention['name'],
                    ];
                }
            }
        }

        // Tags
        if (!empty($detected['tags'])) {
            $context['tags'] = array_column($detected['tags'], 'value');
        }

        // Dates
        if (!empty($detected['dates'])) {
            $context['dates'] = array_filter(array_column($detected['dates'], 'value'));
        }

        return array_filter($context, fn($v) => !empty($v));
    }

    /**
     * Inject context into prompt
     *
     * @param string $prompt Original prompt
     * @param array $context Context data from build_context()
     * @param string $style Injection style
     * @return string Prompt with injected context
     */
    public static function inject_context(string $prompt, array $context, string $style = self::STYLE_PREFIX): string {
        if (empty($context) || (empty($context['client']) && empty($context['project']) && empty($context['space']))) {
            return $prompt;
        }

        $context_text = self::format_context($context, $style);

        if (empty($context_text)) {
            return $prompt;
        }

        return match ($style) {
            self::STYLE_PREFIX => $context_text . "\n\n---\n\n" . $prompt,
            self::STYLE_SUFFIX => $prompt . "\n\n---\n\n" . $context_text,
            self::STYLE_INLINE => self::inject_inline($prompt, $context),
            self::STYLE_STRUCTURED => self::inject_structured($prompt, $context),
            default => $context_text . "\n\n" . $prompt,
        };
    }

    /**
     * Format context as text
     */
    private static function format_context(array $context, string $style): string {
        $parts = [];

        // Header
        if ($style === self::STYLE_STRUCTURED) {
            $parts[] = "## Business Context";
        } else {
            $parts[] = "=== Contexte Métier ===";
        }

        // Client
        if (!empty($context['client'])) {
            $client = $context['client'];
            $line = "Client: {$client['name']}";

            if (!empty($client['industry'])) {
                $line .= " (Secteur: {$client['industry']})";
            }

            $parts[] = $line;

            if (!empty($client['description'])) {
                $parts[] = "  Description: {$client['description']}";
            }
        }

        // Project
        if (!empty($context['project'])) {
            $project = $context['project'];
            $line = "Projet: {$project['name']}";

            if (!empty($project['status'])) {
                $line .= " [Status: {$project['status']}]";
            }

            $parts[] = $line;

            if (!empty($project['description'])) {
                $parts[] = "  Description: {$project['description']}";
            }

            if (!empty($project['deadline'])) {
                $parts[] = "  Deadline: {$project['deadline']}";
            }
        }

        // Space
        if (!empty($context['space'])) {
            $space = $context['space'];
            $parts[] = "Espace: {$space['name']}";

            if (!empty($space['description'])) {
                $parts[] = "  Description: {$space['description']}";
            }
        }

        // Tags
        if (!empty($context['tags'])) {
            $parts[] = "Tags: #" . implode(' #', $context['tags']);
        }

        // Mentions
        if (!empty($context['mentions'])) {
            $names = array_column($context['mentions'], 'name');
            $parts[] = "Personnes mentionnées: " . implode(', ', $names);
        }

        // Dates
        if (!empty($context['dates'])) {
            $parts[] = "Dates référencées: " . implode(', ', $context['dates']);
        }

        return implode("\n", $parts);
    }

    /**
     * Inject context inline
     */
    private static function inject_inline(string $prompt, array $context): string {
        // Replace entity references with enriched versions
        $replacements = [];

        if (!empty($context['client'])) {
            $client = $context['client'];
            $enriched = "{$client['name']}";
            if (!empty($client['industry'])) {
                $enriched .= " (client du secteur {$client['industry']})";
            }
            $replacements[$client['name']] = $enriched;
        }

        if (!empty($context['project'])) {
            $project = $context['project'];
            $enriched = "{$project['name']}";
            if (!empty($project['status'])) {
                $enriched .= " (projet {$project['status']})";
            }
            $replacements[$project['name']] = $enriched;
        }

        foreach ($replacements as $search => $replace) {
            $prompt = str_ireplace($search, $replace, $prompt);
        }

        return $prompt;
    }

    /**
     * Inject structured context (JSON-like)
     */
    private static function inject_structured(string $prompt, array $context): string {
        $structured = "```context\n";
        $structured .= wp_json_encode([
            'client' => $context['client'] ?? null,
            'project' => $context['project'] ?? null,
            'space' => $context['space'] ?? null,
            'tags' => $context['tags'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $structured .= "\n```\n\n";

        return $structured . $prompt;
    }

    /**
     * Get user context
     */
    private static function get_user_context(int $user_id, string $level): array {
        $user = get_userdata($user_id);

        if (!$user) {
            return [];
        }

        $context = [
            'id' => $user_id,
            'name' => $user->display_name,
        ];

        if ($level !== self::LEVEL_MINIMAL) {
            $context['roles'] = $user->roles;
        }

        if ($level === self::LEVEL_DETAILED) {
            $context['email'] = $user->user_email;
            $context['registered'] = $user->user_registered;

            if (function_exists('bp_get_profile_field_data')) {
                $bio = bp_get_profile_field_data(['field' => 'Bio', 'user_id' => $user_id]);
                if ($bio) {
                    $context['bio'] = wp_trim_words($bio, 30);
                }
            }
        }

        return $context;
    }

    /**
     * Get client context
     */
    private static function get_client_context(int $client_id, string $level): ?array {
        $cache_key = "ml_client_context_{$client_id}_{$level}";
        $cached = wp_cache_get($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $post = get_post($client_id);

        if (!$post || $post->post_type !== 'ml_client') {
            return null;
        }

        $context = [
            'id' => $client_id,
            'name' => $post->post_title,
        ];

        if ($level !== self::LEVEL_MINIMAL) {
            $context['industry'] = get_post_meta($client_id, '_ml_client_industry', true);
            $context['size'] = get_post_meta($client_id, '_ml_client_size', true);
        }

        if ($level === self::LEVEL_DETAILED) {
            $context['description'] = wp_trim_words($post->post_content, 50);
            $context['website'] = get_post_meta($client_id, '_ml_client_website', true);
            $context['contact'] = get_post_meta($client_id, '_ml_client_contact', true);
            $context['notes'] = get_post_meta($client_id, '_ml_client_notes', true);

            // Recent activity
            $context['recent_projects'] = self::get_client_recent_projects($client_id, 3);
        }

        $context = array_filter($context);
        wp_cache_set($cache_key, $context, '', self::CACHE_TTL);

        return $context;
    }

    /**
     * Get project context
     */
    private static function get_project_context(int $project_id, string $level): ?array {
        $cache_key = "ml_project_context_{$project_id}_{$level}";
        $cached = wp_cache_get($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $post = get_post($project_id);

        if (!$post || $post->post_type !== 'ml_project') {
            return null;
        }

        $context = [
            'id' => $project_id,
            'name' => $post->post_title,
        ];

        if ($level !== self::LEVEL_MINIMAL) {
            $context['status'] = get_post_meta($project_id, '_ml_project_status', true);
            $context['deadline'] = get_post_meta($project_id, '_ml_project_deadline', true);

            // Link to client
            $client_id = get_post_meta($project_id, '_ml_project_client_id', true);
            if ($client_id) {
                $client_post = get_post($client_id);
                if ($client_post) {
                    $context['client_name'] = $client_post->post_title;
                }
            }
        }

        if ($level === self::LEVEL_DETAILED) {
            $context['description'] = wp_trim_words($post->post_content, 50);
            $context['budget'] = get_post_meta($project_id, '_ml_project_budget', true);
            $context['team'] = get_post_meta($project_id, '_ml_project_team', true);
            $context['objectives'] = get_post_meta($project_id, '_ml_project_objectives', true);
        }

        $context = array_filter($context);
        wp_cache_set($cache_key, $context, '', self::CACHE_TTL);

        return $context;
    }

    /**
     * Get space context
     */
    private static function get_space_context(int $space_id): array {
        // Canonical: CPT 'space'
        $post = get_post($space_id);
        if ($post && $post->post_type === 'space') {
            $members = (array) get_post_meta($space_id, '_space_members', true);
            $contributors = (array) get_post_meta($space_id, '_space_contributors', true);
            $moderators = (array) get_post_meta($space_id, '_space_moderators', true);
            $count = count(array_unique(array_merge($members, $contributors, $moderators)));
            if ($post->post_author) {
                $count += 1; // owner/admin
            }

            return [
                'id' => (int) $post->ID,
                'name' => $post->post_title,
                'slug' => $post->post_name,
                'description' => wp_strip_all_tags($post->post_excerpt ?: wp_trim_words($post->post_content, 80)),
                'url' => get_permalink($post),
                'member_count' => $count,
                '_kind' => 'space',
                '_id_ns' => 'wp_post',
            ];
        }

        // Backward compat: BuddyBoss group
        if (function_exists('groups_get_group')) {
            $group = groups_get_group($space_id);
            if ($group && $group->id) {
                return [
                    'id' => (int) $group->id,
                    'name' => $group->name,
                    'description' => $group->description ?? '',
                    'url' => bp_get_group_permalink($group),
                    'member_count' => (int) ($group->total_member_count ?? 0),
                    '_kind' => 'group',
                    '_id_ns' => 'bb_group',
                    '_compat' => 'legacy_space_as_group',
                ];
            }
        }

        return [];
    }

    /**
     * Get client's recent projects
     */
    private static function get_client_recent_projects(int $client_id, int $limit = 3): array {
        global $wpdb;

        $projects = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value as status
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ml_project_status'
             WHERE p.post_type = 'ml_project' AND p.post_status = 'publish'
             AND p.ID IN (
                 SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_ml_project_client_id' AND meta_value = %d
             )
             ORDER BY p.post_date DESC
             LIMIT %d",
            $client_id, $limit
        ));

        return array_map(fn($p) => [
            'id' => (int) $p->ID,
            'name' => $p->post_title,
            'status' => $p->status,
        ], $projects);
    }

    /**
     * Auto-detect and inject context into prompt
     *
     * Convenience method that combines Entity_Detector and context injection.
     *
     * @param string $prompt Prompt with potential entity references
     * @param int $user_id User ID
     * @param string $style Injection style
     * @param string $level Context level
     * @return array ['prompt' => enriched prompt, 'context' => detected context]
     */
    public static function auto_enrich(
        string $prompt,
        int $user_id,
        string $style = self::STYLE_PREFIX,
        string $level = self::LEVEL_STANDARD
    ): array {
        // Detect entities
        $detected = Entity_Detector::detect($prompt, $user_id);

        // Build context
        $context = self::build_context($detected, $user_id, $level);

        // Inject context
        $enriched_prompt = self::inject_context($prompt, $context, $style);

        return [
            'prompt' => $enriched_prompt,
            'context' => $context,
            'detected_entities' => $detected,
            'was_enriched' => $enriched_prompt !== $prompt,
        ];
    }
}
