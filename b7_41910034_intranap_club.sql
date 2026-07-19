SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;


DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `nom_categorie` varchar(20) NOT NULL,
  `libelle` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `epreuves`;
CREATE TABLE `epreuves` (
  `id` int(11) NOT NULL,
  `nom_epreuve` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `grille_qualifs`;
CREATE TABLE `grille_qualifs` (
  `id` int(11) NOT NULL,
  `epreuve_id` int(11) NOT NULL,
  `categorie_id` int(11) NOT NULL,
  `temps_de_ref` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `lieux`;
CREATE TABLE `lieux` (
  `id` int(11) NOT NULL,
  `nom_lieu` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `nageurs`;
CREATE TABLE `nageurs` (
  `id` int(11) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `genre` varchar(10) NOT NULL,
  `date_naissance` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `performances`;
CREATE TABLE `performances` (
  `id` int(11) NOT NULL,
  `nageur_id` int(11) NOT NULL,
  `epreuve_id` int(11) NOT NULL,
  `categorie_id` int(11) NOT NULL,
  `lieu_id` int(11) NOT NULL,
  `saison` int(11) NOT NULL,
  `temps` varchar(20) NOT NULL,
  `date_perf` varchar(50) DEFAULT NULL,
  `classement` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nom_categorie` (`nom_categorie`);

ALTER TABLE `epreuves`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nom_epreuve` (`nom_epreuve`);

ALTER TABLE `grille_qualifs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `grille_qualifs_ibfk_1` (`epreuve_id`),
  ADD KEY `grille_qualifs_ibfk_2` (`categorie_id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `epreuves`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `grille_qualifs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `lieux`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `nageurs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `performances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `grille_qualifs`
  ADD CONSTRAINT `grille_qualifs_ibfk_1` FOREIGN KEY (`epreuve_id`) REFERENCES `epreuves` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grille_qualifs_ibfk_2` FOREIGN KEY (`categorie_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

ALTER TABLE `performances`
  ADD CONSTRAINT `performances_ibfk_1` FOREIGN KEY (`nageur_id`) REFERENCES `nageurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performances_ibfk_2` FOREIGN KEY (`epreuve_id`) REFERENCES `epreuves` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performances_ibfk_3` FOREIGN KEY (`categorie_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performances_ibfk_4` FOREIGN KEY (`lieu_id`) REFERENCES `lieux` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
