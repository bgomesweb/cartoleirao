<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Models\League;
use Cartoleirao\Models\Score;
use Cartoleirao\Models\Season;

$seasons = Season::all();
$seasonId = (int) ($_GET['id'] ?? 0);
$season = $seasonId ? Season::findById($seasonId) : null;
$season = $season ?? Season::active() ?? ($seasons[0] ?? null);
if (!$season) {
    redirect('temporada.php');
}
$seasonId = (int) $season['id'];

// Histórico de um time em todos os anos: ?time=<cartola_team_id>
$teamParam = (int) ($_GET['time'] ?? 0);
$teamHistory = $teamParam > 0 ? Score::seasonTotalsForCartolaTeam($teamParam) : [];

$leagues = League::all();

$pageTitle = 'Histórico da temporada ' . (int) $season['year'];
$pageSubtitle = 'Pontuação de cada time por rodada e no geral';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap gap-2 align-items-center mb-4">
    <a href="temporada.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> Temporadas</a>
    <?php foreach ($seasons as $s): ?>
        <a href="?id=<?= (int) $s['id'] ?>"
           class="btn btn-sm <?= (int) $s['id'] === $seasonId ? 'btn-brand' : 'btn-outline-secondary' ?>"><?= (int) $s['year'] ?></a>
    <?php endforeach; ?>
</div>

<?php if ($teamParam > 0): ?>
    <div class="panel mb-4">
        <div class="panel-head">
            <h3>Time em todos os anos</h3>
            <a href="?id=<?= $seasonId ?>" class="btn btn-sm btn-outline-secondary ms-auto">Fechar</a>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Temporada</th><th>Liga</th><th>Time</th><th class="text-end">Rodadas</th><th class="text-end">Pontos</th></tr></thead>
                <tbody>
                <?php foreach ($teamHistory as $h): ?>
                    <tr>
                        <td><?= (int) $h['year'] ?></td>
                        <td><?= e($h['league_name']) ?></td>
                        <td><?= e($h['team_name']) ?> <span class="text-muted small"><?= e($h['owner_name']) ?></span></td>
                        <td class="text-end"><?= (int) $h['scored_rounds'] ?></td>
                        <td class="text-end fw-semibold"><?= number_format((float) $h['total_points'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$teamHistory): ?>
                    <tr><td colspan="5" class="text-muted">Time não encontrado em nenhuma temporada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php $anyData = false; foreach ($leagues as $league): ?>
    <?php
    $lid = (int) $league['id'];
    $ranking = Score::overallRanking($lid, $seasonId);
    if (!$ranking) {
        continue;
    }
    $anyData = true;
    $byRound = Score::pointsByTeamAndRound($lid, $seasonId);
    $roundNumbers = Score::roundNumbersWithData($lid, $seasonId);
    sort($roundNumbers);
    ?>
    <div class="panel mb-4">
        <div class="panel-head">
            <h3><?= e($league['name']) ?></h3>
            <span class="chip ms-auto"><?= count($ranking) ?> times · <?= count($roundNumbers) ?> rodadas</span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Time</th>
                    <th class="text-end">Total</th>
                    <?php foreach ($roundNumbers as $n): ?><th class="text-end">R<?= $n ?></th><?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($ranking as $i => $row): ?>
                    <?php $tid = (int) $row['official_league_team_id']; ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="text-nowrap">
                            <a href="?id=<?= $seasonId ?>&time=<?= (int) $row['cartola_team_id'] ?>" class="text-decoration-none fw-semibold"><?= e($row['team_name']) ?></a>
                            <?php if (!empty($row['registrant_name'])): ?><div class="text-muted small"><?= e($row['registrant_name']) ?></div><?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold"><?= number_format((float) $row['total_points'], 2, ',', '.') ?></td>
                        <?php foreach ($roundNumbers as $n): ?>
                            <td class="text-end text-nowrap">
                                <?= isset($byRound[$tid][$n]) ? number_format($byRound[$tid][$n], 2, ',', '.') : '–' ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<?php if (!$anyData): ?>
    <div class="alert alert-info">Ainda não há pontuações registradas na temporada <?= (int) $season['year'] ?>.</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
