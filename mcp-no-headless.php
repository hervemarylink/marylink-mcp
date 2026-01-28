<?php
/**
 * MCP No Headless - Legacy Bootstrap
 *
 * This file provides backward compatibility for code that references
 * the old mcp-no-headless plugin. It simply loads the main plugin.
 *
 * NOT a WordPress plugin - no Plugin Name header.
 *
 * @package MaryLink_MCP
 * @since 2.2.0
 */

namespace MCP_No_Headless;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('MCPNH_VERSION', '1.0.0');
define('MCPNH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MCPNH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MCPNH_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Autoloader for plugin classes
 */
spl_autoload_register(function ($class) {
    $prefix = 'MCP_No_Headless\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative_class = substr($class, strlen($prefix));
    $path = MCPNH_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

/**
 * Check plugin dependencies (none required for no-headless mode)
 */
function mcpnh_check_dependencies(): bool {
    return true;
}

/**
 * Initialize the plugin
 */
function mcpnh_init(): void {
    load_plugin_textdomain('mcp-no-headless', false, dirname(MCPNH_PLUGIN_BASENAME) . '/languages');

    new User\Token_Manager();
    new User\Profile_Tab();
    new MCP\Tools_Registry();

    // Register scoring cron
    Services\Scoring_Service::register_cron();

    // Register audit log cron
    Ops\Audit_Logger::register_cron();

    // Setup error handlers for MCP requests
    if (defined('DOING_MCP_REQUEST') && DOING_MCP_REQUEST) {
        Ops\Error_Handler::setup_handlers();
    }

    // Initialize admin page
    if (is_admin()) {
        Admin\Admin_Page::init();
    }

    if (class_exists('Meow_MWAI_Core')) {
        new Integration\AI_Engine_Bridge();
    }

    // Register Picasso integration hook for Auto-Improve governance
    mcpnh_register_picasso_hooks();
}
add_action('plugins_loaded', __NAMESPACE__ . '\mcpnh_init', 20);

/**
 * Register Picasso integration hooks for Auto-Improve
 *
 * This hook allows Picasso to define which publications are "approved"
 * and should not be overwritten by ml_auto_improve in update mode.
 */
function mcpnh_register_picasso_hooks(): void {
    // Hook for approved publication detection (used by Auto-Improve)
    add_filter('ml_auto_improve_is_approved_publication', function($is_approved, $publication_id, $user_id) {
        // If Picasso Backend is available, use its workflow step logic
        if (class_exists('Picasso_Backend\\Utils\\Publication')) {
            $step = get_post_meta($publication_id, '_picasso_workflow_step', true);

            // Define which steps are considered "locked/approved"
            $locked_steps = apply_filters('picasso_locked_workflow_steps', [
                'approved',
                'published',
                'locked',
                'archived',
                'validated',
            ]);

            if (in_array($step, $locked_steps, true)) {
                return true;
            }
        }

        // Fallback: consider 'publish' status as approved
        return $is_approved;
    }, 10, 3);
}

/**
 * Register REST API routes
 */
function mcpnh_register_rest_routes(): void {
    // Ops endpoints (health, audit, rate-limits, token management)
    Ops\REST_Controller::register_routes();

    // MCP JSON-RPC endpoint (Claude Desktop, MCP clients)
    MCP\Http\MCP_Controller::register_routes();
}
add_action('rest_api_init', __NAMESPACE__ . '\mcpnh_register_rest_routes');

function mcpnh_activate(): void {
    // Create audit table
    Ops\Audit_Logger::create_table();

    // Create mission tokens table (B2B2B)
    User\Mission_Token_Manager::create_table();

    // Register crons
    Services\Scoring_Service::register_cron();
    Ops\Audit_Logger::register_cron();

    flush_rewrite_rules();
}
register_activation_hook(__FILE__, __NAMESPACE__ . '\mcpnh_activate');

function mcpnh_deactivate(): void {
    // Unregister crons
    Services\Scoring_Service::unregister_cron();
    Ops\Audit_Logger::unregister_cron();

    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\mcpnh_deactivate');

add_filter('bb_exclude_endpoints_from_restriction', function($endpoints, $current) {
    // MCP Server endpoints
    $endpoints[] = '/mcp/v1/mcp';
    $endpoints[] = '/mcp/v1/sse';
    $endpoints[] = '/mcp/v1/discover';
    $endpoints[] = '/mcp/v1/messages';
    return $endpoints;
}, 10, 2);

// Register WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('marylink baseline', '\MCP_No_Headless\CLI\Baseline_Command');
    \WP_CLI::add_command('marylink migrate', '\MCP_No_Headless\CLI\Migration_Command');
    \WP_CLI::add_command('marylink schema', '\MCP_No_Headless\CLI\Schema_Command');
}
