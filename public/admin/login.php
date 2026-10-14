<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;

if (AdminAuth::isLoggedIn()) {
    redirect('index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::isValid($_POST['_csrf'] ?? null)) {
        $error = 'Sessão expirada, tente novamente.';
    } else {
        $username = trim((string) ($_POST['usuario'] ?? ''));
        $password = (string) ($_POST['senha'] ?? '');

        if (AdminAuth::attemptLogin($username, $password)) {
            redirect('index.php');
        }

        $error = 'Usuário ou senha inválidos.';
    }
}

?><!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>Entrar — Admin Cartoleirão</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= @filemtime(__DIR__ . '/../assets/css/admin.css') ?: time() ?>">
</head>
<body class="admin-body">
<div class="login-wrap">
    <div class="login-card">
        <div class="text-center mb-4">
            <img src="../assets/img/logo.png" alt="Cartoleirão">
            <h1>Cartoleirão</h1>
            <div class="text-muted small">Entre para acessar o painel administrativo</div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label" for="usuario">Usuário</label>
                <input type="text" class="form-control" id="usuario" name="usuario" required autofocus autocomplete="username">
            </div>
            <div class="mb-4">
                <label class="form-label" for="senha">Senha</label>
                <input type="password" class="form-control" id="senha" name="senha" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-brand w-100 py-2">Entrar</button>
        </form>
    </div>
</div>
</body>
</html>
