<?php

class PerformanceModel
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Convertit un temps en secondes.
     */
    private function timeToSeconds($timeStr)
    {
        if (
            $timeStr === null ||
            $timeStr === ''
        ) {
            return PHP_FLOAT_MAX;
        }

        $timeStr = (string)$timeStr;

        if (
            strpos(
                $timeStr,
                ':'
            ) !== false
        ) {

            $parts = explode(
                ':',
                str_replace(
                    ',',
                    '.',
                    $timeStr
                )
            );

            if (
                count($parts) === 2
            ) {
                return
                    ($parts[0] * 60) +
                    (float)$parts[1];
            }
        }

        return (float)str_replace(
            ',',
            '.',
            $timeStr
        );
    }

    /**
     * Retourne les saisons disponibles.
     */
    public function getSaisons()
    {
        $stmt =
            $this->pdo->query(
                'SELECT nom_saison
                 FROM saisons
                 ORDER BY nom_saison DESC'
            );

        return $stmt->fetchAll(
            PDO::FETCH_COLUMN
        );
    }

    /**
     * Retourne la meilleure performance
     * de chaque nageur pour chaque épreuve.
     */
    public function getPerformances($saison)
    {
        $nageurs =
            $this->pdo
                ->query(
                    'SELECT * FROM nageurs'
                )
                ->fetchAll(
                    PDO::FETCH_ASSOC
                );

        $nageursById =
            array_column(
                $nageurs,
                null,
                'id'
            );

        $categories =
            $this->pdo
                ->query(
                    'SELECT * FROM categories'
                )
                ->fetchAll(
                    PDO::FETCH_ASSOC
                );

        $categoriesById =
            array_column(
                $categories,
                null,
                'id'
            );

        $epreuves =
            $this->pdo
                ->query(
                    'SELECT * FROM epreuves'
                )
                ->fetchAll(
                    PDO::FETCH_ASSOC
                );

        $epreuvesById =
            array_column(
                $epreuves,
                null,
                'id'
            );

        $lieux =
            $this->pdo
                ->query(
                    'SELECT * FROM lieux'
                )
                ->fetchAll(
                    PDO::FETCH_ASSOC
                );

        $lieuxById =
            array_column(
                $lieux,
                null,
                'id'
            );

        if (
            $saison === 'all'
        ) {

            $stmt =
                $this->pdo->query(
                    'SELECT p.*, s.nom_saison AS saison
                     FROM performances p
                     JOIN saisons s ON s.id = p.saison_id'
                );

        } else {

            $stmt =
                $this->pdo->prepare(
                    'SELECT p.*, s.nom_saison AS saison
                     FROM performances p
                     JOIN saisons s ON s.id = p.saison_id
                     WHERE s.nom_saison = ?'
                );

            $stmt->execute([
                $saison
            ]);
        }

        $filtered =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        /*
         * Recherche du meilleur temps
         * de chaque nageur pour chaque épreuve.
         */
        $best_times = [];

        foreach (
            $filtered as $p
        ) {

            $nid =
                $p['nageur_id'];

            $eid =
                $p['epreuve_id'];

            $key =
                $nid . '-' . $eid;

            $sec =
                $this->timeToSeconds(
                    $p['temps']
                );

            if (
                !isset(
                    $best_times[$key]
                ) ||
                $sec <
                $best_times[$key]['sec']
            ) {

                $best_times[$key] = [
                    'sec' => $sec,
                    'id' => $p['id']
                ];
            }
        }

        $result = [];

        foreach (
            $filtered as $p
        ) {

            $nid =
                $p['nageur_id'];

            $eid =
                $p['epreuve_id'];

            $key =
                $nid . '-' . $eid;

            if (
                !isset(
                    $best_times[$key]
                ) ||
                (int)$p['id'] !==
                (int)$best_times[$key]['id']
            ) {
                continue;
            }

            $cid =
                $p['categorie_id'];

            $lid =
                $p['lieu_id'];

            $result[] = [

                'nageur_id' =>
                    $nid,

                'nom' =>
                    isset(
                        $nageursById[$nid]
                    )
                        ? $nageursById[$nid]['nom']
                        : 'NC',

                'prenom' =>
                    isset(
                        $nageursById[$nid]
                    )
                        ? $nageursById[$nid]['prenom']
                        : 'NC',

                'date_naissance' =>
                    isset(
                        $nageursById[$nid]
                    )
                        ? $nageursById[$nid]['date_naissance']
                        : null,

                'categorie' =>
                    isset(
                        $categoriesById[$cid]
                    )
                        ? $categoriesById[$cid]['nom_categorie']
                        : 'NC',

                'categorie_libelle' =>
                    isset(
                        $categoriesById[$cid]
                    )
                        ? $categoriesById[$cid]['libelle']
                        : 'NC',

                'epreuve' =>
                    isset(
                        $epreuvesById[$eid]
                    )
                        ? $epreuvesById[$eid]['nom_epreuve']
                        : 'NC',

                'temps' =>
                    $p['temps'],

                'date_perf' =>
                    $p['date_perf'],

                'lieu' =>
                    isset(
                        $lieuxById[$lid]
                    )
                        ? $lieuxById[$lid]['nom_lieu']
                        : 'NC'
            ];
        }

        /*
         * Tri par épreuve puis par temps.
         */
        usort(
            $result,
            function ($a, $b) {

                $cmp = strcmp(
                    (string)(
                        $a['epreuve'] ?? ''
                    ),
                    (string)(
                        $b['epreuve'] ?? ''
                    )
                );

                if (
                    $cmp === 0
                ) {

                    return
                        $this->timeToSeconds(
                            $a['temps']
                        )
                        <=>
                        $this->timeToSeconds(
                            $b['temps']
                        );
                }

                return $cmp;
            }
        );

        return $result;
    }

    /**
     * Retourne toutes les performances
     * d'un nageur pour une épreuve.
     */
    public function getHistorique(
        $nageur_id,
        $epreuve
    ) {

        $stmtEpreuve =
            $this->pdo->prepare(
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

        if (
            !$epreuve_id
        ) {
            return [];
        }

        $stmt =
            $this->pdo->prepare(
                'SELECT
                    p.id,
                    p.temps,
                    p.date_perf,
                    s.nom_saison AS saison,
                    l.nom_lieu AS lieu
                 FROM performances p
                 JOIN saisons s
                    ON p.saison_id = s.id
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

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        $result = [];

        foreach (
            $rows as $p
        ) {

            $result[] = [

                'temps' =>
                    $p['temps'],

                'date_perf' =>
                    $p['date_perf'],

                'lieu' =>
                    !empty(
                        $p['lieu']
                    )
                        ? $p['lieu']
                        : 'NC',

                'saison' =>
                    $p['saison']
            ];
        }

        usort(
            $result,
            function ($a, $b) {

                $dateA =
                    $this->dateToTimestamp(
                        $a['date_perf']
                    );

                $dateB =
                    $this->dateToTimestamp(
                        $b['date_perf']
                    );

                if (
                    $dateA === $dateB
                ) {
                    return 0;
                }

                return $dateA <=> $dateB;
            }
        );

        return $result;
    }

    /**
     * Convertit différents formats
     * de date en timestamp.
     */
    private function dateToTimestamp(
        $date
    ) {
        $date =
            trim(
                (string)$date
            );

        if (
            $date === ''
        ) {
            return 0;
        }

        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $date
            )
        ) {

            $timestamp =
                strtotime($date);

            return
                $timestamp !== false
                    ? $timestamp
                    : 0;
        }

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

        $timestamp =
            strtotime($date);

        return
            $timestamp !== false
                ? $timestamp
                : 0;
    }

    /**
     * Retourne les catégories actuelles
     * des nageurs.
     */
    public function getCategoriesActuelles()
    {
        $stmt =
            $this->pdo->query(
                'SELECT
                    p.nageur_id,
                    p.saison_id,
                    p.categorie_id
                 FROM performances p
                 INNER JOIN (
                     SELECT
                        nageur_id,
                        MAX(saison_id) AS derniere_saison_id
                     FROM performances
                     GROUP BY nageur_id
                 ) derniere
                    ON derniere.nageur_id = p.nageur_id
                    AND derniere.derniere_saison_id = p.saison_id
                 GROUP BY
                    p.nageur_id,
                    p.saison_id,
                    p.categorie_id'
            );

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        $categories =
            $this->pdo
                ->query(
                    'SELECT * FROM categories'
                )
                ->fetchAll(
                    PDO::FETCH_ASSOC
                );

        $categoriesById =
            array_column(
                $categories,
                null,
                'id'
            );

        $result = [];

        foreach (
            $rows as $row
        ) {

            $nid =
                $row['nageur_id'];

            $cid =
                $row['categorie_id'];

            if (
                !isset(
                    $categoriesById[$cid]
                )
            ) {
                continue;
            }

            $result[$nid] = [

                'nom_categorie' =>
                    $categoriesById[$cid]['nom_categorie'],

                'libelle' =>
                    $categoriesById[$cid]['libelle']
            ];
        }

        uasort(
            $result,
            function ($a, $b) {

                return strcmp(
                    (string)(
                        $b['libelle'] ?? ''
                    ),
                    (string)(
                        $a['libelle'] ?? ''
                    )
                );
            }
        );

        return $result;
    }

    /**
     * Retourne la grille des qualifications.
     *
     * temps_de_ref :
     * - non NULL => qualification au temps
     *
     * position :
     * - non NULL => qualification à la position
     *   dans le classement temporairement calculé
     *
     * Les deux peuvent exister, mais le temps de référence
     * est prioritaire.
     */
    public function getGrilleQualifs($saison_prioritaire = null)
    {
        $sql =
            'SELECT
                g.saison_id,
                s.nom_saison AS saison,
                c.nom_categorie,
                e.nom_epreuve,
                g.temps_de_ref,
                g.position
             FROM grille_qualifs g
             JOIN saisons s
                ON g.saison_id = s.id
             JOIN categories c
                ON g.categorie_id = c.id
             JOIN epreuves e
                ON g.epreuve_id = e.id
             ORDER BY s.nom_saison DESC, g.id DESC';

        $stmt =
            $this->pdo->query($sql);

        $result = [];

        $priorite_saison = [];

        while (
            $row =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                )
        ) {

            $key = $row['nom_categorie'] . '|' . $row['nom_epreuve'];
            $saison = (string)$row['saison'];
            $prioritaire = $saison_prioritaire !== null && $saison === (string)$saison_prioritaire;

            // Never apply a rule from a future season to an older selection.
            if (
                $saison_prioritaire !== null &&
                strcmp($saison, (string)$saison_prioritaire) > 0
            ) {
                continue;
            }

            $a_temps = $row['temps_de_ref'] !== null && $row['temps_de_ref'] !== '';
            $a_position = $row['position'] !== null && (int)$row['position'] > 0;

            // Ignore empty rules so they cannot hide the latest usable rule.
            if (!$a_temps && !$a_position) {
                continue;
            }

            if (isset($priorite_saison[$key])) {
                $ancienne_priorite = $priorite_saison[$key];
                if ($ancienne_priorite === true || (!$prioritaire && strcmp($saison, $ancienne_priorite) <= 0)) {
                    continue;
                }
            }

            $result[$row['nom_categorie']][$row['nom_epreuve']] = [

                'temps_de_ref' =>
                    $row['temps_de_ref'],

                'position' =>
                    $row['position'] !== null
                        ? (int)$row['position']
                        : null
            ];
            $priorite_saison[$key] = $prioritaire ? true : $saison;
        }

        return $result;
    }
}
