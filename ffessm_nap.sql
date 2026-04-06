DroP DATABASE IF EXISTS `ffessm_nap`;
CREATE DATABASE `ffessm_nap` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `ffessm_nap`;

DROP TABLE IF EXISTS `performances`;
DROP TABLE IF EXISTS `lieux`;
DROP TABLE IF EXISTS `grille_qualifs`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `epreuves`;
DROP TABLE IF EXISTS `nageurs`;



CREATE TABLE `categories` (
  `id` int NOT NULL,
  `nom_categorie` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `libelle` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE `epreuves` (
  `id` int NOT NULL,
  `nom_epreuve` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `grille_qualifs` (
  `id` int NOT NULL,
  `epreuve_id` int NOT NULL,
  `categorie_id` int NOT NULL,
  `temps_de_ref` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `lieux` (
  `id` int NOT NULL,
  `nom_lieu` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `nageurs` (
  `id` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `genre` varchar(10) NOT NULL,
  `date_naissance` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `performances` (
  `id` int NOT NULL,
  `nageur_id` int NOT NULL,
  `epreuve_id` int NOT NULL,
  `categorie_id` int NOT NULL,
  `lieu_id` int NOT NULL,
  `saison` int NOT NULL,
  `temps` varchar(20) NOT NULL,
  `date_perf` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nom_categorie` (`nom_categorie`);

ALTER TABLE `epreuves`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nom_epreuve` (`nom_epreuve`);

  ALTER TABLE `grille_qualifs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `temps_de_ref` (`temps_de_ref`);

ALTER TABLE `lieux`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nom_lieu` (`nom_lieu`);

ALTER TABLE `nageurs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_nageur` (`nom`,`prenom`);

ALTER TABLE `performances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_perf` (`nageur_id`,`epreuve_id`,`date_perf`,`temps`),
  ADD KEY `epreuve_id` (`epreuve_id`),
  ADD KEY `categorie_id` (`categorie_id`),
  ADD KEY `lieu_id` (`lieu_id`);


ALTER TABLE `categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `epreuves`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `grille_qualifs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `lieux`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `nageurs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `performances`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `grille_qualifs`
  ADD CONSTRAINT `grille_qualifs_ibfk_1` FOREIGN KEY (`epreuve_id`) REFERENCES `epreuves` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grille_qualifs_ibfk_2` FOREIGN KEY (`categorie_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;


ALTER TABLE `performances`
  ADD CONSTRAINT `performances_ibfk_1` FOREIGN KEY (`nageur_id`) REFERENCES `nageurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performances_ibfk_2` FOREIGN KEY (`epreuve_id`) REFERENCES `epreuves` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performances_ibfk_3` FOREIGN KEY (`categorie_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performances_ibfk_4` FOREIGN KEY (`lieu_id`) REFERENCES `lieux` (`id`) ON DELETE CASCADE;
COMMIT;

