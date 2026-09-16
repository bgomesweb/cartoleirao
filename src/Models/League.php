<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class League
{
    public static function all(): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->query('
            SELECT l.*, ls.registrations_open, ls.registration_fee, ls.prize_1, ls.prize_2, ls.prize_3, ls.prize_4, ls.prize_5, ls.prize_6, ls.prize_7, ls.prize_8, ls.prize_description
            FROM leagues l
            LEFT JOIN league_settings ls ON ls.league_id = l.id
            ORDER BY l.is_default DESC, l.name ASC
        ');

        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT l.*, ls.registrations_open, ls.registration_fee, ls.prize_1, ls.prize_2, ls.prize_3, ls.prize_4, ls.prize_5, ls.prize_6, ls.prize_7, ls.prize_8, ls.prize_description
            FROM leagues l
            LEFT JOIN league_settings ls ON ls.league_id = l.id
            WHERE l.id = ?
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function default(): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->query('
            SELECT l.*, ls.registrations_open, ls.registration_fee, ls.prize_1, ls.prize_2, ls.prize_3, ls.prize_4, ls.prize_5, ls.prize_6, ls.prize_7, ls.prize_8, ls.prize_description
            FROM leagues l
            LEFT JOIN league_settings ls ON ls.league_id = l.id
            WHERE l.is_default = 1
            LIMIT 1
        ');
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function updateSettings(int $leagueId, array $data): void
    {
        $pdo = Connection::get();

        $setPrizes = implode(', ', array_map(fn ($i) => "prize_{$i} = :prize_{$i}", range(1, 8)));

        $stmt = $pdo->prepare("
            UPDATE league_settings
            SET registrations_open = :registrations_open,
                registration_fee = :registration_fee,
                {$setPrizes},
                prize_description = :prize_description
            WHERE league_id = :league_id
        ");

        $params = [
            ':registrations_open' => (int) $data['registrations_open'],
            ':registration_fee' => ($data['registration_fee'] ?? '') !== '' ? $data['registration_fee'] : null,
            ':prize_description' => ($data['prize_description'] ?? '') !== '' ? $data['prize_description'] : null,
            ':league_id' => $leagueId,
        ];
        foreach (range(1, 8) as $i) {
            $params[":prize_{$i}"] = ($data["prize_{$i}"] ?? '') !== '' ? $data["prize_{$i}"] : null;
        }

        $stmt->execute($params);
    }

    public static function toggleRegistrations(int $leagueId): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            UPDATE league_settings
            SET registrations_open = 1 - registrations_open
            WHERE league_id = ?
        ');
        $stmt->execute([$leagueId]);
    }

    public static function updateCartolaLeagueSlug(int $leagueId, ?string $slug): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('UPDATE leagues SET cartola_league_slug = ? WHERE id = ?');
        $stmt->execute([$slug ?: null, $leagueId]);
    }

    /** All leagues that have the official-league slug configured (used by the sync cron). */
    public static function withCartolaLeagueSlug(): array
    {
        $pdo = Connection::get();

        return $pdo->query("
            SELECT * FROM leagues WHERE cartola_league_slug IS NOT NULL AND cartola_league_slug != '' AND is_active = 1
        ")->fetchAll();
    }
}
