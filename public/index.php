<?php

/**
 * TLS Monitor – Entry-Point & Routing
 */

require_once __DIR__ . '/../config.php';

// Autoloader
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load PHPMailer if available via Composer
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// Initialize database
\App\Database::initialize();

// Start session
\App\Service\AuthService::startSession();

// --- Routing ---
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Health-check endpoint (no auth required)
if ($action === 'health') {
    $healthController = new \App\Controller\HealthController();
    $healthController->index();
    exit;
}

// Auth routes
if ($action === 'login') {
    $controller = new \App\Controller\AuthController();
    if ($method === 'POST') {
        $controller->login();
    } else {
        $controller->loginForm();
    }
    exit;
}

if ($action === 'logout') {
    $controller = new \App\Controller\AuthController();
    $controller->logout();
    exit;
}

// All other routes require authentication
if (!\App\Service\AuthService::isLoggedIn()) {
    header('Location: /index.php?action=login');
    exit;
}

switch ($action) {
    // Domain routes
    case 'domains':
    case '':
        $controller = new \App\Controller\DomainController();
        $controller->index();
        break;

    case 'domain_create':
        $controller = new \App\Controller\DomainController();
        $controller->create();
        break;

    case 'domain_store':
        $controller = new \App\Controller\DomainController();
        $controller->store();
        break;

    case 'domain_edit':
        $controller = new \App\Controller\DomainController();
        $controller->edit();
        break;

    case 'domain_update':
        $controller = new \App\Controller\DomainController();
        $controller->update();
        break;

    case 'domain_toggle':
        $controller = new \App\Controller\DomainController();
        $controller->toggle();
        break;

    // Scan routes
    case 'scan':
        $controller = new \App\Controller\ScanController();
        $controller->scan();
        break;

    // Finding routes
    case 'findings':
        $controller = new \App\Controller\FindingController();
        $controller->index();
        break;

    // SMTP test
    case 'smtp_test':
        \App\Service\AuthService::requireAdmin();
        try {
            $recipient = $_POST['recipient'] ?? SMTP_FROM;
            \App\Service\MailService::sendTestMail($recipient);
            $_SESSION['flash_success'] = 'Test-Mail wurde erfolgreich gesendet.';
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Test-Mail fehlgeschlagen: ' . $e->getMessage();
        }
        header('Location: /index.php?action=domains');
        exit;

    default:
        http_response_code(404);
        echo '404 – Seite nicht gefunden.';
        break;
}
