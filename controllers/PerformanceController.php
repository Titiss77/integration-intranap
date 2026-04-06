<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../models/PerformanceModel.php';

class PerformanceController {
    public function index() {
        $pdo = Database::getConnection();
        $model = new PerformanceModel($pdo);

        // 1. Gestion des filtres
        $annees_disponibles = $model->getSaisons();
        $annee_selectionnee = isset($_GET['saison']) ? $_GET['saison'] : 'all';

        // 2. Récupération des données brutes
        $lignes_bdd = $model->getPerformances($annee_selectionnee);

        // 3. Traitement et formatage des données pour la vue
        $profils_par_categorie = [];
        $epreuves_trouvees = [];

        if (!empty($lignes_bdd)) {
            foreach ($lignes_bdd as $ligne) {
                $categorie = $ligne['categorie'];
                $nom_complet = $ligne['nom'] . " " . $ligne['prenom'];
                
                if (!isset($profils_par_categorie[$categorie])) {
                    $profils_par_categorie[$categorie] = [];
                }
                if (!isset($profils_par_categorie[$categorie][$nom_complet])) {
                    $profils_par_categorie[$categorie][$nom_complet] = [
                        'nom' => $ligne['nom'], 
                        'prenom' => $ligne['prenom'], 
                        'chronos' => []
                    ];
                }
                
                $profils_par_categorie[$categorie][$nom_complet]['chronos'][$ligne['epreuve']] = [
                    'temps' => $ligne['temps'], 
                    'date'  => $ligne['date_perf'], 
                    'lieu'  => $ligne['lieu']
                ];
                
                if (!in_array($ligne['epreuve'], $epreuves_trouvees)) {
                    $epreuves_trouvees[] = $ligne['epreuve'];
                }
            }
        }

        // Tri des épreuves selon l'ordre officiel
        $ordre_officiel = ["25SF", "50SF", "100SF", "200SF", "400SF", "800SF", "1500SF", "1850SF", "25AP", "50AP", "100IS", "800IS", "200IS", "400IS", "50BI", "100BI", "200BI", "400BI"];
        $colonnes_epreuves = array_intersect($ordre_officiel, $epreuves_trouvees);
        $total_colonnes = 2 + count($colonnes_epreuves);

        // 4. Inclusion de la Vue (en lui passant les variables préparées implicitement)
        require_once __DIR__ . '/../views/dashboard.php';
    }
}