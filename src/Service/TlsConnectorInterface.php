<?php

namespace App\Service;

/**
 * Abstraction for TLS/SSL socket connections.
 *
 * Enables unit-testing of ScanService without real network I/O.
 */
interface TlsConnectorInterface
{
    /**
     * Open a TLS connection and extract certificate / stream metadata.
     *
     * @return array<string, mixed>|null
     *   - null  → host unreachable (timeout)
     *   - ['connection_refused' => true, 'error' => string] → TCP connection refused; caller should retry
     *   - ['error' => string] → non-recoverable TLS/SSL error
     *   - otherwise → associative array with cert & stream data
     */
    public function connect(string $fqdn, int $port, int $timeout): ?array;
}
