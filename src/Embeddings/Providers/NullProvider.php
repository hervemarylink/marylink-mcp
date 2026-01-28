<?php
/**
 * Null Provider - Fallback when no embedding provider is available
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Embeddings\Providers;

class NullProvider implements EmbeddingProviderInterface {

    public function embed(string $text): ?array {
        return null;
    }

    public function get_dimension(): int {
        return 0;
    }

    public function get_model(): string {
        return 'null';
    }

    public function is_available(): bool {
        return false;
    }
}
