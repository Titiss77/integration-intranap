<?php
class Database {
    private static $pdo = null;

    public static function getConnection() {
        if (self::$pdo === null) {
            // Utilisation sécurisée des variables du .env
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $db   = $_ENV['DB_NAME'] ?? 'ffessm_nap';
            $user = $_ENV['DB_USER'] ?? 'root';
            $pass = $_ENV['DB_PASS'] ?? '';

            try {
                self::$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                die("<p style='color:red;'>❌ Erreur de connexion à la BDD : " . $e->getMessage() . "</p>");
            }
        }
        return self::$pdo;
    }
}