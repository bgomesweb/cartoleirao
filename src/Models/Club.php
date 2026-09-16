<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class Club
{
    /**
     * Batch upsert from the array returned by CartolaApiClient::clubs()
     * (dictionary id => club data).
     */
    public static function upsertBatch(array $apiClubs): int
    {
        if (empty($apiClubs)) {
            return 0;
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            INSERT INTO clubs (id, name, trade_name, abbreviation, crest_url)
            VALUES (:id, :name, :trade_name, :abbreviation, :crest_url)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                trade_name = VALUES(trade_name),
                abbreviation = VALUES(abbreviation),
                crest_url = VALUES(crest_url)
        ');

        $total = 0;
        foreach ($apiClubs as $club) {
            if (empty($club['id'])) {
                continue;
            }

            $crest = $club['escudos']['60x60']
                ?? $club['escudos']['45x45']
                ?? $club['escudo']
                ?? null;

            $stmt->execute([
                ':id' => (int) $club['id'],
                ':name' => $club['nome'] ?? ('Clube ' . $club['id']),
                ':trade_name' => $club['nome_fantasia'] ?? null,
                ':abbreviation' => $club['abreviacao'] ?? null,
                ':crest_url' => $crest,
            ]);
            $total++;
        }

        return $total;
    }

    public static function all(): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->query('SELECT * FROM clubs ORDER BY name');
        $byId = [];
        foreach ($stmt->fetchAll() as $club) {
            $byId[(int) $club['id']] = $club;
        }

        return $byId;
    }

    public static function total(): int
    {
        $pdo = Connection::get();

        return (int) $pdo->query('SELECT COUNT(*) FROM clubs')->fetchColumn();
    }
}
