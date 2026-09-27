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
     *
     * Exemples :
     * 17.50   -> 00:17.50
     * 54.72   -> 00:54.72
     * 1:02.35 -> 01:02.35
     */
    private function normalizeTime($temps_brut)
    {
        $t = trim(str_replace(',', '.', (string)$temps_brut));

        if ($t === '') {
            return '';
        }

        if (strpos($t, ':') !== false) {
            $parts = explode(':', $t, 2);

            $minutes = str_pad(trim($parts[0]), 2, '0', STR_PAD_LEFT);

            $secParts = explode('.', trim($parts[1]), 2);

            $secondes = str_pad($secParts[0], 2, '0', STR_PAD_LEFT);

            $centiemes = isset($secParts[1])
                ? str_pad(substr($secParts[1], 0, 2), 2, '0', STR_PAD_RIGHT)
                : '00';

            return $minutes . ':' . $secondes . '.' . $centiemes;
        }

        $secParts = explode('.', $t, 2);

        $secondes = str_pad($secParts[0], 2, '0', STR_PAD_LEFT);

        $centiemes = isset($secParts[1])
            ? str_pad(substr($secParts[1], 0, 2), 2, '0', STR_PAD_RIGHT)
            : '00';

        return '00:' . $secondes . '.' . $centiemes;
    }

    /**
     * Convertit un temps en secondes.
     *
     * Permet de comparer correctement :
     * 9.95 < 10.02
     *
     * et évite les comparaisons de chaînes.
     */
    private function timeToSeconds($temps)
    {
        $temps = str_replace(',', '.', trim((string)$temps));

        if ($temps === '') {
            return PHP_FLOAT_MAX;
        }

        if (strpos($temps, ':') !== false) {
            $parts = explode(':', $temps, 2);

            $minutes = (float)$parts[0];
            $secondes = (float)$parts[1];

            return ($minutes * 60) + $secondes;
        }

        return (float)$temps;
    }

    /**
     * Normalise une catégorie.
     *
     * Exemples :
     * SH -> SH
     * sh -> SH
     * "SH " -> SH
     */
    private function normalizeCategory($categorie)
    {
        return strtoupper(trim((string)$categorie));
    }

    /**
     * Crée une clé stable pour identifier un nageur.
     *
     * On utilise le nom + prénom car l'API gettop ne fournit
     * pas nécessairement le même identifiant que notre BDD.
     */
    private function swimmerKey($nom, $prenom)
    {
        $nom = mb_strtolower(trim((string)$nom), 'UTF-8');
        $prenom = mb_strtolower(trim((string)$prenom), 'UTF-8');

        return $nom . '|' . $prenom;
    }

    /**
     * Vérifie si une ligne correspond à un nageur français.
     */
    private function isFrench($n)
    {
        $nat = isset($n['nat'])
            ? strtoupper(trim((string)$n['nat']))
            : 'FRA';

        return $nat === 'FRA';
    }

    public function syncData($token_recu = '')
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');

        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        if (
            empty($_SESSION['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $token_recu)
        ) {
            echo json_encode([
                'error' => true,
                'message' => 'Erreur de sécurité (Jeton CSRF invalide).'
            ]);

            return;
        }

        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        $epreuve = trim($_GET['epreuve'] ?? '');
        $cat_code = strtoupper(trim($_GET['genre'] ?? ''));
        $etape = $_GET['etape'] ?? 'suite';
        $saison = $_GET['saison'] ?? date('Y');

        if ($epreuve === '' || $cat_code === '') {
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

        if (!array_key_exists($cat_code, $categories_genre)) {
            echo json_encode([
                'error' => true,
                'message' => 'Genre invalide.'
            ]);

            return;
        }

        $cat_nom = $categories_genre[$cat_code];

        if ($etape === 'debut') {
            $this->writeToLog('--- DÉBUT DE SYNCHRONISATION ---');

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

        $chemin_blacklist = __DIR__ . '/../blacklist.txt';

        if (file_exists($chemin_blacklist)) {
            $lignes = file(
                $chemin_blacklist,
                FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
            );

            foreach ($lignes as $ligne) {
                $ligne = trim($ligne);

                if ($ligne === '') {
                    continue;
                }

                if (strpos($ligne, '#') === 0) {
                    continue;
                }

                $blacklist[] = mb_strtolower(
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

            $epreuve_id = $this->getOrCreateSimple(
                'epreuves',
                'nom_epreuve',
                $epreuve
            );

            /*
             * --------------------------------------------------------
             * REQUETES SQL
             * --------------------------------------------------------
             */

            $stmtCheckPerf = $this->pdo->prepare(
                'SELECT id, classement
                 FROM performances
                 WHERE nageur_id = ?
                   AND epreuve_id = ?
                   AND saison = ?
                   AND temps = ?
                   AND date_perf = ?
                 LIMIT 1'
            );

            $stmtUpdatePerf = $this->pdo->prepare(
                'UPDATE performances
                 SET classement = ?
                 WHERE id = ?'
            );

            $stmtAddPerf = $this->pdo->prepare(
                'INSERT INTO performances
                (
                    nageur_id,
                    epreuve_id,
                    categorie_id,
                    lieu_id,
                    saison,
                    temps,
                    date_perf,
                    classement
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            /*
             * --------------------------------------------------------
             * APPEL API
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

            $url_complete = $this->url . '?' . http_build_query($params);

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url_complete);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $cookie_file = __DIR__ . '/../cookie_ffessm.txt';

            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);

            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

            curl_setopt(
                $ch,
                CURLOPT_USERAGENT,
                'Mozilla/5.0'
            );

            curl_setopt($ch, CURLOPT_ENCODING, '');

            curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                [
                    'Accept: application/json',
                    'Cache-Control: no-cache'
                ]
            );

            usleep(rand(800000, 2000000));

            $response = curl_exec($ch);

            $http_code = curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

            $curl_error = curl_error($ch);

            curl_close($ch);

            if ($response === false || $curl_error !== '') {
                throw new Exception(
                    "Erreur réseau cURL ({$http_code}) : {$curl_error}"
                );
            }

            if (trim($response) === '') {
                throw new Exception(
                    'La réponse de la FFESSM est vide.'
                );
            }

            $donnees = json_decode(
                $response,
                true
            );

            if (!is_array($donnees)) {
                throw new Exception(
                    'La réponse de la FFESSM n\'est pas un JSON valide.'
                );
            }

            /*
             * --------------------------------------------------------
             * ETAPE 1 :
             *
             * On garde uniquement :
             *
             * - les nageurs français
             * - du genre demandé
             * - avec une catégorie valide
             *
             * IMPORTANT :
             *
             * On ne calcule PAS encore le classement.
             *
             * --------------------------------------------------------
             */

            $performances = [];

            foreach ($donnees as $n) {

                if (!is_array($n)) {
                    continue;
                }

                /*
                 * Exclusion des étrangers
                 */
                if (!$this->isFrench($n)) {
                    continue;
                }

                $nom_nageur = trim($n['nom'] ?? '');
                $prenom_nageur = trim($n['prenom'] ?? '');

                $temps_brut = trim($n['temps'] ?? '');

                $categorie = $this->normalizeCategory(
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
                 * Le genre est contrôlé par le paramètre M/F.
                 *
                 * Si l'API fournit une catégorie comme SH, SF, JH,
                 * JF, etc., son premier caractère permet également
                 * de vérifier le sexe.
                 */
                if ($categorie !== '') {

                    $premiere_lettre = substr(
                        $categorie,
                        0,
                        1
                    );

                    if (
                        ($cat_code === 'M' && $premiere_lettre !== 'S' && $premiere_lettre !== 'J' && $premiere_lettre !== 'C')
                        ||
                        ($cat_code === 'F' && $premiere_lettre !== 'S' && $premiere_lettre !== 'J' && $premiere_lettre !== 'C')
                    ) {
                        /*
                         * On ne filtre pas ici sur l'âge :
                         * le classement sera séparé par catégorie
                         * à l'étape suivante.
                         */
                    }
                }

                $temps_final = $this->normalizeTime(
                    $temps_brut
                );

                if ($temps_final === '') {
                    continue;
                }

                $performances[] = [
                    'data' => $n,
                    'nom' => $nom_nageur,
                    'prenom' => $prenom_nageur,
                    'categorie' => $categorie,
                    'temps' => $temps_final,
                    'temps_secondes' => $this->timeToSeconds(
                        $temps_final
                    )
                ];
            }

            /*
             * --------------------------------------------------------
             * ETAPE 2 :
             *
             * Pour chaque nageur + catégorie :
             *
             * on conserve UNIQUEMENT son meilleur temps.
             *
             * C'est important car l'API peut retourner plusieurs
             * performances pour un même nageur.
             * --------------------------------------------------------
             */

            $meilleurs_temps = [];

            foreach ($performances as $perf) {

                $cle_nageur = $this->swimmerKey(
                    $perf['nom'],
                    $perf['prenom']
                );

                $categorie = $perf['categorie'];

                /*
                 * Le classement est indépendant pour chaque catégorie.
                 *
                 * Exemple :
                 *
                 * SH -> classement SH
                 * JH -> classement JH
                 * SF -> classement SF
                 * JF -> classement JF
                 */
                $cle = $categorie . '|' . $cle_nageur;

                if (
                    !isset($meilleurs_temps[$cle]) ||
                    $perf['temps_secondes'] <
                    $meilleurs_temps[$cle]['temps_secondes']
                ) {
                    $meilleurs_temps[$cle] = $perf;
                }
            }

            /*
             * --------------------------------------------------------
             * ETAPE 3 :
             *
             * On regroupe les nageurs par catégorie.
             *
             * C'est ici que se trouve la correction principale.
             * --------------------------------------------------------
             */

            $classements_par_categorie = [];

            foreach ($meilleurs_temps as $perf) {

                $categorie = $perf['categorie'];

                if (!isset($classements_par_categorie[$categorie])) {
                    $classements_par_categorie[$categorie] = [];
                }

                $classements_par_categorie[$categorie][] = $perf;
            }

            /*
             * --------------------------------------------------------
             * ETAPE 4 :
             *
             * Tri numérique par temps.
             *
             * On ne dépend plus de l'ordre retourné par l'API.
             * --------------------------------------------------------
             */

            foreach (
                $classements_par_categorie
                as $categorie => &$liste
            ) {

                usort(
                    $liste,
                    function ($a, $b) {

                        if (
                            $a['temps_secondes'] ==
                            $b['temps_secondes']
                        ) {
                            /*
                             * Départage stable en cas d'égalité parfaite.
                             */
                            $nomA = $a['nom'] . ' ' . $a['prenom'];
                            $nomB = $b['nom'] . ' ' . $b['prenom'];

                            return strcmp(
                                $nomA,
                                $nomB
                            );
                        }

                        return $a['temps_secondes'] <=>
                               $b['temps_secondes'];
                    }
                );
            }

            unset($liste);

            /*
             * --------------------------------------------------------
             * ETAPE 5 :
             *
             * Attribution des rangs.
             *
             * On utilise le classement "compétition".
             *
             * Exemple :
             *
             * 1er
             * 2e
             * 2e
             * 4e
             *
             * et non :
             *
             * 1er
             * 2e
             * 2e
             * 3e
             * --------------------------------------------------------
             */

            $rangs = [];

            foreach (
                $classements_par_categorie
                as $categorie => $liste
            ) {

                $rang = 0;
                $position = 0;
                $dernier_temps = null;

                foreach ($liste as $perf) {

                    $position++;

                    if (
                        $dernier_temps === null ||
                        $perf['temps_secondes'] != $dernier_temps
                    ) {
                        $rang = $position;
                        $dernier_temps = $perf['temps_secondes'];
                    }

                    $cle_nageur = $this->swimmerKey(
                        $perf['nom'],
                        $perf['prenom']
                    );

                    $cle = $categorie . '|' . $cle_nageur;

                    $rangs[$cle] = $rang;
                }
            }

            /*
             * --------------------------------------------------------
             * ETAPE 6 :
             *
             * Mise à jour / insertion dans notre BDD.
             * --------------------------------------------------------
             */

            $nb_updates = 0;
            $nb_insertions = 0;

            foreach ($performances as $perf) {

                $n = $perf['data'];

                $nom_nageur = $perf['nom'];
                $prenom_nageur = $perf['prenom'];
                $categorie = $perf['categorie'];
                $temps_final = $perf['temps'];

                /*
                 * Le classement correspond au meilleur temps
                 * du nageur dans sa catégorie.
                 *
                 * Une performance secondaire n'est donc pas
                 * enregistrée avec un classement différent.
                 */
                $cle_nageur = $this->swimmerKey(
                    $nom_nageur,
                    $prenom_nageur
                );

                $cle = $categorie . '|' . $cle_nageur;

                $position_nationale =
                    $rangs[$cle] ?? null;

                /*
                 * Club
                 */
                $club_nageur = strtoupper(
                    trim($n['club'] ?? '')
                );

                if ($club_nageur !== $this->club_cible) {
                    continue;
                }

                /*
                 * Blacklist
                 */
                $nom_complet_normalise =
                    mb_strtolower(
                        $nom_nageur . ' ' . $prenom_nageur,
                        'UTF-8'
                    );

                $prenom_nom_normalise =
                    mb_strtolower(
                        $prenom_nageur . ' ' . $nom_nageur,
                        'UTF-8'
                    );

                $est_blacklist = false;

                foreach ($blacklist as $bl_nom) {

                    if (
                        $nom_complet_normalise === $bl_nom ||
                        $prenom_nom_normalise === $bl_nom
                    ) {
                        $est_blacklist = true;
                        break;
                    }
                }

                if ($est_blacklist) {
                    continue;
                }

                /*
                 * Nageur
                 */
                $nageur_id = $this->getOrCreateNageur(
                    $nom_nageur,
                    $prenom_nageur,
                    $cat_nom,
                    null
                );

                /*
                 * Catégorie
                 */
                $categorie_id = $this->getOrCreateSimple(
                    'categories',
                    'nom_categorie',
                    $categorie !== ''
                        ? $categorie
                        : 'NC'
                );

                /*
                 * Lieu
                 */
                $lieu_id = $this->getOrCreateSimple(
                    'lieux',
                    'nom_lieu',
                    $n['lieu'] ?? 'NC'
                );

                /*
                 * Date
                 */
                $date_perf = trim(
                    $n['date'] ?? ''
                );

                /*
                 * Sécurité : on ne tente pas de traiter
                 * une performance sans temps.
                 */
                if ($temps_final === '') {
                    continue;
                }

                /*
                 * Recherche de la performance existante.
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

                if ($existingPerf) {

                    /*
                     * Performance déjà présente :
                     * on actualise toujours le classement
                     * si nous avons un rang valide.
                     */
                    $ancienClassement =
                        $existingPerf['classement'] !== null
                            ? (int)$existingPerf['classement']
                            : null;

                    $nouveauClassement =
                        $position_nationale !== null
                            ? (int)$position_nationale
                            : null;

                    if (
                        $nouveauClassement !== null &&
                        $ancienClassement !==
                        $nouveauClassement
                    ) {

                        $stmtUpdatePerf->execute([
                            $nouveauClassement,
                            $existingPerf['id']
                        ]);

                        $nb_updates++;

                        $info = sprintf(
                            "%s %s (%s / %s) | Position : %s -> %s | Temps : %s",
                            $prenom_nageur,
                            $nom_nageur,
                            $epreuve,
                            $categorie !== ''
                                ? $categorie
                                : 'NC',
                            $ancienClassement !== null
                                ? $ancienClassement . 'e'
                                : 'N/C',
                            $nouveauClassement . 'e',
                            $temps_final
                        );

                        $this->writeToLog(
                            '[MAJ CLASSEMENT] ' . $info
                        );

                        $this->logger->info(
                            'RANKING',
                            $info
                        );
                    }

                } else {

                    /*
                     * Nouvelle performance.
                     */
                    $stmtAddPerf->execute([
                        $nageur_id,
                        $epreuve_id,
                        $categorie_id,
                        $lieu_id,
                        $saison,
                        $temps_final,
                        $date_perf,
                        $position_nationale
                    ]);

                    if ($stmtAddPerf->rowCount() > 0) {

                        $nb_insertions++;

                        $info = sprintf(
                            "%s %s (%s / %s) | Ajout temps : %s | Classement : %s | Lieu : %s",
                            $prenom_nageur,
                            $nom_nageur,
                            $epreuve,
                            $categorie !== ''
                                ? $categorie
                                : 'NC',
                            $temps_final,
                            $position_nationale !== null
                                ? $position_nationale . 'e'
                                : 'N/C',
                            $n['lieu'] ?? 'NC'
                        );

                        $this->writeToLog(
                            '[NOUVEAU TEMPS] ' . $info
                        );

                        $this->logger->info(
                            'INSERT',
                            $info
                        );
                    }
                }
            }

            /*
             * --------------------------------------------------------
             * FIN
             * --------------------------------------------------------
             */

            if ($etape === 'fin') {

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
                    "{$nb_insertions} nouvelle(s) performance(s), " .
                    "{$nb_updates} classement(s) mis à jour."
            ]);

        } catch (Exception $e) {

            $this->logger->info(
                'ERROR',
                $e->getMessage()
            );

            echo json_encode([
                'error' => true,
                'message' => 'Erreur : ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Écrit dans le journal principal.
     */
    private function writeToLog($message)
    {
        file_put_contents(
            $this->log_file,
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
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
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO {$table} ({$column}) VALUES (?)"
        );

        $stmt->execute([
            $value
        ]);

        $stmt = $this->pdo->prepare(
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

        /*
         * TRIM permet d'éviter les doublons causés
         * par des espaces provenant de l'API.
         */
        $stmt = $this->pdo->prepare(
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
            $stmt->fetch(PDO::FETCH_ASSOC);

        if ($nageur) {
            return $nageur['id'];
        }

        $stmt = $this->pdo->prepare(
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
        echo file_exists($this->log_file)
            ? file_get_contents($this->log_file)
            : 'Aucun historique.';
    }
}