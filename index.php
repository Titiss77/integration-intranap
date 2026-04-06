<?php
require_once __DIR__ . '/controllers/PerformanceController.php';
$controller = new PerformanceController();

// 1. Interception pour la SYNCHRONISATION (Celle qui manquait !)
if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    require_once __DIR__ . '/controllers/SyncController.php';
    $sync = new SyncController();
    $sync->syncData();
    exit;
}

// 2. Interception pour l'API du GRAPHIQUE
if (isset($_GET['action']) && $_GET['action'] === 'history') {
    $controller->getHistoryApi();
    exit;
}

// 3. Sinon, on charge la page normale
$controller->index();