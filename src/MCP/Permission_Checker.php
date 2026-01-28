<?php
/**
 * Permission Checker v3 - Validates user permissions for MCP tools using Picasso Backend
 *
 * CORRECTIONS v3:
 * - Hooks d'invalidation cache branchés automatiquement
 * - can_see_publication() avec fail-fast complet (co-auteur, équipe, expert, invité, groupe, public)
 * - Meta keys corrigées (singulier)
 * - Méthodes statiques pour invalidation globale
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\MCP;

use Picasso_Backend\Permission\User as PB_Permission;
use Picasso_Backend\Utils\Publication as Publication_Utils;
use Picasso_Backend\Utils\Space as Space_Utils;
use MCP_No_Headless\User\Mission_Token_Manager;

class Permission_Checker {

    /**
     * Picasso permission instance
     */
    private ?PB_Permission $pb = null;

    /**
     * Publication utilities
     */
    private ?Publication_Utils $pub_utils = null;

    /**
     * Space utilities
     */
    private ?Space_Utils $space_utils = null;

    /**
     * Current user ID
     */
    private int $user_id;

    /**
     * Cache group
     */
    public const CACHE_GROUP = 'marylink_mcp_permissions';

    /**
     * Meta keys qui déclenchent une invalidation de cache
     */
    private static array $permission_meta_keys = [
        '_space_moderators',
        '_space_champions',
        '_space_experts',
        '_space_see_permissions',
        '_space_publish_and_edit_permissions',
        '_publication_co_author',
        '_in_publication_team',
        '_publication_expert',
        '_user_invited_to_team',
        '_publication_group',
    ];

    /**
     * Hooks initialized flag
     */
    private static bool $hooks_initialized = false;

    /**
     * Constructor
     */
    public function __construct(int $user_id) {
        $this->user_id = $user_id;
        $this->init_picasso();
        self::init_hooks();
    }

    /**
     * Initialize Picasso Backend classes
     */
    private function init_picasso(): void {
        // Guard: Picasso User::__construct calls get_user_by() which returns false for invalid IDs
        // PHP 8.x strict typing doesn't allow false on ?WP_User property
        if (class_exists('Picasso_Backend\Permission\User') && $this->user_id > 0 && get_user_by('id', $this->user_id)) {
            $this->pb = new PB_Permission($this->user_id);
        }
        if (class_exists('Picasso_Backend\\Utils\\Publication')) {
            $this->pub_utils = new Publication_Utils();
        }

        if (class_exists('Picasso_Backend\\Utils\\Space')) {
            $this->space_utils = new Space_Utils();
        }
    }

    // ========================================
    // HOOKS D'INVALIDATION CACHE
    // ========================================

    /**
     * Initialise les hooks pour l'invalidation automatique du cache
     */
    public static function init_hooks(): void {
        if (self::$hooks_initialized) {
            return;
        }

        // Hooks sur les meta de permissions
        add_action('updated_post_meta', [__CLASS__, 'on_meta_change'], 10, 4);
        add_action('added_post_meta', [__CLASS__, 'on_meta_change'], 10, 4);
        add_action('deleted_post_meta', [__CLASS__, 'on_meta_delete'], 10, 4);

        // Hook sur la sauvegarde d'un espace
        add_action('save_post_space', [__CLASS__, 'on_space_saved'], 10, 3);

        // Hook sur la modification de la table pb_see_permissions (hook custom Picasso)
        add_action('pb_see_permissions_updated', [__CLASS__, 'on_see_permissions_table_updated'], 10, 1);

        self::$hooks_initialized = true;
    }

    /**
     * Callback quand un meta est modifié ou ajouté
     */
    public static function on_meta_change($meta_id, $post_id, $meta_key, $meta_value): void {
        if (!in_array($meta_key, self::$permission_meta_keys, true)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post) return;

        if ($post->post_type === 'space') {
            self::invalidate_all_cache_for_space($post_id, $meta_key, $meta_value);
        } elseif ($post->post_type === 'publication') {
            self::invalidate_cache_for_publication_meta($post_id, $meta_key, $meta_value);
        }
    }

    /**
     * Callback quand un meta est supprimé
     */
    public static function on_meta_delete($meta_ids, $post_id, $meta_key, $meta_value): void {
        self::on_meta_change(0, $post_id, $meta_key, $meta_value);
    }

    /**
     * Callback quand un espace est sauvegardé
     */
    public static function on_space_saved($post_id, $post, $update): void {
        if (wp_is_post_revision($post_id)) return;
        self::invalidate_all_cache_for_space($post_id);
    }

    /**
     * Callback quand la table pb_see_permissions est modifiée
     */
    public static function on_see_permissions_table_updated($space_id): void {
        self::invalidate_all_cache_for_space($space_id);
    }

    /**
     * Invalide le cache de tous les users concernés par un espace
     */
    private static function invalidate_all_cache_for_space($space_id, $meta_key = null, $meta_value = null): void {
        // Si on a les IDs des users directement
        if ($meta_key && in_array($meta_key, ['_space_moderators', '_space_champions', '_space_experts'])) {
            $user_ids = is_array($meta_value) ? $meta_value : [];
            $old_users = get_post_meta($space_id, $meta_key, true);
            if (is_array($old_users)) {
                $user_ids = array_merge($user_ids, $old_users);
            }
            foreach (array_unique($user_ids) as $user_id) {
                self::invalidate_user_cache((int)$user_id);
            }
        }

        // Flush tout le groupe (plus radical mais garantit la cohérence)
        wp_cache_flush_group(self::CACHE_GROUP);
    }

    /**
     * Invalide le cache pour les users liés à une publication
     */
    private static function invalidate_cache_for_publication_meta($post_id, $meta_key, $meta_value): void {
        $user_ids = [];

        if (in_array($meta_key, ['_publication_co_author', '_in_publication_team', '_publication_expert', '_user_invited_to_team'])) {
            if (is_array($meta_value)) {
                $user_ids = $meta_value;
            } elseif (is_numeric($meta_value)) {
                $user_ids = [(int)$meta_value];
            }
        }

        foreach ($user_ids as $user_id) {
            self::invalidate_user_cache((int)$user_id);
        }
    }

    /**
     * Invalide le cache d'un user spécifique
     */
    public static function invalidate_user_cache(int $user_id): void {
        if ($user_id <= 0) return;
        wp_cache_delete('user_spaces_' . $user_id, self::CACHE_GROUP);
        wp_cache_delete('user_pub_spaces_' . $user_id, self::CACHE_GROUP);
    }

    /**
     * Invalide le cache de l'utilisateur courant (instance)
     */
    public function invalidate_cache(): void {
        self::invalidate_user_cache($this->user_id);
    }

    // ========================================
    // MÉTHODES - Espaces accessibles
    // ========================================

    /**
     * Récupère les espaces VISIBLES pour l'utilisateur (permission 'space')
     */
    public function get_user_spaces(): array {
        $cache_key = 'user_spaces_' . $this->user_id;
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        if ($this->is_admin()) {
            $spaces = $this->get_all_space_ids();
            wp_cache_set($cache_key, $spaces, self::CACHE_GROUP, 300);
            return $spaces;
        }

        $spaces = [];
        $spaces = array_merge($spaces, $this->get_spaces_by_role('_space_moderators'));
        $spaces = array_merge($spaces, $this->get_spaces_by_role('_space_champions'));
        $spaces = array_merge($spaces, $this->get_spaces_by_role('_space_experts'));
        $spaces = array_merge($spaces, $this->get_spaces_from_permissions_table('space'));
        $spaces = array_merge($spaces, $this->get_spaces_where_author());
        $spaces = array_merge($spaces, $this->get_spaces_where_team_member());

        $spaces = array_values(array_unique(array_map('intval', array_filter($spaces))));

        // Mission token restriction (B2B2B)
        $allowed_spaces = Mission_Token_Manager::get_allowed_space_ids();
        if ($allowed_spaces !== null) {
            $spaces = array_values(array_intersect($spaces, $allowed_spaces));
        }

        wp_cache_set($cache_key, $spaces, self::CACHE_GROUP, 300);
        return $spaces;
    }

    /**
     * Récupère les espaces où l'user peut voir les PUBLICATIONS
     */
    public function get_user_publication_spaces(): array {
        $cache_key = 'user_pub_spaces_' . $this->user_id;
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        if ($this->is_admin()) {
            $spaces = $this->get_all_space_ids();
            wp_cache_set($cache_key, $spaces, self::CACHE_GROUP, 300);
            return $spaces;
        }

        $spaces = [];
        $spaces = array_merge($spaces, $this->get_spaces_by_role('_space_moderators'));
        $spaces = array_merge($spaces, $this->get_spaces_by_role('_space_champions'));
        $spaces = array_merge($spaces, $this->get_spaces_by_role('_space_experts'));
        $spaces = array_merge($spaces, $this->get_spaces_from_permissions_table('publications'));
        $spaces = array_merge($spaces, $this->get_spaces_where_author());
        $spaces = array_merge($spaces, $this->get_spaces_where_team_member());

        $spaces = array_values(array_unique(array_map('intval', array_filter($spaces))));

        // Mission token restriction (B2B2B)
        $allowed_spaces = Mission_Token_Manager::get_allowed_space_ids();
        if ($allowed_spaces !== null) {
            $spaces = array_values(array_intersect($spaces, $allowed_spaces));
        }

        wp_cache_set($cache_key, $spaces, self::CACHE_GROUP, 300);
        return $spaces;
    }

    /**
     * Vérifie si l'user est admin
     */
    public function is_admin(): bool {
        $user = get_userdata($this->user_id);
        if (!$user) return false;

        $roles = (array) $user->roles;
        return in_array('administrator', $roles, true) || in_array('admin', $roles, true);
    }

    /**
     * Récupère tous les IDs de spaces (pour admin)
     */
    private function get_all_space_ids(): array {
        return get_posts([
            'post_type' => 'space',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]) ?: [];
    }

    /**
     * Récupère les espaces par rôle (moderator, champion, expert)
     */
    private function get_spaces_by_role(string $meta_key): array {
        global $wpdb;

        $results = $wpdb->get_col($wpdb->prepare("
            SELECT pm.post_id
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s
            AND p.post_type = 'space'
            AND p.post_status = 'publish'
        ", $meta_key));

        $matched = [];
        foreach ($results as $space_id) {
            $users = get_post_meta($space_id, $meta_key, true);
            if (is_array($users) && in_array($this->user_id, array_map('intval', $users), true)) {
                $matched[] = (int) $space_id;
            }
        }

        return $matched;
    }

    /**
     * Récupère les espaces depuis la table de permissions Picasso
     */
    private function get_spaces_from_permissions_table(string $permission_type = 'space'): array {
        global $wpdb;

        $user = get_userdata($this->user_id);
        if (!$user) return [];

        $roles = (array) $user->roles;
        $roles = array_map(fn($role) => $role === 'administrator' ? 'admin' : $role, $roles);

        $groups = [];
        if (function_exists('groups_get_user_groups')) {
            $user_groups = groups_get_user_groups($this->user_id);
            if (!empty($user_groups['groups'])) {
                $groups = array_map('intval', $user_groups['groups']);
            }
        }

        $table = $wpdb->prefix . 'pb_see_permissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return [];
        }

        $conditions = [];
        $params = [$permission_type];

        $conditions[] = "(agent_type = 'users' AND agent = %s)";
        $params[] = (string) $this->user_id;

        if (!empty($roles)) {
            $role_placeholders = implode(',', array_fill(0, count($roles), '%s'));
            $conditions[] = "(agent_type = 'wp_role' AND agent IN ($role_placeholders))";
            $params = array_merge($params, $roles);
        }

        if (!empty($groups)) {
            $group_placeholders = implode(',', array_fill(0, count($groups), '%s'));
            $conditions[] = "(agent_type = 'groups' AND agent IN ($group_placeholders))";
            $params = array_merge($params, array_map('strval', $groups));
        }

        $where_conditions = implode(' OR ', $conditions);

        $sql = $wpdb->prepare("
            SELECT DISTINCT space_id
            FROM $table
            WHERE permission_to = %s
            AND ($where_conditions)
        ", $params);

        return array_map('intval', $wpdb->get_col($sql) ?: []);
    }

    /**
     * Récupère les espaces où l'user est auteur
     */
    private function get_spaces_where_author(): array {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT post_parent
            FROM {$wpdb->posts}
            WHERE post_type = 'publication'
            AND post_author = %d
            AND post_parent > 0
            AND post_status IN ('publish', 'draft', 'pending')
        ", $this->user_id)) ?: []);
    }

    /**
     * Récupère les espaces où l'user est membre d'une équipe publication
     */
    private function get_spaces_where_team_member(): array {
        global $wpdb;

        // Co-auteur, équipe, expert, invité
        $meta_keys = ['_publication_co_author', '_in_publication_team', '_publication_expert', '_user_invited_to_team'];
        $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

        $params = array_merge($meta_keys, [(string)$this->user_id]);

        $results = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.post_parent
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type = 'publication'
            AND p.post_parent > 0
            AND pm.meta_key IN ($placeholders)
            AND pm.meta_value = %s
        ", $params));

        return array_map('intval', $results ?: []);
    }

    // ========================================
    // VÉRIFICATION ACCÈS PUBLICATION (FAIL-FAST COMPLET)
    // ========================================

    /**
     * Vérifie si l'user peut voir une publication
     * Ordre fail-fast conforme au guide Picasso
     */
    public function can_see_publication(int $publication_id): bool {
        if ($publication_id <= 0) {
            return false;
        }

        $post = get_post($publication_id);
        if (!$post || $post->post_type !== 'publication') {
            return false;
        }

        // Mission token restriction (B2B2B) - check via space
        $space_id = (int) $post->post_parent;
        if ($space_id > 0 && !Mission_Token_Manager::is_space_allowed($space_id)) {
            return false;
        }

        // 1. Admin global
        if ($this->is_admin()) {
            return true;
        }

        // 2. Auteur de la publication
        if ((int) $post->post_author === $this->user_id) {
            return true;
        }

        // 3. Co-auteur (meta singulier)
        $co_author = get_post_meta($publication_id, '_publication_co_author', true);
        if (!empty($co_author) && (int)$co_author === $this->user_id) {
            return true;
        }

        // 4. Membre de l'équipe
        if ($this->is_in_publication_meta($publication_id, '_in_publication_team')) {
            return true;
        }

        // 5. Expert de la publication
        if ($this->is_in_publication_meta($publication_id, '_publication_expert')) {
            return true;
        }

        // 6. Invité dans l'équipe
        if ($this->is_in_publication_meta($publication_id, '_user_invited_to_team')) {
            return true;
        }

        // 7. Membre d'un groupe BuddyBoss associé
        if ($this->is_in_publication_group($publication_id)) {
            return true;
        }

        // 8. Publication publique
        $is_public = get_post_meta($publication_id, '_is_public', true);
        if ((string)$is_public === '1' || $is_public === true) {
            return true;
        }

        // 9-12. Vérifier via espace
        $space_id = (int) $post->post_parent;
        if ($space_id <= 0) {
            return false; // fail-closed
        }

        // 9. Moderator de l'espace
        if ($this->is_in_space_role($space_id, '_space_moderators')) {
            return true;
        }

        // 10. Champion de l'espace
        if ($this->is_in_space_role($space_id, '_space_champions')) {
            return true;
        }

        // 11. Expert de l'espace
        if ($this->is_in_space_role($space_id, '_space_experts')) {
            return true;
        }

        // 12. Permission 'publications' via table pb_see_permissions
        $user_pub_spaces = $this->get_user_publication_spaces();
        if (in_array($space_id, $user_pub_spaces, true)) {
            return true;
        }

        // 13. Utiliser Picasso Backend si disponible (dernier recours)
        if ($this->pb && method_exists($this->pb, 'can_see_publication')) {
            return $this->pb->can_see_publication($publication_id);
        }

        return false; // fail-closed
    }

    /**
     * Vérifie si l'user est dans un meta multiple d'une publication
     */
    private function is_in_publication_meta(int $publication_id, string $meta_key): bool {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->postmeta}
            WHERE post_id = %d
            AND meta_key = %s
            AND meta_value = %s
        ", $publication_id, $meta_key, (string)$this->user_id));
        return (int)$result > 0;
    }

    /**
     * Vérifie si l'user est dans un groupe associé à la publication
     */
    private function is_in_publication_group(int $publication_id): bool {
        $publication_group = get_post_meta($publication_id, '_publication_group', true);
        if (empty($publication_group)) return false;

        if (!function_exists('groups_get_user_groups')) return false;

        $user_groups = groups_get_user_groups($this->user_id);
        if (empty($user_groups['groups'])) return false;

        return in_array((int)$publication_group, array_map('intval', $user_groups['groups']), true);
    }

    /**
     * Vérifie si l'user a un rôle dans un espace
     */
    private function is_in_space_role(int $space_id, string $meta_key): bool {
        $users = get_post_meta($space_id, $meta_key, true);
        if (!is_array($users)) return false;
        return in_array($this->user_id, array_map('intval', $users), true);
    }

    /**
     * Vérifie si l'user peut voir un espace
     */
    public function can_see_space(int $space_id): bool {
        if ($space_id <= 0) {
            return false;
        }

        // Mission token restriction (B2B2B)
        if (!Mission_Token_Manager::is_space_allowed($space_id)) {
            return false;
        }

        $post = get_post($space_id);
        if (!$post || $post->post_type !== 'space') {
            return false;
        }

        if ($this->is_admin()) {
            return true;
        }

        $user_spaces = $this->get_user_spaces();
        return in_array($space_id, $user_spaces, true);
    }

    // ========================================
    // CAN_EXECUTE - Point d'entrée principal
    // ========================================

    /**
     * Check if user can execute a specific MCP tool
     */
    public function can_execute(string $tool, array $args = []): bool {
        if ($this->user_id <= 0) {
            return false;
        }

        if (!$this->pb) {
            return $this->fallback_check($tool, $args);
        }

        switch ($tool) {
            case 'ml_list_publications':
            case 'ml_list_spaces':
            case 'ml_get_my_context':
                return true;

            case 'ml_get_publication':
                return $this->can_see_publication($args['publication_id'] ?? 0);

            case 'ml_get_space':
                return $this->can_see_space($args['space_id'] ?? 0);

            case 'ml_create_publication':
            case 'ml_create_publication_from_text':
            case 'ml_publication_create':
                return $this->can_create_publication($args['space_id'] ?? 0);

            case 'ml_edit_publication':
            case 'ml_append_to_publication':
            case 'ml_publication_update':
                return $this->can_edit_publication($args['publication_id'] ?? 0);

            case 'ml_publication_delete':
            case 'ml_delete_publication':
                return $this->can_delete_publication($args['publication_id'] ?? 0);

            case 'ml_add_comment':
            case 'ml_import_as_comment':
                return $this->can_add_comment($args['publication_id'] ?? 0, $args['type'] ?? 'public');

            case 'ml_create_review':
                return $this->can_create_review($args['publication_id'] ?? 0, $args['type'] ?? 'user');

            case 'ml_edit_review':
                return $this->can_edit_review($args['publication_id'] ?? 0);

            case 'ml_move_to_step':
                return $this->can_move_step($args['publication_id'] ?? 0);

            case 'ml_manage_team':
                return $this->can_manage_team($args['publication_id'] ?? 0);

            case 'ml_add_expert':
                return $this->can_manage_experts($args['publication_id'] ?? 0);

            case 'ml_apply_tool':
                // Apply tool handles its own fine-grained permissions internally
                return $this->user_id > 0;

            // New tool-map tools (Epic 1-7)
            case 'ml_spaces_list':
            case 'ml_space_get':
            case 'ml_space_steps_list':
            case 'ml_space_permissions_summary':
                if (isset($args['space_id'])) {
                    return $this->can_see_space($args['space_id']);
                }
                return true;

            case 'ml_publications_list':
            case 'ml_publication_get':
            case 'ml_publication_dependencies':
                if (isset($args['publication_id'])) {
                    return $this->can_see_publication($args['publication_id']);
                }
                return true;

            case 'ml_tool_resolve':
            case 'ml_tool_validate':
                return $this->can_use_tool($args['tool_id'] ?? 0);

            case 'ml_favorites_list':
                return $this->user_id > 0;

            case 'ml_favorites_set':
                return $this->can_see_publication($args['publication_id'] ?? 0);

            case 'ml_comments_list':
                return $this->can_see_publication($args['publication_id'] ?? 0);

            case 'ml_comment_add':
                return $this->can_add_comment(
                    $args['publication_id'] ?? 0,
                    $args['visibility'] ?? 'public'
                );

            case 'ml_best_list':
                return true;

            case 'ml_ratings_get':
                return $this->can_view_ratings($args['publication_id'] ?? 0);

            // Subscription tools (T1.4)
            case 'ml_subscribe_space':
                return $this->can_see_space($args['space_id'] ?? 0);

            case 'ml_get_subscriptions':
                return $this->user_id > 0;

            // Chain resolution (T2.1)
            case 'ml_get_publication_chain':
                return $this->can_see_publication($args['publication_id'] ?? 0);

            // Duplication (T2.2)
            case 'ml_duplicate_publication':
                $pub_id = $args['publication_id'] ?? 0;
                if (!$this->can_see_publication($pub_id)) {
                    return false;
                }
                // For commit stage, also check target space
                if (($args['stage'] ?? 'prepare') === 'commit') {
                    $target_space = $args['target_space_id'] ?? null;
                    if ($target_space && !$this->can_publish_in_space((int) $target_space)) {
                        return false;
                    }
                }
                return true;

            // Bulk operations (T3.1)
            case 'ml_bulk_apply_tool':
                // Check tool access
                $tool_id = $args['tool_id'] ?? 0;
                if (!$this->can_use_tool($tool_id)) {
                    return false;
                }
                return $this->user_id > 0;

            // Comparison (T3.2)
            case 'ml_compare_publications':
                // Just need to be logged in, individual access checked in tool
                return $this->user_id > 0;

            // Team management (T4.1)
            case 'ml_get_team':
                return $this->can_see_publication($args['publication_id'] ?? 0);

            case 'ml_manage_team':
                // Team management permission checked internally
                return $this->can_see_publication($args['publication_id'] ?? 0);

            // Export bundle (T4.2)
            case 'ml_export_bundle':
                return $this->can_see_publication($args['publication_id'] ?? 0);

            // Auto-Improve Intelligence Layer (T5)
            case 'ml_auto_improve':
            case 'ml_prompt_health_check':
                return $this->can_see_publication($args['publication_id'] ?? 0);

            case 'ml_auto_improve_batch':
                // Need access to space
                return $this->can_see_space($args['space_id'] ?? 0);

            // BuddyBoss tools - permissions checked internally by services
            case 'ml_groups_search':
            case 'ml_group_fetch':
            case 'ml_group_members':
            case 'ml_activity_list':
            case 'ml_activity_fetch':
            case 'ml_activity_comments':
            case 'ml_members_search':
            case 'ml_member_fetch':
                return $this->user_id > 0;

            // Dependency management tools
            case 'ml_get_dependencies':
                return $this->can_see_publication($args['publication_id'] ?? 0);

            case 'ml_add_dependency':
            case 'ml_remove_dependency':
            case 'ml_set_dependencies':
                return $this->can_edit_publication($args['publication_id'] ?? 0);

            // Tool_Catalog v2.2.0 tools - read-only, user must be logged in
            case 'ml_help':
            case 'ml_assist_prepare':
            case 'ml_recommend':
            case 'ml_compare':
            case 'ml_export':
            case 'ml_search_advanced':
            case 'ml_feedback':
            case 'ml_rate':
            case 'ml_favorite_toggle':
                return $this->user_id > 0;

            default:
                return false;
        }
    }

    /**
     * Get list of tools available for the current user
     */
    public function get_available_tools(): array {
        if ($this->user_id > 0) {
            $tools = [
                // Legacy tools
                'ml_list_publications',
                'ml_list_spaces',
                'ml_get_publication',
                'ml_get_space',
                'ml_get_my_context',
                'ml_create_publication',
                'ml_create_publication_from_text',
                'ml_edit_publication',
                'ml_append_to_publication',
                'ml_add_comment',
                'ml_import_as_comment',
                'ml_create_review',
                'ml_move_to_step',
                'ml_apply_tool',
                // New tool-map tools (Epic 1-7)
                'ml_spaces_list',
                'ml_space_get',
                'ml_space_steps_list',
                'ml_space_permissions_summary',
                'ml_publications_list',
                'ml_publication_get',
                'ml_publication_dependencies',
                'ml_tool_resolve',
                'ml_tool_validate',
                'ml_favorites_list',
                'ml_favorites_set',
                'ml_comments_list',
                'ml_comment_add',
                'ml_best_list',
                'ml_ratings_get',
                // Ratings & Workflow tools (T1.1, T1.2, T1.3)
                'ml_rate_publication',
                'ml_get_ratings_summary',
                'ml_list_workflow_steps',
                // Subscriptions (T1.4)
                'ml_subscribe_space',
                'ml_get_subscriptions',
                // Chain resolution (T2.1)
                'ml_get_publication_chain',
                // Duplication (T2.2)
                'ml_duplicate_publication',
                // Bulk operations (T3.1)
                'ml_bulk_apply_tool',
                // Comparison (T3.2)
                'ml_compare_publications',
                // Team management (T4.1)
                'ml_get_team',
                'ml_manage_team',
                // Export bundle (T4.2)
                'ml_export_bundle',
                // Auto-Improve Intelligence Layer (T5)
                'ml_auto_improve',
                'ml_prompt_health_check',
                'ml_auto_improve_batch',
                // Recommendation and Apply tools
                'ml_recommend',
                'ml_recommend_styles',
                'ml_apply_tool_prepare',
                'ml_apply_tool_commit',
                'ml_context_bundle_build',
                // Assist Tool (1-call orchestrator)
                'ml_assist_prepare',
                // Dependency management tools
                'ml_get_dependencies',
                'ml_add_dependency',
                'ml_remove_dependency',
                'ml_set_dependencies',
            ];

            // Add BuddyBoss tools if available
            if (\MCP_No_Headless\BuddyBoss\Group_Service::is_available()) {
                $tools = array_merge($tools, [
                    'ml_groups_search',
                    'ml_group_fetch',
                    'ml_group_members',
                ]);
            }

            if (\MCP_No_Headless\BuddyBoss\Activity_Service::is_available()) {
                $tools = array_merge($tools, [
                    'ml_activity_list',
                    'ml_activity_fetch',
                    'ml_activity_comments',
                ]);
            }

            if (\MCP_No_Headless\BuddyBoss\Member_Service::is_available()) {
                $tools = array_merge($tools, [
                    'ml_members_search',
                    'ml_member_fetch',
                ]);
            }

            return $tools;
        }
        return [];
    }

    // ========================================
    // AUTRES PERMISSIONS
    // ========================================

    public function can_create_publication(int $space_id): bool {
        if (!$this->pb) {
            return user_can($this->user_id, 'publish_posts');
        }

        if (method_exists($this->pb, 'can_add_publication') && !$this->pb->can_add_publication()) {
            return false;
        }

        if ($space_id > 0) {
            $user_spaces = $this->get_user_spaces();
            return in_array($space_id, $user_spaces, true);
        }

        return true;
    }

    /**
     * Check if user can publish in a space (public method for Apply_Tool_V2)
     *
     * @param int $space_id
     * @return bool
     */
    public function can_publish_in_space(int $space_id): bool {
        if ($space_id <= 0) {
            return false;
        }

        // Admin can always publish
        if ($this->is_admin()) {
            return true;
        }

        // Check if user can see the space first
        if (!$this->can_see_space($space_id)) {
            return false;
        }

        // Check if user has a role that allows publishing
        if ($this->is_in_space_role($space_id, '_space_moderators')) {
            return true;
        }
        if ($this->is_in_space_role($space_id, '_space_champions')) {
            return true;
        }

        // Check publish permission via Picasso table
        $pub_spaces = $this->get_spaces_from_permissions_table('publications');
        if (in_array($space_id, $pub_spaces, true)) {
            return true;
        }

        // Use Picasso Backend if available
        if ($this->pb && method_exists($this->pb, 'can_add_publication')) {
            return $this->pb->can_add_publication();
        }

        // Fallback: general publish capability
        return user_can($this->user_id, 'publish_posts');
    }

    public function can_edit_publication(int $publication_id): bool {
        if ($publication_id <= 0) return false;

        if ($this->pb && method_exists($this->pb, 'can_edit_publication')) {
            return $this->pb->can_edit_publication($publication_id);
        }

        $post = get_post($publication_id);
        return $post && (int) $post->post_author === $this->user_id;
    }

    public function can_delete_publication(int $publication_id): bool {
        if ($publication_id <= 0) return false;

        if ($this->pb && method_exists($this->pb, 'can_delete_publication')) {
            return $this->pb->can_delete_publication($publication_id);
        }

        $post = get_post($publication_id);
        return $post && (int) $post->post_author === $this->user_id;
    }

    private function can_add_comment(int $publication_id, string $type = 'public'): bool {
        if ($publication_id <= 0) return false;

        if (!$this->pb) return true;

        if ($type === 'private') {
            return method_exists($this->pb, 'can_post_private_comment')
                && $this->pb->can_post_private_comment($publication_id);
        }

        return method_exists($this->pb, 'can_post_public_comment')
            && $this->pb->can_post_public_comment($publication_id);
    }

    private function can_create_review(int $publication_id, string $type = 'user'): bool {
        if ($publication_id <= 0) return false;

        if (!$this->pb) return true;

        if ($type === 'expert') {
            return method_exists($this->pb, 'can_post_expert_review')
                && $this->pb->can_post_expert_review($publication_id);
        }

        return method_exists($this->pb, 'can_post_user_review')
            && $this->pb->can_post_user_review($publication_id);
    }

    private function can_edit_review(int $publication_id): bool {
        if ($publication_id <= 0) return false;

        if ($this->pb && method_exists($this->pb, 'can_edit_review')) {
            return $this->pb->can_edit_review($publication_id);
        }

        return false;
    }

    private function can_move_step(int $publication_id): bool {
        if ($publication_id <= 0) return false;

        if ($this->pb && method_exists($this->pb, 'can_move_publication_to_next_step')) {
            return $this->pb->can_move_publication_to_next_step($publication_id);
        }

        return false;
    }

    private function can_manage_team(int $publication_id): bool {
        if ($publication_id <= 0) return false;

        if ($this->pb && method_exists($this->pb, 'can_manage_publication_team_members')) {
            return $this->pb->can_manage_publication_team_members($publication_id);
        }

        return false;
    }

    private function can_manage_experts(int $publication_id): bool {
        if ($publication_id <= 0) return false;

        if ($this->pb && method_exists($this->pb, 'can_manage_publication_experts')) {
            return $this->pb->can_manage_publication_experts($publication_id);
        }

        return false;
    }

    /**
     * Check if user can comment on a publication
     */
    public function can_comment_publication(int $publication_id, string $visibility = 'public'): bool {
        return $this->can_add_comment($publication_id, $visibility);
    }

    /**
     * Check if user can use a tool (publication)
     */
    public function can_use_tool(int $tool_id): bool {
        if ($tool_id <= 0) return false;

        // Tool must be accessible as a publication
        return $this->can_see_publication($tool_id);
    }

    /**
     * Check if user can view ratings on a publication
     */
    public function can_view_ratings(int $publication_id): bool {
        if ($publication_id <= 0) return false;

        // Must be able to see publication first
        if (!$this->can_see_publication($publication_id)) {
            return false;
        }

        // Check space settings for ratings visibility
        $post = get_post($publication_id);
        if (!$post) return false;

        $space_id = (int) $post->post_parent;
        if ($space_id > 0) {
            $hide_ratings = get_post_meta($space_id, '_ml_hide_ratings', true);
            if (!empty($hide_ratings)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get permissions summary for a space/step
     */
    public function get_permissions_summary(int $space_id, ?string $step_name = null): array {
        $permissions = [
            'can_access' => $this->can_see_space($space_id),
            'can_create' => $this->can_create_publication($space_id),
            'can_comment_public' => false,
            'can_comment_private' => false,
        ];

        if (!$permissions['can_access']) {
            return $permissions;
        }

        $permissions['can_comment_public'] = true;

        // Check if user has elevated role in space
        $is_moderator = $this->is_in_space_role($space_id, '_space_moderators');
        $is_champion = $this->is_in_space_role($space_id, '_space_champions');
        $is_expert = $this->is_in_space_role($space_id, '_space_experts');

        $permissions['can_comment_private'] = $is_moderator || $is_champion || $is_expert;
        $permissions['is_moderator'] = $is_moderator;
        $permissions['is_champion'] = $is_champion;
        $permissions['is_expert'] = $is_expert;

        return $permissions;
    }

    /**
     * Vérifie si l'utilisateur peut créer dans un espace
     *
     * @param int $space_id ID de l'espace
     * @return bool
     */
    public function can_create_in_space(int $space_id): bool {
        $space = get_post($space_id);
        if (!$space || $space->post_type !== 'espace') {
            return false;
        }

        // Admin
        if (user_can($this->user_id, 'manage_options')) {
            return true;
        }

        // Membre du groupe (BuddyBoss)
        if (function_exists('groups_is_user_member')) {
            return groups_is_user_member($this->user_id, $space_id);
        }

        // Auteur de l'espace
        if ((int) $space->post_author === $this->user_id) {
            return true;
        }

        return $this->can_see_publication($space_id);
    }

    private function fallback_check(string $tool, array $args): bool {
        if (in_array($tool, ['ml_list_publications', 'ml_list_spaces', 'ml_get_my_context'])) {
            return true;
        }

        if (strpos($tool, 'ml_create') !== false || strpos($tool, 'ml_edit') !== false) {
            return user_can($this->user_id, 'edit_posts');
        }

        if (strpos($tool, 'ml_delete') !== false) {
            return user_can($this->user_id, 'delete_posts');
        }

        return true;
    }
}
