<?php

declare(strict_types=1);

// Envia uma notificação de teste para todos os aparelhos inscritos.
require_once __DIR__ . '/../src/Config/bootstrap.php';

use Cartoleirao\Models\PushSubscription;
use Cartoleirao\Services\WebPush;

$push = WebPush::fromEnv();
if (!$push) {
    fwrite(STDERR, "VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY não configuradas.\n");
    exit(1);
}

$total = count(PushSubscription::all());
$ok = PushSubscription::broadcast($push, '⚽ Cartoleirão', 'Goool: Corinthians 1 x 0 Palmeiras - Yuri Alberto');
echo "Aparelhos inscritos: {$total} — entregues: {$ok}\n";
