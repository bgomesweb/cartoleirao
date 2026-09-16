<?php

declare(strict_types=1);

namespace Cartoleirao\Auth;

use Cartoleirao\Database\Connection;

class AdminAuth
{
    public static function tentarLogin(string $usuario, string $senha): bool
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT id, senha_hash FROM admins WHERE usuario = ?');
        $stmt->execute([$usuario]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($senha, $admin['senha_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_usuario'] = $usuario;

        return true;
    }

    public static function logado(): bool
    {
        return !empty($_SESSION['admin_id']);
    }

    public static function usuarioAtual(): ?string
    {
        return $_SESSION['admin_usuario'] ?? null;
    }

    /**
     * Troca a senha do admin logado. Exige a senha atual correta (mesmo
     * já estando logado — evita que uma sessão esquecida aberta em outro
     * lugar consiga trocar a senha sem saber a atual).
     *
     * @return true|string true em caso de sucesso, ou uma mensagem de erro
     */
    public static function alterarSenha(int $adminId, string $senhaAtual, string $novaSenha)
    {
        if (mb_strlen($novaSenha) < 8) {
            return 'A nova senha precisa ter pelo menos 8 caracteres.';
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT senha_hash FROM admins WHERE id = ?');
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($senhaAtual, $admin['senha_hash'])) {
            return 'Senha atual incorreta.';
        }

        $stmt = $pdo->prepare('UPDATE admins SET senha_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($novaSenha, PASSWORD_DEFAULT), $adminId]);

        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_usuario']);
        session_regenerate_id(true);
    }

    public static function exigirLogin(): void
    {
        if (!self::logado()) {
            redirect('/admin/login.php');
        }
    }

    public static function exigirLoginAjax(): void
    {
        if (!self::logado()) {
            header('Content-Type: application/json', true, 401);
            echo json_encode(['erro' => 'Não autenticado']);
            exit;
        }
    }
}
