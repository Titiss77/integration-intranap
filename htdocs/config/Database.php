<?php

class Database
{
    private static $pdo;

    public static function getConnection()
    {
        if (null === self::$pdo) {
            // Utilisation sécurisée des variables du .env
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $db = $_ENV['DB_NAME'] ?? 'ffessm_nap';
            $user = $_ENV['DB_USER'] ?? 'root';
            $pass = $_ENV['DB_PASS'] ?? '';

            try {
                self::$pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            } catch (PDOException $e) {
                error_log('Database connection failed: ' . $e->getMessage());
                http_response_code(503);
                exit('Service temporairement indisponible.');
            }
        }

        return self::$pdo;
    }
}
