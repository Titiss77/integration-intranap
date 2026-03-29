
CREATE DATABASE IF NOT EXISTS `ffessm_nap` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `ffessm_nap`;

DROP TABLE IF EXISTS `performances`;
DROP TABLE IF EXISTS `nageurs`;

-- Table des nageurs (Identité unique)
CREATE TABLE nageurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    genre VARCHAR(10) NOT NULL,
    UNIQUE KEY unique_nageur (nom, prenom) -- Empêche de créer deux fois la même personne
);

-- Table des performances liées aux nageurs
CREATE TABLE performances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nageur_id INT NOT NULL,
    saison INT NOT NULL,
    epreuve VARCHAR(20) NOT NULL,
    categorie VARCHAR(20),
    temps VARCHAR(20) NOT NULL,
    date_perf VARCHAR(50),
    lieu VARCHAR(150),
    FOREIGN KEY (nageur_id) REFERENCES nageurs(id) ON DELETE CASCADE,
    -- Empêche d'enregistrer le même chrono pour le même nageur le même jour
    UNIQUE KEY unique_perf (nageur_id, epreuve, date_perf, temps)
);