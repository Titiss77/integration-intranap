<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../models/PerformanceModel.php';

class PerformanceController
{
    public function index()
    {
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
                $nom_complet = $ligne['nom'] . ' ' . $ligne['prenom'];

                if (!isset($profils_par_categorie[$categorie])) {
                    $profils_par_categorie[$categorie] = [];
                }

                // [!] NOUVEAU : On sauvegarde l'ID du nageur
                if (!isset($profils_par_categorie[$categorie][$nom_complet])) {
                    $profils_par_categorie[$categorie][$nom_complet] = [
                        'nageur_id' => $ligne['nageur_id'],  // <- ICI
                        'nom' => $ligne['nom'],
                        'prenom' => $ligne['prenom'],
                        'chronos' => []
                    ];
                }

                $profils_par_categorie[$categorie][$nom_complet]['chronos'][$ligne['epreuve']] = [
                    'temps' => $ligne['temps'], 'date' => $ligne['date_perf'], 'lieu' => $ligne['lieu']
                ];

                if (!in_array($ligne['epreuve'], $epreuves_trouvees)) {
                    $epreuves_trouvees[] = $ligne['epreuve'];
                }
            }
        }

        // Tri des épreuves selon l'ordre officiel
        $ordre_officiel = ['25SF', '50SF', '100SF', '200SF', '400SF', '800SF', '1500SF', '1850SF', '25AP', '50AP', '100IS', '800IS', '200IS', '400IS', '50BI', '100BI', '200BI', '400BI'];
        $colonnes_epreuves = array_intersect($ordre_officiel, $epreuves_trouvees);
        $total_colonnes = 2 + count($colonnes_epreuves);

        // 4. Inclusion de la Vue (en lui passant les variables préparées implicitement)
        require_once __DIR__ . '/../views/dashboard.php';
    }

    // NOUVELLE MÉTHODE : Appelée via AJAX pour générer le graphique
    public function getHistoryApi()
    {
        $nageur_id = $_GET['nageur_id'] ?? 0;
        $epreuve = $_GET['epreuve'] ?? '';

        $pdo = Database::getConnection();
        $model = new PerformanceModel($pdo);
        $history = $model->getHistorique($nageur_id, $epreuve);

        $data = [];
        foreach ($history as $h) {
            // Conversion du temps (ex: "01:23.45") en secondes pour le graphique
            $parts = explode(':', str_replace(',', '.', $h['temps']));
            if (count($parts) == 2) {
                $secondes = ($parts[0] * 60) + (float) $parts[1];
            } else {
                $secondes = (float) $parts[0];
            }

            $data[] = [
                'date' => $h['date_perf'],
                'temps_str' => $h['temps'],
                'temps_sec' => $secondes,
                'lieu' => $h['lieu']
            ];
        }

        // Tri chronologique des dates (Format JJ/MM/AAAA)
        usort($data, function ($a, $b) {
            $da = implode('', array_reverse(explode('/', $a['date'])));
            $db = implode('', array_reverse(explode('/', $b['date'])));
            return strcmp($da, $db);
        });

        header('Content-Type: application/json');
        echo json_encode($data);
    }
}