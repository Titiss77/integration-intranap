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