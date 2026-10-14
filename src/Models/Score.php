<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

/**
 * Score per OFFICIAL LEAGUE TEAM (official_league_teams), not per
 * registrant. The ranking reflects everyone actually playing in the
 * Cartola league — registration/payment is separate information, only
 * cross-referenced here for display (paid badge, contact name).
 */
class Score
{
    public static function upsert(int $officialLeagueTeamId, int $roundId, float $points, ?float $budget, string $source = 'api'): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            INSERT INTO scores (official_league_team_id, round_id, points, budget, source)
            VALUES (:official_league_team_id, :round_id, :points, :budget, :source)
            ON DUPLICATE KEY UPDATE
                points = VALUES(points),
                budget = VALUES(budget),
                source = VALUES(source)
        ');
        $stmt->execute([
            ':official_league_team_id' => $officialLeagueTeamId,
            ':round_id' => $roundId,
            ':points' => $points,
            ':budget' => $budget,
            ':source' => $source,
        ]);
    }

    private const SELECT_BASE = "
        SELECT
            t.id AS official_league_team_id,
            t.cartola_team_id,
            t.team_name,
            t.slug,
            t.crest_url,
            t.registrant_id,
            r.name AS registrant_name,
            r.paid AS paid
    ";

    /** League ranking for a specific round. */
    public static function rankingForRound(int $leagueId, int $roundId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(self::SELECT_BASE . ',
                sc.points, sc.budget
            FROM scores sc
            JOIN official_league_teams t ON t.id = sc.official_league_team_id
            LEFT JOIN registrants r ON r.id = t.registrant_id
            WHERE t.league_id = :league_id AND sc.round_id = :round_id
            ORDER BY sc.points DESC
        ');
        $stmt->execute([':league_id' => $leagueId, ':round_id' => $roundId]);

        return $stmt->fetchAll();
    }

    /** Overall ranking (sum of all scored rounds so far) of a season (default: the active one). */
    public static function overallRanking(int $leagueId, ?int $seasonId = null): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(self::SELECT_BASE . ',
                COALESCE(SUM(sc.points), 0) AS total_points,
                COUNT(sc.id) AS scored_rounds
            FROM official_league_teams t
            LEFT JOIN registrants r ON r.id = t.registrant_id
            LEFT JOIN scores sc ON sc.official_league_team_id = t.id
            WHERE t.league_id = :league_id AND t.season_id = :season_id
            GROUP BY t.id, t.cartola_team_id, t.team_name, t.slug, t.crest_url, t.registrant_id, r.name, r.paid
            ORDER BY total_points DESC
        ');
        $stmt->execute([':league_id' => $leagueId, ':season_id' => $seasonId ?? Season::activeId()]);

        return $stmt->fetchAll();
    }

    /** Overall ranking considering only rounds up to (and including) the given number. */
    public static function overallRankingUpToRound(int $leagueId, int $roundNumberLimit): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(self::SELECT_BASE . ',
                COALESCE(SUM(sc.points), 0) AS total_points,
                COUNT(sc.id) AS scored_rounds
            FROM official_league_teams t
            LEFT JOIN registrants r ON r.id = t.registrant_id
            LEFT JOIN (
                SELECT sc2.id, sc2.official_league_team_id, sc2.points
                FROM scores sc2
                JOIN rounds r2 ON r2.id = sc2.round_id
                WHERE r2.number <= :number_limit AND r2.season_id = :round_season_id
            ) sc ON sc.official_league_team_id = t.id
            WHERE t.league_id = :league_id AND t.season_id = :season_id
            GROUP BY t.id, t.cartola_team_id, t.team_name, t.slug, t.crest_url, t.registrant_id, r.name, r.paid
            ORDER BY total_points DESC
        ');
        $stmt->execute([':league_id' => $leagueId, ':number_limit' => $roundNumberLimit, ':season_id' => Season::activeId(), ':round_season_id' => Season::activeId()]);

        return $stmt->fetchAll();
    }

    /** Round numbers (desc) that already have at least one score for this league. */
    public static function roundNumbersWithData(int $leagueId, ?int $seasonId = null): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT DISTINCT r.number
            FROM rounds r
            JOIN scores sc ON sc.round_id = r.id
            JOIN official_league_teams t ON t.id = sc.official_league_team_id
            WHERE t.league_id = :league_id AND r.season_id = :season_id
            ORDER BY r.number DESC
        ');
        $stmt->execute([':league_id' => $leagueId, ':season_id' => $seasonId ?? Season::activeId()]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Turns a (already ordered) ranking array into a map
     * official_league_team_id => position (1-based). Used to compare
     * positions between two points in time and compute the change (▲▼-).
     */
    public static function positionMap(array $ranking): array
    {
        $map = [];
        foreach ($ranking as $i => $row) {
            $map[(int) $row['official_league_team_id']] = $i + 1;
        }

        return $map;
    }

    /**
     * Computes the position change between two position maps (current vs
     * previous). Returns null if the team wasn't in the previous ranking
     * (newcomer). Positive = moved up, negative = moved down, 0 = unchanged.
     */
    public static function positionChange(array $previousMap, int $officialLeagueTeamId, int $currentPosition): ?int
    {
        if (!isset($previousMap[$officialLeagueTeamId])) {
            return null;
        }

        return $previousMap[$officialLeagueTeamId] - $currentPosition;
    }

    /** Top N teams of a league in the given round (used in the home page highlights). */
    public static function highlightsForRound(int $leagueId, int $roundId, int $limit = 8): array
    {
        $ranking = self::rankingForRound($leagueId, $roundId);

        return array_slice($ranking, 0, $limit);
    }

    /**
     * Latest round (number) that already has at least one score registered
     * for the given league. Used to know which round to show in the home
     * page "highlights" without relying on the market's current_round
     * (which may not have scores yet, since the market opens before the
     * games happen).
     */
    public static function latestRoundWithData(int $leagueId): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT r.*
            FROM rounds r
            JOIN scores sc ON sc.round_id = r.id
            JOIN official_league_teams t ON t.id = sc.official_league_team_id
            WHERE t.league_id = :league_id AND r.season_id = :season_id
            GROUP BY r.id
            ORDER BY r.number DESC
            LIMIT 1
        ');
        $stmt->execute([':league_id' => $leagueId, ':season_id' => Season::activeId()]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Points of every team of a league in a season, round by round:
     * [official_league_team_id => [round_number => points]].
     */
    public static function pointsByTeamAndRound(int $leagueId, int $seasonId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT sc.official_league_team_id, r.number, sc.points
            FROM scores sc
            JOIN rounds r ON r.id = sc.round_id
            JOIN official_league_teams t ON t.id = sc.official_league_team_id
            WHERE t.league_id = :league_id AND t.season_id = :season_id AND r.season_id = :round_season_id
        ');
        $stmt->execute([':league_id' => $leagueId, ':season_id' => $seasonId, ':round_season_id' => $seasonId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['official_league_team_id']][(int) $row['number']] = (float) $row['points'];
        }

        return $map;
    }

    /**
     * Every season a Cartola team played, with its league, total and
     * number of scored rounds (history of one team across the years).
     */
    public static function seasonTotalsForCartolaTeam(int $cartolaTeamId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT s.year, s.id AS season_id, l.name AS league_name, t.id AS official_league_team_id,
                   t.team_name, t.owner_name,
                   COALESCE(SUM(sc.points), 0) AS total_points, COUNT(sc.id) AS scored_rounds
            FROM official_league_teams t
            JOIN seasons s ON s.id = t.season_id
            JOIN leagues l ON l.id = t.league_id
            LEFT JOIN scores sc ON sc.official_league_team_id = t.id
            WHERE t.cartola_team_id = :team_id
            GROUP BY s.id, s.year, l.id, l.name, t.id, t.team_name, t.owner_name
            ORDER BY s.year DESC, l.is_default DESC, l.name ASC
        ');
        $stmt->execute([':team_id' => $cartolaTeamId]);

        return $stmt->fetchAll();
    }
}
