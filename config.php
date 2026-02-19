<?php

// ============================================================
// TLS Monitor – Konfigurationsdatei
// ============================================================

// --- Datenbank ---
define('DB_PATH', __DIR__ . '/data/tls_monitor.sqlite');

// --- Domain-Validierung ---
define('ALLOW_IP_ADDRESSES', true); // true = IP-Adressen erlaubt, false = nur FQDNs

// --- Scan-Engine ---
define('SCAN_TIMEOUT', 10);          // Verbindungs-Timeout in Sekunden
define('RETRY_DELAY', 5);           // Sekunden bis zum Retry
define('RETRY_COUNT', 1);            // Anzahl Retries bei UNREACHABLE/ERROR
define('NOTIFY_ON_UNREACHABLE', false); // E-Mail bei UNREACHABLE/ERROR senden?

// Mindestanforderungen
// Mindest-RSA-Schlüssellänge (Bits). Standardsicher ist mindestens 2048; längere Schlüssel können sinnvoll sein.
define('MIN_RSA_KEY_BITS', 2048);

// --- Zertifikats-Schwellwerte (Tage) ---
define('CERT_WARN_DAYS', [30, 14, 7]);

// --- Session ---
define('SESSION_TIMEOUT', 3600);     // Session-Timeout in Sekunden (60 Minuten)

// --- SMTP ---
define('SMTP_HOST', '');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_FROM', 'tls-monitor@example.com');
define('SMTP_FROM_NAME', 'TLS Monitor');
define('SMTP_ENCRYPTION', 'tls');    // tls | ssl | none

// --- E-Mail-Empfänger ---
define('ALERT_RECIPIENTS', [
    // 'admin@example.com',
]);
