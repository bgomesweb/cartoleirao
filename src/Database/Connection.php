<?php

namespace Cartoleirao\Database;

use PDO;
use PDOException;

class Connection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: 'db';
            $port = getenv('DB_PORT') ?: '3306';
            $db   = getenv('DB_DATABASE') ?: 'cartoleirao';
            $user = getenv('DB_USERNAME') ?: 'cartoleirao';
            $pass = getenv('DB_PASSWORD') ?: 'cartoleirao';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                throw new PDOException('Falha ao conectar no banco: ' . $e->getMessage(), (int) $e->getCode());
            }
        }

        return self::$instance;
    }
}
