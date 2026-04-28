<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Performances du Club</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="icon" type="image/x-icon" href="https://palmes-en-cornouailles.22web.org/favicon.ico">
    <script src="assets/script.js" defer></script>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
    const CSRF_TOKEN = "<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>";
    </script>
</head>

<body>
    <div class="container">
        <h1>🏆 Meilleurs Temps du PEC</h1>

        <div style="margin-bottom: 20px;">
            <button id="btnSync" class="btn-primary" onclick="lancerSync()">
                🔄 Synchroniser avec la FFESSM
            </button>
            <button onclick="voirLogs()"
                style="background: none; border: 1px solid var(--bordure); padding: 4px 10px; font-size: 0.8rem; border-radius: 6px; color: var(--texte-secondaire); width: auto;">
                📜 Voir les dernières modifs
            </button>
        </div>

        <div id="progressContainer" style="display: none; width: 100%; margin: 15px 0; text-align: center;">
            <div
                style="background-color: var(--fond-page); border-radius: 8px; overflow: hidden; height: 25px; border: 1px solid var(--bordure);">
                <div id="progressBar"
                    style="height: 100%; width: 0%; background-color: var(--succes); transition: width 0.3s; color: white; font-weight: bold; line-height: 25px; font-size: 0.9rem;">
                    0%</div>
            </div>
            <p id="progressText"
                style="margin-top: 8px; font-size: 0.9em; color: var(--texte-secondaire); font-style: italic;">
                Démarrage...</p>
        </div>

        <div class="controls">
            <form method="GET" style="display: flex; align-items: center; gap: 10px; width: 100%;">
                <label style="white-space: nowrap;">📅 <strong>Année :</strong></label>
                <select name="saison" onchange="this.form.submit()" style="flex: 1;">
                    <option value="all" <?php echo 'all' === $annee_selectionnee ? 'selected' : ''; ?>>Toutes les
                        saisons</option>
                    <?php foreach ($annees_disponibles as $annee) { ?>
                    <option value="<?php echo htmlspecialchars($annee); ?>"
                        <?php echo $annee_selectionnee == $annee ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($annee); ?></option>
                    <?php } ?>
                </select>
            </form>

            <select id="categoryFilter" onchange="filterData()">
                <option value="all">Toutes les catégories</option>
                <?php foreach ($categories_disponibles as $cat_code => $cat_libelle) { ?>
                <option value="<?php echo htmlspecialchars($cat_code, ENT_QUOTES); ?>">
                    <?php echo !empty($cat_libelle) ? htmlspecialchars($cat_libelle) . ' (' . htmlspecialchars($cat_code) . ')' : htmlspecialchars($cat_code); ?>
                </option>
                <?php } ?>
            </select>

            <input type="text" id="searchInput" onkeyup="filterData()" placeholder="🔍 Rechercher un nageur...">
        </div>

        <div class="actions-bar">
            <div><button type="button" class="btn-success" onclick="exporterCsv()">📥 Exporter en CSV</button></div>
            <div><button type="button" id="btnToggleStats" class="btn-info" onclick="toggleStats()">📊 Afficher les
                    Statistiques</button></div>
        </div>

        <div id="statsContainer"
            style="display: none; background: white; padding: 20px; border-radius: 8px; border: 1px solid var(--bordure); margin-bottom: 20px;">
            <h2 style="color: var(--couleur-principale); text-align: center; margin-bottom: 20px;">📊 Statistiques de la
                sélection</h2>

            <?php if ('all' !== $annee_selectionnee) { ?>
            <h3 style="color: #dc3545; font-size: medium; font-weight: 600; margin: 1rem;">* Attention il y a un
                problème au niveau de la FFESSM pour les nageurs qualifiés au 400IS (en <?php echo date('Y'); ?>)</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3 style="color: var(--couleur-principale);"><?php echo $statistiques['total_nageurs']; ?></h3>
                    <p>Nageurs</p>
                    <div style="font-size: 0.85em; color: var(--texte-secondaire); margin-top: 5px;">👩
                        <?php echo $statistiques['filles']; ?> Filles | 👨 <?php echo $statistiques['garcons']; ?>
                        Garçons</div>
                </div>
                <div class="stat-card">
                    <h3 style="color: var(--succes);"><?php echo count($statistiques['nageurs_qualifies']); ?></h3>
                    <p>Nageurs Qualifiés</p>
                    <div style="font-size: 0.85em; color: var(--texte-secondaire); margin-top: 5px;">Sur
                        <?php echo $statistiques['total_qualifications']; ?> épreuves au total</div>
                </div>
                <div class="stat-card">
                    <h3 style="color: var(--info);"><?php echo $statistiques['total_performances']; ?></h3>
                    <p>Performances Totales</p>
                </div>
            </div>
            <?php if (count($statistiques['nageurs_qualifies']) > 0) { ?>
            <h3 style="color: var(--couleur-principale); margin-bottom: 15px;">🏅 Liste des nageurs qualifiés</h3>
            <ul style="list-style-type: none; padding: 0;">
                <?php foreach ($statistiques['nageurs_qualifies'] as $q) { ?>
                <li
                    style="padding: 12px; border-bottom: 1px solid var(--bordure); display: flex; flex-direction: column; gap: 4px;">
                    <div>
                        <strong
                            style="color: var(--succes); font-size: 1.1rem;"><?php echo htmlspecialchars($q['nom'] . ' ' . $q['prenom']); ?></strong>
                        <span
                            style="background: var(--fond-page); padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; margin-left: 8px; color: var(--texte-principal);"><?php echo htmlspecialchars($q['categorie']); ?></span>
                    </div>
                    <span style="color: var(--texte-secondaire); font-size: 0.9em;">🎯 Qualifié sur :
                        <strong><?php echo htmlspecialchars($q['epreuves']); ?></strong></span>
                </li>
                <?php } ?>
            </ul>
            <?php } else { ?>
            <p style="text-align: center; color: var(--avertissement); font-weight: bold; padding: 20px;">Aucun nageur
                qualifié n'a été trouvé.</p>
            <?php } ?>
            <?php } else { ?>
            <p style="text-align: center; color: var(--avertissement); font-weight: bold; padding: 20px;">Les
                statistiques ne sont pas disponibles pour "Toutes les saisons".</p>
            <?php } ?>
        </div>

        <?php if (empty($lignes_bdd)) { ?>
        <p style='text-align:center; color: var(--avertissement); font-size:1.2em; padding: 40px;'>⚠️ Aucun record
            trouvé pour l'année <?php echo htmlspecialchars($annee_selectionnee); ?>.</p>
        <?php } else { ?>

        <div id='tableContainer'>
            <div class="tabs-ffessm">
                <?php $premiere = true;
                foreach ($colonnes_epreuves as $epreuve) { ?>
                <button class="tab-btn <?php echo $premiere ? 'active' : ''; ?>"
                    onclick="openEpreuve(event, 'ep-<?php echo $epreuve; ?>')">
                    <?php echo htmlspecialchars($epreuve); ?>
                </button>
                <?php $premiere = false;
                } ?>
            </div>

            <div class="tabs-content-ffessm">
                <?php
                $premiere = true;
                foreach ($colonnes_epreuves as $epreuve) {
                    $perfs = $performances_par_epreuve[$epreuve];
                    ?>
                <div id="ep-<?php echo $epreuve; ?>" class="tab-pane"
                    style="display: <?php echo $premiere ? 'block' : 'none'; ?>;">
                    <h2 style="color: var(--couleur-principale); margin-bottom: 20px; text-align: center;">🥇 Classement
                        <?php echo htmlspecialchars($epreuve); ?></h2>

                    <table class="table-rank">
                        <thead>
                            <tr>
                                <th style="width: 50px;">Clt</th>
                                <th>Nageur</th>
                                <th>Catégorie</th>
                                <th>Temps</th>
                                <th>Date</th>
                                <th>Lieu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($perfs as $index => $perf) {
                                $color = '';
                                if (true === $perf['est_qualifie']) {
                                    $color = 'color: var(--succes); font-weight:bold;';
                                } elseif (false === $perf['est_qualifie']) {
                                    $color = 'color: var(--danger);';
                                }
                                ?>
                            <tr class="nageur-row"
                                data-category="<?php echo htmlspecialchars($perf['categorie'], ENT_QUOTES); ?>">
                                <td data-label="Classement"
                                    style="font-weight: bold; color: var(--couleur-principale); font-size: 1.1em;">
                                    #<?php echo $index + 1; ?>
                                </td>
                                <td data-label="Nageur">
                                    <div style="text-align: left;">
                                        <strong
                                            style="color: var(--texte-principal); display: block; font-size: 1.05rem;"><?php echo htmlspecialchars($perf['nom']); ?></strong>
                                        <span
                                            style="color: var(--texte-secondaire); font-size: 0.9rem;"><?php echo htmlspecialchars($perf['prenom']); ?></span>
                                    </div>
                                </td>
                                <td data-label="Catégorie">
                                    <span
                                        style="background: var(--couleur-principale); color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; font-weight: bold;">
                                        <?php echo htmlspecialchars($perf['categorie']); ?>
                                    </span>
                                </td>
                                <td data-label="Temps" class="cell-temps"
                                    onclick='showChart(<?php echo $perf['nageur_id']; ?>, "<?php echo htmlspecialchars($epreuve); ?>", "<?php echo htmlspecialchars($perf['nom'] . ' ' . $perf['prenom']); ?>", "<?php echo htmlspecialchars($perf['categorie']); ?>")'>
                                    <?php
                                    // Définition de la couleur selon la qualification
                                    $color = 'color: var(--texte-principal);';
                                    if (true === $perf['est_qualifie']) {
                                        $color = 'color: var(--succes);';
                                    } elseif (false === $perf['est_qualifie']) {
                                        $color = 'color: var(--danger);';
                                    }
                                    ?>

                                    <div class="btn-evolution" style="<?php echo $color; ?>" title="Voir l'évolution">
                                        <span><?php echo htmlspecialchars($perf['temps']); ?></span>
                                        <span class="icon">📊</span>
                                    </div>

                                    <?php if (!empty($perf['classement'])) { ?>
                                    <div>
                                        <small class="classement-badge">🏅 <?php echo $perf['classement']; ?>e</small>
                                    </div>
                                    <?php } ?>
                                </td>
                                <td data-label="Date" style="color: var(--texte-secondaire); font-size: 0.9em;">
                                    <?php echo htmlspecialchars($perf['date_perf']); ?>
                                </td>
                                <td data-label="Lieu" style="color: var(--texte-secondaire); font-size: 0.9em;">
                                    <?php echo htmlspecialchars($perf['lieu']); ?>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php $premiere = false;
                } ?>
            </div>
        </div>
        <?php } ?>
    </div>

    <div id="chartModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeChart()">&times;</span>
            <h2 id="chartTitle"
                style="margin-bottom: 20px; font-size: 1.3rem; color: var(--couleur-principale); padding-right: 40px;">
                Évolution</h2>
            <div style="position: relative; width: 100%; height: 60vh; min-height: 350px;">
                <canvas id="evolutionChart"></canvas>
            </div>
        </div>
    </div>

    <div id="logContent" style="padding: 15px; border-radius: 8px; max-height: 60vh; overflow-y: auto;">
        <div class="modal-content" style="max-width: 700px;">
            <span class="close-btn" onclick="closeLogs()">&times;</span>
            <h2 style="color: var(--couleur-principale); margin-bottom: 15px; font-size: 1.3rem;">📜 Historique des
                synchronisations</h2>
            <div id="logContent"
                style="background: #1e1e1e; color: #a6e22e; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 0.85rem; max-height: 50vh; overflow-y: auto; text-align: left; line-height: 1.4;">
                Chargement...
            </div>
        </div>
    </div>
    <script>
    function openEpreuve(evt, epreuveId) {
        let tabPanes = document.getElementsByClassName("tab-pane");
        for (let i = 0; i < tabPanes.length; i++) tabPanes[i].style.display = "none";

        let tabBtns = document.getElementsByClassName("tab-btn");
        for (let i = 0; i < tabBtns.length; i++) tabBtns[i].className = tabBtns[i].className.replace(" active", "");

        document.getElementById(epreuveId).style.display = "block";
        evt.currentTarget.className += " active";
    }

    function filterData() {
        let searchValue = document.getElementById('searchInput').value.toLowerCase().trim();
        let categoryValue = document.getElementById('categoryFilter').value;
        let rows = document.querySelectorAll('.nageur-row');

        rows.forEach(row => {
            let rowCat = row.getAttribute('data-category');
            // Le nom et prénom sont dans la 2ème cellule (index 1)
            let nageur = row.cells[1] ? row.cells[1].textContent.toLowerCase() : "";

            let matchText = nageur.includes(searchValue);
            let matchCategory = (categoryValue === 'all' || rowCat === categoryValue);

            row.style.display = (matchText && matchCategory) ? '' : 'none';
        });
    }
    </script>
</body>

</html>