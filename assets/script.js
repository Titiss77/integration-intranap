// --- 1. FONCTION DE SYNCHRONISATION ---
function lancerSync() {
    const btn = document.getElementById('btnSync');
    const progressContainer = document.getElementById('progressContainer');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');

    btn.disabled = true;
    btn.style.backgroundColor = "#ccc";
    progressContainer.style.display = 'block';
    progressBar.style.width = '0%';
    progressBar.style.backgroundColor = '#28a745';
    progressBar.innerText = '0%';
    progressText.innerText = 'Connexion à la base FFESSM...';

    
const evtSource = new EventSource("index.php?action=sync&token=" + encodeURIComponent(CSRF_TOKEN));

    evtSource.onmessage = function(event) {
        const data = JSON.parse(event.data);

        if (data.error) {
            progressBar.style.backgroundColor = '#d9534f';
            progressText.innerText = data.message;
            evtSource.close();
            btn.disabled = false;
            btn.style.backgroundColor = "var(--secondary)";
            return;
        }

        progressBar.style.width = data.progress + '%';
        progressBar.innerText = data.progress + '%';
        progressText.innerText = data.message;

        if (data.done) {
            evtSource.close();
            progressBar.style.backgroundColor = '#28a745';
            progressText.innerHTML = "<strong>✅ " + data.message + " La page va se recharger.</strong>";
            setTimeout(() => { location.reload(); }, 2000);
        }
    };

    evtSource.onerror = function(event) {
        if (evtSource.readyState === EventSource.CLOSED) {
            progressBar.style.backgroundColor = '#d9534f';
            progressText.innerText = "❌ Connexion perdue avec le serveur.";
            evtSource.close();
            btn.disabled = false;
            btn.style.backgroundColor = "var(--secondary)";
        } else {
            progressText.innerText = "⚠️ Reconnexion en cours...";
        }
    };
}

// --- 2. FONCTION POUR LE FILTRE DES NAGEURS ---
function filterData() {
    let searchValue = document.getElementById('searchInput').value.toLowerCase().trim();
    let categoryValue = document.getElementById('categoryFilter').value;
    let rows = document.querySelectorAll('.nageur-row');

    rows.forEach(row => {
        let rowCat = row.getAttribute('data-category');
        let nom = row.cells[0].textContent.toLowerCase();
        let prenom = row.cells[1].textContent.toLowerCase();

        let matchText = (nom + " " + prenom).includes(searchValue) || (prenom + " " + nom).includes(searchValue);
        let matchCategory = (categoryValue === 'all' || rowCat === categoryValue);

        if (matchText && matchCategory) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// --- 3. FONCTIONS POUR LE GRAPHIQUE ---
let myChart = null;

async function showChart(nageurId, epreuve, nomComplet, categorie = '') {
    document.getElementById('chartModal').style.display = 'block';
    document.getElementById('chartTitle').innerText = "📈 Évolution : " + nomComplet; // Titre plus court
    
    let url = 'index.php?action=history&nageur_id=' + nageurId + '&epreuve=' + epreuve;
    if (categorie !== '') {
        url += '&categorie=' + encodeURIComponent(categorie);
    }

    let response = await fetch(url);
    let responseData = await response.json();
    
    let data = responseData.history;
    let tempsRefSec = responseData.temps_ref_sec;
    let tempsRefStr = responseData.temps_ref_str;
    
    // 🔴 1. On ne met plus que la DATE sur l'axe X pour gagner de la place
    const labels = data.map(d => d.date);
    // 🔴 2. On prépare le détail complet (Date + Lieu) pour l'afficher au clic (Tooltip)
    const fullDetails = data.map(d => d.date + " - " + d.lieu);
    
    const values = data.map(d => d.temps_sec);
    const tooltips = data.map(d => d.temps_str);
    
    const ctx = document.getElementById('evolutionChart').getContext('2d');
    if (myChart) { myChart.destroy(); }
    
    let datasets = [{
        label: 'Temps',
        data: values,
        borderColor: '#007bff',
        backgroundColor: 'rgba(0, 123, 255, 0.1)',
        borderWidth: 3, pointRadius: 5, pointHoverRadius: 8,
        pointBackgroundColor: '#0056b3', fill: true, tension: 0.2
    }];

    if (tempsRefSec !== null) {
        datasets.push({
            label: 'Objectif (' + tempsRefStr + ')',
            data: data.map(() => tempsRefSec),
            borderColor: '#dc3545',
            borderWidth: 2,
            borderDash: [5, 5],
            pointRadius: 0,
            fill: false,
            tension: 0
        });
    }
    
    myChart = new Chart(ctx, {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false, // 🔴 INDISPENSABLE : Laisse le CSS (60vh) décider de la hauteur !
            plugins: { 
                legend: {
                    position: 'bottom' // On descend la légende pour libérer le haut
                },
                tooltip: { 
                    callbacks: { 
                        title: function(context) {
                            // 🔴 Affiche le Date + Lieu complet en gras dans la bulle
                            return fullDetails[context[0].dataIndex];
                        },
                        label: function(c) { 
                            if (c.datasetIndex === 0) {
                                return " ⏱️ Chrono : " + tooltips[c.dataIndex]; 
                            } else {
                                return " 🎯 Objectif : " + tempsRefStr;
                            }
                        } 
                    } 
                } 
            },
            scales: { 
                x: {
                    ticks: {
                        maxRotation: 45, // Incline légèrement les dates, mais pas trop (évite le texte vertical)
                        minRotation: 45
                    }
                },
                y: { 
                    reverse: true, 
                    title: { display: false } // On retire le texte "Plus rapide ⬆️" qui écrase le graph à gauche
                } 
            }
        }
    });
}

function closeChart() { document.getElementById('chartModal').style.display = 'none'; }
window.onclick = function(event) {
    let modal = document.getElementById('chartModal');
    if (event.target == modal) { closeChart(); }
}

// --- 4. FONCTION POUR EXPORTER EN CSV ---
function exporterCsv() {
    // On récupère l'année actuellement sélectionnée dans la liste déroulante
    let saisonSelect = document.getElementById('saisonSelect');
    
    // Si l'ID n'est pas trouvé, on tente de le récupérer par son nom
    if (!saisonSelect) {
        saisonSelect = document.querySelector('select[name="saison"]');
    }

    let saison = saisonSelect ? saisonSelect.value : 'all';

    // On redirige vers la nouvelle route PHP pour lancer le téléchargement
    window.location.href = 'index.php?action=export&saison=' + encodeURIComponent(saison);
}

// --- 5. FONCTION POUR BASCULER ENTRE TABLEAU ET STATISTIQUES ---
function toggleStats() {
    let tableContainer = document.getElementById('tableContainer');
    let statsContainer = document.getElementById('statsContainer');
    let btnToggle = document.getElementById('btnToggleStats');

    // Si les stats sont cachées, on les affiche et on cache le tableau
    if (statsContainer.style.display === 'none') {
        statsContainer.style.display = 'block';
        if(tableContainer) tableContainer.style.display = 'none';
        
        btnToggle.innerHTML = '📋 Retour au Tableau';
        btnToggle.style.backgroundColor = 'var(--couleur-principale)';
    } 
    // Sinon, on fait l'inverse
    else {
        statsContainer.style.display = 'none';
        if(tableContainer) tableContainer.style.display = 'block';
        
        btnToggle.innerHTML = '📊 Afficher les Statistiques';
        btnToggle.style.backgroundColor = '#17a2b8';
    }
}

// --- 6. FONCTIONS POUR LES LOGS (VERSION ROBUSTE) ---
async function voirLogs() {
    const modal = document.getElementById('logModal');
    const container = document.getElementById('logContent');
    
    if (!modal || !container) return;

    modal.style.display = 'block';
    container.innerHTML = '<div style="text-align:center; padding:20px;">Analyse de l\'historique...</div>';

    try {
        const response = await fetch('index.php?action=get_logs');
        const rawText = await response.text();
        
        if (rawText.includes("Aucun historique") || rawText.trim() === "") {
            container.innerHTML = "<div style='padding:20px; text-align:center;'>Aucun historique de synchronisation pour le moment.</div>";
            return;
        }

        const lines = rawText.split('\n').filter(l => l.trim() !== "");
        let sessions = [];
        let currentSession = null;

        // Étape 1 : On regroupe les logs par session de synchro
        lines.forEach(line => {
            if (line.includes("--- DÉBUT")) {
                let dateMatch = line.match(/\[(.*?)\]/);
                let date = dateMatch ? dateMatch[1] : "Date inconnue";
                currentSession = { date: date, logs: [] };
            } else if (line.includes("--- FIN")) {
                if (currentSession) {
                    sessions.push(currentSession);
                    currentSession = null;
                }
            } else if (currentSession) {
                // Si la ligne commence bien par une date (ex: [2026-04-28)
                if (line.trim().startsWith("[")) {
                    currentSession.logs.push(line);
                } else if (currentSession.logs.length > 0) {
                    // Sinon, c'est que la ligne a été coupée en deux ! On la recolle à la précédente.
                    currentSession.logs[currentSession.logs.length - 1] += " " + line.trim();
                }
            }
        });

        // Si une session n'a pas de "FIN" (ex: erreur réseau en cours)
        if (currentSession) sessions.push(currentSession);

        // NOUVEAU : On filtre pour ne garder que les sessions qui ont des logs
        sessions = sessions.filter(session => session.logs.length > 0);

        // Étape 2 : On inverse l'ordre des sessions (les plus récentes en haut)
        sessions.reverse();

        // Étape 3 : On génère le HTML
        let html = "";
        sessions.forEach(session => {
            html += `<div class="log-session">`;
            html += `<div class="log-session-title">📅 Synchronisation du ${session.date}</div>`;
            
            // Plus besoin de vérifier si c'est vide, on a déjà filtré
            session.logs.forEach(logLine => {
                html += parseLogLine(logLine);
            });
            
            html += `</div>`;
        });

        container.style.background = "transparent";
        
        // S'il n'y a eu aucune modification dans aucune session, on affiche un message global
        container.innerHTML = html || "<div style='padding:20px; text-align:center;'>Aucune modification trouvée dans l'historique récent.</div>";
        
    } catch (e) {
        console.error("Erreur d'affichage des logs:", e);
        container.innerHTML = "❌ Erreur de chargement de l'historique.";
    }
}

function parseLogLine(line) {
    try {
        // Extraction de l'heure
        let dateMatch = line.match(/\[(.*?)\]/);
        let heure = dateMatch ? dateMatch[1].split(' ')[1] : ""; 
        
        // Extraction du contenu après la date
        let content = line.substring(line.indexOf(']') + 1).trim();
        
        let type = "Info", icon = "ℹ️", css = "type-ajout", label = "Modification";
        let detailHtml = "";

        // Détection du type de modification
        if (content.includes("[NOUVEAU TEMPS]")) {
            type = "Performance"; icon = "⏱️"; css = "type-temps"; label = "Nouveau Chrono";
            let rawData = content.replace("[NOUVEAU TEMPS]", "").trim();
            let parts = rawData.split('|');
            let namePart = parts[0] ? parts[0].trim() : "Nageur inconnu";
            let changePart = parts[1] ? parts[1].replace('Ancien temps :', '').replace('Nouveau :', '→').trim() : "";
            detailHtml = `<div class="log-name">${namePart}</div><div class="log-change">${changePart}</div>`;
        } 
        else if (content.includes("[MAJ CLASSEMENT]")) {
            type = "Classement"; icon = "📈"; css = "type-classement"; label = "Évolution Rang";
            let rawData = content.replace("[MAJ CLASSEMENT]", "").trim();
            let parts = rawData.split('|');
            let namePart = parts[0] ? parts[0].trim() : "Nageur inconnu";
            let changePart = parts[1] ? parts[1].replace('Ancien Clt :', '').replace('Nouveau Clt :', '→').trim() : "";
            detailHtml = `<div class="log-name">${namePart}</div><div class="log-change">Rang ${changePart}</div>`;
        }
        else {
            // Repli générique
            detailHtml = `<div class="log-details">${content.replace(/\[.*?\]/, '').trim()}</div>`;
        }

        return `
            <div class="log-card ${css}">
                <div class="log-icon">${icon}</div>
                <div class="log-body">
                    <div class="log-type">${label}</div>
                    ${detailHtml}
                </div>
                <div style="font-size:0.75rem; color:var(--texte-secondaire); min-width: 40px; text-align: right; font-weight: bold;">${heure}</div>
            </div>`;
            
    } catch (err) {
        console.error("Erreur de parsing sur la ligne :", line, err);
        return `<div class="log-card type-ajout"><div class="log-details">Détail technique : ${line}</div></div>`;
    }
}

function closeLogs() { 
    const modal = document.getElementById('logModal');
    if(modal) modal.style.display = 'none'; 
}

// MISE À JOUR DE LA FERMETURE AU CLIC EN DEHORS DES MODALES
window.onclick = function(event) {
    let chartModal = document.getElementById('chartModal');
    let logModal = document.getElementById('logModal');
    
    if (event.target == chartModal) { closeChart(); }
    if (event.target == logModal) { closeLogs(); }
}

// --- 7. FONCTIONS RGPD (Cookies & Mentions Légales) ---

// Vérifie au chargement de la page si l'utilisateur a déjà cliqué sur "Compris"
document.addEventListener("DOMContentLoaded", function() {
    if (!localStorage.getItem("rgpd_accepted")) {
        document.getElementById("cookieBanner").style.display = "flex";
        document.getElementById("cookieBanner").style.alignItems = "center";
        document.getElementById("cookieBanner").style.justifyContent = "center";
        document.getElementById("cookieBanner").style.flexWrap = "wrap";
        document.getElementById("cookieBanner").style.gap = "10px";
    }
});

function acceptCookies() {
    localStorage.setItem("rgpd_accepted", "true");
    document.getElementById("cookieBanner").style.display = "none";
}

function openPrivacy(e) {
    if (e) e.preventDefault();
    document.getElementById('privacyModal').style.display = 'block';
}

function closePrivacy() {
    document.getElementById('privacyModal').style.display = 'none';
}

// MISE À JOUR DE LA FERMETURE AU CLIC EN DEHORS DES MODALES
window.onclick = function(event) {
    let chartModal = document.getElementById('chartModal');
    let logModal = document.getElementById('logModal');
    let privacyModal = document.getElementById('privacyModal');
    
    if (event.target == chartModal) { closeChart(); }
    if (event.target == logModal) { closeLogs(); }
    if (event.target == privacyModal) { closePrivacy(); }
}