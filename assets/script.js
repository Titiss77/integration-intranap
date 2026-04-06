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
    
        // Gestion des erreurs de connexion au serveur
        evtSource.onerror = function() {
            progressBar.style.backgroundColor = '#d9534f';
            progressText.innerText = "❌ Connexion perdue avec le serveur.";
            evtSource.close();
            btn.disabled = false;
            btn.style.backgroundColor = "var(--secondary)";
        };
    }