<?php
/**
 * GitHub Webhook - Auto Deploy
 *
 * URL: https://mcpproject.marylink.net/wp-content/plugins/marylink-mcp-tools/deploy-webhook.php
 *
 * Configure dans GitHub > Settings > Webhooks:
 * - Payload URL: [cette URL]
 * - Content type: application/json
 * - Secret: [WEBHOOK_SECRET ci-dessous]
 * - Events: Just the push event
 */

// Configuration
define('WEBHOOK_SECRET', 'marylink-mcp-deploy-2024');
define('REPO_PATH', '/home/runcloud/webapps/clientsite/wp-content/plugins/marylink-mcp-tools');
define('LOG_FILE', '/tmp/mcp-deploy.log');

// Vérifier la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Récupérer le payload
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

// Vérifier la signature
$expected = 'sha256=' . hash_hmac('sha256', $payload, WEBHOOK_SECRET);
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    log_deploy('Signature invalide');
    exit('Invalid signature');
}

// Décoder le payload
$data = json_decode($payload, true);

// Vérifier que c'est un push sur main
if (($data['ref'] ?? '') !== 'refs/heads/main') {
    http_response_code(200);
    log_deploy('Push ignoré (pas sur main): ' . ($data['ref'] ?? 'unknown'));
    exit('Not main branch, skipping');
}

// Exécuter git pull
log_deploy('Déploiement démarré...');

$output = [];
$return_var = 0;

// Changer de répertoire et pull
chdir(REPO_PATH);
exec('git pull origin main 2>&1', $output, $return_var);

$result = implode("\n", $output);
log_deploy("Git pull result (code $return_var):\n$result");

if ($return_var === 0) {
    http_response_code(200);
    echo "Deploy success:\n$result";
} else {
    http_response_code(500);
    echo "Deploy failed:\n$result";
}

/**
 * Log helper
 */
function log_deploy($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[$timestamp] $message\n", FILE_APPEND);
}
