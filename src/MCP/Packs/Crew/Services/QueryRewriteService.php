<?php
/**
 * Query Rewrite Service - Expands search queries for better retrieval
 *
 * Uses AI to expand queries with:
 * - Synonyms and related terms
 * - Entity detection (clients, projects, products)
 * - Domain-specific terminology
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Packs\Crew\Services;

use MCP_No_Headless\Integration\AI_Engine_Bridge;

class QueryRewriteService {

    const VERSION = '1.0.0';

    // Cache duration in seconds
    const CACHE_DURATION = 3600;

    /**
     * Rewrite and expand a query
     *
     * @param string $query Original query
     * @param string $language Language code (fr, en, etc.)
     * @return array Expanded query data
     */
    public static function rewrite(string $query, string $language = 'fr'): array {
        // Check cache first (WordPress-independent)
        $cache_key = 'ml_qr_' . md5($query . $language);
        $cached = self::get_cache($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Try AI expansion if available
        if (class_exists(AI_Engine_Bridge::class) && AI_Engine_Bridge::is_available()) {
            $result = self::ai_expand($query, $language);
            if ($result) {
                self::set_cache($cache_key, $result, self::CACHE_DURATION);
                return $result;
            }
        }

        // Fallback: basic keyword extraction
        $result = self::basic_expand($query, $language);
        self::set_cache($cache_key, $result, self::CACHE_DURATION);
        return $result;
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
     * AI-powered query expansion
     */
    private static function ai_expand(string $query, string $language): ?array {
        $prompt = self::get_expansion_prompt($language);

        $system_message = $prompt['system'];
        $user_message = $prompt['user'] . "\n\nQuery: " . $query;

        try {
            $response = AI_Engine_Bridge::chat([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => $system_message],
                    ['role' => 'user', 'content' => $user_message],
                ],
                'temperature' => 0.3,
                'max_tokens' => 500,
            ]);

            if (empty($response['content'])) {
                return null;
            }

            // Parse JSON response
            $content = $response['content'];

            // Extract JSON from markdown code block if present
            if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
                $content = $matches[1];
            }

            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            return [
                'original_query' => $query,
                'expanded_query' => self::build_expanded_query($query, $data),
                'keywords' => $data['keywords'] ?? [],
                'entities' => $data['entities'] ?? [],
                'negative_keywords' => $data['negative_keywords'] ?? [],
                'source' => 'ai',
            ];
        } catch (\Exception $e) {
            error_log('[QueryRewriteService] AI expansion failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Basic keyword extraction fallback
     */
    private static function basic_expand(string $query, string $language): array {
        $keywords = [];
        $entities = [];

        // Extract words, filter stopwords
        $stopwords = self::get_stopwords($language);
        $words = preg_split('/\s+/', mb_strtolower($query));
        $words = array_filter($words, function ($word) use ($stopwords) {
            return strlen($word) > 2 && !in_array($word, $stopwords);
        });

        $keywords = array_values($words);

        // Simple entity detection (capitalized words in original)
        preg_match_all('/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\b/', $query, $matches);
        if (!empty($matches[0])) {
            $entities = array_map(function ($entity) {
                return ['name' => $entity, 'type' => 'unknown'];
            }, array_unique($matches[0]));
        }

        // Add synonyms from local dictionary
        $synonyms = self::get_synonyms($keywords, $language);
        $keywords = array_unique(array_merge($keywords, $synonyms));

        return [
            'original_query' => $query,
            'expanded_query' => implode(' ', $keywords),
            'keywords' => $keywords,
            'entities' => $entities,
            'negative_keywords' => [],
            'source' => 'basic',
        ];
    }

    /**
     * Build expanded query string
     */
    private static function build_expanded_query(string $original, array $data): string {
        $parts = [$original];

        // Add keywords (limited)
        if (!empty($data['keywords'])) {
            $keywords = array_slice($data['keywords'], 0, 5);
            $parts = array_merge($parts, $keywords);
        }

        // Add entity names
        if (!empty($data['entities'])) {
            foreach ($data['entities'] as $entity) {
                if (isset($entity['name'])) {
                    $parts[] = $entity['name'];
                }
            }
        }

        return implode(' ', array_unique($parts));
    }

    /**
     * Get expansion prompt by language
     */
    private static function get_expansion_prompt(string $language): array {
        $prompts = [
            'fr' => [
                'system' => <<<'PROMPT'
Tu es un expert en recherche d'information. Ton rôle est d'analyser une requête utilisateur et d'extraire des informations pour améliorer la recherche.

Retourne UNIQUEMENT un objet JSON valide avec cette structure:
{
  "keywords": ["mot1", "mot2", ...],
  "entities": [{"name": "Nom", "type": "client|projet|produit|organisation|personne"}],
  "negative_keywords": ["mot_exclu1", ...]
}

Les keywords doivent inclure:
- Les termes importants de la requête
- Des synonymes pertinents
- Des termes du domaine métier associés

Les entities sont les noms propres détectés (clients, projets, produits, personnes).
PROMPT,
                'user' => 'Analyse cette requête de recherche et extrais les informations:',
            ],
            'en' => [
                'system' => <<<'PROMPT'
You are an information retrieval expert. Your role is to analyze a user query and extract information to improve search.

Return ONLY a valid JSON object with this structure:
{
  "keywords": ["word1", "word2", ...],
  "entities": [{"name": "Name", "type": "client|project|product|organization|person"}],
  "negative_keywords": ["excluded_word1", ...]
}

Keywords should include:
- Important terms from the query
- Relevant synonyms
- Associated domain-specific terms

Entities are detected proper nouns (clients, projects, products, people).
PROMPT,
                'user' => 'Analyze this search query and extract information:',
            ],
        ];

        return $prompts[$language] ?? $prompts['fr'];
    }

    /**
     * Get stopwords by language
     */
    private static function get_stopwords(string $language): array {
        $stopwords = [
            'fr' => ['le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'et', 'ou', 'mais', 'donc', 'car',
                     'ni', 'que', 'qui', 'quoi', 'dont', 'pour', 'par', 'sur', 'sous', 'avec', 'sans',
                     'dans', 'en', 'à', 'au', 'aux', 'ce', 'cette', 'ces', 'mon', 'ma', 'mes', 'ton',
                     'ta', 'tes', 'son', 'sa', 'ses', 'notre', 'votre', 'leur', 'leurs', 'je', 'tu',
                     'il', 'elle', 'nous', 'vous', 'ils', 'elles', 'être', 'avoir', 'faire', 'est',
                     'sont', 'a', 'ont', 'fait', 'très', 'plus', 'moins', 'bien', 'mal'],
            'en' => ['the', 'a', 'an', 'and', 'or', 'but', 'is', 'are', 'was', 'were', 'be', 'been',
                     'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
                     'should', 'may', 'might', 'must', 'can', 'to', 'of', 'in', 'for', 'on', 'with',
                     'at', 'by', 'from', 'as', 'into', 'through', 'during', 'before', 'after',
                     'above', 'below', 'between', 'under', 'again', 'further', 'then', 'once',
                     'here', 'there', 'when', 'where', 'why', 'how', 'all', 'each', 'few', 'more',
                     'most', 'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same',
                     'so', 'than', 'too', 'very', 'just', 'i', 'you', 'he', 'she', 'it', 'we', 'they'],
        ];

        return $stopwords[$language] ?? $stopwords['fr'];
    }

    /**
     * Get synonyms from local dictionary
     */
    private static function get_synonyms(array $keywords, string $language): array {
        // Basic synonym dictionary - can be extended
        $synonyms_dict = [
            'fr' => [
                'créer' => ['faire', 'produire', 'générer', 'construire'],
                'comparer' => ['confronter', 'analyser', 'évaluer'],
                'résumer' => ['synthétiser', 'condenser', 'récapituler'],
                'traduire' => ['convertir', 'transposer'],
                'améliorer' => ['optimiser', 'perfectionner', 'enrichir'],
                'analyser' => ['examiner', 'étudier', 'évaluer'],
                'client' => ['compte', 'prospect', 'partenaire'],
                'projet' => ['initiative', 'programme', 'mission'],
                'document' => ['fichier', 'rapport', 'note'],
                'outil' => ['instrument', 'utilitaire', 'fonction'],
                'prompt' => ['instruction', 'consigne', 'directive'],
                'style' => ['ton', 'format', 'manière'],
            ],
            'en' => [
                'create' => ['make', 'produce', 'generate', 'build'],
                'compare' => ['contrast', 'analyze', 'evaluate'],
                'summarize' => ['synthesize', 'condense', 'recap'],
                'translate' => ['convert', 'transpose'],
                'improve' => ['optimize', 'enhance', 'enrich'],
                'analyze' => ['examine', 'study', 'evaluate'],
                'client' => ['customer', 'account', 'prospect'],
                'project' => ['initiative', 'program', 'mission'],
                'document' => ['file', 'report', 'note'],
                'tool' => ['instrument', 'utility', 'function'],
            ],
        ];

        $dict = $synonyms_dict[$language] ?? $synonyms_dict['fr'];
        $result = [];

        foreach ($keywords as $keyword) {
            $keyword_lower = mb_strtolower($keyword);
            if (isset($dict[$keyword_lower])) {
                $result = array_merge($result, $dict[$keyword_lower]);
            }
        }

        return array_slice(array_unique($result), 0, 10);
    }
}
