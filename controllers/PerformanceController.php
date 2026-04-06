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

        // Récupération de la grille des qualifications
        $grille_qualifs = $model->getGrilleQualifs();

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
                    $libelle_a_afficher = $ligne['categorie_libelle'] . ' (en ' . $annee_selectionnee . ')';
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
                        'categorie_libelle' => $libelle_a_afficher,  // Nouveau champ
                        'date_naissance_str' => $date_naissance_str,
                        'age_str' => $age_str,
                        'chronos' => [],
                    ];
                }

                // --- NOUVEAU : Vérification de la qualification ---
                $temps_nageur = $ligne['temps'];
                $est_qualifie = null;  // null = pas de temps de ref défini

                // On vérifie si un temps de référence existe pour cette catégorie et cette épreuve
                if (isset($grille_qualifs[$categorie_a_afficher][$ligne['epreuve']])) {
                    $temps_ref = $grille_qualifs[$categorie_a_afficher][$ligne['epreuve']];

                    $sec_nageur = $this->timeToSeconds($temps_nageur);
                    $sec_ref = $this->timeToSeconds($temps_ref);

                    // Si le temps du nageur est inférieur ou égal au temps de référence, il est qualifié
                    $est_qualifie = ($sec_nageur <= $sec_ref);
                }
                // --------------------------------------------------

                $profils_nageurs[$nageur_id]['chronos'][$ligne['epreuve']] = [
                    'temps' => $temps_nageur,
                    'date' => $ligne['date_perf'],
                    'lieu' => $ligne['lieu'],
                    'est_qualifie' => $est_qualifie  // On stocke l'état
                ];

                if (!in_array($ligne['epreuve'], $epreuves_trouvees)) {
                    $epreuves_trouvees[] = $ligne['epreuve'];
                }
            }
        }

        // 1. Définir l'ordre officiel souhaité (du plus jeune au plus âgé par exemple)
        $ordre_categories_officiel = [
            'FPO', 'HPO',  // Poussins
            'FBE', 'HBE',  // Benjamins
            'FMI', 'HMI',  // Minimes
            'FCA', 'HCA',  // Cadets
            'FJU', 'HJU',  // Juniors
            'FSE', 'HSE',  // Seniors
            'F35+', 'H35+',  // Masters 35+
            'F45+', 'H45+',  // Masters 45+
            'F55+', 'H55+'  // Masters 55+
        ];

        // 2. Créer un nouveau tableau trié selon cet ordre
        $categories_triees = [];
        foreach ($ordre_categories_officiel as $code_cat) {
            if (isset($categories_disponibles[$code_cat])) {
                $categories_triees[$code_cat] = $categories_disponibles[$code_cat];
            }
        }

        // 3. Ajouter à la fin les éventuelles catégories qui ne seraient pas dans la liste officielle
        foreach ($categories_disponibles as $code_cat => $libelle) {
            if (!isset($categories_triees[$code_cat])) {
                $categories_triees[$code_cat] = $libelle;
            }
        }

        // 4. Remplacer l'ancien tableau par le tableau trié
        $categories_disponibles = $categories_triees;

        $ordre_officiel = ['25SF', '50SF', '100SF', '200SF', '400SF', '800SF', '1500SF', '1850SF', '25AP', '50AP', '100IS', '800IS', '200IS', '400IS', '50BI', '100BI', '200BI', '400BI'];
        $colonnes_epreuves = array_intersect($ordre_officiel, $epreuves_trouvees);

        require_once __DIR__ . '/../views/dashboard.php';
    }

    // Fonction utilitaire pour convertir un chrono "MM:SS.ms" ou "SS.ms" en secondes
    private function timeToSeconds($timeStr)
    {
        $parts = explode(':', str_replace(',', '.', $timeStr));
        if (count($parts) === 2) {
            return ($parts[0] * 60) + (float) $parts[1];
        }
        return (float) $parts[0];
    }

    // MÉTHODE APPELÉE VIA AJAX POUR GÉNÉRER LE GRAPHIQUE
    public function getHistoryApi()
    {
        $nageur_id = $_GET['nageur_id'] ?? 0;
        $epreuve = $_GET['epreuve'] ?? '';

        $pdo = Database::getConnection();
        $model = new PerformanceModel($pdo);
        $history = $model->getHistorique($nageur_id, $epreuve);

        $data = [];
        foreach ($history as $h) {
            // Conversion du temps en secondes
            $data[] = [
                'date' => $h['date_perf'],
                'temps_str' => $h['temps'],
                'temps_sec' => $this->timeToSeconds($h['temps']),
                'lieu' => $h['lieu'],
            ];
        }

        // Tri chronologique des dates (Format JJ/MM/AAAA)
        usort($data, function ($a, $b) {
            $da = implode('', array_reverse(explode('/', $a['date'])));
            $db = implode('', array_reverse(explode('/', $b['date'])));
            return strcmp($da, $db);
        });

        // --- NOUVEAU : Récupération du temps de référence ---
        $temps_ref_sec = null;
        $temps_ref_str = null;

        $categories_actuelles = $model->getCategoriesActuelles();
        if (isset($categories_actuelles[$nageur_id])) {
            $categorie_actuelle = $categories_actuelles[$nageur_id]['nom_categorie'];
            $grille = $model->getGrilleQualifs();
            
            if (isset($grille[$categorie_actuelle][$epreuve])) {
                $temps_ref_str = $grille[$categorie_actuelle][$epreuve];
                $temps_ref_sec = $this->timeToSeconds($temps_ref_str);
            }
        }
        // ----------------------------------------------------

        header('Content-Type: application/json');
        
        // On modifie la structure du JSON envoyé au JavaScript
        echo json_encode([
            'history' => $data,
            'temps_ref_sec' => $temps_ref_sec,
            'temps_ref_str' => $temps_ref_str
        ]);
    }
}