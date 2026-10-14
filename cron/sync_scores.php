<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\SyncService;

$api = new CartolaApiClient();
$service = new SyncService($api);

// Syncs the score of the rounds for EVERY team in official_league_teams
// (public endpoint, no login). That roster is built from the registrants
// linked to a Cartola team through the public API — refreshed here first.
\Cartoleirao\Models\OfficialLeagueTeam::syncFromRegistrants();

try {
    $status = $api->marketStatus();
} catch (\Throwable $e) {
    echo "[sync_scores] falha ao consultar status do mercado: {$e->getMessage()}\n";
    exit(1);
}

if (!$status) {
    echo "[sync_scores] não foi possível consultar o status do mercado\n";
    if ($api->getLastError()) {
        echo "[sync_scores] motivo: {$api->getLastError()}\n";
    }
    exit(1);
}

$currentRoundNumber = $status['rodada_atual'] ?? null;

if (!$currentRoundNumber) {
    echo "[sync_scores] não foi possível determinar a rodada atual\n";
    exit(1);
}

// IMPORTANT: "rodada_atual" from /mercado/status is the round with the
// MARKET OPEN for squad picks — i.e. it hasn't happened yet, no real score
// exists. The public per-team score endpoint, when queried for a round
// that hasn't been played, doesn't return an error — it returns the
// "frozen" score of the last closed round, which used to save wrong data
// (the new round showing up with the same points as the previous one).
// That's why we NEVER sync the current round, only past ones (with a
// 2-round safety window to make sure nothing is left behind).
$roundsToSync = array_unique(array_filter([
    max(1, (int) $currentRoundNumber - 2),
    max(1, (int) $currentRoundNumber - 1),
]));

foreach ($roundsToSync as $number) {
    $result = $service->syncRoundScores($number);
    echo "[sync_scores] rodada {$number}: {$result['sucesso']} times ok, {$result['falhas']} falhas\n";
}
