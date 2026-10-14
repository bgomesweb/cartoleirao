<?php

declare(strict_types=1);

namespace Cartoleirao\Services;

use Cartoleirao\Database\Connection;
use Cartoleirao\Models\Club;
use Cartoleirao\Models\Game;
use Cartoleirao\Models\MarketStatus;
use Cartoleirao\Models\OfficialLeagueTeam;
use Cartoleirao\Models\Registrant;
use Cartoleirao\Models\Round;
use Cartoleirao\Models\Score;

class SyncService
{
    private CartolaApiClient $api;

    public function __construct(?CartolaApiClient $api = null)
    {
        $this->api = $api ?? new CartolaApiClient();
    }

    /** Last HTTP/parse error from the Cartola API call. */
    public function getLastApiError(): ?string
    {
        return $this->api->getLastError();
    }

    /**
     * Queries /mercado/status and caches it locally (market_status_cache),
     * to show the badge next to the logo without hitting the API on every
     * page view.
     */
    public function syncMarketStatus(?array $marketStatus = null): ?array
    {
        try {
            $marketStatus = $marketStatus ?? $this->api->marketStatus();
        } catch (\Throwable $e) {
            $this->log('mercado_status', null, false, $e->getMessage());
            return null;
        }

        if (!$marketStatus) {
            $this->log('mercado_status', null, false, 'API não retornou status do mercado');
            return null;
        }

        $statusCode = isset($marketStatus['status_mercado']) ? (int) $marketStatus['status_mercado'] : null;
        $round = isset($marketStatus['rodada_atual']) ? (int) $marketStatus['rodada_atual'] : null;

        MarketStatus::update($statusCode, $round);
        $this->log('mercado_status', $round, true, "status={$statusCode}");

        return $marketStatus;
    }

    /**
     * Syncs the local club cache. Runs at a low frequency (clubs barely
     * change during the season).
     */
    public function syncClubs(?array $apiClubs = null): int
    {
        try {
            $apiClubs = $apiClubs ?? $this->api->clubs();
        } catch (\Throwable $e) {
            $this->log('clubes', null, false, $e->getMessage());
            return 0;
        }

        if (!$apiClubs) {
            $this->log('clubes', null, false, 'API não retornou dados de clubes — ' . ($this->api->getLastError() ?? 'sem detalhes'));
            return 0;
        }

        $total = Club::upsertBatch($apiClubs);
        $this->log('clubes', null, true, "{$total} clubes sincronizados");

        return $total;
    }

    /**
     * Finds out the current round (via /mercado/status), syncs that
     * round's games and returns the round number processed (or null on
     * failure).
     */
    public function syncCurrentRoundGames(?array $marketStatus = null, ?array $games = null): ?int
    {
        try {
            $marketStatus = $marketStatus ?? $this->api->marketStatus();
        } catch (\Throwable $e) {
            $this->log('jogos', null, false, $e->getMessage());
            return null;
        }

        $roundNumber = $marketStatus['rodada_atual'] ?? null;
        if (!$roundNumber) {
            $this->log('jogos', null, false, 'Não foi possível determinar a rodada atual — ' . ($this->api->getLastError() ?? 'resposta sem campo rodada_atual'));
            return null;
        }

        return $this->syncRoundGames((int) $roundNumber, $games);
    }

    public function syncRoundGames(int $roundNumber, ?array $games = null): ?int
    {
        // make sure we have enough clubs to resolve the games' FKs
        if (Club::total() === 0) {
            $this->syncClubs();
        }

        try {
            $gamesResponse = $games ?? $this->api->gamesForRound($roundNumber);
        } catch (\Throwable $e) {
            $this->log('jogos', $roundNumber, false, $e->getMessage());
            return null;
        }

        if (!$gamesResponse) {
            $this->log('jogos', $roundNumber, false, 'API não retornou partidas para a rodada — ' . ($this->api->getLastError() ?? 'sem detalhes'));
            return null;
        }

        $gamesList = $gamesResponse['partidas'] ?? $gamesResponse;

        $roundId = Round::upsert($roundNumber, null, null, 'open');
        $total = Game::upsertBatch($roundId, $gamesList);

        $this->log('jogos', $roundNumber, true, "{$total} jogos sincronizados");

        return $roundNumber;
    }

    /**
     * For each team registered in `official_league_teams` (regardless of
     * having a matching registrant), fetches that round's score via the
     * public endpoint (`/time/id/{id}/{rodada}`, no login needed) and
     * saves it in `scores`.
     *
     * @return array{sucesso:int, falhas:int}
     */
    public function syncRoundScores(int $roundNumber): array
    {
        $round = Round::findByNumber($roundNumber) ?? ['id' => Round::upsert($roundNumber)];
        $roundId = (int) $round['id'];

        $pdo = Connection::get();
        $stmt = $pdo->query('SELECT id, cartola_team_id FROM official_league_teams');
        $teams = $stmt->fetchAll();

        $succeeded = 0;
        $failed = 0;

        foreach ($teams as $team) {
            try {
                $data = $this->api->teamScoreForRound((int) $team['cartola_team_id'], $roundNumber);
            } catch (\Throwable $e) {
                $failed++;
                continue;
            }

            if (!$data || !isset($data['pontos'])) {
                $failed++;
                continue;
            }

            Score::upsert(
                (int) $team['id'],
                $roundId,
                (float) $data['pontos'],
                isset($data['patrimonio']) ? (float) $data['patrimonio'] : null
            );
            $succeeded++;
        }

        $this->log('pontuacao', $roundNumber, $failed === 0, "{$succeeded} ok, {$failed} falhas (de " . count($teams) . " times cadastrados)");

        return ['sucesso' => $succeeded, 'falhas' => $failed];
    }

    private function log(string $type, ?int $roundNumber, bool $success, string $message): void
    {
        try {
            $pdo = Connection::get();
            $stmt = $pdo->prepare('
                INSERT INTO sync_log (type, round_number, success, message)
                VALUES (:type, :round_number, :success, :message)
            ');
            $stmt->execute([
                ':type' => $type,
                ':round_number' => $roundNumber,
                ':success' => $success ? 1 : 0,
                ':message' => $message,
            ]);
        } catch (\Throwable $e) {
            // logging must never bring the sync down
        }
    }
}
