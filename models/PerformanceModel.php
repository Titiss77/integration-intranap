<?php
class PerformanceModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // Récupérer toutes les saisons disponibles
    public function getSaisons() {
        $stmt = $this->pdo->query("SELECT DISTINCT saison FROM performances ORDER BY saison DESC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Récupérer les performances filtrées par saison
    public function getPerformances($saison) {
        $condition_saison = ""; 
        $params = [];
        
        if ($saison !== 'all') {
            $condition_saison = "AND p.saison = :saison";
            $params[':saison'] = $saison;
        }

        // Requête complexe
        $sql = "
            SELECT n.nom, n.prenom, c.nom_categorie AS categorie, e.nom_epreuve AS epreuve, 
                   p1.temps, p1.date_perf, l.nom_lieu AS lieu
            FROM performances p1
            JOIN nageurs n ON p1.nageur_id = n.id
            JOIN epreuves e ON p1.epreuve_id = e.id
            JOIN categories c ON p1.categorie_id = c.id
            JOIN lieux l ON p1.lieu_id = l.id
            JOIN (
                SELECT p.nageur_id, p.epreuve_id, MIN(p.temps) as min_temps
                FROM performances p
                WHERE 1=1 $condition_saison
                GROUP BY p.nageur_id, p.epreuve_id
            ) p2 ON p1.nageur_id = p2.nageur_id AND p1.epreuve_id = p2.epreuve_id AND p1.temps = p2.min_temps
            WHERE 1=1 " . str_replace("p.", "p1.", $condition_saison) . "
            ORDER BY c.nom_categorie ASC, n.nom ASC, n.prenom ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}