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

    async function lancerSync() {
        const btn = document.getElementById('btnSync');
        
        // Changement d'état du bouton pendant le chargement
        btn.disabled = true;
        btn.innerHTML = "⏳ Synchronisation en cours... (patientez)";
        btn.style.backgroundColor = "#ccc";
    
        try {
            // On appelle notre index.php avec le paramètre action=sync
            let response = await fetch('index.php?action=sync');
            let data = await response.json();
    
            if (response.ok) {
                alert(data.message);
                location.reload(); // On recharge la page pour voir les nouvelles données
            } else {
                alert("Erreur: " + data.message);
            }
        } catch (error) {
            alert("Une erreur de communication est survenue.");
            console.error(error);
        } finally {
            // Restauration du bouton (si la page ne se recharge pas)
            btn.disabled = false;
            btn.innerHTML = "🔄 Synchroniser avec la FFESSM";
            btn.style.backgroundColor = "var(--secondary)";
        }
    }