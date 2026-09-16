<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;

if (AdminAuth::logado()) {
    redirect('index.php');
}

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::valido($_POST['_csrf'] ?? null)) {
        $erro = 'Sessão expirada, tente novamente.';
    } else {
        $usuario = trim((string) ($_POST['usuario'] ?? ''));
        $senha = (string) ($_POST['senha'] ?? '');

        if (AdminAuth::tentarLogin($usuario, $senha)) {
            redirect('index.php');
        }

        $erro = 'Usuário ou senha inválidos.';
    }
}

?><!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>Login — Admin Cartoleirão</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="d-flex align-items-center" style="min-height:100vh; background: var(--cartoleirao-navy);">
    <div class="container">
        <div class="card card-inscricao">
            <div class="card-header sem-pills">
                <img src="../assets/img/logo.png" alt="Cartoleirão" height="48" class="mb-2">
                <h3 class="mb-0">Admin Cartoleirão</h3>
            </div>
            <div class="card-body p-4">
                <?php if ($erro): ?>
                    <div class="alert alert-danger"><?= e($erro) ?></div>
                <?php endif; ?>
                <form method="post">
                    <?= Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="usuario">Usuário</label>
                        <input type="text" class="form-control" id="usuario" name="usuario" required autofocus>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="senha">Senha</label>
                        <input type="password" class="form-control" id="senha" name="senha" required>
                    </div>
                    <button type="submit" class="btn btn-cartoleirao w-100">Entrar</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
