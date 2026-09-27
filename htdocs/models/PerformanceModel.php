<?php

class PerformanceModel
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Lit le fichier JSON en mémoire.
     *
     * Le JSON reste utilisé pour les données historiques
     * de la page principale afin de conserver le fonctionnement
     * actuel du site.
     */
    private function getPerformancesFromJson()
    {
        $file = __DIR__ . '/../perfs/performances.json';

        if (!file_exists($file)) {
            return [];
        }

        $decoded = json_decode(
            file_get_contents($file),
            true
        ) ?: [];

        // Détection de la structure d'export phpMyAdmin
        foreach ($decoded as $item) {
            if (
                isset($item['type']) &&
                $item['type'] === 'table' &&
                isset($item['name']) &&
                $item['name'] === 'performances'
            ) {
                return isset($item['data'])
                    ? $item['data']
                    : [];
            }
        }

        // Tableau JSON classique
        return $decoded;
    }

    /**
     * Convertit un temps en secondes.
     *
     * Exemples :
     *
     * 00:17.50 -> 17.50
     * 01:02.35 -> 62.35
     * 17.50    -> 17.50
     */
    private function timeToSeconds($timeStr)
{
    if ($timeStr === null || $timeStr === '') {
        return PHP_FLOAT_MAX;
    }

    $timeStr = (string)$timeStr;

    if (strpos($timeStr, ':') !== false) {
        $parts = explode(':', str_replace(',', '.', $timeStr));

        if (count($parts) === 2) {
            return ($parts[0] * 60) + (float)$parts[1];
        }
    }

    return (float)str_replace(',', '.', $timeStr);
}

    /**
     * Retourne les saisons disponibles.
     */
    public function getSaisons()
    {
        /*
         * On récupère directement les saisons de la BDD.
         *
         * Cela permet d'inclure les performances ajoutées
         * par la synchronisation.
         */
        $stmt = $this->pdo->query(
            'SELECT DISTINCT saison
             FROM performances
             WHERE saison IS NOT NULL
             ORDER BY saison DESC'
        );

        return $stmt->fetchAll(
            PDO::FETCH_COLUMN
        );
    }

    /**
     * Retourne les meilleures performances de chaque nageur
     * pour chaque épreuve.
     *
     * IMPORTANT :
     * Cette méthode est utilisée par la page principale.
     *
     * Elle ne retourne donc PAS tous les temps.
     *
     * Exemple :
     *
     * Mathis :
     *
     * 50AP
     * 17.50
     * 18.10
     * 17.80
     *
     * La page principale n'affichera que :
     *
     * 17.50
     *
     * Le graphique, lui, affichera les trois.
     */
    public function getPerformances($saison)
    {
        /*
         * Chargement des nageurs
         */
        $nageurs = $this->pdo
            ->query('SELECT * FROM nageurs')
            ->fetchAll(PDO::FETCH_ASSOC);

        $nageursById = array_column(
            $nageurs,
            null,
            'id'
        );

        /*
         * Chargement des catégories
         */
        $categories = $this->pdo
            ->query('SELECT * FROM categories')
            ->fetchAll(PDO::FETCH_ASSOC);

        $categoriesById = array_column(
            $categories,
            null,
            'id'
        );

        /*
         * Chargement des épreuves
         */
        $epreuves = $this->pdo
            ->query('SELECT * FROM epreuves')
            ->fetchAll(PDO::FETCH_ASSOC);

        $epreuvesById = array_column(
            $epreuves,
            null,
            'id'
        );

        /*
         * Chargement des lieux
         */
        $lieux = $this->pdo
            ->query('SELECT * FROM lieux')
            ->fetchAll(PDO::FETCH_ASSOC);

        $lieuxById = array_column(
            $lieux,
            null,
            'id'
        );

        /*
         * Récupération des performances.
         *
         * On récupère TOUTES les performances de la BDD
         * puis on conserve uniquement le meilleur temps
         * pour la page principale.
         */
        if ($saison === 'all') {

            $stmt = $this->pdo->query(
                'SELECT *
                 FROM performances'
            );

        } else {

            $stmt = $this->pdo->prepare(
                'SELECT *
                 FROM performances
                 WHERE saison = ?'
            );

            $stmt->execute([
                $saison
            ]);
        }

        $filtered = $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        /*
         * ---------------------------------------------------------
         * RECHERCHE DU MEILLEUR TEMPS
         * ---------------------------------------------------------
         */

        $best_times = [];

        foreach ($filtered as $p) {

            $nid = $p['nageur_id'];
            $eid = $p['epreuve_id'];

            $key = $nid . '-' . $eid;

            $sec = $this->timeToSeconds(
                $p['temps']
            );

            if (
                !isset($best_times[$key]) ||
                $sec < $best_times[$key]['sec']
            ) {
                $best_times[$key] = [
                    'sec' => $sec,
                    'id' => $p['id']
                ];
            }
        }

        /*
         * ---------------------------------------------------------
         * CONSTRUCTION DU RESULTAT
         * ---------------------------------------------------------
         */

        $result = [];

        foreach ($filtered as $p) {

            $nid = $p['nageur_id'];
            $eid = $p['epreuve_id'];

            $key = $nid . '-' . $eid;

            /*
             * On ne garde que le record.
             */
            if (
                !isset($best_times[$key]) ||
                (int)$p['id'] !==
                (int)$best_times[$key]['id']
            ) {
                continue;
            }

            $cid = $p['categorie_id'];
            $lid = $p['lieu_id'];

            $result[] = [
                'nageur_id' => $nid,

                'nom' =>
                    isset($nageursById[$nid])
                        ? $nageursById[$nid]['nom']
                        : 'NC',

                'prenom' =>
                    isset($nageursById[$nid])
                        ? $nageursById[$nid]['prenom']
                        : 'NC',

                'date_naissance' =>
                    isset($nageursById[$nid])
                        ? $nageursById[$nid]['date_naissance']
                        : null,

                'categorie' =>
                    isset($categoriesById[$cid])
                        ? $categoriesById[$cid]['nom_categorie']
                        : 'NC',

                'categorie_libelle' =>
                    isset($categoriesById[$cid])
                        ? $categoriesById[$cid]['libelle']
                        : 'NC',

                'epreuve' =>
                    isset($epreuvesById[$eid])
                        ? $epreuvesById[$eid]['nom_epreuve']
                        : 'NC',

                'temps' => $p['temps'],

                'date_perf' => $p['date_perf'],

                /*
                 * Le classement provient de la synchronisation
                 * et correspond au record.
                 */
                'classement' => $p['classement'],

                'lieu' =>
                    isset($lieuxById[$lid])
                        ? $lieuxById[$lid]['nom_lieu']
                        : 'NC',
            ];
        }

        /*
         * Tri par épreuve puis par temps.
         */
        usort(
            $result,
            function ($a, $b) {

            $cmp = strcmp((string)($a['epreuve'] ?? ''), (string)($b['epreuve'] ?? ''));

                if ($cmp === 0) {

                    return
                        $this->timeToSeconds($a['temps'])
                        <=>
                        $this->timeToSeconds($b['temps']);
                }

                return $cmp;
            }
        );

        return $result;
    }

    /**
     * Retourne TOUTES les performances d'un nageur
     * pour une épreuve donnée.
     *
     * IMPORTANT :
     *
     * Contrairement à getPerformances(),
     * cette méthode NE sélectionne PAS le record.
     *
     * Exemple :
     *
     * 50AP :
     *
     * 18.20 - 12/09/2025
     * 17.90 - 20/10/2025
     * 18.05 - 15/11/2025
     * 17.50 - 10/01/2026
     *
     * Les quatre performances sont retournées.
     */
    public function getHistorique(
        $nageur_id,
        $epreuve
    ) {
        /*
         * On récupère l'ID de l'épreuve.
         */
        $stmtEpreuve = $this->pdo->prepare(
            'SELECT id
             FROM epreuves
             WHERE nom_epreuve = ?
             LIMIT 1'
        );

        $stmtEpreuve->execute([
            $epreuve
        ]);

        $epreuve_id =
            $stmtEpreuve->fetchColumn();

        if (!$epreuve_id) {
            return [];
        }

        /*
         * Toutes les performances du nageur
         * pour cette épreuve.
         *
         * AUCUN filtre sur le meilleur temps.
         */
        $stmt = $this->pdo->prepare(
            'SELECT
                p.id,
                p.temps,
                p.date_perf,
                p.saison,
                l.nom_lieu AS lieu
             FROM performances p
             LEFT JOIN lieux l
                ON p.lieu_id = l.id
             WHERE p.nageur_id = ?
               AND p.epreuve_id = ?
             ORDER BY p.date_perf ASC, p.id ASC'
        );

        $stmt->execute([
            $nageur_id,
            $epreuve_id
        ]);

        $rows = $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        $result = [];

        foreach ($rows as $p) {

            $result[] = [
                'temps' => $p['temps'],

                'date_perf' => $p['date_perf'],

                'lieu' =>
                    !empty($p['lieu'])
                        ? $p['lieu']
                        : 'NC',

                'saison' => $p['saison'],
            ];
        }

        /*
         * Sécurité supplémentaire :
         * tri chronologique côté PHP également.
         *
         * Cela permet de gérer aussi les dates qui
         * ne seraient pas stockées dans un format SQL
         * strict.
         */
        usort(
            $result,
            function ($a, $b) {

                $dateA = $this->dateToTimestamp(
                    $a['date_perf']
                );

                $dateB = $this->dateToTimestamp(
                    $b['date_perf']
                );

                if ($dateA === $dateB) {
                    return 0;
                }

                return $dateA <=> $dateB;
            }
        );

        return $result;
    }

    /**
     * Convertit différents formats de date en timestamp.
     */
    private function dateToTimestamp($date)
    {
        $date = trim((string)$date);

        if ($date === '') {
            return 0;
        }

        /*
         * Format YYYY-MM-DD
         */
        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $date
            )
        ) {
            $timestamp = strtotime($date);

            return $timestamp !== false
                ? $timestamp
                : 0;
        }

        /*
         * Format DD/MM/YYYY
         */
        if (
            preg_match(
                '/^(\d{2})\/(\d{2})\/(\d{4})$/',
                $date,
                $matches
            )
        ) {

            return mktime(
                0,
                0,
                0,
                (int)$matches[2],
                (int)$matches[1],
                (int)$matches[3]
            );
        }

        /*
         * Autres formats compréhensibles par PHP.
         */
        $timestamp = strtotime($date);

        return $timestamp !== false
            ? $timestamp
            : 0;
    }

    /**
     * Retourne les catégories actuelles des nageurs.
     */
    public function getCategoriesActuelles()
    {
        /*
         * Les catégories sont maintenant récupérées depuis
         * la BDD afin d'utiliser les mêmes données que la
         * synchronisation.
         */
        $stmt = $this->pdo->query(
            'SELECT
                p.nageur_id,
                p.saison,
                p.categorie_id
             FROM performances p
             INNER JOIN (
                 SELECT
                    nageur_id,
                    MAX(saison) AS derniere_saison
                 FROM performances
                 GROUP BY nageur_id
             ) derniere
                ON derniere.nageur_id = p.nageur_id
                AND derniere.derniere_saison = p.saison
             GROUP BY
                p.nageur_id,
                p.saison,
                p.categorie_id'
        );

        $rows = $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        $categories = $this->pdo
            ->query('SELECT * FROM categories')
            ->fetchAll(PDO::FETCH_ASSOC);

        $categoriesById = array_column(
            $categories,
            null,
            'id'
        );

        $result = [];

        foreach ($rows as $row) {

            $nid = $row['nageur_id'];
            $cid = $row['categorie_id'];

            if (!isset($categoriesById[$cid])) {
                continue;
            }

            $result[$nid] = [
                'nom_categorie' =>
                    $categoriesById[$cid]['nom_categorie'],

                'libelle' =>
                    $categoriesById[$cid]['libelle'],
            ];
        }

        uasort(
            $result,
            function ($a, $b) {
                return strcmp((string)($b['libelle'] ?? ''), (string)($a['libelle'] ?? ''));
            }
        );

        return $result;
    }

    /**
     * Retourne la grille des temps de qualification.
     */
    public function getGrilleQualifs()
    {
        $sql =
            'SELECT
                c.nom_categorie,
                e.nom_epreuve,
                g.temps_de_ref
             FROM grille_qualifs g
             JOIN categories c
                ON g.categorie_id = c.id
             JOIN epreuves e
                ON g.epreuve_id = e.id';

        $stmt = $this->pdo->query($sql);

        $result = [];

        while (
            $row = $stmt->fetch(PDO::FETCH_ASSOC)
        ) {

            $result[
                $row['nom_categorie']
            ][
                $row['nom_epreuve']
            ] =
                $row['temps_de_ref'];
        }

        return $result;
    }
}