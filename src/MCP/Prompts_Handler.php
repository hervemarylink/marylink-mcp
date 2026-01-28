<?php
/**
 * Prompts Handler - MCP prompts/list and prompts/get capability
 *
 * Implements standard MCP prompts capability:
 * - prompts/list: List approved prompt publications
 * - prompts/get: Get a prompt's template with arguments, context bundle, and WHY
 *
 * Source: Publications with label "prompt" in approved steps
 * Naming: marylink.prompt.pub_<publication_id>
 *
 * Cabinet-grade prompts/get includes:
 * - Context bundle (styles, data, docs)
 * - Style guide injection
 * - WHY section (explainability)
 *
 * @package MCP_No_Headless
 * @see https://modelcontextprotocol.info/specification/draft/server/prompts/
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Approved_Steps_Resolver;
use MCP_No_Headless\Services\Recommendation_Service;
use MCP_No_Headless\Picasso\Picasso_Adapter;

class Prompts_Handler {

    /**
     * Prompt name prefix
     */
    private const NAME_PREFIX = 'marylink.prompt.pub_';

    private int $user_id;
    private Permission_Checker $permissions;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
    }

    /**
     * Handle prompts/list method
     *
     * @param array $params Request params (optional cursor for pagination)
     * @return array MCP prompts/list response
     */
    public function list_prompts(array $params = []): array {
        $prompts = [];

        // Get accessible spaces
        $space_ids = $this->permissions->get_user_spaces();
        if (empty($space_ids)) {
            return ['prompts' => []];
        }

        foreach ($space_ids as $space_id) {
            // Get approved steps for this space
            $approved_steps = Approved_Steps_Resolver::get_approved_steps($space_id);
            if (empty($approved_steps)) {
                continue;
            }

            // Query prompt publications in approved steps
            $query_args = [
                'post_type' => 'publication',
                'post_status' => 'publish',
                'post_parent' => $space_id,
                'posts_per_page' => 100, // Reasonable limit per space
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => '_ml_step',
                        'value' => $approved_steps,
                        'compare' => 'IN',
                    ],
                ],
            ];

            // Filter by label "prompt" via taxonomy or meta
            $publications = $this->query_prompt_publications($query_args);

            foreach ($publications as $post) {
                // Permission check (anti-leak)
                if (!$this->permissions->can_see_publication($post->ID)) {
                    continue;
                }

                $prompts[] = $this->format_prompt_list_item($post, $space_id);
            }
        }

        return ['prompts' => $prompts];
    }

    /**
     * Handle prompts/get method (cabinet-grade)
     *
     * Returns:
     * - description: prompt description
     * - messages: array of role/content messages with:
     *   - SYSTEM section (if present)
     *   - Context bundle (styles, data)
     *   - TEMPLATE section with placeholders resolved
     *   - WHY section (explainability)
     *   - OUTPUT guidance
     *
     * @param array $params Request params (name required)
     * @return array MCP prompts/get response
     */
    public function get_prompt(array $params): array {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        // Parse prompt name (format: marylink.prompt.pub_<id>)
        if (!str_starts_with($name, self::NAME_PREFIX)) {
            return [
                'error' => [
                    'code' => -32602,
                    'message' => 'Invalid prompt name format. Expected: marylink.prompt.pub_<id>',
                ],
            ];
        }

        $publication_id = (int) substr($name, strlen(self::NAME_PREFIX));
        if ($publication_id <= 0) {
            return [
                'error' => [
                    'code' => -32602,
                    'message' => 'Invalid publication ID in prompt name',
                ],
            ];
        }

        // Get publication
        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            return [
                'error' => [
                    'code' => -32602,
                    'message' => 'Prompt not found',
                ],
            ];
        }

        // Permission check (anti-leak - neutral message)
        if (!$this->permissions->can_see_publication($publication_id)) {
            return [
                'error' => [
                    'code' => -32602,
                    'message' => 'Prompt not found',
                ],
            ];
        }

        // Check if in approved step
        $space_id = (int) $post->post_parent;
        $current_step = Picasso_Adapter::get_publication_step($publication_id);
        if ($current_step && !Approved_Steps_Resolver::is_step_approved($space_id, $current_step)) {
            return [
                'error' => [
                    'code' => -32602,
                    'message' => 'Prompt not found',
                ],
            ];
        }

        // Parse content sections (## SYSTEM, ## TEMPLATE, ## OUTPUT)
        $sections = $this->parse_prompt_sections($post);

        // Build context bundle (styles, data, docs)
        $context_bundle = $this->build_context_bundle($publication_id);

        // Build WHY section (explainability)
        $why_section = $this->build_why_section($post, $publication_id);

        // Build the messages array
        $messages = [];

        // 1. SYSTEM message (if present)
        if (!empty($sections['system'])) {
            $messages[] = [
                'role' => 'system',
                'content' => [
                    'type' => 'text',
                    'text' => $this->render_template($sections['system'], $arguments),
                ],
            ];
        }

        // 2. Context bundle as system context
        if (!empty($context_bundle['assembled'])) {
            $messages[] = [
                'role' => 'system',
                'content' => [
                    'type' => 'text',
                    'text' => "# Contexte disponible\n\n" . $context_bundle['assembled'],
                ],
            ];
        }

        // 3. Main user message (TEMPLATE + WHY + OUTPUT)
        $user_content = [];

        // Template section
        if (!empty($sections['template'])) {
            $user_content[] = $this->render_template($sections['template'], $arguments);
        } else {
            // Fallback to full content
            $user_content[] = $this->render_template($this->get_prompt_template($post), $arguments);
        }

        // WHY section
        if (!empty($why_section)) {
            $user_content[] = "\n\n---\n**Pourquoi ce prompt ?**\n" . $why_section;
        }

        // OUTPUT guidance
        if (!empty($sections['output'])) {
            $user_content[] = "\n\n---\n**Format de sortie attendu:**\n" . $sections['output'];
        }

        $messages[] = [
            'role' => 'user',
            'content' => [
                'type' => 'text',
                'text' => implode('', $user_content),
            ],
        ];

        return [
            'description' => wp_trim_words(strip_tags($post->post_content), 30),
            'messages' => $messages,
            '_meta' => [
                'publication_id' => $publication_id,
                'space_id' => $space_id,
                'step' => $current_step,
                'context_bundle' => [
                    'style_count' => $context_bundle['style_count'],
                    'content_count' => $context_bundle['content_count'],
                    'citations' => $context_bundle['citations'],
                ],
                'arguments_used' => array_keys($arguments),
            ],
        ];
    }

    /**
     * Parse prompt content into sections (## SYSTEM, ## TEMPLATE, ## OUTPUT)
     *
     * @param \WP_Post $post
     * @return array
     */
    private function parse_prompt_sections(\WP_Post $post): array {
        $content = $post->post_content;
        $sections = [
            'system' => '',
            'template' => '',
            'output' => '',
        ];

        // Try to parse markdown sections
        $patterns = [
            'system' => '/## SYSTEM\s*\n(.*?)(?=##|$)/si',
            'template' => '/## TEMPLATE\s*\n(.*?)(?=##|$)/si',
            'output' => '/## OUTPUT\s*\n(.*?)(?=##|$)/si',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $sections[$key] = trim($matches[1]);
            }
        }

        // If no sections found, check for HTML comment markers
        if (empty($sections['template'])) {
            if (preg_match('/<!-- template -->(.*?)<!-- \/template -->/s', $content, $matches)) {
                $sections['template'] = trim($matches[1]);
            }
        }
        if (empty($sections['system'])) {
            if (preg_match('/<!-- system -->(.*?)<!-- \/system -->/s', $content, $matches)) {
                $sections['system'] = trim($matches[1]);
            }
        }
        if (empty($sections['output'])) {
            if (preg_match('/<!-- output -->(.*?)<!-- \/output -->/s', $content, $matches)) {
                $sections['output'] = trim($matches[1]);
            }
        }

        // Also check _tool_prompt meta for template
        if (empty($sections['template'])) {
            $meta_prompt = get_post_meta($post->ID, '_tool_prompt', true);
            if (!empty($meta_prompt)) {
                $sections['template'] = $meta_prompt;
            }
        }

        return $sections;
    }

    /**
     * Build context bundle from linked publications
     *
     * @param int $publication_id
     * @return array
     */
    private function build_context_bundle(int $publication_id): array {
        $styles = [];
        $contents = [];
        $citations = [];
        $assembled_parts = [];

        // Get linked styles
        $linked_styles = Picasso_Adapter::get_tool_linked_styles($publication_id);
        foreach ($linked_styles as $style_id) {
            if ($this->permissions->can_see_publication($style_id)) {
                $style_post = get_post($style_id);
                if ($style_post) {
                    $variant = get_post_meta($style_id, '_ml_style_variant', true) ?: 'default';
                    $styles[] = [
                        'id' => $style_id,
                        'title' => $style_post->post_title,
                        'variant' => $variant,
                    ];
                    $citations[] = [
                        'type' => 'style',
                        'id' => $style_id,
                        'title' => $style_post->post_title,
                        'url' => get_permalink($style_id),
                    ];

                    // Add style content to assembled
                    $assembled_parts[] = "## Style: {$style_post->post_title} ({$variant})\n" . strip_tags($style_post->post_content);
                }
            }
        }

        // Get linked contents (data, docs)
        $linked_contents = Picasso_Adapter::get_tool_linked_contents($publication_id);
        foreach ($linked_contents as $content_id) {
            if ($this->permissions->can_see_publication($content_id)) {
                $content_post = get_post($content_id);
                if ($content_post) {
                    $type = get_post_meta($content_id, '_ml_publication_type', true) ?: 'doc';
                    $contents[] = [
                        'id' => $content_id,
                        'title' => $content_post->post_title,
                        'type' => $type,
                    ];
                    $citations[] = [
                        'type' => $type,
                        'id' => $content_id,
                        'title' => $content_post->post_title,
                        'url' => get_permalink($content_id),
                    ];

                    // Add content to assembled (truncated)
                    $content_text = strip_tags($content_post->post_content);
                    if (mb_strlen($content_text) > 1000) {
                        $content_text = mb_substr($content_text, 0, 1000) . '...';
                    }
                    $assembled_parts[] = "## {$type}: {$content_post->post_title}\n{$content_text}";
                }
            }
        }

        return [
            'styles' => $styles,
            'contents' => $contents,
            'style_count' => count($styles),
            'content_count' => count($contents),
            'citations' => $citations,
            'assembled' => implode("\n\n", $assembled_parts),
        ];
    }

    /**
     * Build WHY section (explainability)
     *
     * @param \WP_Post $post
     * @param int $publication_id
     * @return string
     */
    private function build_why_section(\WP_Post $post, int $publication_id): string {
        $why_parts = [];

        // Rating info
        $avg_rating = (float) get_post_meta($publication_id, '_ml_average_rating', true);
        $rating_count = (int) get_post_meta($publication_id, '_ml_rating_count', true);
        if ($rating_count > 0) {
            $why_parts[] = sprintf("Note moyenne: %.1f/5 (%d avis)", $avg_rating, $rating_count);
        }

        // Best-of status
        $is_best = get_post_meta($publication_id, '_ml_is_best_of', true);
        if ($is_best) {
            $why_parts[] = "Marqué comme meilleur exemple";
        }

        // Favorites count
        $favorites_count = (int) get_post_meta($publication_id, '_ml_favorites_count', true);
        if ($favorites_count > 0) {
            $why_parts[] = sprintf("%d utilisateur(s) l'ont en favori", $favorites_count);
        }

        // Usage count
        $usage_count = (int) get_post_meta($publication_id, '_ml_usage_count', true);
        if ($usage_count > 0) {
            $why_parts[] = sprintf("Utilisé %d fois", $usage_count);
        }

        // Recency
        $days_since = (time() - strtotime($post->post_modified)) / 86400;
        if ($days_since < 7) {
            $why_parts[] = "Mis à jour récemment";
        }

        // Comments/engagement
        if ($post->comment_count > 0) {
            $why_parts[] = sprintf("%d commentaire(s)", $post->comment_count);
        }

        return !empty($why_parts) ? "- " . implode("\n- ", $why_parts) : '';
    }

    /**
     * Query publications that are prompts
     */
    private function query_prompt_publications(array $query_args): array {
        // Add label filter - try taxonomy first
        $tax_query = [
            'relation' => 'OR',
            [
                'taxonomy' => 'publication_label',
                'field' => 'slug',
                'terms' => ['prompt', 'tool', 'template'],
            ],
        ];

        // Also include by meta if taxonomy not used
        $query_args['meta_query'][] = [
            'relation' => 'OR',
            [
                'key' => '_ml_publication_type',
                'value' => ['prompt', 'tool', 'template'],
                'compare' => 'IN',
            ],
            [
                'key' => '_publication_type',
                'value' => ['prompt', 'tool', 'template'],
                'compare' => 'IN',
            ],
        ];

        // Try with taxonomy
        $query_with_tax = $query_args;
        $query_with_tax['tax_query'] = $tax_query;

        $query = new \WP_Query($query_with_tax);
        if ($query->have_posts()) {
            return $query->posts;
        }

        // Fallback: query without taxonomy
        $query = new \WP_Query($query_args);
        return $query->posts;
    }

    /**
     * Format a prompt for prompts/list response
     */
    private function format_prompt_list_item(\WP_Post $post, int $space_id): array {
        $excerpt = wp_trim_words(strip_tags($post->post_content), 20);
        $step = Picasso_Adapter::get_publication_step($post->ID);

        // Extract arguments from template
        $arguments = $this->extract_arguments($post);

        $item = [
            'name' => self::NAME_PREFIX . $post->ID,
            'description' => $post->post_title . ($excerpt ? " - {$excerpt}" : ''),
        ];

        // Add arguments if detected
        if (!empty($arguments)) {
            $item['arguments'] = $arguments;
        }

        return $item;
    }

    /**
     * Get prompt template from publication
     */
    private function get_prompt_template(\WP_Post $post): string {
        // Priority 1: _tool_prompt meta
        $prompt = get_post_meta($post->ID, '_tool_prompt', true);
        if (!empty($prompt)) {
            return $prompt;
        }

        // Priority 2: _ml_instruction meta
        $instruction = get_post_meta($post->ID, '_ml_instruction', true);
        if (!empty($instruction)) {
            return $instruction;
        }

        // Priority 3: Extract from content with markers
        $content = $post->post_content;
        if (preg_match('/<!-- instruction -->(.*?)<!-- \/instruction -->/s', $content, $matches)) {
            return trim($matches[1]);
        }

        // Priority 4: Full content
        return $content;
    }

    /**
     * Extract argument definitions from template
     */
    private function extract_arguments(\WP_Post $post): array {
        $template = $this->get_prompt_template($post);
        $arguments = [];

        // Match {{variable}} and {variable} patterns
        if (preg_match_all('/\{\{?([a-zA-Z_][a-zA-Z0-9_]*)\}?\}/', $template, $matches)) {
            $found_vars = array_unique($matches[1]);

            // Common variable mappings
            $common_descriptions = [
                'input' => 'The main input text to process',
                'text' => 'The text content',
                'content' => 'The content to work with',
                'language' => 'Target language (e.g., French, English)',
                'tone' => 'Tone of voice (e.g., professional, casual)',
                'format' => 'Output format (e.g., markdown, plain)',
                'output_format' => 'Desired output format',
                'context' => 'Additional context information',
                'audience' => 'Target audience',
                'length' => 'Desired length (short, medium, long)',
                'style' => 'Style variant to use',
                'client' => 'Client name or information',
                'prospect' => 'Prospect name or information',
            ];

            foreach ($found_vars as $var) {
                $var_lower = strtolower($var);
                $arguments[] = [
                    'name' => $var,
                    'description' => $common_descriptions[$var_lower] ?? "Value for {$var}",
                    'required' => in_array($var_lower, ['input', 'text', 'content'], true),
                ];
            }
        }

        return $arguments;
    }

    /**
     * Render prompt template with arguments
     */
    private function render_template(string $template, array $arguments): string {
        $rendered = $template;

        foreach ($arguments as $key => $value) {
            // Replace both {{key}} and {key} formats
            $rendered = str_replace(
                ["{{" . $key . "}}", "{" . $key . "}"],
                $value,
                $rendered
            );
        }

        return trim($rendered);
    }

    /**
     * Get capability declaration for MCP initialize
     */
    public static function get_capability(): array {
        return [
            'prompts' => [
                'listChanged' => true,
            ],
        ];
    }

    /**
     * Check if prompts capability is available
     */
    public static function is_available(): bool {
        return post_type_exists('publication');
    }

    /**
     * Get prompt name prefix for external use
     */
    public static function get_name_prefix(): string {
        return self::NAME_PREFIX;
    }

    /**
     * Parse publication ID from prompt name
     *
     * @param string $name Prompt name (marylink.prompt.pub_<id>)
     * @return int|null Publication ID or null if invalid
     */
    public static function parse_publication_id(string $name): ?int {
        if (!str_starts_with($name, self::NAME_PREFIX)) {
            return null;
        }
        $id = (int) substr($name, strlen(self::NAME_PREFIX));
        return $id > 0 ? $id : null;
    }
}
