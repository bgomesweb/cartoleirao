<?php

declare(strict_types=1);

// Compatibilidade: o cron real do cPanel ainda aponta pra este nome de
// arquivo. Atualize o crontab pra apontar direto pra sync_games.php (veja
// DEPLOY-CPANEL.md) — depois disso, este arquivo pode ser removido.
require __DIR__ . '/sync_games.php';
