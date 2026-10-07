// --- 1. SYNCHRONISATION ---

async function lancerSync() {

    const btn =
        document.getElementById('btnSync');

    const progressContainer =
        document.getElementById(
            'progressContainer'
        );

    const progressBar =
        document.getElementById(
            'progressBar'
        );

    const progressText =
        document.getElementById(
            'progressText'
        );

    btn.disabled = true;

    btn.style.backgroundColor =
        "#ccc";

    progressContainer.style.display =
        'block';

    progressBar.style.width =
        '0%';

    progressBar.style.backgroundColor =
        'var(--succes)';

    progressBar.innerText =
        '0%';

    progressText.innerText =
        'Initialisation de la synchronisation...';

    const liste_epreuves = [
        '50SF',
        '100SF',
        '200SF',
        '400SF',
        '800SF',
        '1500SF',
        '50AP',
        '100IS',
        '800IS',
        '200IS',
        '400IS',
        '50BI',
        '100BI',
        '200BI',
        '400BI'
    ];

    const genres = [
        'F',
        'M'
    ];

    const tasks = [];

    for (
        let epreuve of liste_epreuves
    ) {

        for (
            let genre of genres
        ) {

            tasks.push({
                epreuve: epreuve,
                genre: genre
            });
        }
    }

    const totalSteps =
        tasks.length;

    let currentStep = 0;

    for (
        let i = 0;
        i < totalSteps;
        i++
    ) {

        let task =
            tasks[i];

        let etapeStr =
            'suite';

        if (
            i === 0
        ) {
            etapeStr =
                'debut';
        }

        if (
            i === totalSteps - 1
        ) {
            etapeStr =
                'fin';
        }

        progressText.innerText =
            `Synchronisation en cours : ${task.epreuve} (${task.genre === 'F' ? 'Femmes' : 'Hommes'})...`;

        try {

            let url =
                `index.php?action=sync&token=${encodeURIComponent(CSRF_TOKEN)}&epreuve=${task.epreuve}&genre=${task.genre}&etape=${etapeStr}`;

            let response =
                await fetch(url);

            let data =
                await response.json();

            if (
                data.error
            ) {

                progressBar.style.backgroundColor =
                    'var(--danger)';

                progressText.innerText =
                    "Erreur : " +
                    data.message;

                btn.disabled =
                    false;

                btn.style.backgroundColor =
                    "var(--couleur-secondaire)";

                return;
            }

            currentStep++;

            let percent =
                Math.round(
                    (
                        currentStep /
                        totalSteps
                    ) *
                    100
                );

            progressBar.style.width =
                percent + '%';

            progressBar.innerText =
                percent + '%';

        } catch (
            err
        ) {

            console.error(
                "Erreur synchronisation :",
                err
            );

            progressBar.style.backgroundColor =
                'var(--danger)';

            progressText.innerText =
                "Erreur réseau. L'hébergeur a peut-être bloqué la requête.";

            btn.disabled =
                false;

            btn.style.backgroundColor =
                "var(--couleur-secondaire)";

            return;
        }
    }

    progressText.innerHTML =
        "<strong>Synchronisation terminée ! La page va se recharger.</strong>";

    setTimeout(
        () => {
            location.reload();
        },
        2000
    );
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

    document.getElementById(
        'chartTitle'
    ).innerText =
        "📈 Évolution : " +
        nomComplet;

    let url =
        'index.php?action=history' +
        '&nageur_id=' +
        nageurId +
        '&epreuve=' +
        epreuve;

    if (
        categorie !== ''
    ) {

        url +=
            '&categorie=' +
            encodeURIComponent(
                categorie
            );
    }

    try {

        let response =
            await fetch(url);

        let responseData =
            await response.json();

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
    }
}

function closeChart()
{
    document.getElementById(
        'chartModal'
    ).style.display =
        'none';
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

    window.location.href =
        'index.php?action=export&saison=' +
        encodeURIComponent(
            saison
        );
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
                `<div class="log-name">${namePart}</div>` +
                `<div class="log-change">${changePart}</div>`;

        } else {

            detailHtml =
                `<div class="log-details">${content.replace(/\[.*?\]/, '').trim()}</div>`;
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

                    ${heure}

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
                    ${line}
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