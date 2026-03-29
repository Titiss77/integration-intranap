<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performances du Club - Vue par Nageur</title>
    <style>
    body {
        font-family: Arial, sans-serif;
        background-color: #f4f7f6;
        padding: 20px;
    }

    h1 {
        color: #333;
        text-align: center;
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

    .alert {
        padding: 15px;
        background-color: #ff9800;
        color: white;
        border-radius: 4px;
        text-align: center;
    }
    </style>
</head>

<body>

    <div class="container">
        <h1>🏆 Grille des Performances par Nageur 🏆</h1>

        <?php
    $fichiers_json = glob("profils_nageurs_*.json");

    if (empty($fichiers_json)) {
        echo "<div class='alert'>⚠️ Aucun fichier JSON n'a été trouvé dans ce dossier.</div>";
    } else {
        $fichier_cible = $fichiers_json[0];
        echo "<p style='text-align:center;'><em>Données chargées depuis : <strong>" . basename($fichier_cible) . "</strong></em></p>";

        $contenu_json = file_get_contents($fichier_cible);
        $profils = json_decode($contenu_json, true);

        if ($profils === null) {
            echo "<div class='alert'>❌ Erreur lors de la lecture du fichier JSON.</div>";
        } else {
            // 1. Identifier toutes les épreuves existantes pour créer les colonnes
            $ordre_officiel = [
                "25SF", "50SF", "100SF", "200SF", "400SF", "800SF", "1500SF", "1850SF",
                "25AP", "50AP", "100IS", "800IS", "200IS", "400IS", "50BI", "100BI", "200BI", "400BI"
            ];
            
            $epreuves_trouvees = [];
            foreach ($profils as $infos) {
                if (!empty($infos['performances'])) {
                    foreach ($infos['performances'] as $perf) {
                        if (!in_array($perf['epreuve'], $epreuves_trouvees)) {
                            $epreuves_trouvees[] = $perf['epreuve'];
                        }
                    }
                }
            }

            // Trier les colonnes d'épreuves selon l'ordre officiel de la fédération
            $colonnes_epreuves = [];
            foreach ($ordre_officiel as $epreuve) {
                if (in_array($epreuve, $epreuves_trouvees)) {
                    $colonnes_epreuves[] = $epreuve;
                }
            }

            // 2. Construire l'entête du tableau
            echo "<table>";
            echo "<thead><tr>";
            echo "<th>Nom</th>";
            echo "<th>Prénom</th>";
            echo "<th>Catégorie</th>";
            
            foreach ($colonnes_epreuves as $epreuve) {
                echo "<th>" . htmlspecialchars($epreuve) . "</th>";
            }
            echo "</tr></thead><tbody>";

            // 3. Remplir les lignes pour chaque nageur
            foreach ($profils as $nom_complet => $infos) {
                // Créer un tableau associatif avec [ "50SF" => "00:20.54", "100BI" => "00:51.22" ]
                $chronos_du_nageur = [];
                if (!empty($infos['performances'])) {
                    foreach ($infos['performances'] as $perf) {
                        $chronos_du_nageur[$perf['epreuve']] = $perf['temps'];
                    }
                }

                // Afficher la ligne uniquement s'il a au moins un chrono
                if (!empty($chronos_du_nageur)) {
                    echo "<tr>";
                    echo "<td><strong>" . htmlspecialchars($infos['nom']) . "</strong></td>";
                    echo "<td>" . htmlspecialchars($infos['prenom']) . "</td>";
                    echo "<td>" . htmlspecialchars($infos['categorie']) . "</td>";

                    // Afficher les temps dans la bonne colonne
                    foreach ($colonnes_epreuves as $epreuve) {
                        if (isset($chronos_du_nageur[$epreuve])) {
                            echo "<td class='temps'>" . htmlspecialchars($chronos_du_nageur[$epreuve]) . "</td>";
                        } else {
                            echo "<td class='vide'>-</td>"; // Tiret si l'épreuve n'a pas été nagée
                        }
                    }
                    echo "</tr>";
                }
            }

            echo "</tbody></table>";
        }
    }
    ?>

    </div>

</body>

</html>