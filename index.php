<?php
// On charge le contrôleur principal
require_once __DIR__ . '/controllers/PerformanceController.php';

// On instancie le contrôleur et on lance la méthode index
$controller = new PerformanceController();
$controller->index();