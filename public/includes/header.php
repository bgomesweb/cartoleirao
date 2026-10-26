<?php

declare(strict_types=1);

use Cartoleirao\Models\BonusRound;
use Cartoleirao\Models\MarketStatus;
use Cartoleirao\Models\Season;

$currentPage = basename($_SERVER['SCRIPT_NAME']);
// Com inscrições abertas o site mostra só a inscrição; fechadas, só o conteúdo da temporada.
$registrationMode = Season::registrationsOpen();
$navItems = [
    ['index.php', 'Início', 'fa-house'],
    ['jogos.php', 'Jogos', 'fa-futbol'],
    ['classificacao.php', 'Classificação', 'fa-ranking-star'],
];
$market = MarketStatus::current();
$bonusRoundsModal = BonusRound::forCurrentRound($market['current_round'] ?? null);

?><!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#00165a">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?>Cartoleirão</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="assets/img/icon-192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Cartoleirão">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
</head>
<body class="<?= $registrationMode ? '' : 'has-tabbar' ?>">
<header class="site-header">
    <div class="container site-header-inner">
        <a class="brand" href="<?= $registrationMode ? 'inscricao.php' : 'index.php' ?>">
            <img src="assets/img/logo.png" alt="">
            <span>Cartoleirão</span>
        </a>
        <?php if ($market): ?>
            <span class="badge-mercado badge-mercado-<?= e($market['class']) ?>" title="Rodada <?= (int) ($market['current_round'] ?? 0) ?>">
                <?= e($market['label']) ?>
            </span>
        <?php endif; ?>

        <?php if (getenv('VAPID_PUBLIC_KEY') && !str_contains($_SERVER['HTTP_USER_AGENT'] ?? '', 'CartoleiraoApp')): ?>
            <button type="button" id="btn-notificacoes" class="btn-bell ms-auto" data-vapid="<?= e(getenv('VAPID_PUBLIC_KEY')) ?>"
                    title="Ativar notificações de gol" aria-label="Notificações de gol">
                <i class="fa-regular fa-bell"></i>
            </button>
        <?php endif; ?>

        <?php if (!$registrationMode): ?>
            <nav class="site-nav d-none d-md-flex" aria-label="Principal">
                <?php foreach ($navItems as [$file, $label, $icon]): ?>
                    <a href="<?= $file ?>" class="<?= $currentPage === $file ? 'active' : '' ?>" <?= $currentPage === $file ? 'aria-current="page"' : '' ?>><?= $label ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>
</header>

<?php if (!$registrationMode): ?>
    <nav class="tabbar d-md-none" aria-label="Principal">
        <?php foreach ($navItems as [$file, $label, $icon]): ?>
            <a href="<?= $file ?>" class="<?= $currentPage === $file ? 'active' : '' ?>" <?= $currentPage === $file ? 'aria-current="page"' : '' ?>>
                <i class="fa-solid <?= $icon ?>"></i><?= $label ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

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
