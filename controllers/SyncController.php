<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/SyncLogger.php'; // Inclusion du système de logs

class SyncController
{
    private $pdo;
    private $token;
    private $url;
    private $club_cible;
    private $logger;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
        $this->url = $_ENV['API_URL'] ?? '';
        $this->token = $_ENV['API_TOKEN'] ?? '';
        $this->club_cible = $_ENV['API_CLUB'] ?? '';
        // Initialisation du logger avec le chemin correct
        $this->logger = new SyncLogger(__DIR__ . '/../sync_modifications.log');
    }

    public function syncData($token_recu = '')
    {
        // 1. En-têtes obligatoires pour le Server-Sent Events (barre de progression JavaScript)
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Désactive le buffer Nginx sur l'hébergeur

        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        // Sécurité CSRF
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token_recu)) {
            $this->sendSSE(0, 'Erreur de sécurité (Jeton CSRF invalide).', true, true);
            return;
        }

        // Anti-spam (5 minutes)
        $now = time();
        if (isset($_SESSION['last_sync_time']) && ($now - $_SESSION['last_sync_time']) < 300) {
            $attente = 300 - ($now - $_SESSION['last_sync_time']);
            $this->sendSSE(0, "Anti-spam : Attendez {$attente}s.", true, true);
            return;
        }
        $_SESSION['last_sync_time'] = $now;

        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        // Vide les buffers de sortie pour forcer l'envoi en direct
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_implicit_flush(true);
        set_time_limit(0);

        $this->logger->separator();
        $this->logger->info('START', '--- DÉBUT DE SYNCHRONISATION ---');

        // 2. Chargement de la blacklist
        $blacklist = [];
        $chemin_blacklist = __DIR__ . '/../blacklist.txt';
        if (file_exists($chemin_blacklist)) {
            $lignes = file($chemin_blacklist, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lignes as $ligne) {
                if (strpos(trim($ligne), '#') !== 0) {
                    $blacklist[] = mb_strtolower(trim($ligne), 'UTF-8');
                }
            }
            $this->logger->info('CONFIG', count($blacklist) . " nageurs chargés depuis la blacklist.");
        }

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
                        $current_step++;
                        
                        // 3. Préparation API avec anti-cache pour votre hébergeur
                        $params = [
                            'action' => 'gettop', 'course' => $epreuve, 'saison' => $saison, 
                            'category' => $cat_code, 'token' => $this->token, 'clubid' => '0', 
                            'order' => 'tps', 'nocache' => time()
                        ];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $this->url . '?' . http_build_query($params));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                        // Faux navigateur pour passer le pare-feu
                        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

                        $response = curl_exec($ch);
                        
                        if (curl_errno($ch)) {
                            $erreur = curl_error($ch);
                            $this->logger->error('API_CURL', "Erreur réseau sur $epreuve ($cat_code) : $erreur");
                            $this->sendSSE(0, "Erreur réseau : $erreur", true, true);
                            return;
                        }
                        curl_close($ch);

                        $modifs_session = [];

                        if ($response) {
                            $donnees = json_decode($response, true);
                            
                            // Si l'hébergeur FFESSM nous renvoie une page d'erreur HTML au lieu du JSON
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                $this->logger->error('API_JSON', "L'API a bloqué la requête ou renvoyé du HTML sur $epreuve ($cat_code).");
                                continue;
                            }

                            if (is_array($donnees)) {
                                $compteur_lignes = [];
                                $vraie_position = [];
                                $dernier_temps = [];

                                foreach ($donnees as $n) {
                                    $cat_nageur = $n['categorie'] ?? 'NC';
                                    if (!isset($compteur_lignes[$cat_nageur])) {
                                        $compteur_lignes[$cat_nageur] = 0;
                                        $vraie_position[$cat_nageur] = 0;
                                        $dernier_temps[$cat_nageur] = null;
                                    }
                                    
                                    $compteur_lignes[$cat_nageur]++;
                                    if ($n['temps'] !== $dernier_temps[$cat_nageur]) {
                                        $vraie_position[$cat_nageur] = $compteur_lignes[$cat_nageur];
                                        $dernier_temps[$cat_nageur] = $n['temps'];
                                    }
                                    $position_nationale = $vraie_position[$cat_nageur];

                                    if (isset($n['club']) && $n['club'] === $this->club_cible) {
                                        $nom_nageur = $n['nom'] ?? '';
                                        $prenom_nageur = $n['prenom'] ?? '';

                                        // Vérification de la blacklist
                                        $nom_complet_1 = mb_strtolower($nom_nageur . ' ' . $prenom_nageur, 'UTF-8');
                                        $nom_complet_2 = mb_strtolower($prenom_nageur . ' ' . $nom_nageur, 'UTF-8');
                                        
                                        $est_blacklist = false;
                                        foreach ($blacklist as $bl_nom) {
                                            if ($nom_complet_1 === $bl_nom || $nom_complet_2 === $bl_nom) {
                                                $est_blacklist = true;
                                                break;
                                            }
                                        }

                                        if ($est_blacklist) {
                                            $stmtDel = $this->pdo->prepare('DELETE FROM nageurs WHERE nom = ? AND prenom = ?');
                                            $stmtDel->execute([$nom_nageur, $prenom_nageur]);
                                            if ($stmtDel->rowCount() > 0) {
                                                $info = "Suppression des données de : {$prenom_nageur} {$nom_nageur}";
                                                $modifs_session[] = "[BLACKLIST] " . $info;
                                                $this->logger->warning('BLACKLIST', $info);
                                            }
                                            continue; 
                                        }

                                        // Insertion BDD
                                        $nageur_id = $this->getOrCreateNageur($nom_nageur, $prenom_nageur, $cat_nom, null);
                                        $categorie_id = $this->getOrCreateSimple('categories', 'nom_categorie', $n['categorie'] ?? 'NC');
                                        $lieu_id = $this->getOrCreateSimple('lieux', 'nom_lieu', $n['lieu'] ?? 'NC');

                                        $stmtBest = $this->pdo->prepare('SELECT temps FROM performances WHERE nageur_id = ? AND epreuve_id = ? AND saison = ? ORDER BY temps ASC LIMIT 1');
                                        $stmtBest->execute([$nageur_id, $epreuve_id, $saison]);
                                        $old_best = $stmtBest->fetch(PDO::FETCH_ASSOC);

                                        $stmtExact = $this->pdo->prepare('SELECT classement FROM performances WHERE nageur_id = ? AND epreuve_id = ? AND temps = ? AND date_perf = ?');
                                        $stmtExact->execute([$nageur_id, $epreuve_id, $n['temps'], $n['date'] ?? '']);
                                        $old_exact = $stmtExact->fetch(PDO::FETCH_ASSOC);

                                        $affectedRows = $this->insertPerformance($nageur_id, $epreuve_id, $categorie_id, $lieu_id, $saison, $n['temps'], $n['date'] ?? '', $position_nationale);

                                        if ($affectedRows > 0) {
                                            if ($affectedRows === 1) {
                                                if ($old_best && $old_best['temps'] !== $n['temps']) {
                                                    $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve}) | Ancien: {$old_best['temps']} -> Nouveau: {$n['temps']} à {$n['lieu']}";
                                                    $modifs_session[] = "[NOUVEAU TEMPS] " . $info;
                                                    $this->logger->success('UPDATE', $info);
                                                } else {
                                                    $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve}) | Ajout 1er temps : {$n['temps']} à {$n['lieu']}";
                                                    $modifs_session[] = "[AJOUT] " . $info;
                                                    $this->logger->info('INSERT', $info);
                                                }
                                            } elseif ($affectedRows === 2) {
                                                $ancien_clt = ($old_exact && $old_exact['classement'] !== null) ? $old_exact['classement'] : 'NC';
                                                if ($ancien_clt != $position_nationale) {
                                                    $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve} - {$n['temps']}) | Ancien Clt : {$ancien_clt} -> Nouveau : {$position_nationale}";
                                                    $modifs_session[] = "[MAJ CLASSEMENT] " . $info;
                                                    $this->logger->info('RANKING', $info);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        $pourcentage = round(($current_step / $total_steps) * 100);
                        $message = !empty($modifs_session) ? "{$epreuve} ({$cat_nom}) : modifs en cours..." : "{$epreuve} ({$cat_nom}) : à jour";
                        $this->sendSSE($pourcentage, $message);
                        usleep(150000); // Petite pause pour ne pas saturer l'API
                    }
                }
            }

            $this->logger->info('END', '--- FIN DE SYNCHRONISATION ---');
            $this->sendSSE(100, 'Synchronisation terminée !', true);

        } catch (Exception $e) {
            $this->logger->error('FATAL', 'Erreur critique : ' . $e->getMessage());
            $this->sendSSE(0, 'Erreur interne au serveur.', true, true);
        }
    }

    // Fonction d'envoi Server-Sent Events
    private function sendSSE($progress, $message, $is_done = false, $is_error = false)
    {
        echo 'data: ' . json_encode(['progress' => $progress, 'message' => $message, 'done' => $is_done, 'error' => $is_error]) . "\n\n";
        // Envoi d'espaces vides pour forcer le vidage du buffer sur les hébergeurs récalcitrants
        echo str_pad('', 4096) . "\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }

    // --- Méthodes privées BDD ---
    private function getOrCreateSimple($table, $column, $value) {
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO {$table} ({$column}) VALUES (?)");
        $stmt->execute([$value]);
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE {$column} = ?");
        $stmt->execute([$value]);
        return $stmt->fetchColumn();
    }

    private function getOrCreateNageur($nom, $prenom, $genre, $date_naissance) {
        $stmt = $this->pdo->prepare('SELECT id FROM nageurs WHERE nom = ? AND prenom = ?');
        $stmt->execute([$nom, $prenom]);
        $nageur = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($nageur) return $nageur['id'];
        $stmt = $this->pdo->prepare('INSERT INTO nageurs (nom, prenom, genre, date_naissance) VALUES (?, ?, ?, ?)');
        $stmt->execute([$nom, $prenom, $genre, $date_naissance]);
        return $this->pdo->lastInsertId();
    }

    private function insertPerformance($nageur_id, $epreuve_id, $categorie_id, $lieu_id, $saison, $temps, $date_perf, $classement) {
        $sql = 'INSERT INTO performances (nageur_id, epreuve_id, categorie_id, lieu_id, saison, temps, date_perf, classement)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE classement = VALUES(classement)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$nageur_id, $epreuve_id, $categorie_id, $lieu_id, $saison, $temps, $date_perf, $classement]);
        return $stmt->rowCount();
    }

    public function getLogs() {
        $log_file = __DIR__ . '/../sync_modifications.log';
        if (file_exists($log_file)) {
            echo file_get_contents($log_file);
        } else {
            echo 'Aucun historique.';
        }
    }
}