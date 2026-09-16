<?php

declare(strict_types=1);

namespace Cartoleirao\Auth;

use Cartoleirao\Database\Connection;

class AdminAuth
{
    public static function attemptLogin(string $username, string $password): bool
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT id, password_hash FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_username'] = $username;

        return true;
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['admin_id']);
    }

    public static function currentUsername(): ?string
    {
        return $_SESSION['admin_username'] ?? null;
    }

    /**
     * Changes the logged-in admin's password. Requires the current password
     * (even though already logged in — prevents a forgotten open session
     * elsewhere from changing the password without knowing the current one).
     *
     * @return true|string true on success, or an error message
     */
    public static function changePassword(int $adminId, string $currentPassword, string $newPassword)
    {
        if (mb_strlen($newPassword) < 8) {
            return 'A nova senha precisa ter pelo menos 8 caracteres.';
        }

        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($currentPassword, $admin['password_hash'])) {
            return 'Senha atual incorreta.';
        }

        $stmt = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $adminId]);

        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_username']);
        session_regenerate_id(true);
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            redirect('/admin/login.php');
        }
    }

    public static function requireLoginAjax(): void
    {
        if (!self::isLoggedIn()) {
            header('Content-Type: application/json', true, 401);
            echo json_encode(['erro' => 'Não autenticado']);
            exit;
        }
    }
}
