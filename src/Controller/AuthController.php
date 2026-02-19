<?php

namespace App\Controller;

use App\Service\AuthService;

class AuthController
{
    public function loginForm(): void
    {
        if (AuthService::isLoggedIn()) {
            header('Location: /index.php?action=domains');
            exit;
        }
        $error = '';
        require __DIR__ . '/../../templates/login.php';
    }

    public function login(): void
    {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Benutzername und Passwort sind erforderlich.';
            require __DIR__ . '/../../templates/login.php';
            return;
        }

        $user = AuthService::login($username, $password);
        if ($user) {
            header('Location: /index.php?action=domains');
            exit;
        }

        $error = 'Ungültiger Benutzername oder Passwort.';
        require __DIR__ . '/../../templates/login.php';
    }

    public function logout(): void
    {
        AuthService::logout();
        header('Location: /index.php?action=login');
        exit;
    }
}
