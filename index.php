<?php
// Gérer l'appel AJAX pour la synchronisation
if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    require_once __DIR__ . '/controllers/SyncController.php';
    header('Content-Type: application/json');
    $sync = new SyncController();
    $sync->syncData();
    exit; // On stoppe l'exécution ici pour ne pas afficher le HTML
}

// Comportement normal : On charge le contrôleur principal
require_once __DIR__ . '/controllers/PerformanceController.php';
$controller = new PerformanceController();
$controller->index();