<?php

namespace App\Service;

use App\Model\User;

class AuthService
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Check session timeout
        if (isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
                self::logout();
                return;
            }
        }
        $_SESSION['last_activity'] = time();
    }

    public static function login(string $username, string $password): ?array
    {
        $user = User::authenticate($username, $password);
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['last_activity'] = time();
            return $user;
        }
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function isAdmin(): bool
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: /index.php?action=login');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            echo 'Zugriff verweigert – Admin-Rechte erforderlich.';
            exit;
        }
    }

    public static function getCurrentUser(): ?array
    {
        if (self::isLoggedIn()) {
            return [
                'id'       => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'role'     => $_SESSION['role'],
            ];
        }
        return null;
    }
}
