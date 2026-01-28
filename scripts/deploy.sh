#!/bin/bash
# MaryLink MCP - Safe Deployment Script
# Runs tests before deploying, with rollback capability

set -e

# Configuration
SITES="${DEPLOY_SITES:-mcpwizard clientsite clientsitemcp JAN26}"
SERVER="${DEPLOY_SERVER:-ax102}"
REMOTE_PATH="/home/runcloud/webapps"
LOCAL_PATH="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="/tmp/marylink-mcp-backups"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log() { echo -e "$1"; }
error() { log "${RED}ERROR: $1${NC}"; exit 1; }

# ============================================
# PRE-FLIGHT CHECKS
# ============================================
preflight() {
    log "${BLUE}╔════════════════════════════════════════╗${NC}"
    log "${BLUE}║  MaryLink MCP - Safe Deploy            ║${NC}"
    log "${BLUE}╚════════════════════════════════════════╝${NC}"

    # Check we're in the right directory
    if [ ! -f "$LOCAL_PATH/marylink-mcp.php" ]; then
        error "Not in marylink-mcp directory"
    fi

    # Get version
    VERSION=$(grep '"version"' "$LOCAL_PATH/version.json" | grep -o '[0-9]\+\.[0-9]\+\.[0-9]\+' | head -1)
    log "Version to deploy: ${GREEN}$VERSION${NC}"
    log "Target sites: $SITES"
    log ""
}

# ============================================
# RUN TESTS
# ============================================
run_tests() {
    log "${YELLOW}=== Running Pre-Deploy Tests ===${NC}"

    cd "$LOCAL_PATH"

    if [ -f "tests/smoke-test.sh" ]; then
        export SKIP_REMOTE=1
        if ! bash tests/smoke-test.sh; then
            error "Tests failed! Aborting deployment."
        fi
    else
        log "${YELLOW}⚠️  No smoke tests found, proceeding anyway${NC}"
    fi

    log ""
}

# ============================================
# CREATE BACKUP
# ============================================
create_backup() {
    log "${YELLOW}=== Creating Backups ===${NC}"

    local timestamp=$(date +%Y%m%d_%H%M%S)

    ssh $SERVER "mkdir -p $BACKUP_DIR"

    for site in $SITES; do
        local plugin_path="$REMOTE_PATH/$site/wp-content/plugins/marylink-mcp"
        if ssh $SERVER "[ -d '$plugin_path' ]"; then
            ssh $SERVER "cp -r '$plugin_path' '$BACKUP_DIR/${site}_${timestamp}'"
            log "${GREEN}✓${NC} Backup: $site"
        else
            log "${YELLOW}○${NC} Skip backup: $site (not installed)"
        fi
    done

    echo "$timestamp" > /tmp/last_deploy_timestamp
    log ""
}

# ============================================
# BUILD & UPLOAD
# ============================================
build_and_upload() {
    log "${YELLOW}=== Building & Uploading ===${NC}"

    # Create tarball and upload via scp (Windows compatible)
    cd "$LOCAL_PATH"
    tar czf - --exclude='.git' --exclude='.DS_Store' --exclude='*.log' . | \
        ssh $SERVER "rm -rf /tmp/marylink-mcp-new && mkdir -p /tmp/marylink-mcp-new && cd /tmp/marylink-mcp-new && tar xzf -"

    log "${GREEN}✓${NC} Uploaded to server"
    log ""
}

# ============================================
# DEPLOY TO SITES
# ============================================
deploy_to_sites() {
    log "${YELLOW}=== Deploying to Sites ===${NC}"

    for site in $SITES; do
        local plugin_path="$REMOTE_PATH/$site/wp-content/plugins/marylink-mcp"

        ssh $SERVER "rm -rf '$plugin_path' && \
                     cp -r /tmp/marylink-mcp-new '$plugin_path' && \
                     chown -R runcloud:runcloud '$plugin_path'"

        log "${GREEN}✓${NC} Deployed to $site"
    done

    # Cleanup
    ssh $SERVER "rm -rf /tmp/marylink-mcp-new /tmp/marylink-mcp-*.zip"
    log ""
}

# ============================================
# POST-DEPLOY VERIFICATION
# ============================================
verify_deployment() {
    log "${YELLOW}=== Verifying Deployment ===${NC}"

    local failed=0

    for site in $SITES; do
        local remote_version=$(ssh $SERVER "grep '\"version\"' '$REMOTE_PATH/$site/wp-content/plugins/marylink-mcp/version.json' 2>/dev/null | grep -o '[0-9]\+\.[0-9]\+\.[0-9]\+'" || echo "unknown")

        if [ "$remote_version" = "$VERSION" ]; then
            log "${GREEN}✓${NC} $site: v$remote_version"
        else
            log "${RED}✗${NC} $site: v$remote_version (expected $VERSION)"
            ((failed++))
        fi
    done

    if [ $failed -gt 0 ]; then
        log ""
        log "${RED}⚠️  Some sites failed verification!${NC}"
        log "Run: $0 rollback"
        return 1
    fi

    log ""
}

# ============================================
# ROLLBACK
# ============================================
rollback() {
    log "${YELLOW}=== Rolling Back ===${NC}"

    if [ ! -f /tmp/last_deploy_timestamp ]; then
        error "No deployment to rollback (timestamp not found)"
    fi

    local timestamp=$(cat /tmp/last_deploy_timestamp)

    for site in $SITES; do
        local backup_path="$BACKUP_DIR/${site}_${timestamp}"
        local plugin_path="$REMOTE_PATH/$site/wp-content/plugins/marylink-mcp"

        if ssh $SERVER "[ -d '$backup_path' ]"; then
            ssh $SERVER "rm -rf '$plugin_path' && cp -r '$backup_path' '$plugin_path' && chown -R runcloud:runcloud '$plugin_path'"
            log "${GREEN}✓${NC} Rolled back $site"
        else
            log "${YELLOW}○${NC} No backup for $site"
        fi
    done

    log "${GREEN}Rollback complete${NC}"
}

# ============================================
# MAIN
# ============================================
main() {
    case "${1:-deploy}" in
        deploy)
            preflight
            run_tests
            create_backup
            build_and_upload
            deploy_to_sites
            verify_deployment
            log "${GREEN}╔════════════════════════════════════════╗${NC}"
            log "${GREEN}║  Deployment Complete: v$VERSION         ${NC}"
            log "${GREEN}╚════════════════════════════════════════╝${NC}"
            ;;
        rollback)
            rollback
            ;;
        test)
            cd "$LOCAL_PATH"
            bash tests/smoke-test.sh
            ;;
        *)
            echo "Usage: $0 [deploy|rollback|test]"
            exit 1
            ;;
    esac
}

main "$@"
