<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Models\League;
use Cartoleirao\Services\SyncService;

// DISABLED BY DEFAULT: email/password login always hits a Globo captcha,
// so this can't be automated on its own. The path that works: paste a
// session token (obtained by logging in manually in the browser) into
// admin/cartola-login.php — CartolaAuthService caches that token in
// `cartola_auth_token` and this cron reuses it. Only enable this variable
// AFTER a valid token has been pasted there, or the cron will just keep
// trying to log in on its own and failing on the captcha.
$officialLeagueEnabled = getenv('CARTOLA_OFFICIAL_LEAGUE_ENABLED') ?: (getenv('CARTOLA_LIGA_OFICIAL_HABILITADA') ?: 'false');
if ($officialLeagueEnabled !== 'true') {
    echo "[sync_official_league] desabilitado (CARTOLA_OFFICIAL_LEAGUE_ENABLED != true) — "
        . "cole um token em admin/cartola-login.php e habilite essa variável no ambiente\n";
    exit(0);
}

$service = new SyncService();
$leagues = League::withCartolaLeagueSlug();

if (empty($leagues)) {
    echo "[sync_official_league] nenhuma liga com cartola_league_slug configurado\n";
    exit(0);
}

$anyFailed = false;

foreach ($leagues as $league) {
    $result = $service->syncOfficialLeagueTeams((int) $league['id'], $league['cartola_league_slug'], null, $league['type'] ?? 'classic');

    if ($result === null) {
        echo "[sync_official_league] {$league['name']}: falhou (veja sync_log pra detalhes — provável problema de login)\n";
        $anyFailed = true;
        continue;
    }

    echo "[sync_official_league] {$league['name']}: {$result['total']} times, "
        . "{$result['vinculados']} vinculados, {$result['sem_correspondencia']} sem inscrição correspondente\n";
}

exit($anyFailed ? 1 : 0);
