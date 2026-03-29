<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Performances du Club - Vue par Nageur</title>
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
        <h1 style="text-align:center;">🏆 Grille des Meilleurs Temps par Nageur 🏆</h1>

        <?php
    // --- CONFIGURATION BASE DE DONNÉES ---
    $host = 'localhost';
    $db   = 'ffessm_nap';
    $user = 'root';
    $pass = ''; // Mettez votre mot de passe si vous en avez un sur votre serveur local

    try {
        // Connexion à la base avec PDO
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // --- LA REQUÊTE SQL MAGIQUE ---
        // On joint les 4 tables et on regroupe par nageur et par épreuve pour avoir le meilleur temps (MIN)
        $sql = "
            SELECT 
                n.nom, 
                n.prenom, 
                MAX(c.nom_categorie) AS categorie, 
                e.nom_epreuve AS epreuve, 
                MIN(p.temps) AS meilleur_temps
            FROM performances p
            JOIN nageurs n ON p.nageur_id = n.id
            JOIN epreuves e ON p.epreuve_id = e.id
            JOIN categories c ON p.categorie_id = c.id
            GROUP BY n.id, e.id
            ORDER BY n.nom ASC, n.prenom ASC
        ";

        $stmt = $pdo->query($sql);
        $lignes_bdd = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($lignes_bdd)) {
            echo "<p style='text-align:center; color:#ff9800;'>⚠️ Aucune performance trouvée en base de données.</p>";
        } else {
            // 1. Reconstruire la logique par nageur (Tableau croisé)
            $profils = [];
            $epreuves_trouvees = [];

            foreach ($lignes_bdd as $ligne) {
                $nom_complet = $ligne['nom'] . " " . $ligne['prenom'];
                
                // Si c'est la première fois qu'on voit ce nageur, on initialise sa ligne
                if (!isset($profils[$nom_complet])) {
                    $profils[$nom_complet] = [
                        'nom' => $ligne['nom'],
                        'prenom' => $ligne['prenom'],
                        'categorie' => $ligne['categorie'],
                        'chronos' => []
                    ];
                }
                
                // On ajoute son record sur l'épreuve
                $profils[$nom_complet]['chronos'][$ligne['epreuve']] = $ligne['meilleur_temps'];
                
                // On garde en mémoire les épreuves nagées au moins une fois pour construire les colonnes
                if (!in_array($ligne['epreuve'], $epreuves_trouvees)) {
                    $epreuves_trouvees[] = $ligne['epreuve'];
                }
            }

            // 2. Ordre officiel de la fédération pour les colonnes
            $ordre_officiel = [
                "25SF", "50SF", "100SF", "200SF", "400SF", "800SF", "1500SF", "1850SF",
                "25AP", "50AP", "100IS", "800IS", "200IS", "400IS", "50BI", "100BI", "200BI", "400BI"
            ];
            
            $colonnes_epreuves = [];
            foreach ($ordre_officiel as $epreuve) {
                if (in_array($epreuve, $epreuves_trouvees)) {
                    $colonnes_epreuves[] = $epreuve;
                }
            }

            // 3. Génération du tableau HTML
            echo "<table>";
            echo "<thead><tr><th>Nom</th><th>Prénom</th><th>Catégorie</th>";
            
            // Affichage des en-têtes (les courses)
            foreach ($colonnes_epreuves as $epreuve) {
                echo "<th>" . htmlspecialchars($epreuve) . "</th>";
            }
            echo "</tr></thead><tbody>";

            // Remplissage des cellules pour chaque nageur
            foreach ($profils as $infos) {
                echo "<tr>";
                echo "<td><strong>" . htmlspecialchars($infos['nom']) . "</strong></td>";
                echo "<td>" . htmlspecialchars($infos['prenom']) . "</td>";
                echo "<td>" . htmlspecialchars($infos['categorie']) . "</td>";

                foreach ($colonnes_epreuves as $epreuve) {
                    if (isset($infos['chronos'][$epreuve])) {
                        // Le nageur a un temps pour cette course
                        echo "<td class='temps'>" . htmlspecialchars($infos['chronos'][$epreuve]) . "</td>";
                    } else {
                        // Pas de temps = on affiche un tiret
                        echo "<td class='vide'>-</td>";
                    }
                }
                echo "</tr>";
            }
            echo "</tbody></table>";
        }
    } catch (PDOException $e) {
        // En cas d'erreur (mot de passe incorrect, base introuvable...)
        echo "<p style='color:red; text-align:center;'>❌ Erreur de connexion à la BDD : " . $e->getMessage() . "</p>";
    }
    ?>
    </div>

</body>

</html>