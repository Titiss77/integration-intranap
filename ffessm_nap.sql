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
  ADD PRIMARY KEY (`id`);

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
  ADD COLUMN classement INT DEFAULT NULL,
  ADD CONSTRAINT `performances_ibfk_1` FOREIGN KEY (`nageur_id`) REFERENCES `nageurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performances_ibfk_2` FOREIGN KEY (`epreuve_id`) REFERENCES `epreuves` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performances_ibfk_3` FOREIGN KEY (`categorie_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performances_ibfk_4` FOREIGN KEY (`lieu_id`) REFERENCES `lieux` (`id`) ON DELETE CASCADE;
COMMIT;


INSERT INTO `categories` (`id`, `nom_categorie`, `libelle`) VALUES
(1, 'F45+', 'Masters F45+'),
(2, 'FCA', 'Cadettes'),
(3, 'FSE', 'Seniors F'),
(6, 'FMI', 'Minimes F'),
(10, 'F35+', 'Masters F35+'),
(11, 'FJU', 'Juniors F'),
(13, 'HSE', 'Seniors H'),
(15, 'HJU', 'Juniors H'),
(16, 'HCA', 'Cadets'),
(18, 'H45+', 'Masters H45+'),
(21, 'HMI', 'Minimes H'),
(22, 'HBE', 'Benjamins'),
(31, 'F55+', 'Masters F55+'),
(47, 'H55+', 'Masters H55+'),
(49, 'H35+', 'Masters H35+'),
(290, 'FBE', 'Benjamines'),
(398, 'FPO', 'Poussines'),
(2265, 'HPO', 'Poussins');

INSERT INTO `epreuves` (`id`, `nom_epreuve`) VALUES
(15, '100BI'),
(10, '100IS'),
(4, '100SF'),
(8, '1500SF'),
(16, '200BI'),
(12, '200IS'),
(5, '200SF'),
(17, '400BI'),
(13, '400IS'),
(6, '400SF'),
(9, '50AP'),
(14, '50BI'),
(1, '50SF'),
(7, '800SF');

INSERT INTO `grille_qualifs` (`epreuve_id`, `categorie_id`, `temps_de_ref`) VALUES
(1, 3, '00:21.75'),
(4, 3, '00:48.90'),
(5, 3, '01:50.00'),
(6, 3, '04:02.00'),
(7, 3, '08:33.00'),
(8, 3, '18:00.00'),
(9, 3, '00:20.75'),
(10, 3, '00:48.40'),
(12, 3, '01:49.00'),
(13, 3, '04:40.00'),
(14, 3, '00:25.50'),
(15, 3, '00:55.80'),
(16, 3, '02:03.00'),
(17, 3, '04:30.00'),
(1, 13, '00:19.25'),
(4, 13, '00:42.60'),
(5, 13, '01:40.00'),
(6, 13, '03:37.00'),
(7, 13, '07:47.00'),
(8, 13, '17:00.00'),
(9, 13, '00:18.25'),
(10, 13, '00:42.10'),
(12, 13, '01:39.00'),
(13, 13, '04:15.00'),
(14, 13, '00:22.20'),
(15, 13, '00:50.00'),
(16, 13, '01:51.00'),
(17, 13, '04:00.00'),
(1, 11, '00:23.75'),
(4, 11, '00:52.50'),
(5, 11, '01:57.00'),
(6, 11, '04:14.00'),
(7, 11, '08:54.00'),
(8, 11, '18:30.00'),
(9, 11, '00:22.75'),
(10, 11, '00:52.00'),
(12, 11, '01:55.00'),
(13, 11, '04:50.00'),
(14, 11, '00:27.00'),
(15, 11, '00:58.00'),
(16, 11, '02:12.00'),
(17, 11, '04:50.00'),
(1, 15, '00:21.75'),
(4, 15, '00:47.00'),
(5, 15, '01:47.00'),
(6, 15, '03:54.00'),
(7, 15, '08:04.00'),
(8, 15, '17:30.00'),
(9, 15, '00:20.75'),
(10, 15, '00:46.50'),
(12, 15, '01:45.00'),
(13, 15, '04:35.00'),
(14, 15, '00:23.50'),
(15, 15, '00:52.00'),
(16, 15, '01:56.00'),
(17, 15, '04:20.00');