<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class Rodada
{
    public static function upsert(int $numero, ?string $inicio = null, ?string $fim = null, string $status = 'agendada'): int
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            INSERT INTO rodadas (numero, inicio, fim, status)
            VALUES (:numero, :inicio, :fim, :status)
            ON DUPLICATE KEY UPDATE
                inicio = COALESCE(VALUES(inicio), inicio),
                fim = COALESCE(VALUES(fim), fim),
                status = VALUES(status)
        ');
        $stmt->execute([
            ':numero' => $numero,
            ':inicio' => $inicio,
            ':fim' => $fim,
            ':status' => $status,
        ]);

        $select = $pdo->prepare('SELECT id FROM rodadas WHERE numero = ?');
        $select->execute([$numero]);

        return (int) $select->fetchColumn();
    }

    public static function porNumero(int $numero): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM rodadas WHERE numero = ?');
        $stmt->execute([$numero]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function porId(int $id): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM rodadas WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Última rodada sincronizada (maior número já conhecido). */
    public static function maisRecente(): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->query('SELECT * FROM rodadas ORDER BY numero DESC LIMIT 1');
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function todasOrdenadas(): array
    {
        $pdo = Connection::get();

        return $pdo->query('SELECT * FROM rodadas ORDER BY numero DESC')->fetchAll();
    }
}
