<?php

require_once __DIR__ . '/../config/Database.php';

class SyncController
{
    private $pdo;
    private $token;
    private $url;
    private $club_cible;
    private $log_file;

    public function __construct()
    {
        $this->pdo = Database::getConnection();

        $this->url = $_ENV['API_URL'] ?? '';
        $this->token = $_ENV['API_TOKEN'] ?? '';
        $this->club_cible = $_ENV['API_CLUB'] ?? '';

        $this->log_file = __DIR__ . '/../sync_modifications.log';
    }

    public function syncData($token_recu = '')
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token_recu)) {
            $this->sendSSE(0, 'Erreur de sécurité (Jeton CSRF invalide).', true, true);
            return;
        }

        $now = time();
        if (isset($_SESSION['last_sync_time']) && ($now - $_SESSION['last_sync_time']) < 10) {
            $attente = 10 - ($now - $_SESSION['last_sync_time']);
            $this->sendSSE(0, "Anti-spam : Attendez {$attente}s.", true, true);
            return;
        }
        $_SESSION['last_sync_time'] = $now;

        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        while (ob_get_level() > 0)
            ob_end_clean();
        ob_implicit_flush(true);
        set_time_limit(0);

        // 🔴 1. CHARGEMENT DE LA BLACKLIST
        $blacklist = [];
        $chemin_blacklist = __DIR__ . '/../blacklist.txt';
        if (file_exists($chemin_blacklist)) {
            $lignes = file($chemin_blacklist, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lignes as $ligne) {
                // On ignore les lignes commentées avec #
                if (strpos(trim($ligne), '#') !== 0) {
                    // On met tout en minuscules pour faciliter la comparaison
                    $blacklist[] = mb_strtolower(trim($ligne), 'UTF-8');
                }
            }
        }

        $saisons = [date('Y')];
        $liste_epreuves = ['50SF', '100SF', '200SF', '400SF', '800SF', '1500SF', '50AP', '100IS', '800IS', '200IS', '400IS', '50BI', '100BI', '200BI', '400BI'];
        $categories_genre = ['F' => 'Femmes', 'M' => 'Hommes'];

        $total_steps = count($saisons) * count($liste_epreuves) * count($categories_genre);
        $current_step = 0;

        try {
            $this->writeToLog('--- DÉBUT DE SYNCHRONISATION ---');

            foreach ($saisons as $saison) {
                foreach ($liste_epreuves as $epreuve) {
                    $epreuve_id = $this->getOrCreateSimple('epreuves', 'nom_epreuve', $epreuve);

                    foreach ($categories_genre as $cat_code => $cat_nom) {
                        ++$current_step;

                        $params = ['action' => 'gettop', 'course' => $epreuve, 'saison' => $saison, 'category' => $cat_code, 'token' => $this->token, 'clubid' => '0', 'order' => 'tps'];

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $this->url . '?' . http_build_query($params));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $this->writeToLog('ERREUR RÉSEAU : ' . curl_error($ch));
                            $this->sendSSE(0, 'Erreur réseau.', true, true);
                            return;
                        }
                        curl_close($ch);

                        $modifs_session = [];

                        if ($response) {
                            $donnees = json_decode($response, true);
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

                                        // 🔴 2. VÉRIFICATION BLACKLIST POUR CE NAGEUR
                                        $nom_complet_1 = mb_strtolower($nom_nageur . ' ' . $prenom_nageur, 'UTF-8');
                                        $nom_complet_2 = mb_strtolower($prenom_nageur . ' ' . $nom_nageur, 'UTF-8');
                                        
                                        $est_blacklist = false;
                                        foreach ($blacklist as $bl_nom) {
                                            if ($nom_complet_1 === $bl_nom || $nom_complet_2 === $bl_nom) {
                                                $est_blacklist = true;
                                                break;
                                            }
                                        }

                                        // Si le nageur est sur liste noire
                                        if ($est_blacklist) {
                                            // On tente de le supprimer de la base (si jamais il y était avant son ajout à la liste)
                                            $stmtDel = $this->pdo->prepare('DELETE FROM nageurs WHERE nom = ? AND prenom = ?');
                                            $stmtDel->execute([$nom_nageur, $prenom_nageur]);
                                            
                                            // Si on a bien supprimé quelqu'un, on le loggue pour information
                                            if ($stmtDel->rowCount() > 0) {
                                                $info = "Suppression des données de : {$prenom_nageur} {$nom_nageur}";
                                                $modifs_session[] = "[BLACKLIST] " . $info;
                                                $this->writeToLog("[BLACKLIST] " . $info);
                                            }
                                            
                                            // On saute ce nageur et on passe au suivant (aucune insertion)
                                            continue; 
                                        }

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
                                                    $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve}) | Ancien temps : {$old_best['temps']} -> Nouveau : {$n['temps']} à {$n['lieu']}";
                                                    $modifs_session[] = $info;
                                                    $this->writeToLog('[NOUVEAU TEMPS] ' . $info);
                                                } else {
                                                    $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve}) | Ajout 1er temps : {$n['temps']} à {$n['lieu']}";
                                                    $modifs_session[] = $info;
                                                    $this->writeToLog('[AJOUT] ' . $info);
                                                }
                                            } elseif ($affectedRows === 2) {
                                                $ancien_clt = ($old_exact && $old_exact['classement'] !== null) ? $old_exact['classement'] : 'NC';
                                                if ($ancien_clt != $position_nationale) {
                                                    $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve} - {$n['temps']}) | Ancien Clt : {$ancien_clt} -> Nouveau Clt : {$position_nationale}";
                                                    $modifs_session[] = $info;
                                                    $this->writeToLog('[MAJ CLASSEMENT] ' . $info);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        $pourcentage = round(($current_step / $total_steps) * 100);
                        $message = !empty($modifs_session) ? "{$epreuve} ({$cat_nom}) : " . implode(' | ', $modifs_session) : "{$epreuve} ({$cat_nom}) : À jour (0 modif)";
                        $this->sendSSE($pourcentage, $message);

                        usleep(300000);
                    }
                }
            }
            $this->writeToLog('--- FIN DE SYNCHRONISATION ---');
            $this->sendSSE(100, 'Synchronisation terminée !', true);
        } catch (Exception $e) {
            $this->writeToLog('ERREUR CRITIQUE : ' . $e->getMessage());
            $this->sendSSE(0, 'Erreur interne.', true, true);
        }
    }

    private function writeToLog($message)
    {
        $date = date('Y-m-d H:i:s');
        $format = "[$date] $message" . PHP_EOL;
        file_put_contents($this->log_file, $format, FILE_APPEND);
    }

    private function sendSSE($progress, $message, $is_done = false, $is_error = false)
    {
        echo 'data: ' . json_encode(['progress' => $progress, 'message' => $message, 'done' => $is_done, 'error' => $is_error]) . "\n\n";
        echo str_pad('', 4096) . "\n";
        if (ob_get_level() > 0)
            ob_flush();
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
        $stmt = $this->pdo->prepare('SELECT id FROM nageurs WHERE nom = ? AND prenom = ?');
        $stmt->execute([$nom, $prenom]);
        $nageur = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($nageur)
            return $nageur['id'];

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
        return $stmt->rowCount();
    }

    public function getLogs()
    {
        if (file_exists($this->log_file)) {
            echo file_get_contents($this->log_file);
        } else {
            echo 'Aucun historique.';
        }
    }
}