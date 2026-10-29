<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

\Cartoleirao\Models\Season::guardPublicPage(false);

use Cartoleirao\Models\BonusRound;
use Cartoleirao\Models\League;
use Cartoleirao\Models\MarketStatus;
use Cartoleirao\Models\OfficialLeagueTeam;
use Cartoleirao\Models\Round;
use Cartoleirao\Models\Score;
use Cartoleirao\Services\CartolaApiClient;
use Cartoleirao\Services\LiveRoundService;

/** URL of this page keeping the current filters, overriding the given ones. */
function rankingUrl(array $overrides = []): string
{
    $params = array_merge(array_intersect_key($_GET, array_flip(['liga_id', 'modo', 'rodada'])), $overrides);

    return 'classificacao.php' . ($params ? '?' . http_build_query($params) : '');
}

function teamDisplayName(array $team): string
{
    return $team['team_name'] ?: ('Time ' . $team['cartola_team_id']);
}

function crestImg(?string $url, string $class): string
{
    if (!empty($url)) {
        return '<img src="' . e($url) . '" class="' . $class . '" alt="" loading="lazy">';
    }

    return '<span class="crest-fallback ' . $class . '"><i class="fa-solid fa-shield"></i></span>';
}

function variationHtml(?int $change): string
{
    if ($change === null) {
        return '<span class="rk-var variacao-same" title="Sem dado anterior pra comparar">novo</span>';
    }
    if ($change > 0) {
        return '<span class="rk-var variacao-up"><i class="fa-solid fa-caret-up"></i>' . $change . '</span>';
    }
    if ($change < 0) {
        return '<span class="rk-var variacao-down"><i class="fa-solid fa-caret-down"></i>' . abs($change) . '</span>';
    }

    return '<span class="rk-var variacao-same">▪</span>';
}

function paidBadge(array $row): string
{
    if (empty($row['registrant_name'])) {
        return '';
    }

    return !empty($row['paid'])
        ? '<span class="badge-pago">pago</span>'
        : '<span class="badge-pendente">pendente</span>';
}

$leagues = League::all();
$leagueId = isset($_GET['liga_id']) ? (int) $_GET['liga_id'] : ($leagues[0]['id'] ?? 0);

// With the market closed the round is being played: the default view is the live partial score.
$market = MarketStatus::current();
$liveRound = $market && $market['status_code'] === 2 ? $market['current_round'] : null;
$requestedMode = $_GET['modo'] ?? ($liveRound ? 'ao-vivo' : 'geral');
$mode = $requestedMode === 'rodada' ? 'rodada' : ($requestedMode === 'ao-vivo' && $liveRound ? 'ao-vivo' : 'geral');

$currentLeague = null;
foreach ($leagues as $league) {
    if ((int) $league['id'] === $leagueId) {
        $currentLeague = $league;
        break;
    }
}

// The official "Tabela de Resultados" (W/D/L) needs a standings snapshot; without
// it (rosters are now built from linked registrants) falls back to the points ranking.
$isRoundRobin = false;
$standings = [];
if ($currentLeague && $currentLeague['type'] === 'round_robin') {
    $standings = OfficialLeagueTeam::byLeagueOrderedByStanding($leagueId);
    $isRoundRobin = !empty($standings) && $standings[0]['standing_position'] !== null;
}

$rounds = [];
$ranking = [];
$selectedRound = null;
$previousPositionMap = [];
$currentBonusRound = null;

if (!$isRoundRobin) {
    $rounds = Round::allOrdered();
    $numbersWithData = $leagueId ? Score::roundNumbersWithData($leagueId) : [];

    $selectedRoundNumber = isset($_GET['rodada']) ? (int) $_GET['rodada'] : null;
    if ($selectedRoundNumber) {
        $selectedRound = Round::findByNumber($selectedRoundNumber);
    } elseif (!empty($numbersWithData)) {
        // default: the latest round that HAS scores for this league (avoids
        // landing on a future/current round that already exists in the
        // `rounds` table — because of the games — but has no score yet)
        $selectedRound = Round::findByNumber($numbersWithData[0]);
    } else {
        $selectedRound = Round::mostRecent();
    }

    if ($leagueId && $mode === 'ao-vivo') {
        // Total so far (rounds before the one being played) + the partial of the round in progress.
        $baseRanking = Score::overallRankingUpToRound($leagueId, (int) $liveRound - 1);
        $previousPositionMap = Score::positionMap($baseRanking);
        $partials = (new LiveRoundService(new CartolaApiClient()))
            ->partials(array_map(fn($t) => (int) $t['cartola_team_id'], $baseRanking), (int) $liveRound);
        foreach ($baseRanking as $t) {
            $partial = $partials[(int) $t['cartola_team_id']] ?? null;
            $t['round_points'] = $partial['points'] ?? null;
            $t['points'] = (float) $t['total_points'] + (float) ($partial['points'] ?? 0);
            if ($partial && empty($t['crest_url'])) {
                $t['crest_url'] = $partial['crest'];
            }
            $ranking[] = $t;
        }
        usort($ranking, fn($a, $b) => $b['points'] <=> $a['points']);
    } elseif ($leagueId) {
        if ($mode === 'rodada' && $selectedRound) {
            $ranking = Score::rankingForRound($leagueId, (int) $selectedRound['id']);

            $previousNumber = null;
            foreach ($numbersWithData as $n) {
                if ($n < (int) $selectedRound['number']) {
                    $previousNumber = $n;
                    break;
                }
            }
            if ($previousNumber) {
                $previousRound = Round::findByNumber($previousNumber);
                if ($previousRound) {
                    $previousPositionMap = Score::positionMap(Score::rankingForRound($leagueId, (int) $previousRound['id']));
                }
            }
        } else {
            $ranking = Score::overallRanking($leagueId);

            if (count($numbersWithData) >= 2) {
                $secondToLast = $numbersWithData[1];
                $previousPositionMap = Score::positionMap(Score::overallRankingUpToRound($leagueId, $secondToLast));
            }
        }
    }

    // Bonus round: if the selected round (in "per round" mode) is marked as
    // a bonus round for this league, the ranking's 1st place is highlighted
    // in gold as "bonus round champion".
    if ($leagueId && $mode === 'rodada' && $selectedRound) {
        $currentBonusRound = BonusRound::forLeagueAndRound($leagueId, (int) $selectedRound['number']);
    }

    // Previous / next round (the list is ordered from the newest to the oldest).
    $prevRoundNumber = $nextRoundNumber = null;
    if ($selectedRound) {
        foreach ($rounds as $idx => $r) {
            if ((int) $r['number'] === (int) $selectedRound['number']) {
                $nextRoundNumber = isset($rounds[$idx - 1]) ? (int) $rounds[$idx - 1]['number'] : null;
                $prevRoundNumber = isset($rounds[$idx + 1]) ? (int) $rounds[$idx + 1]['number'] : null;
                break;
            }
        }
    }
}

$pageTitle = 'Classificação';
require __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-head">
        <h1><?= $isRoundRobin ? 'Tabela de resultados' : 'Classificação' ?></h1>
        <p><?= e($currentLeague['name'] ?? '') ?></p>
    </div>

    <div class="controls">
        <?php if (count($leagues) > 1): ?>
            <div class="segmented" role="tablist" aria-label="Liga">
                <?php foreach ($leagues as $l): ?>
                    <a href="<?= e(rankingUrl(['liga_id' => (int) $l['id'], 'rodada' => null])) ?>"
                       class="<?= $leagueId === (int) $l['id'] ? 'active' : '' ?>"><?= e($l['name']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$isRoundRobin): ?>
            <div class="segmented" aria-label="Tipo de classificação">
                <?php if ($liveRound): ?>
                    <a href="<?= e(rankingUrl(['modo' => 'ao-vivo'])) ?>" class="<?= $mode === 'ao-vivo' ? 'active' : '' ?>"><i class="fa-solid fa-tower-broadcast"></i> Ao vivo</a>
                <?php endif; ?>
                <a href="<?= e(rankingUrl(['modo' => 'geral'])) ?>" class="<?= $mode === 'geral' ? 'active' : '' ?>">Geral</a>
                <a href="<?= e(rankingUrl(['modo' => 'rodada'])) ?>" class="<?= $mode === 'rodada' ? 'active' : '' ?>">Por rodada</a>
            </div>

            <?php if ($mode === 'rodada' && !empty($rounds)): ?>
                <form method="get" class="round-nav">
                    <input type="hidden" name="liga_id" value="<?= (int) $leagueId ?>">
                    <input type="hidden" name="modo" value="rodada">
                    <a class="btn-icon <?= $prevRoundNumber ? '' : 'disabled' ?>" aria-label="Rodada anterior"
                       href="<?= $prevRoundNumber ? e(rankingUrl(['modo' => 'rodada', 'rodada' => $prevRoundNumber])) : '#' ?>"><i class="fa-solid fa-chevron-left"></i></a>
                    <select name="rodada" class="form-select" aria-label="Rodada" onchange="this.form.submit()">
                        <?php foreach ($rounds as $r): ?>
                            <option value="<?= (int) $r['number'] ?>" <?= $selectedRound && (int) $selectedRound['number'] === (int) $r['number'] ? 'selected' : '' ?>>
                                Rodada <?= (int) $r['number'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <a class="btn-icon <?= $nextRoundNumber ? '' : 'disabled' ?>" aria-label="Próxima rodada"
                       href="<?= $nextRoundNumber ? e(rankingUrl(['modo' => 'rodada', 'rodada' => $nextRoundNumber])) : '#' ?>"><i class="fa-solid fa-chevron-right"></i></a>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (($isRoundRobin && !empty($standings)) || (!$isRoundRobin && !empty($ranking))): ?>
            <label class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="busca-time" class="form-control" placeholder="Buscar time ou participante" autocomplete="off">
            </label>
        <?php endif; ?>
    </div>

<?php if ($isRoundRobin): ?>
    <div class="table-card mb-4">
        <div class="table-scroll">
            <table class="st-table" id="tabela-busca">
                <thead>
                <tr>
                    <th class="team">Times</th>
                    <th title="Pontos">P</th>
                    <th title="Vitórias">V</th>
                    <th title="Empates">E</th>
                    <th title="Derrotas">D</th>
                    <th title="Pontuação no Cartola">PC</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($standings as $t): ?>
                    <?php
                    if ($t['standing_position'] === null) {
                        continue;
                    }
                    $position = (int) $t['standing_position'];
                    $change = (int) $t['standing_change'];
                    $busca = ($t['team_name'] ?? '') . ' ' . ($t['owner_name'] ?? '');
                    ?>
                    <tr class="<?= $position <= 3 ? 'top-' . $position : '' ?>" data-busca="<?= e($busca) ?>">
                        <td class="team">
                            <div class="st-team">
                                <span class="pos"><?= $position ?>º</span>
                                <?= crestImg($t['crest_url'] ?? null, '') ?>
                                <div>
                                    <div class="nm">
                                        <?= e(teamDisplayName($t)) ?>
                                        <?php if (!empty($t['is_subscriber'])): ?><span class="pc-badge-pro">PRO</span><?php endif; ?>
                                        <?php if ($change > 0): ?>
                                            <span class="rk-var variacao-up"><i class="fa-solid fa-caret-up"></i><?= $change ?></span>
                                        <?php elseif ($change < 0): ?>
                                            <span class="rk-var variacao-down"><i class="fa-solid fa-caret-down"></i><?= abs($change) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ow"><?= e($t['owner_name'] ?? '') ?> <?= paidBadge(['registrant_name' => $t['registrant_id'] ?? null, 'paid' => $t['registrant_paid'] ?? 0]) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="pts"><?= (int) $t['standing_points'] ?></td>
                        <td><?= (int) $t['standing_wins'] ?></td>
                        <td><?= (int) $t['standing_draws'] ?></td>
                        <td><?= (int) $t['standing_losses'] ?></td>
                        <td><?= number_format((float) $t['standing_cartola_points'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="legend">
            <strong>P</strong> pontos · <strong>V</strong> vitórias · <strong>E</strong> empates ·
            <strong>D</strong> derrotas · <strong>PC</strong> pontuação no Cartola
        </div>
    </div>
    <div id="busca-vazia" class="empty-state d-none"><i class="fa-regular fa-face-frown"></i>Nenhum time encontrado.</div>

<?php else: ?>

    <?php if ($currentBonusRound): ?>
        <div class="faixa-rodada-bonus mb-3">
            <span class="faixa-rodada-bonus-trofeu">🏆</span>
            <div>
                <div class="faixa-rodada-bonus-titulo">Rodada <?= (int) $currentBonusRound['round_number'] ?> é Bônus!</div>
                <div class="faixa-rodada-bonus-sub">
                    <?php if (!empty($currentBonusRound['prize_amount'])): ?>
                        O 1º colocado dessa rodada leva mais
                        <strong>R$ <?= number_format((float) $currentBonusRound['prize_amount'], 2, ',', '.') ?></strong>
                    <?php else: ?>
                        O 1º colocado dessa rodada leva um prêmio extra
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($ranking) && Score::roundNumbersWithData($leagueId) === []): ?>
        <div class="empty-state"><i class="fa-solid fa-hourglass-half"></i>Ainda não há times/pontuação cadastrados pra essa liga.</div>
    <?php elseif ($mode === 'rodada' && empty($rounds)): ?>
        <div class="empty-state"><i class="fa-solid fa-calendar-xmark"></i>Ainda não há rodadas sincronizadas.</div>
    <?php elseif (empty($ranking)): ?>
        <div class="empty-state"><i class="fa-solid fa-hourglass-half"></i>Ainda não há pontuações registradas para essa rodada.</div>
    <?php else: ?>
        <?php
        $isLive = $mode === 'ao-vivo';
        $pointsLabel = $isLive ? 'total' : ($mode === 'rodada' ? 'pts na rodada' : 'pts');
        $roundGain = fn($r) => ($r['round_points'] ?? null) === null ? '' : '+' . number_format((float) $r['round_points'], 2, ',', '.') . ' na rodada';
        $fmtPoints = fn($r) => ($r['points'] ?? $r['total_points'] ?? null) === null ? '—' : number_format((float) ($r['points'] ?? $r['total_points']), 2, ',', '.');
        $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
        ?>

        <?php if ($isLive): ?>
            <div class="live-note"><span class="live-dot"></span> Total + parcial da rodada <?= (int) $liveRound ?> em tempo real · setas comparam com a classificação geral antes da rodada</div>
        <?php endif; ?>

        <?php if (count($ranking) >= 3): ?>
            <div class="podium" aria-label="Pódio">
                <?php foreach (array_slice($ranking, 0, 3) as $i => $r): ?>
                    <?php $pos = $i + 1; ?>
                    <div class="podium-card p<?= $pos ?>">
                        <div class="podium-medal"><?= $medals[$pos] ?></div>
                        <?= crestImg($r['crest_url'] ?? null, '') ?>
                        <div class="podium-name"><?= e(teamDisplayName($r)) ?></div>
                        <?php if (!empty($r['registrant_name'])): ?><div class="podium-owner"><?= e($r['registrant_name']) ?></div><?php endif; ?>
                        <div class="podium-points"><?= $fmtPoints($r) ?> <small><?= $pointsLabel ?></small>
                        <?php if ($isLive): ?><span class="round-gain"><?= $roundGain($r) ?></span><?php endif; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="rk-list" id="rk-list">
            <?php foreach ($ranking as $i => $r): ?>
                <?php
                $currentPosition = $i + 1;
                $change = Score::positionChange($previousPositionMap, (int) $r['official_league_team_id'], $currentPosition);
                $isBonusRoundChampion = $currentBonusRound && $currentPosition === 1;
                $classes = trim(
                    ($currentPosition <= 3 ? 'top-' . $currentPosition : '')
                    . ($isBonusRoundChampion ? ' is-bonus' : '')
                );
                $busca = teamDisplayName($r) . ' ' . ($r['registrant_name'] ?? '');
                ?>
                <div class="rk-row <?= $classes ?>" data-busca="<?= e($busca) ?>">
                    <div class="rk-pos">
                        <b><?= $currentPosition ?></b>
                        <?= variationHtml($change) ?>
                    </div>
                    <?= crestImg($r['crest_url'] ?? null, 'rk-crest') ?>
                    <div class="min-w-0">
                        <div class="rk-name">
                            <?php if ($isLive): ?>
                                <a href="time.php?id=<?= (int) $r['official_league_team_id'] ?>" class="rk-link"><?= e(teamDisplayName($r)) ?></a>
                            <?php else: ?>
                                <?= e(teamDisplayName($r)) ?>
                            <?php endif; ?>
                            <?php if ($isBonusRoundChampion): ?>
                                <span class="selo-campeao-bonus"><i class="fa-solid fa-trophy"></i> Campeão da rodada bônus</span>
                            <?php endif; ?>
                        </div>
                        <div class="rk-sub">
                            <?php if (!empty($r['registrant_name'])): ?>
                                <?= e($r['registrant_name']) ?> <?= paidBadge($r) ?>
                            <?php else: ?>
                                <span>sem inscrição</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="rk-points">
                        <?= $fmtPoints($r) ?>
                        <?php if ($isLive): ?>
                            <small>total</small>
                            <span class="round-gain"><?= $roundGain($r) ?></span>
                        <?php else: ?>
                            <small><?= $mode === 'geral' ? (int) $r['scored_rounds'] . ' rodadas' : 'pts' ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div id="busca-vazia" class="empty-state d-none mt-3"><i class="fa-regular fa-face-frown"></i>Nenhum time encontrado.</div>
    <?php endif; ?>
<?php endif; ?>
</div>

<?php if (($mode ?? '') === 'ao-vivo'): ?>
<script>
// Real-time partial: reloads every 30s (not while the tab is hidden or a search is typed).
setInterval(function () {
    var q = document.getElementById('busca-time');
    if (!document.hidden && !(q && q.value)) { location.reload(); }
}, 30000);
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
