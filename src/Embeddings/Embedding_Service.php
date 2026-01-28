<?php
/**
 * Embedding Service - Manage publication embeddings for semantic search
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\Embeddings;

use MCP_No_Headless\Embeddings\Providers\EmbeddingProviderInterface;
use MCP_No_Headless\Embeddings\Providers\NullProvider;
use MCP_No_Headless\Embeddings\Providers\AIEngineProvider;

class Embedding_Service {

    private const TABLE_EMBEDDINGS = 'ml_embeddings';
    private const CACHE_TTL = 600; // 10 minutes

    private EmbeddingProviderInterface $provider;
    private static ?Embedding_Service $instance = null;

    public function __construct(?EmbeddingProviderInterface $provider = null) {
        $this->provider = $provider ?? self::get_default_provider();
    }

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if embeddings are enabled
     */
    public static function is_enabled(): bool {
        return (bool) get_option('ml_embeddings_enabled', false);
    }

    /**
     * Get default provider based on available services
     */
    private static function get_default_provider(): EmbeddingProviderInterface {
        // AI Engine = pont vers Azure/OpenAI (config centralisée)
        $aiengine = new AIEngineProvider();
        if ($aiengine->is_available()) {
            return $aiengine;
        }

        // Fallback: No embeddings (graceful degradation)
        return new NullProvider();
    }

    /**
     * Get provider status
     */
    public function get_status(): array {
        return [
            'enabled' => self::is_enabled(),
            'provider' => $this->provider->get_model(),
            'available' => $this->provider->is_available(),
            'dimension' => $this->provider->get_dimension(),
        ];
    }

    /**
     * Build text to embed for a publication
     */
    public function build_text_for_publication(int $publication_id): ?string {
        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            return null;
        }

        $parts = [];

        // Title (important)
        $parts[] = $post->post_title;

        // Content summary
        $content = wp_strip_all_tags($post->post_content);
        if (mb_strlen($content) > 500) {
            $content = mb_substr($content, 0, 500) . '...';
        }
        if ($content) {
            $parts[] = $content;
        }

        // Instruction (for tools)
        $instruction = get_post_meta($publication_id, '_ml_instruction', true);
        if ($instruction) {
            $instr_text = wp_strip_all_tags($instruction);
            if (mb_strlen($instr_text) > 300) {
                $instr_text = mb_substr($instr_text, 0, 300) . '...';
            }
            $parts[] = $instr_text;
        }

        // Tags
        $tags = wp_get_post_terms($publication_id, 'publication_tag', ['fields' => 'names']);
        if (!empty($tags) && !is_wp_error($tags)) {
            $parts[] = implode(', ', $tags);
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Get or create embedding for a publication
     */
    public function get_embedding(int $publication_id): ?array {
        // Check cache first
        $cached = $this->get_cached_embedding($publication_id);
        if ($cached !== null) {
            return $cached;
        }

        // Build text and generate embedding
        $text = $this->build_text_for_publication($publication_id);
        if (!$text) {
            return null;
        }

        $embedding = $this->provider->embed($text);
        if ($embedding) {
            $this->store_embedding($publication_id, $embedding);
        }

        return $embedding;
    }

    /**
     * Get cached embedding from database
     */
    private function get_cached_embedding(int $publication_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_EMBEDDINGS;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT embedding FROM $table WHERE publication_id = %d",
            $publication_id
        ));

        if ($row && $row->embedding) {
            return json_decode($row->embedding, true);
        }

        return null;
    }

    /**
     * Store embedding in database
     */
    private function store_embedding(int $publication_id, array $embedding): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_EMBEDDINGS;

        $data = [
            'publication_id' => $publication_id,
            'embedding' => wp_json_encode($embedding),
            'model' => $this->provider->get_model(),
            'dimension' => count($embedding),
            'updated_at' => current_time('mysql', true),
        ];

        // Upsert
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE publication_id = %d",
            $publication_id
        ));

        if ($existing) {
            return $wpdb->update($table, $data, ['publication_id' => $publication_id]) !== false;
        }

        $data['created_at'] = current_time('mysql', true);
        return $wpdb->insert($table, $data) !== false;
    }

    /**
     * Calculate cosine similarity between two vectors
     */
    public static function cosine_similarity(array $a, array $b): float {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }

        $dot = 0.0;
        $norm_a = 0.0;
        $norm_b = 0.0;

        for ($i = 0, $len = count($a); $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $norm_a += $a[$i] * $a[$i];
            $norm_b += $b[$i] * $b[$i];
        }

        $norm_a = sqrt($norm_a);
        $norm_b = sqrt($norm_b);

        if ($norm_a == 0 || $norm_b == 0) {
            return 0.0;
        }

        return $dot / ($norm_a * $norm_b);
    }

    /**
     * Find similar publications by embedding
     */
    public function find_similar(int $publication_id, int $limit = 10): array {
        $source_embedding = $this->get_embedding($publication_id);
        if (!$source_embedding) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_EMBEDDINGS;

        // Get all embeddings (TODO: optimize with vector DB for large scale)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT publication_id, embedding FROM $table WHERE publication_id != %d",
            $publication_id
        ));

        $similarities = [];
        foreach ($rows as $row) {
            $other_embedding = json_decode($row->embedding, true);
            if ($other_embedding) {
                $similarity = self::cosine_similarity($source_embedding, $other_embedding);
                $similarities[$row->publication_id] = $similarity;
            }
        }

        arsort($similarities);
        return array_slice($similarities, 0, $limit, true);
    }

    /**
     * Create the embeddings table
     */
    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_EMBEDDINGS;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            publication_id BIGINT UNSIGNED NOT NULL,
            embedding LONGTEXT NOT NULL,
            model VARCHAR(100) NOT NULL,
            dimension INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_publication (publication_id),
            KEY idx_model (model)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Check if table exists
     */
    public static function table_exists(): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_EMBEDDINGS;
        return $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    }
}
