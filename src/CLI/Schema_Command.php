<?php
namespace MCP_No_Headless\CLI;

use MCP_No_Headless\Schema\Publication_Schema;
use WP_CLI;

class Schema_Command {
    public function __invoke($args, $assoc_args) {
        if (empty($args[0])) {
            $current = Publication_Schema::get_mode();
            WP_CLI::log("Current schema mode: $current\n");
            WP_CLI::log("Available modes:");
            WP_CLI::log("  legacy - Read/write _ml_* only");
            WP_CLI::log("  dual   - Read both, write both (default)");
            WP_CLI::log("  strict - Read Picasso priority, write Picasso only\n");
            WP_CLI::log("Usage: wp marylink schema <mode>");
            return;
        }

        $new_mode = $args[0];
        if (Publication_Schema::set_mode($new_mode)) {
            WP_CLI::success("Schema mode set to: $new_mode");
        } else {
            WP_CLI::error("Invalid mode. Use: legacy, dual, or strict");
        }
    }
}
