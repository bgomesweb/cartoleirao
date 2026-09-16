<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class OfficialLeagueTeam
{
    public static function upsert(
        int $leagueId,
        int $cartolaTeamId,
        ?string $teamName,
        ?string $slug,
        ?string $crestUrl,
        ?int $registrantId,
        ?string $ownerName = null,
        bool $isSubscriber = false
    ): void {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            INSERT INTO official_league_teams (league_id, cartola_team_id, team_name, owner_name, is_subscriber, slug, crest_url, registrant_id)
            VALUES (:league_id, :cartola_team_id, :team_name, :owner_name, :is_subscriber, :slug, :crest_url, :registrant_id)
            ON DUPLICATE KEY UPDATE
                team_name = VALUES(team_name),
                owner_name = VALUES(owner_name),
                is_subscriber = VALUES(is_subscriber),
                slug = VALUES(slug),
                crest_url = VALUES(crest_url),
                registrant_id = COALESCE(VALUES(registrant_id), registrant_id)
        ');
        $stmt->execute([
            ':league_id' => $leagueId,
            ':cartola_team_id' => $cartolaTeamId,
            ':team_name' => $teamName,
            ':owner_name' => $ownerName,
            ':is_subscriber' => $isSubscriber ? 1 : 0,
            ':slug' => $slug,
            ':crest_url' => $crestUrl,
            ':registrant_id' => $registrantId,
        ]);
    }

    /**
     * Saves the official round-robin standings snapshot (position, change,
     * W/D/L, table points, accumulated Cartola score) — comes from the
     * `ranking` array of the league response, refreshed on every import.
     */
    public static function updateStandingsSnapshot(
        int $leagueId,
        int $cartolaTeamId,
        int $position,
        int $change,
        int $points,
        int $wins,
        int $draws,
        int $losses,
        float $cartolaPoints
    ): void {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            UPDATE official_league_teams
            SET standing_position = :position,
                standing_change = :change,
                standing_points = :points,
                standing_wins = :wins,
                standing_draws = :draws,
                standing_losses = :losses,
                standing_cartola_points = :cartola_points
            WHERE league_id = :league_id AND cartola_team_id = :cartola_team_id
        ');
        $stmt->execute([
            ':position' => $position,
            ':change' => $change,
            ':points' => $points,
            ':wins' => $wins,
            ':draws' => $draws,
            ':losses' => $losses,
            ':cartola_points' => $cartolaPoints,
            ':league_id' => $leagueId,
            ':cartola_team_id' => $cartolaTeamId,
        ]);
    }

    /** Official round-robin table, ordered by the official standing position. */
    public static function byLeagueOrderedByStanding(int $leagueId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT t.*, r.name AS registrant_name, r.email AS registrant_email, r.paid AS registrant_paid
            FROM official_league_teams t
            LEFT JOIN registrants r ON r.id = t.registrant_id
            WHERE t.league_id = :league_id
            ORDER BY (t.standing_position IS NULL), t.standing_position ASC
        ');
        $stmt->execute([':league_id' => $leagueId]);

        return $stmt->fetchAll();
    }

    /** All teams of the official league, with the matched registrant name (if any). */
    public static function byLeague(int $leagueId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT t.*, r.name AS registrant_name, r.email AS registrant_email, r.paid AS registrant_paid
            FROM official_league_teams t
            LEFT JOIN registrants r ON r.id = t.registrant_id
            WHERE t.league_id = :league_id
            ORDER BY (t.registrant_id IS NULL) DESC, t.team_name ASC
        ');
        $stmt->execute([':league_id' => $leagueId]);

        return $stmt->fetchAll();
    }

    public static function countUnmatched(int $leagueId): int
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM official_league_teams WHERE league_id = :league_id AND registrant_id IS NULL
        ');
        $stmt->execute([':league_id' => $leagueId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Manually links an official league team to a registered registrant
     * (cross-reference to show name/payment status alongside the team in
     * the ranking).
     */
    public static function linkRegistrant(int $officialLeagueTeamId, int $registrantId): void
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('UPDATE official_league_teams SET registrant_id = ? WHERE id = ?');
        $stmt->execute([$registrantId, $officialLeagueTeamId]);
    }

    public static function findById(int $id): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM official_league_teams WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Map cartola_team_id => local id, used to resolve the scores FK in batch. */
    public static function cartolaIdToIdMap(int $leagueId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT id, cartola_team_id FROM official_league_teams WHERE league_id = ?');
        $stmt->execute([$leagueId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['cartola_team_id']] = (int) $row['id'];
        }

        return $map;
    }
}
