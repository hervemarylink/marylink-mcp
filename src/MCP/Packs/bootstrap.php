<?php
/**
 * MCP Packs Bootstrap
 *
 * Initializes all available MCP packs.
 * This file is loaded automatically by the plugin autoloader.
 *
 * @package MCP_No_Headless
 * @since 3.0.5
 */

namespace MCP_No_Headless\MCP\Packs;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Initialize all registered packs
 */
function init_packs(): void {
    // CREW Pack - Tool Assembly
    if (class_exists(\MCP_No_Headless\MCP\Packs\Crew\CrewPack::class)) {
        \MCP_No_Headless\MCP\Packs\Crew\CrewPack::init();
    }

    // Add more packs here as they are developed
    // Example:
    // if (class_exists(\MCP_No_Headless\MCP\Packs\Quality\QualityPack::class)) {
    //     \MCP_No_Headless\MCP\Packs\Quality\QualityPack::init();
    // }
}

// Initialize packs on plugins_loaded (after main plugin init)
add_action('plugins_loaded', __NAMESPACE__ . '\init_packs', 25);
