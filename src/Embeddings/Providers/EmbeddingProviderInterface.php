<?php
/**
 * Embedding Provider Interface
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Embeddings\Providers;

interface EmbeddingProviderInterface {

    /**
     * Get embedding vector for text
     *
     * @param string $text Text to embed
     * @return array|null Vector array or null on failure
     */
    public function embed(string $text): ?array;

    /**
     * Get embedding dimension
     *
     * @return int Vector dimension (e.g., 1536 for text-embedding-3-small)
     */
    public function get_dimension(): int;

    /**
     * Get model name
     *
     * @return string Model identifier
     */
    public function get_model(): string;

    /**
     * Check if provider is available and configured
     *
     * @return bool True if ready to use
     */
    public function is_available(): bool;
}
