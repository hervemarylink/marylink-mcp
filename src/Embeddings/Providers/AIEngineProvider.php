<?php
/**
 * AI Engine Provider - Get embeddings via AI Engine plugin
 *
 * Uses AI Engine's centralized configuration for Azure/OpenAI.
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Embeddings\Providers;

class AIEngineProvider implements EmbeddingProviderInterface {

    private const DEFAULT_MODEL = 'text-embedding-3-small';
    private const DEFAULT_DIMENSION = 1536;

    /**
     * Get embedding vector for text via AI Engine
     */
    public function embed(string $text): ?array {
        if (!$this->is_available()) {
            return null;
        }

        try {
            $ai = \Meow_MWAI_Core::instance();

            // Use AI Engine's embeddings API
            $query = new \Meow_MWAI_Query_Embed($text);
            $query->set_model($this->get_model());

            $result = $ai->run_query($query);

            if (!empty($result->result) && is_array($result->result)) {
                return $result->result;
            }

            return null;

        } catch (\Exception $e) {
            error_log('[AIEngineProvider] Embedding error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get embedding dimension
     */
    public function get_dimension(): int {
        return self::DEFAULT_DIMENSION;
    }

    /**
     * Get model name
     */
    public function get_model(): string {
        return get_option('ml_embedding_model', self::DEFAULT_MODEL);
    }

    /**
     * Check if AI Engine is available for embeddings
     */
    public function is_available(): bool {
        // Check if AI Engine core class exists
        if (!class_exists('Meow_MWAI_Core')) {
            return false;
        }

        // Check if embeddings query class exists
        if (!class_exists('Meow_MWAI_Query_Embed')) {
            return false;
        }

        return true;
    }
}
