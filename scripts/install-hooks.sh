#!/bin/bash
# Install git hooks for MaryLink MCP

HOOKS_DIR="$(git rev-parse --git-dir)/hooks"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

echo "Installing git hooks..."

# Create pre-commit hook
cat > "$HOOKS_DIR/pre-commit" << 'HOOK'
#!/bin/bash
# Pre-commit hook - Run smoke tests before allowing commit

echo "🔍 Running pre-commit checks..."

# Get project root
PROJECT_ROOT="$(git rev-parse --show-toplevel)"
cd "$PROJECT_ROOT"

# Run local tests only (fast)
export SKIP_REMOTE=1

if [ -f "tests/smoke-test.sh" ]; then
    bash tests/smoke-test.sh
    if [ $? -ne 0 ]; then
        echo ""
        echo "❌ Pre-commit checks FAILED"
        echo "Fix the issues above before committing."
        echo ""
        echo "To bypass (not recommended): git commit --no-verify"
        exit 1
    fi
else
    echo "⚠️  smoke-test.sh not found, skipping tests"
fi

echo "✅ Pre-commit checks passed"
exit 0
HOOK

chmod +x "$HOOKS_DIR/pre-commit"
echo "✓ pre-commit hook installed"

# Create pre-push hook (runs full tests including remote)
cat > "$HOOKS_DIR/pre-push" << 'HOOK'
#!/bin/bash
# Pre-push hook - Run full tests before pushing

echo "🔍 Running pre-push checks (including remote tests)..."

PROJECT_ROOT="$(git rev-parse --show-toplevel)"
cd "$PROJECT_ROOT"

# Run full tests including remote
export SKIP_REMOTE=0
export MCP_TEST_URL="${MCP_TEST_URL:-https://jan26.marylink.net}"

if [ -f "tests/smoke-test.sh" ]; then
    bash tests/smoke-test.sh
    if [ $? -ne 0 ]; then
        echo ""
        echo "❌ Pre-push checks FAILED"
        echo "Fix the issues above before pushing."
        exit 1
    fi
fi

echo "✅ Pre-push checks passed"
exit 0
HOOK

chmod +x "$HOOKS_DIR/pre-push"
echo "✓ pre-push hook installed"

echo ""
echo "Git hooks installed successfully!"
echo "- pre-commit: Local tests only (fast)"
echo "- pre-push: Full tests including remote"
