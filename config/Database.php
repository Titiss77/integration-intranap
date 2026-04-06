<?php
class Database {
    private static $pdo = null;

    public static function getConnection() {
        if (self::$pdo === null) {
            $host = 'localhost';
            $db   = 'ffessm_nap';
            $user = 'root';
            $pass = '';

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