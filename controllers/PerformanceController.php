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

        // Récupérer les catégories actuelles si on est sur "Toutes les saisons"
        $categories_actuelles = [];
        if ($annee_selectionnee === 'all') {
            $categories_actuelles = $model->getCategoriesActuelles();
        }

        // 3. Traitement et formatage pour un tableau plat
        $profils_nageurs = [];
        $epreuves_trouvees = [];
        $categories_disponibles = [];  // Deviendra un tableau associatif [Code => Libelle]

        if (!empty($lignes_bdd)) {
            foreach ($lignes_bdd as $ligne) {
                $nageur_id = $ligne['nageur_id'];

                // Détermination de la bonne catégorie à afficher
                if ($annee_selectionnee === 'all' && isset($categories_actuelles[$nageur_id])) {
                    $categorie_a_afficher = $categories_actuelles[$nageur_id]['nom_categorie'];
                    $libelle_a_afficher = $categories_actuelles[$nageur_id]['libelle'];
                } else {
                    $categorie_a_afficher = $ligne['categorie'];
                    $libelle_a_afficher = $ligne['categorie_libelle'];
                }

                // On liste les catégories uniques (Code => Libelle) pour le menu déroulant
                if (!isset($categories_disponibles[$categorie_a_afficher])) {
                    $categories_disponibles[$categorie_a_afficher] = $libelle_a_afficher;
                }

                // On crée le nageur s'il n'existe pas encore
                if (!isset($profils_nageurs[$nageur_id])) {
                    $date_naissance_str = '-';
                    $age_str = '';

                    if (!empty($ligne['date_naissance'])) {
                        try {
                            $dob = new DateTime($ligne['date_naissance']);
                            $now = new DateTime();
                            $age = $now->diff($dob)->y;
                            $date_naissance_str = $dob->format('d/m/Y');
                            $age_str = "({$age} ans)";
                        } catch (Exception $e) {
                            $date_naissance_str = $ligne['date_naissance'];
                        }
                    }

                    $profils_nageurs[$nageur_id] = [
                        'nageur_id' => $nageur_id,
                        'nom' => $ligne['nom'],
                        'prenom' => $ligne['prenom'],
                        'categorie' => $categorie_a_afficher,
                        'categorie_libelle' => $libelle_a_afficher, // Nouveau champ
                        'date_naissance_str' => $date_naissance_str,
                        'age_str' => $age_str,
                        'chronos' => [],
                    ];
                }

                $profils_nageurs[$nageur_id]['chronos'][$ligne['epreuve']] = [
                    'temps' => $ligne['temps'], 'date' => $ligne['date_perf'], 'lieu' => $ligne['lieu'],
                ];

                if (!in_array($ligne['epreuve'], $epreuves_trouvees)) {
                    $epreuves_trouvees[] = $ligne['epreuve'];
                }
            }
        }

        // Trier le tableau associatif des catégories par leurs clés (ordre alphabétique des codes courts)
        ksort($categories_disponibles);

        $ordre_officiel = ['25SF', '50SF', '100SF', '200SF', '400SF', '800SF', '1500SF', '1850SF', '25AP', '50AP', '100IS', '800IS', '200IS', '400IS', '50BI', '100BI', '200BI', '400BI'];
        $colonnes_epreuves = array_intersect($ordre_officiel, $epreuves_trouvees);

        require_once __DIR__.'/../views/dashboard.php';
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
            if (2 == count($parts)) {
                $secondes = ($parts[0] * 60) + (float) $parts[1];
            } else {
                $secondes = (float) $parts[0];
            }

            $data[] = [
                'date' => $h['date_perf'],
                'temps_str' => $h['temps'],
                'temps_sec' => $secondes,
                'lieu' => $h['lieu'],
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