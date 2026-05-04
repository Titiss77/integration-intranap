<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/SyncLogger.php';

class SyncController
{
    private $pdo;
    private $token;
    private $url;
    private $club_cible;
    private $log_file;  // Fichier pour l'interface web
    private $logger;  // Fichier pour le débogage technique

    public function __construct()
    {
        $this->pdo = Database::getConnection();
        $this->url = $_ENV['API_URL'] ?? '';
        $this->token = $_ENV['API_TOKEN'] ?? '';
        $this->club_cible = $_ENV['API_CLUB'] ?? '';

        // Log historique pour l'interface web (inchangé)
        $this->log_file = __DIR__ . '/../sync_modifications.log';

        // Log technique pour trouver les erreurs (Nouveau)
        $this->logger = new SyncLogger('sync_debug.log');
    }

    public function syncData($token_recu = '')
    {
        // On renvoie du JSON standard
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');

        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        // Vérification CSRF
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token_recu)) {
            echo json_encode(['error' => true, 'message' => 'Erreur de sécurité (Jeton CSRF invalide).']);
            return;
        }

        // On libère la session pour ne pas bloquer les requêtes suivantes
        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        // Récupération des paramètres envoyés par le script JS
        $epreuve = $_GET['epreuve'] ?? '';
        $cat_code = $_GET['genre'] ?? '';
        $etape = $_GET['etape'] ?? 'suite';  // 'debut', 'suite', ou 'fin'
        $saison = date('Y');

        if (empty($epreuve) || empty($cat_code)) {
            echo json_encode(['error' => true, 'message' => 'Paramètres manquants.']);
            return;
        }

        $categories_genre = ['F' => 'Femmes', 'M' => 'Hommes'];
        if (!array_key_exists($cat_code, $categories_genre)) {
            echo json_encode(['error' => true, 'message' => 'Genre invalide.']);
            return;
        }
        $cat_nom = $categories_genre[$cat_code];

        // Gestion des logs d'ouverture et de fermeture
        if ($etape === 'debut') {
            $this->writeToLog('--- DÉBUT DE SYNCHRONISATION ---');
            $this->logger->separator();
            $this->logger->info('START', '--- DÉBUT DE SYNCHRONISATION ---');
        }

        $this->logger->info('API_CALL', "Requete: $epreuve | $cat_code");

        // Chargement de la blacklist
        $blacklist = [];
        $chemin_blacklist = __DIR__ . '/../blacklist.txt';
        if (file_exists($chemin_blacklist)) {
            $lignes = file($chemin_blacklist, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lignes as $ligne) {
                if (strpos(trim($ligne), '#') !== 0) {
                    $blacklist[] = mb_strtolower(trim($ligne), 'UTF-8');
                }
            }
        }

        try {
            $epreuve_id = $this->getOrCreateSimple('epreuves', 'nom_epreuve', $epreuve);

            $params = [
                'action' => 'gettop', 'course' => $epreuve, 'saison' => $saison,
                'category' => $cat_code, 'token' => $this->token, 'clubid' => '0',
                'order' => 'tps', 'nocache' => time()
            ];
            $url_complete = $this->url . '?' . http_build_query($params);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url_complete);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_REFERER, 'https://nap.ffessm.fr/index.php');
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_ENCODING, '');

            $headers = [
                'Accept: application/json, text/javascript, */*; q=0.01',
                'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Connection: keep-alive',
                'X-Requested-With: XMLHttpRequest',
                'Sec-Fetch-Dest: empty',
                'Sec-Fetch-Mode: cors',
                'Sec-Fetch-Site: same-origin'
            ];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                throw new Exception("Erreur réseau cURL ($http_code) : " . curl_error($ch));
            }
            curl_close($ch);

            // Si page blanche ou erreur
            if ($response === false || trim($response) === '') {
                $this->logger->warning('API_EMPTY', "L'API a renvoyé une page blanche pour $epreuve $cat_code.");
            } else {
                $donnees = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->error('API_JSON', "JSON invalide pour $epreuve $cat_code.");
                } elseif (is_array($donnees)) {
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
                                    $this->writeToLog('[BLACKLIST] ' . $info);
                                    $this->logger->warning('BLACKLIST', $info);
                                }
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
                                        $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve}) | Ancien: {$old_best['temps']} -> Nouveau: {$n['temps']} à {$n['lieu']}";
                                        $this->writeToLog('[NOUVEAU TEMPS] ' . $info);
                                        $this->logger->success('UPDATE', $info);
                                    } else {
                                        $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve}) | Ajout 1er temps : {$n['temps']} à {$n['lieu']}";
                                        $this->writeToLog('[AJOUT] ' . $info);
                                        $this->logger->info('INSERT', $info);
                                    }
                                } elseif ($affectedRows === 2) {
                                    $ancien_clt = ($old_exact && $old_exact['classement'] !== null) ? $old_exact['classement'] : 'NC';
                                    if ($ancien_clt != $position_nationale) {
                                        $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve} - {$n['temps']}) | Ancien Clt : {$ancien_clt} -> Nouveau : {$position_nationale}";
                                        $this->writeToLog('[MAJ CLASSEMENT] ' . $info);
                                        $this->logger->info('RANKING', $info);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Gestion de la fermeture des logs
            if ($etape === 'fin') {
                $this->writeToLog('--- FIN DE SYNCHRONISATION ---');
                $this->logger->info('END', '--- FIN DE SYNCHRONISATION ---');
            }

            // On envoie un succès JSON au navigateur
            echo json_encode(['error' => false, 'message' => "Traitement de {$epreuve} ({$cat_nom}) terminé."]);
        } catch (Exception $e) {
            $this->logger->error('FATAL', 'Erreur sur ' . $epreuve . ' : ' . $e->getMessage());
            echo json_encode(['error' => true, 'message' => 'Erreur interne : ' . $e->getMessage()]);
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