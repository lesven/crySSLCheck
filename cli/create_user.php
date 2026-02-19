#!/usr/bin/env php
<?php

/**
 * TLS Monitor – CLI User Management Script
 *
 * Usage:
 *   php cli/create_user.php <username> <password> [admin|auditor]
 */

if (php_sapi_name() !== 'cli') {
    echo "Dieses Script kann nur über die Kommandozeile ausgeführt werden.\n";
    exit(1);
}

if ($argc < 3) {
    echo "Usage: php create_user.php <username> <password> [admin|auditor]\n";
    exit(1);
}

$username = $argv[1];
$password = $argv[2];
$role = $argv[3] ?? 'auditor';

if (!in_array($role, ['admin', 'auditor'])) {
    echo "Ungültige Rolle: $role. Erlaubt: admin, auditor\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require $file;
});

\App\Database::initialize();

$existing = \App\Model\User::findByUsername($username);
if ($existing) {
    echo "Benutzer '$username' existiert bereits.\n";
    exit(1);
}

$pdo = \App\Database::getConnection();
$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
$stmt->execute([$username, $hash, $role]);

echo "Benutzer '$username' mit Rolle '$role' erfolgreich angelegt.\n";
