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
        // [!] MODIFICATION : J'ai ajouté n.id AS nageur_id au début du SELECT
        $condition_saison = '';
        $params = [];
        if ('all' !== $saison) {
            $condition_saison = 'AND p.saison = :saison';
            $params[':saison'] = $saison;
        }

        $sql = "
        SELECT n.id AS nageur_id, n.nom, n.prenom, n.date_naissance, c.nom_categorie AS categorie, e.nom_epreuve AS epreuve,
        p1.temps, p1.date_perf, l.nom_lieu AS lieu
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
            ORDER BY c.nom_categorie ASC, n.nom ASC, n.prenom ASC
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // NOUVELLE MÉTHODE : Récupérer tous les temps d'un nageur pour une épreuve
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
}
