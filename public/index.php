<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

\Cartoleirao\Models\Season::guardPublicPage(false);

use Cartoleirao\Models\League;
use Cartoleirao\Models\MarketStatus;
use Cartoleirao\Models\OfficialLeagueTeam;
use Cartoleirao\Models\Season;

/**
 * Recognizes lines in the "1º Campeão: R$950,00" pattern inside the free-text
 * prize description, for whoever had already typed the prizes that way
 * before the structured fields existed. Returns [position => ['label' => ..., 'amount' => ...]].
 */
function parsePrizeDescription(string $text): array
{
    $result = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (preg_match('/^\s*(\d{1,2})\s*[ºo]\s*(.*?)\s*:\s*R\$\s*([\d.,]+)/ui', $line, $m)) {
            $position = (int) $m[1];
            $amount = (float) str_replace(',', '.', str_replace('.', '', $m[3]));
            if ($position >= 1 && $position <= 20 && $amount > 0) {
                $result[$position] = ['label' => trim($m[2]) ?: "{$position}º Colocado", 'amount' => $amount];
            }
        }
    }
    ksort($result);

    return $result;
}

$leagues = [];
$teamCounts = [];
$season = null;
$dbError = null;

try {
    $leagues = League::all();
    $season = Season::active();
    foreach ($leagues as $league) {
        $teamCounts[(int) $league['id']] = OfficialLeagueTeam::countByLeague((int) $league['id']);
    }
} catch (\Throwable $e) {
    $dbError = 'Não foi possível carregar os dados agora. Tente novamente em instantes.';
}

$market = MarketStatus::current();
$totalTeams = array_sum($teamCounts);

$pageTitle = 'Início';
require __DIR__ . '/includes/header.php';
?>

<section class="hero">
    <div class="container">
        <?php if ($dbError): ?>
            <div class="alert alert-warning"><?= e($dbError) ?></div>
        <?php endif; ?>
        <div class="hero-inner">
            <div class="flex-grow-1">
                <span class="eyebrow"><i class="fa-solid fa-trophy"></i> Temporada <?= (int) ($season['year'] ?? date('Y')) ?></span>
                <h1>Cartola, futebol e resenha ⚽🍻</h1>
                <p class="lead-text">
                    A galera brigando ponto a ponto no Cartola FC durante todo o Brasileirão Série A,
                    com ranking atualizado, zoeira garantida e prêmio pra quem terminar no topo.
                </p>
                <div class="hero-actions">
                    <a href="classificacao.php" class="btn btn-cartoleirao"><i class="fa-solid fa-ranking-star me-1"></i> Ver classificação</a>
                    <a href="jogos.php" class="btn btn-ghost"><i class="fa-solid fa-futbol me-1"></i> Jogos da rodada</a>
                </div>
                <div class="hero-stats">
                    <?php if ($totalTeams > 0): ?>
                        <div class="hero-stat"><strong><?= $totalTeams ?></strong><span>times na disputa</span></div>
                    <?php endif; ?>
                    <?php if (!empty($market['current_round'])): ?>
                        <div class="hero-stat"><strong><?= (int) $market['current_round'] ?></strong><span>rodada atual</span></div>
                    <?php endif; ?>
                    <?php if (count($leagues) > 0): ?>
                        <div class="hero-stat"><strong><?= count($leagues) ?></strong><span><?= count($leagues) === 1 ? 'liga' : 'ligas' ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
            <svg class="hero-ball d-none d-sm-block" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle cx="100" cy="100" r="95" fill="#ffffff"/>
                <g fill="#00165a">
                    <polygon points="100,55 122,71 113,97 87,97 78,71"/>
                    <polygon points="100,55 78,71 60,55 70,30 130,30 140,55 122,71"/>
                    <polygon points="87,97 113,97 121,122 100,140 79,122"/>
                    <polygon points="60,55 40,80 47,110 78,71"/>
                    <polygon points="140,55 160,80 153,110 122,71"/>
                    <polygon points="47,110 79,122 70,150 40,140"/>
                    <polygon points="153,110 121,122 130,150 160,140"/>
                </g>
                <circle cx="100" cy="100" r="95" fill="none" stroke="#00165a" stroke-width="4"/>
            </svg>
        </div>
    </div>
</section>

<section class="section pb-0">
    <div class="container">
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <a href="classificacao.php" class="shortcut">
                    <span class="ico"><i class="fa-solid fa-ranking-star"></i></span>
                    <div><strong>Classificação</strong><span>Geral e rodada a rodada</span></div>
                    <i class="fa-solid fa-chevron-right go"></i>
                </a>
            </div>
            <div class="col-12 col-md-6">
                <a href="jogos.php" class="shortcut">
                    <span class="ico" style="background: linear-gradient(135deg, var(--cartoleirao-verde), #12926a);"><i class="fa-solid fa-futbol"></i></span>
                    <div><strong>Jogos</strong><span>Partidas e placares da rodada</span></div>
                    <i class="fa-solid fa-chevron-right go"></i>
                </a>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="section-title">Premiação das ligas</h2>
        <div class="row g-4">
            <?php foreach ($leagues as $league): ?>
                <?php
                $structuredPrizes = [];
                foreach (range(1, 8) as $i) {
                    $amount = $league["prize_{$i}"] ?? null;
                    if ($amount !== null && (float) $amount > 0) {
                        $structuredPrizes[$i] = ['label' => "{$i}º colocado", 'amount' => (float) $amount];
                    }
                }

                // Fallback: if nobody has filled in the structured fields yet,
                // try to recognize the "Nº Label: R$amount" pattern from the
                // free-text field (so whatever was typed before keeps working
                // with the new look, without having to retype it in the admin panel).
                if (empty($structuredPrizes) && !empty($league['prize_description'])) {
                    $structuredPrizes = parsePrizeDescription($league['prize_description']);
                }

                $total = array_sum(array_column($structuredPrizes, 'amount'));
                $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                $classes = [1 => 'ouro', 2 => 'prata', 3 => 'bronze'];
                $teams = $teamCounts[(int) $league['id']] ?? 0;
                ?>
                <div class="col-12 col-md-6">
                    <article class="prize-card">
                        <div class="prize-head">
                            <span class="prize-icon">🏆</span>
                            <div>
                                <h3><?= e($league['name']) ?></h3>
                                <small><?= $league['type'] === 'classic' ? 'Liga clássica' : 'Pontos corridos' ?></small>
                            </div>
                        </div>

                        <?php if (empty($structuredPrizes)): ?>
                            <p class="text-center text-muted my-4">
                                <?= !empty($league['prize_description']) ? nl2br(e($league['prize_description'])) : 'Premiação a definir pelo organizador.' ?>
                            </p>
                        <?php else: ?>
                            <div class="prize-list">
                                <?php foreach ($structuredPrizes as $position => $data): ?>
                                    <div class="prize-row <?= $classes[$position] ?? '' ?>">
                                        <span class="badge-pos"><?= $medals[$position] ?? $position . 'º' ?></span>
                                        <span class="label"><?= e($data['label']) ?></span>
                                        <span class="value">R$ <?= number_format($data['amount'], 2, ',', '.') ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="prize-total">
                                <span class="text-muted small fw-semibold">Total em prêmios</span>
                                <strong>R$ <?= number_format($total, 2, ',', '.') ?></strong>
                            </div>
                        <?php endif; ?>

                        <div class="prize-foot">
                            <?php if ($teams > 0): ?>
                                <span class="pill"><i class="fa-solid fa-users"></i> <?= $teams ?> times</span>
                            <?php endif; ?>
                            <?php if (!empty($league['registration_fee'])): ?>
                                <span class="pill pill-orange"><i class="fa-solid fa-ticket"></i> Inscrição R$ <?= number_format((float) $league['registration_fee'], 2, ',', '.') ?></span>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
