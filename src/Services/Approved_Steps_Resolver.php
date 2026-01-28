<?php
/**
 * Approved Steps Resolver - Get approved step names per space
 *
 * In Picasso workflow, publications in certain steps are "approved" for use.
 * This resolver identifies which steps contain approved content.
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Services;

use MCP_No_Headless\Picasso\Picasso_Adapter;
use MCP_No_Headless\Schema\Publication_Schema;

class Approved_Steps_Resolver {

    /**
     * Cache TTL in seconds (5 minutes)
     */
    private const CACHE_TTL = 300;

    /**
     * Approved step keywords (FR/EN) - matched via str_contains
     * Order matters: more specific patterns first
     */
    private const APPROVED_KEYWORDS = [
        // French
        'approuv',      // approuvé, approuvée, approuver
        'valid',        // validé, validée, valider, validated
        'publié',       // publié, publiée
        'publ',         // publication, publish, published
        'final',        // final, finalisé
        'termin',       // terminé, terminée
        'livr',         // livré, livrée, livrable
        'prêt',         // prêt, prête
        'pret',         // pret (sans accent)
        // English
        'approved',
        'published',
        'validated',
        'ready',
        'done',
        'completed',
        'production',
        'live',
        'released',
        'shipped',
    ];

    /**
     * Get approved step names for a space (cached 5 min)
     *
     * @param int $space_id Space ID
     * @return array Approved step names
     */
    public static function get_approved_steps(int $space_id): array {
        // Check cache first
        $cache_key = 'mcpnh_approved_steps_' . $space_id;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Get space-specific approved steps from meta (admin override)
        $space_approved = get_post_meta($space_id, '_ml_approved_steps', true);
        if (is_array($space_approved) && !empty($space_approved)) {
            set_transient($cache_key, $space_approved, self::CACHE_TTL);
            return $space_approved;
        }

        // Get all steps for this space
        $steps = Picasso_Adapter::get_space_steps($space_id);

        // Match against approved keywords
        $approved = [];
        foreach ($steps as $step) {
            $step_name = mb_strtolower($step['name'] ?? '');
            $step_label = mb_strtolower($step['label'] ?? '');
            $combined = $step_name . ' ' . $step_label;

            foreach (self::APPROVED_KEYWORDS as $keyword) {
                if (str_contains($combined, $keyword)) {
                    $approved[] = $step['name'];
                    break;
                }
            }
        }

        // Allow filter override
        $approved = apply_filters('mcpnh_approved_steps', $approved, $space_id, $steps);

        // Fallback: if no approved steps found, use last step (usually "published")
        if (empty($approved) && !empty($steps)) {
            $last_step = end($steps);
            $approved = [$last_step['name']];
        }

        // Cache result
        set_transient($cache_key, $approved, self::CACHE_TTL);

        return $approved;
    }

    /**
     * Clear cache for a space (call when steps change)
     *
     * @param int $space_id Space ID
     */
    public static function clear_cache(int $space_id): void {
        delete_transient('mcpnh_approved_steps_' . $space_id);
    }

    /**
     * Check if a step is approved in a space
     *
     * @param int $space_id Space ID
     * @param string $step_name Step name
     * @return bool
     */
    public static function is_step_approved(int $space_id, string $step_name): bool {
        $approved_steps = self::get_approved_steps($space_id);
        return in_array($step_name, $approved_steps, true);
    }

    /**
     * Get all spaces with their approved steps
     *
     * @param array $space_ids Space IDs to check
     * @return array [space_id => [approved_steps]]
     */
    public static function get_all_approved_steps(array $space_ids): array {
        $result = [];
        foreach ($space_ids as $space_id) {
            $result[$space_id] = self::get_approved_steps($space_id);
        }
        return $result;
    }

    /**
     * Build meta query for approved publications
     *
     * @param int $space_id Space ID
     * @return array Meta query args
     */
    public static function get_approved_meta_query(int $space_id): array {
        $approved_steps = self::get_approved_steps($space_id);

        if (empty($approved_steps)) {
            return [];
        }

        // Use Publication_Schema for dual-read (Picasso + legacy)
        return Publication_Schema::build_step_meta_query($approved_steps);
    }
}
