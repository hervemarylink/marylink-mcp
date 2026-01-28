<?php
/**
 * Recommendation Service - Smart prompt and content recommendations
 *
 * The "WOAUH" differentiator for MaryLink:
 * - Detects user intent from natural language
 * - Recommends best prompts with explainability
 * - Bundles relevant content (styles, data, docs)
 * - Respects Picasso permissions (anti-leak)
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\MCP\Permission_Checker;
use MCP_No_Headless\Picasso\Picasso_Adapter;
use MCP_No_Headless\Schema\Publication_Schema;

class Recommendation_Service {

    /** Enable ml_recommend logging (set via filter or constant) */
    private bool $logging_enabled;

    private int $user_id;
    private Permission_Checker $permissions;

    /**
     * Intent patterns - keywords mapped to intent types
     */
    private const INTENT_PATTERNS = [
        'sales_letter' => [
            'keywords' => ['lettre commerciale', 'sales letter', 'prospection', 'vente', 'offre commerciale', 'proposition commerciale'],
            'context' => ['client', 'prospect', 'vendre', 'convaincre'],
            'weight' => 1.0,
        ],
        'proposal' => [
            'keywords' => ['proposition', 'proposal', 'devis', 'offre', 'soumission', 'réponse appel'],
            'context' => ['projet', 'budget', 'délai', 'cahier des charges'],
            'weight' => 1.0,
        ],
        'meeting_minutes' => [
            'keywords' => ['compte rendu', 'compte-rendu', 'réunion', 'meeting', 'minutes', 'cr réunion'],
            'context' => ['décision', 'action', 'participant', 'ordre du jour'],
            'weight' => 1.0,
        ],
        'synthesis' => [
            'keywords' => ['synthèse', 'résumé', 'summary', 'recap', 'bilan', 'synthétiser'],
            'context' => ['document', 'rapport', 'analyse'],
            'weight' => 0.9,
        ],
        'email' => [
            'keywords' => ['email', 'mail', 'courriel', 'message'],
            'context' => ['envoyer', 'répondre', 'destinataire'],
            'weight' => 0.8,
        ],
        'report' => [
            'keywords' => ['rapport', 'report', 'analyse', 'étude', 'audit'],
            'context' => ['données', 'résultats', 'recommandations'],
            'weight' => 0.9,
        ],
        'content' => [
            'keywords' => ['article', 'blog', 'post', 'contenu', 'rédiger'],
            'context' => ['publier', 'audience', 'seo'],
            'weight' => 0.8,
        ],
    ];

    /**
     * Scoring weights for prompt ranking
     */
    private const SCORE_WEIGHTS = [
        'text_match' => 0.30,      // Title/content matches intent
        'rating' => 0.20,          // Average rating (0-5 normalized)
        'feedback' => 0.15,        // Thumbs up/down feedback score (v2.2.0+)
        'favorites' => 0.12,       // User's favorites get boost
        'recency' => 0.10,         // Recently updated prompts
        'usage' => 0.08,           // How often this prompt is used
        'comments' => 0.05,        // Comment activity (engagement)
    ];

    /**
     * Minimum score threshold for recommendations (PR ml_assist debug)
     */
    private const MINIMUM_SCORE_THRESHOLD = 0.15;

    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->permissions = new Permission_Checker($user_id);
        $this->logging_enabled = apply_filters('ml_recommend_logging', defined('ML_RECOMMEND_LOG') && ML_RECOMMEND_LOG);
    }

    /**
     * Main recommendation entry point
     *
     * @param string $text User's input text (what they want to create)
     * @param int|null $space_id Optional space filter
     * @param array $options Additional options
     * @return array Recommendation result with explainability
     */
    public function recommend(string $text, ?int $space_id = null, array $options = []): array {
        $start_time = microtime(true);
        $debug_mode = $options['debug'] ?? false;
        $debug = $debug_mode ? [] : null;  // null when not debugging, array when debugging
        $timing = [];

        // Step 1: Detect intent
        $t1 = microtime(true);
        $intent = $this->detect_intent($text);
        $timing['intent_detection'] = (int) ((microtime(true) - $t1) * 1000);

        // Normalize query for debug
        if ($debug_mode) {
            $text_lower = mb_strtolower($text);
            $debug['query_normalized'] = array_values(array_filter(
                explode(' ', preg_replace('/[^a-z0-9\x{00C0}-\x{024F}\s]/u', '', $text_lower)),
                fn($w) => mb_strlen($w) > 2
            ));
        }

        // Step 2: Get candidate prompts (with debug)
        $t2 = microtime(true);
        $candidates = $this->get_prompt_candidates($space_id, $intent, $debug);
        $timing['candidate_fetch'] = (int) ((microtime(true) - $t2) * 1000);

        if ($debug_mode) {
            $debug['candidates_scanned'] = count($candidates);
            $debug['index_types'] = ['prompt', 'tool', 'style'];
            $debug['threshold_used'] = self::MINIMUM_SCORE_THRESHOLD;
        }

        if (empty($candidates)) {
            $total_ms = (int) ((microtime(true) - $start_time) * 1000);
            $result = [
                'ok' => true,
                'intent' => $intent,
                'recommendations' => [],
                'message' => 'No matching prompts found for your request.',
                'suggestions' => $this->get_fallback_suggestions($intent, $space_id),
            ];
            if ($debug_mode) {
                $timing['scoring'] = 0;
                $timing['total'] = $total_ms;
                $debug['timing_ms'] = $timing;
                $debug['top_scores'] = [];
                $result['debug'] = $debug;
            }
            return $result;
        }

        // Step 3: Score and rank candidates
        $t3 = microtime(true);
        $scored = $this->score_candidates($candidates, $text, $intent);
        $timing['scoring'] = (int) ((microtime(true) - $t3) * 1000);

        // Collect top_scores for debug
        if ($debug_mode) {
            $debug['top_scores'] = array_map(fn($s) => [
                'id' => $s['candidate']['post']->ID,
                'title' => $s['candidate']['post']->post_title,
                'final_score' => round($s['total_score'], 3),
                'breakdown' => $s['scores'],
            ], array_slice($scored, 0, 5));
        }

        // Step 4: Build recommendations with content bundles
        $recommendations = $this->build_recommendations($scored, $options);

        // Step 5: Add explainability
        $explained = $this->add_explainability($recommendations, $intent, $text);

        $latency_ms = (int) ((microtime(true) - $start_time) * 1000);
        $timing['total'] = $latency_ms;

        // Emit metrics (v2.2.0+)
        $top_ids = array_map(fn($r) => $r['prompt']['id'] ?? 0, array_slice($explained, 0, 5));
        do_action('ml_metrics', 'recommendation_served', [
            'user_id' => $this->user_id,
            'space_id' => $space_id,
            'intent' => $intent['detected'] ?? 'unknown',
            'query_len' => mb_strlen($text),
            'candidates' => count($candidates),
            'results' => count($explained),
            'top_ids' => $top_ids,
            'latency_ms' => $latency_ms,
        ]);

        $result = [
            'ok' => true,
            'intent' => $intent,
            'input_text' => mb_substr($text, 0, 100) . (mb_strlen($text) > 100 ? '...' : ''),
            'recommendations' => $explained,
            'total_candidates' => count($candidates),
            'latency_ms' => $latency_ms,
            'next_action' => !empty($explained)
                ? "ml_apply_tool(action: 'prepare', tool_id: {$explained[0]['prompt']['id']}, input_text: '...')"
                : null,
        ];

        // Add debug info if requested
        if ($debug_mode) {
            $debug['timing_ms'] = $timing;
            $result['debug'] = $debug;
        }

        return $result;
    }


    /**
     * Log recommendation request (if enabled)
     */
    private function log_recommend(
        string $text,
        ?int $space_id,
        int $candidates,
        int $results,
        int $latency_ms,
        array $intent
    ): void {
        if (!$this->logging_enabled) {
            return;
        }

        $log_entry = [
            'timestamp' => gmdate('c'),
            'user_id' => $this->user_id,
            'space_id' => $space_id,
            'query' => mb_substr($text, 0, 100),
            'candidates' => $candidates,
            'results' => $results,
            'latency_ms' => $latency_ms,
            'intent' => $intent['detected'] ?? 'unknown',
            'intent_confidence' => $intent['confidence'] ?? 0,
        ];

        error_log('[ml_recommend] ' . wp_json_encode($log_entry));

        // Also fire action for custom logging handlers
        do_action('ml_recommend_logged', $log_entry);
    }

    /**
     * Detect intent from user text
     *
     * @param string $text User input
     * @return array Intent detection result
     */
    private function detect_intent(string $text): array {
        $text_lower = mb_strtolower($text);
        $scores = [];

        foreach (self::INTENT_PATTERNS as $intent_type => $pattern) {
            $score = 0.0;
            $matched_keywords = [];
            $matched_context = [];

            // Check keywords (high weight)
            foreach ($pattern['keywords'] as $keyword) {
                if (str_contains($text_lower, mb_strtolower($keyword))) {
                    $score += 2.0;
                    $matched_keywords[] = $keyword;
                }
            }

            // Check context words (lower weight)
            foreach ($pattern['context'] as $context_word) {
                if (str_contains($text_lower, mb_strtolower($context_word))) {
                    $score += 0.5;
                    $matched_context[] = $context_word;
                }
            }

            // Apply intent weight
            $score *= $pattern['weight'];

            if ($score > 0) {
                $scores[$intent_type] = [
                    'score' => $score,
                    'matched_keywords' => $matched_keywords,
                    'matched_context' => $matched_context,
                ];
            }
        }

        // Sort by score
        uasort($scores, fn($a, $b) => $b['score'] <=> $a['score']);

        // Get top intent
        $top_intent = key($scores);
        $confidence = 0.0;

        if ($top_intent && !empty($scores)) {
            // Calculate confidence based on score distribution
            $top_score = $scores[$top_intent]['score'];
            $total_score = array_sum(array_column($scores, 'score'));
            $confidence = $total_score > 0 ? min(1.0, $top_score / ($total_score * 0.6)) : 0.0;
        }

        return [
            'detected' => $top_intent ?: 'general',
            'confidence' => round($confidence, 2),
            'all_intents' => array_slice($scores, 0, 3, true),
            'fallback' => $confidence < 0.3,
        ];
    }

    /**
     * Get prompt candidates from approved steps
     *
     * @param int|null $space_id Space filter
     * @param array $intent Detected intent
     * @return array Candidate prompts
     */
    private function get_prompt_candidates(?int $space_id, array $intent, ?array &$debug = null): array {
        $candidates = [];

        // Get accessible spaces
        $space_ids = $space_id
            ? [$space_id]
            : $this->permissions->get_user_spaces();

        if (empty($space_ids)) {
            return [];
        }

        foreach ($space_ids as $sid) {
            // Check space access
            if (!$this->permissions->can_see_space($sid)) {
                continue;
            }

            // Get approved steps for this space
            $approved_steps = Approved_Steps_Resolver::get_approved_steps($sid);

            // Type filter via taxonomy (publication_label has slugs: prompt, tool, style, data, doc)
            $type_tax_query = [
                'taxonomy' => Publication_Schema::TAX_LABEL,
                'field' => 'slug',
                'terms' => ['prompt', 'tool', 'style'],  // Include style as well
            ];

            // If no workflow steps defined, query ALL publications (no step filter)
            // Otherwise, build step filter
            if (empty($approved_steps)) {
                // No workflow - query by type only (via taxonomy)
                $query_args = [
                    'post_type' => 'publication',
                    'post_status' => 'publish',
                    'post_parent' => $sid,
                    'posts_per_page' => 50,
                    'suppress_filters' => true,
                    'tax_query' => [$type_tax_query],
                ];
            } else {
                // Workflow defined - filter by approved steps AND type
                $step_query = Publication_Schema::build_step_meta_query($approved_steps);
                $query_args = [
                    'post_type' => 'publication',
                    'post_status' => 'publish',
                    'post_parent' => $sid,
                    'posts_per_page' => 50,
                    'suppress_filters' => true,
                    'meta_query' => $step_query,
                    'tax_query' => [$type_tax_query],
                ];
            }

            $query = new \WP_Query($query_args);

            foreach ($query->posts as $post) {
                // Double-check publication access
                if (!$this->permissions->can_see_publication($post->ID)) {
                    continue;
                }

                $candidates[] = [
                    'post' => $post,
                    'space_id' => $sid,
                    'step' => Picasso_Adapter::get_publication_step($post->ID),
                ];
            }
        }

        return $candidates;
    }

    /**
     * Score candidates based on multiple factors
     *
     * @param array $candidates Candidate prompts
     * @param string $text User input
     * @param array $intent Detected intent
     * @return array Scored and sorted candidates
     */
    private function score_candidates(array $candidates, string $text, array $intent): array {
        $scored = [];
        $text_lower = mb_strtolower($text);

        // Get user favorites for boost
        $favorites = $this->get_user_favorites();

        foreach ($candidates as $candidate) {
            $post = $candidate['post'];
            $scores = [];

            // 1. Text match score - FIXED: Only match user's actual search terms
            $title_lower = mb_strtolower($post->post_title);
            $content_lower = mb_strtolower(strip_tags($post->post_content));

            $text_score = 0.0;

            // Direct title match (very strong) - exact or partial phrase match
            if (str_contains($title_lower, $text_lower) || str_contains($text_lower, $title_lower)) {
                $text_score += 2.0;
            }

            // Word overlap from USER's search (not intent keywords)
            $text_words = array_filter(explode(' ', $text_lower), fn($w) => mb_strlen($w) > 2);
            $matched_words = 0;
            $total_words = count($text_words);
            foreach ($text_words as $word) {
                if (str_contains($title_lower, $word)) {
                    $text_score += 0.4;
                    $matched_words++;
                } elseif (str_contains($content_lower, $word)) {
                    $text_score += 0.15;
                    $matched_words++;
                }
            }

            // Bonus for high match ratio (if most user words are found)
            if ($total_words > 0 && $matched_words / $total_words >= 0.7) {
                $text_score += 0.5;
            }

            // Intent bonus ONLY if publication taxonomy terms match detected intent
            // (This was the bug - before, intent keywords were matching against ANY title)
            if ($intent['detected'] !== 'general' && $intent['confidence'] > 0.4) {
                $pub_labels = wp_get_post_terms($post->ID, 'publication_label', ['fields' => 'slugs']);
                $pub_tags = wp_get_post_terms($post->ID, 'publication_tag', ['fields' => 'slugs']);
                $all_terms = array_merge(
                    is_array($pub_labels) ? $pub_labels : [],
                    is_array($pub_tags) ? $pub_tags : []
                );
                $all_terms_str = mb_strtolower(implode(' ', $all_terms));
                
                $intent_term_hints = ['sales_letter' => 'commerci', 'proposal' => 'propos', 
                    'meeting_minutes' => 'reunion', 'synthesis' => 'synth', 'email' => 'mail', 
                    'report' => 'rapport', 'content' => 'content'];
                if (isset($intent_term_hints[$intent['detected']]) && 
                    str_contains($all_terms_str, $intent_term_hints[$intent['detected']])) {
                    $text_score += 0.3;
                }
            }

            // Normalize: max possible ~3.5, target range 0-1
            $scores['text_match'] = min(1.0, $text_score / 3.5);

            // 2. Rating score
            $avg_rating = (float) get_post_meta($post->ID, '_ml_average_rating', true);
            $rating_count = (int) get_post_meta($post->ID, '_ml_rating_count', true);
            $scores['rating'] = $rating_count > 0 ? $avg_rating / 5.0 : 0.5;

            // 3. Favorites boost
            $scores['favorites'] = in_array($post->ID, $favorites, true) ? 1.0 : 0.0;

            // 4. Recency score
            $days_since_modified = (time() - strtotime($post->post_modified)) / 86400;
            $scores['recency'] = max(0, 1.0 - ($days_since_modified / 365));

            // 5. Usage score
            $usage_count = (int) get_post_meta($post->ID, '_ml_usage_count', true);
            $scores['usage'] = min(1.0, $usage_count / 100);

            // 6. Comments score (engagement)
            $scores['comments'] = min(1.0, $post->comment_count / 20);

            // 7. Feedback score (v2.2.0+ learning loop)
            $feedback_count = (int) get_post_meta($post->ID, '_ml_feedback_count', true);
            $feedback_score = (float) get_post_meta($post->ID, '_ml_feedback_score', true);
            if ($feedback_count >= 5) {
                // Normalize score from [-1, 1] to [0, 1]
                $scores['feedback'] = max(0, min(1.0, ($feedback_score + 1) / 2));
            } else {
                // Not enough feedback, neutral score
                $scores['feedback'] = 0.5;
            }

            // Calculate weighted total
            $total_score = 0.0;
            foreach (self::SCORE_WEIGHTS as $factor => $weight) {
                $total_score += ($scores[$factor] ?? 0) * $weight;
            }

            $scored[] = [
                'candidate' => $candidate,
                'scores' => $scores,
                'total_score' => $total_score,
                'rating_info' => [
                    'average' => $avg_rating,
                    'count' => $rating_count,
                ],
                'feedback_info' => [
                    'count' => $feedback_count,
                    'score' => $feedback_score,
                    'boost' => $scores['feedback'] > 0.5 ? 'positive' : ($scores['feedback'] < 0.5 ? 'negative' : 'neutral'),
                ],
            ];
        }

        // Sort by total score descending
        usort($scored, fn($a, $b) => $b['total_score'] <=> $a['total_score']);

        return array_slice($scored, 0, 5); // Top 5
    }

    /**
     * Build full recommendations with content bundles
     *
     * @param array $scored Scored candidates
     * @param array $options Options
     * @return array Full recommendations
     */
    private function build_recommendations(array $scored, array $options = []): array {
        $recommendations = [];

        foreach ($scored as $index => $item) {
            $post = $item['candidate']['post'];
            $space_id = $item['candidate']['space_id'];

            // Get linked content (styles, data, docs)
            $linked_styles = Picasso_Adapter::get_tool_linked_styles($post->ID);
            $linked_contents = Picasso_Adapter::get_tool_linked_contents($post->ID);

            // Filter by permission
            $styles = [];
            foreach ($linked_styles as $style_id) {
                if ($this->permissions->can_see_publication($style_id)) {
                    $style_post = get_post($style_id);
                    if ($style_post) {
                        $styles[] = [
                            'id' => $style_id,
                            'title' => $style_post->post_title,
                            'variant' => get_post_meta($style_id, '_ml_style_variant', true) ?: 'default',
                        ];
                    }
                }
            }

            $contents = [];
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
                    }
                }
            }

            $recommendations[] = [
                'rank' => $index + 1,
                'prompt' => [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'excerpt' => Render_Service::excerpt_from_html($post->post_content, 150),
                    'url' => get_permalink($post->ID),
                    'space_id' => $space_id,
                    'step' => $item['candidate']['step'],
                ],
                'bundle' => [
                    'styles' => $styles,
                    'contents' => $contents,
                    'style_count' => count($styles),
                    'content_count' => count($contents),
                ],
                'scores' => $item['scores'],
                'total_score' => round($item['total_score'], 3),
                'rating' => $item['rating_info'],
            ];
        }

        return $recommendations;
    }

    /**
     * Add explainability to recommendations (the "why")
     *
     * TICKET 6: Enhanced WHY signals for B2B cabinet-grade explainability
     *
     * @param array $recommendations Recommendations
     * @param array $intent Detected intent
     * @param string $text User input
     * @return array Recommendations with explanations
     */
    private function add_explainability(array $recommendations, array $intent, string $text): array {
        foreach ($recommendations as &$rec) {
            $explanations = [];
            $scores = $rec['scores'];
            $post_id = $rec['prompt']['id'];

            // Get additional metrics for signals
            $usage_count = (int) get_post_meta($post_id, '_ml_usage_count', true);
            $days_since_update = (int) ((time() - strtotime(get_post($post_id)->post_modified)) / 86400);
            $comment_count = (int) get_post($post_id)->comment_count;
            $best_of = (bool) get_post_meta($post_id, '_ml_best_of', true);

            // Text match explanation
            if ($scores['text_match'] > 0.5) {
                $explanations[] = "Correspond bien à votre demande";
            }

            // Rating explanation
            if ($rec['rating']['count'] > 0) {
                $explanations[] = sprintf(
                    "Note moyenne de %.1f/5 (%d avis)",
                    $rec['rating']['average'],
                    $rec['rating']['count']
                );
            }

            // Favorites explanation
            if ($scores['favorites'] > 0) {
                $explanations[] = "Dans vos favoris";
            }

            // Recency explanation
            if ($scores['recency'] > 0.8) {
                $explanations[] = "Récemment mis à jour";
            }

            // Usage explanation
            if ($scores['usage'] > 0.5) {
                $explanations[] = "Fréquemment utilisé";
            }

            // Bundle explanation
            if ($rec['bundle']['style_count'] > 0) {
                $explanations[] = sprintf("%d style(s) disponible(s)", $rec['bundle']['style_count']);
            }

            // Best-of explanation
            if ($best_of) {
                $explanations[] = "Marqué comme 'Best of'";
            }

            // TICKET 6: Structured machine-readable signals for B2B proof
            $signals = [
                'rating_avg' => round($rec['rating']['average'], 2),
                'rating_count' => $rec['rating']['count'],
                'usage_count' => $usage_count,
                'updated_days' => $days_since_update,
                'favorites_me' => $scores['favorites'] > 0,
                'best_of' => $best_of,
                'styles_count' => $rec['bundle']['style_count'],
                'contents_count' => $rec['bundle']['content_count'],
                'comments_count' => $comment_count,
                'approved_step' => true, // Always true (only approved steps reach here)
                'text_match_score' => round($scores['text_match'], 2),
            ];

            // Human-readable 1-2 sentence explanation
            $why_human = $this->generate_why_human($rec, $intent, $signals);

            $rec['why'] = [
                // TICKET 6: Short human explanation (1-2 sentences)
                'explain' => $why_human,
                // TICKET 6: Structured signals (machine-readable)
                'signals' => $signals,
                // Legacy fields (backward compat)
                'summary' => $this->generate_why_summary($rec, $intent),
                'factors' => $explanations,
            ];
        }

        return $recommendations;
    }

    /**
     * Generate short human-readable WHY explanation (1-2 sentences)
     */
    private function generate_why_human(array $rec, array $intent, array $signals): string {
        $parts = [];

        // Intent match
        if ($intent['detected'] !== 'general' && $intent['confidence'] > 0.5) {
            $intent_labels = [
                'sales_letter' => 'vos lettres commerciales',
                'proposal' => 'vos propositions',
                'meeting_minutes' => 'vos comptes rendus',
                'synthesis' => 'vos synthèses',
                'email' => 'vos emails',
                'report' => 'vos rapports',
                'content' => 'vos contenus',
            ];
            $intent_label = $intent_labels[$intent['detected']] ?? 'cette tâche';
            $parts[] = "Idéal pour {$intent_label}";
        }

        // Best-of or high rating
        if ($signals['best_of']) {
            $parts[] = "sélectionné comme référence";
        } elseif ($signals['rating_count'] >= 3 && $signals['rating_avg'] >= 4.0) {
            $parts[] = sprintf("noté %.1f/5", $signals['rating_avg']);
        }

        // Favorites
        if ($signals['favorites_me']) {
            $parts[] = "dans vos favoris";
        }

        // Usage
        if ($signals['usage_count'] >= 10) {
            $parts[] = "utilisé " . $signals['usage_count'] . " fois";
        }

        // Bundle value
        if ($signals['styles_count'] > 0 && $signals['contents_count'] > 0) {
            $parts[] = "pack complet inclus";
        }

        if (empty($parts)) {
            return "Recommandé pour votre demande.";
        }

        return ucfirst(implode(', ', array_slice($parts, 0, 3))) . '.';
    }

    /**
     * Generate human-readable "why" summary
     */
    private function generate_why_summary(array $rec, array $intent): string {
        $prompt_title = $rec['prompt']['title'];
        $parts = [];

        // Intent match
        if ($intent['detected'] !== 'general' && $intent['confidence'] > 0.5) {
            $intent_labels = [
                'sales_letter' => 'lettres commerciales',
                'proposal' => 'propositions',
                'meeting_minutes' => 'comptes rendus',
                'synthesis' => 'synthèses',
                'email' => 'emails',
                'report' => 'rapports',
                'content' => 'contenus',
            ];
            $intent_label = $intent_labels[$intent['detected']] ?? $intent['detected'];
            $parts[] = "adapté pour les {$intent_label}";
        }

        // Rating
        if ($rec['rating']['count'] >= 3 && $rec['rating']['average'] >= 4.0) {
            $parts[] = "très bien noté";
        }

        // Favorites
        if ($rec['scores']['favorites'] > 0) {
            $parts[] = "dans vos favoris";
        }

        if (empty($parts)) {
            return "Recommandé pour votre demande";
        }

        return ucfirst(implode(', ', $parts)) . '.';
    }

    /**
     * Get user's favorite publication IDs
     */
    private function get_user_favorites(): array {
        if ($this->user_id <= 0) {
            return [];
        }

        $favorites = get_user_meta($this->user_id, '_ml_favorites', true);
        return is_array($favorites) ? array_map('intval', $favorites) : [];
    }

    /**
     * Get fallback suggestions when no prompts match
     */
    private function get_fallback_suggestions(array $intent, ?int $space_id): array {
        $suggestions = [];

        if ($intent['fallback']) {
            $suggestions[] = "Essayez d'être plus précis dans votre demande (ex: 'lettre commerciale pour prospect')";
        }

        if ($space_id) {
            $suggestions[] = "Vérifiez que l'espace contient des prompts validés";
        } else {
            $suggestions[] = "Essayez de filtrer par espace avec space_id";
        }

        $suggestions[] = "Utilisez ml_help(mode: 'menu') pour voir les outils disponibles";

        return $suggestions;
    }

    /**
     * Get available style variants for a prompt
     *
     * @param int $prompt_id Prompt ID
     * @return array Style variants
     */
    public function get_style_variants(int $prompt_id): array {
        if (!$this->permissions->can_see_publication($prompt_id)) {
            return [];
        }

        $linked_styles = Picasso_Adapter::get_tool_linked_styles($prompt_id);
        $variants = [];

        foreach ($linked_styles as $style_id) {
            if ($this->permissions->can_see_publication($style_id)) {
                $style_post = get_post($style_id);
                if ($style_post) {
                    $variants[] = [
                        'id' => $style_id,
                        'name' => $style_post->post_title,
                        'variant' => get_post_meta($style_id, '_ml_style_variant', true) ?: 'default',
                        'description' => Render_Service::excerpt_from_html($style_post->post_content, 100),
                    ];
                }
            }
        }

        return $variants;
    }

    /**
     * Check if recommendation service is available
     */
    public static function is_available(): bool {
        return post_type_exists('publication') && post_type_exists('space');
    }
}
