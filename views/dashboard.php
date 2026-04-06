<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:,">
    <title>Performances du Club - Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/script.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>

    <div class="container">
        <h1>🏆 Meilleurs Temps du Club 🏆</h1>

        <div class="control-item">
            <button id="btnSync" onclick="lancerSync()"
                style="background-color: var(--secondary); color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%;">
                🔄 Synchroniser avec la FFESSM
            </button>
        </div>

        <div id="progressContainer"
            style="display: none; width: 100%; max-width: 600px; margin: 15px auto; text-align: center;">
            <div
                style="background-color: #e9ecef; border-radius: 8px; overflow: hidden; height: 25px; width: 100%; border: 1px solid #ccc;">
                <div id="progressBar"
                    style="height: 100%; width: 0%; background-color: #28a745; transition: width 0.3s; color: white; font-weight: bold; line-height: 25px;">
                    0%
                </div>
            </div>
            <p id="progressText" style="margin-top: 8px; font-size: 0.9em; color: #555; font-style: italic;">
                Démarrage...</p>
        </div>

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

            <select id='categoryFilter' onchange='filterData()'>
                <option value='all'>Toutes les catégories</option>
                <?php foreach ($categories_disponibles as $cat): ?>
                <option value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>"><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
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
                        <th>Profil</th> <?php foreach ($colonnes_epreuves as $epreuve): ?>
                        <th><?= htmlspecialchars($epreuve) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profils_nageurs as $infos): ?>
                    <tr class='nageur-row' data-category='<?= htmlspecialchars($infos['categorie'], ENT_QUOTES) ?>'>
                        <td><strong><?= htmlspecialchars($infos['nom']) ?></strong></td>
                        <td><?= htmlspecialchars($infos['prenom']) ?></td>

                        <td style="text-align: center; white-space: nowrap;">
                            <?= htmlspecialchars($infos['date_naissance_str']) ?>
                            <?= htmlspecialchars($infos['age_str']) ?><br>
                            <span style="font-size: 0.85em; font-weight: bold; color: var(--primary);">
                                <?= htmlspecialchars($infos['categorie']) ?>
                            </span>
                        </td>

                        <?php foreach ($colonnes_epreuves as $epreuve): ?>
                        <?php if (isset($infos['chronos'][$epreuve])): ?>
                        <?php $perf = $infos['chronos'][$epreuve]; ?>
                        <td class='cell-temps'
                            onclick='showChart(<?= $infos['nageur_id'] ?>, "<?= htmlspecialchars($epreuve) ?>", "<?= htmlspecialchars($infos['nom'] . ' ' . $infos['prenom']) ?>")'>
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
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <div id="chartModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeChart()">&times;</span>
            <h2 id="chartTitle">Évolution</h2>
            <canvas id="evolutionChart"></canvas>
        </div>
    </div>

</body>

</html>