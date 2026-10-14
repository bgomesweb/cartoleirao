<?php

declare(strict_types=1);

/**
 * Before pulling scores, (1) tries to auto-link the registrants that still
 * aren't linked to a Cartola team (public search by the team name they gave;
 * links only when exactly 1 team matches) and (2) refreshes the ranking
 * roster (official_league_teams) from the linked registrants.
 *
 * Pulls the score of EVERY round already played (from 1 to the last closed
 * round) for every team registered in official_league_teams — useful to
 * fill in the whole history at once (the normal cron only syncs the last 2
 * rounds each run, so it doesn't overload the API).
 *
 * Uso:
 *   docker compose exec app php scripts/backfill_scores.php
 *   docker compose exec app php scripts/backfill_scores.php 1 15   (só rodadas 1 a 15)
 *
 * Runs once (not a cron), takes a while because it hits the public API once
 * per team per round — there's a small pause between calls so it doesn't
 * overload it.
 */

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Database\Connection;
use Cartoleirao\Models\OfficialLeagueTeam;
use Cartoleirao\Models\Registrant;
use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\SyncService;

$api = new CartolaApiClient();
$service = new SyncService($api);

// 1) auto-link pending registrants through the public search
$pending = Connection::get()->query("
    SELECT id, cartola_team_name FROM registrants
    WHERE active = 1 AND season_id = " . \Cartoleirao\Models\Season::activeId() . " AND linked_to_api = 0
      AND cartola_team_name IS NOT NULL AND cartola_team_name != ''
")->fetchAll();

$autoLinked = 0;
foreach ($pending as $r) {
    $candidates = $api->searchTeamsByName($r['cartola_team_name']);
    if (count($candidates) === 1) {
        Registrant::linkCartolaTeam((int) $r['id'], $candidates[0]['team_id'], $r['cartola_team_name'], $candidates[0]['slug']);
        $autoLinked++;
    } else {
        echo "[backfill] sem vínculo automático para \"{$r['cartola_team_name']}\" (" . count($candidates) . " resultados) — vincule manualmente em admin/inscricoes.php\n";
    }
    usleep(300000);
}
echo "[backfill] {$autoLinked} inscritos vinculados automaticamente (de " . count($pending) . " pendentes).\n";

// 2) ranking roster = linked registrants
$rosterTotal = OfficialLeagueTeam::syncFromRegistrants();
echo "[backfill] {$rosterTotal} times no elenco do ranking.\n";

$startRound = isset($argv[1]) ? (int) $argv[1] : 1;
$endRound = null;

if (isset($argv[2])) {
    $endRound = (int) $argv[2];
} else {
    $status = $api->marketStatus();
    if (!$status || !isset($status['rodada_atual'])) {
        fwrite(STDERR, "Não consegui descobrir a rodada atual (" . ($api->getLastError() ?? 'sem detalhes') . "). Informe manualmente: php backfill_scores.php 1 20\n");
        exit(1);
    }
    // never includes the current round (not played yet — see bugfix note in the README)
    $endRound = max(1, (int) $status['rodada_atual'] - 1);
}

if ($startRound > $endRound) {
    fwrite(STDERR, "Rodada inicial ({$startRound}) maior que a final ({$endRound}), nada a fazer.\n");
    exit(1);
}

echo "[backfill] sincronizando rodadas {$startRound} até {$endRound}...\n";

$totalOk = 0;
$totalFailed = 0;

for ($round = $startRound; $round <= $endRound; $round++) {
    $result = $service->syncRoundScores($round);
    echo "[backfill] rodada {$round}: {$result['sucesso']} ok, {$result['falhas']} falhas\n";
    $totalOk += $result['sucesso'];
    $totalFailed += $result['falhas'];

    // small pause between rounds so we don't hammer the API
    usleep(300000); // 0.3s
}

echo "[backfill] concluído: {$totalOk} pontuações gravadas, {$totalFailed} falhas no total.\n";
