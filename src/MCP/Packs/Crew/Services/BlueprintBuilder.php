<?php
/**
 * Blueprint Builder - Builds tool assembly blueprints
 *
 * Creates structured blueprints from selected components
 * for tool assembly.
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Packs\Crew\Services;

class BlueprintBuilder {

    const VERSION = '1.0.0';

    /**
     * Build a blueprint from selected components
     *
     * @param array $prompt Selected prompt
     * @param array $contents Selected contents
     * @param array|null $style Selected style (optional)
     * @param int $space_id Target space ID
     * @param float $compat_score Compatibility score
     * @return array Blueprint
     */
    public static function build(
        array $prompt,
        array $contents,
        ?array $style,
        int $space_id,
        float $compat_score
    ): array {
        $content_ids = array_map(fn($c) => $c['id'], $contents);

        return [
            'space_id' => $space_id,
            'prompt_id' => $prompt['id'],
            'content_ids' => $content_ids,
            'style_id' => $style ? $style['id'] : null,
            'compat_score' => round($compat_score, 3),
            'components' => [
                'prompt' => self::format_component($prompt, 'prompt'),
                'contents' => array_map(fn($c) => self::format_component($c, 'content'), $contents),
                'style' => $style ? self::format_component($style, 'style') : null,
            ],
            'metadata' => [
                'version' => self::VERSION,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'component_count' => 1 + count($contents) + ($style ? 1 : 0),
            ],
        ];
    }

    /**
     * Format a component for the blueprint
     */
    private static function format_component(array $item, string $type): array {
        $excerpt = $item['excerpt'] ?? null;
        if (!$excerpt && !empty($item['content'])) {
            $excerpt = self::trim_words($item['content'], 20);
        }

        return [
            'id' => $item['id'],
            'title' => $item['title'] ?? $item['name'] ?? '',
            'type' => $type,
            'excerpt' => $excerpt ?? '',
            'author_id' => $item['author_id'] ?? null,
        ];
    }

    /**
     * Trim words helper (WordPress-independent)
     */
    private static function trim_words(string $text, int $num_words = 20): string {
        if (function_exists('wp_trim_words')) {
            return wp_trim_words($text, $num_words);
        }

        $words = preg_split('/\s+/', trim(strip_tags($text)));
        if (count($words) <= $num_words) {
            return implode(' ', $words);
        }

        return implode(' ', array_slice($words, 0, $num_words)) . '...';
    }

    /**
     * Validate a blueprint structure
     *
     * @param array $blueprint Blueprint to validate
     * @return array Validation result ['valid' => bool, 'errors' => array]
     */
    public static function validate(array $blueprint): array {
        $errors = [];

        if (empty($blueprint['prompt_id'])) {
            $errors[] = 'prompt_id is required';
        }

        if (!isset($blueprint['space_id'])) {
            $errors[] = 'space_id is required';
        }

        if (!isset($blueprint['content_ids']) || !is_array($blueprint['content_ids'])) {
            $errors[] = 'content_ids must be an array';
        }

        if (isset($blueprint['compat_score'])) {
            $score = (float) $blueprint['compat_score'];
            if ($score < 0 || $score > 1) {
                $errors[] = 'compat_score must be between 0 and 1';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Serialize blueprint to JSON for storage
     *
     * @param array $blueprint Blueprint to serialize
     * @return string JSON string
     */
    public static function serialize(array $blueprint): string {
        // Remove the full components for storage (only keep IDs)
        $minimal = [
            'prompt_id' => $blueprint['prompt_id'],
            'content_ids' => $blueprint['content_ids'],
            'style_id' => $blueprint['style_id'],
            'space_id' => $blueprint['space_id'],
            'compat_score' => $blueprint['compat_score'],
            'version' => $blueprint['metadata']['version'] ?? self::VERSION,
        ];

        return function_exists('wp_json_encode') ? wp_json_encode($minimal) : json_encode($minimal);
    }

    /**
     * Deserialize blueprint from JSON storage
     *
     * @param string $json JSON string
     * @return array|null Blueprint or null if invalid
     */
    public static function deserialize(string $json): ?array {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // Validate required fields
        if (!isset($data['prompt_id'])) {
            return null;
        }

        return [
            'prompt_id' => (int) $data['prompt_id'],
            'content_ids' => array_map('intval', $data['content_ids'] ?? []),
            'style_id' => isset($data['style_id']) ? (int) $data['style_id'] : null,
            'space_id' => (int) ($data['space_id'] ?? 0),
            'compat_score' => (float) ($data['compat_score'] ?? 0),
            'metadata' => [
                'version' => $data['version'] ?? self::VERSION,
                'deserialized_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }

    /**
     * Merge two blueprints (for composition)
     *
     * @param array $base Base blueprint
     * @param array $overlay Overlay blueprint (takes precedence)
     * @return array Merged blueprint
     */
    public static function merge(array $base, array $overlay): array {
        $merged = $base;

        // Overlay prompt if provided
        if (!empty($overlay['prompt_id'])) {
            $merged['prompt_id'] = $overlay['prompt_id'];
        }

        // Merge content_ids (union)
        if (!empty($overlay['content_ids'])) {
            $merged['content_ids'] = array_unique(array_merge(
                $merged['content_ids'] ?? [],
                $overlay['content_ids']
            ));
        }

        // Overlay style if provided
        if (isset($overlay['style_id'])) {
            $merged['style_id'] = $overlay['style_id'];
        }

        // Overlay space if provided
        if (!empty($overlay['space_id'])) {
            $merged['space_id'] = $overlay['space_id'];
        }

        // Recalculate metadata
        $merged['metadata'] = [
            'version' => self::VERSION,
            'merged_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'component_count' => 1 + count($merged['content_ids'] ?? []) + ($merged['style_id'] ? 1 : 0),
        ];

        // Invalidate compat_score (needs recalculation)
        $merged['compat_score'] = null;

        return $merged;
    }

    /**
     * Create a diff between two blueprints
     *
     * @param array $old Old blueprint
     * @param array $new New blueprint
     * @return array Differences
     */
    public static function diff(array $old, array $new): array {
        $changes = [];

        if (($old['prompt_id'] ?? null) !== ($new['prompt_id'] ?? null)) {
            $changes['prompt_id'] = [
                'from' => $old['prompt_id'] ?? null,
                'to' => $new['prompt_id'] ?? null,
            ];
        }

        $old_contents = $old['content_ids'] ?? [];
        $new_contents = $new['content_ids'] ?? [];
        $added_contents = array_diff($new_contents, $old_contents);
        $removed_contents = array_diff($old_contents, $new_contents);

        if (!empty($added_contents) || !empty($removed_contents)) {
            $changes['content_ids'] = [
                'added' => array_values($added_contents),
                'removed' => array_values($removed_contents),
            ];
        }

        if (($old['style_id'] ?? null) !== ($new['style_id'] ?? null)) {
            $changes['style_id'] = [
                'from' => $old['style_id'] ?? null,
                'to' => $new['style_id'] ?? null,
            ];
        }

        if (($old['space_id'] ?? null) !== ($new['space_id'] ?? null)) {
            $changes['space_id'] = [
                'from' => $old['space_id'] ?? null,
                'to' => $new['space_id'] ?? null,
            ];
        }

        return [
            'has_changes' => !empty($changes),
            'changes' => $changes,
        ];
    }
}
