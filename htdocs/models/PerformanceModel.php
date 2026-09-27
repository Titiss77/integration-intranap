<?php
class PerformanceModel
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Lit le fichier JSON en mémoire
     */
    private function getPerformancesFromJson()
    {
        $file = __DIR__ . '/../perfs/performances.json';
        if (!file_exists($file)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($file), true) ?: [];

        // Détection de la structure d'export phpMyAdmin
        foreach ($decoded as $item) {
            if (isset($item['type']) && $item['type'] === 'table' && isset($item['name']) && $item['name'] === 'performances') {
                return isset($item['data']) ? $item['data'] : [];
            }
        }

        // Retour par défaut (dans le cas où le JSON est un tableau plat standard)
        return $decoded;
    }

    /**
     * Convertit un temps MM:SS en secondes pour permettre le tri
     */
    private function timeToSeconds($timeStr)
    {
        if (strpos($timeStr, ':') !== false) {
            $parts = explode(':', str_replace(',', '.', $timeStr));
            if (count($parts) === 2) {
                return ($parts[0] * 60) + (float)$parts[1];
            }
        }
        return (float)str_replace(',', '.', $timeStr);
    }

    public function getSaisons()
    {
        $perfs = $this->getPerformancesFromJson();
        $saisons = array_unique(array_column($perfs, 'saison'));
        rsort($saisons);
        return $saisons;
    }

    public function getPerformances($saison)
    {
        $perfs = $this->getPerformancesFromJson();

        // Chargement des référentiels (DB)
        $nageurs = $this->pdo->query('SELECT * FROM nageurs')->fetchAll(PDO::FETCH_ASSOC);
        $nageursById = array_column($nageurs, null, 'id');

        $categories = $this->pdo->query('SELECT * FROM categories')->fetchAll(PDO::FETCH_ASSOC);
        $categoriesById = array_column($categories, null, 'id');

        $epreuves = $this->pdo->query('SELECT * FROM epreuves')->fetchAll(PDO::FETCH_ASSOC);
        $epreuvesById = array_column($epreuves, null, 'id');

        $lieux = $this->pdo->query('SELECT * FROM lieux')->fetchAll(PDO::FETCH_ASSOC);
        $lieuxById = array_column($lieux, null, 'id');

        // Filtrage par saison
        $filtered = [];
        foreach ($perfs as $p) {
            if ('all' !== $saison && $p['saison'] != $saison) {
                continue;
            }
            $filtered[] = $p;
        }

        // Groupement pour trouver le meilleur temps par nageur et épreuve
        $best_times = [];
        foreach ($filtered as $p) {
            $nid = $p['nageur_id'];
            $eid = $p['epreuve_id'];
            $key = "$nid-$eid";
            $sec = $this->timeToSeconds($p['temps']);

            if (!isset($best_times[$key]) || $sec < $best_times[$key]['sec']) {
                $best_times[$key] = ['sec' => $sec, 'temps' => $p['temps']];
            }
        }

        // Reconstruction des résultats
        $result = [];
        $added = []; // Éviter les doublons si le nageur a exactement le même meilleur temps sur deux perfs différentes

        foreach ($filtered as $p) {
            $nid = $p['nageur_id'];
            $eid = $p['epreuve_id'];
            $cid = $p['categorie_id'];
            $lid = $p['lieu_id'];
            $key = "$nid-$eid";

            if ($p['temps'] === $best_times[$key]['temps']) {
                $unique_key = "$key-{$p['temps']}";
                if (!isset($added[$unique_key])) {
                    $result[] = [
                        'nageur_id' => $nid,
                        'nom' => isset($nageursById[$nid]) ? $nageursById[$nid]['nom'] : 'NC',
                        'prenom' => isset($nageursById[$nid]) ? $nageursById[$nid]['prenom'] : 'NC',
                        'date_naissance' => isset($nageursById[$nid]) ? $nageursById[$nid]['date_naissance'] : null,
                        'categorie' => isset($categoriesById[$cid]) ? $categoriesById[$cid]['nom_categorie'] : 'NC',
                        'categorie_libelle' => isset($categoriesById[$cid]) ? $categoriesById[$cid]['libelle'] : 'NC',
                        'epreuve' => isset($epreuvesById[$eid]) ? $epreuvesById[$eid]['nom_epreuve'] : 'NC',
                        'temps' => $p['temps'],
                        'date_perf' => $p['date_perf'],
                        'classement' => $p['classement'],
                        'lieu' => isset($lieuxById[$lid]) ? $lieuxById[$lid]['nom_lieu'] : 'NC',
                    ];
                    $added[$unique_key] = true;
                }
            }
        }

        // Tri équivalent SQL (ORDER BY epreuve ASC, p1.temps ASC)
        usort($result, function ($a, $b) {
            $cmp = strcmp($a['epreuve'], $b['epreuve']);
            if (0 === $cmp) {
                return $this->timeToSeconds($a['temps']) <=> $this->timeToSeconds($b['temps']);
            }
            return $cmp;
        });

        return $result;
    }

    public function getHistorique($nageur_id, $epreuve)
    {
        $perfs = $this->getPerformancesFromJson();

        $epreuves = $this->pdo->query('SELECT * FROM epreuves')->fetchAll(PDO::FETCH_ASSOC);
        $epreuvesById = array_column($epreuves, null, 'id');

        $lieux = $this->pdo->query('SELECT * FROM lieux')->fetchAll(PDO::FETCH_ASSOC);
        $lieuxById = array_column($lieux, null, 'id');

        $result = [];
        foreach ($perfs as $p) {
            if ($p['nageur_id'] == $nageur_id) {
                $eid = $p['epreuve_id'];
                $lid = $p['lieu_id'];
                if (isset($epreuvesById[$eid]) && $epreuvesById[$eid]['nom_epreuve'] === $epreuve) {
                    $result[] = [
                        'temps' => $p['temps'],
                        'date_perf' => $p['date_perf'],
                        'lieu' => isset($lieuxById[$lid]) ? $lieuxById[$lid]['nom_lieu'] : 'NC',
                    ];
                }
            }
        }
        return $result;
    }

    public function getCategoriesActuelles()
    {
        $perfs = $this->getPerformancesFromJson();
        $categories = $this->pdo->query('SELECT * FROM categories')->fetchAll(PDO::FETCH_ASSOC);
        $categoriesById = array_column($categories, null, 'id');

        $max_saisons = [];
        foreach ($perfs as $p) {
            $nid = $p['nageur_id'];
            if (!isset($max_saisons[$nid]) || $p['saison'] > $max_saisons[$nid]['saison']) {
                $max_saisons[$nid] = [
                    'saison' => $p['saison'],
                    'categorie_id' => $p['categorie_id'],
                ];
            }
        }

        $result = [];
        foreach ($max_saisons as $nid => $data) {
            $cid = $data['categorie_id'];
            if (isset($categoriesById[$cid])) {
                $result[$nid] = [
                    'nom_categorie' => $categoriesById[$cid]['nom_categorie'],
                    'libelle' => $categoriesById[$cid]['libelle'],
                ];
            }
        }

        uasort($result, function ($a, $b) {
            return strcmp($b['libelle'], $a['libelle']);
        });

        return $result;
    }

    public function getGrilleQualifs()
    {
        // Reste en SQL car il n'utilise pas la table performances
        $sql = 'SELECT c.nom_categorie, e.nom_epreuve, g.temps_de_ref
                FROM grille_qualifs g
                JOIN categories c ON g.categorie_id = c.id
                JOIN epreuves e ON g.epreuve_id = e.id';
        $stmt = $this->pdo->query($sql);
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['nom_categorie']][$row['nom_epreuve']] = $row['temps_de_ref'];
        }
        return $result;
    }
}