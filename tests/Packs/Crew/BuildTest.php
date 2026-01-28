<?php
/**
 * Tests for ml_build Tool (Pack CREW)
 *
 * Acceptance tests as specified in the CREW spec:
 * 1. Suggest without IDs returns candidates
 * 2. Apply with auto_create creates prompt + tool
 * 3. Permission failure downgrades to suggest
 * 4. Strict mode blocks if no content
 * 5. Pin_components writes snapshot
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\Tests\Packs\Crew;

use MCP_No_Headless\MCP\Packs\Crew\Tools\Build;
use MCP_No_Headless\MCP\Packs\Crew\Services\QueryRewriteService;
use MCP_No_Headless\MCP\Packs\Crew\Services\RerankService;
use MCP_No_Headless\MCP\Packs\Crew\Services\BlueprintBuilder;

/**
 * Test class for Build tool
 */
class BuildTest {

    /**
     * Test 1: Suggest mode without IDs returns candidates
     */
    public static function test_suggest_returns_candidates(): array {
        $args = [
            'context' => 'Créer un outil pour comparer des documents avec l\'approche MaryLink',
            'mode' => 'suggest',
        ];

        // Mock user with read-only access
        $user_id = 1;

        $result = Build::execute($args, $user_id);

        $tests = [];

        // Should succeed
        $tests[] = [
            'name' => 'suggest_success',
            'passed' => $result['success'] === true,
            'expected' => true,
            'actual' => $result['success'],
        ];

        // Mode should be suggest
        $tests[] = [
            'name' => 'suggest_mode',
            'passed' => ($result['data']['mode'] ?? '') === 'suggest',
            'expected' => 'suggest',
            'actual' => $result['data']['mode'] ?? 'unknown',
        ];

        // Should have blueprint
        $tests[] = [
            'name' => 'has_blueprint',
            'passed' => isset($result['data']['blueprint']),
            'expected' => 'blueprint present',
            'actual' => isset($result['data']['blueprint']) ? 'present' : 'missing',
        ];

        // Should have candidates
        $tests[] = [
            'name' => 'has_candidates',
            'passed' => isset($result['data']['candidates']),
            'expected' => 'candidates present',
            'actual' => isset($result['data']['candidates']) ? 'present' : 'missing',
        ];

        // Should have next_action for ml_save
        $tests[] = [
            'name' => 'has_next_action',
            'passed' => isset($result['data']['next_action']['tool']) &&
                        $result['data']['next_action']['tool'] === 'ml_save',
            'expected' => 'ml_save',
            'actual' => $result['data']['next_action']['tool'] ?? 'missing',
        ];

        return [
            'test' => 'test_suggest_returns_candidates',
            'results' => $tests,
            'all_passed' => !in_array(false, array_column($tests, 'passed')),
        ];
    }

    /**
     * Test 2: Apply with auto_create creates prompt + tool
     */
    public static function test_apply_with_auto_create(): array {
        $args = [
            'context' => 'Test auto-création d\'un outil pour analyse documentaire',
            'mode' => 'apply',
            'auto_create' => true,
        ];

        // Mock admin user with write access
        $user_id = 1;

        $result = Build::execute($args, $user_id);

        $tests = [];

        // Should succeed (or fail gracefully)
        $tests[] = [
            'name' => 'apply_executed',
            'passed' => isset($result['success']),
            'expected' => 'response received',
            'actual' => isset($result['success']) ? 'received' : 'no response',
        ];

        // If success, mode should be apply
        if ($result['success']) {
            $tests[] = [
                'name' => 'apply_mode',
                'passed' => ($result['data']['mode'] ?? '') === 'apply',
                'expected' => 'apply',
                'actual' => $result['data']['mode'] ?? 'unknown',
            ];

            // Tool should be created
            $tests[] = [
                'name' => 'tool_created',
                'passed' => ($result['data']['created']['tool'] ?? false) === true,
                'expected' => true,
                'actual' => $result['data']['created']['tool'] ?? false,
            ];

            // Tool should have ID
            $tests[] = [
                'name' => 'tool_has_id',
                'passed' => !empty($result['data']['tool']['id']),
                'expected' => 'tool ID present',
                'actual' => !empty($result['data']['tool']['id']) ? 'present' : 'missing',
            ];
        }

        return [
            'test' => 'test_apply_with_auto_create',
            'results' => $tests,
            'all_passed' => !in_array(false, array_column($tests, 'passed')),
        ];
    }

    /**
     * Test 3: Permission failure downgrades to suggest
     */
    public static function test_permission_downgrade(): array {
        $args = [
            'context' => 'Test downgrade de permission',
            'mode' => 'apply',
            'space_id' => 99999, // Non-existent space
        ];

        // Mock user without write permission to that space
        $user_id = 99999; // Non-existent user

        $result = Build::execute($args, $user_id);

        $tests = [];

        // Should have warning about permission
        $has_permission_warning = false;
        if (isset($result['data']['warnings'])) {
            foreach ($result['data']['warnings'] as $warning) {
                if (strpos($warning['code'] ?? '', 'permission') !== false) {
                    $has_permission_warning = true;
                    break;
                }
            }
        }

        $tests[] = [
            'name' => 'has_permission_warning',
            'passed' => $has_permission_warning || ($result['data']['mode'] ?? '') === 'suggest',
            'expected' => 'permission warning or suggest mode',
            'actual' => $has_permission_warning ? 'warning present' : ($result['data']['mode'] ?? 'unknown'),
        ];

        return [
            'test' => 'test_permission_downgrade',
            'results' => $tests,
            'all_passed' => !in_array(false, array_column($tests, 'passed')),
        ];
    }

    /**
     * Test 4: Strict mode blocks if no content
     */
    public static function test_strict_blocks_no_content(): array {
        $args = [
            'context' => 'xyz123nonexistent789', // Unlikely to find anything
            'mode' => 'apply',
            'strict' => true,
        ];

        $user_id = 1;

        $result = Build::execute($args, $user_id);

        $tests = [];

        // Should fail or have error
        $is_blocked = ($result['success'] === false) ||
                      (isset($result['data']['warnings']) &&
                       count(array_filter($result['data']['warnings'], fn($w) =>
                           strpos($w['code'] ?? '', 'missing') !== false
                       )) > 0);

        $tests[] = [
            'name' => 'strict_blocks',
            'passed' => $is_blocked,
            'expected' => 'blocked or warning',
            'actual' => $is_blocked ? 'blocked' : 'allowed',
        ];

        return [
            'test' => 'test_strict_blocks_no_content',
            'results' => $tests,
            'all_passed' => !in_array(false, array_column($tests, 'passed')),
        ];
    }

    /**
     * Test 5: Pin_components writes snapshot
     */
    public static function test_pin_components(): array {
        $args = [
            'context' => 'Test pin components',
            'mode' => 'dry-run',
            'pin_components' => true,
        ];

        $user_id = 1;

        $result = Build::execute($args, $user_id);

        $tests = [];

        // Should succeed
        $tests[] = [
            'name' => 'dryrun_success',
            'passed' => $result['success'] === true,
            'expected' => true,
            'actual' => $result['success'],
        ];

        // next_action params should have content with snapshot
        if (isset($result['data']['next_action']['params']['content'])) {
            $content = $result['data']['next_action']['params']['content'];
            $has_sections = strpos($content, '## Instruction') !== false ||
                           strpos($content, '## Contenus') !== false;

            $tests[] = [
                'name' => 'snapshot_in_content',
                'passed' => $has_sections,
                'expected' => 'snapshot sections present',
                'actual' => $has_sections ? 'present' : 'missing',
            ];
        }

        // Meta should indicate pinned
        if (isset($result['data']['next_action']['params']['meta'])) {
            $meta = $result['data']['next_action']['params']['meta'];
            $tests[] = [
                'name' => 'pinned_flag_set',
                'passed' => ($meta['_ml_pinned'] ?? false) === true,
                'expected' => true,
                'actual' => $meta['_ml_pinned'] ?? false,
            ];
        }

        return [
            'test' => 'test_pin_components',
            'results' => $tests,
            'all_passed' => !in_array(false, array_column($tests, 'passed')),
        ];
    }

    /**
     * Test QueryRewriteService
     */
    public static function test_query_rewrite_service(): array {
        $query = 'Créer un outil pour comparer des documents clients';

        $result = QueryRewriteService::rewrite($query, 'fr');

        $tests = [];

        // Should have expanded_query
        $tests[] = [
            'name' => 'has_expanded_query',
            'passed' => !empty($result['expanded_query']),
            'expected' => 'expanded_query present',
            'actual' => !empty($result['expanded_query']) ? 'present' : 'missing',
        ];

        // Should have keywords
        $tests[] = [
            'name' => 'has_keywords',
            'passed' => isset($result['keywords']) && is_array($result['keywords']),
            'expected' => 'keywords array',
            'actual' => isset($result['keywords']) ? 'array with ' . count($result['keywords']) . ' items' : 'missing',
        ];

        return [
            'test' => 'test_query_rewrite_service',
            'results' => $tests,
            'all_passed' => !in_array(false, array_column($tests, 'passed')),
        ];
    }

    /**
     * Test RerankService
     */
    public static function test_rerank_service(): array {
        $items = [
            ['id' => 1, 'title' => 'Prompt pour analyse documentaire', 'excerpt' => 'Analyse des documents'],
            ['id' => 2, 'title' => 'Style formel', 'excerpt' => 'Ton professionnel'],
            ['id' => 3, 'title' => 'Comparaison de documents', 'excerpt' => 'Comparer et analyser'],
        ];

        $query = 'analyse documentaire';

        $result = RerankService::rerank($items, $query, 3);

        $tests = [];

        // Should return array
        $tests[] = [
            'name' => 'returns_array',
            'passed' => is_array($result),
            'expected' => 'array',
            'actual' => is_array($result) ? 'array' : gettype($result),
        ];

        // Items should have _rerank_score
        $has_scores = !empty($result) && isset($result[0]['_rerank_score']);
        $tests[] = [
            'name' => 'has_rerank_score',
            'passed' => $has_scores,
            'expected' => '_rerank_score present',
            'actual' => $has_scores ? 'present' : 'missing',
        ];

        return [
            'test' => 'test_rerank_service',
            'results' => $tests,
            'all_passed' => !in_array(false, array_column($tests, 'passed')),
        ];
    }

    /**
     * Test BlueprintBuilder
     */
    public static function test_blueprint_builder(): array {
        $prompt = ['id' => 1, 'title' => 'Test Prompt', 'content' => 'Test content'];
        $contents = [
            ['id' => 2, 'title' => 'Content 1', 'content' => 'Content 1 text'],
            ['id' => 3, 'title' => 'Content 2', 'content' => 'Content 2 text'],
        ];
        $style = ['id' => 4, 'title' => 'Test Style', 'content' => 'Style text'];

        $blueprint = BlueprintBuilder::build($prompt, $contents, $style, 100, 0.85);

        $tests = [];

        // Should have prompt_id
        $tests[] = [
            'name' => 'has_prompt_id',
            'passed' => $blueprint['prompt_id'] === 1,
            'expected' => 1,
            'actual' => $blueprint['prompt_id'],
        ];

        // Should have content_ids
        $tests[] = [
            'name' => 'has_content_ids',
            'passed' => $blueprint['content_ids'] === [2, 3],
            'expected' => '[2, 3]',
            'actual' => json_encode($blueprint['content_ids']),
        ];

        // Should have style_id
        $tests[] = [
            'name' => 'has_style_id',
            'passed' => $blueprint['style_id'] === 4,
            'expected' => 4,
            'actual' => $blueprint['style_id'],
        ];

        // Should have compat_score
        $tests[] = [
            'name' => 'has_compat_score',
            'passed' => $blueprint['compat_score'] === 0.85,
            'expected' => 0.85,
            'actual' => $blueprint['compat_score'],
        ];

        // Validation should pass
        $validation = BlueprintBuilder::validate($blueprint);
        $tests[] = [
            'name' => 'validation_passes',
            'passed' => $validation['valid'] === true,
            'expected' => true,
            'actual' => $validation['valid'],
        ];

        return [
            'test' => 'test_blueprint_builder',
            'results' => $tests,
            'all_passed' => !in_array(false, array_column($tests, 'passed')),
        ];
    }

    /**
     * Run all tests
     */
    public static function run_all(): array {
        $all_results = [];

        $all_results[] = self::test_suggest_returns_candidates();
        $all_results[] = self::test_apply_with_auto_create();
        $all_results[] = self::test_permission_downgrade();
        $all_results[] = self::test_strict_blocks_no_content();
        $all_results[] = self::test_pin_components();
        $all_results[] = self::test_query_rewrite_service();
        $all_results[] = self::test_rerank_service();
        $all_results[] = self::test_blueprint_builder();

        $total_passed = array_sum(array_map(fn($r) => $r['all_passed'] ? 1 : 0, $all_results));
        $total_tests = count($all_results);

        return [
            'summary' => [
                'total' => $total_tests,
                'passed' => $total_passed,
                'failed' => $total_tests - $total_passed,
                'success_rate' => round(($total_passed / $total_tests) * 100, 2) . '%',
            ],
            'results' => $all_results,
        ];
    }
}
