<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

\Cartoleirao\Models\Season::guardPublicPage(false);

use Cartoleirao\Models\BonusRound;
use Cartoleirao\Models\League;
use Cartoleirao\Models\OfficialLeagueTeam;
use Cartoleirao\Models\Round;
use Cartoleirao\Models\Score;

/**
 * Builds the WhatsApp share text: Cartola team name (source of truth is the
 * official league) + score.
 */
function buildRankingShareText(string $title, string $leagueName, array $ranking, string $pointsField): string
{
    $lines = ["🏆 *{$title}*", $leagueName, ''];

    foreach ($ranking as $i => $r) {
        $position = $i + 1;
        $prefix = match ($position) {
            1 => '🥇',
            2 => '🥈',
            3 => '🥉',
            default => "{$position}º",
        };
        $teamName = $r['team_name'] ?: ('Time ' . $r['cartola_team_id']);
        $points = number_format((float) ($r[$pointsField] ?? 0), 2, ',', '.');
        $lines[] = "{$prefix} {$teamName} — {$points} pts";
    }

    $lines[] = '';
    $lines[] = '⚽ Cartoleirão';

    return implode("\n", $lines);
}

/** Share text in the "results table" format (P/V/E/D/PC), for round-robin leagues. */
function buildStandingsShareText(string $leagueName, array $standings): string
{
    $lines = ["🏆 *Tabela de Resultados*", $leagueName, ''];

    foreach ($standings as $t) {
        $position = (int) ($t['standing_position'] ?? 0);
        $prefix = match ($position) {
            1 => '🥇',
            2 => '🥈',
            3 => '🥉',
            default => $position > 0 ? "{$position}º" : '—',
        };
        $teamName = $t['team_name'] ?: ('Time ' . $t['cartola_team_id']);
        $cartolaPoints = number_format((float) ($t['standing_cartola_points'] ?? 0), 2, ',', '.');
        $lines[] = "{$prefix} {$teamName} — {$t['standing_points']} pts (V{$t['standing_wins']} E{$t['standing_draws']} D{$t['standing_losses']}) — {$cartolaPoints} PC";
    }

    $lines[] = '';
    $lines[] = '⚽ Cartoleirão';

    return implode("\n", $lines);
}

$leagues = League::all();
$leagueId = isset($_GET['liga_id']) ? (int) $_GET['liga_id'] : ($leagues[0]['id'] ?? 0);
$mode = ($_GET['modo'] ?? 'geral') === 'rodada' ? 'rodada' : 'geral';

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
if ($currentLeague && $currentLeague['type'] === 'round_robin') {
    $snapshot = OfficialLeagueTeam::byLeagueOrderedByStanding($leagueId);
    $isRoundRobin = !empty($snapshot) && $snapshot[0]['standing_position'] !== null;
}

$pageTitle = 'Classificação';
require __DIR__ . '/includes/header.php';

// ==========================================================================
// ROUND-ROBIN LEAGUE: "Tabela de Resultados" layout (same as Cartola)
// ==========================================================================
if ($isRoundRobin):
    $standings = OfficialLeagueTeam::byLeagueOrderedByStanding($leagueId);
    $standingsShareText = !empty($standings) ? buildStandingsShareText($currentLeague['name'], $standings) : null;
    ?>

    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <h1 class="tabela-pc-titulo mb-0">Tabela de Resultados</h1>
            <?php if ($standingsShareText): ?>
                <button type="button" class="btn btn-whatsapp"
                        data-compartilhar-imagem="#captura-tabela-pc"
                        data-titulo="Tabela de Resultados — <?= e($currentLeague['name']) ?>"
                        data-texto="<?= e($standingsShareText) ?>"
                        data-arquivo="tabela-resultados-<?= (int) $currentLeague['id'] ?>">
                    <i class="fa-brands fa-whatsapp"></i> Compartilhar tabela
                </button>
            <?php endif; ?>
        </div>

        <div class="mb-3">
            <form method="get">
                <select name="liga_id" class="form-select w-auto" onchange="this.form.submit()">
                    <?php foreach ($leagues as $l): ?>
                        <option value="<?= (int) $l['id'] ?>" <?= $leagueId === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if (empty($standings) || $standings[0]['standing_position'] === null): ?>
            <p class="text-center text-muted">
                Ainda não há tabela para essa liga.
            </p>
        <?php else: ?>
            <div id="captura-tabela-pc" class="captura-card">
                <div class="captura-card-header">
                    <span class="captura-card-trofeu">🏆</span>
                    <div>
                        <div class="captura-card-titulo">Tabela de Resultados</div>
                        <div class="captura-card-liga"><?= e($currentLeague['name']) ?></div>
                    </div>
                </div>

            <div class="tabela-pc-card">
                <div class="tabela-pc-header-row">
                    <div class="tabela-pc-col-time">TIMES</div>
                    <div class="tabela-pc-col-stat">P</div>
                    <div class="tabela-pc-col-stat">V</div>
                    <div class="tabela-pc-col-stat">E</div>
                    <div class="tabela-pc-col-stat">D</div>
                    <div class="tabela-pc-col-stat">PC</div>
                </div>

                <?php foreach ($standings as $t): ?>
                    <?php if ($t['standing_position'] === null) continue; ?>
                    <?php $change = (int) $t['standing_change']; ?>
                    <?php $podiumClass = ['ouro', 'prata', 'bronze'][((int) $t['standing_position']) - 1] ?? ''; ?>
                    <div class="tabela-pc-row <?= $podiumClass ? 'tabela-pc-row-' . $podiumClass : '' ?>">
                        <div class="tabela-pc-variacao">
                            <?php if ($change > 0): ?>
                                <i class="fa-solid fa-caret-up variacao-up"></i><span class="variacao-up small"><?= $change ?></span>
                            <?php elseif ($change < 0): ?>
                                <i class="fa-solid fa-caret-down variacao-down"></i><span class="variacao-down small"><?= abs($change) ?></span>
                            <?php else: ?>
                                <span class="tabela-pc-sem-variacao">▪</span>
                            <?php endif; ?>
                        </div>
                        <div class="tabela-pc-posicao">
                            <span class="tabela-pc-posicao-badge <?= $podiumClass ? 'is-' . $podiumClass : '' ?>"><?= (int) $t['standing_position'] ?>º</span>
                        </div>
                        <div class="tabela-pc-col-time d-flex align-items-center">
                            <?php if (!empty($t['crest_url'])): ?>
                                <img src="<?= e($t['crest_url']) ?>" class="tabela-pc-escudo" alt="">
                            <?php endif; ?>
                            <div>
                                <div class="tabela-pc-nome-time">
                                    <?= e($t['team_name'] ?: ('Time ' . $t['cartola_team_id'])) ?>
                                    <?php if (!empty($t['is_subscriber'])): ?>
                                        <span class="pc-badge-pro">PRO</span>
                                    <?php endif; ?>
                                </div>
                                <div class="tabela-pc-dono">
                                    <?= e($t['owner_name'] ?? '') ?>
                                    <?php if (!empty($t['registrant_id'])): ?>
                                        <?php if ($t['registrant_paid']): ?>
                                            <span class="badge badge-pago">pago</span>
                                        <?php else: ?>
                                            <span class="badge badge-pendente">pendente</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="tabela-pc-col-stat"><?= (int) $t['standing_points'] ?></div>
                        <div class="tabela-pc-col-stat"><?= (int) $t['standing_wins'] ?></div>
                        <div class="tabela-pc-col-stat"><?= (int) $t['standing_draws'] ?></div>
                        <div class="tabela-pc-col-stat"><?= (int) $t['standing_losses'] ?></div>
                        <div class="tabela-pc-col-stat"><?= number_format((float) $t['standing_cartola_points'], 2, ',', '.') ?></div>
                    </div>
                <?php endforeach; ?>

                <div class="tabela-pc-legenda">
                    <strong>P</strong> - Pontos, <strong>V</strong> - Vitórias, <strong>E</strong> - Empates,
                    <strong>D</strong> - Derrotas, <strong>PC</strong> - Pontuação no Cartola
                </div>
            </div>
            </div>
        <?php endif; ?>
    </div>

<?php
// ==========================================================================
// CLASSIC LEAGUE: generic layout (overall / per round)
// ==========================================================================
else:
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

    $ranking = [];
    $previousPositionMap = [];

    if ($leagueId) {
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
    $currentBonusRound = null;
    if ($leagueId && $mode === 'rodada' && $selectedRound) {
        $currentBonusRound = BonusRound::forLeagueAndRound($leagueId, (int) $selectedRound['number']);
    }

    $roundShareText = null;
    if ($currentLeague && $selectedRound) {
        $roundShareRanking = ($mode === 'rodada') ? $ranking : Score::rankingForRound($leagueId, (int) $selectedRound['id']);
        if (!empty($roundShareRanking)) {
            $roundShareText = buildRankingShareText(
                "Classificação da Rodada {$selectedRound['number']}",
                $currentLeague['name'],
                $roundShareRanking,
                'points'
            );
        }
    }

    $overallShareText = null;
    if ($currentLeague) {
        $overallShareRanking = ($mode === 'geral') ? $ranking : Score::overallRanking($leagueId);
        if (!empty($overallShareRanking)) {
            $overallShareText = buildRankingShareText(
                'Classificação Geral',
                $currentLeague['name'],
                $overallShareRanking,
                'total_points'
            );
        }
    }
    ?>

    <div class="container py-5">
        <h2 class="page-title justify-content-center mb-4"><i class="fa-solid fa-ranking-star"></i> Classificação</h2>

        <form method="get" class="row g-2 justify-content-center mb-3">
            <div class="col-auto">
                <select name="liga_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($leagues as $league): ?>
                        <option value="<?= (int) $league['id'] ?>" <?= $leagueId === (int) $league['id'] ? 'selected' : '' ?>>
                            <?= e($league['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="modo" class="form-select" onchange="this.form.submit()">
                    <option value="geral" <?= $mode === 'geral' ? 'selected' : '' ?>>Geral (acumulado)</option>
                    <option value="rodada" <?= $mode === 'rodada' ? 'selected' : '' ?>>Por rodada</option>
                </select>
            </div>
            <?php if ($mode === 'rodada' && !empty($rounds)): ?>
                <div class="col-auto">
                    <select name="rodada" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($rounds as $r): ?>
                            <option value="<?= (int) $r['number'] ?>" <?= $selectedRound && (int) $selectedRound['number'] === (int) $r['number'] ? 'selected' : '' ?>>
                                Rodada <?= (int) $r['number'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </form>

        <?php
        // The share button always captures whatever table is actually
        // visible on screen ("geral" or "rodada" mode), so it uses the text
        // matching the current mode.
        $currentShareText = $mode === 'rodada' ? $roundShareText : $overallShareText;
        $currentShareTitle = $mode === 'rodada' && $selectedRound
            ? "Classificação da Rodada {$selectedRound['number']} — {$currentLeague['name']}"
            : "Classificação Geral — " . ($currentLeague['name'] ?? '');
        $currentShareLabel = $mode === 'rodada' && $selectedRound
            ? 'Compartilhar rodada ' . (int) $selectedRound['number']
            : 'Compartilhar geral';
        ?>
        <div class="d-flex justify-content-center gap-2 flex-wrap mb-4">
            <?php if ($currentShareText): ?>
                <button type="button" class="btn btn-whatsapp btn-sm"
                        data-compartilhar-imagem="#captura-classificacao"
                        data-titulo="<?= e($currentShareTitle) ?>"
                        data-texto="<?= e($currentShareText) ?>"
                        data-arquivo="classificacao-<?= (int) $leagueId ?>-<?= $mode ?>">
                    <i class="fa-brands fa-whatsapp"></i> <?= e($currentShareLabel) ?>
                </button>
            <?php endif; ?>
        </div>

        <?php if ($currentBonusRound): ?>
            <div class="faixa-rodada-bonus mb-4">
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
            <p class="text-center text-muted">
                Ainda não há times/pontuação cadastrados pra essa liga.
            </p>
        <?php elseif ($mode === 'rodada' && empty($rounds)): ?>
            <div class="alert alert-info text-center">Ainda não há rodadas sincronizadas.</div>
        <?php elseif (empty($ranking)): ?>
            <p class="text-center text-muted">Ainda não há pontuações registradas para essa rodada.</p>
        <?php else: ?>
            <div id="captura-classificacao" class="captura-card">
                <div class="captura-card-header">
                    <span class="captura-card-trofeu">🏆</span>
                    <div>
                        <div class="captura-card-titulo">
                            <?= $mode === 'rodada' && $selectedRound ? 'Classificação da Rodada ' . (int) $selectedRound['number'] : 'Classificação Geral' ?>
                        </div>
                        <div class="captura-card-liga"><?= e($currentLeague['name'] ?? '') ?></div>
                    </div>
                </div>

            <div class="ranking-flex-card">
                <div class="ranking-flex-header-row">
                    <div class="ranking-flex-variacao">Var.</div>
                    <div class="ranking-flex-pos">#</div>
                    <div class="ranking-flex-time">Time no Cartola</div>
                    <div class="ranking-flex-pontos"><?= $mode === 'rodada' ? 'Pontos na rodada' : 'Pontos (total)' ?></div>
                    <?php if ($mode === 'geral'): ?>
                        <div class="ranking-flex-rodadas">Rodadas</div>
                    <?php endif; ?>
                </div>

                <?php foreach ($ranking as $i => $r): ?>
                    <?php
                    $currentPosition = $i + 1;
                    $change = Score::positionChange($previousPositionMap, (int) $r['official_league_team_id'], $currentPosition);
                    $isBonusRoundChampion = $currentBonusRound && $currentPosition === 1;
                    $topClass = $currentPosition <= 8 ? 'top-' . $currentPosition : '';
                    ?>
                    <div class="ranking-flex-row <?= $topClass ?> <?= $isBonusRoundChampion ? 'linha-campeao-bonus' : '' ?>">
                        <div class="ranking-flex-variacao">
                            <?php if ($change === null): ?>
                                <span class="variacao-same" title="Sem dado anterior pra comparar">novo</span>
                            <?php elseif ($change > 0): ?>
                                <i class="fa-solid fa-caret-up variacao-up"></i><span class="variacao-up small"><?= $change ?></span>
                            <?php elseif ($change < 0): ?>
                                <i class="fa-solid fa-caret-down variacao-down"></i><span class="variacao-down small"><?= abs($change) ?></span>
                            <?php else: ?>
                                <span class="tabela-pc-sem-variacao">▪</span>
                            <?php endif; ?>
                        </div>
                        <div class="ranking-flex-pos">
                            <span class="ranking-posicao-badge <?= $topClass ? 'is-' . $topClass : '' ?>"><?= $currentPosition ?></span>
                        </div>
                        <div class="ranking-flex-time d-flex align-items-center">
                            <?php if (!empty($r['crest_url'])): ?>
                                <img src="<?= e($r['crest_url']) ?>" class="ranking-flex-escudo" alt="">
                            <?php endif; ?>
                            <div>
                                <div class="ranking-flex-nome-time">
                                    <?= e($r['team_name'] ?: ('Time ' . $r['cartola_team_id'])) ?>
                                    <?php if ($isBonusRoundChampion): ?>
                                        <span class="selo-campeao-bonus"><i class="fa-solid fa-trophy"></i> Campeão da Rodada Bônus</span>
                                    <?php endif; ?>
                                </div>
                                <div class="ranking-flex-sub">
                                    <?php if (!empty($r['registrant_name'])): ?>
                                        <?= e($r['registrant_name']) ?>
                                        <?php if ($r['paid']): ?>
                                            <span class="badge badge-pago">pago</span>
                                        <?php else: ?>
                                            <span class="badge badge-pendente">pendente</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span>— sem inscrição —</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="ranking-flex-pontos">
                            <?= number_format((float) ($r['points'] ?? $r['total_points']), 2, ',', '.') ?>
                        </div>
                        <?php if ($mode === 'geral'): ?>
                            <div class="ranking-flex-rodadas"><?= (int) $r['scored_rounds'] ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
