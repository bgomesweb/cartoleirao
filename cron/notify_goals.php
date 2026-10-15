<?php

declare(strict_types=1);

// Roda a cada minuto: detecta gols da rodada atual e envia a notificação push.
require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\GoalNotifier;
use Cartoleirao\Services\WebPush;

date_default_timezone_set('America/Sao_Paulo');

// Avoids two overlapping runs (a slow API call must not duplicate notifications).
if (!GoalNotifier::lock()) {
    echo "[notify_goals] outra execução em andamento\n";
    exit(0);
}

$notifier = new GoalNotifier(new CartolaApiClient(), WebPush::fromEnv());
echo '[notify_goals] ' . date('H:i:s') . ' ' . $notifier->run() . "\n";
