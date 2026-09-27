<?php
require_once __DIR__ . '/vendor/autoload.php';

$error = null;
$successCount = 0;

// 1. CHARGEMENT DE L'ENVIRONNEMENT ET CONNEXION BDD
$envConfig = [];
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $envConfig = parse_ini_file($envPath);
}
$api_club = strtoupper(trim($_ENV['API_CLUB'] ?? $envConfig['API_CLUB'] ?? ''));

$db_host = $_ENV['DB_HOST'] ?? $envConfig['DB_HOST'] ?? 'localhost';
$db_name = $_ENV['DB_NAME'] ?? $envConfig['DB_NAME'] ?? 'b7_41910034_intranap_club';
$db_user = $_ENV['DB_USER'] ?? $envConfig['DB_USER'] ?? 'root';
$db_pass = $_ENV['DB_PASS'] ?? $envConfig['DB_PASS'] ?? '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Erreur de connexion à la base de données : ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert'])) {
    if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        $tmpFilePath = $_FILES['pdf_file']['tmp_name'];
        $parser = new \Smalot\PdfParser\Parser();

        try {
            $pdf = $parser->parseFile($tmpFilePath);
            $text = $pdf->getText();
            $lines = explode("\n", $text);

            // Préparation des requêtes SQL pour éviter la redondance
            $stmtGetEpreuve = $pdo->prepare('SELECT id FROM epreuves WHERE nom_epreuve = ?');
            $stmtAddEpreuve = $pdo->prepare('INSERT INTO epreuves (nom_epreuve) VALUES (?)');

            $stmtGetCat = $pdo->prepare('SELECT id FROM categories WHERE nom_categorie = ?');
            $stmtAddCat = $pdo->prepare('INSERT INTO categories (nom_categorie, libelle) VALUES (?, ?)');

            $stmtGetLieu = $pdo->prepare('SELECT id FROM lieux WHERE nom_lieu = ?');
            $stmtAddLieu = $pdo->prepare('INSERT INTO lieux (nom_lieu) VALUES (?)');

            $stmtGetNageur = $pdo->prepare('SELECT id FROM nageurs WHERE nom = ? AND prenom = ?');
            $stmtAddNageur = $pdo->prepare('INSERT INTO nageurs (nom, prenom, genre, date_naissance) VALUES (?, ?, ?, ?)');

            // INSERT IGNORE permet d'ignorer la ligne si elle existe déjà (grâce à votre UNIQUE KEY unique_perf)
            $stmtAddPerf = $pdo->prepare('INSERT IGNORE INTO performances (nageur_id, epreuve_id, categorie_id, lieu_id, saison, temps, date_perf, classement) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

            // Variables de contexte par défaut
            $epreuve_courante = 'Épreuve inconnue';
            $lieu_texte = 'Compétition Inconnue';
            $date_texte = date('Y-m-d');
            $saison = (int) date('Y');

            // Extraction du Lieu et de la Date/Saison dans l'en-tête du PDF
            foreach (array_slice($lines, 0, 10) as $l) {
                if (preg_match('/([A-Za-zÀ-ÿ\s]+)\s*-\s*(.*20\d{2})/u', $l, $m)) {
                    $lieu_texte = trim($m[1]);
                    $date_texte = trim($m[2]);
                    if (preg_match('/(20\d{2})/', $date_texte, $y)) {
                        $saison = (int) $y[1];
                    }
                    break;
                }
            }

            // Variables de contexte par défaut
            $epreuve_courante = 'INCONNU';  // Plus court pour éviter l'erreur si aucune épreuve n'est trouvée
            $lieu_texte = 'Compétition Inconnue';
            $date_texte = date('Y-m-d');
            $saison = (int) date('Y');

            // Extraction du Lieu et de la Date/Saison dans l'en-tête du PDF
            foreach (array_slice($lines, 0, 10) as $l) {
                if (preg_match('/([A-Za-zÀ-ÿ\s]+)\s*-\s*(.*20\d{2})/u', $l, $m)) {
                    $lieu_texte = trim($m[1]);
                    $date_texte = trim($m[2]);
                    if (preg_match('/(20\d{2})/', $date_texte, $y)) {
                        $saison = (int) $y[1];
                    }
                    break;
                }
            }

            // --- INSERTION DU LIEU ---
            $stmtGetLieu->execute([$lieu_texte]);
            $lieu_id = $stmtGetLieu->fetchColumn();
            if (!$lieu_id) {
                $stmtAddLieu->execute([$lieu_texte]);
                $lieu_id = $pdo->lastInsertId();
            }

            // Parcours de chaque ligne du PDF
            foreach ($lines as $line) {
                $line = trim(preg_replace('/\s+/u', ' ', $line));
                if (empty($line))
                    continue;

                // ---------------------------------------------------------
                // 1. DÉTECTION ET CONVERSION DU NOM DE L'ÉPREUVE (Ex: "50SF")
                // ---------------------------------------------------------
                if (preg_match('/(\d+(?:x\d+)?)m\s+(Surface|Bipalmes|Immersion|Apnée|Apnee|Scaphandre)/ui', $line, $mEpreuve)) {
                    $distance = $mEpreuve[1];  // Capture "50", "100" ou "4x100"
                    $styleStr = mb_strtolower($mEpreuve[2], 'UTF-8');

                    $styleCode = '';
                    if (strpos($styleStr, 'surface') !== false)
                        $styleCode = 'SF';
                    elseif (strpos($styleStr, 'bipalmes') !== false)
                        $styleCode = 'BI';
                    elseif (strpos($styleStr, 'immersion') !== false)
                        $styleCode = 'IS';
                    elseif (strpos($styleStr, 'apn') !== false)
                        $styleCode = 'AP';
                    else
                        $styleCode = 'XX';

                    $epreuve_courante = $distance . $styleCode;  // Concaténation (ex: "50" + "SF" = "50SF")

                    // --- INSERTION DE L'ÉPREUVE ---
                    $stmtGetEpreuve->execute([$epreuve_courante]);
                    $epreuve_id = $stmtGetEpreuve->fetchColumn();
                    if (!$epreuve_id) {
                        $stmtAddEpreuve->execute([$epreuve_courante]);
                        $epreuve_id = $pdo->lastInsertId();
                    }
                    continue;
                }
                // ---------------------------------------------------------

                // 2. Nettoyage du statut et des anomalies
                $statut = '';
                if (preg_match('/\(en finale\)|Abandon|Disqualification|Forfait|Faux départ/i', $line, $matches)) {
                    $statut = trim($matches[0]);
                    $line = trim(str_replace($statut, '', $line));
                }
                $line = preg_replace('/([A-Z]{2,})([\d])/', '$1 $2', $line);
                $line = preg_replace('/(\d{2}\.\d{2})([\d])/', '$1 $2', $line);
                $line = preg_replace('/([\d])(MPF|RF|RM|RE|IN)/', '$1 $2', $line);

                // 3. Scanner de structure
                $pattern = '/^(\d*)\s*([a-zA-ZÀ-ÿ\s\'-]+?)\s*(\d{2})\s*([FH][A-Z0-9+]{2,3})\s*(\*?\s*[a-zA-Z0-9]{2,})\s*(.*)$/ui';

                if (preg_match($pattern, $line, $m)) {
                    $place = trim($m[1]);
                    $place = is_numeric($place) ? (int) $place : null;  // Gestion des nulls pour les Abandons/Forfaits

                    $nom_complet = trim($m[2]);
                    $annee = $m[3];
                    $categorie = trim($m[4]);
                    $club_pdf = trim($m[5]);

                    // 4. Filtrage par club (.env)
                    $club_propre = strtoupper(trim(str_replace('*', '', $club_pdf)));
                    if (!empty($api_club) && $club_propre !== $api_club) {
                        continue;
                    }

                    // ---------------------------------------------------------
                    // 5. GESTION DU TEMPS NORMALISÉ (Format 00:00.00)
                    // ---------------------------------------------------------
                    $temps_brut = trim(preg_replace('/\s+/', ' ', $m[6]));
                    $temps_array = explode(' ', $temps_brut);
                    $temps_final = '';

                    foreach ($temps_array as $t) {
                        if (preg_match('/^\d/', $t)) {
                            // Nettoyage des lettres parasites (MPF, IN, etc.)
                            $t = preg_replace('/[a-zA-Z]+$/', '', $t);

                            // Normalisation vers 00:00.00
                            // Si le temps contient ':', c'est MM:SS.ms
                            if (strpos($t, ':') !== false) {
                                $parts = explode(':', $t);
                                $minutes = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
                                // S'il manque les millisecondes après le point, on en rajoute
                                $secParts = explode('.', $parts[1]);
                                $secondes = str_pad($secParts[0], 2, '0', STR_PAD_LEFT);
                                $ms = isset($secParts[1]) ? str_pad($secParts[1], 2, '0', STR_PAD_RIGHT) : '00';
                                $temps_final = "$minutes:$secondes.$ms";
                            }
                            // Si pas de ':', c'est SS.ms (on ajoute 00: devant)
                            else {
                                $secParts = explode('.', $t);
                                $secondes = str_pad($secParts[0], 2, '0', STR_PAD_LEFT);
                                $ms = isset($secParts[1]) ? str_pad($secParts[1], 2, '0', STR_PAD_RIGHT) : '00';
                                $temps_final = "00:$secondes.$ms";
                            }
                        } else {
                            $statut = trim($statut . ' ' . $t);
                        }
                    }
                    // ---------------------------------------------------------

                    // --- 6. SÉPARATION NOM / PRÉNOM ---
                    // Les noms de famille sont en majuscules, les prénoms en minuscules ou capitalisés
                    preg_match("/^([A-ZÀ-Ÿ\s'-]+)\s+([A-ZÀ-Ÿ]?[a-zà-ÿA-ZÀ-Ÿ\s'-]+)\$/u", $nom_complet, $name_matches);
                    $nom = isset($name_matches[1]) ? trim($name_matches[1]) : $nom_complet;
                    $prenom = isset($name_matches[2]) ? trim($name_matches[2]) : '';

                    // --- 7. DÉDUCTION DU GENRE ET DE L'ANNÉE ---
                    $genre = (strtoupper(substr($categorie, 0, 1)) === 'F') ? 'F' : 'M';
                    $annee_int = (int) $annee;
                    // Si l'année à 2 chiffres est supérieure à (année actuelle - 2000 + 10), c'est une naissance des années 1900
                    $annee_naissance = ($annee_int > date('y') + 10) ? 1900 + $annee_int : 2000 + $annee_int;
                    $date_naissance = $annee_naissance . '-01-01';

                    // --- INSERTION DE LA CATÉGORIE ---
                    $stmtGetCat->execute([$categorie]);
                    $categorie_id = $stmtGetCat->fetchColumn();
                    if (!$categorie_id) {
                        $stmtAddCat->execute([$categorie, $categorie]);
                        $categorie_id = $pdo->lastInsertId();
                    }

                    // --- INSERTION DU NAGEUR ---
                    $stmtGetNageur->execute([$nom, $prenom]);
                    $nageur_id = $stmtGetNageur->fetchColumn();
                    if (!$nageur_id) {
                        $stmtAddNageur->execute([$nom, $prenom, $genre, $date_naissance]);
                        $nageur_id = $pdo->lastInsertId();
                    }

                    // --- INSERTION DE LA PERFORMANCE ---
                    if (!empty($temps_final)) {
                        $stmtAddPerf->execute([
                            $nageur_id,
                            $epreuve_id ?? null,
                            $categorie_id,
                            $lieu_id,
                            $saison,
                            $temps_final,
                            $date_texte,
                            $place
                        ]);
                        // rowCount() permet de vérifier si on a vraiment inséré (sinon IGNORE l'a skippé)
                        if ($stmtAddPerf->rowCount() > 0) {
                            $successCount++;
                        }
                    }
                }
            }

            // Redirection vers le dashboard avec un message de succès
            echo "<script>
                    alert('Traitement terminé ! {$successCount} nouvelle(s) performance(s) importée(s) en base de données.');
                    window.location.href = 'index.php?action=dashboard'; // ou l'url de votre tableau de bord
                  </script>";
            exit;
        } catch (Exception $e) {
            $error = "Erreur lors de la lecture du PDF ou de l'insertion BDD : " . $e->getMessage();
        }
    } else {
        $error = 'Veuillez sélectionner un fichier valide.';
    }
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Convertir PDF en Base de Données</title>
</head>

<body>
    <?php if ($error): ?>
    <p style="color: red; padding: 20px; font-weight: bold;"><?= htmlspecialchars($error) ?></p>
    <button onclick="window.history.back()">Retour</button>
    <?php endif; ?>
</body>

</html>