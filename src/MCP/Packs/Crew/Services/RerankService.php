<?php
/**
 * Rerank Service - AI-powered result reranking
 *
 * Reranks search results based on semantic relevance using:
 * - Title + excerpt + tags for lightweight scoring
 * - AI Engine for semantic understanding
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Packs\Crew\Services;

use MCP_No_Headless\Integration\AI_Engine_Bridge;

class RerankService {

    const VERSION = '1.0.0';

    // Cache duration in seconds
    const CACHE_DURATION = 1800;

    /**
     * Rerank items based on query relevance
     *
     * @param array $items Items to rerank (must have id, title/name, excerpt/content)
     * @param string $query Query to rank against
     * @param int $top_k Number of top results to return
     * @return array Reranked items with _rerank_score
     */
    public static function rerank(array $items, string $query, int $top_k = 5): array {
        if (empty($items)) {
            return [];
        }

        if (count($items) <= 1) {
            return $items;
        }

        // Check cache (WordPress-independent)
        $cache_key = self::get_cache_key($items, $query);
        $cached = self::get_cache($cache_key);
        if ($cached !== false) {
            return array_slice($cached, 0, $top_k);
        }

        // Try AI reranking if available
        if (class_exists(AI_Engine_Bridge::class) && AI_Engine_Bridge::is_available()) {
            $result = self::ai_rerank($items, $query);
            if ($result) {
                self::set_cache($cache_key, $result, self::CACHE_DURATION);
                return array_slice($result, 0, $top_k);
            }
        }

        // Fallback: lexical scoring
        $result = self::lexical_rerank($items, $query);
        self::set_cache($cache_key, $result, self::CACHE_DURATION);
        return array_slice($result, 0, $top_k);
    }

    /**
     * Get cached value (WordPress-independent)
     */
    private static function get_cache(string $key) {
        if (function_exists('get_transient')) {
            return get_transient($key);
        }
        // Fallback: no caching outside WordPress
        return false;
    }

    /**
     * Set cached value (WordPress-independent)
     */
    private static function set_cache(string $key, $value, int $duration): bool {
        if (function_exists('set_transient')) {
            return set_transient($key, $value, $duration);
        }
        // Fallback: no caching outside WordPress
        return false;
    }

    /**
     * AI-powered reranking using embeddings or LLM
     */
    private static function ai_rerank(array $items, string $query): ?array {
        // Prepare items for scoring
        $items_text = [];
        foreach ($items as $index => $item) {
            $text = self::get_item_text($item);
            $items_text[] = "[$index] " . $text;
        }

        $items_list = implode("\n", $items_text);

        $prompt = <<<PROMPT
Tu es un expert en recherche d'information. Classe ces éléments par pertinence pour la requête donnée.

Requête: {$query}

Éléments à classer:
{$items_list}

Retourne UNIQUEMENT un tableau JSON des indices triés par pertinence décroissante, avec un score de 0 à 1.
Format: [[index, score], [index, score], ...]
Exemple: [[2, 0.95], [0, 0.8], [1, 0.6]]
PROMPT;

        try {
            $response = AI_Engine_Bridge::chat([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.1,
                'max_tokens' => 300,
            ]);

            if (empty($response['content'])) {
                return null;
            }

            $content = $response['content'];

            // Extract JSON array
            if (preg_match('/\[\s*\[.*\]\s*\]/s', $content, $matches)) {
                $rankings = json_decode($matches[0], true);
            } else {
                $rankings = json_decode($content, true);
            }

            if (!is_array($rankings)) {
                return null;
            }

            // Apply rankings to items
            $reranked = [];
            foreach ($rankings as $rank) {
                if (is_array($rank) && count($rank) >= 2) {
                    $index = (int) $rank[0];
                    $score = (float) $rank[1];

                    if (isset($items[$index])) {
                        $item = $items[$index];
                        $item['_rerank_score'] = $score;
                        $item['_rerank_source'] = 'ai';
                        $reranked[] = $item;
                    }
                }
            }

            // Add any items not in the ranking (with low score)
            foreach ($items as $index => $item) {
                $found = false;
                foreach ($reranked as $r) {
                    if ($r['id'] === $item['id']) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $item['_rerank_score'] = 0.1;
                    $item['_rerank_source'] = 'ai_fallback';
                    $reranked[] = $item;
                }
            }

            return $reranked;
        } catch (\Exception $e) {
            error_log('[RerankService] AI rerank failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Lexical reranking based on keyword matching
     */
    private static function lexical_rerank(array $items, string $query): array {
        $query_terms = self::tokenize($query);

        foreach ($items as &$item) {
            $item_text = self::get_item_text($item);
            $item_terms = self::tokenize($item_text);

            $score = self::calculate_bm25_score($query_terms, $item_terms, $item_text);
            $item['_rerank_score'] = $score;
            $item['_rerank_source'] = 'lexical';
        }

        // Sort by score descending
        usort($items, function ($a, $b) {
            return ($b['_rerank_score'] ?? 0) <=> ($a['_rerank_score'] ?? 0);
        });

        return $items;
    }

    /**
     * Get combined text from item for scoring
     */
    private static function get_item_text(array $item): string {
        $parts = [];

        // Title/name (weighted higher by repetition)
        $title = $item['title'] ?? $item['name'] ?? '';
        if ($title) {
            $parts[] = $title;
            $parts[] = $title; // Repeat for higher weight
        }

        // Excerpt
        if (!empty($item['excerpt'])) {
            $parts[] = $item['excerpt'];
        }

        // Content (truncated)
        if (!empty($item['content'])) {
            $parts[] = mb_substr($item['content'], 0, 500);
        }

        // Tags
        if (!empty($item['tags']) && is_array($item['tags'])) {
            $parts[] = implode(' ', $item['tags']);
        }

        // Label
        if (!empty($item['label'])) {
            $parts[] = $item['label'];
        }

        return implode(' ', $parts);
    }

    /**
     * Tokenize text into terms
     */
    private static function tokenize(string $text): array {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $terms = preg_split('/\s+/', $text);
        return array_filter($terms, fn($t) => strlen($t) > 1);
    }

    /**
     * Calculate simplified BM25-like score
     */
    private static function calculate_bm25_score(array $query_terms, array $doc_terms, string $full_text): float {
        if (empty($query_terms) || empty($doc_terms)) {
            return 0.0;
        }

        $k1 = 1.2;
        $b = 0.75;
        $avg_dl = 100; // Average document length assumption
        $dl = count($doc_terms);

        $score = 0.0;
        $term_freq = array_count_values($doc_terms);

        foreach ($query_terms as $term) {
            $tf = $term_freq[$term] ?? 0;
            if ($tf > 0) {
                // Simplified IDF (assuming term is somewhat rare)
                $idf = log(2.0);

                // BM25 term score
                $numerator = $tf * ($k1 + 1);
                $denominator = $tf + $k1 * (1 - $b + $b * ($dl / $avg_dl));
                $score += $idf * ($numerator / $denominator);
            }
        }

        // Normalize to 0-1 range (approximate)
        $max_possible = count($query_terms) * 2.0;
        $normalized = min(1.0, $score / $max_possible);

        // Bonus for exact phrase match
        $query_phrase = implode(' ', $query_terms);
        if (str_contains(mb_strtolower($full_text), $query_phrase)) {
            $normalized = min(1.0, $normalized + 0.2);
        }

        // Bonus for title match
        if (str_contains(mb_strtolower($full_text), $query_phrase)) {
            $normalized = min(1.0, $normalized + 0.1);
        }

        return round($normalized, 3);
    }

    /**
     * Generate cache key for items + query
     */
    private static function get_cache_key(array $items, string $query): string {
        $ids = array_map(fn($item) => $item['id'] ?? 0, $items);
        sort($ids);
        return 'ml_rr_' . md5(implode(',', $ids) . '|' . $query);
    }
}
