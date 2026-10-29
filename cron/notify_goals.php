<?php

declare(strict_types=1);

// Chamado a cada minuto pelo cron; dentro dele checa a cada 4s (o cron do cPanel não roda
// menos de 1 min). Envia as notificações push de gol, gol anulado e fim de jogo enquanto houver
// jogo em andamento (rodada com mercado fechado, ou a anterior logo depois que o mercado reabre).
require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\GoalNotifier;
use Cartoleirao\Services\LiveScoreClient;
use Cartoleirao\Services\ScheduleNotifier;
use Cartoleirao\Services\WebPush;

date_default_timezone_set('America/Sao_Paulo');

const INTERVAL_SECONDS = 4;
const RUN_SECONDS = 54; // less than the 1-minute cron period, so runs never overlap

// Avoids two overlapping runs (a slow API call must not duplicate notifications).
if (!GoalNotifier::lock()) {
    echo "[notify_goals] outra execução em andamento\n";
    exit(0);
}

$cartola = new CartolaApiClient();
$notifier = new GoalNotifier($cartola, new LiveScoreClient(), WebPush::fromEnv());

$status = $cartola->marketStatus();

// Avisos de mercado aberto/fechado e dos jogos do dia (uma vez cada); aproveitam este mesmo cron.
$schedule = new ScheduleNotifier(WebPush::fromEnv());
$schedule->notifyMarket($status);
$schedule->notifyDailyGames();

$round = $notifier->activeRound($status);
if ($round === null) {
    echo '[notify_goals] ' . date('H:i:s') . " sem rodada em andamento (ou API do Cartola indisponível): nada a fazer\n";
    exit(0);
}

$deadline = time() + RUN_SECONDS;
while (true) {
    $started = microtime(true);
    echo '[notify_goals] ' . date('H:i:s') . ' ' . $notifier->run($round) . "\n";

    $wait = INTERVAL_SECONDS - (microtime(true) - $started);
    if (!$notifier->hasWork() || time() + $wait >= $deadline) {
        break;
    }
    usleep((int) (max($wait, 0) * 1_000_000));
}
