<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Performances du Club - Filtrage</title>
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
    }

    .table-responsive {
        overflow-x: auto;
        margin-bottom: 30px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
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

    .category-title {
        background-color: #e9ecef;
        padding: 10px 15px;
        border-left: 5px solid #007bff;
        color: #333;
        margin-top: 20px;
        font-size: 1.4em;
    }

    /* Style pour le menu déroulant */
    .filter-container {
        text-align: center;
        margin: 20px 0;
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #ddd;
    }

    select {
        padding: 10px;
        font-size: 16px;
        border-radius: 5px;
        border: 1px solid #ccc;
        outline: none;
        cursor: pointer;
        min-width: 250px;
    }
    </style>
</head>

<body>

    <div class="container">
        <h1 style="text-align:center;">🏆 Meilleurs Temps du Club 🏆</h1>

        <?php
    $host = 'localhost';
    $db   = 'ffessm_nap';
    $user = 'root';
    $pass = '';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = "
            SELECT 
                n.nom, 
                n.prenom, 
                c.nom_categorie AS categorie, 
                e.nom_epreuve AS epreuve, 
                MIN(p.temps) AS meilleur_temps
            FROM performances p
            JOIN nageurs n ON p.nageur_id = n.id
            JOIN epreuves e ON p.epreuve_id = e.id
            JOIN categories c ON p.categorie_id = c.id
            GROUP BY n.id, c.nom_categorie, e.id
            ORDER BY c.nom_categorie ASC, n.nom ASC, n.prenom ASC
        ";

        $stmt = $pdo->query($sql);
        $lignes_bdd = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($lignes_bdd)) {
            echo "<p style='text-align:center; color:#ff9800;'>⚠️ Aucune performance trouvée en base de données.</p>";
        } else {
            $profils_par_categorie = [];
            $epreuves_trouvees = [];

            foreach ($lignes_bdd as $ligne) {
                $categorie = $ligne['categorie'];
                $nom_complet = $ligne['nom'] . " " . $ligne['prenom'];
                
                if (!isset($profils_par_categorie[$categorie])) {
                    $profils_par_categorie[$categorie] = [];
                }
                if (!isset($profils_par_categorie[$categorie][$nom_complet])) {
                    $profils_par_categorie[$categorie][$nom_complet] = [
                        'nom' => $ligne['nom'],
                        'prenom' => $ligne['prenom'],
                        'chronos' => []
                    ];
                }
                
                $profils_par_categorie[$categorie][$nom_complet]['chronos'][$ligne['epreuve']] = $ligne['meilleur_temps'];
                
                if (!in_array($ligne['epreuve'], $epreuves_trouvees)) {
                    $epreuves_trouvees[] = $ligne['epreuve'];
                }
            }

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

            // --- NOUVEAU : LE MENU DÉROULANT ---
            echo "<div class='filter-container'>";
            echo "<label for='categoryFilter'><strong>🎯 Afficher la catégorie : </strong></label>";
            echo "<select id='categoryFilter' onchange='filterCategory()'>";
            echo "<option value='all'>Toutes les catégories</option>";
            // On crée une option pour chaque catégorie trouvée
            foreach (array_keys($profils_par_categorie) as $cat) {
                echo "<option value='" . htmlspecialchars($cat) . "'>" . htmlspecialchars($cat) . "</option>";
            }
            echo "</select>";
            echo "</div>";

            // --- AFFICHAGE DES TABLEAUX ---
            foreach ($profils_par_categorie as $categorie => $nageurs) {
                
                // NOUVEAU : On englobe chaque catégorie dans une "div" avec un attribut "data-category"
                echo "<div class='category-section' data-category='" . htmlspecialchars($categorie) . "'>";
                
                echo "<h2 class='category-title'>" . htmlspecialchars($categorie) . "</h2>";
                echo "<div class='table-responsive'>";
                echo "<table>";
                echo "<thead><tr><th>Nom</th><th>Prénom</th>";
                
                foreach ($colonnes_epreuves as $epreuve) {
                    echo "<th>" . htmlspecialchars($epreuve) . "</th>";
                }
                echo "</tr></thead><tbody>";

                foreach ($nageurs as $infos) {
                    echo "<tr>";
                    echo "<td><strong>" . htmlspecialchars($infos['nom']) . "</strong></td>";
                    echo "<td>" . htmlspecialchars($infos['prenom']) . "</td>";

                    foreach ($colonnes_epreuves as $epreuve) {
                        if (isset($infos['chronos'][$epreuve])) {
                            echo "<td class='temps'>" . htmlspecialchars($infos['chronos'][$epreuve]) . "</td>";
                        } else {
                            echo "<td class='vide'>-</td>";
                        }
                    }
                    echo "</tr>";
                }
                echo "</tbody></table>";
                echo "</div>"; // Fin table-responsive
                
                echo "</div>"; // Fin category-section
            }
        }
    } catch (PDOException $e) {
        echo "<p style='color:red; text-align:center;'>❌ Erreur de connexion à la BDD : " . $e->getMessage() . "</p>";
    }
    ?>
    </div>

    <script>
    function filterCategory() {
        // On récupère la valeur sélectionnée dans le menu déroulant
        var selectedCat = document.getElementById('categoryFilter').value;
        // On récupère tous les blocs contenant les tableaux
        var sections = document.getElementsByClassName('category-section');

        // On boucle sur tous les blocs
        for (var i = 0; i < sections.length; i++) {
            // Si "Toutes les catégories" est sélectionné, ou si le bloc correspond à la catégorie choisie
            if (selectedCat === 'all' || sections[i].getAttribute('data-category') === selectedCat) {
                sections[i].style.display = 'block'; // On affiche
            } else {
                sections[i].style.display = 'none'; // On cache
            }
        }
    }
    </script>

</body>

</html>