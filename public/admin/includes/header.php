<?php

declare(strict_types=1);

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;
use Cartoleirao\Models\MercadoStatus;

AdminAuth::exigirLogin();

$paginaAtual = basename($_SERVER['SCRIPT_NAME']);
$mercado = MercadoStatus::atual();

?><!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= Csrf::token() ?>">
    <title>Admin — Cartoleirão</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="admin-body">
<nav class="navbar navbar-cartoleirao navbar-expand-lg mb-4">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="index.php">
            <img src="../assets/img/logo.png" alt="Cartoleirão">
            Cartoleirão — Admin
            <?php if ($mercado): ?>
                <span class="badge-mercado badge-mercado-<?= e($mercado['classe']) ?>" title="Rodada <?= (int) ($mercado['rodada_atual'] ?? 0) ?>">
                    <?= e($mercado['label']) ?>
                </span>
            <?php endif; ?>
        </a>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a class="admin-nav-link <?= $paginaAtual === 'index.php' ? 'ativo' : '' ?>" href="index.php">Dashboard</a>
            <a class="admin-nav-link <?= $paginaAtual === 'inscricoes.php' ? 'ativo' : '' ?>" href="inscricoes.php">Inscrições</a>
            <a class="admin-nav-link <?= $paginaAtual === 'ligas.php' ? 'ativo' : '' ?>" href="ligas.php">Ligas</a>
            <a class="admin-nav-link <?= $paginaAtual === 'liga-oficial.php' ? 'ativo' : '' ?>" href="liga-oficial.php">Liga Oficial</a>
            <a class="admin-nav-link <?= $paginaAtual === 'rodadas-bonus.php' ? 'ativo' : '' ?>" href="rodadas-bonus.php"><i class="fa-solid fa-star"></i> Rodada Bônus</a>
            <?php $paginasConfig = ['cartola-login.php', 'cartola-bookmarklet.php', 'configuracoes.php']; ?>
            <div class="dropdown">
                <a class="admin-nav-link dropdown-toggle <?= in_array($paginaAtual, $paginasConfig, true) ? 'ativo' : '' ?>"
                   href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    Configurações
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item <?= $paginaAtual === 'cartola-login.php' ? 'active' : '' ?>" href="cartola-login.php"><i class="fa-solid fa-right-to-bracket"></i> Login Globo</a></li>
                    <li><a class="dropdown-item <?= $paginaAtual === 'cartola-bookmarklet.php' ? 'active' : '' ?>" href="cartola-bookmarklet.php"><i class="fa-solid fa-bookmark"></i> Bookmarklet</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item <?= $paginaAtual === 'configuracoes.php' ? 'active' : '' ?>" href="configuracoes.php"><i class="fa-solid fa-user-gear"></i> Minha conta / senha</a></li>
                </ul>
            </div>
            <span class="text-white-50 small ms-2">Olá, <?= e(AdminAuth::usuarioAtual() ?? '') ?></span>
            <a class="btn btn-sm btn-outline-light" href="logout.php">Sair</a>
        </div>
    </div>
</nav>
<div class="container pb-5">
