<?php

$https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// 🔴 2. Démarrage de la session
session_start();

// 🔴 3. Génération d'un jeton CSRF unique
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 🔴 4. En-têtes de sécurité HTTP
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// --- CHARGEMENT DES VARIABLES D'ENVIRONNEMENT ---
require_once __DIR__.'/config/Env.php';
Env::load(__DIR__.'/.env');

require_once __DIR__.'/controllers/PerformanceController.php';
$controller = new PerformanceController();

// Interception pour la SYNCHRONISATION
if (isset($_GET['action']) && 'sync' === $_GET['action']) {
    require_once __DIR__.'/controllers/SyncController.php';
    $sync = new SyncController();

    // 🔴 On passe le token reçu dans l'URL au contrôleur
    $token_recu = $_POST['token'] ?? '';
    $sync->syncData($token_recu);

    exit;
}

// Export des insertions idempotentes pour fusionner la base locale en ligne.
if (isset($_GET['action']) && 'export_sql' === $_GET['action']) {
    require_once __DIR__.'/controllers/SyncController.php';
    $sync = new SyncController();
    $sync->exportSql($_GET['token'] ?? '');
    exit;
}

// Interception pour l'API du GRAPHIQUE
if (isset($_GET['action']) && 'history' === $_GET['action']) {
    $controller->getHistoryApi();

    exit;
}
// Interception pour la LECTURE DES LOGS
if (isset($_GET['action']) && 'get_logs' === $_GET['action']) {
    require_once __DIR__.'/controllers/SyncController.php';
    $sync = new SyncController();
    $sync->getLogs();
    exit;
}
// Sinon, on charge la page normale

// Interception pour l'EXPORT CSV
if (isset($_GET['action']) && 'export' === $_GET['action']) {
    $controller->exportCsv();

    exit;
}

$controller->index();
