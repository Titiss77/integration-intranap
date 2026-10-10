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

    private function isLocalRequest()
    {
        return in_array(
            $_SERVER['REMOTE_ADDR'] ?? '',
            ['127.0.0.1', '::1', '::ffff:127.0.0.1'],
            true
        );
    }

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
     * Retourne la saison sportive actuelle.
     *
     * Exemple :
     * - octobre 2026 -> 2026-2027
     * - janvier 2027 -> 2026-2027
     * - septembre 2027 -> 2027-2028
     */
    private function getCurrentSeason()
    {
        $annee = (int)date('Y');
        $mois = (int)date('n');

        if ($mois >= 9) {
            $anneeDebut = $annee;
        } else {
            $anneeDebut = $annee - 1;
        }

        return $anneeDebut . '-' . ($anneeDebut + 1);
    }

    /**
     * Normalise une saison.
     *
     * Accepte :
     * - 2026
     * - 2026-2027
     *
     * Retourne toujours :
     * - 2026-2027
     */
    private function normalizeSeason($saison)
    {
        $saison = trim((string)$saison);

        if ($saison === '') {
            return $this->getCurrentSeason();
        }

        /*
         * Ancien format :
         * 2026
         */
        if (preg_match('/^(\d{4})$/', $saison, $matches)) {

            $anneeDebut = (int)$matches[1];

            return $anneeDebut . '-' . ($anneeDebut + 1);
        }

        /*
         * Nouveau format :
         * 2026-2027
         */
        if (
            preg_match(
                '/^(\d{4})-(\d{4})$/',
                $saison,
                $matches
            )
        ) {

            $anneeDebut = (int)$matches[1];
            $anneeFin = (int)$matches[2];

            if ($anneeFin !== $anneeDebut + 1) {
                throw new Exception(
                    'Saison invalide. Format attendu : YYYY-YYYY.'
                );
            }

            return $anneeDebut . '-' . $anneeFin;
        }

        throw new Exception(
            'Saison invalide. Format attendu : YYYY ou YYYY-YYYY.'
        );
    }

    /**
     * Retourne l'annee de fin attendue par l'API FFESSM.
     * Exemple : 2026-2027 -> 2027.
     */
    private function getApiYearFromSeason($saison)
    {
        if (
            preg_match(
                '/^(\d{4})-(\d{4})$/',
                $saison,
                $matches
            )
        ) {
            return (int)$matches[2];
        }

        return (int)$saison;
    }

    /** Retourne la saison sportive correspondant à une date de performance. */
    private function getSeasonFromPerformanceDate($date, $fallbackSeason)
    {
        $date = trim((string)$date);

        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $date, $matches)) {
            $month = (int)$matches[2];
            $year = (int)$matches[3];
        } elseif (preg_match('/^(\d{4})[\/.\-](\d{1,2})[\/.\-](\d{1,2})$/', $date, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
        } else {
            return $fallbackSeason;
        }

        $startYear = $month >= 9 ? $year : $year - 1;
        return $startYear . '-' . ($startYear + 1);
    }

    private function getOrCreateSeasonId($saison)
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM saisons WHERE nom_saison = ? LIMIT 1'
        );
        $stmt->execute([$saison]);
        $saisonId = $stmt->fetchColumn();

        if (!$saisonId) {
            $stmt = $this->pdo->prepare(
                'INSERT IGNORE INTO saisons (nom_saison) VALUES (?)'
            );
            $stmt->execute([$saison]);
            $stmt = $this->pdo->prepare(
                'SELECT id FROM saisons WHERE nom_saison = ? LIMIT 1'
            );
            $stmt->execute([$saison]);
            $saisonId = $stmt->fetchColumn();
        }

        return (int)$saisonId;
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
     *
     * La BDD utilise une saison complète :
     * 2026-2027
     *
     * L'API FFESSM reçoit uniquement :
     * 2026
     */
    public function syncData($token_recu = '')
    {
        header(
            'Content-Type: application/json; charset=utf-8'
        );

        header(
            'Cache-Control: no-cache, must-revalidate'
        );

        if (!$this->isLocalRequest()) {
            http_response_code(403);
            echo json_encode(['error' => true, 'message' => 'Synchronisation disponible uniquement en local.']);
            return;
        }

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

        $epreuve = trim($_POST['epreuve'] ?? '');

        $cat_code = strtoupper(
            trim(
                $_POST['genre'] ?? ''
            )
        );

        $etape = $_POST['etape'] ?? 'suite';
        if (!in_array($etape, ['debut', 'suite', 'fin'], true)) {
            echo json_encode(['error' => true, 'message' => 'Étape de synchronisation invalide.']);
            return;
        }

        $epreuves_autorisees = [
            '50SF', '100SF', '200SF', '400SF', '800SF', '1500SF',
            '50AP', '100IS', '800IS', '200IS', '400IS',
            '50BI', '100BI', '200BI', '400BI'
        ];
        if (!in_array($epreuve, $epreuves_autorisees, true)) {
            echo json_encode(['error' => true, 'message' => 'Épreuve invalide.']);
            return;
        }

        /*
         * ------------------------------------------------------------
         * SAISON SPORTIVE
         * ------------------------------------------------------------
         *
         * Exemple :
         * 2026-2027
         */
        $saison_recue = $_POST['saison'] ?? $this->getCurrentSeason();

        try {

            $saison =
                $this->normalizeSeason(
                    $saison_recue
                );

            /*
             * Année de fin envoyée à la FFESSM.
             *
             * 2026-2027 -> 2027
             */
            $annee_api =
                $this->getApiYearFromSeason(
                    $saison
                );

        } catch (
            Exception $e
        ) {

            echo json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]);

            return;
        }

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

        $saison_courante = $this->getCurrentSeason();
        $this->getOrCreateSeasonId($saison);
        $this->getOrCreateSeasonId($saison_courante);

        if ($etape === 'debut') {

            $this->startSyncDelta();

            $this->writeToLog(
                '--- DÉBUT DE SYNCHRONISATION ---'
            );

            $this->logger->separator();

            $this->logger->info(
                'START',
                '--- DÉBUT DE SYNCHRONISATION ---'
            );
        }

        $this->recordSyncDeltaSeason($saison);
        $this->recordSyncDeltaSeason($saison_courante);

        $this->logger->info(
            'API_CALL',
            "Requete: {$epreuve} | Genre: {$cat_code} | Saison: {$saison} | API: {$annee_api}"
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
                       AND saison_id = ?
                       AND lieu_id = ?
                       AND date_perf = ?
                       AND temps = ?
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
                        saison_id,
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

                /*
                 * IMPORTANT :
                 * l'API reçoit 2026,
                 * pas 2026-2027.
                 */
                'saison' => $annee_api,

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

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);

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

            if ($http_code < 200 || $http_code >= 300) {
                throw new Exception("La FFESSM a répondu avec le code HTTP {$http_code}.");
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
                    $blacklist as $bl_nom
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

                $date_perf = trim($n['date'] ?? '');
                $saison_performance = $this->getSeasonFromPerformanceDate(
                    $date_perf,
                    $saison
                );

                // Skip all side effects for rows outside the requested season.
                if ($saison_performance !== $saison) {
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

                $saison_performance_id =
                    $this->getOrCreateSeasonId($saison_performance);

                $this->registerClubMembership(
                    $nageur_id,
                    $saison_performance_id
                );

                /*
                 * ----------------------------------------------------
                 * VERIFICATION D'EXISTENCE
                 * ----------------------------------------------------
                 */

                $stmtCheckPerf->execute([
                    $nageur_id,
                    $epreuve_id,
                    $saison_performance_id,
                    $lieu_id,
                    $date_perf,
                    $temps_final
                ]);

                $existingPerf =
                    $stmtCheckPerf->fetch(
                        PDO::FETCH_ASSOC
                    );

                /*
                 * La performance existe déjà.
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
                    $saison_performance_id,
                    $temps_final,
                    $date_perf
                ]);

                if (
                    $stmtAddPerf->rowCount() > 0
                ) {

                    $this->recordSyncDeltaPerformance(
                        (int)$this->pdo->lastInsertId()
                    );

                    $nb_insertions++;

                    $info = sprintf(
                        "%s %s (%s / %s) | Saison : %s | Ajout temps : %s | Lieu : %s",
                        $prenom_nageur,
                        $nom_nageur,
                        $epreuve,
                        $categorie !== ''
                            ? $categorie
                            : 'NC',
                        $saison_performance,
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

                $this->completeSyncDelta();

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
                    "Traitement de {$epreuve} pour la saison {$saison} terminé. " .
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

    /** Enregistre la saison observée dans le roster du club filtré. */
    private function registerClubMembership($nageur_id, $saison_id)
    {
        $code = strtoupper(trim($this->club_cible));
        if ($code === '') {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO clubs (code, nom) VALUES (?, ?)'
        );
        $stmt->execute([
            $code,
            $_ENV['CLUB_NAME'] ?? 'Palmes en Cornouailles'
        ]);

        $stmt = $this->pdo->prepare('SELECT id FROM clubs WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $club_id = $stmt->fetchColumn();
        if (!$club_id) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO club_memberships (club_id, nageur_id, saison_id)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$club_id, $nageur_id, $saison_id]);
    }

    /**
     * Exporte la base locale sous forme de INSERT IGNORE portables.
     * Les relations sont résolues par les clés métier, jamais par les IDs locaux.
     */
    private function syncDeltaPath($name)
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'sync_delta_' . $name . '.json';
    }

    private function writeSyncDelta($path, $state)
    {
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function startSyncDelta()
    {
        $this->writeSyncDelta($this->syncDeltaPath('pending'), [
            'status' => 'running',
            'started_at' => date('c'),
            'performance_ids' => [],
            'season_names' => []
        ]);
    }

    private function recordSyncDeltaSeason($seasonName)
    {
        $path = $this->syncDeltaPath('pending');
        if (!is_file($path)) {
            return;
        }

        $state = json_decode(file_get_contents($path), true);
        if (!is_array($state) || ($state['status'] ?? '') !== 'running') {
            return;
        }

        $state['season_names'][] = (string)$seasonName;
        $state['season_names'] = array_values(array_unique($state['season_names']));
        $this->writeSyncDelta($path, $state);
    }

    private function recordSyncDeltaPerformance($performanceId)
    {
        $path = $this->syncDeltaPath('pending');
        if (!is_file($path)) {
            return;
        }

        $state = json_decode(file_get_contents($path), true);
        if (!is_array($state) || ($state['status'] ?? '') !== 'running') {
            return;
        }

        $state['performance_ids'][] = (int)$performanceId;
        $state['performance_ids'] = array_values(array_unique($state['performance_ids']));
        $this->writeSyncDelta($path, $state);
    }

    private function completeSyncDelta()
    {
        $pendingPath = $this->syncDeltaPath('pending');
        if (!is_file($pendingPath)) {
            return;
        }

        $state = json_decode(file_get_contents($pendingPath), true);
        if (!is_array($state) || ($state['status'] ?? '') !== 'running') {
            return;
        }

        $state['status'] = 'complete';
        $state['completed_at'] = date('c');
        $this->writeSyncDelta($this->syncDeltaPath('latest'), $state);
        @unlink($pendingPath);
    }

    public function exportSql($token_recu = '')
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        if (
            empty($_SESSION['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $token_recu)
        ) {
            http_response_code(403);
            exit('Jeton CSRF invalide.');
        }

        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
            http_response_code(403);
            exit('Export disponible uniquement en local.');
        }

        $statePath = $this->syncDeltaPath('latest');
        if (!is_file($statePath)) {
            http_response_code(409);
            exit('Aucune synchronisation terminee disponible. Lancez une synchronisation.');
        }

        $state = json_decode(file_get_contents($statePath), true);
        if (!is_array($state) || ($state['status'] ?? '') !== 'complete') {
            http_response_code(409);
            exit('La derniere synchronisation est incomplete.');
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $state['performance_ids'] ?? []),
            function ($id) {
                return $id > 0;
            }
        )));

        $placeholders = $ids
            ? implode(',', $ids)
            : 'NULL';

        $sqlValue = function ($value) {
            return $value === null ? 'NULL' : $this->pdo->quote((string)$value);
        };

        $lines = [
            '-- Fusion idempotente des données locales dans la base en ligne.',
            '-- Les lignes déjà présentes sont ignorées.',
            'SET NAMES utf8mb4;',
            'START TRANSACTION;',
            ''
        ];

        $insertRows = function ($table, $columns, $rows) use (&$lines, $sqlValue) {
            foreach ($rows as $row) {
                $values = array_map($sqlValue, array_values($row));
                $quotedColumns = array_map(function ($column) {
                    return '`' . $column . '`';
                }, $columns);

                $lines[] = 'INSERT IGNORE INTO `' . $table . '` (' .
                    implode(', ', $quotedColumns) . ') VALUES (' .
                    implode(', ', $values) . ');';
            }
            $lines[] = '';
        };

        $seasonRows = $this->pdo
            ->query('SELECT DISTINCT s.nom_saison FROM saisons s JOIN performances p ON p.saison_id = s.id WHERE p.id IN (' . $placeholders . ')')
            ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($state['season_names'] ?? [] as $seasonName) {
            $seasonRows[] = ['nom_saison' => $seasonName];
        }
        $seasonRows[] = ['nom_saison' => $this->getCurrentSeason()];

        $uniqueSeasonRows = [];
        foreach ($seasonRows as $seasonRow) {
            $uniqueSeasonRows[$seasonRow['nom_saison']] = $seasonRow;
        }
        $insertRows('saisons', ['nom_saison'], array_values($uniqueSeasonRows));

        $insertRows('categories', ['nom_categorie', 'libelle'], $this->pdo
            ->query('SELECT DISTINCT c.nom_categorie, c.libelle FROM categories c JOIN performances p ON p.categorie_id = c.id WHERE p.id IN (' . $placeholders . ')')
            ->fetchAll(PDO::FETCH_ASSOC));

        $insertRows('epreuves', ['nom_epreuve'], $this->pdo
            ->query('SELECT DISTINCT e.nom_epreuve FROM epreuves e JOIN performances p ON p.epreuve_id = e.id WHERE p.id IN (' . $placeholders . ')')
            ->fetchAll(PDO::FETCH_ASSOC));

        $insertRows('lieux', ['nom_lieu'], $this->pdo
            ->query('SELECT DISTINCT l.nom_lieu FROM lieux l JOIN performances p ON p.lieu_id = l.id WHERE p.id IN (' . $placeholders . ')')
            ->fetchAll(PDO::FETCH_ASSOC));

        $insertRows('nageurs', ['nom', 'prenom', 'genre', 'date_naissance'], $this->pdo
            ->query('SELECT DISTINCT n.nom, n.prenom, n.genre, n.date_naissance FROM nageurs n JOIN performances p ON p.nageur_id = n.id WHERE p.id IN (' . $placeholders . ')')
            ->fetchAll(PDO::FETCH_ASSOC));

        $clubCode = strtoupper(trim($this->club_cible));
        $clubName = $_ENV['CLUB_NAME'] ?? 'Palmes en Cornouailles';
        $lines[] = 'INSERT IGNORE INTO `clubs` (`code`, `nom`) VALUES (' .
            $sqlValue($clubCode) . ', ' . $sqlValue($clubName) . ');';

        $memberships = $this->pdo->query(
            'SELECT DISTINCT n.nom, n.prenom, s.nom_saison
             FROM club_memberships cm
             JOIN clubs c ON c.id = cm.club_id
             JOIN nageurs n ON n.id = cm.nageur_id
             JOIN saisons s ON s.id = cm.saison_id
             JOIN performances p ON p.nageur_id = cm.nageur_id AND p.saison_id = cm.saison_id
             WHERE c.code = ' . $this->pdo->quote($clubCode) . '
               AND p.id IN (' . $placeholders . ')'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($memberships as $membership) {
            $lines[] = 'INSERT IGNORE INTO `club_memberships` (`club_id`, `nageur_id`, `saison_id`) ' .
                'SELECT (SELECT id FROM clubs WHERE code = ' . $sqlValue($clubCode) . ' LIMIT 1), ' .
                '(SELECT id FROM nageurs WHERE nom = ' . $sqlValue($membership['nom']) .
                    ' AND prenom = ' . $sqlValue($membership['prenom']) . ' LIMIT 1), ' .
                '(SELECT id FROM saisons WHERE nom_saison = ' . $sqlValue($membership['nom_saison']) . ' LIMIT 1);';
        }
        $lines[] = '';

        $performances = $this->pdo->query(
            'SELECT
                n.nom,
                n.prenom,
                e.nom_epreuve,
                c.nom_categorie,
                l.nom_lieu,
                s.nom_saison,
                p.temps,
                p.date_perf
             FROM performances p
             JOIN nageurs n ON n.id = p.nageur_id
             JOIN epreuves e ON e.id = p.epreuve_id
             JOIN categories c ON c.id = p.categorie_id
             JOIN lieux l ON l.id = p.lieu_id
             JOIN saisons s ON s.id = p.saison_id
             WHERE p.id IN (' . $placeholders . ')
             ORDER BY p.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($performances as $performance) {
            $lines[] = 'INSERT IGNORE INTO `performances` ' .
                '(`nageur_id`, `epreuve_id`, `categorie_id`, `lieu_id`, `saison_id`, `temps`, `date_perf`) ' .
                'SELECT ' .
                '(SELECT id FROM nageurs WHERE nom = ' . $sqlValue($performance['nom']) .
                    ' AND prenom = ' . $sqlValue($performance['prenom']) . ' LIMIT 1), ' .
                '(SELECT id FROM epreuves WHERE nom_epreuve = ' . $sqlValue($performance['nom_epreuve']) . ' LIMIT 1), ' .
                '(SELECT id FROM categories WHERE nom_categorie = ' . $sqlValue($performance['nom_categorie']) . ' LIMIT 1), ' .
                '(SELECT id FROM lieux WHERE nom_lieu = ' . $sqlValue($performance['nom_lieu']) . ' LIMIT 1), ' .
                '(SELECT id FROM saisons WHERE nom_saison = ' . $sqlValue($performance['nom_saison']) . ' LIMIT 1), ' .
                $sqlValue($performance['temps']) . ', ' .
                $sqlValue($performance['date_perf']) . ';';
        }

        $lines[] = '';
        $lines[] = 'COMMIT;';

        $filename = 'synchronisation_pec_' . date('Ymd_His') . '.sql';
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        echo implode("\n", $lines);
    }

    /**
     * Affiche les logs de synchronisation.
     */
    public function getLogs()
    {
        if (!$this->isLocalRequest()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Accès refusé.');
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo file_exists(
            $this->log_file
        )
            ? file_get_contents(
                $this->log_file
            )
            : 'Aucun historique.';
    }
}
