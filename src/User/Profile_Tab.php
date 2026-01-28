<?php
/**
 * Profile Tab - Adds MCP settings tab to user profile
 *
 * @package MCP_No_Headless
 */

namespace MCP_No_Headless\User;

use MCP_No_Headless\MCP\Tool_Catalog;

class Profile_Tab {

    /**
     * Token manager instance
     *
     * @var Token_Manager
     */
    private Token_Manager $token_manager;

    /**
     * Constructor - Register hooks
     */
    public function __construct() {
        $this->token_manager = new Token_Manager();

        // Add profile navigation item
        add_action('bp_setup_nav', [$this, 'add_profile_nav'], 100);

        // Enqueue assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handler for profile toggle (v2.2.0+)
        add_action('wp_ajax_mlmcp_toggle_profile', [$this, 'ajax_toggle_profile']);
    }

    /**
     * Add MCP tab to profile navigation
     */
    public function add_profile_nav(): void {
        // Only add for logged in users viewing their own profile
        if (!is_user_logged_in()) {
            return;
        }

        $user_domain = bp_loggedin_user_domain();

        bp_core_new_subnav_item([
            'name' => __('Mon MCP', 'marylink-mcp-tools'),
            'slug' => 'mcp-settings',
            'parent_url' => $user_domain . 'profile/',
            'parent_slug' => 'profile',
            'screen_function' => [$this, 'screen_function'],
            'position' => 90,
            'user_has_access' => bp_is_my_profile(),
        ]);
    }

    /**
     * Screen function for the MCP tab
     */
    public function screen_function(): void {
        add_action('bp_template_content', [$this, 'render_content']);
        bp_core_load_template('members/single/plugins');
    }

    /**
     * Render the MCP settings content
     */
    public function render_content(): void {
        $user_id = bp_displayed_user_id();
        $token = $this->token_manager->get_or_create_token($user_id);
        $token_created = $this->token_manager->get_token_created_date($user_id);
        $mcp_url = home_url('/wp-json/mcp/v1/sse');

        // Get user profile and tools from Tool_Catalog (v2.2.0+)
        $user_profile = Tool_Catalog::get_user_profile($user_id);
        $ctx = [
            'user_id' => $user_id,
            'profile' => $user_profile,
            'scopes' => [],
            'include_legacy' => false,
        ];
        $tools = Tool_Catalog::build($ctx);
        $tool_counts = Tool_Catalog::get_profile_counts();

        // Include template
        $template_path = MCPNH_PLUGIN_DIR . 'templates/profile-mcp-settings.php';

        if (file_exists($template_path)) {
            include $template_path;
        } else {
            // Inline template fallback
            $this->render_inline_template($token, $token_created, $mcp_url);
        }
    }

    /**
     * Inline template fallback
     */
    private function render_inline_template(string $token, ?string $token_created, string $mcp_url): void {
        $nonce = wp_create_nonce('mlmcp_regenerate_token');
        ?>
        <div class="mlmcp-settings">
            <h2><?php esc_html_e('Configuration MCP', 'marylink-mcp-tools'); ?></h2>

            <div class="mlmcp-info-box">
                <p><?php esc_html_e('Connectez votre Claude Desktop ou Claude Max à MaryLink pour utiliser l\'IA avec vos publications.', 'marylink-mcp-tools'); ?></p>
            </div>

            <div class="mlmcp-section">
                <h3><?php esc_html_e('Votre Token MCP', 'marylink-mcp-tools'); ?></h3>

                <div class="mlmcp-token-display">
                    <input type="text" id="mlmcp-token" value="<?php echo esc_attr($token); ?>" readonly />
                    <button type="button" class="button" id="mlmcp-copy-token">
                        <?php esc_html_e('Copier', 'marylink-mcp-tools'); ?>
                    </button>
                    <button type="button" class="button" id="mlmcp-regenerate-token" data-nonce="<?php echo esc_attr($nonce); ?>">
                        <?php esc_html_e('Régénérer', 'marylink-mcp-tools'); ?>
                    </button>
                </div>

                <?php if ($token_created): ?>
                    <p class="mlmcp-token-date">
                        <?php printf(
                            esc_html__('Token créé le: %s', 'marylink-mcp-tools'),
                            esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($token_created)))
                        ); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="mlmcp-section">
                <h3><?php esc_html_e('Configuration Claude Desktop', 'marylink-mcp-tools'); ?></h3>

                <p><?php esc_html_e('Ajoutez cette configuration à votre fichier mcp.json:', 'marylink-mcp-tools'); ?></p>

                <pre class="mlmcp-code-block">{
  "mcpServers": {
    "marylink": {
      "type": "sse",
      "url": "<?php echo esc_url($mcp_url); ?>",
      "headers": {
        "Authorization": "Bearer <?php echo esc_html($token); ?>"
      }
    }
  }
}</pre>

                <p class="mlmcp-help-text">
                    <?php esc_html_e('Emplacement du fichier:', 'marylink-mcp-tools'); ?>
                    <br />
                    <code>~/.config/claude/mcp.json</code> (Mac/Linux)
                    <br />
                    <code>%APPDATA%\claude\mcp.json</code> (Windows)
                </p>
            </div>

            <div class="mlmcp-section">
                <h3><?php esc_html_e('Outils MCP disponibles', 'marylink-mcp-tools'); ?></h3>

                <table class="mlmcp-tools-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Outil', 'marylink-mcp-tools'); ?></th>
                            <th><?php esc_html_e('Description', 'marylink-mcp-tools'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>ml_list_publications</code></td>
                            <td><?php esc_html_e('Lister les publications', 'marylink-mcp-tools'); ?></td>
                        </tr>
                        <tr>
                            <td><code>ml_create_publication</code></td>
                            <td><?php esc_html_e('Créer une publication', 'marylink-mcp-tools'); ?></td>
                        </tr>
                        <tr>
                            <td><code>ml_add_comment</code></td>
                            <td><?php esc_html_e('Ajouter un commentaire', 'marylink-mcp-tools'); ?></td>
                        </tr>
                        <tr>
                            <td><code>ml_create_review</code></td>
                            <td><?php esc_html_e('Créer une review', 'marylink-mcp-tools'); ?></td>
                        </tr>
                        <tr>
                            <td><code>ml_move_to_step</code></td>
                            <td><?php esc_html_e('Changer le step workflow', 'marylink-mcp-tools'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mlmcp-section">
                <h3><?php esc_html_e('Multi-MCP', 'marylink-mcp-tools'); ?></h3>

                <p><?php esc_html_e('Vous pouvez combiner MaryLink avec d\'autres serveurs MCP:', 'marylink-mcp-tools'); ?></p>

                <ul class="mlmcp-mcp-list">
                    <li><strong>Slack MCP</strong> - <?php esc_html_e('Résumer des discussions et créer des publications', 'marylink-mcp-tools'); ?></li>
                    <li><strong>Google Drive MCP</strong> - <?php esc_html_e('Importer des documents', 'marylink-mcp-tools'); ?></li>
                    <li><strong>Notion MCP</strong> - <?php esc_html_e('Synchroniser du contenu', 'marylink-mcp-tools'); ?></li>
                    <li><strong>GitHub MCP</strong> - <?php esc_html_e('Lier du code à des publications', 'marylink-mcp-tools'); ?></li>
                </ul>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Copy token
            document.getElementById('mlmcp-copy-token').addEventListener('click', function() {
                var tokenInput = document.getElementById('mlmcp-token');
                tokenInput.select();
                document.execCommand('copy');
                this.textContent = '<?php esc_html_e('Copié !', 'marylink-mcp-tools'); ?>';
                setTimeout(() => {
                    this.textContent = '<?php esc_html_e('Copier', 'marylink-mcp-tools'); ?>';
                }, 2000);
            });

            // Regenerate token
            document.getElementById('mlmcp-regenerate-token').addEventListener('click', function() {
                if (!confirm('<?php esc_html_e('Régénérer le token invalidera l\'ancien. Continuer ?', 'marylink-mcp-tools'); ?>')) {
                    return;
                }

                var btn = this;
                btn.disabled = true;

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=mlmcp_regenerate_token&nonce=' + btn.dataset.nonce
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('mlmcp-token').value = data.data.token;
                        location.reload();
                    } else {
                        alert(data.data.message || 'Error');
                    }
                    btn.disabled = false;
                })
                .catch(e => {
                    alert('Error: ' + e.message);
                    btn.disabled = false;
                });
            });
        });
        </script>

        <style>
        .mlmcp-settings {
            max-width: 800px;
            padding: 20px;
        }
        .mlmcp-info-box {
            background: #f0f7ff;
            border-left: 4px solid #0073aa;
            padding: 15px;
            margin-bottom: 20px;
        }
        .mlmcp-section {
            margin-bottom: 30px;
        }
        .mlmcp-token-display {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        .mlmcp-token-display input {
            flex: 1;
            font-family: monospace;
            padding: 8px;
        }
        .mlmcp-code-block {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 13px;
        }
        .mlmcp-help-text {
            color: #666;
            font-size: 13px;
        }
        .mlmcp-tools-table {
            width: 100%;
            border-collapse: collapse;
        }
        .mlmcp-tools-table th,
        .mlmcp-tools-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        .mlmcp-tools-table th {
            background: #f5f5f5;
        }
        .mlmcp-mcp-list li {
            margin-bottom: 8px;
        }
        </style>
        <?php
    }

    /**
     * AJAX handler for profile toggle
     */
    public function ajax_toggle_profile(): void {
        check_ajax_referer('mlmcp_toggle_profile', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => 'Not authenticated']);
            return;
        }

        $new_profile = sanitize_text_field($_POST['profile'] ?? '');
        if (!in_array($new_profile, [Tool_Catalog::PROFILE_CORE, Tool_Catalog::PROFILE_ADVANCED], true)) {
            wp_send_json_error(['message' => 'Invalid profile']);
            return;
        }

        Tool_Catalog::set_user_profile($user_id, $new_profile);

        // Get updated tools count
        $ctx = [
            'user_id' => $user_id,
            'profile' => $new_profile,
            'scopes' => [],
            'include_legacy' => false,
        ];
        $tools = Tool_Catalog::build($ctx);

        wp_send_json_success([
            'profile' => $new_profile,
            'tools_count' => count($tools),
            'message' => sprintf(
                __('Profil mis à jour: %s (%d outils)', 'marylink-mcp-tools'),
                $new_profile === Tool_Catalog::PROFILE_CORE ? 'Core' : 'Advanced',
                count($tools)
            ),
        ]);
    }

    /**
     * Enqueue assets
     */
    public function enqueue_assets(): void {
        if (!bp_is_my_profile()) {
            return;
        }

        // Enqueue CSS if it exists
        $css_path = MCPNH_PLUGIN_DIR . 'assets/css/profile.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'mcpnh-profile',
                MCPNH_PLUGIN_URL . 'assets/css/profile.css',
                [],
                MCPNH_VERSION
            );
        }
    }
}
