<?php

declare(strict_types=1);

// Chamado a cada minuto pelo cron; dentro dele checa a cada 10s (o cron do cPanel não roda
// menos de 1 min). Envia as notificações push de gol, gol anulado e fim de jogo, só com o
// mercado fechado e enquanto houver jogo em andamento.
require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\GoalNotifier;
use Cartoleirao\Services\LiveScoreClient;
use Cartoleirao\Services\WebPush;

date_default_timezone_set('America/Sao_Paulo');

const INTERVAL_SECONDS = 10;
const RUN_SECONDS = 54; // less than the 1-minute cron period, so runs never overlap

// Avoids two overlapping runs (a slow API call must not duplicate notifications).
if (!GoalNotifier::lock()) {
    echo "[notify_goals] outra execução em andamento\n";
    exit(0);
}

$notifier = new GoalNotifier(new CartolaApiClient(), new LiveScoreClient(), WebPush::fromEnv());

$round = $notifier->activeRound();
if ($round === null) {
    echo '[notify_goals] ' . date('H:i:s') . " mercado aberto (ou indisponível): nada a fazer\n";
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
