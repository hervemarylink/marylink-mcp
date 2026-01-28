<?php
/**
 * URL Resolver v2 - Prod-ready
 * 
 * Améliorations :
 *   - Support singulier/pluriel (publication/publications, style/styles)
 *   - Normalisation vers API JSON (évite injection HTML)
 *   - Allowlist stricte anti-SSRF
 *   - Fallback graceful (source indisponible)
 *   - Wrapper anti prompt-injection
 *
 * @package MCP_No_Headless
 * @since 2.6.0
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\MCP\Permission_Checker;

class URL_Resolver {

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * Domaines autorisés (anti-SSRF)
     * En prod : uniquement *.marylink.net et *.marylink.io
     */
    private const ALLOWED_DOMAINS = [
        'marylink.net',
        'marylink.io',
    ];

    /**
     * Pattern pour détecter les URLs Marylink
     * Supporte :
     *   - /publication/<slug> (singulier)
     *   - /publications/<slug> (pluriel legacy)
     *   - /style/<slug>
     *   - /styles/<slug>
     */
    private const URL_PATTERN = '~(https?://([a-zA-Z0-9.-]+)/(?:publication|publications|style|styles)/([a-zA-Z0-9_-]+)/?|/(?:publication|publications|style|styles)/([a-zA-Z0-9_-]+)/?)~i';

    /**
     * Limites de contexte (protège le LLM)
     */
    private const MAX_URLS = 20;
    private const MAX_CHARS_PER_REF = 50000;
    private const MAX_TOTAL_CHARS = 200000;
    private const FETCH_TIMEOUT_SECONDS = 5;

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    private int $user_id;
    private Permission_Checker $permissions;
    private ?string $run_id = null;
    private ?string $current_domain = null;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    public function __construct(int $user_id, ?string $run_id = null) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
        $this->run_id = $run_id;
        $this->current_domain = wp_parse_url(home_url(), PHP_URL_HOST);
    }

    // =========================================================================
    // MAIN RESOLVE METHOD
    // =========================================================================

    /**
     * Résoudre toutes les URLs dans un contenu
     *
     * @param string $content Contenu avec URLs
     * @return array ['content' => string, 'resolved' => array, 'errors' => array, 'stats' => array]
     */
    public function resolve(string $content): array {
        $start_time = microtime(true);
        
        $resolved = [];
        $errors = [];
        $total_chars = 0;
        $cache_hits = 0;

        // Extraire toutes les URLs
        $urls = $this->extract_urls($content);

        if (empty($urls)) {
            return $this->build_response($content, [], [], 0, $start_time);
        }

        // Limiter le nombre d'URLs
        if (count($urls) > self::MAX_URLS) {
            $urls = array_slice($urls, 0, self::MAX_URLS);
            $errors[] = [
                'url' => null,
                'error' => 'max_urls_exceeded',
                'message' => 'Limite de ' . self::MAX_URLS . ' URLs atteinte, certaines ignorées',
            ];
        }

        // Résoudre chaque URL
        foreach ($urls as $url_info) {
            $url = $url_info['url'];
            $slug = $url_info['slug'];
            $type = $url_info['type'];
            $domain = $url_info['domain'];

            // Vérifier limite totale
            if ($total_chars >= self::MAX_TOTAL_CHARS) {
                $errors[] = [
                    'url' => $url,
                    'slug' => $slug,
                    'error' => 'total_limit_reached',
                    'message' => 'Limite de caractères totale atteinte',
                ];
                continue;
            }

            // Vérifier allowlist (anti-SSRF)
            if (!$this->is_domain_allowed($domain)) {
                $errors[] = [
                    'url' => $url,
                    'slug' => $slug,
                    'error' => 'domain_not_allowed',
                    'message' => "Domaine non autorisé: {$domain}",
                ];
                continue;
            }

            // Résoudre selon le contexte
            $result = $this->resolve_single($url_info);

            if ($result['success']) {
                $pub_content = $result['content'];
                $original_length = strlen($pub_content);
                $was_truncated = false;

                // Appliquer limite par référence
                if (strlen($pub_content) > self::MAX_CHARS_PER_REF) {
                    $pub_content = substr($pub_content, 0, self::MAX_CHARS_PER_REF) 
                        . "\n\n[... contenu tronqué (" . number_format($original_length) . " caractères) ...]";
                    $was_truncated = true;
                }

                $injected_chars = strlen($pub_content);
                $total_chars += $injected_chars;

                // Remplacer l'URL par le contenu injecté (avec wrapper anti prompt-injection)
                $injection = $this->format_injection($result['title'], $pub_content, $type);
                $content = str_replace($url, $injection, $content);

                $resolved[] = [
                    'url' => $url,
                    'slug' => $slug,
                    'type' => $type,
                    'id' => $result['id'],
                    'title' => $result['title'],
                    'original_chars' => $original_length,
                    'injected_chars' => $injected_chars,
                    'truncated' => $was_truncated,
                    'source' => $result['source'],
                ];

                if ($result['source'] === 'cache') {
                    $cache_hits++;
                }
            } else {
                // Fallback : injecter un message d'erreur plutôt que casser tout
                $fallback = $this->format_fallback($slug, $result['error']);
                $content = str_replace($url, $fallback, $content);

                $errors[] = [
                    'url' => $url,
                    'slug' => $slug,
                    'error' => $result['error'],
                    'message' => $result['message'],
                ];
            }
        }

        return $this->build_response($content, $resolved, $errors, $total_chars, $start_time, $cache_hits);
    }

    // =========================================================================
    // SINGLE URL RESOLUTION
    // =========================================================================

    /**
     * Résoudre une seule URL
     */
    private function resolve_single(array $url_info): array {
        $slug = $url_info['slug'];
        $type = $url_info['type'];
        $domain = $url_info['domain'];
        $is_local = $this->is_local_domain($domain);

        // Résolution locale (même instance)
        if ($is_local) {
            return $this->resolve_local($slug, $type);
        }

        // Résolution distante (autre instance Marylink, ex: lib.marylink.net)
        return $this->resolve_remote($url_info);
    }

    /**
     * Résolution locale (publication dans la même instance)
     */
    private function resolve_local(string $slug, string $type): array {
        // Chercher par slug
        $post = $this->find_publication_by_slug($slug);

        if (!$post) {
            return [
                'success' => false,
                'error' => 'not_found',
                'message' => "Publication non trouvée: {$slug}",
            ];
        }

        // Vérifier permissions
        if (!$this->permissions->can_see_publication($post->ID)) {
            return [
                'success' => false,
                'error' => 'permission_denied',
                'message' => "Accès refusé: {$slug}",
            ];
        }

        // Extraire le contenu
        $content = $this->extract_content($post);

        return [
            'success' => true,
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $content,
            'source' => 'local',
        ];
    }

    /**
     * Résolution distante via API JSON (ex: lib.marylink.net)
     */
    private function resolve_remote(array $url_info): array {
        $domain = $url_info['domain'];
        $slug = $url_info['slug'];
        $type = $url_info['type'];

        // Construire l'URL API (normalisation vers JSON)
        $api_url = $this->build_api_url($domain, $type, $slug);

        // Fetch avec timeout strict
        $response = wp_remote_get($api_url, [
            'timeout' => self::FETCH_TIMEOUT_SECONDS,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => 'fetch_failed',
                'message' => $response->get_error_message(),
            ];
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status === 403 || $status === 401) {
            return [
                'success' => false,
                'error' => 'permission_denied',
                'message' => "Accès refusé par le serveur distant",
            ];
        }

        if ($status === 404) {
            return [
                'success' => false,
                'error' => 'not_found',
                'message' => "Publication non trouvée sur {$domain}",
            ];
        }

        if ($status !== 200) {
            return [
                'success' => false,
                'error' => 'http_error',
                'message' => "Erreur HTTP {$status}",
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['content'])) {
            return [
                'success' => false,
                'error' => 'invalid_response',
                'message' => "Réponse API invalide",
            ];
        }

        return [
            'success' => true,
            'id' => $data['id'] ?? null,
            'title' => $data['title'] ?? $slug,
            'content' => $data['content'],
            'source' => 'remote',
        ];
    }

    // =========================================================================
    // HELPERS - URL PARSING
    // =========================================================================

    /**
     * Extraire les URLs du contenu
     */
    private function extract_urls(string $content): array {
        $urls = [];

        if (preg_match_all(self::URL_PATTERN, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = $match[0];
                $domain = $match[2] ?? $this->current_domain;
                $slug = !empty($match[3]) ? $match[3] : (!empty($match[4]) ? $match[4] : null);

                // Détecter le type (publication ou style)
                $type = 'publication';
                if (preg_match('/style/', $url)) {
                    $type = 'style';
                }

                if ($slug) {
                    $urls[] = [
                        'url' => $url,
                        'slug' => $slug,
                        'type' => $type,
                        'domain' => $domain,
                        'is_relative' => strpos($url, 'http') !== 0,
                    ];
                }
            }
        }

        // Dédupliquer par URL
        $seen = [];
        $unique = [];
        foreach ($urls as $u) {
            if (!isset($seen[$u['url']])) {
                $seen[$u['url']] = true;
                $unique[] = $u;
            }
        }

        return $unique;
    }

    /**
     * Vérifier si le domaine est autorisé (anti-SSRF)
     */
    private function is_domain_allowed(?string $domain): bool {
        if (empty($domain)) {
            return true; // URL relative = local = OK
        }

        // Local domain always allowed
        if ($this->is_local_domain($domain)) {
            return true;
        }

        // Check allowlist
        foreach (self::ALLOWED_DOMAINS as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vérifier si c'est le domaine local
     */
    private function is_local_domain(?string $domain): bool {
        return empty($domain) || $domain === $this->current_domain;
    }

    /**
     * Construire l'URL API JSON
     */
    private function build_api_url(string $domain, string $type, string $slug): string {
        $endpoint = ($type === 'style') ? 'styles' : 'publications';
        return "https://{$domain}/wp-json/marylink/v1/{$endpoint}/{$slug}";
    }

    // =========================================================================
    // HELPERS - CONTENT
    // =========================================================================

    /**
     * Trouver une publication par slug
     */
    private function find_publication_by_slug(string $slug): ?\WP_Post {
        // Si c'est un ID numérique
        if (is_numeric($slug)) {
            $post = get_post((int) $slug);
            if ($post && $post->post_type === 'publication') {
                return $post;
            }
            return null;
        }

        // Chercher par slug (post_name)
        $posts = get_posts([
            'post_type' => 'publication',
            'name' => $slug,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 1,
            'suppress_filters' => false,
        ]);

        return $posts[0] ?? null;
    }

    /**
     * Extraire le contenu d'une publication
     */
    private function extract_content(\WP_Post $post): string {
        // Priorité : meta _ml_instruction (pour les outils)
        $instruction = get_post_meta($post->ID, '_ml_instruction', true);
        if (!empty($instruction)) {
            return $instruction;
        }

        // Sinon : post_content nettoyé
        $content = $post->post_content;

        // Nettoyer le HTML (évite injection de menus/footer/bruit)
        if (strpos($content, '<') !== false) {
            $content = preg_replace('/<br\s*\/?>/i', "\n", $content);
            $content = preg_replace('/<\/p>/i', "\n\n", $content);
            $content = preg_replace('/<\/div>/i', "\n", $content);
            $content = preg_replace('/<\/li>/i', "\n", $content);
            $content = preg_replace('/<\/h[1-6]>/i', "\n\n", $content);
            $content = wp_strip_all_tags($content);
            $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        }

        return trim($content);
    }

    // =========================================================================
    // HELPERS - FORMATTING
    // =========================================================================

    /**
     * Formater l'injection avec wrapper anti prompt-injection
     */
    private function format_injection(string $title, string $content, string $type): string {
        $label = ($type === 'style') ? 'STYLE GUIDE' : 'REFERENCE';
        
        return "\n\n=== BEGIN {$label}: {$title} ===\n{$content}\n=== END {$label} ===\n\n";
    }

    /**
     * Formater le fallback en cas d'erreur
     */
    private function format_fallback(string $slug, string $error): string {
        return "\n\n[Source '{$slug}' indisponible: {$error}]\n\n";
    }

    /**
     * Construire la réponse finale avec métriques
     */
    private function build_response(
        string $content,
        array $resolved,
        array $errors,
        int $total_chars,
        float $start_time,
        int $cache_hits = 0
    ): array {
        $latency_ms = (microtime(true) - $start_time) * 1000;
        $url_count = count($resolved) + count($errors);

        $stats = [
            'urls_found' => $url_count,
            'resolved' => count($resolved),
            'errors' => count($errors),
            'total_chars' => $total_chars,
            'total_tokens_approx' => (int) ($total_chars / 4),
            'truncated' => $total_chars >= self::MAX_TOTAL_CHARS 
                || count(array_filter($resolved, fn($r) => $r['truncated'] ?? false)) > 0,
            'latency_ms' => round($latency_ms, 2),
            'cache_hit_rate' => $url_count > 0 ? round($cache_hits / $url_count, 2) : 0,
        ];

        // Émettre métrique
        do_action('ml_metrics', 'url_resolve', [
            'run_id' => $this->run_id,
            'user_id' => $this->user_id,
            'url_count' => $url_count,
            'success_count' => count($resolved),
            'error_count' => count($errors),
            'error_types' => array_column($errors, 'error'),
            'injected_chars' => $total_chars,
            'injected_tokens' => $stats['total_tokens_approx'],
            'truncated' => $stats['truncated'],
            'latency_ms' => $stats['latency_ms'],
            'cache_hit_rate' => $stats['cache_hit_rate'],
            'local_count' => count(array_filter($resolved, fn($r) => ($r['source'] ?? '') === 'local')),
            'remote_count' => count(array_filter($resolved, fn($r) => ($r['source'] ?? '') === 'remote')),
        ]);

        return [
            'content' => $content,
            'resolved' => $resolved,
            'errors' => $errors,
            'stats' => $stats,
        ];
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    /**
     * Version statique pour usage simple
     */
    public static function resolveForUser(string $content, int $user_id, ?string $run_id = null): array {
        $resolver = new self($user_id, $run_id);
        return $resolver->resolve($content);
    }

    /**
     * Vérifier si une URL est une URL Marylink valide
     */
    public static function isValidMaryLinkUrl(string $url): bool {
        return preg_match(self::URL_PATTERN, $url) === 1;
    }

    /**
     * Extraire le slug d'une URL Marylink
     */
    public static function extractSlug(string $url): ?string {
        if (preg_match(self::URL_PATTERN, $url, $match)) {
            return !empty($match[3]) ? $match[3] : (!empty($match[4]) ? $match[4] : null);
        }
        return null;
    }
}
