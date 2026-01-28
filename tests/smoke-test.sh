#!/bin/bash
# MaryLink MCP - Smoke Tests (Windows compatible)

# Configuration
BASE_URL="${MCP_TEST_URL:-https://jan26.marylink.net}"
SKIP_REMOTE="${SKIP_REMOTE:-0}"

PASSED=0
FAILED=0

pass() { echo "[PASS] $1"; ((PASSED++)); }
fail() { echo "[FAIL] $1"; ((FAILED++)); }

echo "========================================"
echo "  MaryLink MCP - Smoke Tests"
echo "========================================"
echo "Target: ${BASE_URL}"
echo ""

# ============================================
# TEST: Required Files
# ============================================
echo "=== Required Files ==="

files=("marylink-mcp.php" "version.json" "src/MCP/Tools_Registry.php" "src/MCP/Tool_Catalog.php" "src/MCP/Permission_Checker.php")

for file in "${files[@]}"; do
    if [ -f "$file" ]; then
        pass "$file exists"
    else
        fail "$file missing"
    fi
done

# ============================================
# TEST: Version Consistency
# ============================================
echo ""
echo "=== Version Consistency ==="

if [ -f "version.json" ] && [ -f "marylink-mcp.php" ]; then
    json_version=$(grep '"version"' version.json | grep -o '[0-9]\+\.[0-9]\+\.[0-9]\+' | head -1)
    php_version=$(grep '* Version:' marylink-mcp.php | grep -o '[0-9]\+\.[0-9]\+\.[0-9]\+' | head -1)

    if [ "$json_version" = "$php_version" ]; then
        pass "Version consistent: $json_version"
    else
        fail "Version mismatch: json=$json_version php=$php_version"
    fi
fi

# ============================================
# TEST: CRUD Handlers
# ============================================
echo ""
echo "=== CRUD Handlers ==="

registry="src/MCP/Tools_Registry.php"
if [ -f "$registry" ]; then
    for handler in "ml_publication_create" "ml_publication_update" "ml_publication_delete"; do
        if grep -q "$handler" "$registry"; then
            pass "Handler $handler exists"
        else
            fail "Handler $handler missing"
        fi
    done

    # Check metadata support
    if grep -q "_publication_step" "$registry"; then
        pass "Step metadata supported"
    else
        fail "Step metadata missing"
    fi

    if grep -q "_publication_tags\|publication_tag" "$registry"; then
        pass "Tags metadata supported"
    else
        fail "Tags metadata missing"
    fi
fi

# ============================================
# TEST: Remote (if not skipped)
# ============================================
if [ "$SKIP_REMOTE" != "1" ]; then
    echo ""
    echo "=== Remote Tests ==="

    # Test endpoint
    response=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/wp-json/mcp/v1/sse" 2>/dev/null || echo "000")
    if [ "$response" = "200" ] || [ "$response" = "401" ] || [ "$response" = "405" ]; then
        pass "MCP endpoint reachable (HTTP $response)"
    else
        fail "MCP endpoint unreachable (HTTP $response)"
    fi

    # Test initialize (skip content check if auth required)
    init_response=$(curl -s -X POST "${BASE_URL}/wp-json/mcp/v1/sse" \
        -H "Content-Type: application/json" \
        -d '{"jsonrpc":"2.0","method":"initialize","params":{"clientInfo":{"name":"test"}},"id":1}' 2>/dev/null)

    # If auth error, endpoint works but needs auth - that's OK
    if echo "$init_response" | grep -q "rest_forbidden\|forbidden\|autorisation\|code"; then
        pass "Initialize responds (auth required - OK)"
    elif echo "$init_response" | grep -q "protocolVersion"; then
        pass "Initialize returns protocolVersion"
        if echo "$init_response" | grep -q "instructions"; then
            pass "Initialize returns instructions"
        else
            fail "Initialize missing instructions"
        fi
    else
        fail "Initialize unexpected response"
    fi
else
    echo ""
    echo "=== Remote Tests SKIPPED ==="
fi

# ============================================
# SUMMARY
# ============================================
echo ""
echo "========================================"
echo "Results: $PASSED passed, $FAILED failed"
echo "========================================"

if [ $FAILED -gt 0 ]; then
    echo "TESTS FAILED - Do not commit/deploy!"
    exit 1
else
    echo "ALL TESTS PASSED"
    exit 0
fi
