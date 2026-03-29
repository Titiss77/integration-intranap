
CREATE DATABASE IF NOT EXISTS `ffessm_nap` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `ffessm_nap`;

DROP TABLE IF EXISTS `performances`;
DROP TABLE IF EXISTS `nageurs`;
DROP TABLE IF EXISTS `lieux`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `epreuves`;

-- 1. Table des Épreuves
CREATE TABLE epreuves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom_epreuve VARCHAR(20) NOT NULL UNIQUE
);

-- 2. Table des Catégories
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom_categorie VARCHAR(20) NOT NULL UNIQUE
);

-- 3. Table des Lieux
CREATE TABLE lieux (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom_lieu VARCHAR(150) NOT NULL UNIQUE
);

-- 4. Table des Nageurs
CREATE TABLE nageurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    genre VARCHAR(10) NOT NULL,
    UNIQUE KEY unique_nageur (nom, prenom)
);

-- 5. Table des Performances (Ne contient plus que des IDs et le chrono !)
CREATE TABLE performances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nageur_id INT NOT NULL,
    epreuve_id INT NOT NULL,
    categorie_id INT NOT NULL,
    lieu_id INT NOT NULL,
    saison INT NOT NULL,
    temps VARCHAR(20) NOT NULL,
    date_perf VARCHAR(50),
    
    -- Les liens vers les autres tables
    FOREIGN KEY (nageur_id) REFERENCES nageurs(id) ON DELETE CASCADE,
    FOREIGN KEY (epreuve_id) REFERENCES epreuves(id) ON DELETE CASCADE,
    FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (lieu_id) REFERENCES lieux(id) ON DELETE CASCADE,
    
    -- Contrainte d'unicité pour ne pas doubler un chrono
    UNIQUE KEY unique_perf (nageur_id, epreuve_id, date_perf, temps)
);