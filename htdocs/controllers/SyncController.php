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
     * Convertit un temps reçu de l'API (ex: "17.50") vers le format BDD (ex: "00:17.50")
     * Indispensable pour que le SELECT trouve la ligne correspondante insérée par le PDF.
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
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');

        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token_recu)) {
            echo json_encode(['error' => true, 'message' => 'Erreur de sécurité (Jeton CSRF invalide).']);
            return;
        }

        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        $epreuve = $_GET['epreuve'] ?? '';
        $cat_code = $_GET['genre'] ?? '';
        $etape = $_GET['etape'] ?? 'suite';
        $saison = $_GET['saison'] ?? date('Y'); // S'assure qu'on utilise la bonne saison

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

        if ($etape === 'debut') {
            $this->writeToLog('--- DÉBUT DE SYNCHRONISATION ---');
            $this->logger->separator();
            $this->logger->info('START', '--- DÉBUT DE SYNCHRONISATION ---');
        }

        $this->logger->info('API_CALL', "Requete: $epreuve | $cat_code | Saison: $saison");

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

            // Requêtes SQL : On vérifie avec la date pour garantir qu'on modifie la course exacte
            $stmtCheckPerf = $this->pdo->prepare('SELECT id, classement FROM performances WHERE nageur_id = ? AND epreuve_id = ? AND saison = ? AND temps = ? AND date_perf = ? LIMIT 1');
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
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
            curl_setopt($ch, CURLOPT_ENCODING, '');
            
            $headers = ['Accept: application/json', 'Cache-Control: no-cache'];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            usleep(rand(800000, 2000000));
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                throw new Exception("Erreur réseau cURL ($http_code)");
            }
            curl_close($ch);

            if ($response !== false && trim($response) !== '') {
                $donnees = json_decode($response, true);

                if (is_array($donnees)) {
                    
                    // On utilise un compteur global pour générer le "Classement Absolu"
                    $compteur_lignes = 0;
                    $vraie_position = 0;
                    $dernier_temps = null;
                    $nageurs_vus = [];

                    foreach ($donnees as $n) {
                        
                        // Exclusion des étrangers (souvent présents dans l'API mais absents du TOP France)
                        $nat = isset($n['nat']) ? strtoupper(trim($n['nat'])) : 'FRA';
                        if ($nat !== 'FRA') {
                            continue;
                        }

                        $nom_nageur = trim($n['nom'] ?? '');
                        $prenom_nageur = trim($n['prenom'] ?? '');
                        $cle_nageur = mb_strtolower($nom_nageur . ' ' . $prenom_nageur, 'UTF-8');

                        $position_nationale = null;

                        // On ne compte la position que sur le MEILLEUR TEMPS de la saison du nageur
                        if (!isset($nageurs_vus[$cle_nageur])) {
                            $compteur_lignes++;
                            
                            // Gère les ex-aequo
                            if ($n['temps'] !== $dernier_temps) {
                                $vraie_position = $compteur_lignes;
                                $dernier_temps = $n['temps'];
                            }
                            
                            $nageurs_vus[$cle_nageur] = true;
                            $position_nationale = $vraie_position; // Affecte le rang (ex: 17e)
                        } 
                        // Note : Si on a déjà vu le nageur, $position_nationale reste null 
                        // (ce temps secondaire n'est pas classé nationalement)

                        if (isset($n['club']) && $n['club'] === $this->club_cible) {
                            $est_blacklist = false;
                            foreach ($blacklist as $bl_nom) {
                                if ($cle_nageur === $bl_nom || mb_strtolower($prenom_nageur . ' ' . $nom_nageur, 'UTF-8') === $bl_nom) {
                                    $est_blacklist = true;
                                    break;
                                }
                            }

                            if ($est_blacklist) continue;

                            // Extraction des data
                            $nageur_id = $this->getOrCreateNageur($nom_nageur, $prenom_nageur, $cat_nom, null);
                            $categorie_id = $this->getOrCreateSimple('categories', 'nom_categorie', $n['categorie'] ?? 'NC');
                            $lieu_id = $this->getOrCreateSimple('lieux', 'nom_lieu', $n['lieu'] ?? 'NC');
                            
                            // Normalisation indispensable !
                            $temps_final = $this->normalizeTime($n['temps']);
                            $date_perf = $n['date'] ?? '';

                            if (!empty($temps_final)) {
                                
                                $stmtCheckPerf->execute([
                                    $nageur_id,
                                    $epreuve_id ?? null,
                                    $saison,
                                    $temps_final,
                                    $date_perf
                                ]);
                                
                                $existingPerf = $stmtCheckPerf->fetch(PDO::FETCH_ASSOC);

                                if ($existingPerf) {
                                    // La perf existe, on met juste à jour sa place 
                                    $ancienClassement = $existingPerf['classement'] !== null ? (int)$existingPerf['classement'] : null;
                                    $nouveauClassement = $position_nationale !== null ? (int)$position_nationale : null;

                                    if ($nouveauClassement !== null && $ancienClassement !== $nouveauClassement) {
                                        $stmtUpdatePerf->execute([$nouveauClassement, $existingPerf['id']]);

                                        $info = sprintf(
                                            "%s %s (%s) | Position : %s -> %s (Temps : %s)",
                                            $prenom_nageur, $nom_nageur, $epreuve,
                                            $ancienClassement !== null ? $ancienClassement . 'e' : 'N/C',
                                            $nouveauClassement . 'e', $temps_final
                                        );
                                        $this->writeToLog('[MAJ CLASSEMENT] ' . $info);
                                        $this->logger->info('RANKING', $info);
                                    }
                                } else {
                                    // C'est un vrai nouveau temps non présent en base
                                    $stmtAddPerf->execute([
                                        $nageur_id, $epreuve_id ?? null, $categorie_id, $lieu_id,
                                        $saison, $temps_final, $date_perf, $position_nationale
                                    ]);
                                    
                                    if ($stmtAddPerf->rowCount() > 0) {
                                        $info = sprintf(
                                            "%s %s (%s) | Ajout temps : %s (%se) @ %s",
                                            $prenom_nageur, $nom_nageur, $epreuve, $temps_final,
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

            echo json_encode(['error' => false, 'message' => "Traitement de {$epreuve} terminé."]);
        } catch (Exception $e) {
            echo json_encode(['error' => true, 'message' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    private function writeToLog($message)
    {
        file_put_contents($this->log_file, "[" . date('Y-m-d H:i:s') . "] $message\n", FILE_APPEND);
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
        $nom = trim($nom);
        $prenom = trim($prenom);
        
        // TRIM() empêche de recréer un nageur s'il y a un espace en trop dans l'API
        $stmt = $this->pdo->prepare('SELECT id FROM nageurs WHERE TRIM(nom) = ? AND TRIM(prenom) = ?');
        $stmt->execute([$nom, $prenom]);
        $nageur = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($nageur) return $nageur['id'];

        $stmt = $this->pdo->prepare('INSERT INTO nageurs (nom, prenom, genre, date_naissance) VALUES (?, ?, ?, ?)');
        $stmt->execute([$nom, $prenom, $genre, $date_naissance]);
        return $this->pdo->lastInsertId();
    }

    public function getLogs()
    {
        echo file_exists($this->log_file) ? file_get_contents($this->log_file) : 'Aucun historique.';
    }
}