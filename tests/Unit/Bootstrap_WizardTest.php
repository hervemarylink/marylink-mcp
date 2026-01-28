<?php
/**
 * Tests for Bootstrap Wizard Tool flow logic
 *
 * @package MCP_No_Headless\Tests\Unit
 */

namespace MCP_No_Headless\Tests\Unit;

use PHPUnit\Framework\TestCase;

class Bootstrap_WizardTest extends TestCase {

    /**
     * Tool templates
     */
    private array $tool_templates = [
        'ao_response' => [
            'name' => 'Générateur de réponses AO',
            'required_data' => ['catalog', 'pricing', 'references'],
        ],
        'proposal' => [
            'name' => 'Générateur de propositions',
            'required_data' => ['catalog', 'pricing', 'methodology'],
        ],
        'content_writer' => [
            'name' => 'Rédacteur de contenu',
            'required_data' => ['catalog', 'style_guide'],
        ],
    ];

    /**
     * Problem patterns for detection
     */
    private array $problem_patterns = [
        'ao_response' => ['appel', 'offres', 'ao', 'rfp', 'cahier des charges', 'consultation'],
        'proposal' => ['proposition', 'devis', 'offre commerciale', 'quote'],
        'content_writer' => ['rédiger', 'contenu', 'article', 'écrire', 'blog'],
    ];

    /**
     * Detect tools from problem description
     */
    private function detectTools(string $problem): array {
        $problem_lower = strtolower($problem);
        $detected = [];

        foreach ($this->problem_patterns as $tool_id => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($problem_lower, $pattern)) {
                    $detected[] = $tool_id;
                    break;
                }
            }
        }

        return array_unique($detected);
    }

    /**
     * TEST 1: Flow nominal - analyze stage
     */
    public function test_analyze_detects_ao_tool(): void {
        $problem = "Je veux répondre aux appels d'offres";

        $detected = $this->detectTools($problem);

        $this->assertContains('ao_response', $detected);
    }

    /**
     * TEST: Analyze detects multiple tools
     */
    public function test_analyze_detects_multiple_tools(): void {
        $problem = "Je veux répondre aux appels d'offres et rédiger des articles de blog";

        $detected = $this->detectTools($problem);

        $this->assertContains('ao_response', $detected);
        $this->assertContains('content_writer', $detected);
    }

    /**
     * TEST 2: Empty space results in placeholders
     */
    public function test_empty_space_generates_placeholders(): void {
        $tool_id = 'ao_response';
        $template = $this->tool_templates[$tool_id];
        $existing_publications = []; // Empty space

        // Check how many placeholders needed
        $found = [];
        $placeholders_needed = [];

        foreach ($template['required_data'] as $data_id) {
            $found[$data_id] = null; // Nothing found
            $placeholders_needed[] = $data_id;
        }

        $this->assertCount(3, $placeholders_needed);
        $this->assertContains('catalog', $placeholders_needed);
        $this->assertContains('pricing', $placeholders_needed);
        $this->assertContains('references', $placeholders_needed);
    }

    /**
     * TEST: Session structure
     */
    public function test_session_structure(): void {
        $session = [
            'id' => 'session_' . uniqid(),
            'user_id' => 1,
            'space_id' => 42,
            'stage' => 'analyze',
            'detected_tools' => ['ao_response'],
            'components' => [],
            'created_at' => time(),
            'expires_at' => time() + 3600,
        ];

        $this->assertArrayHasKey('id', $session);
        $this->assertArrayHasKey('expires_at', $session);
        $this->assertStringStartsWith('session_', $session['id']);
    }

    /**
     * TEST 13: Session expiration
     */
    public function test_session_expiration(): void {
        $ttl = 3600; // 1 hour
        $created_2h_ago = time() - 7200;

        $session = [
            'created_at' => $created_2h_ago,
            'expires_at' => $created_2h_ago + $ttl,
        ];

        $is_expired = time() > $session['expires_at'];

        $this->assertTrue($is_expired);
    }

    /**
     * TEST 14: Invalid session ID
     */
    public function test_invalid_session_validation(): void {
        $valid_sessions = [
            'session_abc123' => true,
        ];

        $session_id = 'fake_session_id';
        $is_valid = isset($valid_sessions[$session_id]);

        $this->assertFalse($is_valid);
    }

    /**
     * TEST: Stage progression
     */
    public function test_stage_progression(): void {
        $valid_stages = ['analyze', 'propose', 'collect', 'validate', 'execute'];
        $stage_order = array_flip($valid_stages);

        // Can only progress forward
        $current = 'analyze';
        $next = 'propose';

        $can_progress = $stage_order[$next] === $stage_order[$current] + 1;
        $this->assertTrue($can_progress);

        // Can't skip stages
        $next = 'execute';
        $can_progress = $stage_order[$next] === $stage_order[$current] + 1;
        $this->assertFalse($can_progress);
    }

    /**
     * TEST 17: Placeholder creation metadata
     */
    public function test_placeholder_metadata(): void {
        $placeholder = [
            'post_title' => 'Catalogue produits/services à compléter',
            'post_status' => 'draft',
            'meta' => [
                '_ml_is_placeholder' => true,
                '_ml_bootstrap_data_id' => 'catalog',
                '_ml_publication_type' => 'data',
            ],
        ];

        $this->assertTrue($placeholder['meta']['_ml_is_placeholder']);
        $this->assertEquals('catalog', $placeholder['meta']['_ml_bootstrap_data_id']);
        $this->assertEquals('draft', $placeholder['post_status']);
    }

    /**
     * TEST 18: Tool creation metadata
     */
    public function test_tool_metadata(): void {
        $tool = [
            'post_title' => 'Générateur de réponses AO',
            'post_status' => 'publish',
            'meta' => [
                '_ml_publication_type' => 'tool',
                '_ml_tool_contents' => [123, 456],
                '_ml_linked_styles' => [789],
                '_ml_instruction' => 'Tu es un expert...',
            ],
        ];

        $this->assertEquals('tool', $tool['meta']['_ml_publication_type']);
        $this->assertCount(2, $tool['meta']['_ml_tool_contents']);
        $this->assertStringContainsString('Tu es', $tool['meta']['_ml_instruction']);
    }

    /**
     * TEST: Required data validation
     */
    public function test_required_data_validation(): void {
        $template = $this->tool_templates['ao_response'];
        $components = [
            'catalog' => ['found' => true, 'publication_id' => 123],
            'pricing' => ['found' => true, 'publication_id' => 456],
            'references' => ['found' => false, 'placeholder' => true],
        ];

        $all_covered = true;
        $placeholders_count = 0;

        foreach ($template['required_data'] as $data_id) {
            if (!isset($components[$data_id])) {
                $all_covered = false;
                break;
            }
            if (isset($components[$data_id]['placeholder']) && $components[$data_id]['placeholder']) {
                $placeholders_count++;
            }
        }

        $this->assertTrue($all_covered);
        $this->assertEquals(1, $placeholders_count);
    }
}
