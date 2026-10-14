<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/bootstrap.php';

use Cartoleirao\Auth\AdminAuth;
use Cartoleirao\Helpers\Csrf;

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::isValid($_POST['_csrf'] ?? null)) {
    $currentPassword = (string) ($_POST['senha_atual'] ?? '');
    $newPassword = (string) ($_POST['nova_senha'] ?? '');
    $confirmPassword = (string) ($_POST['confirmar_senha'] ?? '');

    if ($newPassword !== $confirmPassword) {
        $error = 'A confirmação não bate com a nova senha.';
    } else {
        $result = AdminAuth::changePassword((int) $_SESSION['admin_id'], $currentPassword, $newPassword);
        if ($result === true) {
            $message = 'Senha alterada com sucesso.';
        } else {
            $error = $result;
        }
    }
}

$pageTitle = 'Minha conta';
$pageSubtitle = 'Altere a senha de acesso ao painel';
require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
<?php endif; ?>

<div class="panel" style="max-width: 520px;">
    <div class="panel-head">
        <h3><i class="fa-solid fa-key"></i> Alterar senha</h3>
    </div>
    <div class="panel-body">
        <p class="text-muted small">
            Usuário atual: <strong class="text-body"><?= e(AdminAuth::currentUsername() ?? '') ?></strong>
        </p>

        <form method="post">
            <?= Csrf::field() ?>

            <div class="mb-3">
                <label class="form-label">Senha atual</label>
                <input type="password" class="form-control" name="senha_atual" required autocomplete="current-password">
            </div>

            <div class="mb-3">
                <label class="form-label">Nova senha</label>
                <input type="password" class="form-control" name="nova_senha" required minlength="8" autocomplete="new-password">
                <div class="form-text">Mínimo de 8 caracteres.</div>
            </div>

            <div class="mb-4">
                <label class="form-label">Confirmar nova senha</label>
                <input type="password" class="form-control" name="confirmar_senha" required minlength="8" autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-brand w-100">
                <i class="fa-solid fa-floppy-disk"></i> Salvar nova senha
            </button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
