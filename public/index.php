<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

\Cartoleirao\Models\Season::guardPublicPage(false);

use Cartoleirao\Models\League;
use Cartoleirao\Models\MarketStatus;
use Cartoleirao\Models\OfficialLeagueTeam;
use Cartoleirao\Models\Season;
use Cartoleirao\Services\RoundRecap;

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

// With the market open, a recap of the round that just ended pops up on the home page.
$recap = null;
if (!$dbError && $market && $market['status_code'] === 1) {
    try {
        $recap = RoundRecap::forMarketOpening((int) $market['current_round']);
    } catch (\Throwable $e) {
        $recap = null;
    }
}
if ($recap) {
    $extraScripts = ['assets/js/resumo-rodada.js?v=' . (@filemtime(__DIR__ . '/assets/js/resumo-rodada.js') ?: time())];
}

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
            <svg class="hero-ball d-none d-sm-block" viewBox="0 0 200 220" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <!-- prancheta do técnico: campo com a escalação e as setas de jogada -->
                <rect x="14" y="14" width="172" height="198" rx="14" fill="#c98a3d"></rect>
                <rect x="24" y="30" width="152" height="172" rx="8" fill="#1f8f4e"></rect>
                <g fill="none" stroke="#ffffff" stroke-width="2" opacity=".85">
                    <rect x="32" y="38" width="136" height="156"></rect>
                    <line x1="32" y1="116" x2="168" y2="116"></line>
                    <circle cx="100" cy="116" r="18"></circle>
                    <rect x="68" y="38" width="64" height="26"></rect>
                    <rect x="68" y="168" width="64" height="26"></rect>
                </g>
                <!-- jogadores (4-3-3) -->
                <g fill="#00165a" stroke="#ffffff" stroke-width="2">
                    <circle cx="100" cy="180" r="6"></circle>
                    <circle cx="52" cy="154" r="6"></circle><circle cx="82" cy="158" r="6"></circle>
                    <circle cx="118" cy="158" r="6"></circle><circle cx="148" cy="154" r="6"></circle>
                    <circle cx="66" cy="124" r="6"></circle><circle cx="100" cy="132" r="6"></circle><circle cx="134" cy="124" r="6"></circle>
                    <circle cx="56" cy="82" r="6"></circle><circle cx="144" cy="82" r="6"></circle>
                </g>
                <circle cx="100" cy="76" r="7" fill="#ffd54a" stroke="#ffffff" stroke-width="2"></circle>
                <!-- setas de jogada -->
                <g fill="none" stroke="#ffd54a" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="5 5">
                    <path d="M100 126 C100 108 100 96 100 86"></path>
                    <path d="M56 76 C64 58 80 52 94 54"></path>
                </g>
                <polygon points="95,90 105,90 100,80" fill="#ffd54a"></polygon>
                <!-- clipe da prancheta -->
                <rect x="70" y="4" width="60" height="22" rx="6" fill="#d9dde8" stroke="#00165a" stroke-width="3"></rect>
                <rect x="88" y="10" width="24" height="6" rx="3" fill="#00165a"></rect>
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

<?php if ($recap): ?>
    <?php require __DIR__ . '/includes/resumo-rodada.php'; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
