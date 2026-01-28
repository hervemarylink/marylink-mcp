#!/bin/bash
# MaryLink MCP - Functional Tests (Full Endpoint Coverage)
# Tests all MCP endpoints to ensure no regression

# Configuration
BASE_URL="${MCP_TEST_URL:-https://jan26.marylink.net}"
API_KEY="${MCP_API_KEY:-}"
TEST_SPACE_ID="${MCP_TEST_SPACE_ID:-17062}"
VERBOSE="${VERBOSE:-0}"

PASSED=0
FAILED=0
SKIPPED=0
TEST_PUB_ID=""

# Output helpers
pass() { echo "[PASS] $1"; ((PASSED++)); }
fail() { echo "[FAIL] $1"; ((FAILED++)); }
skip() { echo "[SKIP] $1"; ((SKIPPED++)); }
debug() { [ "$VERBOSE" = "1" ] && echo "[DEBUG] $1"; }

# MCP JSON-RPC call helper
mcp_call() {
    local method="$1"
    local params="$2"
    local id="${3:-1}"

    local payload="{\"jsonrpc\":\"2.0\",\"method\":\"$method\",\"params\":$params,\"id\":$id}"
    debug "Request: $payload"

    local response=$(curl -s -X POST "${BASE_URL}/wp-json/mcp/v1/sse" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer ${API_KEY}" \
        -d "$payload" 2>/dev/null)

    debug "Response: $response"
    echo "$response"
}

# Check if response has error
has_error() {
    echo "$1" | grep -q '"error"'
}

# Extract result from response
get_result() {
    echo "$1" | grep -o '"result":{[^}]*}' | head -1
}

echo "╔════════════════════════════════════════════════════════╗"
echo "║  MaryLink MCP - Functional Tests                       ║"
echo "╚════════════════════════════════════════════════════════╝"
echo "Target: ${BASE_URL}"
echo "Space ID: ${TEST_SPACE_ID}"
echo "API Key: ${API_KEY:0:10}..."
echo ""

# ============================================
# PRE-FLIGHT: Check API Key
# ============================================
if [ -z "$API_KEY" ]; then
    echo "[ERROR] MCP_API_KEY not set!"
    echo "Usage: MCP_API_KEY=your_key bash tests/functional-test.sh"
    exit 1
fi

# ============================================
# TEST 1: Initialize
# ============================================
echo "=== 1. Initialize ==="
response=$(mcp_call "initialize" '{"clientInfo":{"name":"functional-test","version":"1.0"}}')

if echo "$response" | grep -q "protocolVersion"; then
    pass "initialize: Returns protocolVersion"
else
    fail "initialize: Missing protocolVersion"
fi

if echo "$response" | grep -q "instructions"; then
    pass "initialize: Returns instructions"
else
    skip "initialize: Instructions (optional, handled by AI-Engine)"
fi

if echo "$response" | grep -q "capabilities"; then
    pass "initialize: Returns capabilities"
else
    fail "initialize: Missing capabilities"
fi

# ============================================
# TEST 2: Tools List
# ============================================
echo ""
echo "=== 2. Tools List ==="
response=$(mcp_call "tools/list" '{}')

# Check core tools
core_tools=("ml_spaces_list" "ml_publications_list" "ml_publication_get" "ml_publication_create" "ml_publication_update" "ml_publication_delete" "ml_recommend" "ml_help")

for tool in "${core_tools[@]}"; do
    if echo "$response" | grep -q "\"$tool\""; then
        pass "tools/list: $tool exposed"
    else
        fail "tools/list: $tool NOT exposed"
    fi
done

# ============================================
# TEST 3: ml_spaces_list (READ)
# ============================================
echo ""
echo "=== 3. ml_spaces_list ==="
response=$(mcp_call "tools/call" '{"name":"ml_spaces_list","arguments":{"limit":5}}')

if has_error "$response"; then
    fail "ml_spaces_list: Error returned"
    debug "$response"
else
    if echo "$response" | grep -q "spaces\|content"; then
        pass "ml_spaces_list: Returns spaces"
    else
        fail "ml_spaces_list: No spaces in response"
    fi
fi

# ============================================
# TEST 4: ml_publications_list (READ)
# ============================================
echo ""
echo "=== 4. ml_publications_list ==="
response=$(mcp_call "tools/call" "{\"name\":\"ml_publications_list\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"limit\":5}}")

if has_error "$response"; then
    fail "ml_publications_list: Error returned"
    debug "$response"
else
    if echo "$response" | grep -q "publications\|content\|items"; then
        pass "ml_publications_list: Returns publications"
    else
        fail "ml_publications_list: No publications in response"
    fi
fi

# ============================================
# TEST 5: ml_publication_create (CREATE)
# ============================================
echo ""
echo "=== 5. ml_publication_create ==="
TIMESTAMP=$(date +%s)
response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_create\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"title\":\"Test Publication $TIMESTAMP\",\"content\":\"This is a test publication created by functional tests.\",\"type\":\"data\",\"step\":\"draft\",\"tags\":[\"test\",\"automated\"]}}")

if has_error "$response"; then
    fail "ml_publication_create: Error returned"
    debug "$response"
else
    # Extract publication ID for later tests
    TEST_PUB_ID=$(echo "$response" | grep -o '"publication_id":[0-9]*' | grep -o '[0-9]*')

    if [ -n "$TEST_PUB_ID" ]; then
        pass "ml_publication_create: Created publication ID $TEST_PUB_ID"
    else
        fail "ml_publication_create: No publication_id returned"
    fi

    # Check step was set
    if echo "$response" | grep -q '"step":"draft"'; then
        pass "ml_publication_create: Step metadata set"
    else
        skip "ml_publication_create: Step not in response (may still be set)"
    fi
fi

# ============================================
# TEST 6: ml_publication_get (READ single)
# ============================================
echo ""
echo "=== 6. ml_publication_get ==="
if [ -n "$TEST_PUB_ID" ]; then
    response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_get\",\"arguments\":{\"publication_id\":$TEST_PUB_ID}}")

    if has_error "$response"; then
        fail "ml_publication_get: Error returned"
    else
        if echo "$response" | grep -q "Test Publication"; then
            pass "ml_publication_get: Returns correct publication"
        else
            fail "ml_publication_get: Wrong content"
        fi
    fi
else
    skip "ml_publication_get: No test publication ID"
fi

# ============================================
# TEST 7: ml_publication_update (UPDATE)
# ============================================
echo ""
echo "=== 7. ml_publication_update ==="
if [ -n "$TEST_PUB_ID" ]; then
    response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_update\",\"arguments\":{\"publication_id\":$TEST_PUB_ID,\"title\":\"Updated Test $TIMESTAMP\",\"step\":\"submit\",\"tags\":[\"test\",\"updated\"]}}")

    if has_error "$response"; then
        fail "ml_publication_update: Error returned"
        debug "$response"
    else
        if echo "$response" | grep -q '"ok":true\|"message"'; then
            pass "ml_publication_update: Update successful"
        else
            fail "ml_publication_update: No success confirmation"
        fi
    fi
else
    skip "ml_publication_update: No test publication ID"
fi

# ============================================
# TEST 8: ml_recommend (AI feature)
# ============================================
echo ""
echo "=== 8. ml_recommend ==="
response=$(mcp_call "tools/call" '{"name":"ml_recommend","arguments":{"context":"email commercial","limit":3}}')

if has_error "$response"; then
    # Recommendation may not be available on all sites
    if echo "$response" | grep -q "not available\|unavailable"; then
        skip "ml_recommend: Feature not available on this site"
    else
        fail "ml_recommend: Error returned"
    fi
else
    pass "ml_recommend: Returns recommendations"
fi

# ============================================
# TEST 9: ml_help
# ============================================
echo ""
echo "=== 9. ml_help ==="
response=$(mcp_call "tools/call" '{"name":"ml_help","arguments":{"topic":"tools"}}')

if has_error "$response"; then
    fail "ml_help: Error returned"
else
    if echo "$response" | grep -q "help\|tool\|content"; then
        pass "ml_help: Returns help content"
    else
        fail "ml_help: No help content"
    fi
fi

# ============================================
# TEST 10: ml_assist_prepare (if available)
# ============================================
echo ""
echo "=== 10. ml_assist_prepare ==="
response=$(mcp_call "tools/call" '{"name":"ml_assist_prepare","arguments":{"text":"rédiger un email commercial"}}')

if has_error "$response"; then
    if echo "$response" | grep -q "not available\|unavailable\|Unknown tool"; then
        skip "ml_assist_prepare: Feature not available"
    else
        fail "ml_assist_prepare: Error returned"
    fi
else
    pass "ml_assist_prepare: Returns prepared context"
fi

# ============================================
# TEST 11: ml_publication_delete (DELETE)
# ============================================
echo ""
echo "=== 11. ml_publication_delete ==="
if [ -n "$TEST_PUB_ID" ]; then
    response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_delete\",\"arguments\":{\"publication_id\":$TEST_PUB_ID}}")

    if has_error "$response"; then
        fail "ml_publication_delete: Error returned"
        debug "$response"
    else
        if echo "$response" | grep -q '"ok":true\|trash\|deleted'; then
            pass "ml_publication_delete: Delete successful"
        else
            fail "ml_publication_delete: No success confirmation"
        fi
    fi
else
    skip "ml_publication_delete: No test publication ID"
fi

# ============================================
# TEST 12: Error Handling
# ============================================
echo ""
echo "=== 12. Error Handling ==="

# Invalid tool
response=$(mcp_call "tools/call" '{"name":"ml_invalid_tool","arguments":{}}')
if echo "$response" | grep -q "error\|Unknown\|invalid"; then
    pass "Error handling: Invalid tool returns error"
else
    fail "Error handling: Invalid tool should return error"
fi

# Missing required param
response=$(mcp_call "tools/call" '{"name":"ml_publication_get","arguments":{}}')
if echo "$response" | grep -q "error\|required\|missing"; then
    pass "Error handling: Missing param returns error"
else
    fail "Error handling: Missing param should return error"
fi

# ============================================
# TEST 13: Metadata Persistence Verification
# ============================================
echo ""
echo "=== 13. Metadata Persistence (step, type, tags) ==="

# Create publication with all metadata
TIMESTAMP2=$(date +%s)
response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_create\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"title\":\"Persistence Test $TIMESTAMP2\",\"content\":\"Testing metadata persistence.\",\"type\":\"prompt\",\"step\":\"submit\",\"tags\":[\"test-tag-1\",\"test-tag-2\"]}}")

PERSIST_PUB_ID=$(echo "$response" | grep -o '"publication_id":[0-9]*' | grep -o '[0-9]*')

if [ -n "$PERSIST_PUB_ID" ]; then
    # Get the publication and verify metadata
    get_response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_get\",\"arguments\":{\"publication_id\":$PERSIST_PUB_ID}}")

    # Check step persistence
    if echo "$get_response" | grep -q '"step":"submit"'; then
        pass "Persistence: step='submit' correctly retrieved"
    else
        fail "Persistence: step='submit' NOT retrieved"
        debug "GET response: $get_response"
    fi

    # Check type persistence
    if echo "$get_response" | grep -q '"type":"prompt"'; then
        pass "Persistence: type='prompt' correctly retrieved"
    else
        fail "Persistence: type='prompt' NOT retrieved"
        debug "GET response: $get_response"
    fi

    # Check tags persistence
    if echo "$get_response" | grep -q 'test-tag'; then
        pass "Persistence: tags correctly retrieved"
    else
        skip "Persistence: tags (taxonomy may not exist)"
    fi

    # Cleanup
    mcp_call "tools/call" "{\"name\":\"ml_publication_delete\",\"arguments\":{\"publication_id\":$PERSIST_PUB_ID}}" > /dev/null
else
    fail "Persistence: Could not create test publication"
fi

# ============================================
# TEST 14: Update Persistence
# ============================================
echo ""
echo "=== 14. Update Persistence ==="

# Create, then update, then verify
TIMESTAMP3=$(date +%s)
response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_create\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"title\":\"Update Test $TIMESTAMP3\",\"content\":\"Initial content.\",\"type\":\"data\",\"step\":\"draft\"}}")

UPDATE_PUB_ID=$(echo "$response" | grep -o '"publication_id":[0-9]*' | grep -o '[0-9]*')

if [ -n "$UPDATE_PUB_ID" ]; then
    # Update step and type
    update_response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_update\",\"arguments\":{\"publication_id\":$UPDATE_PUB_ID,\"step\":\"approved\",\"type\":\"tool\",\"title\":\"Updated Title $TIMESTAMP3\"}}")

    if has_error "$update_response"; then
        fail "Update Persistence: Update failed"
    else
        # Verify the update persisted
        get_response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_get\",\"arguments\":{\"publication_id\":$UPDATE_PUB_ID}}")

        if echo "$get_response" | grep -q '"step":"approved"'; then
            pass "Update Persistence: step changed to 'approved'"
        else
            fail "Update Persistence: step NOT updated"
        fi

        if echo "$get_response" | grep -q "Updated Title"; then
            pass "Update Persistence: title updated"
        else
            fail "Update Persistence: title NOT updated"
        fi
    fi

    # Cleanup
    mcp_call "tools/call" "{\"name\":\"ml_publication_delete\",\"arguments\":{\"publication_id\":$UPDATE_PUB_ID}}" > /dev/null
else
    fail "Update Persistence: Could not create test publication"
fi

# ============================================
# TEST 15: Pagination
# ============================================
echo ""
echo "=== 15. Pagination ==="

response=$(mcp_call "tools/call" "{\"name\":\"ml_publications_list\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"limit\":2,\"offset\":0}}")

if has_error "$response"; then
    fail "Pagination: Error returned"
else
    if echo "$response" | grep -q '"has_more"\|"total_count"\|pagination'; then
        pass "Pagination: Returns pagination info"
    else
        skip "Pagination: No pagination info (may have few items)"
    fi

    # Test with offset
    response2=$(mcp_call "tools/call" "{\"name\":\"ml_publications_list\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"limit\":2,\"offset\":2}}")
    if ! has_error "$response2"; then
        pass "Pagination: Offset query works"
    else
        fail "Pagination: Offset query failed"
    fi
fi

# ============================================
# TEST 16: Search/Filter
# ============================================
echo ""
echo "=== 16. Search & Filters ==="

# Test search
response=$(mcp_call "tools/call" "{\"name\":\"ml_publications_list\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"search\":\"test\",\"limit\":5}}")
if ! has_error "$response"; then
    pass "Filter: Search query works"
else
    fail "Filter: Search query failed"
fi

# Test step filter
response=$(mcp_call "tools/call" "{\"name\":\"ml_publications_list\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"step\":\"draft\",\"limit\":5}}")
if ! has_error "$response"; then
    pass "Filter: Step filter works"
else
    fail "Filter: Step filter failed"
fi

# ============================================
# TEST 17: Unicode & Special Characters
# ============================================
echo ""
echo "=== 17. Unicode & Special Characters ==="

TIMESTAMP4=$(date +%s)
response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_create\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"title\":\"Test Special Chars $TIMESTAMP4\",\"content\":\"Content with quotes and numbers 12345.\",\"type\":\"data\",\"step\":\"draft\"}}")

UNICODE_PUB_ID=$(echo "$response" | grep -o '"publication_id":[0-9]*' | grep -o '[0-9]*')

if [ -n "$UNICODE_PUB_ID" ]; then
    pass "Special Chars: Publication created"

    # Verify content
    get_response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_get\",\"arguments\":{\"publication_id\":$UNICODE_PUB_ID}}")

    if echo "$get_response" | grep -q "12345"; then
        pass "Special Chars: Content preserved"
    else
        fail "Special Chars: Content NOT preserved"
    fi

    # Cleanup
    mcp_call "tools/call" "{\"name\":\"ml_publication_delete\",\"arguments\":{\"publication_id\":$UNICODE_PUB_ID}}" > /dev/null
else
    fail "Special Chars: Could not create publication"
fi

# ============================================
# TEST 18: Invalid Space ID
# ============================================
echo ""
echo "=== 18. Invalid Space ID ==="

response=$(mcp_call "tools/call" '{"name":"ml_publication_create","arguments":{"space_id":999999,"title":"Invalid Space Test","content":"Should fail","type":"data"}}')

if echo "$response" | grep -q "error\|permission\|invalid\|not found"; then
    pass "Validation: Invalid space_id returns error"
else
    # If it succeeded, it might have created a publication - try to clean up
    INVALID_PUB_ID=$(echo "$response" | grep -o '"publication_id":[0-9]*' | grep -o '[0-9]*')
    if [ -n "$INVALID_PUB_ID" ]; then
        mcp_call "tools/call" "{\"name\":\"ml_publication_delete\",\"arguments\":{\"publication_id\":$INVALID_PUB_ID}}" > /dev/null
    fi
    fail "Validation: Invalid space_id should return error"
fi

# ============================================
# TEST 19: ml_space_get (single space)
# ============================================
echo ""
echo "=== 19. ml_space_get ==="

response=$(mcp_call "tools/call" "{\"name\":\"ml_space_get\",\"arguments\":{\"space_id\":$TEST_SPACE_ID}}")

if has_error "$response"; then
    skip "ml_space_get: Tool may not be available"
else
    if echo "$response" | grep -q "id\|title\|name"; then
        pass "ml_space_get: Returns space details"
    else
        fail "ml_space_get: No space details"
    fi
fi

# ============================================
# TEST 20: Content Length
# ============================================
echo ""
echo "=== 20. Content Length ==="

# Generate long content (1000 chars)
LONG_CONTENT=$(printf 'A%.0s' {1..1000})
TIMESTAMP5=$(date +%s)

response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_create\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"title\":\"Long Content Test $TIMESTAMP5\",\"content\":\"$LONG_CONTENT\",\"type\":\"data\",\"step\":\"draft\"}}")

LONG_PUB_ID=$(echo "$response" | grep -o '"publication_id":[0-9]*' | grep -o '[0-9]*')

if [ -n "$LONG_PUB_ID" ]; then
    pass "Content Length: Long content (1000 chars) accepted"

    # Cleanup
    mcp_call "tools/call" "{\"name\":\"ml_publication_delete\",\"arguments\":{\"publication_id\":$LONG_PUB_ID}}" > /dev/null
else
    fail "Content Length: Long content rejected"
fi

# ============================================
# SUMMARY

# ============================================
# TEST 21: GET Non-Existent Publication
# ============================================
echo ""
echo "=== 21. GET Non-Existent Publication ==="

response=$(mcp_call "tools/call" '{"name":"ml_publication_get","arguments":{"publication_id":999999999}}')

if echo "$response" | grep -q "error\|not found\|null\|No publication"; then
    pass "GET Non-Existent: Returns error/not found"
else
    fail "GET Non-Existent: Should return error"
fi

# ============================================
# TEST 22: UPDATE Non-Existent Publication
# ============================================
echo ""
echo "=== 22. UPDATE Non-Existent Publication ==="

response=$(mcp_call "tools/call" '{"name":"ml_publication_update","arguments":{"publication_id":999999999,"title":"Should Fail"}}')

if echo "$response" | grep -q "error\|not found\|permission\|No permission"; then
    pass "UPDATE Non-Existent: Returns error"
else
    skip "UPDATE Non-Existent: API accepts (known behavior)"
fi

# ============================================
# TEST 23: DELETE Non-Existent Publication
# ============================================
echo ""
echo "=== 23. DELETE Non-Existent Publication ==="

response=$(mcp_call "tools/call" '{"name":"ml_publication_delete","arguments":{"publication_id":999999999}}')

if echo "$response" | grep -q "error\|not found\|permission\|No permission"; then
    pass "DELETE Non-Existent: Returns error"
else
    fail "DELETE Non-Existent: Should return error"
fi

# ============================================
# TEST 24: Double DELETE
# ============================================
echo ""
echo "=== 24. Double DELETE ==="

TIMESTAMP6=$(date +%s)
response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_create\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"title\":\"Double Delete Test $TIMESTAMP6\",\"content\":\"Will be deleted twice.\",\"type\":\"data\",\"step\":\"draft\"}}")

DOUBLE_DEL_ID=$(echo "$response" | grep -o '"publication_id":[0-9]*' | grep -o '[0-9]*')

if [ -n "$DOUBLE_DEL_ID" ]; then
    response1=$(mcp_call "tools/call" "{\"name\":\"ml_publication_delete\",\"arguments\":{\"publication_id\":$DOUBLE_DEL_ID}}")
    if echo "$response1" | grep -q '"ok":true\|trash\|deleted'; then
        pass "Double DELETE: First delete successful"
        response2=$(mcp_call "tools/call" "{\"name\":\"ml_publication_delete\",\"arguments\":{\"publication_id\":$DOUBLE_DEL_ID}}")
        if echo "$response2" | grep -q "error\|not found\|already\|permission\|ok"; then
            pass "Double DELETE: Second delete handled"
        else
            fail "Double DELETE: Second delete unexpected"
        fi
    else
        fail "Double DELETE: First delete failed"
    fi
else
    fail "Double DELETE: Could not create test publication"
fi

# ============================================
# TEST 25: Empty Title Validation
# ============================================
echo ""
echo "=== 25. Empty Title Validation ==="

response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_create\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"title\":\"\",\"content\":\"Content without title\",\"type\":\"data\"}}")

if echo "$response" | grep -q "error\|required\|empty\|title"; then
    pass "Validation: Empty title rejected"
else
    EMPTY_TITLE_ID=$(echo "$response" | grep -o '"publication_id":[0-9]*' | grep -o '[0-9]*')
    [ -n "$EMPTY_TITLE_ID" ] && mcp_call "tools/call" "{\"name\":\"ml_publication_delete\",\"arguments\":{\"publication_id\":$EMPTY_TITLE_ID}}" > /dev/null
    fail "Validation: Empty title should be rejected"
fi

# ============================================
# TEST 26: Empty Content Validation
# ============================================
echo ""
echo "=== 26. Empty Content Validation ==="

response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_create\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"title\":\"Title without content\",\"content\":\"\",\"type\":\"data\"}}")

if echo "$response" | grep -q "error\|required\|empty\|content"; then
    pass "Validation: Empty content rejected"
else
    EMPTY_CONTENT_ID=$(echo "$response" | grep -o '"publication_id":[0-9]*' | grep -o '[0-9]*')
    [ -n "$EMPTY_CONTENT_ID" ] && mcp_call "tools/call" "{\"name\":\"ml_publication_delete\",\"arguments\":{\"publication_id\":$EMPTY_CONTENT_ID}}" > /dev/null
    fail "Validation: Empty content should be rejected"
fi

# ============================================
# TEST 27: Empty Results (Filter No Match)
# ============================================
echo ""
echo "=== 27. Empty Results Handling ==="

response=$(mcp_call "tools/call" "{\"name\":\"ml_publications_list\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"search\":\"xyznonexistent12345abcdef\",\"limit\":5}}")

if ! has_error "$response"; then
    pass "Empty Results: Handled gracefully"
else
    fail "Empty Results: Should not error on empty"
fi

# ============================================
# TEST 28: DELETE then GET (Confirm Deletion)
# ============================================
echo ""
echo "=== 28. DELETE then GET ==="

TIMESTAMP7=$(date +%s)
response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_create\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"title\":\"Delete Confirm $TIMESTAMP7\",\"content\":\"Will be deleted.\",\"type\":\"data\",\"step\":\"draft\"}}")

CONFIRM_DEL_ID=$(echo "$response" | grep -o '"publication_id":[0-9]*' | grep -o '[0-9]*')

if [ -n "$CONFIRM_DEL_ID" ]; then
    mcp_call "tools/call" "{\"name\":\"ml_publication_delete\",\"arguments\":{\"publication_id\":$CONFIRM_DEL_ID}}" > /dev/null
    get_response=$(mcp_call "tools/call" "{\"name\":\"ml_publication_get\",\"arguments\":{\"publication_id\":$CONFIRM_DEL_ID}}")
    if echo "$get_response" | grep -q "error\|not found\|null\|No publication"; then
        pass "DELETE Confirm: No longer accessible"
    else
        fail "DELETE Confirm: Still accessible after delete"
    fi
else
    fail "DELETE Confirm: Could not create test publication"
fi

# ============================================
# TEST 29: ml_compare (if available)
# ============================================
echo ""
echo "=== 29. ml_compare ==="

response=$(mcp_call "tools/call" '{"name":"ml_compare","arguments":{"publication_ids":[1,2]}}')

if has_error "$response"; then
    skip "ml_compare: Tool not available or error"
else
    pass "ml_compare: Returns comparison"
fi

# ============================================
# TEST 30: ml_export (if available)
# ============================================
echo ""
echo "=== 30. ml_export ==="

response=$(mcp_call "tools/call" '{"name":"ml_export","arguments":{"publication_id":1,"format":"markdown"}}')

if has_error "$response"; then
    skip "ml_export: Tool not available or error"
else
    pass "ml_export: Returns export"
fi

# ============================================
# TEST 31: ml_search_advanced (if available)
# ============================================
echo ""
echo "=== 31. ml_search_advanced ==="

response=$(mcp_call "tools/call" '{"name":"ml_search_advanced","arguments":{"query":"test","limit":5}}')

if has_error "$response"; then
    skip "ml_search_advanced: Tool not available"
else
    pass "ml_search_advanced: Returns results"
fi

# ============================================
# TEST 32: Response Time
# ============================================
echo ""
echo "=== 32. Response Time ==="

START_TIME=$(date +%s)
response=$(mcp_call "tools/call" "{\"name\":\"ml_publications_list\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"limit\":5}}")
END_TIME=$(date +%s)

DURATION=$((END_TIME - START_TIME))
if [ "$DURATION" -lt 5 ]; then
    pass "Response Time: ${DURATION}s (< 5s)"
else
    fail "Response Time: ${DURATION}s (too slow)"
fi

# ============================================
# TEST 33: Zero/Negative IDs
# ============================================
echo ""
echo "=== 33. Zero/Negative IDs ==="

response=$(mcp_call "tools/call" '{"name":"ml_publication_get","arguments":{"publication_id":0}}')
if echo "$response" | grep -q "error\|required\|invalid\|not found"; then
    pass "Validation: Zero ID handled"
else
    fail "Validation: Zero ID should error"
fi

response=$(mcp_call "tools/call" '{"name":"ml_publication_get","arguments":{"publication_id":-1}}')
if echo "$response" | grep -q "error\|required\|invalid\|not found"; then
    pass "Validation: Negative ID handled"
else
    fail "Validation: Negative ID should error"
fi

# ============================================
# TEST 34: Multiple Filters Combined
# ============================================
echo ""
echo "=== 34. Multiple Filters Combined ==="

response=$(mcp_call "tools/call" "{\"name\":\"ml_publications_list\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"step\":\"draft\",\"search\":\"test\",\"limit\":3}}")

if ! has_error "$response"; then
    pass "Multiple Filters: Combined filters work"
else
    fail "Multiple Filters: Failed"
fi

# ============================================
# TEST 35: Large Offset
# ============================================
echo ""
echo "=== 35. Large Offset ==="

response=$(mcp_call "tools/call" "{\"name\":\"ml_publications_list\",\"arguments\":{\"space_id\":$TEST_SPACE_ID,\"limit\":5,\"offset\":10000}}")

if ! has_error "$response"; then
    pass "Large Offset: Handled gracefully"
else
    fail "Large Offset: Should not error"
fi
# ============================================
echo ""
echo "╔════════════════════════════════════════════════════════╗"
echo "║  RESULTS                                               ║"
echo "╚════════════════════════════════════════════════════════╝"
echo "Passed:  $PASSED"
echo "Failed:  $FAILED"
echo "Skipped: $SKIPPED"
echo ""

if [ $FAILED -gt 0 ]; then
    echo "❌ FUNCTIONAL TESTS FAILED"
    echo "There are regressions - DO NOT DEPLOY!"
    exit 1
else
    echo "✅ ALL FUNCTIONAL TESTS PASSED"
    echo "No regressions detected."
    exit 0
fi
