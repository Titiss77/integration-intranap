<?php

// --- 1. CHARGEMENT DES VARIABLES D'ENVIRONNEMENT ---
require_once __DIR__.'/config/Env.php';
Env::load(__DIR__.'/.env');

// ---------------------------------------------------

require_once __DIR__.'/controllers/PerformanceController.php';
$controller = new PerformanceController();

// Interception pour la SYNCHRONISATION
if (isset($_GET['action']) && 'sync' === $_GET['action']) {
    require_once __DIR__.'/controllers/SyncController.php';
    $sync = new SyncController();
    $sync->syncData();

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
