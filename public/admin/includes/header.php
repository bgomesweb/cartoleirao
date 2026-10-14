<?php

declare(strict_types=1);

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\MarketStatus;

AdminAuth::requireLogin();

$currentPage = basename($_SERVER['SCRIPT_NAME']);
$market = MarketStatus::current();
$username = AdminAuth::currentUsername() ?? '';

// Page vars (set by each page before requiring this file)
$pageTitle = $pageTitle ?? 'Admin';
$pageSubtitle = $pageSubtitle ?? null;

$ligasPages = ['ligas.php', 'rodadas-bonus.php'];
$ligasOpen = in_array($currentPage, $ligasPages, true);
$configPages = ['integracoes.php', 'configuracoes.php'];
$configOpen = in_array($currentPage, $configPages, true);

?><!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= Csrf::token() ?>">
    <title><?= e($pageTitle) ?> — Admin Cartoleirão</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/admin.css') ?: time() ?>">
</head>
<body class="admin-body">
<div class="app">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="index.php">
            <img src="../assets/img/logo.png" alt="Cartoleirão">
            <span>
                <span class="brand-name d-block">Cartoleirão</span>
                <span class="brand-sub">Painel admin</span>
            </span>
        </a>

        <nav>
            <div class="nav-section">Geral</div>
            <a class="side-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" href="index.php">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            <a class="side-link <?= $currentPage === 'inscricoes.php' ? 'active' : '' ?>" href="inscricoes.php">
                <i class="fa-solid fa-users"></i> Inscrições
            </a>

            <button class="side-link <?= $ligasOpen ? 'active' : '' ?>" type="button"
                    data-bs-toggle="collapse" data-bs-target="#menuLigas"
                    aria-expanded="<?= $ligasOpen ? 'true' : 'false' ?>">
                <i class="fa-solid fa-trophy"></i> Ligas
                <i class="fa-solid fa-chevron-down caret"></i>
            </button>
            <div class="collapse side-sub <?= $ligasOpen ? 'show' : '' ?>" id="menuLigas">
                <a class="side-link <?= $currentPage === 'ligas.php' ? 'active' : '' ?>" href="ligas.php">
                    <i class="fa-solid fa-medal"></i> Ligas e premiações
                </a>
                <a class="side-link <?= $currentPage === 'rodadas-bonus.php' ? 'active' : '' ?>" href="rodadas-bonus.php">
                    <i class="fa-solid fa-star"></i> Rodada bônus
                </a>
            </div>

            <div class="nav-section">Sistema</div>
            <button class="side-link <?= $configOpen ? 'active' : '' ?>" type="button"
                    data-bs-toggle="collapse" data-bs-target="#menuConfig"
                    aria-expanded="<?= $configOpen ? 'true' : 'false' ?>">
                <i class="fa-solid fa-gear"></i> Configurações
                <i class="fa-solid fa-chevron-down caret"></i>
            </button>
            <div class="collapse side-sub <?= $configOpen ? 'show' : '' ?>" id="menuConfig">
                <a class="side-link <?= $currentPage === 'integracoes.php' ? 'active' : '' ?>" href="integracoes.php">
                    <i class="fa-solid fa-plug"></i> Integrações
                </a>
                <a class="side-link <?= $currentPage === 'configuracoes.php' ? 'active' : '' ?>" href="configuracoes.php">
                    <i class="fa-solid fa-user-gear"></i> Minha conta
                </a>
            </div>
        </nav>

        <div class="side-user">
            <span class="avatar"><?= e(mb_strtoupper(mb_substr($username, 0, 1)) ?: 'A') ?></span>
            <div>
                <div class="side-user-name"><?= e($username) ?></div>
                <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
            </div>
        </div>
    </aside>
    <div class="backdrop" id="backdrop"></div>

    <div class="main">
        <header class="topbar">
            <button class="topbar-toggle" type="button" id="sidebarToggle" aria-label="Abrir menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div>
                <h1 class="topbar-title"><?= e($pageTitle) ?></h1>
                <?php if ($pageSubtitle): ?>
                    <p class="topbar-sub"><?= e($pageSubtitle) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($market): ?>
                <div class="topbar-right">
                    <span class="chip chip-market <?= e($market['class']) ?>" title="Rodada <?= (int) ($market['current_round'] ?? 0) ?>">
                        <?= e($market['label']) ?>
                    </span>
                </div>
            <?php endif; ?>
        </header>
        <main class="content">
