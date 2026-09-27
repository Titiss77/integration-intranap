<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/SyncLogger.php';

class SyncController
{
    private $pdo;
    private $token;
    private $url;
    private $club_cible;
    private $log_file;
    private $logger;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
        $this->url = $_ENV['API_URL'] ?? '';
        $this->token = $_ENV['API_TOKEN'] ?? '';
        $this->club_cible = $_ENV['API_CLUB'] ?? '';
        $this->log_file = __DIR__ . '/../sync_modifications.log';
        $this->logger = new SyncLogger('sync_debug.log');
    }

    /**
     * Convertit un temps MM:SS en secondes (utilisé pour identifier le meilleur temps localement)
     */
    private function timeToSecondsSync($timeStr)
    {
        if (strpos($timeStr, ':') !== false) {
            $parts = explode(':', str_replace(',', '.', $timeStr));
            if (count($parts) === 2) {
                return ($parts[0] * 60) + (float) $parts[1];
            }
        }
        return (float) str_replace(',', '.', $timeStr);
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
        $etape = $_GET['etape'] ?? 'suite';
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
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $cookie_file = __DIR__ . '/../cookie_ffessm.txt';
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_REFERER, 'https://nap.ffessm.fr/index.php');
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_ENCODING, '');

            $headers = [
                'Accept: application/json, text/javascript, */*; q=0.01',
                'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Connection: keep-alive',
                'X-Requested-With: XMLHttpRequest',
                'Sec-Fetch-Dest: empty',
                'Sec-Fetch-Mode: cors',
                'Sec-Fetch-Site: same-origin',
                'Pragma: no-cache',
                'Cache-Control: no-cache'
            ];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            usleep(rand(800000, 2500000));

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new Exception("Erreur réseau cURL ($http_code) : " . $err);
            }
            curl_close($ch);

            if ($response === false || trim($response) === '') {
                $this->logger->warning('API_EMPTY', "L'API a renvoyé une page blanche pour $epreuve $cat_code (HTTP $http_code).");
            } else {
                $donnees = json_decode($response, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $extrait = substr(trim($response), 0, 300);
                    $this->logger->error('API_JSON', "JSON invalide pour $epreuve $cat_code (HTTP $http_code). Extrait: " . $extrait);
                } elseif (is_array($donnees)) {
                    // ---------------------------------------------------------
                    // 1. LECTURE DU FICHIER JSON DES PERFORMANCES EN MÉMOIRE
                    // ---------------------------------------------------------
                    $json_file = __DIR__ . '/../perfs/performances.json';
                    $json_content = file_exists($json_file) ? json_decode(file_get_contents($json_file), true) : [];
                    $perfs_ref = &$json_content;

                    // Détection si le JSON respecte le format d'export phpMyAdmin
                    foreach ($json_content as $i => $item) {
                        if (isset($item['type']) && $item['type'] === 'table' && isset($item['name']) && $item['name'] === 'performances') {
                            if (!isset($json_content[$i]['data'])) {
                                $json_content[$i]['data'] = [];
                            }
                            $perfs_ref = &$json_content[$i]['data'];
                            break;
                        }
                    }

                    // On détermine l'ID maximum existant pour générer les nouveaux inserts
                    $max_id = 0;
                    foreach ($perfs_ref as $p) {
                        if (isset($p['id']) && (int) $p['id'] > $max_id) {
                            $max_id = (int) $p['id'];
                        }
                    }
                    $json_updated = false;

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
                                continue;
                            }

                            // On conserve la base de données MySQL stricto sensu pour les identifiants structurels
                            $nageur_id = $this->getOrCreateNageur($nom_nageur, $prenom_nageur, $cat_nom, null);
                            $categorie_id = $this->getOrCreateSimple('categories', 'nom_categorie', $n['categorie'] ?? 'NC');
                            $lieu_id = $this->getOrCreateSimple('lieux', 'nom_lieu', $n['lieu'] ?? 'NC');

                            // ---------------------------------------------------------
                            // 2. RECHERCHE DANS LE JSON EN MÉMOIRE
                            // ---------------------------------------------------------
                            $old_best = null;
                            $old_exact = null;
                            $best_sec = PHP_INT_MAX;

                            foreach ($perfs_ref as $p) {
                                if ($p['nageur_id'] == $nageur_id && $p['epreuve_id'] == $epreuve_id) {
                                    // Vérifie si la perf exacte existe déjà (pour détecter une modification du classement)
                                    if ($p['temps'] === $n['temps'] && $p['date_perf'] === ($n['date'] ?? '')) {
                                        $old_exact = ['classement' => $p['classement']];
                                    }
                                    // Garde en mémoire le meilleur temps existant pour la saison en cours
                                    if ($p['saison'] == $saison) {
                                        $sec = $this->timeToSecondsSync($p['temps']);
                                        if ($sec < $best_sec) {
                                            $best_sec = $sec;
                                            $old_best = ['temps' => $p['temps']];
                                        }
                                    }
                                }
                            }

                            $affectedRows = 0;
                            $found_perf = false;

                            // ---------------------------------------------------------
                            // RECHERCHE D'UNE PERFORMANCE EXISTANTE (Idem que précédent)
                            // ---------------------------------------------------------
                            $found_key = null;
                            foreach ($perfs_ref as $key => $p) {
                                if ($p['nageur_id'] == $nageur_id && $p['epreuve_id'] == $epreuve_id && $p['temps'] === $n['temps'] && $p['date_perf'] === ($n['date'] ?? '')) {
                                    $found_key = $key;
                                    break;
                                }
                            }

                            if ($found_key !== null) {
                                // La perf existe. Vérifions si le classement a changé.
                                if ($perfs_ref[$found_key]['classement'] != $position_nationale) {
                                    $ancien_clt = $perfs_ref[$found_key]['classement'];
                                    $perfs_ref[$found_key]['classement'] = (string) $position_nationale;
                                    $json_updated = true;

                                    // LOG : Mise à jour du classement
                                    $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve} - {$n['temps']}) | Ancien Clt : {$ancien_clt} -> Nouveau : {$position_nationale}";
                                    $this->writeToLog('[MAJ CLASSEMENT] ' . $info);
                                    $this->logger->info('RANKING', $info);
                                }
                                // Si rien n'a changé, on ne fait rien (donc pas de log, c'est normal)
                            } else {
                                // La perf n'existe pas, on l'ajoute.
                                $max_id++;
                                $perfs_ref[] = [
                                    'id' => (string) $max_id,
                                    'nageur_id' => (string) $nageur_id,
                                    'epreuve_id' => (string) $epreuve_id,
                                    'categorie_id' => (string) $categorie_id,
                                    'lieu_id' => (string) $lieu_id,
                                    'saison' => (string) $saison,
                                    'temps' => $n['temps'],
                                    'date_perf' => $n['date'] ?? '',
                                    'classement' => (string) $position_nationale
                                ];
                                $json_updated = true;

                                // LOG : Ajout
                                $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve}) | Ajout 1er temps : {$n['temps']} @ {$n['lieu']}";
                                $this->writeToLog('[AJOUT] ' . $info);
                                $this->logger->info('INSERT', $info);
                            }

                            // ---------------------------------------------------------
                            // 4. GESTION DU JOURNAL D'ACTIVITÉ (Logs)
                            // ---------------------------------------------------------
                            if ($affectedRows > 0) {
                                if ($affectedRows === 1) {
                                    if ($old_best && $old_best['temps'] !== $n['temps']) {
                                        $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve}) | Ancien: {$old_best['temps']} -> Nouveau: {$n['temps']} @ {$n['lieu']}";
                                        $this->writeToLog('[NOUVEAU TEMPS] ' . $info);
                                        $this->logger->success('UPDATE', $info);
                                    } else {
                                        $info = "{$prenom_nageur} {$nom_nageur} ({$epreuve}) | Ajout 1er temps : {$n['temps']} @ {$n['lieu']}";
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

                    // ---------------------------------------------------------
                    // 5. ÉCRITURE FINALE SUR LE DISQUE
                    // ---------------------------------------------------------
                    if ($json_updated) {
                        file_put_contents(
                            $json_file,
                            json_encode($json_content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                            LOCK_EX  // <-- Ajout du verrou exclusif ici
                        );
                    }
                }
            }

            if ($etape === 'fin') {
                $this->writeToLog('--- FIN DE SYNCHRONISATION ---');
                $this->logger->info('END', '--- FIN DE SYNCHRONISATION ---');
            }

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

    public function getLogs()
    {
        if (file_exists($this->log_file)) {
            echo file_get_contents($this->log_file);
        } else {
            echo 'Aucun historique.';
        }
    }
}