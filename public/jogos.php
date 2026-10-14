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

$prevRound = $nextRound = null;
if ($selectedRound) {
    foreach ($rounds as $idx => $r) {
        if ((int) $r['number'] === (int) $selectedRound['number']) {
            $nextRound = isset($rounds[$idx - 1]) ? (int) $rounds[$idx - 1]['number'] : null;
            $prevRound = isset($rounds[$idx + 1]) ? (int) $rounds[$idx + 1]['number'] : null;
            break;
        }
    }
}

// Games grouped by day (already ordered by date).
$days = [];
foreach ($games as $game) {
    $key = !empty($game['played_at']) ? (new DateTime($game['played_at']))->format('Y-m-d') : 'sem-data';
    $days[$key][] = $game;
}

$weekdays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
$dayLabel = static function (string $key) use ($weekdays): string {
    if ($key === 'sem-data') {
        return 'Data a definir';
    }
    $d = new DateTime($key);

    return $weekdays[(int) $d->format('w')] . ', ' . $d->format('d/m');
};

$crest = static function (?string $url): string {
    return $url
        ? '<img src="' . e($url) . '" alt="" loading="lazy">'
        : '<span class="crest-fallback"><i class="fa-solid fa-shield"></i></span>';
};

$pageTitle = 'Jogos';
require __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-head">
        <h1>Jogos do Brasileirão</h1>
        <?php if ($selectedRound): ?><p>Rodada <?= (int) $selectedRound['number'] ?></p><?php endif; ?>
    </div>

    <?php if (empty($rounds)): ?>
        <div class="empty-state mt-3"><i class="fa-solid fa-calendar-xmark"></i>Ainda não há rodadas sincronizadas com o Cartola. Elas aparecem aqui automaticamente.</div>
    <?php else: ?>
        <div class="controls">
            <form method="get" class="round-nav">
                <a class="btn-icon <?= $prevRound ? '' : 'disabled' ?>" aria-label="Rodada anterior"
                   href="<?= $prevRound ? '?rodada=' . $prevRound : '#' ?>"><i class="fa-solid fa-chevron-left"></i></a>
                <select name="rodada" class="form-select" aria-label="Rodada" onchange="this.form.submit()">
                    <?php foreach ($rounds as $r): ?>
                        <option value="<?= (int) $r['number'] ?>" <?= (int) $selectedRound['number'] === (int) $r['number'] ? 'selected' : '' ?>>
                            Rodada <?= (int) $r['number'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a class="btn-icon <?= $nextRound ? '' : 'disabled' ?>" aria-label="Próxima rodada"
                   href="<?= $nextRound ? '?rodada=' . $nextRound : '#' ?>"><i class="fa-solid fa-chevron-right"></i></a>
            </form>
        </div>

        <?php if (empty($games)): ?>
            <div class="empty-state"><i class="fa-solid fa-futbol"></i>Nenhum jogo encontrado para essa rodada.</div>
        <?php else: ?>
            <?php foreach ($days as $key => $dayGames): ?>
                <h2 class="day-title"><?= e($dayLabel($key)) ?></h2>
                <div class="row g-3">
                    <?php foreach ($dayGames as $game): ?>
                        <?php $hasScore = $game['home_score'] !== null && $game['away_score'] !== null; ?>
                        <div class="col-12 col-md-6 col-xl-4">
                            <article class="game-card">
                                <div class="game-main">
                                    <div class="game-team">
                                        <?= $crest($game['home_crest'] ?? null) ?>
                                        <div class="nm"><?= e($game['home_abbreviation'] ?? $game['home_name']) ?></div>
                                    </div>
                                    <div class="game-center">
                                        <?php if ($hasScore): ?>
                                            <div class="game-score"><?= (int) $game['home_score'] ?> x <?= (int) $game['away_score'] ?></div>
                                        <?php elseif (!empty($game['played_at'])): ?>
                                            <span class="game-time"><?= (new DateTime($game['played_at']))->format('H:i') ?></span>
                                        <?php else: ?>
                                            <span class="game-time">x</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="game-team">
                                        <?= $crest($game['away_crest'] ?? null) ?>
                                        <div class="nm"><?= e($game['away_abbreviation'] ?? $game['away_name']) ?></div>
                                    </div>
                                </div>
                                <?php if (!empty($game['venue'])): ?>
                                    <div class="game-venue"><i class="fa-solid fa-location-dot"></i> <?= e($game['venue']) ?></div>
                                <?php endif; ?>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
