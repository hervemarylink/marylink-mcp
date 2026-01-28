<?php
/**
 * Metrics Collector - Centralise la collecte et le stockage des métriques
 *
 * Écoute les events ml_metrics et les stocke pour analyse.
 * Supporte multiple backends : table custom, post meta, ou API externe.
 *
 * @package MCP_No_Headless
 * @since 2.6.0
 */

namespace MCP_No_Headless\Services;

class Metrics_Collector {

    /**
     * Nom de la table custom pour les métriques
     */
    private const TABLE_NAME = 'ml_metrics';

    /**
     * Durée de rétention des métriques (jours)
     */
    private const RETENTION_DAYS = 90;

    /**
     * Initialiser le collector
     */
    public static function init(): void {
        // Écouter tous les events ml_metrics
        add_action('ml_metrics', [self::class, 'collect'], 10, 2);

        // Nettoyage périodique (via wp_cron)
        add_action('ml_metrics_cleanup', [self::class, 'cleanup']);

        // Programmer le cleanup si pas déjà fait
        if (!wp_next_scheduled('ml_metrics_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ml_metrics_cleanup');
        }
    }

    /**
     * Collecter une métrique
     *
     * @param string $event_type Type d'event (bootstrap_analyze, url_resolve, etc.)
     * @param array $data Données de la métrique
     */
    public static function collect(string $event_type, array $data): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // Vérifier que la table existe
        if (!self::table_exists()) {
            self::create_table();
        }

        // Préparer les données
        $row = [
            'event_type' => $event_type,
            'run_id' => $data['run_id'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'user_id' => $data['user_id'] ?? get_current_user_id(),
            'space_id' => $data['space_id'] ?? null,
            'data' => wp_json_encode($data),
            'created_at' => current_time('mysql'),
        ];

        // Insérer
        $wpdb->insert($table, $row);

        // Aussi déclencher un hook pour intégration externe (DataDog, Grafana, etc.)
        do_action('ml_metrics_collected', $event_type, $data);
    }

    /**
     * Créer la table des métriques
     */
    public static function create_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(50) NOT NULL,
            run_id VARCHAR(50) NULL,
            session_id VARCHAR(50) NULL,
            user_id BIGINT UNSIGNED NULL,
            space_id BIGINT UNSIGNED NULL,
            data LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_event_type (event_type),
            INDEX idx_run_id (run_id),
            INDEX idx_created_at (created_at),
            INDEX idx_space_id (space_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Vérifier si la table existe
     */
    private static function table_exists(): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        return $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
    }

    /**
     * Nettoyer les anciennes métriques
     */
    public static function cleanup(): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::RETENTION_DAYS . ' days'));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff
        ));
    }

    // =========================================================================
    // QUERIES - Pour le dashboard
    // =========================================================================

    /**
     * Obtenir les métriques agrégées pour une période
     *
     * @param string $period '7d', '30d', '90d'
     * @param int|null $space_id Filtrer par espace
     * @return array
     */
    public static function get_dashboard_metrics(string $period = '30d', ?int $space_id = null): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $days = (int) filter_var($period, FILTER_SANITIZE_NUMBER_INT);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $space_filter = $space_id ? $wpdb->prepare("AND space_id = %d", $space_id) : "";

        // 1. Coverage rate (moyenne)
        $coverage = $wpdb->get_var("
            SELECT AVG(JSON_EXTRACT(data, '$.coverage_rate'))
            FROM {$table}
            WHERE event_type = 'bootstrap_select'
            AND created_at >= '{$cutoff}'
            {$space_filter}
        ");

        // 2. Placeholder rate (moyenne)
        $placeholder = $wpdb->get_var("
            SELECT AVG(JSON_EXTRACT(data, '$.placeholder_rate'))
            FROM {$table}
            WHERE event_type = 'bootstrap_select'
            AND created_at >= '{$cutoff}'
            {$space_filter}
        ");

        // 3. Replacement rate (overrides / total selections)
        $overrides = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$table}
            WHERE event_type = 'bootstrap_override'
            AND JSON_EXTRACT(data, '$.is_replacement') = true
            AND created_at >= '{$cutoff}'
            {$space_filter}
        ");

        $total_selects = $wpdb->get_var("
            SELECT SUM(JSON_EXTRACT(data, '$.required_count'))
            FROM {$table}
            WHERE event_type = 'bootstrap_select'
            AND created_at >= '{$cutoff}'
            {$space_filter}
        ");

        $replacement_rate = $total_selects > 0 ? $overrides / $total_selects : 0;

        // 4. URL resolve success rate
        $url_stats = $wpdb->get_row("
            SELECT 
                SUM(JSON_EXTRACT(data, '$.url_count')) as total_urls,
                SUM(JSON_EXTRACT(data, '$.success_count')) as success_urls,
                AVG(JSON_EXTRACT(data, '$.latency_ms')) as avg_latency,
                MAX(JSON_EXTRACT(data, '$.latency_ms')) as max_latency
            FROM {$table}
            WHERE event_type = 'url_resolve'
            AND created_at >= '{$cutoff}'
            {$space_filter}
        ");

        $fetch_success_rate = $url_stats->total_urls > 0 
            ? $url_stats->success_urls / $url_stats->total_urls 
            : 0;

        // 5. Injected tokens (moyenne)
        $avg_tokens = $wpdb->get_var("
            SELECT AVG(JSON_EXTRACT(data, '$.injected_tokens'))
            FROM {$table}
            WHERE event_type = 'url_resolve'
            AND created_at >= '{$cutoff}'
            {$space_filter}
        ");

        // 6. Truncation rate
        $truncated = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$table}
            WHERE event_type = 'url_resolve'
            AND JSON_EXTRACT(data, '$.truncated') = true
            AND created_at >= '{$cutoff}'
            {$space_filter}
        ");

        $total_resolves = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$table}
            WHERE event_type = 'url_resolve'
            AND created_at >= '{$cutoff}'
            {$space_filter}
        ");

        $truncation_rate = $total_resolves > 0 ? $truncated / $total_resolves : 0;

        // 7. Tools created
        $tools_created = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$table}
            WHERE event_type = 'tool_created'
            AND created_at >= '{$cutoff}'
            {$space_filter}
        ");

        // 8. Wizards completed
        $wizards_completed = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$table}
            WHERE event_type = 'bootstrap_complete'
            AND created_at >= '{$cutoff}'
            {$space_filter}
        ");

        return [
            'period' => $period,
            'space_id' => $space_id,
            'metrics' => [
                'coverage_rate' => round((float) $coverage * 100, 1),
                'placeholder_rate' => round((float) $placeholder * 100, 1),
                'replacement_rate' => round($replacement_rate * 100, 1),
                'fetch_success_rate' => round($fetch_success_rate * 100, 1),
                'p50_resolve_latency' => round((float) $url_stats->avg_latency, 0),
                'p95_resolve_latency' => round((float) $url_stats->max_latency, 0), // Approximation
                'avg_injected_tokens' => round((float) $avg_tokens, 0),
                'truncation_rate' => round($truncation_rate * 100, 1),
                'tools_created' => (int) $tools_created,
                'wizards_completed' => (int) $wizards_completed,
            ],
            'thresholds' => [
                'coverage_rate' => ['target' => 70, 'alert' => 50],
                'placeholder_rate' => ['target' => 30, 'alert' => 50],
                'replacement_rate' => ['target' => 25, 'alert' => 40],
                'fetch_success_rate' => ['target' => 95, 'alert' => 90],
                'p95_resolve_latency' => ['target' => 500, 'alert' => 1000],
                'avg_injected_tokens' => ['target' => 15000, 'alert' => 25000],
            ],
        ];
    }

    /**
     * Obtenir les métriques par jour (pour graphiques)
     */
    public static function get_daily_metrics(string $period = '30d', ?int $space_id = null): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $days = (int) filter_var($period, FILTER_SANITIZE_NUMBER_INT);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $space_filter = $space_id ? $wpdb->prepare("AND space_id = %d", $space_id) : "";

        $results = $wpdb->get_results("
            SELECT 
                DATE(created_at) as day,
                COUNT(CASE WHEN event_type = 'bootstrap_complete' THEN 1 END) as wizards,
                COUNT(CASE WHEN event_type = 'tool_created' THEN 1 END) as tools,
                AVG(CASE WHEN event_type = 'bootstrap_select' 
                    THEN JSON_EXTRACT(data, '$.coverage_rate') END) as coverage
            FROM {$table}
            WHERE created_at >= '{$cutoff}'
            {$space_filter}
            GROUP BY DATE(created_at)
            ORDER BY day ASC
        ");

        return array_map(fn($row) => [
            'day' => $row->day,
            'wizards' => (int) $row->wizards,
            'tools' => (int) $row->tools,
            'coverage' => round((float) $row->coverage * 100, 1),
        ], $results);
    }

    /**
     * Exporter les métriques brutes (pour debug/audit)
     */
    public static function export_raw(string $event_type, string $period = '7d', int $limit = 1000): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $days = (int) filter_var($period, FILTER_SANITIZE_NUMBER_INT);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$table}
            WHERE event_type = %s
            AND created_at >= %s
            ORDER BY created_at DESC
            LIMIT %d
        ", $event_type, $cutoff, $limit));

        return array_map(fn($row) => [
            'id' => $row->id,
            'run_id' => $row->run_id,
            'user_id' => $row->user_id,
            'space_id' => $row->space_id,
            'data' => json_decode($row->data, true),
            'created_at' => $row->created_at,
        ], $results);
    }
}
