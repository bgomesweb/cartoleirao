<?php

declare(strict_types=1);

// Envia notificações de teste para todos os aparelhos inscritos.
//   php send_test_push.php          -> gol, 5s depois gol anulado, 5s depois fim de jogo
//   php send_test_push.php gol      -> só o gol
//   php send_test_push.php anulado  -> só o gol anulado
//   php send_test_push.php fim      -> só o fim de jogo
// Só dispara as mensagens; não mexe no estado da detecção de gols.
require_once __DIR__ . '/../src/Config/bootstrap.php';

use Cartoleirao\Models\PushSubscription;
use Cartoleirao\Services\WebPush;

$push = WebPush::fromEnv();
if (!$push) {
    fwrite(STDERR, "VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY não configuradas.\n");
    exit(1);
}

$mode = $argv[1] ?? 'ambos';
$messages = [];
if (in_array($mode, ['ambos', 'gol'], true)) {
    $messages[] = 'Goool: Corinthians 1 x 0 Palmeiras - Yuri Alberto';
}
if (in_array($mode, ['ambos', 'anulado'], true)) {
    $messages[] = 'O gol de Yuri Alberto foi anulado: Corinthians 0 x 0 Palmeiras';
}
if (in_array($mode, ['ambos', 'fim'], true)) {
    $messages[] = 'Fim de jogo: Corinthians 3 x 1 Palmeiras - Gols: Yuri Alberto (2), Raniele, Flaco Lopes';
}
if (!$messages) {
    fwrite(STDERR, "Uso: php send_test_push.php [gol|anulado|fim]\n");
    exit(1);
}

echo 'Aparelhos inscritos: ' . count(PushSubscription::all()) . "\n";
foreach ($messages as $i => $message) {
    if ($i > 0) {
        sleep(5);
    }
    $ok = PushSubscription::broadcast($push, '⚽ Cartoleirão', $message);
    echo "{$message} — entregues: {$ok}\n";
}
