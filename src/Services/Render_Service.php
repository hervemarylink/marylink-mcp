<?php
/**
 * Render Service - Content rendering and text extraction
 *
 * Provides:
 * - HTML to plain text conversion
 * - Excerpt generation
 * - Content sanitization for MCP output
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

class Render_Service {

    /**
     * Default excerpt length in characters
     */
    const DEFAULT_EXCERPT_LENGTH = 240;

    /**
     * Maximum content length for full text
     */
    const MAX_CONTENT_LENGTH = 50000;

    /**
     * Convert HTML to plain text
     *
     * @param string $html HTML content
     * @param bool $preserve_links Keep link URLs inline
     * @return string Plain text
     */
    public static function html_to_text(string $html, bool $preserve_links = false): string {
        if (empty($html)) {
            return '';
        }

        // Remove scripts and styles
        $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $text);

        // Handle links
        if ($preserve_links) {
            // Convert <a href="url">text</a> to "text (url)"
            $text = preg_replace_callback(
                '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i',
                function ($matches) {
                    $url = $matches[1];
                    $anchor = trim($matches[2]);
                    // Skip if URL is same as anchor text
                    if ($anchor === $url || strpos($url, $anchor) !== false) {
                        return $anchor;
                    }
                    return "{$anchor} ({$url})";
                },
                $text
            );
        }

        // Convert block elements to newlines
        $block_tags = ['p', 'div', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'tr', 'blockquote'];
        foreach ($block_tags as $tag) {
            $text = preg_replace("/<\/?{$tag}[^>]*>/i", "\n", $text);
        }

        // Convert list items
        $text = preg_replace('/<li[^>]*>/i', "\n• ", $text);

        // Strip remaining tags
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Generate excerpt from text
     *
     * @param string $text Text content
     * @param int $max_length Maximum length in characters
     * @param string $suffix Suffix to add if truncated
     * @return string Excerpt
     */
    public static function excerpt(string $text, int $max_length = self::DEFAULT_EXCERPT_LENGTH, string $suffix = '...'): string {
        if (empty($text)) {
            return '';
        }

        // Clean text first
        $text = self::html_to_text($text);

        if (mb_strlen($text) <= $max_length) {
            return $text;
        }

        // Find a good break point (word boundary)
        $excerpt = mb_substr($text, 0, $max_length);
        $last_space = mb_strrpos($excerpt, ' ');

        if ($last_space !== false && $last_space > $max_length * 0.7) {
            $excerpt = mb_substr($excerpt, 0, $last_space);
        }

        return trim($excerpt) . $suffix;
    }

    /**
     * Generate excerpt from HTML
     *
     * @param string $html HTML content
     * @param int $max_length Maximum length
     * @return string Excerpt
     */
    public static function excerpt_from_html(string $html, int $max_length = self::DEFAULT_EXCERPT_LENGTH): string {
        $text = self::html_to_text($html);
        return self::excerpt($text, $max_length);
    }

    /**
     * Prepare content for MCP output
     *
     * @param string $html HTML content
     * @param bool $include_text Include plain text version
     * @param int|null $max_length Maximum content length (null for no limit)
     * @return array [content_html, content_text]
     */
    public static function prepare_content(string $html, bool $include_text = true, ?int $max_length = self::MAX_CONTENT_LENGTH): array {
        // Sanitize HTML
        $html = wp_kses_post($html);

        // Truncate if needed
        if ($max_length !== null && mb_strlen($html) > $max_length) {
            $html = mb_substr($html, 0, $max_length) . '... [truncated]';
        }

        $result = [
            'content_html' => $html,
        ];

        if ($include_text) {
            $result['content_text'] = self::html_to_text($html);
        }

        return $result;
    }

    /**
     * Extract first paragraph from HTML
     *
     * @param string $html HTML content
     * @param int $max_length Maximum length
     * @return string First paragraph as text
     */
    public static function first_paragraph(string $html, int $max_length = 500): string {
        // Try to find first paragraph
        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $matches)) {
            $text = self::html_to_text($matches[1]);
            if (mb_strlen($text) > 50) { // Only use if substantial
                return self::excerpt($text, $max_length);
            }
        }

        // Fallback to regular excerpt
        return self::excerpt_from_html($html, $max_length);
    }

    /**
     * Count words in content
     *
     * @param string $html HTML content
     * @return int Word count
     */
    public static function word_count(string $html): int {
        $text = self::html_to_text($html);
        return str_word_count($text);
    }

    /**
     * Estimate reading time in minutes
     *
     * @param string $html HTML content
     * @param int $words_per_minute Reading speed
     * @return int Minutes
     */
    public static function reading_time(string $html, int $words_per_minute = 200): int {
        $words = self::word_count($html);
        return max(1, (int) ceil($words / $words_per_minute));
    }

    /**
     * Format date for display
     *
     * @param string $date Date string
     * @param string $format PHP date format
     * @return string Formatted date
     */
    public static function format_date(string $date, string $format = 'Y-m-d H:i'): string {
        $timestamp = strtotime($date);
        return $timestamp ? date($format, $timestamp) : $date;
    }

    /**
     * Format date as human readable relative time
     *
     * @param string $date Date string
     * @return string Relative time (e.g., "2 hours ago")
     */
    public static function format_date_relative(string $date): string {
        $timestamp = strtotime($date);
        if (!$timestamp) {
            return $date;
        }

        return human_time_diff($timestamp, current_time('timestamp')) . ' ago';
    }

    /**
     * Sanitize text input (for tool arguments)
     *
     * @param string $text Text to sanitize
     * @param int $max_length Maximum length
     * @return string Sanitized text
     */
    public static function sanitize_input(string $text, int $max_length = 10000): string {
        $text = sanitize_text_field($text);
        if (mb_strlen($text) > $max_length) {
            $text = mb_substr($text, 0, $max_length);
        }
        return $text;
    }

    /**
     * Sanitize HTML input (for content fields)
     *
     * @param string $html HTML to sanitize
     * @param int $max_length Maximum length
     * @return string Sanitized HTML
     */
    public static function sanitize_html(string $html, int $max_length = 50000): string {
        $html = wp_kses_post($html);
        if (mb_strlen($html) > $max_length) {
            $html = mb_substr($html, 0, $max_length);
        }
        return $html;
    }

    /**
     * Build summary object for a publication/space
     *
     * @param \WP_Post $post Post object
     * @param int $excerpt_length Excerpt length
     * @return array Summary data
     */
    public static function build_summary(\WP_Post $post, int $excerpt_length = self::DEFAULT_EXCERPT_LENGTH): array {
        $content = $post->post_content;

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'excerpt' => self::excerpt_from_html($content, $excerpt_length),
            'url' => get_permalink($post->ID),
            'date' => self::format_date($post->post_date),
            'date_relative' => self::format_date_relative($post->post_date),
        ];
    }

    /**
     * Extract headings from HTML content
     *
     * @param string $html HTML content
     * @return array Headings with level and text
     */
    public static function extract_headings(string $html): array {
        $headings = [];

        if (preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $headings[] = [
                    'level' => (int) $match[1],
                    'text' => self::html_to_text($match[2]),
                ];
            }
        }

        return $headings;
    }

    /**
     * Build table of contents from headings
     *
     * @param string $html HTML content
     * @param int $max_level Maximum heading level to include
     * @return array TOC entries
     */
    public static function build_toc(string $html, int $max_level = 3): array {
        $headings = self::extract_headings($html);
        $toc = [];

        foreach ($headings as $heading) {
            if ($heading['level'] <= $max_level) {
                $toc[] = [
                    'level' => $heading['level'],
                    'text' => $heading['text'],
                    'indent' => str_repeat('  ', $heading['level'] - 1),
                ];
            }
        }

        return $toc;
    }
}
