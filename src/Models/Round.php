<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class Round
{
    public static function upsert(int $number, ?string $startsAt = null, ?string $endsAt = null, string $status = 'scheduled'): int
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            INSERT INTO rounds (season_id, number, starts_at, ends_at, status)
            VALUES (:season_id, :number, :starts_at, :ends_at, :status)
            ON DUPLICATE KEY UPDATE
                starts_at = COALESCE(VALUES(starts_at), starts_at),
                ends_at = COALESCE(VALUES(ends_at), ends_at),
                status = VALUES(status)
        ');
        $seasonId = Season::activeId();
        $stmt->execute([
            ':season_id' => $seasonId,
            ':number' => $number,
            ':starts_at' => $startsAt,
            ':ends_at' => $endsAt,
            ':status' => $status,
        ]);

        $select = $pdo->prepare('SELECT id FROM rounds WHERE season_id = ? AND number = ?');
        $select->execute([$seasonId, $number]);

        return (int) $select->fetchColumn();
    }

    public static function findByNumber(int $number): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM rounds WHERE season_id = ? AND number = ?');
        $stmt->execute([Season::activeId(), $number]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM rounds WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Most recently synced round (highest known number). */
    public static function mostRecent(): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM rounds WHERE season_id = ? ORDER BY number DESC LIMIT 1');
        $stmt->execute([Season::activeId()]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function allOrdered(): array
    {
        $pdo = Connection::get();

        $stmt = $pdo->prepare('SELECT * FROM rounds WHERE season_id = ? ORDER BY number DESC');
        $stmt->execute([Season::activeId()]);

        return $stmt->fetchAll();
    }
}
