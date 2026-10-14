<?php

declare(strict_types=1);

// Temporário: somente a página de inscrição está pública.
header('Location: inscricao.php', true, 302);
exit;

require_once __DIR__ . '/../src/Config/bootstrap.php';

use Cartoleirao\Models\League;
use Cartoleirao\Models\Score;

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

/**
 * Builds the text that goes along with the highlights image when sharing.
 */
function buildHighlightsShareText(string $leagueName, int $roundNumber, array $highlights): string
{
    $lines = ["🏆 *Destaques da Rodada {$roundNumber}*", $leagueName, ''];

    foreach ($highlights as $i => $h) {
        $position = $i + 1;
        $prefix = match ($position) {
            1 => '🥇',
            2 => '🥈',
            3 => '🥉',
            default => "{$position}º",
        };
        $teamName = $h['team_name'] ?: ('Time ' . $h['cartola_team_id']);
        $points = number_format((float) $h['points'], 2, ',', '.');
        $lines[] = "{$prefix} {$teamName} — {$points} pts";
    }

    $lines[] = '';
    $lines[] = '⚽ Cartoleirão';

    return implode("\n", $lines);
}

$leagues = [];
$classicLeague = null;
$highlights = [];
$highlightsRound = null;
$dbError = null;
$highlightsShareText = null;

try {
    $leagues = League::all();
    foreach ($leagues as $league) {
        if ((int) $league['is_default'] === 1) {
            $classicLeague = $league;
            break;
        }
    }

    if ($classicLeague) {
        $highlightsRound = Score::latestRoundWithData((int) $classicLeague['id']);
        if ($highlightsRound) {
            $highlights = Score::highlightsForRound((int) $classicLeague['id'], (int) $highlightsRound['id'], 8);
            if (!empty($highlights)) {
                $highlightsShareText = buildHighlightsShareText(
                    $classicLeague['name'],
                    (int) $highlightsRound['number'],
                    $highlights
                );
            }
        }
    }
} catch (\Throwable $e) {
    $dbError = 'Não foi possível carregar os dados agora. Tente novamente em instantes.';
}

$pageTitle = 'Início';
require __DIR__ . '/includes/header.php';
?>

<section class="hero-cartoleirao">
    <div class="container">
    <?php if ($dbError): ?>
        <div class="alert alert-warning"><?= e($dbError) ?></div>
    <?php endif; ?>
    </div>
    <div class="container d-flex flex-column flex-lg-row align-items-center gap-4">
        <div class="flex-grow-1">
            <h1 class="mb-3">Cartola, futebol e resenha ⚽🍻</h1>
            <p class="subtitulo mb-4">
                O Cartoleirão reúne a galera pra brigar ponto a ponto no Cartola FC
                durante todo o Brasileirão Série A — com ranking em tempo real,
                zoeira garantida no grupo e prêmio pra quem terminar no topo.
            </p>
            <a href="inscricao.php" class="btn btn-lg btn-cartoleirao">Quero me inscrever</a>
            <a href="classificacao.php" class="btn btn-lg btn-outline-light ms-2">Ver classificação</a>
        </div>
        <div class="text-center">
            <svg class="hero-ball" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
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

<div class="container py-5">
    <h2 class="page-title-home justify-content-center mb-4">Premiação das Ligas</h2>
    <div class="row g-4 justify-content-center">
        <?php foreach ($leagues as $league): ?>
            <?php
            $structuredPrizes = [];
            foreach (range(1, 8) as $i) {
                $amount = $league["prize_{$i}"] ?? null;
                if ($amount !== null && (float) $amount > 0) {
                    $structuredPrizes[$i] = ['label' => "{$i}º Colocado", 'amount' => (float) $amount];
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
            ?>
            <div class="col-md-5">
                <div class="premiacao-card-v2">
                    <div class="premiacao-v2-header">
                        <span class="premiacao-v2-icon">🏆</span>
                        <div>
                            <div class="premiacao-v2-titulo">Premiação</div>
                            <div class="premiacao-v2-liga"><?= e($league['name']) ?></div>
                        </div>
                    </div>

                    <?php if (empty($structuredPrizes)): ?>
                        <p class="text-center text-muted my-4">
                            <?= !empty($league['prize_description']) ? nl2br(e($league['prize_description'])) : 'Premiação a definir pelo organizador.' ?>
                        </p>
                    <?php else: ?>
                        <div class="premiacao-v2-lista">
                            <?php
                            $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                            $classes = [1 => 'ouro', 2 => 'prata', 3 => 'bronze'];
                            foreach ($structuredPrizes as $position => $data):
                                $class = $classes[$position] ?? 'padrao';
                            ?>
                                <div class="premio-v2-row <?= $class ?>">
                                    <span class="premio-v2-badge"><?= $medals[$position] ?? $position ?></span>
                                    <span class="premio-v2-label"><?= e($data['label']) ?></span>
                                    <span class="premio-v2-valor">R$ <?= number_format($data['amount'], 2, ',', '.') ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="premio-v2-total">
                            <span class="text-muted small">Total em prêmios</span>
                            <span class="premio-v2-total-valor">R$ <?= number_format($total, 2, ',', '.') ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="premiacao-v2-footer">
                        <?php if ($league['registrations_open']): ?>
                            <span class="badge badge-laranja">Inscrições abertas</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inscrições encerradas</span>
                        <?php endif; ?>
                        <?php if (!empty($league['registration_fee'])): ?>
                            <span class="text-muted small">Inscrição: R$ <?= number_format((float) $league['registration_fee'], 2, ',', '.') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="container pb-5">
    <div class="destaques-topo mb-4">
        <h2 class="mb-0">
            Destaques da Rodada
            <?php if ($highlightsRound): ?>
                <small class="text-muted fs-6">(rodada <?= (int) $highlightsRound['number'] ?> — <?= e($classicLeague['name']) ?>)</small>
            <?php endif; ?>
        </h2>
        <?php if ($highlightsShareText): ?>
            <button type="button" class="btn btn-whatsapp btn-sm"
                    data-compartilhar-imagem="#captura-destaques"
                    data-titulo="Destaques da Rodada <?= (int) $highlightsRound['number'] ?> — Cartoleirão"
                    data-texto="<?= e($highlightsShareText) ?>"
                    data-arquivo="destaques-rodada-<?= (int) $highlightsRound['number'] ?>">
                <i class="fa-brands fa-whatsapp"></i> Compartilhar
            </button>
        <?php endif; ?>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <?php if (empty($highlights)): ?>
                <p class="text-center text-muted">
                    Ainda não há pontuações registradas. Assim que a rodada fechar,
                    os destaques aparecem aqui.
                </p>
            <?php else: ?>
                <div id="captura-destaques" class="captura-card">
                    <div class="captura-card-header">
                        <span class="captura-card-trofeu">🏆</span>
                        <div>
                            <div class="captura-card-titulo">Destaques da Rodada <?= (int) $highlightsRound['number'] ?></div>
                            <div class="captura-card-liga"><?= e($classicLeague['name']) ?></div>
                        </div>
                    </div>
                    <div class="captura-card-corpo">
                        <?php foreach ($highlights as $i => $h): ?>
                            <div class="destaque-item">
                                <div class="d-flex align-items-center">
                                    <span class="destaque-posicao"><?= $i + 1 ?></span>
                                    <?php if (!empty($h['crest_url'])): ?>
                                        <img src="<?= e($h['crest_url']) ?>" class="escudo-time me-2" alt="">
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-semibold"><?= e($h['team_name'] ?: ('Time ' . $h['cartola_team_id'])) ?></div>
                                        <?php if (!empty($h['registrant_name'])): ?>
                                            <div class="small text-muted"><?= e($h['registrant_name']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="fw-bold text-success"><?= number_format((float) $h['points'], 2, ',', '.') ?> pts</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="captura-card-footer">⚽ Cartoleirão</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
