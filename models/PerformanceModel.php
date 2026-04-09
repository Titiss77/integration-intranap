<?php

class PerformanceModel
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function getSaisons()
    {
        $stmt = $this->pdo->query('SELECT DISTINCT saison FROM performances ORDER BY saison DESC');

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getPerformances($saison)
    {
        $condition_saison = '';
        $params = [];
        if ('all' !== $saison) {
            $condition_saison = 'AND p.saison = :saison';
            $params[':saison'] = $saison;
        }

        // AJOUT : c.libelle AS categorie_libelle
        $sql = "
        SELECT n.id AS nageur_id, n.nom, n.prenom, n.date_naissance,
               c.nom_categorie AS categorie, c.libelle AS categorie_libelle,
               e.nom_epreuve AS epreuve,
               p1.temps, p1.date_perf, p1.classement, l.nom_lieu AS lieu
        FROM performances p1
        JOIN nageurs n ON p1.nageur_id = n.id
        JOIN epreuves e ON p1.epreuve_id = e.id
        JOIN categories c ON p1.categorie_id = c.id
        JOIN lieux l ON p1.lieu_id = l.id
        JOIN (
            SELECT p.nageur_id, p.epreuve_id, MIN(p.temps) as min_temps
            FROM performances p
            WHERE 1=1 {$condition_saison}
            GROUP BY p.nageur_id, p.epreuve_id
        ) p2 ON p1.nageur_id = p2.nageur_id AND p1.epreuve_id = p2.epreuve_id AND p1.temps = p2.min_temps
        WHERE 1=1 ".str_replace('p.', 'p1.', $condition_saison).'
        ORDER BY epreuve ASC, p1.temps ASC
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getHistorique($nageur_id, $epreuve)
    {
        $sql = 'SELECT p.temps, p.date_perf, l.nom_lieu AS lieu
                FROM performances p
                JOIN epreuves e ON p.epreuve_id = e.id
                JOIN lieux l ON p.lieu_id = l.id
                WHERE p.nageur_id = ? AND e.nom_epreuve = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$nageur_id, $epreuve]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // AJOUT : Récupère également le libellé pour la saison la plus récente
    public function getCategoriesActuelles()
    {
        $sql = '
            SELECT DISTINCT p.nageur_id, c.nom_categorie, c.libelle
            FROM performances p
            JOIN categories c ON p.categorie_id = c.id
            INNER JOIN (
                SELECT nageur_id, MAX(saison) AS max_saison
                FROM performances
                GROUP BY nageur_id
            ) p_max ON p.nageur_id = p_max.nageur_id AND p.saison = p_max.max_saison
            ORDER BY c.libelle DESC
        ';

        $stmt = $this->pdo->query($sql);
        $result = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['nageur_id']] = [
                'nom_categorie' => $row['nom_categorie'],
                'libelle' => $row['libelle'],
            ];
        }

        return $result;
    }

    // NOUVELLE MÉTHODE : Récupérer la grille des qualifications
    public function getGrilleQualifs()
    {
        $sql = 'SELECT c.nom_categorie, e.nom_epreuve, g.temps_de_ref
                FROM grille_qualifs g
                JOIN categories c ON g.categorie_id = c.id
                JOIN epreuves e ON g.epreuve_id = e.id';

        $stmt = $this->pdo->query($sql);
        $result = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Structure : $result['F35+']['50SF'] = '00:23.50'
            $result[$row['nom_categorie']][$row['nom_epreuve']] = $row['temps_de_ref'];
        }

        return $result;
    }
}
