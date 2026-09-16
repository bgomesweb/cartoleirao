<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;

$mensagem = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::valido($_POST['_csrf'] ?? null)) {
    $senhaAtual = (string) ($_POST['senha_atual'] ?? '');
    $novaSenha = (string) ($_POST['nova_senha'] ?? '');
    $confirmarSenha = (string) ($_POST['confirmar_senha'] ?? '');

    if ($novaSenha !== $confirmarSenha) {
        $erro = 'A confirmação não bate com a nova senha.';
    } else {
        $resultado = AdminAuth::alterarSenha((int) $_SESSION['admin_id'], $senhaAtual, $novaSenha);
        if ($resultado === true) {
            $mensagem = 'Senha alterada com sucesso.';
        } else {
            $erro = $resultado;
        }
    }
}

$tituloPagina = 'Configurações — Minha conta';
require __DIR__ . '/includes/header.php';
?>

<h2 class="page-title mb-4"><i class="fa-solid fa-gear"></i> Configurações — Minha conta</h2>

<?php if ($mensagem): ?>
    <div class="alert alert-success"><?= e($mensagem) ?></div>
<?php endif; ?>
<?php if ($erro): ?>
    <div class="alert alert-danger"><?= e($erro) ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6">
        <div class="card admin-sidebar-card">
            <div class="card-body">
                <h5 class="card-title mb-3">Alterar senha</h5>
                <p class="text-muted small">
                    Usuário atual: <strong><?= e(AdminAuth::usuarioAtual() ?? '') ?></strong>
                </p>

                <form method="post">
                    <?= Csrf::field() ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Senha atual</label>
                        <input type="password" class="form-control" name="senha_atual" required autocomplete="current-password">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nova senha</label>
                        <input type="password" class="form-control" name="nova_senha" required minlength="8" autocomplete="new-password">
                        <div class="form-text">Mínimo de 8 caracteres.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Confirmar nova senha</label>
                        <input type="password" class="form-control" name="confirmar_senha" required minlength="8" autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn btn-cartoleirao w-100">
                        <i class="fa-solid fa-floppy-disk"></i> Salvar nova senha
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card admin-sidebar-card">
            <div class="card-body">
                <h5 class="card-title mb-3">Outras configurações</h5>
                <p class="text-muted small mb-4">
                    Autenticação com o Cartola FC (necessária só pra sincronizar a lista de times da
                    liga oficial — pontuação e jogos funcionam sem isso).
                </p>
                <a href="cartola-login.php" class="btn btn-outline-navy w-100 mb-2">
                    <i class="fa-solid fa-right-to-bracket"></i> Login Globo (status do token)
                </a>
                <a href="cartola-bookmarklet.php" class="btn btn-outline-navy w-100">
                    <i class="fa-solid fa-bookmark"></i> Bookmarklet de captura de token
                </a>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
