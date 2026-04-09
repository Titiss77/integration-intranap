<?php

require_once __DIR__ . '/../config/Database.php';

class SyncController
{
    private $pdo;
    private $token;
    private $url;
    private $club_cible;

    public function __construct()
    {
        $this->pdo = Database::getConnection();

        // On récupère les valeurs depuis le .env
        $this->url = $_ENV['API_URL'] ?? '';
        $this->token = $_ENV['API_TOKEN'] ?? '';
        $this->club_cible = $_ENV['API_CLUB'] ?? '';
    }

    public function syncData($token_recu = '')
    {
        // On initialise les en-têtes SSE en premier pour pouvoir envoyer des messages d'erreur à l'interface
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // 🔴 1. VÉRIFICATION CSRF
        // On vérifie que la session est bien démarrée pour accéder aux variables
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token_recu)) {
            $this->sendSSE(0, 'Erreur de sécurité (Jeton CSRF invalide). Veuillez recharger la page.', true, true);
            return;
        }

        // 🔴 2. RATE LIMITING (Anti-Spam / DoS)
        // Limite à une synchronisation toutes les 5 minutes (300 secondes)
        $now = time();
        if (isset($_SESSION['last_sync_time']) && ($now - $_SESSION['last_sync_time']) < 300) {
            $attente = 300 - ($now - $_SESSION['last_sync_time']);
            $this->sendSSE(0, "Anti-spam : Veuillez patienter {$attente} secondes avant de relancer.", true, true);
            return;
        }
        // On met à jour l'heure de la dernière tentative
        $_SESSION['last_sync_time'] = $now;

        // On ferme la session en écriture pour éviter de bloquer la navigation de l'utilisateur
        // pendant la longue boucle de synchronisation
        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_implicit_flush(true);
        set_time_limit(0);

        $saisons = [date('Y')];
        $liste_epreuves = ['50SF', '100SF', '200SF', '400SF', '800SF', '1500SF', '50AP', '100IS', '800IS', '200IS', '400IS', '50BI', '100BI', '200BI', '400BI'];
        $categories_genre = ['F' => 'Femmes', 'M' => 'Hommes'];
        $total_steps = count($saisons) * count($liste_epreuves) * count($categories_genre);
        $current_step = 0;

        try {
            foreach ($saisons as $saison) {
                foreach ($liste_epreuves as $epreuve) {
                    $epreuve_id = $this->getOrCreateSimple('epreuves', 'nom_epreuve', $epreuve);

                    foreach ($categories_genre as $cat_code => $cat_nom) {
                        ++$current_step;
                        $this->sendSSE(round(($current_step / $total_steps) * 100), "Recherche : {$epreuve} ({$cat_nom})");

                        $params = ['action' => 'gettop', 'course' => $epreuve, 'bassin' => '0', 'cid' => '0', 'order' => 'tps', 'clubid' => '0', 'saison' => $saison, 'category' => $cat_code, 'token' => $this->token];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $this->url . '?' . http_build_query($params));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                        // 🔴 3. SÉCURITÉ SSL RÉACTIVÉE (Protection Man-In-The-Middle)
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

                        // NOTE IMPORTANTE SI CELA BLOQUE EN LOCAL (WAMP/XAMPP) :
                        // Téléchargez cacert.pem depuis https://curl.se/ca/cacert.pem
                        // Placez-le dans votre dossier config/ et décommentez la ligne suivante :
                        // curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/../config/cacert.pem');

                        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                        $response = curl_exec($ch);

                        // Gestion explicite des erreurs cURL (utile pour débugger le SSL)
                        if (curl_errno($ch)) {
                            $this->sendSSE(0, 'Erreur réseau FFESSM : ' . curl_error($ch), true, true);
                            curl_close($ch);
                            return;
                        }

                        curl_close($ch);

                        if ($response) {
                            $donnees = json_decode($response, true);
                            if (is_array($donnees)) {
                                $classement_par_categorie = [];

                                foreach ($donnees as $n) {
                                    // Calcul du classement national
                                    $cat_nageur = $n['categorie'] ?? 'Non renseigné';
                                    if (!isset($classement_par_categorie[$cat_nageur])) {
                                        $classement_par_categorie[$cat_nageur] = 0;
                                    }
                                    ++$classement_par_categorie[$cat_nageur];
                                    $position_nationale = $classement_par_categorie[$cat_nageur];

                                    // Sauvegarde en BDD
                                    if (isset($n['club']) && $n['club'] === $this->club_cible) {
                                        $raw_date = $n['annee'] ?? $n['naissance'] ?? $n['date_naissance'] ?? null;
                                        $date_formatee = null;

                                        if (!empty($raw_date)) {
                                            if (preg_match('/^\d{4}$/', $raw_date)) {
                                                $date_formatee = $raw_date . '-01-01';
                                            } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw_date)) {
                                                $p = explode('/', $raw_date);
                                                $date_formatee = $p[2] . '-' . $p[1] . '-' . $p[0];
                                            } else {
                                                $date_formatee = $raw_date;
                                            }
                                        }

                                        $nageur_id = $this->getOrCreateNageur($n['nom'], $n['prenom'], $cat_nom, $date_formatee);
                                        $categorie_id = $this->getOrCreateSimple('categories', 'nom_categorie', $n['categorie'] ?? 'Non renseigné');
                                        $lieu_id = $this->getOrCreateSimple('lieux', 'nom_lieu', $n['lieu'] ?? 'Non renseigné');

                                        $this->insertPerformance($nageur_id, $epreuve_id, $categorie_id, $lieu_id, $saison, $n['temps'], $n['date'] ?? '', $position_nationale);
                                    }
                                }
                            }
                        }
                        usleep(500000);  // Respect du serveur FFESSM
                    }
                }
            }
            $this->sendSSE(100, 'Synchronisation terminée avec succès !', true);
        } catch (Exception $e) {
            $this->sendSSE(0, 'Erreur interne de synchronisation.', true, true);
        }
    }

    private function sendSSE($progress, $message, $is_done = false, $is_error = false)
    {
        echo 'data: ' . json_encode(['progress' => $progress, 'message' => $message, 'done' => $is_done, 'error' => $is_error]) . "\n\n";
        echo str_pad('', 4096) . "\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function getOrCreateSimple($table, $column, $value)
    {
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO {$table} ({$column}) VALUES (?)");
        $stmt->execute([$value]);
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE {$column} = ?");
        $stmt->execute([$value]);

        return $stmt->fetchColumn();
    }

    private function getOrCreateNageur($nom, $prenom, $genre, $date_naissance)
    {
        $stmt = $this->pdo->prepare('SELECT id, date_naissance FROM nageurs WHERE nom = ? AND prenom = ?');
        $stmt->execute([$nom, $prenom]);
        $nageur = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($nageur) {
            if (empty($nageur['date_naissance']) && !empty($date_naissance)) {
                $updateStmt = $this->pdo->prepare('UPDATE nageurs SET date_naissance = ? WHERE id = ?');
                $updateStmt->execute([$date_naissance, $nageur['id']]);
            }
            return $nageur['id'];
        }

        $stmt = $this->pdo->prepare('INSERT INTO nageurs (nom, prenom, genre, date_naissance) VALUES (?, ?, ?, ?)');
        $stmt->execute([$nom, $prenom, $genre, $date_naissance]);

        return $this->pdo->lastInsertId();
    }

    private function insertPerformance($nageur_id, $epreuve_id, $categorie_id, $lieu_id, $saison, $temps, $date_perf, $classement)
    {
        $sql = 'INSERT INTO performances (nageur_id, epreuve_id, categorie_id, lieu_id, saison, temps, date_perf, classement)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE classement = VALUES(classement)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$nageur_id, $epreuve_id, $categorie_id, $lieu_id, $saison, $temps, $date_perf, $classement]);
    }
}
