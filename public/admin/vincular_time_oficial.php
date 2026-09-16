<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\OfficialLeagueTeam;
use Cartoleirao\Models\Registrant;

AdminAuth::requireLogin();

$leagueId = (int) ($_POST['liga_id'] ?? 0);

if (!Csrf::isValid($_POST['_csrf'] ?? null)) {
    redirect('liga-oficial.php?liga_id=' . $leagueId);
}

$officialLeagueTeamId = (int) ($_POST['time_liga_oficial_id'] ?? 0);
$registrantId = (int) ($_POST['participante_id'] ?? 0);

if ($officialLeagueTeamId > 0 && $registrantId > 0) {
    $team = OfficialLeagueTeam::findById($officialLeagueTeamId);
    $registrant = Registrant::findById($registrantId);

    // only link if the team and the registrant belong to the same league
    if ($team && $registrant && (int) $registrant['league_id'] === (int) $team['league_id']) {
        OfficialLeagueTeam::linkRegistrant($officialLeagueTeamId, $registrantId);
    }
}

redirect('liga-oficial.php?liga_id=' . $leagueId);
