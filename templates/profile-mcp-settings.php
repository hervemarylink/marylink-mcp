<?php
/**
 * Template: MCP Settings in BuddyBoss Profile
 *
 * Variables available:
 * - $token: User's MCP token
 * - $token_created: Token creation date
 * - $mcp_url: MCP SSE endpoint URL
 * - $user_profile: User's current profile (core/advanced)
 * - $tools: Array of tools available to user
 * - $tool_counts: Array with core/advanced/admin counts
 *
 * @package MaryLink_MCP
 * @since 2.2.0
 */

use MCP_No_Headless\MCP\Tool_Catalog;

if (!defined('ABSPATH')) {
    exit;
}

$nonce = wp_create_nonce('mlmcp_regenerate_token');
$toggle_nonce = wp_create_nonce('mlmcp_toggle_profile');
$is_core = ($user_profile === Tool_Catalog::PROFILE_CORE);
?>

<div class="mlmcp-settings">
    <div class="mlmcp-header">
        <h2><?php esc_html_e('Mon MCP', 'marylink-mcp-tools'); ?></h2>
        <p class="mlmcp-subtitle">
            <?php esc_html_e('Connectez Claude Desktop ou Claude Max a MaryLink', 'marylink-mcp-tools'); ?>
        </p>
    </div>

    <!-- Profile Toggle Card -->
    <div class="mlmcp-card mlmcp-card-highlight">
        <div class="mlmcp-card-header">
            <span class="mlmcp-icon">&#9881;</span>
            <h3><?php esc_html_e('Votre Profil', 'marylink-mcp-tools'); ?></h3>
        </div>
        <div class="mlmcp-card-body">
            <div class="mlmcp-profile-toggle">
                <div class="mlmcp-toggle-options">
                    <label class="mlmcp-toggle-option <?php echo $is_core ? 'active' : ''; ?>">
                        <input type="radio" name="mcp_profile" value="core" <?php checked($is_core); ?> />
                        <span class="mlmcp-toggle-label">
                            <strong>Core</strong>
                            <small><?php printf(__('%d outils essentiels', 'marylink-mcp-tools'), $tool_counts['core']); ?></small>
                        </span>
                    </label>
                    <label class="mlmcp-toggle-option <?php echo !$is_core ? 'active' : ''; ?>">
                        <input type="radio" name="mcp_profile" value="advanced" <?php checked(!$is_core); ?> />
                        <span class="mlmcp-toggle-label">
                            <strong>Advanced</strong>
                            <small><?php printf(__('%d outils (+%d)', 'marylink-mcp-tools'), $tool_counts['core'] + $tool_counts['advanced'], $tool_counts['advanced']); ?></small>
                        </span>
                    </label>
                </div>
                <p class="mlmcp-profile-desc">
                    <?php if ($is_core): ?>
                        <?php esc_html_e('Mode simplifie ideal pour Claude.ai web - outils essentiels uniquement.', 'marylink-mcp-tools'); ?>
                    <?php else: ?>
                        <?php esc_html_e('Mode complet avec tous les outils avances pour power users.', 'marylink-mcp-tools'); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Token Card -->
    <div class="mlmcp-card">
        <div class="mlmcp-card-header">
            <span class="mlmcp-icon">&#128273;</span>
            <h3><?php esc_html_e('Votre Token MCP', 'marylink-mcp-tools'); ?></h3>
        </div>
        <div class="mlmcp-card-body">
            <div class="mlmcp-token-display">
                <input type="text" id="mlmcp-token" value="<?php echo esc_attr($token); ?>" readonly />
                <button type="button" class="mlmcp-btn mlmcp-btn-secondary" id="mlmcp-copy-token">
                    <?php esc_html_e('Copier', 'marylink-mcp-tools'); ?>
                </button>
                <button type="button" class="mlmcp-btn mlmcp-btn-outline" id="mlmcp-regenerate-token" data-nonce="<?php echo esc_attr($nonce); ?>">
                    <?php esc_html_e('Regenerer', 'marylink-mcp-tools'); ?>
                </button>
            </div>

            <?php if ($token_created): ?>
                <p class="mlmcp-token-date">
                    <?php printf(
                        esc_html__('Cree le %s', 'marylink-mcp-tools'),
                        esc_html(date_i18n(get_option('date_format'), strtotime($token_created)))
                    ); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Config Card -->
    <div class="mlmcp-card">
        <div class="mlmcp-card-header">
            <span class="mlmcp-icon">&#9881;</span>
            <h3><?php esc_html_e('Configuration Claude Desktop', 'marylink-mcp-tools'); ?></h3>
        </div>
        <div class="mlmcp-card-body">
            <p><?php esc_html_e('Copiez cette configuration dans votre fichier mcp.json:', 'marylink-mcp-tools'); ?></p>

            <div class="mlmcp-code-container">
                <button type="button" class="mlmcp-code-copy" id="mlmcp-copy-config">
                    <?php esc_html_e('Copier', 'marylink-mcp-tools'); ?>
                </button>
                <pre class="mlmcp-code-block" id="mlmcp-config">{
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
            </div>

            <div class="mlmcp-paths">
                <p><strong><?php esc_html_e('Emplacement du fichier:', 'marylink-mcp-tools'); ?></strong></p>
                <ul>
                    <li><code>~/.config/claude/mcp.json</code> <span class="mlmcp-os">(Mac/Linux)</span></li>
                    <li><code>%APPDATA%\claude\mcp.json</code> <span class="mlmcp-os">(Windows)</span></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Tools Card - Dynamic from Tool_Catalog -->
    <div class="mlmcp-card">
        <div class="mlmcp-card-header">
            <span class="mlmcp-icon">&#128736;</span>
            <h3>
                <?php esc_html_e('Outils MCP disponibles', 'marylink-mcp-tools'); ?>
                <span class="mlmcp-badge" id="mlmcp-tools-count"><?php echo count($tools); ?></span>
            </h3>
        </div>
        <div class="mlmcp-card-body">
            <div class="mlmcp-tools-grid" id="mlmcp-tools-list">
                <?php foreach ($tools as $tool): ?>
                    <div class="mlmcp-tool">
                        <code><?php echo esc_html($tool['name']); ?></code>
                        <span><?php echo esc_html($tool['description']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Multi-MCP Card -->
    <div class="mlmcp-card">
        <div class="mlmcp-card-header">
            <span class="mlmcp-icon">&#128279;</span>
            <h3><?php esc_html_e('Multi-MCP', 'marylink-mcp-tools'); ?></h3>
        </div>
        <div class="mlmcp-card-body">
            <p><?php esc_html_e('Combinez MaryLink avec d\'autres serveurs MCP:', 'marylink-mcp-tools'); ?></p>

            <div class="mlmcp-integrations">
                <div class="mlmcp-integration">
                    <strong>Slack</strong>
                    <p><?php esc_html_e('Resumer des discussions et creer des publications', 'marylink-mcp-tools'); ?></p>
                </div>
                <div class="mlmcp-integration">
                    <strong>Google Drive</strong>
                    <p><?php esc_html_e('Importer des documents comme publications', 'marylink-mcp-tools'); ?></p>
                </div>
                <div class="mlmcp-integration">
                    <strong>Notion</strong>
                    <p><?php esc_html_e('Synchroniser du contenu entre Notion et MaryLink', 'marylink-mcp-tools'); ?></p>
                </div>
                <div class="mlmcp-integration">
                    <strong>GitHub</strong>
                    <p><?php esc_html_e('Lier du code source a des publications', 'marylink-mcp-tools'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Copy token
    document.getElementById('mlmcp-copy-token').addEventListener('click', function() {
        var tokenInput = document.getElementById('mlmcp-token');
        tokenInput.select();
        navigator.clipboard.writeText(tokenInput.value);
        this.textContent = 'Copie !';
        setTimeout(() => { this.textContent = 'Copier'; }, 2000);
    });

    // Copy config
    document.getElementById('mlmcp-copy-config').addEventListener('click', function() {
        var config = document.getElementById('mlmcp-config').textContent;
        navigator.clipboard.writeText(config);
        this.textContent = 'Copie !';
        setTimeout(() => { this.textContent = 'Copier'; }, 2000);
    });

    // Regenerate token
    document.getElementById('mlmcp-regenerate-token').addEventListener('click', function() {
        if (!confirm('Regenerer le token invalidera l\'ancien. Continuer ?')) return;
        var btn = this;
        btn.disabled = true;
        btn.textContent = '...';
        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=mlmcp_regenerate_token&nonce=' + btn.dataset.nonce
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else { alert(data.data.message || 'Erreur'); btn.disabled = false; btn.textContent = 'Regenerer'; }
        })
        .catch(e => { alert('Erreur: ' + e.message); btn.disabled = false; });
    });

    // Profile toggle
    document.querySelectorAll('input[name="mcp_profile"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            var newProfile = this.value;
            document.querySelectorAll('.mlmcp-toggle-option').forEach(opt => opt.classList.remove('active'));
            this.closest('.mlmcp-toggle-option').classList.add('active');
            fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=mlmcp_toggle_profile&nonce=<?php echo esc_attr($toggle_nonce); ?>&profile=' + newProfile
            })
            .then(r => r.json())
            .then(data => { if (data.success) location.reload(); else alert(data.data.message || 'Erreur'); })
            .catch(e => alert('Erreur: ' + e.message));
        });
    });
});
</script>

<style>
.mlmcp-card-highlight { border: 2px solid #0073aa; }
.mlmcp-profile-toggle { margin-bottom: 10px; }
.mlmcp-toggle-options { display: flex; gap: 15px; margin-bottom: 15px; }
.mlmcp-toggle-option { flex: 1; display: flex; align-items: center; padding: 15px 20px; border: 2px solid #e0e0e0; border-radius: 10px; cursor: pointer; transition: all 0.2s; background: #fff; }
.mlmcp-toggle-option:hover { border-color: #0073aa; }
.mlmcp-toggle-option.active { border-color: #0073aa; background: #f0f7ff; }
.mlmcp-toggle-option input { margin-right: 12px; width: 20px; height: 20px; }
.mlmcp-toggle-label { display: flex; flex-direction: column; }
.mlmcp-toggle-label strong { font-size: 16px; color: #1a1a1a; }
.mlmcp-toggle-label small { color: #666; font-size: 13px; margin-top: 2px; }
.mlmcp-profile-desc { color: #666; font-size: 14px; margin: 0; padding: 10px; background: #f8f9fa; border-radius: 6px; }
.mlmcp-badge { display: inline-block; background: #0073aa; color: #fff; font-size: 12px; font-weight: bold; padding: 2px 8px; border-radius: 10px; margin-left: 8px; }
@media (max-width: 600px) { .mlmcp-toggle-options { flex-direction: column; } }
</style>
