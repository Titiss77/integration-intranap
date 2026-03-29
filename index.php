<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Performances du Club - PEC</title>
    <style>
    body {
        font-family: Arial, sans-serif;
        background-color: #f4f7f6;
        padding: 20px;
    }

    .container {
        max-width: 1200px;
        margin: auto;
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        white-space: nowrap;
    }

    th,
    td {
        padding: 10px 15px;
        text-align: center;
        border-bottom: 1px solid #ddd;
        border-right: 1px solid #eee;
    }

    th:first-child,
    td:first-child,
    th:nth-child(2),
    td:nth-child(2) {
        text-align: left;
    }

    th {
        background-color: #007bff;
        color: white;
        position: sticky;
        top: 0;
    }

    tr:hover {
        background-color: #f1f1f1;
    }

    .vide {
        color: #ccc;
    }

    .temps {
        color: #d9534f;
        font-weight: bold;
    }
    </style>
</head>

<body>

    <div class="container">
        <h1 style="text-align:center;">🏆 Grille des Performances par Nageur 🏆</h1>

        <?php
        // Configuration de la base de données
        $host = 'localhost';
        $db = 'ffessm_nap';
        $user = 'root';
        $pass = '';

        try {
            // Connexion à la BDD via PDO
            $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Récupérer toutes les performances
            $stmt = $pdo->query('SELECT * FROM performances ORDER BY nom ASC, prenom ASC');
            $lignes_bdd = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($lignes_bdd)) {
                echo "<p style='text-align:center;'>Aucune performance trouvée en base de données.</p>";
            } else {
                // Reconstruire la logique par nageur
                $profils = [];
                $epreuves_trouvees = [];

                foreach ($lignes_bdd as $ligne) {
                    $nom_complet = $ligne['nom'] . ' ' . $ligne['prenom'];

                    // Initialiser le nageur s'il n'existe pas encore dans notre tableau PHP
                    if (!isset($profils[$nom_complet])) {
                        $profils[$nom_complet] = [
                            'nom' => $ligne['nom'],
                            'prenom' => $ligne['prenom'],
                            'categorie' => $ligne['categorie'],
                            'chronos' => []
                        ];
                    }

                    // Ajouter le chrono et l'épreuve à la liste des épreuves existantes
                    $profils[$nom_complet]['chronos'][$ligne['epreuve']] = $ligne['temps'];

                    if (!in_array($ligne['epreuve'], $epreuves_trouvees)) {
                        $epreuves_trouvees[] = $ligne['epreuve'];
                    }
                }

                // Ordre officiel des colonnes
                $ordre_officiel = [
                    '25SF', '50SF', '100SF', '200SF', '400SF', '800SF', '1500SF', '1850SF',
                    '25AP', '50AP', '100IS', '800IS', '200IS', '400IS', '50BI', '100BI', '200BI', '400BI'
                ];

                $colonnes_epreuves = [];
                foreach ($ordre_officiel as $epreuve) {
                    if (in_array($epreuve, $epreuves_trouvees)) {
                        $colonnes_epreuves[] = $epreuve;
                    }
                }

                // Génération du tableau HTML
                echo '<table>';
                echo '<thead><tr><th>Nom</th><th>Prénom</th><th>Catégorie</th>';
                foreach ($colonnes_epreuves as $epreuve) {
                    echo '<th>' . htmlspecialchars($epreuve) . '</th>';
                }
                echo '</tr></thead><tbody>';

                foreach ($profils as $infos) {
                    echo '<tr>';
                    echo '<td><strong>' . htmlspecialchars($infos['nom']) . '</strong></td>';
                    echo '<td>' . htmlspecialchars($infos['prenom']) . '</td>';
                    echo '<td>' . htmlspecialchars($infos['categorie']) . '</td>';

                    foreach ($colonnes_epreuves as $epreuve) {
                        if (isset($infos['chronos'][$epreuve])) {
                            echo "<td class='temps'>" . htmlspecialchars($infos['chronos'][$epreuve]) . '</td>';
                        } else {
                            echo "<td class='vide'>-</td>";
                        }
                    }
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        } catch (PDOException $e) {
            echo "<p style='color:red; text-align:center;'>Erreur de connexion à la BDD : " . $e->getMessage() . '</p>';
        }
        ?>
    </div>

</body>

</html>