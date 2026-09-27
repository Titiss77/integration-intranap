<?php

// 🔴 1. Sécurisation extrême du cookie de session (RGPD / Sécurité)
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => isset($_SERVER['HTTPS']), // true si HTTPS est actif
    'httponly' => true, // Empêche l'accès au cookie via JavaScript (anti-XSS)
    'samesite' => 'Strict' // Empêche l'envoi du cookie depuis d'autres sites (anti-CSRF)
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
header('X-XSS-Protection: 1; mode=block');
// Dé-commenté pour forcer le HTTPS (très important pour le RGPD)
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

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
    $token_recu = $_GET['token'] ?? '';
    $sync->syncData($token_recu);

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