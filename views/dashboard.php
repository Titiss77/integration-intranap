<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performances du Club - Dashboard</title>
    <style>
    /* [VOTRE CSS EXACTEMENT COMME AVANT - JE L'AI RÉDUIT ICI POUR LA LECTURE MAIS CONSERVEZ LE VÔTRE] */
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
        outline: none;
    }

    .table-responsive {
        overflow-x: auto;
        max-height: 70vh;
        margin-top: 15px;
        border-radius: 8px;
        border: 1px solid #dee2e6;
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
    }

    td:nth-child(1),
    th:nth-child(1) {
        left: 0;
    }

    td:nth-child(2),
    th:nth-child(2) {
        left: 120px;
    }

    th:nth-child(1),
    th:nth-child(2) {
        z-index: 4;
    }

    tr:hover td {
        background-color: #f1f1f1;
    }

    .vide {
        color: #bbb;
        font-size: 0.9em;
    }

    .category-separator td {
        background-color: #fafafa !important;
        color: #555;
        font-weight: bold;
        font-style: italic;
        text-align: left;
        border-top: 2px solid #aaa;
        border-bottom: 1px solid #ddd;
        position: sticky;
        left: 0;
        z-index: 2;
    }

    .cell-temps {
        position: relative;
        cursor: pointer;
        min-width: 100px;
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

        <div class='controls'>
            <form method='GET' class='control-item'>
                <label>📅 <strong>Année :</strong></label>
                <select name='saison' onchange='this.form.submit()'>
                    <option value='all' <?= ($annee_selectionnee === 'all' ? 'selected' : '') ?>>Toutes les saisons
                    </option>
                    <?php foreach ($annees_disponibles as $annee): ?>
                    <option value="<?= htmlspecialchars($annee) ?>"
                        <?= ($annee_selectionnee == $annee ? 'selected' : '') ?>>
                        <?= htmlspecialchars($annee) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class='control-item'>
                <label>🎯 <strong>Catégorie :</strong></label>
                <select id='categoryFilter' onchange='filterData()'>
                    <option value='all'>Toutes les catégories</option>
                    <?php foreach (array_keys($profils_par_categorie) as $cat): ?>
                    <option value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>"><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class='control-item'>
                <input type='text' id='searchInput' onkeyup='filterData()' placeholder='🔍 Rechercher...'>
            </div>
        </div>

        <?php if (empty($lignes_bdd)): ?>
        <p style='text-align:center; color:#ff9800; font-size:1.2em;'>
            ⚠️ Aucun record trouvé pour l'année <?= htmlspecialchars($annee_selectionnee) ?>.
        </p>
        <?php else: ?>
        <div class='table-responsive'>
            <table id='mainTable'>
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Prénom</th>
                        <?php foreach ($colonnes_epreuves as $epreuve): ?>
                        <th><?= htmlspecialchars($epreuve) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profils_par_categorie as $categorie => $nageurs): ?>

                    <tr class='category-separator' data-category='<?= htmlspecialchars($categorie, ENT_QUOTES) ?>'>
                        <td colspan='<?= $total_colonnes ?>'><?= htmlspecialchars($categorie) ?></td>
                    </tr>

                    <?php foreach ($nageurs as $infos): ?>
                    <tr class='nageur-row' data-category='<?= htmlspecialchars($categorie, ENT_QUOTES) ?>'>
                        <td><strong><?= htmlspecialchars($infos['nom']) ?></strong></td>
                        <td><?= htmlspecialchars($infos['prenom']) ?></td>

                        <?php foreach ($colonnes_epreuves as $epreuve): ?>
                        <?php if (isset($infos['chronos'][$epreuve])): ?>
                        <?php $perf = $infos['chronos'][$epreuve]; ?>
                        <td class='cell-temps'>
                            <span class='chrono-val'><?= htmlspecialchars($perf['temps']) ?></span>
                            <span class='chrono-info'>
                                📍 <?= htmlspecialchars($perf['lieu']) ?><br>
                                📅 <?= htmlspecialchars($perf['date']) ?>
                            </span>
                        </td>
                        <?php else: ?>
                        <td class='vide'>-</td>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <script>
    // [VOTRE JAVASCRIPT EXACTEMENT COMME AVANT]
    function filterData() {
        let searchValue = document.getElementById('searchInput').value.toLowerCase().trim();
        let categoryValue = document.getElementById('categoryFilter').value;

        let rows = document.querySelectorAll('.nageur-row');
        let separators = document.querySelectorAll('.category-separator');
        let categoriesVisibles = new Set();

        rows.forEach(row => {
            let rowCat = row.getAttribute('data-category');
            let nom = row.cells[0].textContent.toLowerCase();
            let prenom = row.cells[1].textContent.toLowerCase();

            let matchText = (nom + " " + prenom).includes(searchValue) || (prenom + " " + nom).includes(
                searchValue);
            let matchCategory = (categoryValue === 'all' || rowCat === categoryValue);

            if (matchText && matchCategory) {
                row.style.display = '';
                categoriesVisibles.add(rowCat);
            } else {
                row.style.display = 'none';
            }
        });

        separators.forEach(sep => {
            let sepCat = sep.getAttribute('data-category');
            sep.style.display = categoriesVisibles.has(sepCat) ? '' : 'none';
        });
    }
    </script>

</body>

</html>