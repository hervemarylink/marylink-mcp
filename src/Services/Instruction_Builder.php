<?php
/**
 * Instruction Builder - Construit l'instruction d'un outil avec URLs
 *
 * Assemble :
 *   1. Instruction de base (template)
 *   2. URLs des contenus
 *   3. URLs des styles
 *   4. Tâche finale
 *
 * @package MCP_No_Headless
 * @since 2.6.0
 */

namespace MCP_No_Headless\Services;

class Instruction_Builder {

    /**
     * Nombre maximum d'URLs par instruction
     */
    private const MAX_URLS = 20;

    /**
     * Construire l'instruction complète avec URLs
     *
     * @param string $base_instruction Instruction de base (rôle, contexte)
     * @param array $content_ids IDs des publications de contenu
     * @param array $style_ids IDs des publications de style
     * @param string $final_task Tâche finale (ce que doit faire le LLM)
     * @return string Instruction complète avec URLs
     */
    public static function build(
        string $base_instruction,
        array $content_ids,
        array $style_ids,
        string $final_task
    ): string {
        $urls = [];

        // Collecter les URLs des contenus
        foreach ($content_ids as $id) {
            $url = self::get_publication_url((int) $id);
            if ($url) {
                $urls[] = $url;
            }
        }

        // Collecter les URLs des styles
        foreach ($style_ids as $id) {
            $url = self::get_publication_url((int) $id);
            if ($url) {
                $urls[] = $url;
            }
        }

        // Dédupliquer
        $urls = array_values(array_unique($urls));

        // Limiter
        if (count($urls) > self::MAX_URLS) {
            $urls = array_slice($urls, 0, self::MAX_URLS);
        }

        // Assembler
        $parts = [];

        // 1. Instruction de base
        $parts[] = trim($base_instruction);

        // 2. URLs (si présentes)
        if (!empty($urls)) {
            $parts[] = implode("\n", $urls);
        }

        // 3. Tâche finale
        $parts[] = trim($final_task);

        return implode("\n\n", $parts);
    }

    /**
     * Obtenir l'URL d'une publication
     */
    private static function get_publication_url(int $post_id): ?string {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'publication') {
            return null;
        }

        // Construire l'URL au format attendu par URL_Resolver
        // Format : https://xxx.marylink.io/publication/slug/
        $slug = $post->post_name;
        if (empty($slug)) {
            $slug = sanitize_title($post->post_title);
        }

        return home_url("/publication/{$slug}/");
    }

    /**
     * Construire une instruction simple (sans URLs, pour debug/preview)
     */
    public static function buildPreview(
        string $base_instruction,
        array $content_titles,
        array $style_titles,
        string $final_task
    ): string {
        $parts = [];

        $parts[] = trim($base_instruction);

        if (!empty($content_titles)) {
            $parts[] = "## Sources\n" . implode("\n", array_map(fn($t) => "- {$t}", $content_titles));
        }

        if (!empty($style_titles)) {
            $parts[] = "## Styles\n" . implode("\n", array_map(fn($t) => "- {$t}", $style_titles));
        }

        $parts[] = trim($final_task);

        return implode("\n\n", $parts);
    }

    /**
     * Extraire les URLs d'une instruction existante
     */
    public static function extractUrls(string $instruction): array {
        $pattern = '~https?://[a-zA-Z0-9.-]+\.marylink\.(io|net)/publication/[a-zA-Z0-9_-]+/?~i';
        
        if (preg_match_all($pattern, $instruction, $matches)) {
            return array_unique($matches[0]);
        }

        return [];
    }

    /**
     * Remplacer les URLs dans une instruction
     */
    public static function replaceUrls(string $instruction, array $old_to_new_map): string {
        foreach ($old_to_new_map as $old_url => $new_url) {
            $instruction = str_replace($old_url, $new_url, $instruction);
        }
        return $instruction;
    }
}
