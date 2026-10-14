<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Models\Season;
use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\SyncService;

$api = new CartolaApiClient();
$service = new SyncService($api);

$marketStatus = $service->syncMarketStatus();
echo $marketStatus ? "[sync_games] status do mercado atualizado\n" : "[sync_games] falha ao atualizar status do mercado: " . ($api->getLastError() ?? '') . "\n";

$totalClubs = $service->syncClubs();
echo "[sync_games] clubes sincronizados: {$totalClubs}\n";
if ($totalClubs === 0 && $api->getLastError()) {
    echo "[sync_games] motivo da falha em /clubes: {$api->getLastError()}\n";
}

// Same as sync_scores: no rounds are saved into the new season while sign-ups are open.
if (Season::registrationsOpen()) {
    echo "[sync_games] inscrições abertas — jogos não são sincronizados\n";
    exit(0);
}

$round = $service->syncCurrentRoundGames();

if ($round === null) {
    echo "[sync_games] falhou ao sincronizar jogos (API indisponível ou rodada não identificada)\n";
    if ($api->getLastError()) {
        echo "[sync_games] motivo: {$api->getLastError()}\n";
    }
    exit(1);
}

echo "[sync_games] jogos da rodada {$round} sincronizados com sucesso\n";
