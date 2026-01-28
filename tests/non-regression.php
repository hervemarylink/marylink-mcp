<?php
/**
 * Non-regression test suite for ml_find v3.2
 * Run: wp eval-file /path/to/non-regression.php
 */

if (!defined('ABSPATH')) {
    require_once dirname(__DIR__, 4) . '/wp-load.php';
}

use MCP_No_Headless\MCP\Core\Tools\Find;

$tests = [];
$passed = 0;
$failed = 0;
$user_id = 1;

// Fixture IDs
$P1 = 26019; $P2 = 26020; $P3 = 26021; $P4 = 26022; $P5 = 26023; $P6 = 26024;
$fixture_ids = [$P1, $P2, $P3, $P4, $P5, $P6];

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

function get_items($result) {
    return $result['data']['items'] ?? [];
}

function filter_fixtures($items, $fixture_ids) {
    return array_filter($items, fn($item) => in_array($item['id'], $fixture_ids));
}

// ============================================
// CT1 - Contract-first
// ============================================
echo "\n=== CT1 - Contract-first ===\n";

// CT1.1 - query=* should not return users
$result = Find::execute(['query' => '*', 'limit' => 20], $user_id);
$items = get_items($result);
$has_users = false;
foreach ($items as $item) {
    if (($item['_type'] ?? '') === 'user') { $has_users = true; break; }
}
test('CT1.1 - query=* no users', !$has_users, 'Found users in results');

// CT1.2 - query=john should return only publications
$result = Find::execute(['query' => 'john', 'limit' => 10], $user_id);
$items = get_items($result);
$has_users = false;
foreach ($items as $item) {
    if (($item['_type'] ?? '') === 'user') { $has_users = true; break; }
}
test('CT1.2 - query=john no users', !$has_users);

// CT1.3 - baseline structure
$result = Find::execute(['limit' => 10], $user_id);
$items = get_items($result);
$item = $items[0] ?? [];
$has_required = isset($item['id']) && isset($item['title']) && isset($item['_type']);
test('CT1.3 - baseline structure (id, title, _type)', $has_required, 'Keys: ' . implode(',', array_keys($item)));

// ============================================
// CT2 - include=metadata
// ============================================
echo "\n=== CT2 - include=metadata ===\n";

// CT2.1 - P5 with metadata
$result = Find::execute(['limit' => 50, 'include' => ['metadata']], $user_id);
$items = get_items($result);
$p5 = null;
$p6 = null;
foreach ($items as $item) {
    if ($item['id'] == $P5) $p5 = $item;
    if ($item['id'] == $P6) $p6 = $item;
}

if ($p5) {
    $meta = $p5['metadata'] ?? [];
    test('CT2.1a - P5 rating.average=4.9', ($meta['rating']['average'] ?? 0) == 4.9, 'Got: ' . ($meta['rating']['average'] ?? 'null'));
    test('CT2.1b - P5 rating.count=50', ($meta['rating']['count'] ?? 0) == 50, 'Got: ' . ($meta['rating']['count'] ?? 'null'));
    test('CT2.1c - P5 quality_score=20', ($meta['quality_score'] ?? 0) == 20, 'Got: ' . ($meta['quality_score'] ?? 'null'));
    test('CT2.1d - P5 engagement_score=10', ($meta['engagement_score'] ?? 0) == 10, 'Got: ' . ($meta['engagement_score'] ?? 'null'));
} else {
    test('CT2.1 - P5 found', false, 'P5 not in results');
}

// CT2.2 - P6 without metas
if ($p6) {
    $meta = $p6['metadata'] ?? [];
    test('CT2.2a - P6 rating.average null', ($meta['rating']['average'] ?? null) === null);
    test('CT2.2b - P6 quality_score null', ($meta['quality_score'] ?? null) === null);
} else {
    test('CT2.2 - P6 found', false, 'P6 not in results');
}

// CT2.3 - without include, no metadata
$result = Find::execute(['limit' => 10], $user_id);
$items = get_items($result);
$has_metadata = isset($items[0]['metadata']);
test('CT2.3 - no include = no metadata', !$has_metadata);

// ============================================
// CT3 - sort
// ============================================
echo "\n=== CT3 - sort ===\n";

// CT3.1 - sort absent = date desc
$result = Find::execute(['limit' => 50], $user_id);
$items = array_values(filter_fixtures(get_items($result), $fixture_ids));
$ids = array_column($items, 'id');
test('CT3.1 - no sort returns fixtures', count($ids) >= 6, 'Got ' . count($ids) . ' fixtures');

// CT3.2 - sort=best (P6 has no scores so may be ranked low - expect at least 5)
$result = Find::execute(['limit' => 50, 'sort' => 'best'], $user_id);
$items = array_values(filter_fixtures(get_items($result), $fixture_ids));
$ids = array_column($items, 'id');
test('CT3.2 - sort=best returns fixtures', count($ids) >= 5, 'Got ' . count($ids));
echo "    Order: " . implode(', ', $ids) . "\n";

// CT3.3 - sort=best_rated - P5 before P4
$result = Find::execute(['limit' => 50, 'sort' => 'best_rated'], $user_id);
$items = array_values(filter_fixtures(get_items($result), $fixture_ids));
$ids = array_column($items, 'id');
$p5_pos = array_search($P5, $ids);
$p4_pos = array_search($P4, $ids);
$p5_before_p4 = $p5_pos !== false && $p4_pos !== false && $p5_pos < $p4_pos;
test('CT3.3 - best_rated: P5 before P4', $p5_before_p4, "P5@$p5_pos P4@$p4_pos");
echo "    Order: " . implode(', ', $ids) . "\n";

// CT3.4 - sort=trending - P3 first (highest engagement)
$result = Find::execute(['limit' => 50, 'sort' => 'trending'], $user_id);
$items = array_values(filter_fixtures(get_items($result), $fixture_ids));
$ids = array_column($items, 'id');
test('CT3.4 - trending: P3 first', ($ids[0] ?? 0) == $P3, 'First: ' . ($ids[0] ?? 'none'));
echo "    Order: " . implode(', ', $ids) . "\n";

// CT3.5 - sort=most_liked - P3 first
$result = Find::execute(['limit' => 50, 'sort' => 'most_liked'], $user_id);
$items = array_values(filter_fixtures(get_items($result), $fixture_ids));
$ids = array_column($items, 'id');
test('CT3.5 - most_liked: P3 first', ($ids[0] ?? 0) == $P3, 'First: ' . ($ids[0] ?? 'none'));

// CT3.6 - sort=most_favorited - P2 first
$result = Find::execute(['limit' => 50, 'sort' => 'most_favorited'], $user_id);
$items = array_values(filter_fixtures(get_items($result), $fixture_ids));
$ids = array_column($items, 'id');
test('CT3.6 - most_favorited: P2 first', ($ids[0] ?? 0) == $P2, 'First: ' . ($ids[0] ?? 'none'));

// ============================================
// CT4 - include=ranking_reason
// ============================================
echo "\n=== CT4 - include=ranking_reason ===\n";

// CT4.1 - without include, no ranking_reason
$result = Find::execute(['limit' => 10, 'sort' => 'best_rated'], $user_id);
$items = get_items($result);
$has_reason = isset($items[0]['ranking_reason']);
test('CT4.1 - no include = no ranking_reason', !$has_reason);

// CT4.2 - with include=ranking_reason
$result = Find::execute(['limit' => 50, 'sort' => 'best_rated', 'include' => ['ranking_reason']], $user_id);
$items = get_items($result);
$p5 = $p6 = null;
foreach ($items as $item) {
    if ($item['id'] == $P5) $p5 = $item;
    if ($item['id'] == $P6) $p6 = $item;
}

if ($p5) {
    $reason = $p5['ranking_reason'] ?? [];
    echo "    P5 ranking_reason: " . json_encode($reason) . "\n";
    test('CT4.2a - P5 has ranking_reason', !empty($reason));
    test('CT4.2b - P5 fallback=false (has ratings)', ($reason['fallback_applied'] ?? true) === false);
}

if ($p6) {
    $reason = $p6['ranking_reason'] ?? [];
    echo "    P6 ranking_reason: " . json_encode($reason) . "\n";
    test('CT4.2c - P6 fallback=true (no ratings)', ($reason['fallback_applied'] ?? false) === true);
}

// ============================================
// CT5 - include=reviews
// ============================================
echo "\n=== CT5 - include=reviews ===\n";

$result = Find::execute(['limit' => 50, 'include' => ['reviews', 'metadata']], $user_id);
$items = get_items($result);
$p5_reviews = [];
$p4_reviews = [];
foreach ($items as $item) {
    if ($item['id'] == $P5) $p5_reviews = $item['metadata']['reviews_sample'] ?? [];
    if ($item['id'] == $P4) $p4_reviews = $item['metadata']['reviews_sample'] ?? [];
}

test('CT5.2a - P5 has 2 reviews', count($p5_reviews) == 2, 'Got: ' . count($p5_reviews));
test('CT5.2b - P4 has 1 review', count($p4_reviews) == 1, 'Got: ' . count($p4_reviews));

if (!empty($p5_reviews)) {
    echo "    P5 review: " . json_encode($p5_reviews[0]) . "\n";
    $has_fields = isset($p5_reviews[0]['rating']) && isset($p5_reviews[0]['comment']);
    test('CT5.2c - review has rating+comment', $has_fields);
}

// ============================================
// Summary
// ============================================
echo "\n==========================================\n";
echo "SUMMARY: $passed PASSED, $failed FAILED\n";
echo "==========================================\n";

exit($failed > 0 ? 1 : 0);
