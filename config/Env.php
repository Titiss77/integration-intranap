<?php

class Env
{
    public static function load($path)
    {
        if (!file_exists($path)) {
            exit('❌ Erreur : Le fichier .env est introuvable à la racine du projet.');
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Ignorer les lignes de commentaires
            if (0 === strpos(trim($line), '#')) {
                continue;
            }

            // Séparer la clé et la valeur
            if (false !== strpos($line, '=')) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                // Enregistrer dans les variables superglobales de PHP
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}
