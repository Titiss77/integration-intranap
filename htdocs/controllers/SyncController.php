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
        $this->club_cible = strtoupper(trim($_ENV['API_CLUB'] ?? ''));

        $this->log_file = __DIR__ . '/../sync_modifications.log';

        $this->logger = new SyncLogger('sync_debug.log');
    }

    /**
     * Normalise un temps vers le format :
     * MM:SS.CC
     */
    private function normalizeTime($temps_brut)
    {
        $t = trim(
            str_replace(
                ',',
                '.',
                (string)$temps_brut
            )
        );

        if ($t === '') {
            return '';
        }

        if (strpos($t, ':') !== false) {

            $parts = explode(':', $t, 2);

            $minutes = str_pad(
                trim($parts[0]),
                2,
                '0',
                STR_PAD_LEFT
            );

            $secParts = explode(
                '.',
                trim($parts[1]),
                2
            );

            $secondes = str_pad(
                $secParts[0],
                2,
                '0',
                STR_PAD_LEFT
            );

            $centiemes = isset($secParts[1])
                ? str_pad(
                    substr(
                        $secParts[1],
                        0,
                        2
                    ),
                    2,
                    '0',
                    STR_PAD_RIGHT
                )
                : '00';

            return $minutes . ':' . $secondes . '.' . $centiemes;
        }

        $secParts = explode(
            '.',
            $t,
            2
        );

        $secondes = str_pad(
            $secParts[0],
            2,
            '0',
            STR_PAD_LEFT
        );

        $centiemes = isset($secParts[1])
            ? str_pad(
                substr(
                    $secParts[1],
                    0,
                    2
                ),
                2,
                '0',
                STR_PAD_RIGHT
            )
            : '00';

        return '00:' . $secondes . '.' . $centiemes;
    }

    /**
     * Normalise une catégorie.
     */
    private function normalizeCategory($categorie)
    {
        return strtoupper(
            trim(
                (string)$categorie
            )
        );
    }

    /**
     * Vérifie si une ligne correspond
     * à un nageur français.
     */
    private function isFrench($n)
    {
        $nat = isset($n['nat'])
            ? strtoupper(
                trim(
                    (string)$n['nat']
                )
            )
            : 'FRA';

        return $nat === 'FRA';
    }

    /**
     * Synchronisation des performances.
     *
     * Aucun classement n'est calculé,
     * enregistré ou mis à jour.
     */
    public function syncData($token_recu = '')
    {
        header(
            'Content-Type: application/json; charset=utf-8'
        );

        header(
            'Cache-Control: no-cache, must-revalidate'
        );

        if (
            PHP_SESSION_NONE === session_status()
        ) {
            session_start();
        }

        if (
            empty($_SESSION['csrf_token']) ||
            !hash_equals(
                $_SESSION['csrf_token'],
                $token_recu
            )
        ) {
            echo json_encode([
                'error' => true,
                'message' =>
                    'Erreur de sécurité (Jeton CSRF invalide).'
            ]);

            return;
        }

        if (
            PHP_SESSION_ACTIVE === session_status()
        ) {
            session_write_close();
        }

        $epreuve = trim(
            $_GET['epreuve'] ?? ''
        );

        $cat_code = strtoupper(
            trim(
                $_GET['genre'] ?? ''
            )
        );

        $etape = $_GET['etape'] ?? 'suite';

        $saison = $_GET['saison'] ?? date('Y');

        if (
            $epreuve === '' ||
            $cat_code === ''
        ) {
            echo json_encode([
                'error' => true,
                'message' => 'Paramètres manquants.'
            ]);

            return;
        }

        $categories_genre = [
            'F' => 'Femmes',
            'M' => 'Hommes'
        ];

        if (
            !array_key_exists(
                $cat_code,
                $categories_genre
            )
        ) {
            echo json_encode([
                'error' => true,
                'message' => 'Genre invalide.'
            ]);

            return;
        }

        $cat_nom = $categories_genre[$cat_code];

        if ($etape === 'debut') {

            $this->writeToLog(
                '--- DÉBUT DE SYNCHRONISATION ---'
            );

            $this->logger->separator();

            $this->logger->info(
                'START',
                '--- DÉBUT DE SYNCHRONISATION ---'
            );
        }

        $this->logger->info(
            'API_CALL',
            "Requete: {$epreuve} | Genre: {$cat_code} | Saison: {$saison}"
        );

        /*
         * ------------------------------------------------------------
         * BLACKLIST
         * ------------------------------------------------------------
         */

        $blacklist = [];

        $chemin_blacklist =
            __DIR__ . '/../blacklist.txt';

        if (
            file_exists(
                $chemin_blacklist
            )
        ) {

            $lignes = file(
                $chemin_blacklist,
                FILE_IGNORE_NEW_LINES |
                FILE_SKIP_EMPTY_LINES
            );

            foreach ($lignes as $ligne) {

                $ligne = trim($ligne);

                if ($ligne === '') {
                    continue;
                }

                if (
                    strpos(
                        $ligne,
                        '#'
                    ) === 0
                ) {
                    continue;
                }

                $blacklist[] =
                    mb_strtolower(
                        $ligne,
                        'UTF-8'
                    );
            }
        }

        try {

            /*
             * --------------------------------------------------------
             * EPREUVE
             * --------------------------------------------------------
             */

            $epreuve_id =
                $this->getOrCreateSimple(
                    'epreuves',
                    'nom_epreuve',
                    $epreuve
                );

            /*
             * --------------------------------------------------------
             * REQUETE DE VERIFICATION
             * --------------------------------------------------------
             */

            $stmtCheckPerf =
                $this->pdo->prepare(
                    'SELECT id
                     FROM performances
                     WHERE nageur_id = ?
                       AND epreuve_id = ?
                       AND saison = ?
                       AND temps = ?
                       AND date_perf = ?
                     LIMIT 1'
                );

            /*
             * --------------------------------------------------------
             * INSERTION
             * --------------------------------------------------------
             */

            $stmtAddPerf =
                $this->pdo->prepare(
                    'INSERT INTO performances
                    (
                        nageur_id,
                        epreuve_id,
                        categorie_id,
                        lieu_id,
                        saison,
                        temps,
                        date_perf
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?)'
                );

            /*
             * --------------------------------------------------------
             * APPEL API FFESSM
             * --------------------------------------------------------
             */

            $params = [
                'action' => 'gettop',
                'course' => $epreuve,
                'saison' => $saison,
                'category' => $cat_code,
                'token' => $this->token,
                'clubid' => '0',
                'order' => 'tps',
                'nocache' => time()
            ];

            $url_complete =
                $this->url .
                '?' .
                http_build_query($params);

            $ch = curl_init();

            curl_setopt(
                $ch,
                CURLOPT_URL,
                $url_complete
            );

            curl_setopt(
                $ch,
                CURLOPT_RETURNTRANSFER,
                true
            );

            curl_setopt(
                $ch,
                CURLOPT_SSL_VERIFYPEER,
                false
            );

            curl_setopt(
                $ch,
                CURLOPT_SSL_VERIFYHOST,
                false
            );

            $cookie_file =
                __DIR__ .
                '/../cookie_ffessm.txt';

            curl_setopt(
                $ch,
                CURLOPT_COOKIEJAR,
                $cookie_file
            );

            curl_setopt(
                $ch,
                CURLOPT_COOKIEFILE,
                $cookie_file
            );

            curl_setopt(
                $ch,
                CURLOPT_TIMEOUT,
                20
            );

            curl_setopt(
                $ch,
                CURLOPT_CONNECTTIMEOUT,
                10
            );

            curl_setopt(
                $ch,
                CURLOPT_FOLLOWLOCATION,
                true
            );

            curl_setopt(
                $ch,
                CURLOPT_USERAGENT,
                'Mozilla/5.0'
            );

            curl_setopt(
                $ch,
                CURLOPT_ENCODING,
                ''
            );

            curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                [
                    'Accept: application/json',
                    'Cache-Control: no-cache'
                ]
            );

            usleep(
                rand(
                    800000,
                    2000000
                )
            );

            $response = curl_exec($ch);

            $http_code =
                curl_getinfo(
                    $ch,
                    CURLINFO_HTTP_CODE
                );

            $curl_error =
                curl_error($ch);

            curl_close($ch);

            if (
                $response === false ||
                $curl_error !== ''
            ) {
                throw new Exception(
                    "Erreur réseau cURL ({$http_code}) : {$curl_error}"
                );
            }

            if (
                trim($response) === ''
            ) {
                throw new Exception(
                    'La réponse de la FFESSM est vide.'
                );
            }

            $donnees =
                json_decode(
                    $response,
                    true
                );

            if (
                !is_array($donnees)
            ) {
                throw new Exception(
                    'La réponse de la FFESSM n\'est pas un JSON valide.'
                );
            }

            /*
             * --------------------------------------------------------
             * TRAITEMENT DES PERFORMANCES
             * --------------------------------------------------------
             */

            $nb_insertions = 0;

            foreach ($donnees as $n) {

                if (
                    !is_array($n)
                ) {
                    continue;
                }

                /*
                 * Nageur français uniquement.
                 */
                if (
                    !$this->isFrench($n)
                ) {
                    continue;
                }

                $nom_nageur =
                    trim(
                        $n['nom'] ?? ''
                    );

                $prenom_nageur =
                    trim(
                        $n['prenom'] ?? ''
                    );

                $temps_brut =
                    trim(
                        $n['temps'] ?? ''
                    );

                $categorie =
                    $this->normalizeCategory(
                        $n['categorie'] ?? ''
                    );

                if (
                    $nom_nageur === '' ||
                    $prenom_nageur === '' ||
                    $temps_brut === ''
                ) {
                    continue;
                }

                /*
                 * Normalisation du temps.
                 */
                $temps_final =
                    $this->normalizeTime(
                        $temps_brut
                    );

                if (
                    $temps_final === ''
                ) {
                    continue;
                }

                /*
                 * Club.
                 */
                $club_nageur =
                    strtoupper(
                        trim(
                            $n['club'] ?? ''
                        )
                    );

                if (
                    $club_nageur !==
                    $this->club_cible
                ) {
                    continue;
                }

                /*
                 * Blacklist.
                 */
                $nom_complet_normalise =
                    mb_strtolower(
                        $nom_nageur .
                        ' ' .
                        $prenom_nageur,
                        'UTF-8'
                    );

                $prenom_nom_normalise =
                    mb_strtolower(
                        $prenom_nageur .
                        ' ' .
                        $nom_nageur,
                        'UTF-8'
                    );

                $est_blacklist = false;

                foreach (
                    $blacklist
                    as $bl_nom
                ) {

                    if (
                        $nom_complet_normalise ===
                        $bl_nom ||
                        $prenom_nom_normalise ===
                        $bl_nom
                    ) {
                        $est_blacklist = true;
                        break;
                    }
                }

                if (
                    $est_blacklist
                ) {
                    continue;
                }

                /*
                 * ----------------------------------------------------
                 * NAGEUR
                 * ----------------------------------------------------
                 */

                $nageur_id =
                    $this->getOrCreateNageur(
                        $nom_nageur,
                        $prenom_nageur,
                        $cat_nom,
                        null
                    );

                /*
                 * ----------------------------------------------------
                 * CATEGORIE
                 * ----------------------------------------------------
                 */

                $categorie_id =
                    $this->getOrCreateSimple(
                        'categories',
                        'nom_categorie',
                        $categorie !== ''
                            ? $categorie
                            : 'NC'
                    );

                /*
                 * ----------------------------------------------------
                 * LIEU
                 * ----------------------------------------------------
                 */

                $lieu_id =
                    $this->getOrCreateSimple(
                        'lieux',
                        'nom_lieu',
                        $n['lieu'] ?? 'NC'
                    );

                /*
                 * ----------------------------------------------------
                 * DATE
                 * ----------------------------------------------------
                 */

                $date_perf =
                    trim(
                        $n['date'] ?? ''
                    );

                if (
                    $temps_final === ''
                ) {
                    continue;
                }

                /*
                 * ----------------------------------------------------
                 * VERIFICATION D'EXISTENCE
                 * ----------------------------------------------------
                 */

                $stmtCheckPerf->execute([
                    $nageur_id,
                    $epreuve_id,
                    $saison,
                    $temps_final,
                    $date_perf
                ]);

                $existingPerf =
                    $stmtCheckPerf->fetch(
                        PDO::FETCH_ASSOC
                    );

                /*
                 * La performance existe déjà.
                 * Rien à modifier.
                 */
                if (
                    $existingPerf
                ) {
                    continue;
                }

                /*
                 * ----------------------------------------------------
                 * NOUVELLE PERFORMANCE
                 * ----------------------------------------------------
                 */

                $stmtAddPerf->execute([
                    $nageur_id,
                    $epreuve_id,
                    $categorie_id,
                    $lieu_id,
                    $saison,
                    $temps_final,
                    $date_perf
                ]);

                if (
                    $stmtAddPerf->rowCount() > 0
                ) {

                    $nb_insertions++;

                    $info = sprintf(
                        "%s %s (%s / %s) | Ajout temps : %s | Lieu : %s",
                        $prenom_nageur,
                        $nom_nageur,
                        $epreuve,
                        $categorie !== ''
                            ? $categorie
                            : 'NC',
                        $temps_final,
                        $n['lieu'] ?? 'NC'
                    );

                    $this->writeToLog(
                        '[NOUVEAU TEMPS] ' .
                        $info
                    );

                    $this->logger->info(
                        'INSERT',
                        $info
                    );
                }
            }

            /*
             * --------------------------------------------------------
             * FIN
             * --------------------------------------------------------
             */

            if (
                $etape === 'fin'
            ) {

                $this->writeToLog(
                    '--- FIN DE SYNCHRONISATION ---'
                );

                $this->logger->info(
                    'END',
                    '--- FIN DE SYNCHRONISATION ---'
                );
            }

            echo json_encode([
                'error' => false,
                'message' =>
                    "Traitement de {$epreuve} terminé. " .
                    "{$nb_insertions} nouvelle(s) performance(s)."
            ]);

        } catch (
            Exception $e
        ) {

            $this->logger->info(
                'ERROR',
                $e->getMessage()
            );

            echo json_encode([
                'error' => true,
                'message' =>
                    'Erreur : ' .
                    $e->getMessage()
            ]);
        }
    }

    /**
     * Ecrit dans le journal principal.
     */
    private function writeToLog(
        $message
    ) {
        file_put_contents(
            $this->log_file,
            '[' .
            date('Y-m-d H:i:s') .
            '] ' .
            $message .
            PHP_EOL,
            FILE_APPEND |
            LOCK_EX
        );
    }

    /**
     * Récupère ou crée une valeur simple.
     */
    private function getOrCreateSimple(
        $table,
        $column,
        $value
    ) {
        $stmt =
            $this->pdo->prepare(
                "INSERT IGNORE INTO {$table} ({$column}) VALUES (?)"
            );

        $stmt->execute([
            $value
        ]);

        $stmt =
            $this->pdo->prepare(
                "SELECT id
                 FROM {$table}
                 WHERE {$column} = ?"
            );

        $stmt->execute([
            $value
        ]);

        return $stmt->fetchColumn();
    }

    /**
     * Récupère ou crée un nageur.
     */
    private function getOrCreateNageur(
        $nom,
        $prenom,
        $genre,
        $date_naissance
    ) {
        $nom = trim($nom);
        $prenom = trim($prenom);

        $stmt =
            $this->pdo->prepare(
                'SELECT id
                 FROM nageurs
                 WHERE TRIM(nom) = ?
                   AND TRIM(prenom) = ?
                 LIMIT 1'
            );

        $stmt->execute([
            $nom,
            $prenom
        ]);

        $nageur =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (
            $nageur
        ) {
            return $nageur['id'];
        }

        $stmt =
            $this->pdo->prepare(
                'INSERT INTO nageurs
                (
                    nom,
                    prenom,
                    genre,
                    date_naissance
                )
                VALUES (?, ?, ?, ?)'
            );

        $stmt->execute([
            $nom,
            $prenom,
            $genre,
            $date_naissance
        ]);

        return $this->pdo->lastInsertId();
    }

    /**
     * Affiche les logs de synchronisation.
     */
    public function getLogs()
    {
        echo file_exists(
            $this->log_file
        )
            ? file_get_contents(
                $this->log_file
            )
            : 'Aucun historique.';
    }
}