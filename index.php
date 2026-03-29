<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performances du Club</title>
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
        max-width: 1000px;
        margin: auto;
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    th,
    td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }

    th {
        background-color: #007bff;
        color: white;
    }

    tr:hover {
        background-color: #f1f1f1;
    }

    .alert {
        padding: 15px;
        background-color: #ff9800;
        color: white;
        border-radius: 4px;
    }
    </style>
</head>

<body>

    <div class="container">
        <h1>🏆 Tableau des Performances 🏆</h1>

        <?php
        // 1. Chercher automatiquement un fichier JSON correspondant
        $fichiers_json = glob('profils_nageurs_*.json');

        if (empty($fichiers_json)) {
            echo "<div class='alert'>⚠️ Aucun fichier JSON n'a été trouvé dans ce dossier. Avez-vous bien lancé le script Python ?</div>";
        } else {
            // On prend le premier fichier trouvé (vous pouvez adapter si vous en avez plusieurs)
            $fichier_cible = $fichiers_json[0];
            echo '<p><em>Données chargées depuis : <strong>' . basename($fichier_cible) . '</strong></em></p>';

            // 2. Lire et décoder le fichier JSON
            $contenu_json = file_get_contents($fichier_cible);
            $profils = json_decode($contenu_json, true);

            if ($profils === null) {
                echo "<div class='alert'>❌ Erreur lors de la lecture du fichier JSON.</div>";
            } else {
                // 3. Construire l'entête du tableau HTML
                echo '<table>';
                echo '<thead>
                    <tr>
                        <th>Nom</th>
                        <th>Prénom</th>
                        <th>Catégorie</th>
                        <th>Épreuve</th>
                        <th>Temps</th>
                        <th>Date</th>
                        <th>Lieu</th>
                    </tr>
                  </thead>';
                echo '<tbody>';

                // 4. Parcourir le JSON pour générer les lignes du tableau
                foreach ($profils as $nom_complet => $infos) {
                    // S'il y a des performances, on les liste
                    if (!empty($infos['performances'])) {
                        foreach ($infos['performances'] as $perf) {
                            echo '<tr>';
                            // htmlspecialchars() permet d'éviter les failles XSS
                            echo '<td>' . htmlspecialchars($infos['nom']) . '</td>';
                            echo '<td>' . htmlspecialchars($infos['prenom']) . '</td>';
                            echo '<td>' . htmlspecialchars($infos['categorie']) . '</td>';
                            echo '<td><strong>' . htmlspecialchars($perf['epreuve']) . '</strong></td>';
                            echo "<td><span style='color:#d9534f; font-weight:bold;'>" . htmlspecialchars($perf['temps']) . '</span></td>';
                            echo '<td>' . htmlspecialchars($perf['date']) . '</td>';
                            echo '<td>' . htmlspecialchars($perf['lieu']) . '</td>';
                            echo '</tr>';
                        }
                    }
                }

                echo '</tbody>';
                echo '</table>';
            }
        }
        ?>

    </div>

</body>

</html>