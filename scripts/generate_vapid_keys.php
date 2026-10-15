<?php

declare(strict_types=1);

// Gera o par de chaves VAPID das notificações push. Rode UMA vez e cole as
// linhas no config.local.php do servidor (as chaves não mudam depois).
require_once __DIR__ . '/../src/Services/WebPush.php';

$keys = \Cartoleirao\Services\WebPush::generateVapidKeys();

echo "putenv('VAPID_PUBLIC_KEY={$keys['public']}');\n";
echo "putenv('VAPID_PRIVATE_KEY={$keys['private']}');\n";
echo "putenv('VAPID_SUBJECT=mailto:bgomesweb@gmail.com');\n";
