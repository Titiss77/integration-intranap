<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:,">
    <title>Performances du Club</title>
    <link rel="icon" type="image/x-icon" href="https://palmes-en-cornouailles.22web.org/favicon.ico">
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
                    <option value='all' <?php echo 'all' === $annee_selectionnee ? 'selected' : ''; ?>>Toutes les
                        saisons
                    </option>
                    <?php foreach ($annees_disponibles as $annee) { ?>
                    <option value="<?php echo htmlspecialchars($annee); ?>"
                        <?php echo $annee_selectionnee == $annee ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($annee); ?>
                    </option>
                    <?php } ?>
                </select>
            </form>

            <select id='categoryFilter' onchange='filterData()'>
                <option value='all'>Toutes les catégories</option>
                <?php foreach ($categories_disponibles as $cat_code => $cat_libelle) { ?>
                <option value="<?php echo htmlspecialchars($cat_code, ENT_QUOTES); ?>">
                    <?php if (!empty($cat_libelle)) { ?>
                    <?php echo htmlspecialchars($cat_libelle) . ' (' . htmlspecialchars($cat_code) . ')'; ?>
                    <?php } else { ?>
                    <?php echo htmlspecialchars($cat_code); ?>
                    <?php } ?>
                </option>
                <?php } ?>
            </select>
            <div class='control-item'>
                <input type='text' id='searchInput' onkeyup='filterData()' placeholder='🔍 Rechercher...'>
            </div>
        </div>

        <div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
            <div class='control-item' style="flex: 1;">
                <button type="button" onclick="exporterCsv()"
                    style="background-color: #28a745; color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%; height: 100%;">
                    📥 Exporter la sélection en CSV
                </button>
            </div>
            <div class='control-item' style="flex: 1;">
                <button type="button" id="btnToggleStats" onclick="toggleStats()"
                    style="background-color: #17a2b8; color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%; height: 100%;">
                    📊 Afficher les Statistiques
                </button>
            </div>
        </div>

        <div id="statsContainer"
            style="display: none; background: white; padding: 20px; border-radius: 8px; border: 1px solid var(--bordure); margin-bottom: 20px;">
            <h2 style="color: var(--couleur-principale); text-align: center; margin-bottom: 20px;">📊 Statistiques de la
                sélection</h2>

            <h3 style="color: #dc3545; font-size: medium; font-weight: 600; margin: 1rem;">* Attention il y a un
                problème au niveau de la FFESSM pour les nageurs qualifiés au 400IS (en <?php echo date('Y'); ?>)</h3>

            <div style="display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;">

                <div
                    style="flex: 1; min-width: 150px; background: #e9ecef; padding: 15px; border-radius: 8px; text-align: center;">
                    <h3 style="margin:0; font-size: 2.5em; color: var(--couleur-principale);">
                        <?php echo $statistiques['total_nageurs']; ?>
                    </h3>
                    <p style="margin:0; color: var(--texte-secondaire); font-weight: bold;">Nageurs</p>
                    <div style="font-size: 0.85em; color: #555; margin-top: 5px;">
                        👩 <?php echo $statistiques['filles']; ?> Filles | 👨 <?php echo $statistiques['garcons']; ?>
                        Garçons
                    </div>
                </div>

                <div
                    style="flex: 1; min-width: 150px; background: #e9ecef; padding: 15px; border-radius: 8px; text-align: center;">
                    <h3 style="margin:0; font-size: 2.5em; color: #28a745;">
                        <?php echo count($statistiques['nageurs_qualifies']); ?>
                    </h3>
                    <p style="margin:0; color: var(--texte-secondaire); font-weight: bold;">Nageurs Qualifiés</p>
                    <div style="font-size: 0.85em; color: #555; margin-top: 5px;">
                        Sur <?php echo $statistiques['total_qualifications']; ?> épreuves au total
                    </div>
                </div>

                <div
                    style="flex: 1; min-width: 150px; background: #e9ecef; padding: 15px; border-radius: 8px; text-align: center;">
                    <h3 style="margin:0; font-size: 2.5em; color: #17a2b8;">
                        <?php echo $statistiques['total_performances']; ?>
                    </h3>
                    <p style="margin:0; color: var(--texte-secondaire); font-weight: bold;">Performances Totales</p>
                </div>

            </div>

            <?php if (count($statistiques['nageurs_qualifies']) > 0): ?>
            <h3 style="color: var(--couleur-principale); margin-bottom: 10px;">🏅 Liste des nageurs qualifiés</h3>
            <ul style="list-style-type: none; padding: 0;">
                <?php foreach ($statistiques['nageurs_qualifies'] as $q): ?>
                <li style="padding: 10px; border-bottom: 1px solid #ddd;">
                    <strong
                        style="color: #28a745;"><?php echo htmlspecialchars($q['nom'] . ' ' . $q['prenom']); ?></strong>
                    <span
                        style="color: var(--couleur-secondaire);font-size: small;"><?php echo htmlspecialchars($q['categorie']); ?></span>
                    <span style="color: #555; font-size: 0.9em; margin-left: 10px;">(Qualifié sur :
                        <?php echo htmlspecialchars($q['epreuves']); ?>)</span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p style="text-align: center; color: #ff9800; font-weight: bold;">Aucun nageur qualifié n'a été trouvé.</p>
            <?php endif; ?>
        </div>

        <?php if (empty($lignes_bdd)) { ?>
        <p style='text-align:center; color:#ff9800; font-size:1.2em;'>
            ⚠️ Aucun record trouvé pour l'année <?php echo htmlspecialchars($annee_selectionnee); ?>.
        </p>
        <?php } else { ?>
        <div id='tableContainer' class='table-responsive'>
            <table id='mainTable'>
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Prénom</th>
                        <th>Profil</th> <?php foreach ($colonnes_epreuves as $epreuve) { ?>
                        <th><?php echo htmlspecialchars($epreuve); ?></th>
                        <?php } ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($profils_nageurs as $infos) { ?>
                    <tr class='nageur-row'
                        data-category='<?php echo htmlspecialchars($infos['categorie'], ENT_QUOTES); ?>'>
                        <td><strong><?php echo htmlspecialchars($infos['nom']); ?></strong></td>
                        <td><?php echo htmlspecialchars($infos['prenom']); ?></td>

                        <td style="text-align: center; white-space: nowrap;">
                            <?php echo htmlspecialchars($infos['date_naissance_str']); ?>
                            <?php echo htmlspecialchars($infos['age_str']); ?><br>

                            <?php if (!empty($infos['categorie_libelle'])): ?>
                            <span style="font-size: 0.85em; font-weight: bold; color: var(--primary);">
                                <?php echo htmlspecialchars($infos['categorie_libelle']); ?>
                            </span>
                            <?php else: ?>
                            <span style="font-size: 0.85em; font-weight: bold; color: var(--primary);">
                                <?php echo htmlspecialchars($infos['categorie']); ?>
                            </span>
                            <?php endif; ?>
                        </td>

                        <?php foreach ($colonnes_epreuves as $epreuve) { ?>
                        <?php if (isset($infos['chronos'][$epreuve])) { ?>
                        <?php $perf = $infos['chronos'][$epreuve]; ?>
                        <td class='cell-temps'
                            onclick='showChart(<?php echo $infos['nageur_id']; ?>, "<?php echo htmlspecialchars($epreuve); ?>", "<?php echo htmlspecialchars($infos['nom'] . ' ' . $infos['prenom']); ?>")'>
                            <?php
                            $color_style = '';
                            if ($perf['est_qualifie'] === true) {
                                $color_style = 'color: #28a745; font-weight: bold;';  // Vert (Qualifié)
                            } elseif ($perf['est_qualifie'] === false) {
                                $color_style = 'color: #dc3545;';  // Rouge (Non qualifié)
                            }
                            // Si $perf['est_qualifie'] === null, ça garde la couleur par défaut (Noir)
                            ?>
                            <span class='chrono-val' style='<?php echo $color_style; ?>'>
                                <?php echo htmlspecialchars($perf['temps']); ?>
                            </span>
                            <span class='chrono-info'>
                                📍 <?php echo htmlspecialchars($perf['lieu']); ?><br>
                                📅 <?php echo htmlspecialchars($perf['date']); ?><br>
                                <?php if (!empty($perf['classement'])) { ?>
                                🏅 Classé <strong><?php echo $perf['classement']; ?>e</strong> FR
                                <?php } ?>
                            </span>
                        </td>
                        <?php } else { ?>
                        <td class='vide'>-</td>
                        <?php } ?>
                        <?php } ?>
                    </tr>

                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
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