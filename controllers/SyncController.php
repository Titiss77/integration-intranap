<?php
require_once __DIR__ . '/../config/Database.php';

class SyncController {
    private $pdo;
    private $token = "15e86f224cf5f9737247328e34a456ca";
    private $url = "https://nap.ffessm.fr/request.php";
    private $club_cible = "PEC";

    public function __construct() {
        $this->pdo = Database::getConnection();
    }

    public function syncData() {
        // 1. En-têtes indispensables pour le streaming en temps réel (SSE)
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        // Désactiver la mise en cache de la sortie PHP
        if (ob_get_level() > 0) { ob_end_clean(); }
        set_time_limit(300); 

        $saisons = ["2026"];
        $liste_epreuves = [
            "50SF", "100SF", "200SF", "400SF", "800SF", "1500SF",
            "50AP", "100IS", "800IS", "200IS", "400IS", "50BI", "100BI", "200BI", "400BI"
        ];
        $categories_genre = ["F" => "Femmes", "M" => "Hommes"];

        // Calcul du nombre total d'appels API pour la progression
        $total_steps = count($saisons) * count($liste_epreuves) * count($categories_genre);
        $current_step = 0;

        try {
            foreach ($saisons as $saison) {
                foreach ($liste_epreuves as $epreuve) {
                    $epreuve_id = $this->getOrCreateSimple("epreuves", "nom_epreuve", $epreuve);

                    foreach ($categories_genre as $cat_code => $cat_nom) {
                        
                        // --- ENVOI DE LA PROGRESSION AU NAVIGATEUR ---
                        $current_step++;
                        $percentage = round(($current_step / $total_steps) * 100);
                        $this->sendSSE($percentage, "Recherche : $epreuve ($cat_nom)");
                        // ---------------------------------------------

                        $params = [
                            "action" => "gettop", "course" => $epreuve, "bassin" => "0",
                            "cid" => "0", "order" => "tps", "clubid" => "0",
                            "saison" => $saison, "category" => $cat_code, "token" => $this->token
                        ];

                        $apiUrl = $this->url . '?' . http_build_query($params);
                        
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $apiUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        $response = curl_exec($ch);
                        curl_close($ch);

                        if ($response) {
                            $donnees = json_decode($response, true);
                            if(is_array($donnees)) {
                                foreach ($donnees as $n) {
                                    if (isset($n['club']) && $n['club'] === $this->club_cible) {
                                        $nageur_id = $this->getOrCreateNageur($n['nom'], $n['prenom'], $cat_nom);
                                        $categorie_id = $this->getOrCreateSimple("categories", "nom_categorie", $n['categorie'] ?? 'Non renseigné');
                                        $lieu_id = $this->getOrCreateSimple("lieux", "nom_lieu", $n['lieu'] ?? 'Non renseigné');

                                        $this->insertPerformance($nageur_id, $epreuve_id, $categorie_id, $lieu_id, $saison, $n['temps'], $n['date'] ?? '');
                                    }
                                }
                            }
                        }
                        usleep(500000); // Pause de 0.5s
                    }
                }
            }
            // Fin du traitement
            $this->sendSSE(100, "Synchronisation terminée avec succès !", true);

        } catch (Exception $e) {
            $this->sendSSE(0, "Erreur interne de synchronisation.", true, true);
        }
    }

    // Fonction utilitaire pour envoyer les données en temps réel (SSE)
    private function sendSSE($progress, $message, $is_done = false, $is_error = false) {
        $data = json_encode([
            'progress' => $progress,
            'message' => $message,
            'done' => $is_done,
            'error' => $is_error
        ]);
        echo "data: $data\n\n";
        flush(); // Force PHP à envoyer le paquet immédiatement
    }

    private function getOrCreateSimple($table, $column, $value) {
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO $table ($column) VALUES (?)");
        $stmt->execute([$value]);
        $stmt = $this->pdo->prepare("SELECT id FROM $table WHERE $column = ?");
        $stmt->execute([$value]);
        return $stmt->fetchColumn();
    }

    private function getOrCreateNageur($nom, $prenom, $genre) {
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO nageurs (nom, prenom, genre) VALUES (?, ?, ?)");
        $stmt->execute([$nom, $prenom, $genre]);
        $stmt = $this->pdo->prepare("SELECT id FROM nageurs WHERE nom = ? AND prenom = ?");
        $stmt->execute([$nom, $prenom]);
        return $stmt->fetchColumn();
    }

    private function insertPerformance($nageur_id, $epreuve_id, $categorie_id, $lieu_id, $saison, $temps, $date_perf) {
        $sql = "INSERT IGNORE INTO performances (nageur_id, epreuve_id, categorie_id, lieu_id, saison, temps, date_perf) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$nageur_id, $epreuve_id, $categorie_id, $lieu_id, $saison, $temps, $date_perf]);
    }
}