<?php
/** @var array $recap result of RoundRecap::forMarketOpening() */

$recapTeam = function (array $t, int $pos, string $class = ''): string {
    $name = $t['team_name'] ?: 'Time ' . $t['cartola_team_id'];
    ob_start(); ?>
    <div class="rk-row recap-row <?= $class ?>">
        <div class="rk-pos"><b><?= $pos ?>º</b></div>
        <?php if (!empty($t['crest_url'])): ?>
            <img src="<?= e($t['crest_url']) ?>" class="rk-crest" alt="" loading="lazy">
        <?php else: ?>
            <span class="crest-fallback rk-crest"><i class="fa-solid fa-shield"></i></span>
        <?php endif; ?>
        <div class="min-w-0">
            <div class="rk-name"><?= e($name) ?></div>
            <div class="rk-sub"><?= e($t['owner_name'] ?: '—') ?></div>
        </div>
        <div class="rk-points"><?= number_format((float) $t['points'], 2, ',', '.') ?><small>pts</small></div>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="modal fade" id="modalResumoRodada" tabindex="-1" aria-hidden="true" data-rodada="<?= (int) $recap['round'] ?>">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable recap-dialog">
        <div class="modal-content">
            <div class="modal-header recap-header">
                <div>
                    <div class="recap-tag">MERCADO ABERTO</div>
                    <h2 class="modal-title">Como foi a rodada <?= (int) $recap['round'] ?></h2>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <?php foreach ($recap['leagues'] as $l): ?>
                    <section class="recap-league">
                        <h3><i class="fa-solid fa-trophy"></i> <?= e($l['name']) ?></h3>
                        <h4 class="recap-sub recap-best">Os melhores</h4>
                        <div class="rk-list">
                            <?php foreach ($l['top'] as $i => $t): ?>
                                <?= $recapTeam($t, $i + 1, $i < 3 ? 'top-' . ($i + 1) : '') ?>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($l['bottom']): ?>
                            <h4 class="recap-sub recap-worst">Os piores</h4>
                            <div class="rk-list">
                                <?php foreach ($l['bottom'] as $i => $t): ?>
                                    <?= $recapTeam($t, $l['bottom_from'] + $i, 'recap-bad') ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>

                <div class="recap-zoeira">
                    <?php foreach ($recap['text'] as $line): ?>
                        <p><?= e($line) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <a href="classificacao.php" class="btn btn-cartoleirao">Ver classificação</a>
                <button type="button" class="btn btn-ghost-dark" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>
