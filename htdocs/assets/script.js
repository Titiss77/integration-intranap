// --- 1. SYNCHRONISATION ---

async function lancerSync(tousLesTemps = false) {
    const btnSync = document.getElementById('btnSync');
    const btnAll = document.getElementById('btnSyncAllTimes');
    const btn = tousLesTemps ? btnAll : btnSync;
    const progressContainer = document.getElementById('progressContainer');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');

    if (!btn || !progressContainer || !progressBar || !progressText) return;
    btn.disabled = true;
    if (btnSync) btnSync.disabled = true;
    if (btnAll) btnAll.disabled = true;
    progressContainer.style.display = 'block';
    progressBar.style.width = '0%';
    progressBar.style.backgroundColor = 'var(--succes)';
    progressBar.innerText = '0%';
    progressText.innerText = 'Initialisation de la synchronisation...';

    const epreuves = ['50SF','100SF','200SF','400SF','800SF','1500SF','50AP','100IS','800IS','200IS','400IS','50BI','100BI','200BI','400BI'];
    const genres = ['F', 'M'];
    const selected = document.querySelector('select[name="saison"]')?.value || 'all';
    const now = new Date();
    const startYear = now.getMonth() >= 8 ? now.getFullYear() : now.getFullYear() - 1;
    const currentSeason = `${startYear}-${startYear + 1}`;
    const seasons = tousLesTemps && selected === 'all'
        ? [...new Set([...(SYNC_SEASONS || []), currentSeason])]
        : [selected === 'all' ? currentSeason : selected];
    if (tousLesTemps && !confirm(`La récupération va interroger ${seasons.length} saison(s) et peut prendre plusieurs minutes. Continuer ?`)) {
        if (btnSync) btnSync.disabled = false;
        if (btnAll) btnAll.disabled = false;
        progressContainer.style.display = 'none';
        return;
    }
    const tasks = [];
    for (const saison of seasons) {
        for (const epreuve of epreuves) {
            for (const genre of genres) tasks.push({saison, epreuve, genre});
        }
    }

    for (let i = 0; i < tasks.length; i++) {
        const task = tasks[i];
        const etape = i === 0 ? 'debut' : (i === tasks.length - 1 ? 'fin' : 'suite');
        progressText.innerText = `Synchronisation ${task.saison} : ${task.epreuve} (${task.genre === 'F' ? 'Femmes' : 'Hommes'})...`;
        try {
            const params = new URLSearchParams({token:CSRF_TOKEN, epreuve:task.epreuve, genre:task.genre, saison:task.saison, etape});
            const response = await fetch('index.php?action=sync', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: params.toString(),
                credentials: 'same-origin'
            });
            if (!response.ok) throw new Error(`Erreur HTTP ${response.status}`);
            const data = await response.json();
            if (data.error) throw new Error(data.message || 'Erreur de synchronisation');
            const percent = Math.round(((i + 1) / tasks.length) * 100);
            progressBar.style.width = `${percent}%`;
            progressBar.innerText = `${percent}%`;
        } catch (err) {
            console.error('Erreur synchronisation :', err);
            progressBar.style.backgroundColor = 'var(--danger)';
            progressText.innerText = `Erreur : ${err.message || 'Erreur r?seau.'}`;
            if (btnSync) btnSync.disabled = false;
            if (btnAll) btnAll.disabled = false;
            return;
        }
    }
    progressText.innerText = 'Synchronisation termin?e ! La page va se recharger.';
    if (btnSync) btnSync.disabled = false;
    if (btnAll) btnAll.disabled = false;
    setTimeout(() => location.reload(), 2000);
}


// --- 2. FILTRE DES NAGEURS ---

function filterData()
{
    let searchValue =
        document
            .getElementById(
                'searchInput'
            )
            .value
            .toLowerCase()
            .trim();

    let categoryValue =
        document
            .getElementById(
                'categoryFilter'
            )
            .value;

    let rows =
        document.querySelectorAll(
            '.nageur-row'
        );

    rows.forEach(
        row => {

            let rowCat =
                row.getAttribute(
                    'data-category'
                );

            let nageur =
                row.cells[0]
                    ? row.cells[0]
                        .textContent
                        .toLowerCase()
                    : "";

            let matchText =
                nageur.includes(
                    searchValue
                );

            let matchCategory =
                (
                    categoryValue === 'all' ||
                    rowCat === categoryValue
                );

            row.style.display =
                (
                    matchText &&
                    matchCategory
                )
                    ? ''
                    : 'none';
        }
    );

    const panes = Array.from(document.querySelectorAll('.tab-pane'));
    const activeTab = document.querySelector('.tab-btn.active');
    if (searchValue !== '' || categoryValue !== 'all') {
        panes.forEach(pane => { pane.style.display = 'block'; });
    } else {
        panes.forEach(pane => {
            pane.style.display = activeTab && pane.id === activeTab.dataset.target ? 'block' : 'none';
        });
    }
}


// --- 3. GRAPHIQUE DES PERFORMANCES ---

let myChart = null;

async function showChart(
    nageurId,
    epreuve,
    nomComplet,
    categorie = ''
) {

    document.getElementById(
        'chartModal'
    ).style.display =
        'block';

    const title = document.getElementById('chartTitle');

    document.getElementById(
        'chartTitle'
    ).innerText =
        "📈 Évolution : " +
        nomComplet;

    const url = new URL('index.php', window.location.href);
    url.searchParams.set('action', 'history');
    url.searchParams.set('nageur_id', String(nageurId));
    url.searchParams.set('epreuve', epreuve);

    const saisonSelect =
        document.querySelector('select[name="saison"]');

    if (saisonSelect) {
        url.searchParams.set('saison', saisonSelect.value);
    }

    if (
        categorie !== ''
    ) {

        url.searchParams.set('categorie', categorie);
    }

    try {

        let response = await fetch(url, {credentials: 'same-origin'});
        if (!response.ok) throw new Error(`Erreur HTTP ${response.status}`);

        let responseData =
            await response.json();

        if (!Array.isArray(responseData.history)) {
            throw new Error('Réponse d’historique invalide.');
        }
        if (typeof Chart === 'undefined') {
            throw new Error('Le module graphique est indisponible.');
        }

        let data =
            responseData.history;

        let tempsRefSec =
            responseData.temps_ref_sec;

        let tempsRefStr =
            responseData.temps_ref_str;

        const labels =
            data.map(
                d => d.date
            );

        const fullDetails =
            data.map(
                d =>
                    d.date +
                    " - " +
                    d.lieu
            );

        const values =
            data.map(
                d =>
                    d.temps_sec
            );

        const tooltips =
            data.map(
                d =>
                    d.temps_str
            );

        const ctx =
            document
                .getElementById(
                    'evolutionChart'
                )
                .getContext(
                    '2d'
                );

        if (
            myChart
        ) {

            myChart.destroy();
        }

        let datasets = [
            {
                label: 'Temps',
                data: values,
                borderColor: '#007bff',
                backgroundColor:
                    'rgba(0, 123, 255, 0.1)',
                borderWidth: 3,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointBackgroundColor:
                    '#0056b3',
                fill: true,
                tension: 0.2
            }
        ];

        if (
            tempsRefSec !== null
        ) {

            datasets.push({

                label:
                    'Objectif (' +
                    tempsRefStr +
                    ')',

                data:
                    data.map(
                        () =>
                            tempsRefSec
                    ),

                borderColor:
                    '#dc3545',

                borderWidth: 2,

                borderDash: [
                    5,
                    5
                ],

                pointRadius: 0,

                fill: false,

                tension: 0
            });
        }

        myChart =
            new Chart(
                ctx,
                {
                    type: 'line',

                    data: {
                        labels:
                            labels,

                        datasets:
                            datasets
                    },

                    options: {

                        responsive:
                            true,

                        maintainAspectRatio:
                            false,

                        plugins: {

                            legend: {
                                position:
                                    'bottom'
                            },

                            tooltip: {

                                callbacks: {

                                    title:
                                        function (
                                            context
                                        ) {

                                            return fullDetails[
                                                context[0]
                                                    .dataIndex
                                            ];
                                        },

                                    label:
                                        function (
                                            c
                                        ) {

                                            if (
                                                c.datasetIndex === 0
                                            ) {

                                                return (
                                                    " ⏱️ Chrono : " +
                                                    tooltips[
                                                        c.dataIndex
                                                    ]
                                                );

                                            }

                                            return (
                                                " 🎯 Objectif : " +
                                                tempsRefStr
                                            );
                                        }
                                }
                            }
                        },

                        scales: {

                            x: {

                                ticks: {

                                    maxRotation:
                                        45,

                                    minRotation:
                                        45
                                }
                            },

                            y: {

                                reverse:
                                    true,

                                title: {
                                    display:
                                        false
                                }
                            }
                        }
                    }
                }
            );

    } catch (
        error
    ) {

        console.error(
            "Erreur lors du chargement du graphique :",
            error
        );
        title.textContent = `Évolution indisponible : ${error.message}`;
    }
}

function closeChart()
{
    document.getElementById(
        'chartModal'
    ).style.display =
        'none';

    if (myChart) {
        myChart.destroy();
        myChart = null;
    }
}


// --- 4. EXPORT CSV ---

function exporterCsv()
{
    let saisonSelect =
        document.getElementById(
            'saisonSelect'
        );

    if (
        !saisonSelect
    ) {

        saisonSelect =
            document.querySelector(
                'select[name="saison"]'
            );
    }

    let saison =
        saisonSelect
            ? saisonSelect.value
            : 'all';

    const modeSelect = document.getElementById('displayMode');
    const params = new URLSearchParams({action: 'export', saison});
    if (modeSelect) params.set('affichage', modeSelect.value);
    window.location.href = `index.php?${params.toString()}`;
}


// --- 5. STATISTIQUES ---

function toggleStats()
{
    let tableContainer =
        document.getElementById(
            'tableContainer'
        );

    let statsContainer =
        document.getElementById(
            'statsContainer'
        );

    let btnToggle =
        document.getElementById(
            'btnToggleStats'
        );

    if (
        statsContainer.style.display ===
        'none'
    ) {

        statsContainer.style.display =
            'block';

        if (
            tableContainer
        ) {

            tableContainer.style.display =
                'none';
        }

        btnToggle.innerHTML =
            '📋 Retour au Tableau';

        btnToggle.style.backgroundColor =
            'var(--couleur-principale)';

    } else {

        statsContainer.style.display =
            'none';

        if (
            tableContainer
        ) {

            tableContainer.style.display =
                'block';
        }

        btnToggle.innerHTML =
            '📊 Afficher les Statistiques';

        btnToggle.style.backgroundColor =
            '#17a2b8';
    }
}


// --- 6. LOGS ---

async function voirLogs()
{
    const modal =
        document.getElementById(
            'logModal'
        );

    const container =
        document.getElementById(
            'logContent'
        );

    if (
        !modal ||
        !container
    ) {
        return;
    }

    modal.style.display =
        'block';

    container.innerHTML =
        '<div style="text-align:center; padding:20px;">Analyse de l\'historique...</div>';

    try {

        const response =
            await fetch(
                'index.php?action=get_logs'
            );

        if (!response.ok) {
            throw new Error(`Erreur HTTP ${response.status}`);
        }

        const rawText =
            await response.text();

        if (
            rawText.includes(
                "Aucun historique"
            ) ||
            rawText.trim() === ""
        ) {

            container.innerHTML =
                "<div style='padding:20px; text-align:center;'>Aucun historique de synchronisation pour le moment.</div>";

            return;
        }

        const lines =
            rawText
                .split('\n')
                .filter(
                    l =>
                        l.trim() !== ""
                );

        let sessions = [];

        let currentSession =
            null;

        lines.forEach(
            line => {

                if (
                    line.includes(
                        "--- DÉBUT"
                    )
                ) {

                    let dateMatch =
                        line.match(
                            /\[(.*?)\]/
                        );

                    let date =
                        dateMatch
                            ? dateMatch[1]
                            : "Date inconnue";

                    currentSession = {
                        date: date,
                        logs: []
                    };

                } else if (
                    line.includes(
                        "--- FIN"
                    )
                ) {

                    if (
                        currentSession
                    ) {

                        sessions.push(
                            currentSession
                        );

                        currentSession =
                            null;
                    }

                } else if (
                    currentSession
                ) {

                    if (
                        line
                            .trim()
                            .startsWith("[")
                    ) {

                        currentSession.logs.push(
                            line
                        );

                    } else if (
                        currentSession
                            .logs
                            .length > 0
                    ) {

                        currentSession
                            .logs[
                                currentSession
                                    .logs
                                    .length - 1
                            ] +=
                            " " +
                            line.trim();
                    }
                }
            }
        );

        if (
            currentSession
        ) {

            sessions.push(
                currentSession
            );
        }

        sessions =
            sessions.filter(
                session =>
                    session.logs.length > 0
            );

        sessions.reverse();

        let html = "";

        sessions.forEach(
            session => {

                html +=
                    `<div class="log-session">`;

                html +=
                    `<div class="log-session-title">📅 Synchronisation du ${session.date}</div>`;

                session.logs.forEach(
                    logLine => {

                        html +=
                            parseLogLine(
                                logLine
                            );
                    }
                );

                html +=
                    `</div>`;
            }
        );

        container.style.background =
            "transparent";

        container.innerHTML =
            html ||
            "<div style='padding:20px; text-align:center;'>Aucune modification trouvée dans l'historique récent.</div>";

    } catch (
        e
    ) {

        console.error(
            "Erreur d'affichage des logs:",
            e
        );

        container.innerHTML =
            "❌ Erreur de chargement de l'historique.";
    }
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    })[char]);
}

function parseLogLine(
    line
) {

    try {

        let dateMatch =
            line.match(
                /\[(.*?)\]/
            );

        let heure =
            dateMatch
                ? dateMatch[1]
                    .split(' ')[1]
                : "";

        let content =
            line
                .substring(
                    line.indexOf(']') + 1
                )
                .trim();

        let icon =
            "ℹ️";

        let css =
            "type-ajout";

        let label =
            "Information";

        let detailHtml = "";

        if (
            content.includes(
                "[NOUVEAU TEMPS]"
            )
        ) {

            icon =
                "⏱️";

            css =
                "type-temps";

            label =
                "Nouvelle performance";

            let rawData =
                content
                    .replace(
                        "[NOUVEAU TEMPS]",
                        ""
                    )
                    .trim();

            let parts =
                rawData.split('|');

            let namePart =
                parts[0]
                    ? parts[0].trim()
                    : "Nageur inconnu";

            let changePart =
                parts[1]
                    ? parts[1].trim()
                    : "";

            detailHtml =
                `<div class="log-name">${escapeHtml(namePart)}</div>` +
                `<div class="log-change">${escapeHtml(changePart)}</div>`;

        } else {

            detailHtml =
                `<div class="log-details">${escapeHtml(content.replace(/\[.*?\]/, '').trim())}</div>`;
        }

        return `
            <div class="log-card ${css}">

                <div class="log-icon">
                    ${icon}
                </div>

                <div class="log-body">

                    <div class="log-type">
                        ${label}
                    </div>

                    ${detailHtml}

                </div>

                <div
                    style="font-size:0.75rem; color:var(--texte-secondaire); min-width:40px; text-align:right; font-weight:bold;">

                    ${escapeHtml(heure)}

                </div>

            </div>
        `;

    } catch (
        err
    ) {

        console.error(
            "Erreur de parsing sur la ligne :",
            line,
            err
        );

        return `
            <div class="log-card type-ajout">

                <div class="log-details">
                    Détail technique :
                    ${escapeHtml(line)}
                </div>

            </div>
        `;
    }
}

function closeLogs()
{
    const modal =
        document.getElementById(
            'logModal'
        );

    if (
        modal
    ) {

        modal.style.display =
            'none';
    }
}


// --- 7. RGPD ---

document.addEventListener(
    "DOMContentLoaded",
    function () {

        if (
            !localStorage.getItem(
                "rgpd_accepted"
            )
        ) {

            const banner =
                document.getElementById(
                    "cookieBanner"
                );

            if (
                banner
            ) {

                banner.style.display =
                    "flex";

                banner.style.alignItems =
                    "center";

                banner.style.justifyContent =
                    "center";

                banner.style.flexWrap =
                    "wrap";

                banner.style.gap =
                    "10px";
            }
        }
    }
);

function acceptCookies()
{
    localStorage.setItem(
        "rgpd_accepted",
        "true"
    );

    const banner =
        document.getElementById(
            "cookieBanner"
        );

    if (
        banner
    ) {

        banner.style.display =
            "none";
    }
}

function openPrivacy(e)
{
    if (
        e
    ) {
        e.preventDefault();
    }

    document.getElementById(
        'privacyModal'
    ).style.display =
        'block';
}

function closePrivacy()
{
    document.getElementById(
        'privacyModal'
    ).style.display =
        'none';
}

function openPdfModal()
{
    document.getElementById(
        'pdfModal'
    ).style.display =
        'block';
}

function closePdfModal()
{
    document.getElementById(
        'pdfModal'
    ).style.display =
        'none';
}


// --- FERMETURE DES MODALES ---

document.addEventListener('click', function (event) {
    const tab = event.target.closest('.tab-btn[data-target]');
    if (tab) {
        openEpreuve({currentTarget: tab}, tab.dataset.target);
        filterData();
    }

    const cell = event.target.closest('.cell-temps[data-nageur-id]');
    if (cell) {
        showChart(cell.dataset.nageurId, cell.dataset.epreuve, cell.dataset.nom, cell.dataset.categorie);
    }
});

document.addEventListener('keydown', function (event) {
    const cell = event.target.closest('.cell-temps[data-nageur-id]');
    if (cell && (event.key === 'Enter' || event.key === ' ')) {
        event.preventDefault();
        showChart(cell.dataset.nageurId, cell.dataset.epreuve, cell.dataset.nom, cell.dataset.categorie);
    }
});

window.onclick =
    function (event) {

        let chartModal =
            document.getElementById(
                'chartModal'
            );

        let logModal =
            document.getElementById(
                'logModal'
            );

        let privacyModal =
            document.getElementById(
                'privacyModal'
            );

        let pdfModal =
            document.getElementById(
                'pdfModal'
            );

        if (
            event.target ===
            chartModal
        ) {

            closeChart();
        }

        if (
            event.target ===
            logModal
        ) {

            closeLogs();
        }

        if (
            event.target ===
            privacyModal
        ) {

            closePrivacy();
        }

        if (
            event.target ===
            pdfModal
        ) {

            closePdfModal();
        }
    };
