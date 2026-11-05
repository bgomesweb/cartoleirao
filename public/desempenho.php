<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

\Cartoleirao\Models\Season::guardPublicPage(false);

use Cartoleirao\Models\MarketStatus;
use Cartoleirao\Models\OfficialLeagueTeam;
use Cartoleirao\Models\Score;
use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\PerformanceService;

$team = OfficialLeagueTeam::findById((int) ($_GET['id'] ?? 0));
if (!$team) {
    http_response_code(404);
}

$teamName = $team ? ($team['team_name'] ?: 'Time ' . $team['cartola_team_id']) : 'Time não encontrado';

$perf = null;
if ($team) {
    $roundNumbers = Score::roundNumbersForTeam((int) $team['id']);
    if ($roundNumbers) {
        $perf = (new PerformanceService(new CartolaApiClient()))->forTeam((int) $team['cartola_team_id'], $roundNumbers);
    }
}
$rounds = $perf['rounds'] ?? [];
$crest = $team['crest_url'] ?? ($perf['crest'] ?? null);

$fmt = fn(float $v): string => number_format($v, 2, ',', '.');

$total = array_sum(array_column($rounds, 'points'));
$best = $rounds ? array_reduce($rounds, fn($c, $r) => $c === null || $r['points'] > $c['points'] ? $r : $c) : null;
$worst = $rounds ? array_reduce($rounds, fn($c, $r) => $c === null || $r['points'] < $c['points'] ? $r : $c) : null;
$gain = array_sum(array_map(fn($r) => (float) $r['variation'], $rounds));
$lastPatrimony = $rounds ? end($rounds)['patrimony'] : null;

function playerRank(array $players, string $unit): string
{
    ob_start(); ?>
    <div class="rk-list">
        <?php foreach ($players as $i => $p): ?>
            <div class="rk-row <?= $i === 0 ? 'top-1' : '' ?>">
                <div class="rk-pos"><b><?= $i + 1 ?></b></div>
                <?php if (!empty($p['photo'])): ?>
                    <img src="<?= e($p['photo']) ?>" class="player-photo" alt="" loading="lazy">
                <?php else: ?>
                    <span class="crest-fallback player-photo"><i class="fa-solid fa-user"></i></span>
                <?php endif; ?>
                <div class="min-w-0"><div class="rk-name"><?= e($p['name']) ?></div></div>
                <div class="rk-points"><?= (int) $p['count'] ?> <small><?= $unit ?></small></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

$pageTitle = 'Desempenho — ' . $teamName;
$extraScripts = $rounds ? ['https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', 'assets/js/desempenho.js?v=' . (@filemtime(__DIR__ . '/assets/js/desempenho.js') ?: time())] : [];
require __DIR__ . '/includes/header.php';
?>

<div class="container">
    <a href="classificacao.php" class="back-link"><i class="fa-solid fa-chevron-left"></i> Classificação</a>

    <?php if (!$team): ?>
        <div class="empty-state"><i class="fa-regular fa-face-frown"></i>Time não encontrado.</div>
    <?php else: ?>
        <div class="team-head">
            <?php if (!empty($crest)): ?>
                <img src="<?= e($crest) ?>" class="team-head-crest" alt="">
            <?php else: ?>
                <span class="crest-fallback team-head-crest"><i class="fa-solid fa-shield"></i></span>
            <?php endif; ?>
            <div class="min-w-0">
                <h1><?= e($teamName) ?></h1>
                <div class="team-head-sub">Desempenho na temporada</div>
            </div>
        </div>

        <?php if (!$rounds): ?>
            <div class="empty-state"><i class="fa-solid fa-chart-line"></i>Ainda não há rodadas pontuadas para mostrar o desempenho.</div>
        <?php else: ?>
            <?php if ($perf['missing'] > 0): ?>
                <div class="live-note">Não foi possível carregar <?= (int) $perf['missing'] ?> rodada(s) agora; os dados abaixo estão incompletos.</div>
            <?php endif; ?>

            <div class="perf-stats">
                <div class="perf-stat"><small>Total</small><b><?= $fmt($total) ?></b></div>
                <div class="perf-stat"><small>Média por rodada</small><b><?= $fmt($total / count($rounds)) ?></b></div>
                <div class="perf-stat"><small>Melhor rodada</small><b><?= $fmt($best['points']) ?></b><span>Rodada <?= (int) $best['round'] ?></span></div>
                <div class="perf-stat"><small>Pior rodada</small><b><?= $fmt($worst['points']) ?></b><span>Rodada <?= (int) $worst['round'] ?></span></div>
                <div class="perf-stat"><small>Patrimônio</small><b>C$ <?= $lastPatrimony !== null ? $fmt((float) $lastPatrimony) : '—' ?></b>
                    <span class="<?= $gain >= 0 ? 'variacao-up' : 'variacao-down' ?>"><?= ($gain >= 0 ? '+' : '') . $fmt($gain) ?> na temporada</span></div>
            </div>

            <h2 class="bench-title">Pontos por rodada</h2>
            <div class="perf-chart"><canvas id="chart-pontos" aria-label="Pontos por rodada"></canvas></div>

            <h2 class="bench-title">Valorização por rodada</h2>
            <div class="perf-chart"><canvas id="chart-valorizacao" aria-label="Valorização por rodada"></canvas></div>

            <h2 class="bench-title">Jogadores mais escalados</h2>
            <?= playerRank($perf['most_picked'], 'rodadas') ?>

            <h2 class="bench-title">Jogadores que mais foram capitão</h2>
            <?= $perf['most_captain'] ? playerRank($perf['most_captain'], 'vezes') : '<div class="empty-state">Sem capitão registrado.</div>' ?>

            <script>window.PERF_ROUNDS = <?= json_encode($rounds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
