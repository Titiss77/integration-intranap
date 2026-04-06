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

    function lancerSync() {
        const btn = document.getElementById('btnSync');
        const progressContainer = document.getElementById('progressContainer');
        const progressBar = document.getElementById('progressBar');
        const progressText = document.getElementById('progressText');
    
        // Désactiver le bouton et afficher la barre
        btn.disabled = true;
        btn.style.backgroundColor = "#ccc";
        progressContainer.style.display = 'block';
        progressBar.style.width = '0%';
        progressBar.style.backgroundColor = '#28a745';
        progressBar.innerText = '0%';
        progressText.innerText = 'Connexion à la base FFESSM...';
    
        // Ouvrir une connexion Server-Sent Events (SSE)
        const evtSource = new EventSource("index.php?action=sync");
    
        // À chaque fois que PHP fait un "echo", cet événement se déclenche
        evtSource.onmessage = function(event) {
            const data = JSON.parse(event.data);
    
            // Si erreur
            if (data.error) {
                progressBar.style.backgroundColor = '#d9534f'; // Rouge
                progressText.innerText = data.message;
                evtSource.close();
                btn.disabled = false;
                btn.style.backgroundColor = "var(--secondary)";
                return;
            }
    
            // Mise à jour visuelle de la barre et du texte
            progressBar.style.width = data.progress + '%';
            progressBar.innerText = data.progress + '%';
            progressText.innerText = data.message;
    
            // Si terminé
            if (data.done) {
                evtSource.close(); // Fermer la connexion
                progressBar.style.backgroundColor = '#28a745'; // S'assurer que c'est vert
                progressText.innerHTML = "<strong>✅ " + data.message + " La page va se recharger.</strong>";
                
                // Recharger la page après 2 secondes pour afficher les nouvelles données
                setTimeout(() => {
                    location.reload();
                }, 2000);
            }
        };
    
        // Gestion des erreurs de connexion au serveur (Bien placée à l'intérieur de lancerSync)
        evtSource.onerror = function(event) {
            // Si la connexion est définitivement fermée par le serveur
            if (evtSource.readyState === EventSource.CLOSED) {
                progressBar.style.backgroundColor = '#d9534f';
                progressText.innerText = "❌ Connexion perdue avec le serveur.";
                evtSource.close();
                btn.disabled = false;
                btn.style.backgroundColor = "var(--secondary)";
            } else {
                // Si la connexion vacille
                progressText.innerText = "⚠️ Reconnexion en cours...";
            }
        };
    }

    let myChart = null;

async function showChart(nageurId, epreuve, nomComplet) {
    document.getElementById('chartModal').style.display = 'block';
    document.getElementById('chartTitle').innerText = "📈 Évolution de " + nomComplet + " sur " + epreuve;
    
    // Appel au contrôleur PHP pour récupérer l'historique
    let response = await fetch('index.php?action=history&nageur_id=' + nageurId + '&epreuve=' + epreuve);
    let data = await response.json();
    
    const labels = data.map(d => d.date + " (" + d.lieu + ")");
    const values = data.map(d => d.temps_sec);
    const tooltips = data.map(d => d.temps_str);
    
    const ctx = document.getElementById('evolutionChart').getContext('2d');
    if (myChart) { myChart.destroy(); } // Détruire l'ancien graphique s'il existe
    
    myChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Temps chronométré',
                data: values,
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                borderWidth: 3,
                pointRadius: 6,
                pointHoverRadius: 8,
                pointBackgroundColor: '#0056b3',
                fill: true,
                tension: 0.2 // Courbe légèrement arrondie
            }]
        },
        options: {
            responsive: true,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            // On affiche le chrono au format MM:SS.ms au survol
                            return " Chrono : " + tooltips[context.dataIndex];
                        }
                    }
                }
            },
            scales: {
                y: { 
                    reverse: true, // IMPORTANT : Inverse l'axe Y (plus le temps est bas, plus la courbe monte !)
                    title: { display: true, text: 'Plus rapide ⬆️' }
                }
            }
        }
    });
}

function closeChart() {
    document.getElementById('chartModal').style.display = 'none';
}

// Fermer la modale si on clique à côté
window.onclick = function(event) {
    let modal = document.getElementById('chartModal');
    if (event.target == modal) { closeChart(); }
}

// On supprime les variables d'accordéon et on garde juste le filtre pur
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

        // Si le nageur correspond à la recherche ET à la catégorie choisie dans le menu, on l'affiche
        if (matchText && matchCategory) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Gestion des erreurs de connexion au serveur
        evtSource.onerror = function(event) {
            // Si la connexion est définitivement fermée par le serveur
            if (evtSource.readyState === EventSource.CLOSED) {
                progressBar.style.backgroundColor = '#d9534f';
                progressText.innerText = "❌ Connexion perdue avec le serveur.";
                evtSource.close();
                btn.disabled = false;
                btn.style.backgroundColor = "var(--secondary)";
            } else {
                // Si la connexion vacille, on indique que le navigateur tente de se reconnecter
                progressText.innerText = "⚠️ Reconnexion en cours...";
            }
        };