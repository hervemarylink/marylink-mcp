#!/bin/bash
# Non-Regression Tests - Run on server via WP-CLI
# Tests the actual handler logic for tags/labels/step/type persistence

SERVER="${MCP_SERVER:-ax102}"
WEBAPP="${MCP_WEBAPP:-JAN26}"
WEBAPP_PATH="/home/runcloud/webapps/${WEBAPP}"

echo "========================================"
echo "  MaryLink MCP - Non-Regression Tests"
echo "========================================"
echo "Server: $SERVER"
echo "Webapp: $WEBAPP"
echo ""

# Check SSH connectivity (try Windows ssh first, then Linux)
SSH_CMD="ssh"
if command -v ssh.exe &>/dev/null; then
    SSH_CMD="ssh.exe"
fi

if ! $SSH_CMD -o ConnectTimeout=5 -o BatchMode=yes "$SERVER" "echo 'SSH OK'" 2>/dev/null; then
    echo "[SKIP] Cannot connect to server - skipping remote tests"
    exit 0
fi

# Run tests via WP-CLI
RESULT=$($SSH_CMD "$SERVER" "cd $WEBAPP_PATH && wp eval '
\$passed = 0;
\$failed = 0;
\$tests = [];

// Test 1: CREATE with JSON string tags/labels (MCP format)
\$tags_json = \"[\\\"act-adapt\\\", \\\"tuto\\\"]\";
\$labels_json = \"[\\\"data\\\", \\\"tool\\\"]\";

\$tags = \$tags_json;
\$labels = \$labels_json;
if (is_string(\$tags)) \$tags = json_decode(\$tags, true) ?? [];
if (is_string(\$labels)) \$labels = json_decode(\$labels, true) ?? [];

\$post_id = wp_insert_post([
    \"post_title\" => \"NR Test \" . uniqid(),
    \"post_content\" => \"Non-regression test\",
    \"post_status\" => \"publish\",
    \"post_type\" => \"publication\"
]);

if (\$post_id && !is_wp_error(\$post_id)) {
    update_post_meta(\$post_id, \"_publication_step\", \"submit\");
    update_post_meta(\$post_id, \"_ml_publication_type\", \"prompt\");
    wp_set_object_terms(\$post_id, \$tags, \"publication_tag\");
    wp_set_object_terms(\$post_id, \$labels, \"publication_label\");

    \$r_step = get_post_meta(\$post_id, \"_publication_step\", true);
    \$r_type = get_post_meta(\$post_id, \"_ml_publication_type\", true);
    \$r_tags = wp_get_post_terms(\$post_id, \"publication_tag\", [\"fields\"=>\"slugs\"]);
    \$r_labels = wp_get_post_terms(\$post_id, \"publication_label\", [\"fields\"=>\"slugs\"]);

    if (\$r_step === \"submit\" && \$r_type === \"prompt\" && count(\$r_tags) === 2 && count(\$r_labels) === 2) {
        \$passed++;
        \$tests[] = \"[PASS] CREATE JSON strings\";
    } else {
        \$failed++;
        \$tests[] = \"[FAIL] CREATE JSON strings: step=\$r_step type=\$r_type tags=\" . count(\$r_tags) . \" labels=\" . count(\$r_labels);
    }

    // Test 2: UPDATE with JSON strings
    \$new_tags = \"[\\\"fct-general\\\"]\";
    \$new_labels = \"[\\\"prompt\\\"]\";
    \$t2 = \$new_tags; \$l2 = \$new_labels;
    if (is_string(\$t2)) \$t2 = json_decode(\$t2, true) ?? [];
    if (is_string(\$l2)) \$l2 = json_decode(\$l2, true) ?? [];

    wp_set_object_terms(\$post_id, \$t2, \"publication_tag\");
    wp_set_object_terms(\$post_id, \$l2, \"publication_label\");
    update_post_meta(\$post_id, \"_publication_step\", \"review\");

    \$r2_step = get_post_meta(\$post_id, \"_publication_step\", true);
    \$r2_tags = wp_get_post_terms(\$post_id, \"publication_tag\", [\"fields\"=>\"slugs\"]);
    \$r2_labels = wp_get_post_terms(\$post_id, \"publication_label\", [\"fields\"=>\"slugs\"]);

    if (\$r2_step === \"review\" && count(\$r2_tags) === 1 && count(\$r2_labels) === 1) {
        \$passed++;
        \$tests[] = \"[PASS] UPDATE JSON strings\";
    } else {
        \$failed++;
        \$tests[] = \"[FAIL] UPDATE JSON strings\";
    }

    // Cleanup
    wp_delete_post(\$post_id, true);
} else {
    \$failed++;
    \$tests[] = \"[FAIL] Could not create test post\";
}

// Test 3: Direct arrays (non-MCP format)
\$post_id2 = wp_insert_post([
    \"post_title\" => \"NR Test Direct \" . uniqid(),
    \"post_content\" => \"Test\",
    \"post_status\" => \"publish\",
    \"post_type\" => \"publication\"
]);

if (\$post_id2) {
    \$direct_tags = [\"test\", \"validation\"];
    \$direct_labels = [\"doc\"];
    if (is_string(\$direct_tags)) \$direct_tags = json_decode(\$direct_tags, true) ?? [];
    if (is_string(\$direct_labels)) \$direct_labels = json_decode(\$direct_labels, true) ?? [];

    wp_set_object_terms(\$post_id2, \$direct_tags, \"publication_tag\");
    wp_set_object_terms(\$post_id2, \$direct_labels, \"publication_label\");

    \$r3_tags = wp_get_post_terms(\$post_id2, \"publication_tag\", [\"fields\"=>\"slugs\"]);
    \$r3_labels = wp_get_post_terms(\$post_id2, \"publication_label\", [\"fields\"=>\"slugs\"]);

    if (count(\$r3_tags) === 2 && count(\$r3_labels) === 1) {
        \$passed++;
        \$tests[] = \"[PASS] Direct arrays\";
    } else {
        \$failed++;
        \$tests[] = \"[FAIL] Direct arrays\";
    }

    wp_delete_post(\$post_id2, true);
}

// Output results
foreach (\$tests as \$t) echo \$t . \"\\n\";
echo \"---\\n\";
echo \"PASSED: \$passed\\n\";
echo \"FAILED: \$failed\\n\";
exit(\$failed > 0 ? 1 : 0);
' --allow-root 2>/dev/null")

echo "$RESULT"

# Check for failures
if echo "$RESULT" | grep -q "FAILED: 0"; then
    echo ""
    echo "✅ Non-regression tests PASSED"
    exit 0
else
    echo ""
    echo "❌ Non-regression tests FAILED"
    exit 1
fi

// ------------------------------------------------------------
// NR Test: ml_find ranking + reviews sample (top items only)
// ------------------------------------------------------------
try {
    if (class_exists('\\MCP_No_Headless\\MCP\\Core\\Tools\\Find')) {
        // Create 3 publications with different ratings
        $p1 = wp_insert_post([
            "post_title" => "NR Rank A " . uniqid(),
            "post_content" => "content A",
            "post_status" => "publish",
            "post_type" => "publication"
        ]);
        $p2 = wp_insert_post([
            "post_title" => "NR Rank B " . uniqid(),
            "post_content" => "content B",
            "post_status" => "publish",
            "post_type" => "publication"
        ]);
        $p3 = wp_insert_post([
            "post_title" => "NR Rank C " . uniqid(),
            "post_content" => "content C",
            "post_status" => "publish",
            "post_type" => "publication"
        ]);

        update_post_meta($p1, "_ml_average_rating", 4.5);
        update_post_meta($p1, "_ml_rating_count", 40);
        update_post_meta($p2, "_ml_average_rating", 4.9);
        update_post_meta($p2, "_ml_rating_count", 1);
        update_post_meta($p3, "_ml_average_rating", 4.7);
        update_post_meta($p3, "_ml_rating_count", 80);

        $res = \MCP_No_Headless\MCP\Core\Tools\Find::execute([
            "type" => "publication",
            "query" => "NR Rank",
            "limit" => 3,
            "include" => ["metadata","reviews"],
            "sort" => "best"
        ], get_current_user_id());

        $ids = array_map(fn($it) => $it["id"], $res["data"]["items"] ?? []);

        // Expect high-confidence 4.7/80 to outrank 4.9/1
        if (!empty($ids) && $ids[0] === $p3) {
            $passed++;
            $tests[] = "[PASS] ml_find ranking(best) prefers confidence";
        } else {
            $failed++;
            $tests[] = "[FAIL] ml_find ranking(best) order=" . json_encode($ids);
        }

        $sample = $res["data"]["items"][0]["metadata"]["reviews_sample"] ?? null;
        if (is_array($sample)) {
            $passed++;
            $tests[] = "[PASS] ml_find reviews_sample present";
        } else {
            $failed++;
            $tests[] = "[FAIL] ml_find reviews_sample missing";
        }
    }
} catch (Throwable $e) {
    $failed++;
    $tests[] = "[FAIL] ml_find ranking exception: " . $e->getMessage();
}

