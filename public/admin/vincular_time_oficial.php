<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\Participante;
use Cartoleirao\Models\TimeLigaOficial;

AdminAuth::exigirLogin();

$ligaId = (int) ($_POST['liga_id'] ?? 0);

if (!Csrf::valido($_POST['_csrf'] ?? null)) {
    redirect('liga-oficial.php?liga_id=' . $ligaId);
}

$timeLigaOficialId = (int) ($_POST['time_liga_oficial_id'] ?? 0);
$participanteId = (int) ($_POST['participante_id'] ?? 0);

if ($timeLigaOficialId > 0 && $participanteId > 0) {
    $time = TimeLigaOficial::porId($timeLigaOficialId);
    $participante = Participante::porId($participanteId);

    // só vincula se o time e o participante forem da mesma liga
    if ($time && $participante && (int) $participante['liga_id'] === (int) $time['liga_id']) {
        TimeLigaOficial::vincularParticipante($timeLigaOficialId, $participanteId);
    }
}

redirect('liga-oficial.php?liga_id=' . $ligaId);
