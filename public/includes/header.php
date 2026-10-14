<?php

declare(strict_types=1);

use Cartoleirao\Models\BonusRound;
use Cartoleirao\Models\MarketStatus;

$currentPage = basename($_SERVER['SCRIPT_NAME']);
$market = MarketStatus::current();
$bonusRoundsModal = BonusRound::forCurrentRound($market['current_round'] ?? null);

?><!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?>Cartoleirão</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
</head>
<body>
<nav class="navbar navbar-cartoleirao navbar-expand-lg">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="inscricao.php">
            <img src="assets/img/logo.png" alt="Cartoleirão">
            Cartoleirão
            <?php if ($market): ?>
                <span class="badge-mercado badge-mercado-<?= e($market['class']) ?>" title="Rodada <?= (int) ($market['current_round'] ?? 0) ?>">
                    <?= e($market['label']) ?>
                </span>
            <?php endif; ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <div class="navbar-nav ms-auto">
                <a class="nav-link <?= $currentPage === 'inscricao.php' ? 'active-link' : '' ?>" href="inscricao.php">
                    <span class="badge bg-warning text-dark">Inscreva-se</span>
                </a>
            </div>
        </div>
    </div>
</nav>

<?php if (!empty($bonusRoundsModal)): ?>
    <div class="modal fade" id="modalRodadaBonus" tabindex="-1" aria-hidden="true"
         data-rodada-bonus="<?= (int) $bonusRoundsModal[0]['round_number'] ?>">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-bonus-content">
                <button type="button" class="btn-close btn-close-white modal-bonus-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                <div class="modal-bonus-glow"></div>
                <div class="modal-body text-center py-5 px-4">
                    <div class="modal-bonus-trophy">🏆</div>
                    <span class="modal-bonus-tag">RODADA BÔNUS</span>
                    <h2 class="modal-bonus-rodada">Rodada <?= (int) $bonusRoundsModal[0]['round_number'] ?></h2>
                    <p class="modal-bonus-sub">
                        Quem fechar em <strong>1º lugar nessa rodada</strong> leva um extra na faixa,
                        além da briga na classificação geral!
                    </p>

                    <div class="modal-bonus-premios">
                        <?php foreach ($bonusRoundsModal as $bonus): ?>
                            <div class="modal-bonus-premio-item">
                                <span class="modal-bonus-liga"><?= e($bonus['league_name']) ?></span>
                                <span class="modal-bonus-valor">
                                    <?php if (!empty($bonus['prize_amount'])): ?>
                                        R$ <?= number_format((float) $bonus['prize_amount'], 2, ',', '.') ?>
                                    <?php else: ?>
                                        A definir
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="btn modal-bonus-btn mt-4" data-bs-dismiss="modal">
                        Bora pra cima! 🔥
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php $extraScripts[] = 'assets/js/rodada-bonus.js'; ?>
<?php endif; ?>
