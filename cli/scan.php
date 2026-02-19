#!/usr/bin/env php
<?php

/**
 * TLS Monitor – CLI Scan Script
 *
 * Dieses Script führt einen vollständigen Scan aller aktiven Domains durch.
 * Es ist für den Einsatz als Cronjob vorgesehen.
 *
 * Cronjob-Eintrag:
 *   0 2 * * * php /path/to/cli/scan.php
 *
 * Usage:
 *   php cli/scan.php [--force|-f]
 * Optionen:
 *   --force, -f    Erzwingt Scan auch wenn bereits ein erfolgreicher Scan heute existiert.
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    echo "Dieses Script kann nur über die Kommandozeile ausgeführt werden.\n";
    exit(1);
}

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

// Check idempotency – skip if already scanned today with success (use --force or -f to override)
$force = (isset($argv) && (in_array('--force', $argv, true) || in_array('-f', $argv, true)));
if ($force) {
    echo "Force-Option erkannt — erzwinge Scan trotz vorherigem erfolgreichen Lauf.\n";
} else {
    $todayRun = \App\Model\ScanRun::findLatestToday();
    if ($todayRun && $todayRun['status'] === 'success') {
        echo "Scan für heute bereits erfolgreich durchgeführt (Run #{$todayRun['id']}). Überspringe.\n";
        exit(0);
    }
}

echo "TLS Monitor – Starte vollständigen Scan...\n";
echo "Zeitpunkt: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $runId = \App\Service\ScanService::runFullScan();

    if ($runId === 0) {
        echo "Keine aktiven Domains vorhanden.\n";
        exit(0);
    }

    $run = \App\Model\ScanRun::findById($runId);
    $findings = \App\Model\Finding::findByRunId($runId);

    echo "Scan abgeschlossen!\n";
    echo "Run-ID:     #{$runId}\n";
    echo "Status:     {$run['status']}\n";
    echo "Gestartet:  {$run['started_at']}\n";
    echo "Beendet:    {$run['finished_at']}\n";
    echo "Findings:   " . count($findings) . "\n\n";

    // Summary
    $summary = [];
    foreach ($findings as $f) {
        $type = $f['finding_type'];
        $summary[$type] = ($summary[$type] ?? 0) + 1;
    }

    echo "Zusammenfassung:\n";
    foreach ($summary as $type => $count) {
        echo "  $type: $count\n";
    }
    echo "\n";

    exit($run['status'] === 'success' ? 0 : 1);

} catch (\Exception $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
    exit(2);
}
