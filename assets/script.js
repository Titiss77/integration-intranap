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

    const evtSource = new EventSource("index.php?action=sync");

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

async function showChart(nageurId, epreuve, nomComplet) {
    document.getElementById('chartModal').style.display = 'block';
    document.getElementById('chartTitle').innerText = "📈 Évolution de " + nomComplet + " sur " + epreuve;
    
    let response = await fetch('index.php?action=history&nageur_id=' + nageurId + '&epreuve=' + epreuve);
    let responseData = await response.json();
    
    // Extraction des données de la nouvelle structure JSON
    let data = responseData.history;
    let tempsRefSec = responseData.temps_ref_sec;
    let tempsRefStr = responseData.temps_ref_str;
    
    const labels = data.map(d => d.date + " (" + d.lieu + ")");
    const values = data.map(d => d.temps_sec);
    const tooltips = data.map(d => d.temps_str);
    
    const ctx = document.getElementById('evolutionChart').getContext('2d');
    if (myChart) { myChart.destroy(); }
    
    // 1er Dataset : Les performances du nageur
    let datasets = [{
        label: 'Temps chronométré',
        data: values,
        borderColor: '#007bff',
        backgroundColor: 'rgba(0, 123, 255, 0.1)',
        borderWidth: 3, pointRadius: 6, pointHoverRadius: 8,
        pointBackgroundColor: '#0056b3', fill: true, tension: 0.2
    }];

    // 2ème Dataset : La ligne de qualification (si elle existe)
    if (tempsRefSec !== null) {
        datasets.push({
            label: 'Objectif Qualif (' + tempsRefStr + ')',
            // On crée un tableau avec la même valeur pour tracer une ligne droite
            data: data.map(() => tempsRefSec),
            borderColor: '#dc3545', // Rouge
            borderWidth: 2,
            borderDash: [5, 5], // Ligne en pointillés
            pointRadius: 0, // Ne pas afficher de points sur cette ligne
            fill: false,
            tension: 0
        });
    }
    
    myChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            plugins: { 
                tooltip: { 
                    callbacks: { 
                        label: function(c) { 
                            // On adapte le texte du survol selon la ligne ciblée
                            if (c.datasetIndex === 0) {
                                return " Chrono : " + tooltips[c.dataIndex]; 
                            } else {
                                return " Objectif à atteindre : " + tempsRefStr;
                            }
                        } 
                    } 
                } 
            },
            scales: { y: { reverse: true, title: { display: true, text: 'Plus rapide ⬆️' } } }
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