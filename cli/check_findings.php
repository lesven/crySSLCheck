#!/usr/bin/env php
<?php

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

$pdo = \App\Database::getConnection();
$stmt = $pdo->query("SELECT id, finding_type, severity, details FROM findings ORDER BY id DESC LIMIT 3");
$findings = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Letzte 3 Findings ===\n\n";
foreach ($findings as $finding) {
    echo "ID: {$finding['id']}\n";
    echo "Type: {$finding['finding_type']}\n";
    echo "Severity: {$finding['severity']}\n";
    echo "Details (raw): " . substr($finding['details'], 0, 200) . "\n";
    echo "Details (decoded):\n";
    $decoded = json_decode($finding['details'], true);
    print_r($decoded);
    echo "\n---\n\n";
}
