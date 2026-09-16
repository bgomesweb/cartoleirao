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
    private CartolaAuthService $auth;

    public function __construct(?CartolaApiClient $api = null, ?CartolaAuthService $auth = null)
    {
        $this->api = $api ?? new CartolaApiClient();
        $this->auth = $auth ?? new CartolaAuthService($this->api);
    }

    /** Last HTTP/parse error from the Cartola API call (not to be confused with a login/token error). */
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

    /**
     * Logs in with the configured account (.env) and queries the OFFICIAL
     * Cartola league, matching each team found to a registrant of our
     * system when possible (by an already-linked team_id, or by the team
     * name given at sign-up). Teams that don't match anyone remain visible
     * in the admin panel as "no matching registration" — someone joined
     * the official league but hasn't filled out our registration form yet.
     *
     * @return array{total:int, vinculados:int, sem_correspondencia:int}|null null if authentication/query failed
     */
    public function syncOfficialLeagueTeams(int $leagueId, string $cartolaLeagueSlug, ?array $forcedResponse = null, string $type = 'classic'): ?array
    {
        $teams = [];
        $fullResponse = null;

        if ($forcedResponse !== null) {
            $teams = $this->extractTeamsFromResponse($forcedResponse);
            $fullResponse = $forcedResponse;
        } elseif ($type === 'round_robin') {
            // Newer format (opaque ID, e.g. cartola.globo.com/#!/competicoes/pontoscorridos/{id}):
            // single response, no pagination — already comes with participants, matchups and ranking ready.
            $token = $this->auth->getToken();
            if (!$token) {
                $this->log('liga_oficial', null, false, 'Sem token de autenticação: ' . ($this->auth->getLastError() ?? 'motivo desconhecido'));
                return null;
            }

            $response = $this->api->roundRobinCompetition($cartolaLeagueSlug, $token);

            if ($response === null) {
                $error = $this->api->getLastError() ?? '';
                if (str_contains($error, 'HTTP 401') || str_contains($error, 'HTTP 403')) {
                    $token = $this->auth->renewToken();
                    if ($token) {
                        $response = $this->api->roundRobinCompetition($cartolaLeagueSlug, $token);
                    }
                }
            }

            if ($response === null) {
                $this->log('liga_oficial', null, false, 'Falha ao consultar competição pontos corridos: ' . ($this->api->getLastError() ?? 'sem detalhes'));
                return null;
            }

            $fullResponse = $response;
            $teams = $this->extractTeamsFromResponse($response);
        } else {
            $token = $this->auth->getToken();
            if (!$token) {
                $this->log('liga_oficial', null, false, 'Sem token de autenticação: ' . ($this->auth->getLastError() ?? 'motivo desconhecido'));
                return null;
            }

            $page = 1;
            $maxPages = 25; // protection against an infinite loop
            $tokenRenewed = false;

            while ($page <= $maxPages) {
                $response = $this->api->officialLeague($cartolaLeagueSlug, $token, $page);

                // token may have expired -> try to renew it once
                if ($response === null && !$tokenRenewed) {
                    $error = $this->api->getLastError() ?? '';
                    if (str_contains($error, 'HTTP 401') || str_contains($error, 'HTTP 403')) {
                        $token = $this->auth->renewToken();
                        $tokenRenewed = true;
                        if (!$token) {
                            $this->log('liga_oficial', null, false, 'Token expirou e a renovação falhou: ' . ($this->auth->getLastError() ?? ''));
                            return null;
                        }
                        continue; // retry the same page with the new token
                    }
                }

                if ($response === null) {
                    $this->log('liga_oficial', null, false, 'Falha ao consultar liga oficial: ' . ($this->api->getLastError() ?? 'sem detalhes'));
                    return $teams ? $this->processOfficialLeagueTeams($leagueId, $teams) : null;
                }

                $fullResponse = $response;
                $pageTeams = $this->extractTeamsFromResponse($response);
                if (empty($pageTeams)) {
                    break;
                }

                $teams = array_merge($teams, $pageTeams);
                $page++;
            }
        }

        if (empty($teams)) {
            $this->log('liga_oficial', null, false, 'Nenhum time encontrado na liga oficial (slug: ' . $cartolaLeagueSlug . ')');
            return ['total' => 0, 'vinculados' => 0, 'sem_correspondencia' => 0, 'rodadas_importadas' => 0];
        }

        $result = $this->processOfficialLeagueTeams($leagueId, $teams);

        // Bonus: if the response brings the matchup history (the "pontos
        // corridos"/round-robin format), take the opportunity to save all
        // already-played rounds' scores at once, without needing the
        // round-by-round public endpoint.
        $result['rodadas_importadas'] = $fullResponse
            ? $this->processMatchupsFromResponse($leagueId, $fullResponse)
            : 0;

        // Bonus 2: the `ranking` array already brings the ready-made
        // official table (position, change, W/D/L, table points,
        // accumulated score) — save that snapshot to display it just like
        // the Cartola site does.
        $result['ranking_atualizado'] = $fullResponse
            ? $this->processRankingFromResponse($leagueId, $fullResponse)
            : 0;

        return $result;
    }

    /**
     * "Pontos corridos" (round-robin) format bonus: the response brings a
     * `ranking` array already with the ready-made official table
     * (position, change, W/D/L, table points, accumulated Cartola score)
     * — exactly what the Cartola site shows in the "Tabela de Resultados".
     * We just save that snapshot, without recalculating anything.
     */
    private function processRankingFromResponse(int $leagueId, array $response): int
    {
        if (!isset($response['ranking']) || !is_array($response['ranking'])) {
            return 0;
        }

        $idMap = OfficialLeagueTeam::cartolaIdToIdMap($leagueId);
        $total = 0;

        foreach ($response['ranking'] as $item) {
            if (!is_array($item) || !isset($item['time_id'])) {
                continue;
            }

            $cartolaTeamId = (int) $item['time_id'];
            if (!isset($idMap[$cartolaTeamId])) {
                continue; // team in the ranking that wasn't in `participantes` (shouldn't happen)
            }

            OfficialLeagueTeam::updateStandingsSnapshot(
                $leagueId,
                $cartolaTeamId,
                (int) ($item['posicao'] ?? 0),
                (int) ($item['variacao'] ?? 0),
                (int) ($item['pontos'] ?? 0),
                (int) ($item['vitorias'] ?? 0),
                (int) ($item['empates'] ?? 0),
                (int) ($item['derrotas'] ?? 0),
                (float) ($item['pontos_cartola'] ?? 0)
            );
            $total++;
        }

        $this->log('liga_oficial', null, true, "{$total} times com snapshot da tabela oficial atualizado");

        return $total;
    }

    /**
     * @param array<int,array{team_id:int,name_raw:string,cartola_name_raw:string,slug:string,crest:?string}> $teams
     * @return array{total:int, vinculados:int, sem_correspondencia:int}
     */
    private function processOfficialLeagueTeams(int $leagueId, array $teams): array
    {
        // The Cartola API has been observed with the `nome`/`nome_cartola`
        // fields in different orders depending on the league's format.
        // Instead of hardcoding a fixed convention in code, we decide
        // dynamically, comparing both fields against the team names the
        // registrants themselves provided in our sign-up form — whichever
        // has more matches is the right field.
        $teamNameField = $this->decideTeamNameField($leagueId, $teams);
        $ownerNameField = $teamNameField === 'name_raw' ? 'cartola_name_raw' : 'name_raw';

        $linked = 0;
        $unmatched = 0;

        foreach ($teams as $team) {
            $teamId = $team['team_id'];
            $registrantId = null;
            $teamName = $team[$teamNameField];
            $ownerName = $team[$ownerNameField];

            // 1) is there already someone from this league linked to this team_id?
            $registrant = Registrant::findByLeagueAndTeamId($leagueId, $teamId);

            // 2) if not, try to match by the team name given at sign-up —
            //    try the field already identified as "team name" first,
            //    then fall back to the other field too (covers the rare
            //    case where the convention varies within the same
            //    response).
            if (!$registrant && $teamName !== '') {
                $registrant = Registrant::findUnlinkedByLeagueAndTeamName($leagueId, $teamName);
                if ($registrant) {
                    Registrant::linkCartolaTeam((int) $registrant['id'], $teamId, $teamName, $team['slug']);
                }
            }

            if (!$registrant && $ownerName !== '') {
                $registrant = Registrant::findUnlinkedByLeagueAndTeamName($leagueId, $ownerName);
                if ($registrant) {
                    // matched via the "swapped" field — this registrant's
                    // display name for the team is the other field
                    $teamName = $ownerName;
                    Registrant::linkCartolaTeam((int) $registrant['id'], $teamId, $teamName, $team['slug']);
                }
            }

            if ($registrant) {
                $registrantId = (int) $registrant['id'];
                $linked++;
            } else {
                $unmatched++;
            }

            OfficialLeagueTeam::upsert(
                $leagueId,
                $teamId,
                $teamName,
                $team['slug'],
                $team['crest'] ?? null,
                $registrantId,
                $ownerName ?: null,
                $team['is_subscriber'] ?? false
            );
        }

        $total = count($teams);
        $this->log('liga_oficial', null, true, "{$total} times na liga oficial, {$linked} vinculados, {$unmatched} sem correspondência (campo detectado como nome do time: {$teamNameField})");

        return ['total' => $total, 'vinculados' => $linked, 'sem_correspondencia' => $unmatched];
    }

    /**
     * Decides which of the two fields coming from the API ("nome" or
     * "nome_cartola") really holds the team name, by comparing both values
     * against the team names the league's registrants provided at
     * sign-up. Whenever there's enough data to decide, this automatic
     * detection always wins over any fixed assumption about the API's
     * format — the format has changed convention before and may change
     * again.
     *
     * @param array<int,array{name_raw:string,cartola_name_raw:string}> $teams
     */
    private function decideTeamNameField(int $leagueId, array $teams): string
    {
        $registeredNames = Registrant::cartolaTeamNamesByLeague($leagueId);

        if (empty($registeredNames)) {
            // nobody has registered yet (or didn't give a team name) —
            // nothing to compare against, keep the documented default
            // convention as a safety fallback
            return 'name_raw';
        }

        $nameHits = 0;
        $cartolaNameHits = 0;

        foreach ($teams as $team) {
            $name = mb_strtolower(trim((string) ($team['name_raw'] ?? '')));
            $cartolaName = mb_strtolower(trim((string) ($team['cartola_name_raw'] ?? '')));

            if ($name !== '' && in_array($name, $registeredNames, true)) {
                $nameHits++;
            }
            if ($cartolaName !== '' && in_array($cartolaName, $registeredNames, true)) {
                $cartolaNameHits++;
            }
        }

        $field = $cartolaNameHits > $nameHits ? 'cartola_name_raw' : 'name_raw';

        $this->log(
            'liga_oficial',
            null,
            true,
            "Detecção automática do campo de nome do time: '{$field}' (acertos nome={$nameHits}, nome_cartola={$cartolaNameHits}, inscritos comparados=" . count($registeredNames) . ')'
        );

        return $field;
    }

    /**
     * The auth/liga/{slug} response has at least two known formats:
     *
     * 1) "pontos corridos" (round-robin): `participantes` dictionary (key = team_id).
     * 2) Generic alternative format (classic league): list in `times` or at the root.
     *
     * IMPORTANT: which field (`nome` or `nome_cartola`) is the team name
     * and which is the owner/manager name has already been observed to
     * vary between responses — so this method does NOT decide that. It
     * only extracts both raw values (`name_raw` and `cartola_name_raw`);
     * `decideTeamNameField()` decides which is which, by comparing against
     * the registrants' real data.
     */
    private function extractTeamsFromResponse(array $response): array
    {
        // Format 1: `participantes` dictionary (round-robin league)
        if (isset($response['participantes']) && is_array($response['participantes'])) {
            $teams = [];
            foreach ($response['participantes'] as $item) {
                if (!is_array($item) || !isset($item['time_id'])) {
                    continue;
                }

                $teams[] = [
                    'team_id' => (int) $item['time_id'],
                    'name_raw' => trim((string) ($item['nome'] ?? '')),
                    'cartola_name_raw' => trim((string) ($item['nome_cartola'] ?? '')),
                    'slug' => $item['slug'] ?? '',
                    'crest' => $item['url_escudo_svg'] ?? $item['url_escudo_png'] ?? ($item['escudo'] ?? null),
                    'is_subscriber' => !empty($item['assinante']),
                ];
            }

            return $teams;
        }

        // Format 2: generic fallback (format not yet confirmed)
        $raw = $response['times'] ?? $response['liga']['times'] ?? $response;

        if (!is_array($raw)) {
            return [];
        }

        $teams = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['time_id'])) {
                continue;
            }

            $teams[] = [
                'team_id' => (int) $item['time_id'],
                'name_raw' => trim((string) ($item['nome'] ?? '')),
                'cartola_name_raw' => trim((string) ($item['nome_cartola'] ?? ($item['nome'] ?? ''))),
                'slug' => $item['slug'] ?? '',
                'crest' => $item['url_escudo_svg'] ?? $item['url_escudo_png'] ?? ($item['escudo'] ?? null),
            ];
        }

        return $teams;
    }

    /**
     * "Pontos corridos" (round-robin) format bonus: the response already
     * brings the full score history per round in `confrontos` (key = round
     * number, value = list of the league's internal matchups, each with
     * home/away containing the team's `id` and `pontuacao_rodada`). This
     * avoids having to call the public endpoint round by round, team by
     * team — it all comes at once on import.
     */
    private function processMatchupsFromResponse(int $leagueId, array $response): int
    {
        if (!isset($response['confrontos']) || !is_array($response['confrontos'])) {
            return 0;
        }

        $idMap = OfficialLeagueTeam::cartolaIdToIdMap($leagueId);
        $totalRounds = 0;

        foreach ($response['confrontos'] as $roundNumber => $roundMatchups) {
            $roundNumber = (int) $roundNumber;
            if ($roundNumber <= 0 || !is_array($roundMatchups)) {
                continue;
            }

            $roundId = null;

            foreach ($roundMatchups as $matchup) {
                foreach (['mandante', 'visitante'] as $side) {
                    $data = $matchup[$side] ?? null;

                    // future rounds only have 'id', no pontuacao_rodada yet
                    if (!is_array($data) || !isset($data['id']) || !array_key_exists('pontuacao_rodada', $data) || $data['pontuacao_rodada'] === null) {
                        continue;
                    }

                    $cartolaTeamId = (int) $data['id'];
                    if (!isset($idMap[$cartolaTeamId])) {
                        continue;
                    }

                    if ($roundId === null) {
                        $roundId = Round::upsert($roundNumber, null, null, 'closed');
                    }

                    Score::upsert($idMap[$cartolaTeamId], $roundId, (float) $data['pontuacao_rodada'], null, 'api');
                }
            }

            if ($roundId !== null) {
                $totalRounds++;
            }
        }

        $this->log('liga_oficial', null, true, "{$totalRounds} rodadas de pontuação importadas do JSON colado (confrontos)");

        return $totalRounds;
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
