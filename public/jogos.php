<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/bootstrap.php';

\Cartoleirao\Models\Season::guardPublicPage(false);

use Cartoleirao\Models\Game;
use Cartoleirao\Models\Round;

$rounds = Round::allOrdered();
$selectedNumber = isset($_GET['rodada']) ? (int) $_GET['rodada'] : null;

$selectedRound = null;
if ($selectedNumber) {
    $selectedRound = Round::findByNumber($selectedNumber);
}
if (!$selectedRound) {
    $selectedRound = Round::mostRecent();
}

$games = $selectedRound ? Game::findByRound((int) $selectedRound['id']) : [];

/**
 * Builds the round's games share text for WhatsApp.
 */
function buildGamesShareText(array $round, array $games): string
{
    $lines = ["⚽ *Jogos da Rodada {$round['number']}* — Cartoleirão", ''];

    foreach ($games as $game) {
        $home = $game['home_abbreviation'] ?: $game['home_name'];
        $away = $game['away_abbreviation'] ?: $game['away_name'];

        if ($game['home_score'] !== null && $game['away_score'] !== null) {
            $matchup = "{$home} {$game['home_score']} x {$game['away_score']} {$away}";
        } else {
            $matchup = "{$home} x {$away}";
        }

        if (!empty($game['played_at'])) {
            $matchup .= ' (' . (new DateTime($game['played_at']))->format('d/m H:i') . ')';
        }

        $lines[] = $matchup;
    }

    return implode("\n", $lines);
}

$gamesShareText = ($selectedRound && !empty($games)) ? buildGamesShareText($selectedRound, $games) : null;

$pageTitle = 'Jogos';
require __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <h2 class="page-title justify-content-center mb-4"><i class="fa-solid fa-futbol"></i> Jogos do Brasileirão</h2>

    <?php if (empty($rounds)): ?>
        <div class="alert alert-info text-center">
            Ainda não sincronizamos nenhuma rodada com a API do Cartola. Isso acontece
            automaticamente a cada 10 minutos, ou pode ser forçado rodando
            <code>cron/sync_games.php</code>.
        </div>
    <?php else: ?>
        <form method="get" class="d-flex justify-content-center mb-4">
            <select name="rodada" class="form-select w-auto" onchange="this.form.submit()">
                <?php foreach ($rounds as $r): ?>
                    <option value="<?= (int) $r['number'] ?>" <?= (int) $selectedRound['number'] === (int) $r['number'] ? 'selected' : '' ?>>
                        Rodada <?= (int) $r['number'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($gamesShareText): ?>
            <div class="text-center mb-4">
                <a class="btn btn-whatsapp btn-sm" target="_blank" rel="noopener"
                   href="https://wa.me/?text=<?= rawurlencode($gamesShareText) ?>">
                    <i class="fa-brands fa-whatsapp"></i> Compartilhar rodada <?= (int) $selectedRound['number'] ?>
                </a>
            </div>
        <?php endif; ?>

        <?php if (empty($games)): ?>
            <p class="text-center text-muted">Nenhum jogo encontrado para essa rodada.</p>
        <?php else: ?>
            <div class="row g-3 justify-content-center">
                <?php foreach ($games as $game): ?>
                    <div class="col-md-6 col-lg-5">
                        <div class="card jogo-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-center flex-fill">
                                        <?php if (!empty($game['home_crest'])): ?>
                                            <img src="<?= e($game['home_crest']) ?>" class="escudo-time mb-1" alt="">
                                        <?php endif; ?>
                                        <div class="small fw-semibold"><?= e($game['home_abbreviation'] ?? $game['home_name']) ?></div>
                                    </div>

                                    <div class="px-3 text-center">
                                        <?php if ($game['home_score'] !== null && $game['away_score'] !== null): ?>
                                            <div class="fs-4 fw-bold"><?= (int) $game['home_score'] ?> x <?= (int) $game['away_score'] ?></div>
                                        <?php else: ?>
                                            <div class="fs-5 fw-bold text-muted">x</div>
                                        <?php endif; ?>
                                        <?php if (!empty($game['played_at'])): ?>
                                            <div class="small text-muted"><?= (new DateTime($game['played_at']))->format('d/m H:i') ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="text-center flex-fill">
                                        <?php if (!empty($game['away_crest'])): ?>
                                            <img src="<?= e($game['away_crest']) ?>" class="escudo-time mb-1" alt="">
                                        <?php endif; ?>
                                        <div class="small fw-semibold"><?= e($game['away_abbreviation'] ?? $game['away_name']) ?></div>
                                    </div>
                                </div>
                                <?php if (!empty($game['venue'])): ?>
                                    <div class="text-center small text-muted mt-2"><?= e($game['venue']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
