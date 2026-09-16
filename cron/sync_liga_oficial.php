<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cartoleirao\Models\Liga;
use Cartoleirao\Services\SincronizacaoService;

// DESABILITADO POR PADRÃO: login por email/senha sempre esbarra em captcha
// da Globo, então não dá pra automatizar isso sozinho. O caminho que
// funciona: colar um token de sessão (obtido logando manualmente no
// navegador) em admin/cartola-login.php — o CartolaAuthService cacheia esse
// token em `cartola_auth` e essa cron reaproveita ele. Só habilite essa
// variável DEPOIS de ter um token válido colado lá, ou a cron vai só ficar
// tentando logar sozinha e falhando no captcha.
if ((getenv('CARTOLA_LIGA_OFICIAL_HABILITADA') ?: 'false') !== 'true') {
    echo "[sync_liga_oficial] desabilitado (CARTOLA_LIGA_OFICIAL_HABILITADA != true) — "
        . "cole um token em admin/cartola-login.php e habilite essa variável no ambiente\n";
    exit(0);
}

$servico = new SincronizacaoService();
$ligas = Liga::comSlugOficial();

if (empty($ligas)) {
    echo "[sync_liga_oficial] nenhuma liga com cartola_liga_slug configurado\n";
    exit(0);
}

$falhouAlguma = false;

foreach ($ligas as $liga) {
    $resultado = $servico->sincronizarTimesDaLigaOficial((int) $liga['id'], $liga['cartola_liga_slug'], null, $liga['tipo'] ?? 'classica');

    if ($resultado === null) {
        echo "[sync_liga_oficial] {$liga['nome']}: falhou (veja sync_log pra detalhes — provável problema de login)\n";
        $falhouAlguma = true;
        continue;
    }

    echo "[sync_liga_oficial] {$liga['nome']}: {$resultado['total']} times, "
        . "{$resultado['vinculados']} vinculados, {$resultado['sem_correspondencia']} sem inscrição correspondente\n";
}

exit($falhouAlguma ? 1 : 0);
