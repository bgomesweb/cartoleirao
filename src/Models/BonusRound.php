<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

/**
 * "Bonus" rounds: rounds marked by the organizer with an extra prize for
 * the team that finishes 1st in THAT specific round (on top of the
 * league's general prize). E.g. rounds 10, 19, 26 and 37 of the
 * Brasileirão.
 *
 * Configuration is per league (league_id), since each league has its own
 * prizes — the same round can be a bonus round in one league and not in
 * another.
 */
class BonusRound
{
    /** All registered bonus rounds, with the league name (for the admin panel). */
    public static function allWithLeague(): array
    {
        $pdo = Connection::get();

        return $pdo->query('
            SELECT br.*, l.name AS league_name
            FROM bonus_rounds br
            JOIN leagues l ON l.id = br.league_id
            ORDER BY l.name ASC, br.round_number ASC
        ')->fetchAll();
    }

    public static function listByLeague(int $leagueId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM bonus_rounds WHERE league_id = ? ORDER BY round_number ASC');
        $stmt->execute([$leagueId]);

        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM bonus_rounds WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Returns the bonus round config for this league/round, or null if it isn't a bonus round. */
    public static function forLeagueAndRound(int $leagueId, int $roundNumber): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM bonus_rounds WHERE league_id = ? AND round_number = ?');
        $stmt->execute([$leagueId, $roundNumber]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Registers (or updates the prize amount of) one or more rounds at
     * once — used in the admin panel, which accepts a list like
     * "10,19,26,37" with a single prize amount applied to all of them.
     *
     * @param int[] $roundNumbers
     */
    public static function saveBatch(int $leagueId, array $roundNumbers, ?float $prizeAmount): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            INSERT INTO bonus_rounds (league_id, round_number, prize_amount)
            VALUES (:league_id, :round_number, :prize_amount)
            ON DUPLICATE KEY UPDATE prize_amount = VALUES(prize_amount)
        ');

        foreach ($roundNumbers as $number) {
            $stmt->execute([
                ':league_id' => $leagueId,
                ':round_number' => $number,
                ':prize_amount' => $prizeAmount,
            ]);
        }
    }

    public static function updatePrizeAmount(int $id, ?float $prizeAmount): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('UPDATE bonus_rounds SET prize_amount = ? WHERE id = ?');
        $stmt->execute([$prizeAmount, $id]);
    }

    public static function delete(int $id): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('DELETE FROM bonus_rounds WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Bonus rounds that match the market's current round (used to decide
     * whether to show the modal when the site loads). More than one league
     * can have the same round marked as a bonus round — in that case they
     * all show up in the modal.
     */
    public static function forCurrentRound(?int $currentRound): array
    {
        if (!$currentRound) {
            return [];
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT br.*, l.name AS league_name
            FROM bonus_rounds br
            JOIN leagues l ON l.id = br.league_id
            WHERE br.round_number = ? AND l.is_active = 1
            ORDER BY l.is_default DESC, l.name ASC
        ');
        $stmt->execute([$currentRound]);

        return $stmt->fetchAll();
    }
}
