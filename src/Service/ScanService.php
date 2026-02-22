<?php

namespace App\Service;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScanRun;
use App\Repository\DomainRepository;
use App\Repository\FindingRepository;
use App\Repository\ScanRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ScanService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DomainRepository $domainRepository,
        private readonly FindingRepository $findingRepository,
        private readonly ScanRunRepository $scanRunRepository,
        private readonly MailService $mailService,
        private readonly LoggerInterface $logger,
        private readonly int $scanTimeout = 10,
        private readonly int $retryDelay = 5,
        private readonly int $retryCount = 1,
        private readonly bool $notifyOnUnreachable = false,
        private readonly int $minRsaKeyBits = 2048,
    ) {
    }

    public function runFullScan(): ScanRun
    {
        $domains = $this->domainRepository->findActive();

        $scanRun = new ScanRun();
        $this->entityManager->persist($scanRun);
        $this->entityManager->flush();

        if (empty($domains)) {
            $this->logger->info('Keine aktiven Domains zu scannen.');
            $scanRun->finish('success');
            $this->entityManager->flush();
            return $scanRun;
        }

        $this->logger->info("Scan-Run #{$scanRun->getId()} gestartet mit " . count($domains) . " Domains.");

        $hasErrors = false;
        $allFailed = true;

        foreach ($domains as $domain) {
            try {
                $scanFindings = $this->scanDomain($domain);
                $this->persistFindings($domain, $scanRun, $scanFindings);

                $allFailed = false;
                foreach ($scanFindings as $f) {
                    if (in_array($f['finding_type'], ['UNREACHABLE', 'ERROR'])) {
                        $hasErrors = true;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error("Fehler beim Scannen von {$domain->getFqdn()}:{$domain->getPort()}: " . $e->getMessage());
                $hasErrors = true;

                $finding = new Finding();
                $finding->setDomain($domain);
                $finding->setScanRun($scanRun);
                $finding->setFindingType('ERROR');
                $finding->setSeverity('low');
                $finding->setDetails(['error' => $e->getMessage()]);
                $finding->setStatus('new');
                $this->entityManager->persist($finding);
                $this->entityManager->flush();
            }
        }

        $status = $allFailed ? 'failed' : ($hasErrors ? 'partial' : 'success');
        $scanRun->finish($status);
        $this->entityManager->flush();

        $this->logger->info("Scan-Run #{$scanRun->getId()} beendet mit Status: {$status}");

        return $scanRun;
    }

    public function runSingleScan(Domain $domain): array
    {
        if (!$domain->isActive()) {
            throw new \RuntimeException('Deaktivierte Domains können nicht gescannt werden.');
        }

        $scanRun = new ScanRun();
        $this->entityManager->persist($scanRun);
        $this->entityManager->flush();

        $scanFindings = $this->scanDomain($domain);
        $findings = $this->persistFindings($domain, $scanRun, $scanFindings);

        $hasErrors = false;
        foreach ($scanFindings as $f) {
            if (in_array($f['finding_type'], ['UNREACHABLE', 'ERROR'])) {
                $hasErrors = true;
            }
        }

        $scanRun->finish($hasErrors ? 'partial' : 'success');
        $this->entityManager->flush();

        return $findings;
    }

    /**
     * @return array<array{finding_type: string, severity: string, details: array}>
     */
    public function scanDomain(Domain $domain): array
    {
        $fqdn = $domain->getFqdn();
        $port = $domain->getPort();
        $findings = [];

        $result = $this->performTlsCheck($fqdn, $port);

        if ($result === null) {
            for ($retry = 1; $retry <= $this->retryCount; $retry++) {
                $this->logger->info("Retry {$retry}/{$this->retryCount} für {$fqdn}:{$port}");
                sleep($this->retryDelay);
                $result = $this->performTlsCheck($fqdn, $port);
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

        // Certificate expiry check
        $daysRemaining = null;
        if (isset($result['valid_to'])) {
            $expiryDate = new \DateTimeImmutable($result['valid_to']);
            $now = new \DateTimeImmutable();
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

        // TLS version check
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

        // Certificate chain check
        if (!empty($result['chain_error'])) {
            $findings[] = [
                'finding_type' => 'CHAIN_ERROR',
                'severity'     => 'high',
                'details'      => ['error' => $result['chain_error']],
            ];
        }

        // RSA key length check
        if (isset($result['public_key_type']) && strtoupper($result['public_key_type']) === 'RSA' && isset($result['public_key_bits'])) {
            $bits = (int) $result['public_key_bits'];
            if ($bits < $this->minRsaKeyBits) {
                $findings[] = [
                    'finding_type' => 'RSA_KEY_LENGTH',
                    'severity'     => ($bits < 1024 ? 'critical' : 'high'),
                    'details'      => [
                        'key_bits' => $bits,
                        'message'  => "RSA-Schlüssellänge zu kurz: {$bits} bits (empfohlen >= {$this->minRsaKeyBits})",
                    ],
                ];
            }
        }

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
                    'days_remaining'  => $daysRemaining,
                    'subject'         => $result['subject'] ?? '',
                    'issuer'          => $result['issuer'] ?? '',
                    'public_key_type' => $result['public_key_type'] ?? null,
                    'public_key_bits' => $result['public_key_bits'] ?? null,
                ],
            ];
        }

        return $findings;
    }

    /**
     * @param array<array{finding_type: string, severity: string, details: array}> $scanFindings
     * @return array<array{finding_type: string, severity: string, details: array, id: int, status: string}>
     */
    private function persistFindings(Domain $domain, ScanRun $scanRun, array $scanFindings): array
    {
        $results = [];

        foreach ($scanFindings as $rawFinding) {
            $isKnown = $this->findingRepository->isKnownFinding(
                $domain->getId(),
                $rawFinding['finding_type'],
                $scanRun->getId()
            );
            $status = $isKnown ? 'known' : 'new';

            $finding = new Finding();
            $finding->setDomain($domain);
            $finding->setScanRun($scanRun);
            $finding->setFindingType($rawFinding['finding_type']);
            $finding->setSeverity($rawFinding['severity']);
            $finding->setDetails($rawFinding['details']);
            $finding->setStatus($status);
            $this->entityManager->persist($finding);

            $debugSubject = sprintf('[TLS Monitor] %s – %s für %s:%d',
                $rawFinding['severity'],
                $rawFinding['finding_type'],
                $domain->getFqdn(),
                $domain->getPort(),
            );

            $isUnreachableType = in_array($rawFinding['finding_type'], ['UNREACHABLE', 'ERROR']);
            $severityQualifies = in_array($rawFinding['severity'], ['high', 'critical'])
                || ($isUnreachableType && $this->notifyOnUnreachable);

            if ($status !== 'new') {
                $this->mailService->recordSkipped($debugSubject, 'Finding bereits bekannt (status: ' . $status . ')');
            } elseif ($isUnreachableType && !$this->notifyOnUnreachable) {
                $this->mailService->recordSkipped($debugSubject, 'Typ ' . $rawFinding['finding_type'] . ' – Benachrichtigung deaktiviert (SCAN_NOTIFY_UNREACHABLE=false)');
            } elseif (!$severityQualifies) {
                $this->mailService->recordSkipped($debugSubject, 'Severity zu niedrig (' . $rawFinding['severity'] . ')');
            } else {
                $sent = $this->mailService->sendFindingAlert($domain, $finding);
                if (!$sent) {
                    $this->logger->warning('ScanService: SMTP-Alarm-Mail fehlgeschlagen', [
                        'domain'   => $domain->getFqdn(),
                        'port'     => $domain->getPort(),
                        'finding'  => $rawFinding['finding_type'],
                        'severity' => $rawFinding['severity'],
                    ]);
                }
            }

            $results[] = array_merge($rawFinding, ['status' => $status]);
        }

        // Resolve previous findings that no longer appear
        $currentFindingTypes = array_column($scanFindings, 'finding_type');
        $previousFindings = $this->findingRepository->findPreviousRunFindings($domain->getId(), $scanRun->getId());

        foreach ($previousFindings as $prevFinding) {
            if (!in_array($prevFinding->getFindingType(), $currentFindingTypes)) {
                $prevFinding->markResolved();
            }
        }

        $this->entityManager->flush();

        return $results;
    }

    private function performTlsCheck(string $fqdn, int $port): ?array
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
                $this->scanTimeout,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($stream === false) {
                if ($errno === 0 || stripos($errstr, 'timed out') !== false) {
                    $this->logger->info("UNREACHABLE: {$fqdn}:{$port} - {$errstr}");
                    return null;
                }

                $sslError = openssl_error_string();
                $combinedErrorMsg = $errstr . ($sslError ? ' ' . $sslError : '');
                $isCertError = stripos($combinedErrorMsg, 'certificate') !== false
                    || stripos($combinedErrorMsg, 'ssl') !== false
                    || stripos($combinedErrorMsg, 'self signed') !== false
                    || stripos($combinedErrorMsg, 'unknown ca') !== false;
                if ($isCertError) {
                    $result['chain_error'] = $errstr . ($sslError ? " ({$sslError})" : '');

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
                        $this->scanTimeout,
                        STREAM_CLIENT_CONNECT,
                        $contextNoVerify
                    );

                    if ($streamRetry !== false) {
                        $params = stream_context_get_params($streamRetry);
                        if (isset($params['options']['ssl']['peer_certificate'])) {
                            $certPem = $params['options']['ssl']['peer_certificate'];
                            $certInfo = openssl_x509_parse($certPem);
                            if ($certInfo) {
                                $result['valid_to'] = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
                                $result['valid_from'] = date('Y-m-d H:i:s', $certInfo['validFrom_time_t']);
                                $result['subject'] = $certInfo['subject']['CN'] ?? '';
                                $result['issuer'] = $certInfo['issuer']['CN'] ?? '';
                            }

                            $pubKey = @openssl_pkey_get_public($certPem);
                            if ($pubKey !== false) {
                                $keyDetails = openssl_pkey_get_details($pubKey);
                                if ($keyDetails && isset($keyDetails['bits'])) {
                                    $result['public_key_bits'] = $keyDetails['bits'];
                                    $types = [
                                        OPENSSL_KEYTYPE_RSA => 'RSA',
                                        OPENSSL_KEYTYPE_DSA => 'DSA',
                                        OPENSSL_KEYTYPE_DH  => 'DH',
                                        OPENSSL_KEYTYPE_EC  => 'EC',
                                    ];
                                    $result['public_key_type'] = $types[$keyDetails['type']] ?? 'UNKNOWN';
                                }
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

                $this->logger->info("ERROR: {$fqdn}:{$port} - {$errstr}");
                return ['error' => $errstr . ($sslError ? " ({$sslError})" : '')];
            }

            $params = stream_context_get_params($stream);
            $meta = stream_get_meta_data($stream);

            $result['protocol'] = $meta['crypto']['protocol'] ?? 'unknown';
            $result['cipher_name'] = $meta['crypto']['cipher_name'] ?? 'unknown';
            $result['cipher_bits'] = $meta['crypto']['cipher_bits'] ?? null;
            $result['cipher_version'] = $meta['crypto']['cipher_version'] ?? null;

            if (isset($params['options']['ssl']['peer_certificate'])) {
                $certPem = $params['options']['ssl']['peer_certificate'];
                $certInfo = openssl_x509_parse($certPem);
                if ($certInfo) {
                    $result['valid_to'] = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
                    $result['valid_from'] = date('Y-m-d H:i:s', $certInfo['validFrom_time_t']);
                    $result['subject'] = $certInfo['subject']['CN'] ?? '';
                    $result['issuer'] = $certInfo['issuer']['CN'] ?? '';
                    $result['serial'] = $certInfo['serialNumberHex'] ?? '';
                }

                $pubKey = false;
                if (is_resource($certPem) || is_object($certPem)) {
                    $pem = '';
                    if (openssl_x509_export($certPem, $pem)) {
                        $pubKey = @openssl_pkey_get_public($pem);
                    }
                } else {
                    $pubKey = @openssl_pkey_get_public($certPem);
                }

                if ($pubKey !== false) {
                    $keyDetails = openssl_pkey_get_details($pubKey);
                    if ($keyDetails && isset($keyDetails['bits'])) {
                        $result['public_key_bits'] = $keyDetails['bits'];
                        $types = [
                            OPENSSL_KEYTYPE_RSA => 'RSA',
                            OPENSSL_KEYTYPE_DSA => 'DSA',
                            OPENSSL_KEYTYPE_DH  => 'DH',
                            OPENSSL_KEYTYPE_EC  => 'EC',
                        ];
                        $result['public_key_type'] = $types[$keyDetails['type']] ?? 'UNKNOWN';
                    }
                }
            }

            fclose($stream);
            $this->logger->info("OK: {$fqdn}:{$port} - TLS-Check erfolgreich");
            return $result;

        } catch (\Throwable $e) {
            $this->logger->error("EXCEPTION: {$fqdn}:{$port} - " . $e->getMessage());
            if (stripos($e->getMessage(), 'timed out') !== false) {
                return null;
            }
            return ['error' => $e->getMessage()];
        }
    }
}
