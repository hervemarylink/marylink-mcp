<?php
/**
 * Tests for Component_Picker scoring logic
 *
 * @package MCP_No_Headless\Tests\Unit
 */

namespace MCP_No_Headless\Tests\Unit;

use PHPUnit\Framework\TestCase;

class Component_PickerTest extends TestCase {

    /**
     * Data type definitions for scoring
     */
    private array $data_types = [
        'catalog' => [
            'title_keywords' => ['catalogue', 'catalog', 'offre', 'service', 'produit'],
            'description' => 'Catalogue produits/services',
        ],
        'pricing' => [
            'title_keywords' => ['tarif', 'pricing', 'prix', 'grille'],
            'description' => 'Grille tarifaire',
        ],
        'references' => [
            'title_keywords' => ['référence', 'reference', 'client', 'témoignage', 'testimonial'],
            'description' => 'Références clients',
        ],
    ];

    /**
     * Calculate score for a publication against a data type
     */
    private function calculateScore(array $publication, string $data_id): int {
        $score = 0;
        $data_type = $this->data_types[$data_id] ?? null;

        if (!$data_type) {
            return 0;
        }

        // +100 for exact meta match
        if (isset($publication['meta']['_ml_bootstrap_data_id'])) {
            if ($publication['meta']['_ml_bootstrap_data_id'] === $data_id) {
                $score += 100;
            }
        }

        // +30 for title keyword match
        $title_lower = strtolower($publication['title'] ?? '');
        foreach ($data_type['title_keywords'] as $keyword) {
            if (str_contains($title_lower, $keyword)) {
                $score += 30;
                break;
            }
        }

        // +10 for recent modification (< 30 days)
        if (isset($publication['modified'])) {
            $days_ago = (time() - strtotime($publication['modified'])) / 86400;
            if ($days_ago < 30) {
                $score += 10;
            }
        }

        // +20 for correct publication type
        if (isset($publication['meta']['_ml_publication_type'])) {
            if ($publication['meta']['_ml_publication_type'] === 'data') {
                $score += 20;
            }
        }

        return $score;
    }

    /**
     * TEST 4: Meta exacte prioritaire
     */
    public function test_meta_match_has_highest_priority(): void {
        $publication_a = [
            'id' => 1,
            'title' => 'Document quelconque',
            'meta' => ['_ml_bootstrap_data_id' => 'catalog'],
        ];

        $publication_b = [
            'id' => 2,
            'title' => 'Catalogue Produits',
            'meta' => [],
        ];

        $score_a = $this->calculateScore($publication_a, 'catalog');
        $score_b = $this->calculateScore($publication_b, 'catalog');

        $this->assertGreaterThan($score_b, $score_a);
        $this->assertGreaterThanOrEqual(100, $score_a);
    }

    /**
     * TEST 5: Scoring - Récence
     */
    public function test_recent_publications_score_higher(): void {
        $publication_old = [
            'id' => 1,
            'title' => 'Catalogue Produits',
            'modified' => date('Y-m-d H:i:s', strtotime('-60 days')),
            'meta' => [],
        ];

        $publication_new = [
            'id' => 2,
            'title' => 'Catalogue Produits',
            'modified' => date('Y-m-d H:i:s', strtotime('-7 days')),
            'meta' => [],
        ];

        $score_old = $this->calculateScore($publication_old, 'catalog');
        $score_new = $this->calculateScore($publication_new, 'catalog');

        $this->assertGreaterThan($score_old, $score_new);
    }

    /**
     * TEST 6: Score minimum
     */
    public function test_unrelated_publication_scores_below_threshold(): void {
        $min_threshold = 20;

        $publication = [
            'id' => 1,
            'title' => 'Notes de réunion',
            'meta' => [],
        ];

        $score = $this->calculateScore($publication, 'catalog');

        $this->assertLessThan($min_threshold, $score);
    }

    /**
     * TEST: Multiple keyword matches don't stack
     */
    public function test_keyword_matches_dont_stack(): void {
        $publication = [
            'id' => 1,
            'title' => 'Catalogue des offres et services',  // 3 keywords
            'meta' => [],
        ];

        $score = $this->calculateScore($publication, 'catalog');

        // Should only add 30 once, not 90
        $this->assertEquals(30, $score);
    }

    /**
     * TEST: Correct type adds bonus
     */
    public function test_correct_publication_type_adds_bonus(): void {
        $publication_with_type = [
            'id' => 1,
            'title' => 'Catalogue Produits',
            'meta' => ['_ml_publication_type' => 'data'],
        ];

        $publication_without_type = [
            'id' => 2,
            'title' => 'Catalogue Produits',
            'meta' => [],
        ];

        $score_with = $this->calculateScore($publication_with_type, 'catalog');
        $score_without = $this->calculateScore($publication_without_type, 'catalog');

        $this->assertEquals($score_without + 20, $score_with);
    }

    /**
     * TEST: Combined scoring
     */
    public function test_combined_scoring(): void {
        // Best case: meta match + keyword + recent + correct type
        $best_publication = [
            'id' => 1,
            'title' => 'Catalogue Services 2024',
            'modified' => date('Y-m-d H:i:s', strtotime('-5 days')),
            'meta' => [
                '_ml_bootstrap_data_id' => 'catalog',
                '_ml_publication_type' => 'data',
            ],
        ];

        $score = $this->calculateScore($best_publication, 'catalog');

        // 100 (meta) + 30 (keyword) + 10 (recent) + 20 (type) = 160
        $this->assertEquals(160, $score);
    }

    /**
     * TEST: Picker selects best match
     */
    public function test_picker_selects_highest_score(): void {
        $publications = [
            [
                'id' => 1,
                'title' => 'Notes de réunion',
                'meta' => [],
            ],
            [
                'id' => 2,
                'title' => 'Catalogue 2023',
                'modified' => date('Y-m-d H:i:s', strtotime('-60 days')),
                'meta' => [],
            ],
            [
                'id' => 3,
                'title' => 'Catalogue 2024',
                'modified' => date('Y-m-d H:i:s', strtotime('-5 days')),
                'meta' => ['_ml_publication_type' => 'data'],
            ],
        ];

        $best_id = null;
        $best_score = 0;

        foreach ($publications as $pub) {
            $score = $this->calculateScore($pub, 'catalog');
            if ($score > $best_score) {
                $best_score = $score;
                $best_id = $pub['id'];
            }
        }

        // Publication 3 should win (keyword + recent + type = 60)
        $this->assertEquals(3, $best_id);
    }
}
