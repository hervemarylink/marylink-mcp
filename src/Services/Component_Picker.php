<?php
/**
 * Component Picker - Auto-sélection des meilleurs contenus dans un espace
 *
 * Algorithme de scoring :
 *   1. Meta _ml_bootstrap_data_id exacte → +100
 *   2. Label/type publication correspond → +50
 *   3. Titre contient mot-clé → +30
 *   4. Slug contient mot-clé → +20
 *   5. Publication récente (< 30 jours) → +10
 *   6. Publication longue (> 500 chars) → +5
 *
 * Tie-breaker : post_modified DESC
 * Fallback : Si score < 20 → publication_id = null
 *
 * @package MCP_No_Headless
 * @since 2.6.0
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\MCP\Permission_Checker;

class Component_Picker {

    /**
     * Score minimum pour considérer un match valide
     */
    private const MIN_SCORE = 20;

    /**
     * Seuil de récence (30 jours)
     */
    private const RECENCY_DAYS = 30;

    /**
     * Seuil de longueur (500 caractères)
     */
    private const LENGTH_THRESHOLD = 500;

    private int $user_id;
    private int $space_id;
    private Permission_Checker $permissions;

    /**
     * Cache des publications de l'espace
     */
    private ?array $publications_cache = null;

    public function __construct(int $user_id, int $space_id) {
        $this->user_id = $user_id;
        $this->space_id = $space_id;
        $this->permissions = new Permission_Checker($user_id);
    }

    /**
     * Trouver la meilleure publication pour un data_id
     *
     * @param string $data_id Identifiant du type de données (catalog, pricing, etc.)
     * @param array $keywords Mots-clés à rechercher
     * @return array ['found' => bool, 'publication_id' => int|null, 'title' => string|null, 'score' => float]
     */
    public function pick(string $data_id, array $keywords = []): array {
        // Charger les publications de l'espace
        $publications = $this->get_space_publications();

        if (empty($publications)) {
            return [
                'found' => false,
                'publication_id' => null,
                'title' => null,
                'score' => 0,
                'reason' => 'Aucune publication dans l\'espace',
            ];
        }

        // Scorer chaque publication
        $scored = [];
        foreach ($publications as $post) {
            $score = $this->score_publication($post, $data_id, $keywords);
            if ($score > 0) {
                $scored[] = [
                    'post' => $post,
                    'score' => $score,
                ];
            }
        }

        // Trier par score décroissant, puis par date de modification
        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return strtotime($b['post']->post_modified) <=> strtotime($a['post']->post_modified);
        });

        // Prendre le meilleur
        if (!empty($scored) && $scored[0]['score'] >= self::MIN_SCORE) {
            $best = $scored[0];
            return [
                'found' => true,
                'publication_id' => $best['post']->ID,
                'title' => $best['post']->post_title,
                'score' => $best['score'],
                'reason' => 'Match trouvé (score: ' . $best['score'] . ')',
            ];
        }

        return [
            'found' => false,
            'publication_id' => null,
            'title' => null,
            'score' => !empty($scored) ? $scored[0]['score'] : 0,
            'reason' => 'Aucun match suffisant (score min: ' . self::MIN_SCORE . ')',
        ];
    }

    /**
     * Scorer une publication pour un data_id donné
     */
    private function score_publication(\WP_Post $post, string $data_id, array $keywords): int {
        $score = 0;

        // 1. Meta exacte _ml_bootstrap_data_id
        $meta_data_id = get_post_meta($post->ID, '_ml_bootstrap_data_id', true);
        if ($meta_data_id === $data_id) {
            $score += 100;
        }

        // 2. Type de publication correspond
        $pub_type = get_post_meta($post->ID, '_ml_publication_type', true);
        if ($pub_type === 'data' || $pub_type === 'contenu') {
            $score += 20;
        }
        if ($pub_type === 'style' && $data_id === 'brand_guide') {
            $score += 50;
        }

        // 3. Titre contient un mot-clé
        $title_lower = mb_strtolower($post->post_title);
        foreach ($keywords as $kw) {
            if (strpos($title_lower, mb_strtolower($kw)) !== false) {
                $score += 30;
                break; // Un seul bonus titre
            }
        }

        // 4. Slug contient un mot-clé
        $slug_lower = mb_strtolower($post->post_name);
        foreach ($keywords as $kw) {
            if (strpos($slug_lower, mb_strtolower($kw)) !== false) {
                $score += 20;
                break;
            }
        }

        // 5. Publication récente
        $modified_time = strtotime($post->post_modified);
        $threshold_time = time() - (self::RECENCY_DAYS * 24 * 3600);
        if ($modified_time > $threshold_time) {
            $score += 10;
        }

        // 6. Publication longue (contenu substantiel)
        $content_length = strlen($post->post_content);
        if ($content_length > self::LENGTH_THRESHOLD) {
            $score += 5;
        }

        // Malus : placeholder non complété
        $is_placeholder = get_post_meta($post->ID, '_ml_is_placeholder', true);
        if ($is_placeholder) {
            $score -= 50; // On préfère ne pas réutiliser un placeholder vide
        }

        return max(0, $score);
    }

    /**
     * Charger les publications de l'espace (avec cache)
     */
    private function get_space_publications(): array {
        if ($this->publications_cache !== null) {
            return $this->publications_cache;
        }

        $posts = get_posts([
            'post_type' => 'publication',
            'post_parent' => $this->space_id,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 200, // Limite raisonnable
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => false,
        ]);

        // Filtrer par permissions
        $accessible = [];
        foreach ($posts as $post) {
            if ($this->permissions->can_see_publication($post->ID)) {
                $accessible[] = $post;
            }
        }

        $this->publications_cache = $accessible;
        return $accessible;
    }

    /**
     * Rechercher des candidats pour un data_id (sans sélection finale)
     * Utile pour afficher des alternatives à l'admin
     */
    public function find_candidates(string $data_id, array $keywords, int $limit = 5): array {
        $publications = $this->get_space_publications();
        $candidates = [];

        foreach ($publications as $post) {
            $score = $this->score_publication($post, $data_id, $keywords);
            if ($score > 0) {
                $candidates[] = [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'score' => $score,
                    'type' => get_post_meta($post->ID, '_ml_publication_type', true),
                    'modified' => $post->post_modified,
                ];
            }
        }

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($candidates, 0, $limit);
    }
}
