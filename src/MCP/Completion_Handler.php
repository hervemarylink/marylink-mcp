<?php
/**
 * Completion Handler - MCP completion/complete capability
 *
 * Implements standard MCP completions capability:
 * - completion/complete: Autocomplete for prompt arguments
 *
 * Supports:
 * - style: Available style variants
 * - language: Common languages
 * - destination: Accessible publication destinations (encoded for anti-leak)
 * - tone: Tone variants
 *
 * @package MCP_No_Headless
 * @see https://modelcontextprotocol.info/specification/draft/server/completions/
 */

namespace MCP_No_Headless\MCP;

use MCP_No_Headless\Services\Approved_Steps_Resolver;
use MCP_No_Headless\Picasso\Picasso_Adapter;

class Completion_Handler {

    private int $user_id;
    private Permission_Checker $permissions;

    /**
     * Common language suggestions
     */
    private const LANGUAGES = [
        'fr' => 'Français',
        'en' => 'English',
        'es' => 'Español',
        'de' => 'Deutsch',
        'it' => 'Italiano',
        'pt' => 'Português',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'ar' => 'العربية',
        'zh' => '中文',
        'ja' => '日本語',
    ];

    /**
     * Common tone suggestions
     */
    private const TONES = [
        'professional' => 'Professional and formal',
        'casual' => 'Casual and friendly',
        'warm' => 'Warm and empathetic',
        'direct' => 'Direct and concise',
        'persuasive' => 'Persuasive and compelling',
        'educational' => 'Educational and informative',
        'premium' => 'Premium and exclusive',
        'neutral' => 'Neutral and objective',
    ];

    /**
     * Common format suggestions
     */
    private const FORMATS = [
        'markdown' => 'Markdown formatting',
        'plain' => 'Plain text',
        'html' => 'HTML markup',
        'bullet_points' => 'Bullet point list',
        'numbered' => 'Numbered list',
        'table' => 'Table format',
        'json' => 'JSON structure',
    ];

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
    }

    /**
     * Handle completion/complete method
     *
     * @param array $params Request params
     * @return array MCP completion/complete response
     */
    public function complete(array $params): array {
        $ref = $params['ref'] ?? [];
        $argument = $params['argument'] ?? [];

        // Validate ref
        $ref_type = $ref['type'] ?? '';
        $ref_name = $ref['name'] ?? '';

        if ($ref_type !== 'ref/prompt') {
            return [
                'completion' => [
                    'values' => [],
                    'hasMore' => false,
                ],
            ];
        }

        // Get argument info
        $arg_name = $argument['name'] ?? '';
        $arg_value = $argument['value'] ?? '';

        // Route to appropriate completion handler
        $completions = match (strtolower($arg_name)) {
            'style', 'style_id' => $this->complete_style($ref_name, $arg_value),
            'language', 'lang' => $this->complete_language($arg_value),
            'destination', 'space_id', 'target_space' => $this->complete_destination($arg_value),
            'tone' => $this->complete_tone($arg_value),
            'format', 'output_format' => $this->complete_format($arg_value),
            default => [],
        };

        return [
            'completion' => [
                'values' => array_slice($completions, 0, 20), // Max 20 suggestions
                'hasMore' => count($completions) > 20,
                'total' => count($completions),
            ],
        ];
    }

    /**
     * Complete style argument
     *
     * Returns available style variants for the referenced prompt.
     * If no prompt specified, returns all accessible styles.
     *
     * @param string $ref_name Prompt reference name
     * @param string $value Current input value
     * @return array Completion values
     */
    private function complete_style(string $ref_name, string $value): array {
        $styles = [];

        // If we have a prompt reference, get linked styles
        $publication_id = Prompts_Handler::parse_publication_id($ref_name);

        if ($publication_id && $this->permissions->can_see_publication($publication_id)) {
            // Get styles linked to this prompt
            $linked_styles = Picasso_Adapter::get_tool_linked_styles($publication_id);

            foreach ($linked_styles as $style_id) {
                if ($this->permissions->can_see_publication($style_id)) {
                    $style_post = get_post($style_id);
                    if ($style_post) {
                        $variant = get_post_meta($style_id, '_ml_style_variant', true) ?: 'default';
                        $label = "{$style_post->post_title} ({$variant})";

                        // Filter by current input
                        if (empty($value) || stripos($label, $value) !== false || stripos($variant, $value) !== false) {
                            $styles[] = $variant;
                        }
                    }
                }
            }
        }

        // If no linked styles, search all accessible styles
        if (empty($styles)) {
            $styles = $this->search_all_styles($value);
        }

        return array_unique($styles);
    }

    /**
     * Search all accessible styles
     */
    private function search_all_styles(string $value): array {
        $styles = [];
        $space_ids = $this->permissions->get_user_spaces();

        if (empty($space_ids)) {
            return [];
        }

        $query_args = [
            'post_type' => 'publication',
            'post_status' => 'publish',
            'post_parent__in' => $space_ids,
            'posts_per_page' => 50,
            'meta_query' => [
                [
                    'key' => '_ml_publication_type',
                    'value' => 'style',
                    'compare' => '=',
                ],
            ],
        ];

        if (!empty($value)) {
            $query_args['s'] = $value;
        }

        $query = new \WP_Query($query_args);

        foreach ($query->posts as $post) {
            if ($this->permissions->can_see_publication($post->ID)) {
                $variant = get_post_meta($post->ID, '_ml_style_variant', true) ?: 'default';
                $styles[] = $variant;
            }
        }

        return array_unique($styles);
    }

    /**
     * Complete language argument
     *
     * @param string $value Current input value
     * @return array Completion values
     */
    private function complete_language(string $value): array {
        $languages = [];

        foreach (self::LANGUAGES as $code => $name) {
            if (empty($value) || stripos($name, $value) !== false || stripos($code, $value) !== false) {
                $languages[] = $name;
            }
        }

        return $languages;
    }

    /**
     * Complete destination argument (space_id)
     *
     * Uses encoded format for anti-leak:
     * - Shows accessible spaces with readable names
     * - Returns space_id values that the user can use
     *
     * @param string $value Current input value
     * @return array Completion values
     */
    private function complete_destination(string $value): array {
        $destinations = [];
        $space_ids = $this->permissions->get_user_spaces();

        if (empty($space_ids)) {
            return [];
        }

        foreach ($space_ids as $space_id) {
            $space = get_post($space_id);
            if (!$space) {
                continue;
            }

            $label = $space->post_title;

            // Filter by current input
            if (empty($value) || stripos($label, $value) !== false) {
                // Return space ID as the value (for use in tool calls)
                $destinations[] = (string) $space_id;
            }
        }

        return $destinations;
    }

    /**
     * Complete tone argument
     *
     * @param string $value Current input value
     * @return array Completion values
     */
    private function complete_tone(string $value): array {
        $tones = [];

        foreach (self::TONES as $key => $description) {
            if (empty($value) || stripos($key, $value) !== false || stripos($description, $value) !== false) {
                $tones[] = $key;
            }
        }

        return $tones;
    }

    /**
     * Complete format argument
     *
     * @param string $value Current input value
     * @return array Completion values
     */
    private function complete_format(string $value): array {
        $formats = [];

        foreach (self::FORMATS as $key => $description) {
            if (empty($value) || stripos($key, $value) !== false || stripos($description, $value) !== false) {
                $formats[] = $key;
            }
        }

        return $formats;
    }

    /**
     * Get capability declaration for MCP initialize
     */
    public static function get_capability(): array {
        return [
            'completions' => new \stdClass(), // Empty object = capability supported
        ];
    }

    /**
     * Check if completions capability is available
     */
    public static function is_available(): bool {
        return post_type_exists('publication');
    }

    /**
     * Get destination info for a space_id (for anti-leak validation)
     *
     * @param int $space_id
     * @return array|null Space info or null if not accessible
     */
    public function get_destination_info(int $space_id): ?array {
        if (!$this->permissions->can_see_space($space_id)) {
            return null; // Anti-leak: same response for non-existent and inaccessible
        }

        $space = get_post($space_id);
        if (!$space || $space->post_type !== 'space') {
            return null;
        }

        return [
            'id' => $space_id,
            'title' => $space->post_title,
            'can_publish' => $this->permissions->can_publish_in_space($space_id),
        ];
    }
}
