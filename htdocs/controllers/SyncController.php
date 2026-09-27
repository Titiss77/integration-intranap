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

    /**
     * Normalise un temps reçu de l'API (ex: "17.50") vers le format BDD (ex: "00:17.50")
     */
    private function normalizeTime($temps_brut)
    {
        $t = trim(str_replace(',', '.', $temps_brut));
        if (empty($t)) return '';

        if (strpos($t, ':') !== false) {
            $parts = explode(':', $t);
            $minutes = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            $secParts = explode('.', $parts[1]);
            $secondes = str_pad($secParts[0], 2, '0', STR_PAD_LEFT);
            $ms = isset($secParts[1]) ? str_pad($secParts[1], 2, '0', STR_PAD_RIGHT) : '00';
            return "$minutes:$secondes.$ms";
        } else {
            $secParts = explode('.', $t);
            $secondes = str_pad($secParts[0], 2, '0', STR_PAD_LEFT);
            $ms = isset($secParts[1]) ? str_pad($secParts[1], 2, '0', STR_PAD_RIGHT) : '00';
            return "00:$secondes.$ms";
        }
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

            // PRÉPARATION DES REQUÊTES MYSQL POUR LES CLASSEMENTS
            $stmtCheckPerf = $this->pdo->prepare('SELECT id, classement FROM performances WHERE nageur_id = ? AND epreuve_id = ? AND saison = ? AND temps = ? LIMIT 1');
            $stmtUpdatePerf = $this->pdo->prepare('UPDATE performances SET classement = ? WHERE id = ?');
            $stmtAddPerf = $this->pdo->prepare('INSERT INTO performances (nageur_id, epreuve_id, categorie_id, lieu_id, saison, temps, date_perf, classement) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

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
                    
                    // CORRECTION ICI : Le compteur est maintenant GLOBAL pour refléter le classement général
                    $compteur_lignes = 0;
                    $vraie_position = 0;
                    $dernier_temps = null;

                    foreach ($donnees as $n) {
                        
                        $compteur_lignes++;

                        if ($n['temps'] !== $dernier_temps) {
                            $vraie_position = $compteur_lignes;
                            $dernier_temps = $n['temps'];
                        }

                        $position_nationale = $vraie_position;

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

                            $nageur_id = $this->getOrCreateNageur($nom_nageur, $prenom_nageur, $cat_nom, null);
                            $categorie_id = $this->getOrCreateSimple('categories', 'nom_categorie', $n['categorie'] ?? 'NC');
                            $lieu_id = $this->getOrCreateSimple('lieux', 'nom_lieu', $n['lieu'] ?? 'NC');
                            
                            // CORRECTION ICI : Normalisation du temps pour correspondre à la base de données PDF
                            $temps_final = $this->normalizeTime($n['temps']);
                            $date_perf = $n['date'] ?? '';

                            // --- GESTION EN BASE DE DONNÉES (AJOUT OU MISE À JOUR DU CLASSEMENT) ---
                            if (!empty($temps_final)) {
                                
                                $stmtCheckPerf->execute([
                                    $nageur_id,
                                    $epreuve_id ?? null,
                                    $saison,
                                    $temps_final
                                ]);
                                
                                $existingPerf = $stmtCheckPerf->fetch(PDO::FETCH_ASSOC);

                                if ($existingPerf) {
                                    // La performance existe déjà : on compare le classement
                                    $ancienClassement = $existingPerf['classement'] !== null ? (int)$existingPerf['classement'] : null;
                                    $nouveauClassement = $position_nationale !== null ? (int)$position_nationale : null;

                                    if ($nouveauClassement !== null && $ancienClassement !== $nouveauClassement) {
                                        // La position a changé ! Mise à jour en base de données.
                                        $stmtUpdatePerf->execute([$nouveauClassement, $existingPerf['id']]);

                                        // Trace dans les logs
                                        $info = sprintf(
                                            "%s %s (%s) | Position : %s -> %s (Temps : %s)",
                                            $prenom_nageur,
                                            $nom_nageur,
                                            $epreuve,
                                            $ancienClassement !== null ? $ancienClassement . 'e' : 'N/C',
                                            $nouveauClassement . 'e',
                                            $temps_final
                                        );
                                        $this->writeToLog('[MAJ CLASSEMENT] ' . $info);
                                        $this->logger->info('RANKING', $info);
                                    }
                                } else {
                                    // La performance n'existe pas, c'est un nouveau temps, on l'ajoute
                                    $stmtAddPerf->execute([
                                        $nageur_id,
                                        $epreuve_id ?? null,
                                        $categorie_id,
                                        $lieu_id,
                                        $saison,
                                        $temps_final,
                                        $date_perf,
                                        $position_nationale
                                    ]);
                                    
                                    if ($stmtAddPerf->rowCount() > 0) {
                                        $info = sprintf(
                                            "%s %s (%s) | Ajout temps : %s (%se) @ %s",
                                            $prenom_nageur,
                                            $nom_nageur,
                                            $epreuve,
                                            $temps_final,
                                            $position_nationale !== null ? $position_nationale : 'N/C',
                                            $n['lieu'] ?? 'NC'
                                        );
                                        $this->writeToLog('[NOUVEAU TEMPS] ' . $info);
                                        $this->logger->info('INSERT', $info);
                                    }
                                }
                            }
                        }
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