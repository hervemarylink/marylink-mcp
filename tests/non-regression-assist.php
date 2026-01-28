<?php
/**
 * Non-regression test suite for ml_assist debug mode (PR ml_assist-debug)
 * Run: wp eval-file /path/to/non-regression-assist.php
 *
 * @package MCP_No_Headless
 * @since 3.2.11
 */

if (!defined('ABSPATH')) {
    require_once dirname(__DIR__, 4) . '/wp-load.php';
}

use MCP_No_Headless\MCP\Assist_Tool;

$tests = [];
$passed = 0;
$failed = 0;
$user_id = 1; // admin

function test($name, $condition, $msg = '') {
    global $tests, $passed, $failed;
    if ($condition) {
        $tests[] = ['name' => $name, 'status' => 'PASS'];
        $passed++;
        echo "[OK] $name\n";
    } else {
        $tests[] = ['name' => $name, 'status' => 'FAIL', 'msg' => $msg];
        $failed++;
        echo "[!!] $name: FAIL" . ($msg ? " ($msg)" : "") . "\n";
    }
}

// ============================================
// NR1 - Sans debug, pas de cle debug
// ============================================
echo "\n=== NR1 - Sans debug, pas de cle debug ===\n";

$result = Assist_Tool::execute(['text' => 'lettre commerciale pour Acme'], $user_id);
$has_debug = isset($result['data']['debug']);
test('NR1 - No debug without include', !$has_debug, 'debug key found: ' . ($has_debug ? 'yes' : 'no'));

// ============================================
// NR2 - Avec debug, cle presente avec champs obligatoires
// ============================================
echo "\n=== NR2 - Avec debug, cles presentes ===\n";

$result = Assist_Tool::execute([
    'text' => 'lettre commerciale pour Acme',
    'include' => ['debug']
], $user_id);

$debug = $result['data']['debug'] ?? null;

test('NR2a - Debug key present', $debug !== null, 'debug is null');

if ($debug !== null) {
    test('NR2b - candidates_scanned present', isset($debug['candidates_scanned']), 'Key missing');
    test('NR2c - spaces_checked present', isset($debug['spaces_checked']), 'Key missing');
    test('NR2d - spaces_accessible present', isset($debug['spaces_accessible']), 'Key missing');
    test('NR2e - index_types present', isset($debug['index_types']), 'Key missing');
    test('NR2f - threshold_used present', isset($debug['threshold_used']), 'Key missing');
    test('NR2g - timing_ms present', isset($debug['timing_ms']), 'Key missing');
    test('NR2h - top_scores present', isset($debug['top_scores']), 'Key missing');

    // Verify timing_ms structure
    if (isset($debug['timing_ms'])) {
        $tm = $debug['timing_ms'];
        test('NR2i - timing_ms.intent_detection present', isset($tm['intent_detection']), 'Key missing');
        test('NR2j - timing_ms.candidate_fetch present', isset($tm['candidate_fetch']), 'Key missing');
        test('NR2k - timing_ms.total present', isset($tm['total']), 'Key missing');
    }

    // Print debug info for inspection
    echo "\n--- Debug output sample ---\n";
    echo "candidates_scanned: " . ($debug['candidates_scanned'] ?? 'null') . "\n";
    echo "spaces_checked: " . json_encode($debug['spaces_checked'] ?? []) . "\n";
    echo "spaces_accessible: " . ($debug['spaces_accessible'] ?? 'null') . "\n";
    echo "timing_ms: " . json_encode($debug['timing_ms'] ?? []) . "\n";
} else {
    // Mark all sub-tests as failed
    test('NR2b - candidates_scanned present', false, 'debug is null');
    test('NR2c - spaces_checked present', false, 'debug is null');
    test('NR2d - spaces_accessible present', false, 'debug is null');
    test('NR2e - index_types present', false, 'debug is null');
    test('NR2f - threshold_used present', false, 'debug is null');
    test('NR2g - timing_ms present', false, 'debug is null');
    test('NR2h - top_scores present', false, 'debug is null');
}

// ============================================
// NR3 - Debug avec candidats montre top_scores avec breakdown
// ============================================
echo "\n=== NR3 - top_scores avec breakdown ===\n";

if ($debug !== null && isset($debug['top_scores']) && !empty($debug['top_scores'])) {
    $first_score = $debug['top_scores'][0] ?? null;
    test('NR3a - top_scores has items', $first_score !== null, 'top_scores empty');

    if ($first_score) {
        test('NR3b - top_scores has id', isset($first_score['id']), 'id missing');
        test('NR3c - top_scores has title', isset($first_score['title']), 'title missing');
        test('NR3d - top_scores has final_score', isset($first_score['final_score']), 'final_score missing');
        test('NR3e - top_scores has breakdown', isset($first_score['breakdown']), 'breakdown missing');

        if (isset($first_score['breakdown'])) {
            $bd = $first_score['breakdown'];
            test('NR3f - breakdown has text_match', isset($bd['text_match']), 'text_match missing');
            test('NR3g - breakdown has rating', isset($bd['rating']), 'rating missing');
        }

        echo "\n--- Top score sample ---\n";
        echo json_encode($first_score, JSON_PRETTY_PRINT) . "\n";
    }
} else {
    test('NR3 - top_scores requires candidates', false, 'No candidates or debug null');
}

// ============================================
// NR4 - Debug avec query_normalized
// ============================================
echo "\n=== NR4 - query_normalized ===\n";

if ($debug !== null && isset($debug['query_normalized'])) {
    $qn = $debug['query_normalized'];
    test('NR4a - query_normalized is array', is_array($qn), 'Not an array');
    test('NR4b - query_normalized not empty', !empty($qn), 'Empty array');
    echo "query_normalized: " . json_encode($qn) . "\n";
} else {
    test('NR4 - query_normalized present', false, 'Key missing or debug null');
}

// ============================================
// Summary
// ============================================
echo "\n==========================================\n";
echo "SUMMARY: $passed PASSED, $failed FAILED\n";
echo "==========================================\n";

exit($failed > 0 ? 1 : 0);
