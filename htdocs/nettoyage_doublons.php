<?php
// Chemin vers votre fichier JSON (à ajuster si besoin)
$file = __DIR__ . '/perfs/performances.json';

if (!file_exists($file)) {
    die("❌ Fichier $file introuvable.");
}

$json_content = json_decode(file_get_contents($file), true);

// 1. Trouver le bloc de données dans la structure phpMyAdmin
$data_ref = null;
$data_index = -1;

foreach ($json_content as $i => $item) {
    if (isset($item['type']) && $item['type'] === 'table' && isset($item['name']) && $item['name'] === 'performances') {
        $data_ref = &$json_content[$i]['data'];
        $data_index = $i;
        break;
    }
}

if ($data_ref === null) {
    die("❌ Structure attendue introuvable dans le JSON.");
}

// 2. Traque et suppression des doublons
$unique_perfs = [];
$cleaned_data = [];
$doublons_count = 0;

foreach ($data_ref as $p) {
    // On crée une empreinte unique pour chaque performance : nageur + epreuve + temps + date
    $key = $p['nageur_id'] . '_' . $p['epreuve_id'] . '_' . $p['temps'] . '_' . $p['date_perf'];
    
    if (!isset($unique_perfs[$key])) {
        $unique_perfs[$key] = true;
        $cleaned_data[] = $p; // On garde cette performance (c'est la première occurrence)
    } else {
        $doublons_count++; // On l'ignore (c'est un doublon)
    }
}

// 3. Remplacement des données et sauvegarde
$json_content[$data_index]['data'] = $cleaned_data;

file_put_contents(
    $file, 
    json_encode($json_content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

echo "✅ Nettoyage terminé avec succès ! <br>";
echo "🗑️ <strong>$doublons_count doublons</strong> ont été supprimés de la base de données JSON.";