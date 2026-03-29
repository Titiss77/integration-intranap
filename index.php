<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performances du Club - Dashboard</title>
    <style>
    /* --- VARIABLES & BASE --- */
    :root {
        --primary: #0056b3;
        --secondary: #007bff;
        --bg: #f4f7f6;
        --text: #333;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: var(--bg);
        color: var(--text);
        padding: 20px;
        line-height: 1.6;
    }

    .container {
        max-width: 95%;
        margin: auto;
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    }

    h1 {
        text-align: center;
        color: var(--primary);
        margin-bottom: 30px;
    }

    /* --- PANNEAU DE CONTRÔLE --- */
    .controls {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        justify-content: center;
        background: #e9ecef;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        align-items: center;
    }

    .control-item {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    input[type="text"],
    select {
        padding: 10px 15px;
        border: 1px solid #ccc;
        border-radius: 6px;
        font-size: 15px;
        min-width: 220px;
        transition: border 0.3s;
        outline: none;
    }

    input[type="text"]:focus,
    select:focus {
        border-color: var(--secondary);
        box-shadow: 0 0 5px rgba(0, 123, 255, 0.3);
    }

    /* --- TABLEAU UNIQUE --- */
    .table-responsive {
        overflow-x: auto;
        max-height: 70vh;
        margin-top: 15px;
        border-radius: 8px;
        border: 1px solid #dee2e6;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        white-space: nowrap;
    }

    th,
    td {
        padding: 12px 15px;
        text-align: center;
        border-bottom: 1px solid #eee;
        border-right: 1px solid #eee;
        vertical-align: middle;
    }

    /* Entêtes et colonnes figées */
    th {
        background-color: var(--primary);
        color: white;
        position: sticky;
        top: 0;
        z-index: 3;
    }

    td:nth-child(1),
    td:nth-child(2) {
        position: sticky;
        background-color: #fff;
        z-index: 1;
        font-weight: bold;
        box-shadow: 2px 0 5px rgba(0, 0, 0, 0.05);
    }

    td:nth-child(1) {
        left: 0;
    }

    td:nth-child(2) {
        left: 120px;
    }

    th:nth-child(1),
    th:nth-child(2) {
        z-index: 4;
        left: 0;
    }

    th:nth-child(2) {
        left: 120px;
    }

    tr:hover td {
        background-color: #f1f1f1;
    }

    .vide {
        color: #bbb;
        font-size: 0.9em;
    }

    /* --- NOUVEAU : SÉPARATEUR DE CATÉGORIE SUBTIL --- */
    .category-separator td {
        background-color: #fafafa !important;
        /* Fond très légèrement grisé */
        color: #555;
        font-weight: bold;
        font-style: italic;
        text-align: left;
        /* La fameuse ligne fine et grise en haut de la cellule */
        border-top: 2px solid #aaa;
        border-bottom: 1px solid #ddd;
        padding: 8px 15px;
        /* On s'assure qu'il reste collé à gauche au scroll horizontal */
        position: sticky;
        left: 0;
        z-index: 2;
    }

    /* --- REMPLACEMENT DU TEXTE AU SURVOL (CHRONOS) --- */
    .cell-temps {
        position: relative;
        cursor: pointer;
        min-width: 100px;
        transition: background-color 0.2s;
    }

    .cell-temps:hover {
        background-color: #e9ecef !important;
    }

    .chrono-val {
        color: #d9534f;
        font-family: monospace;
        font-size: 1.1em;
        font-weight: bold;
        display: block;
    }

    .chrono-info {
        display: none;
        font-size: 0.85em;
        color: #333;
        font-weight: normal;
        line-height: 1.3;
        white-space: normal;
    }

    .cell-temps:hover .chrono-val {
        display: none;
    }

    .cell-temps:hover .chrono-info {
        display: block;
        animation: fadeIn 0.2s ease-in-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }
    </style>
</head>

<body>

    <div class="container">
        <h1>🏆 Meilleurs Temps du Club 🏆</h1>

        <?php
    $host = 'localhost'; $db = 'ffessm_nap'; $user = 'root'; $pass = '';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // --- FILTRE SAISON ---
        $stmt_saisons = $pdo->query("SELECT DISTINCT saison FROM performances ORDER BY saison DESC");
        $annees_disponibles = $stmt_saisons->fetchAll(PDO::FETCH_COLUMN);
        $annee_selectionnee = isset($_GET['saison']) ? $_GET['saison'] : 'all';

        $condition_saison = ""; $params = [];
        if ($annee_selectionnee !== 'all') {
            $condition_saison = "AND p.saison = :saison";
            $params[':saison'] = $annee_selectionnee;
        }

        // --- REQUÊTE SQL ---
        $sql = "
            SELECT n.nom, n.prenom, c.nom_categorie AS categorie, e.nom_epreuve AS epreuve, 
                   p1.temps, p1.date_perf, l.nom_lieu AS lieu
            FROM performances p1
            JOIN nageurs n ON p1.nageur_id = n.id
            JOIN epreuves e ON p1.epreuve_id = e.id
            JOIN categories c ON p1.categorie_id = c.id
            JOIN lieux l ON p1.lieu_id = l.id
            JOIN (
                SELECT p.nageur_id, p.epreuve_id, MIN(p.temps) as min_temps
                FROM performances p
                WHERE 1=1 $condition_saison
                GROUP BY p.nageur_id, p.epreuve_id
            ) p2 ON p1.nageur_id = p2.nageur_id AND p1.epreuve_id = p2.epreuve_id AND p1.temps = p2.min_temps
            WHERE 1=1 " . str_replace("p.", "p1.", $condition_saison) . "
            ORDER BY c.nom_categorie ASC, n.nom ASC, n.prenom ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $lignes_bdd = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- PANNEAU DE CONTRÔLE ---
        echo "<div class='controls'>";
        echo "<form method='GET' class='control-item'><label>📅 <strong>Année :</strong></label><select name='saison' onchange='this.form.submit()'>";
        echo "<option value='all' " . ($annee_selectionnee === 'all' ? 'selected' : '') . ">Toutes les saisons</option>";
        foreach ($annees_disponibles as $annee) {
            $selected = ($annee_selectionnee == $annee) ? 'selected' : '';
            echo "<option value='" . htmlspecialchars($annee) . "' $selected>" . htmlspecialchars($annee) . "</option>";
        }
        echo "</select></form>";

        echo "<div class='control-item'><label>🎯 <strong>Catégorie :</strong></label><select id='categoryFilter' onchange='filterData()'><option value='all'>Toutes les catégories</option></select></div>";
        echo "<div class='control-item'><input type='text' id='searchInput' onkeyup='filterData()' placeholder='🔍 Rechercher...'></div>";
        echo "</div>";

        if (empty($lignes_bdd)) {
            echo "<p style='text-align:center; color:#ff9800; font-size:1.2em;'>⚠️ Aucun record trouvé pour l'année " . htmlspecialchars($annee_selectionnee) . ".</p>";
        } else {
            $profils_par_categorie = [];
            $epreuves_trouvees = [];

            foreach ($lignes_bdd as $ligne) {
                $categorie = $ligne['categorie'];
                $nom_complet = $ligne['nom'] . " " . $ligne['prenom'];
                
                if (!isset($profils_par_categorie[$categorie])) $profils_par_categorie[$categorie] = [];
                if (!isset($profils_par_categorie[$categorie][$nom_complet])) {
                    $profils_par_categorie[$categorie][$nom_complet] = ['nom' => $ligne['nom'], 'prenom' => $ligne['prenom'], 'chronos' => []];
                }
                
                $profils_par_categorie[$categorie][$nom_complet]['chronos'][$ligne['epreuve']] = [
                    'temps' => $ligne['temps'], 'date'  => $ligne['date_perf'], 'lieu'  => $ligne['lieu']
                ];
                
                if (!in_array($ligne['epreuve'], $epreuves_trouvees)) $epreuves_trouvees[] = $ligne['epreuve'];
            }

            $ordre_officiel = ["25SF", "50SF", "100SF", "200SF", "400SF", "800SF", "1500SF", "1850SF", "25AP", "50AP", "100IS", "800IS", "200IS", "400IS", "50BI", "100BI", "200BI", "400BI"];
            $colonnes_epreuves = array_intersect($ordre_officiel, $epreuves_trouvees);
            $total_colonnes = 2 + count($colonnes_epreuves); // Nom + Prénom + le nombre d'épreuves

            $options_js = "";
            foreach (array_keys($profils_par_categorie) as $cat) { $options_js .= "<option value='" . htmlspecialchars($cat, ENT_QUOTES) . "'>" . htmlspecialchars($cat, ENT_QUOTES) . "</option>"; }
            echo "<script>document.getElementById('categoryFilter').innerHTML += `$options_js`;</script>";

            // --- NOUVEAU : UN SEUL TABLEAU GLOBAL ---
            echo "<div class='table-responsive'><table id='mainTable'>";
            echo "<thead><tr><th>Nom</th><th>Prénom</th>";
            foreach ($colonnes_epreuves as $epreuve) echo "<th>" . htmlspecialchars($epreuve) . "</th>";
            echo "</tr></thead><tbody>";

            // Boucle sur les catégories pour insérer la ligne de séparation, puis les nageurs
            foreach ($profils_par_categorie as $categorie => $nageurs) {
                
                // LIGNE DE SÉPARATION SUBTILE
                echo "<tr class='category-separator' data-category='" . htmlspecialchars($categorie, ENT_QUOTES) . "'>";
                echo "<td colspan='$total_colonnes'>" . htmlspecialchars($categorie) . "</td>";
                echo "</tr>";

                // LIGNES DES NAGEURS
                foreach ($nageurs as $infos) {
                    echo "<tr class='nageur-row' data-category='" . htmlspecialchars($categorie, ENT_QUOTES) . "'>";
                    echo "<td><strong>" . htmlspecialchars($infos['nom']) . "</strong></td>";
                    echo "<td>" . htmlspecialchars($infos['prenom']) . "</td>";

                    foreach ($colonnes_epreuves as $epreuve) {
                        if (isset($infos['chronos'][$epreuve])) {
                            $perf = $infos['chronos'][$epreuve];
                            echo "<td class='cell-temps'>";
                            echo "<span class='chrono-val'>" . htmlspecialchars($perf['temps']) . "</span>";
                            echo "<span class='chrono-info'>📍 " . htmlspecialchars($perf['lieu']) . "<br>📅 " . htmlspecialchars($perf['date']) . "</span>";
                            echo "</td>";
                        } else {
                            echo "<td class='vide'>-</td>";
                        }
                    }
                    echo "</tr>";
                }
            }
            echo "</tbody></table></div>"; // Fin de l'unique tableau
        }
    } catch (PDOException $e) { echo "<p style='color:red;'>❌ Erreur de connexion à la BDD : " . $e->getMessage() . "</p>"; }
    ?>
    </div>

    <script>
    function filterData() {
        let searchValue = document.getElementById('searchInput').value.toLowerCase().trim();
        let categoryValue = document.getElementById('categoryFilter').value;

        let rows = document.querySelectorAll('.nageur-row');
        let separators = document.querySelectorAll('.category-separator');

        // On garde en mémoire quelles catégories ont au moins un nageur visible
        let categoriesVisibles = new Set();

        // 1. On gère les nageurs
        rows.forEach(row => {
            let rowCat = row.getAttribute('data-category');
            let nom = row.cells[0].textContent.toLowerCase();
            let prenom = row.cells[1].textContent.toLowerCase();

            let matchText = (nom + " " + prenom).includes(searchValue) || (prenom + " " + nom).includes(
                searchValue);
            let matchCategory = (categoryValue === 'all' || rowCat === categoryValue);

            if (matchText && matchCategory) {
                row.style.display = '';
                categoriesVisibles.add(rowCat); // On note que cette catégorie est active
            } else {
                row.style.display = 'none';
            }
        });

        // 2. On gère les lignes séparatrices
        separators.forEach(sep => {
            let sepCat = sep.getAttribute('data-category');
            // Si la catégorie a des nageurs visibles, on affiche la ligne grise, sinon on la cache
            if (categoriesVisibles.has(sepCat)) {
                sep.style.display = '';
            } else {
                sep.style.display = 'none';
            }
        });
    }
    </script>

</body>

</html>