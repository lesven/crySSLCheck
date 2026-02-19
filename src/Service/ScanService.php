<?php

namespace App\Service;

use App\Model\Domain;
use App\Model\Finding;
use App\Model\ScanRun;

class ScanService
{
    private static function log(string $message): void
    {
        $logFile = __DIR__ . '/../../logs/scan.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    }

    public static function scanDomain(array $domain): array
    {
        $fqdn = $domain['fqdn'];
        $port = (int) $domain['port'];
        $findings = [];

        $result = self::performTlsCheck($fqdn, $port);

        if ($result === null) {
            // Retry logic
            for ($retry = 1; $retry <= RETRY_COUNT; $retry++) {
                self::log("Retry $retry/" . RETRY_COUNT . " für {$fqdn}:{$port}");
                sleep(RETRY_DELAY);
                $result = self::performTlsCheck($fqdn, $port);
                if ($result !== null) {
                    break;
                }
            }
        }

        if ($result === null) {
            return [[
                'finding_type' => 'UNREACHABLE',
                'severity'     => 'low',
                'details'      => ['error' => "Host {$fqdn}:{$port} nicht erreichbar (Timeout)"],
            ]];
        }

        if (isset($result['error'])) {
            return [[
                'finding_type' => 'ERROR',
                'severity'     => 'low',
                'details'      => ['error' => $result['error']],
            ]];
        }

        // Check certificate expiry
        if (isset($result['valid_to'])) {
            $expiryDate = new \DateTime($result['valid_to']);
            $now = new \DateTime();
            $daysRemaining = (int) $now->diff($expiryDate)->format('%r%a');

            if ($daysRemaining < 0) {
                $findings[] = [
                    'finding_type' => 'CERT_EXPIRY',
                    'severity'     => 'critical',
                    'details'      => [
                        'expiry_date'    => $result['valid_to'],
                        'days_remaining' => $daysRemaining,
                        'subject'        => $result['subject'] ?? '',
                        'issuer'         => $result['issuer'] ?? '',
                    ],
                ];
            } elseif ($daysRemaining <= 7) {
                $findings[] = [
                    'finding_type' => 'CERT_EXPIRY',
                    'severity'     => 'high',
                    'details'      => [
                        'expiry_date'    => $result['valid_to'],
                        'days_remaining' => $daysRemaining,
                        'subject'        => $result['subject'] ?? '',
                        'issuer'         => $result['issuer'] ?? '',
                    ],
                ];
            } elseif ($daysRemaining <= 14) {
                $findings[] = [
                    'finding_type' => 'CERT_EXPIRY',
                    'severity'     => 'medium',
                    'details'      => [
                        'expiry_date'    => $result['valid_to'],
                        'days_remaining' => $daysRemaining,
                        'subject'        => $result['subject'] ?? '',
                        'issuer'         => $result['issuer'] ?? '',
                    ],
                ];
            } elseif ($daysRemaining <= 30) {
                $findings[] = [
                    'finding_type' => 'CERT_EXPIRY',
                    'severity'     => 'low',
                    'details'      => [
                        'expiry_date'    => $result['valid_to'],
                        'days_remaining' => $daysRemaining,
                        'subject'        => $result['subject'] ?? '',
                        'issuer'         => $result['issuer'] ?? '',
                    ],
                ];
            }
        }

        // Check TLS version
        if (isset($result['protocol'])) {
            $insecureProtocols = ['TLSv1', 'TLSv1.0', 'TLSv1.1', 'SSLv3', 'SSLv2'];
            if (in_array($result['protocol'], $insecureProtocols)) {
                $findings[] = [
                    'finding_type' => 'TLS_VERSION',
                    'severity'     => 'high',
                    'details'      => [
                        'protocol' => $result['protocol'],
                        'message'  => "Unsichere TLS-Version: {$result['protocol']}",
                    ],
                ];
            }
        }

        // Check certificate chain
        if (!empty($result['chain_error'])) {
            $findings[] = [
                'finding_type' => 'CHAIN_ERROR',
                'severity'     => 'high',
                'details'      => [
                    'error' => $result['chain_error'],
                ],
            ];
        }

        // If no problems found, add OK finding
        if (empty($findings)) {
            $findings[] = [
                'finding_type' => 'OK',
                'severity'     => 'ok',
                'details'      => [
                    'protocol'        => $result['protocol'] ?? 'unknown',
                    'cipher_name'     => $result['cipher_name'] ?? 'unknown',
                    'cipher_bits'     => $result['cipher_bits'] ?? null,
                    'cipher_version'  => $result['cipher_version'] ?? null,
                    'valid_to'        => $result['valid_to'] ?? 'unknown',
                    'valid_from'      => $result['valid_from'] ?? 'unknown',
                    'days_remaining'  => $daysRemaining ?? null,
                    'subject'         => $result['subject'] ?? '',
                    'issuer'          => $result['issuer'] ?? '',
                ],
            ];
        }

        return $findings;
    }

    private static function performTlsCheck(string $fqdn, int $port): ?array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert'       => true,
                'capture_peer_cert_chain' => true,
                'verify_peer'             => true,
                'verify_peer_name'        => true,
                'allow_self_signed'       => false,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $result = [];

        try {
            $stream = @stream_socket_client(
                "ssl://{$fqdn}:{$port}",
                $errno,
                $errstr,
                SCAN_TIMEOUT,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($stream === false) {
                if ($errno === 0 || stripos($errstr, 'timed out') !== false) {
                    self::log("UNREACHABLE: {$fqdn}:{$port} - $errstr");
                    return null; // Triggers retry
                }

                // Try to get more details about the error
                $sslError = openssl_error_string();
                if ($sslError && stripos($sslError, 'certificate') !== false) {
                    // Certificate chain error but we can still get cert info
                    $result['chain_error'] = $errstr . ($sslError ? " ($sslError)" : '');

                    // Try again without verification to get cert details
                    $contextNoVerify = stream_context_create([
                        'ssl' => [
                            'capture_peer_cert' => true,
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                            'allow_self_signed' => true,
                        ],
                    ]);

                    $streamRetry = @stream_socket_client(
                        "ssl://{$fqdn}:{$port}",
                        $errno2,
                        $errstr2,
                        SCAN_TIMEOUT,
                        STREAM_CLIENT_CONNECT,
                        $contextNoVerify
                    );

                    if ($streamRetry !== false) {
                        $params = stream_context_get_params($streamRetry);
                        if (isset($params['options']['ssl']['peer_certificate'])) {
                            $certInfo = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
                            if ($certInfo) {
                                $result['valid_to'] = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
                                $result['valid_from'] = date('Y-m-d H:i:s', $certInfo['validFrom_time_t']);
                                $result['subject'] = $certInfo['subject']['CN'] ?? '';
                                $result['issuer'] = $certInfo['issuer']['CN'] ?? '';
                            }
                        }
                        $meta = stream_get_meta_data($streamRetry);
                        $result['protocol'] = $meta['crypto']['protocol'] ?? 'unknown';
                        $result['cipher_name'] = $meta['crypto']['cipher_name'] ?? 'unknown';
                        $result['cipher_bits'] = $meta['crypto']['cipher_bits'] ?? null;
                        $result['cipher_version'] = $meta['crypto']['cipher_version'] ?? null;
                        fclose($streamRetry);
                    }

                    return $result;
                }

                self::log("ERROR: {$fqdn}:{$port} - $errstr");
                return ['error' => $errstr . ($sslError ? " ($sslError)" : '')];
            }

            // Successful connection
            $params = stream_context_get_params($stream);
            $meta = stream_get_meta_data($stream);

            // Get protocol version
            $result['protocol'] = $meta['crypto']['protocol'] ?? 'unknown';
            
            // Get cipher suite
            $result['cipher_name'] = $meta['crypto']['cipher_name'] ?? 'unknown';
            $result['cipher_bits'] = $meta['crypto']['cipher_bits'] ?? null;
            $result['cipher_version'] = $meta['crypto']['cipher_version'] ?? null;

            // Parse certificate
            if (isset($params['options']['ssl']['peer_certificate'])) {
                $certInfo = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
                if ($certInfo) {
                    $result['valid_to'] = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
                    $result['valid_from'] = date('Y-m-d H:i:s', $certInfo['validFrom_time_t']);
                    $result['subject'] = $certInfo['subject']['CN'] ?? '';
                    $result['issuer'] = $certInfo['issuer']['CN'] ?? '';
                    $result['serial'] = $certInfo['serialNumberHex'] ?? '';
                }
            }

            fclose($stream);
            self::log("OK: {$fqdn}:{$port} - TLS check successful");
            return $result;

        } catch (\Exception $e) {
            self::log("EXCEPTION: {$fqdn}:{$port} - " . $e->getMessage());
            if (stripos($e->getMessage(), 'timed out') !== false) {
                return null;
            }
            return ['error' => $e->getMessage()];
        }
    }

    public static function runFullScan(): int
    {
        $domains = Domain::findActive();
        if (empty($domains)) {
            self::log("No active domains to scan.");
            return 0;
        }

        $runId = ScanRun::create();
        self::log("Scan run #$runId started with " . count($domains) . " domains.");

        $hasErrors = false;
        $allFailed = true;

        foreach ($domains as $domain) {
            try {
                $scanFindings = self::scanDomain($domain);

                foreach ($scanFindings as $finding) {
                    $isKnown = Finding::isKnownFinding($domain['id'], $finding['finding_type'], $runId);
                    $status = $isKnown ? 'known' : 'new';

                    Finding::create(
                        $domain['id'],
                        $runId,
                        $finding['finding_type'],
                        $finding['severity'],
                        $finding['details'],
                        $status
                    );

                    // Send email for new high/critical findings
                    if ($status === 'new' && in_array($finding['severity'], ['high', 'critical'])) {
                        if ($finding['finding_type'] !== 'UNREACHABLE' && $finding['finding_type'] !== 'ERROR') {
                            MailService::sendFindingAlert($domain, $finding);
                        } elseif (NOTIFY_ON_UNREACHABLE) {
                            MailService::sendFindingAlert($domain, $finding);
                        }
                    }
                }

                // Resolve previous findings that no longer appear
                $currentFindingTypes = array_column($scanFindings, 'finding_type');
                $previousFindings = Finding::findPreviousRunFindings($domain['id'], $runId);
                foreach ($previousFindings as $prevFinding) {
                    if (!in_array($prevFinding['finding_type'], $currentFindingTypes)) {
                        Finding::markResolved($prevFinding['id']);
                    }
                }

                $allFailed = false;
                $hasProblem = false;
                foreach ($scanFindings as $f) {
                    if (in_array($f['finding_type'], ['UNREACHABLE', 'ERROR'])) {
                        $hasErrors = true;
                        $hasProblem = true;
                    }
                }
            } catch (\Exception $e) {
                self::log("Error scanning {$domain['fqdn']}:{$domain['port']}: " . $e->getMessage());
                $hasErrors = true;
                Finding::create($domain['id'], $runId, 'ERROR', 'low', ['error' => $e->getMessage()], 'new');
            }
        }

        $status = $allFailed ? 'failed' : ($hasErrors ? 'partial' : 'success');
        ScanRun::finish($runId, $status);
        self::log("Scan run #$runId finished with status: $status");

        return $runId;
    }

    public static function runSingleScan(int $domainId): array
    {
        $domain = Domain::findById($domainId);
        if (!$domain) {
            throw new \RuntimeException("Domain nicht gefunden.");
        }
        if ($domain['status'] !== 'active') {
            throw new \RuntimeException("Deaktivierte Domains können nicht gescannt werden.");
        }

        $runId = ScanRun::create();
        $scanFindings = self::scanDomain($domain);
        $results = [];

        foreach ($scanFindings as $finding) {
            $isKnown = Finding::isKnownFinding($domain['id'], $finding['finding_type'], $runId);
            $status = $isKnown ? 'known' : 'new';

            $findingId = Finding::create(
                $domain['id'],
                $runId,
                $finding['finding_type'],
                $finding['severity'],
                $finding['details'],
                $status
            );

            if ($status === 'new' && in_array($finding['severity'], ['high', 'critical'])) {
                if ($finding['finding_type'] !== 'UNREACHABLE' && $finding['finding_type'] !== 'ERROR') {
                    MailService::sendFindingAlert($domain, $finding);
                } elseif (NOTIFY_ON_UNREACHABLE) {
                    MailService::sendFindingAlert($domain, $finding);
                }
            }

            $results[] = array_merge($finding, ['id' => $findingId, 'status' => $status]);
        }

        // Resolve previous findings
        $currentFindingTypes = array_column($scanFindings, 'finding_type');
        $previousFindings = Finding::findPreviousRunFindings($domain['id'], $runId);
        foreach ($previousFindings as $prevFinding) {
            if (!in_array($prevFinding['finding_type'], $currentFindingTypes)) {
                Finding::markResolved($prevFinding['id']);
            }
        }

        $hasErrors = false;
        foreach ($scanFindings as $f) {
            if (in_array($f['finding_type'], ['UNREACHABLE', 'ERROR'])) {
                $hasErrors = true;
            }
        }
        ScanRun::finish($runId, $hasErrors ? 'partial' : 'success');

        return $results;
    }
}
