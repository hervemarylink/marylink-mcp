<?php
/**
 * CREW Pack - Tool Assembly and Orchestration Pack
 *
 * Provides tools for dynamic tool creation through component assembly.
 *
 * Tools included:
 * - ml_build: Create tools by assembling prompt + contents + style
 *
 * @package MCP_No_Headless
 * @since 3.0.0
 */

namespace MCP_No_Headless\MCP\Packs\Crew;

use MCP_No_Headless\MCP\Core\Router_V3;
use MCP_No_Headless\MCP\Packs\Crew\Tools\Build;

class CrewPack {

    const PACK_NAME = 'crew';
    const VERSION = '1.0.0';

    /**
     * Initialize the pack
     */
    public static function init(): void {
        // Register pack in WordPress options
        self::register_pack();

        // Register tools with Router
        self::register_tools();

        // Add pack to Tool Catalog
        add_filter('mcp_pack_tools', [self::class, 'add_to_catalog'], 10, 2);
    }

    /**
     * Register the pack in WordPress
     */
    private static function register_pack(): void {
        $registered_packs = get_option('mcp_registered_packs', []);

        if (!in_array(self::PACK_NAME, $registered_packs)) {
            $registered_packs[] = self::PACK_NAME;
            update_option('mcp_registered_packs', $registered_packs);
        }

        // Store pack metadata
        update_option('mcp_pack_' . self::PACK_NAME . '_info', [
            'name' => self::PACK_NAME,
            'version' => self::VERSION,
            'description' => 'Pack CREW pour l\'assemblage d\'outils',
            'tools' => ['ml_build'],
            'registered_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Register tools with Router_V3
     */
    private static function register_tools(): void {
        if (!class_exists(Router_V3::class)) {
            return;
        }

        Router_V3::register_pack(self::PACK_NAME, [
            'ml_build' => Build::class,
        ]);
    }

    /**
     * Add pack tools to Tool Catalog
     *
     * @param array $tools Existing pack tools
     * @param string $pack Pack name filter
     * @return array Updated tools
     */
    public static function add_to_catalog(array $tools, string $pack): array {
        if ($pack !== '' && $pack !== self::PACK_NAME) {
            return $tools;
        }

        $tools['ml_build'] = Build::get_definition();

        return $tools;
    }

    /**
     * Get pack tools definitions for MCP
     *
     * @return array Tool definitions
     */
    public static function get_tools(): array {
        return [
            Build::get_definition(),
        ];
    }

    /**
     * Check if pack is active
     *
     * @return bool
     */
    public static function is_active(): bool {
        if (!function_exists('get_option')) {
            return true; // Assume active outside WordPress
        }
        $active_packs = get_option('mcp_active_packs', []);
        return in_array(self::PACK_NAME, $active_packs);
    }

    /**
     * Activate the pack
     */
    public static function activate(): void {
        $active_packs = get_option('mcp_active_packs', []);

        if (!in_array(self::PACK_NAME, $active_packs)) {
            $active_packs[] = self::PACK_NAME;
            update_option('mcp_active_packs', $active_packs);
        }

        // Initialize on activation
        self::init();
    }

    /**
     * Deactivate the pack
     */
    public static function deactivate(): void {
        $active_packs = get_option('mcp_active_packs', []);
        $active_packs = array_filter($active_packs, fn($p) => $p !== self::PACK_NAME);
        update_option('mcp_active_packs', array_values($active_packs));
    }

    /**
     * Get pack info
     *
     * @return array Pack information
     */
    public static function get_info(): array {
        return [
            'name' => self::PACK_NAME,
            'version' => self::VERSION,
            'description' => 'Pack CREW pour l\'assemblage dynamique d\'outils à partir de composants (prompts, contenus, styles).',
            'tools' => ['ml_build'],
            'is_active' => self::is_active(),
            'capabilities' => [
                'tool_assembly' => true,
                'ai_rerank' => true,
                'query_expansion' => true,
                'compatibility_scoring' => true,
            ],
        ];
    }
}
