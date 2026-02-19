#!/usr/bin/env php
<?php

$fqdn = $argv[1] ?? 'www.google.com';
$port = (int)($argv[2] ?? 443);

echo "Testing TLS connection to $fqdn:$port\n\n";

$context = stream_context_create([
    'ssl' => [
        'capture_peer_cert'       => true,
        'capture_peer_cert_chain' => true,
        'verify_peer'             => true,
        'verify_peer_name'        => true,
        'allow_self_signed'       => false,
    ],
]);

$stream = @stream_socket_client(
    "ssl://{$fqdn}:{$port}",
    $errno,
    $errstr,
    10,
    STREAM_CLIENT_CONNECT,
    $context
);

if ($stream === false) {
    echo "Connection failed: $errstr\n";
    exit(1);
}

$meta = stream_get_meta_data($stream);
echo "=== Full meta data ===\n";
print_r($meta);

echo "\n=== Crypto data ===\n";
if (isset($meta['crypto'])) {
    print_r($meta['crypto']);
} else {
    echo "No crypto data available\n";
}

$params = stream_context_get_params($stream);
if (isset($params['options']['ssl']['peer_certificate'])) {
    $certInfo = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    echo "\n=== Certificate info ===\n";
    echo "Subject: " . ($certInfo['subject']['CN'] ?? 'N/A') . "\n";
    echo "Issuer: " . ($certInfo['issuer']['CN'] ?? 'N/A') . "\n";
    echo "Valid to: " . date('Y-m-d H:i:s', $certInfo['validTo_time_t']) . "\n";
}

fclose($stream);
