<?php
require_once __DIR__ . '/controllers/PerformanceController.php';
$controller = new PerformanceController();

// Interception pour l'API du graphique
if (isset($_GET['action']) && $_GET['action'] === 'history') {
    $controller->getHistoryApi();
    exit;
}

// Sinon, on charge la page normale
$controller->index();