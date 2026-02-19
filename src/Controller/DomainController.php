<?php

namespace App\Controller;

use App\Model\Domain;
use App\Service\AuthService;
use App\Service\ValidationService;

class DomainController
{
    public function index(): void
    {
        AuthService::requireLogin();
        $domains = Domain::findAll();
        $user = AuthService::getCurrentUser();
        $success = $_GET['success'] ?? null;
        require __DIR__ . '/../../templates/domains/list.php';
    }

    public function create(): void
    {
        AuthService::requireAdmin();
        $errors = [];
        $fqdn = '';
        $port = 443;
        $description = '';
        $user = AuthService::getCurrentUser();
        require __DIR__ . '/../../templates/domains/form.php';
    }

    public function store(): void
    {
        AuthService::requireAdmin();

        $fqdn = trim($_POST['fqdn'] ?? '');
        $port = (int) ($_POST['port'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $user = AuthService::getCurrentUser();

        $errors = ValidationService::validateDomain($fqdn, $port);

        if (!empty($errors)) {
            require __DIR__ . '/../../templates/domains/form.php';
            return;
        }

        Domain::create($fqdn, $port, $description ?: null);
        header('Location: /index.php?action=domains&success=created');
        exit;
    }

    public function edit(): void
    {
        AuthService::requireAdmin();

        $id = (int) ($_GET['id'] ?? 0);
        $domain = Domain::findById($id);
        if (!$domain) {
            http_response_code(404);
            echo 'Domain nicht gefunden.';
            return;
        }

        $fqdn = $domain['fqdn'];
        $port = $domain['port'];
        $description = $domain['description'] ?? '';
        $errors = [];
        $user = AuthService::getCurrentUser();
        require __DIR__ . '/../../templates/domains/form.php';
    }

    public function update(): void
    {
        AuthService::requireAdmin();

        $id = (int) ($_POST['id'] ?? 0);
        $fqdn = trim($_POST['fqdn'] ?? '');
        $port = (int) ($_POST['port'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $user = AuthService::getCurrentUser();

        $domain = Domain::findById($id);
        if (!$domain) {
            http_response_code(404);
            echo 'Domain nicht gefunden.';
            return;
        }

        $errors = ValidationService::validateDomain($fqdn, $port, $id);

        if (!empty($errors)) {
            require __DIR__ . '/../../templates/domains/form.php';
            return;
        }

        Domain::update($id, $fqdn, $port, $description ?: null);
        header('Location: /index.php?action=domains&success=updated');
        exit;
    }

    public function toggle(): void
    {
        AuthService::requireAdmin();

        $id = (int) ($_POST['id'] ?? 0);
        $domain = Domain::findById($id);
        if (!$domain) {
            http_response_code(404);
            echo 'Domain nicht gefunden.';
            return;
        }

        Domain::toggleStatus($id);
        header('Location: /index.php?action=domains&success=toggled');
        exit;
    }
}
