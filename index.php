<?php
// 🔴 1. Démarrage de la session (Indispensable pour le CSRF et le Rate Limiting)
session_start();

// 🔴 2. Génération d'un jeton CSRF unique pour la session s'il n'existe pas
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 🔴 3. En-têtes de sécurité HTTP
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
// Force le HTTPS si disponible
// header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// --- CHARGEMENT DES VARIABLES D'ENVIRONNEMENT ---
require_once __DIR__ . '/config/Env.php';
Env::load(__DIR__ . '/.env');

require_once __DIR__ . '/controllers/PerformanceController.php';
$controller = new PerformanceController();

// Interception pour la SYNCHRONISATION
if (isset($_GET['action']) && 'sync' === $_GET['action']) {
    require_once __DIR__ . '/controllers/SyncController.php';
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

// Sinon, on charge la page normale

// Interception pour l'EXPORT CSV
if (isset($_GET['action']) && 'export' === $_GET['action']) {
    $controller->exportCsv();

    exit;
}

$controller->index();
