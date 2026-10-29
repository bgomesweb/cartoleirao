<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

\Cartoleirao\Models\Season::guardPublicPage(false);

use Cartoleirao\Models\MarketStatus;
use Cartoleirao\Models\OfficialLeagueTeam;
use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\LiveRoundService;

$team = OfficialLeagueTeam::findById((int) ($_GET['id'] ?? 0));
if (!$team) {
    http_response_code(404);
}

$market = MarketStatus::current();
$liveRound = $market && $market['status_code'] === 2 ? $market['current_round'] : null;

$partial = null;
if ($team && $liveRound) {
    $cartolaId = (int) $team['cartola_team_id'];
    $partial = (new LiveRoundService(new CartolaApiClient()))->partials([$cartolaId], (int) $liveRound)[$cartolaId] ?? null;
}

$teamName = $team ? ($team['team_name'] ?: 'Time ' . $team['cartola_team_id']) : 'Time não encontrado';
$president = $partial['president'] ?? ($team['owner_name'] ?? null);

$crest = $team['crest_url'] ?? ($partial['crest'] ?? null);

function playerRow(array $p): string
{
    $classes = 'rk-row player-row'
        . ($p['sub'] === 'out' ? ' sub-out' : '')
        . ($p['sub'] === 'in' ? ' sub-in' : '')
        . (!$p['played'] && $p['sub'] === null ? ' not-played' : '');
    $active = $p['played'] && $p['sub'] !== 'out';

    ob_start(); ?>
    <div class="<?= $classes ?>">
        <?php if (!empty($p['photo'])): ?>
            <img src="<?= e($p['photo']) ?>" class="player-photo" alt="" loading="lazy">
        <?php else: ?>
            <span class="crest-fallback player-photo"><i class="fa-solid fa-user"></i></span>
        <?php endif; ?>
        <div class="min-w-0">
            <div class="rk-name">
                <?= e($p['name']) ?>
                <?php if ($p['captain']): ?><span class="captain-badge" title="Capitão (1,5x)">C</span><?php endif; ?>
                <?php if ($p['luxury']): ?><span class="captain-badge luxury-badge" title="Reserva de luxo (1,5x se entrar)">L</span><?php endif; ?>
                <?php if ($p['sub'] === 'out'): ?><span class="sub-tag sub-tag-out"><i class="fa-solid fa-arrow-right-from-bracket"></i> saiu · entrou <?= e($p['sub_with']) ?></span><?php endif; ?>
                <?php if ($p['sub'] === 'in'): ?><span class="sub-tag sub-tag-in"><i class="fa-solid fa-arrow-right-to-bracket"></i> entrou no lugar de <?= e($p['sub_with']) ?></span><?php endif; ?>
            </div>
            <div class="rk-sub">
                <?php if (!empty($p['club']['crest'])): ?><img src="<?= e($p['club']['crest']) ?>" class="player-club" alt=""><?php endif; ?>
                <?= e($p['position']) ?> · <?= e($p['club']['name']) ?>
            </div>
        </div>
        <div class="rk-points">
            <?= $active ? number_format($p['points'], 2, ',', '.') : '—' ?>
            <small><?= $active ? 'pts' : ($p['sub'] === 'out' ? 'substituído' : 'não jogou') ?></small>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

$pageTitle = $teamName;
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
                <?php if ($president): ?><div class="team-head-sub">Presidente: <?= e($president) ?></div><?php endif; ?>
            </div>
            <?php if ($partial): ?>
                <div class="rk-points">
                    <?= number_format($partial['points'], 2, ',', '.') ?>
                    <small>parcial</small>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$liveRound): ?>
            <div class="empty-state"><i class="fa-solid fa-lock-open"></i>A escalação aparece aqui quando o mercado fechar e a rodada começar.</div>
        <?php elseif (!$partial): ?>
            <div class="empty-state"><i class="fa-solid fa-hourglass-half"></i>Não foi possível carregar a escalação agora. Tente de novo em instantes.</div>
        <?php else: ?>
            <div class="live-note"><span class="live-dot"></span> Rodada <?= (int) $liveRound ?> em tempo real · atualiza sozinha · capitão vale 1,5x</div>
            <div class="rk-list">
                <?php foreach ($partial['players'] as $p): ?>
                    <?= playerRow($p) ?>
                <?php endforeach; ?>
            </div>

            <?php if ($partial['reserves']): ?>
                <h2 class="bench-title">Banco de reservas</h2>
                <div class="rk-list">
                    <?php foreach ($partial['reserves'] as $p): ?>
                        <?= playerRow($p) ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <script>
            // Real-time partial: reloads every 30s while the tab is visible.
            setInterval(function () { if (!document.hidden) { location.reload(); } }, 30000);
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
