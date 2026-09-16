<?php

declare(strict_types=1);

namespace Cartoleirao\Models;

use Cartoleirao\Database\Connection;

class Game
{
    /**
     * Batch upsert of a round's games, from the array of matches returned by
     * CartolaApiClient::gamesForRound().
     */
    public static function upsertBatch(int $roundId, array $apiGames): int
    {
        if (empty($apiGames)) {
            return 0;
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            INSERT INTO games
                (cartola_game_id, round_id, home_club_id, away_club_id, home_score, away_score, venue, played_at)
            VALUES
                (:cartola_game_id, :round_id, :home_club_id, :away_club_id, :home_score, :away_score, :venue, :played_at)
            ON DUPLICATE KEY UPDATE
                cartola_game_id = VALUES(cartola_game_id),
                home_score = VALUES(home_score),
                away_score = VALUES(away_score),
                venue = VALUES(venue),
                played_at = VALUES(played_at)
        ');

        $total = 0;
        foreach ($apiGames as $game) {
            if (empty($game['clube_casa_id']) || empty($game['clube_visitante_id'])) {
                continue;
            }

            $homeScore = $game['placar_oficial_mandante'] ?? $game['placar_mandante'] ?? null;
            $awayScore = $game['placar_oficial_visitante'] ?? $game['placar_visitante'] ?? null;

            $playedAt = null;
            if (!empty($game['partida_data'])) {
                $playedAt = str_replace('T', ' ', substr((string) $game['partida_data'], 0, 19));
            }

            $stmt->execute([
                ':cartola_game_id' => $game['partida_id'] ?? null,
                ':round_id' => $roundId,
                ':home_club_id' => (int) $game['clube_casa_id'],
                ':away_club_id' => (int) $game['clube_visitante_id'],
                ':home_score' => $homeScore,
                ':away_score' => $awayScore,
                ':venue' => $game['local'] ?? null,
                ':played_at' => $playedAt,
            ]);
            $total++;
        }

        return $total;
    }

    /**
     * Games of a round, already resolved with club name/crest.
     */
    public static function findByRound(int $roundId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('
            SELECT
                g.*,
                hc.name AS home_name, hc.abbreviation AS home_abbreviation, hc.crest_url AS home_crest,
                ac.name AS away_name, ac.abbreviation AS away_abbreviation, ac.crest_url AS away_crest
            FROM games g
            JOIN clubs hc ON hc.id = g.home_club_id
            JOIN clubs ac ON ac.id = g.away_club_id
            WHERE g.round_id = ?
            ORDER BY g.played_at ASC
        ');
        $stmt->execute([$roundId]);

        return $stmt->fetchAll();
    }
}
