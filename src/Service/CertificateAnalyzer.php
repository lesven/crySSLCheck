<?php

namespace App\Service;

use App\Enum\FindingType;
use App\Enum\Severity;
use App\ValueObject\ScanConfiguration;

/**
 * Pure analysis logic extracted from ScanService.
 * Given raw TLS check results, determines which findings (if any) to report.
 *
 * All methods are deterministic and have no side effects – easily unit-testable.
 */
class CertificateAnalyzer
{
    public function __construct(
        private readonly ScanConfiguration $config = new ScanConfiguration(),
    ) {
    }

    /**
     * Analyzes raw TLS result data and returns an array of finding arrays.
     *
     * @param array $result Raw TLS check result from TlsConnector
     * @return array<array{finding_type: string, severity: string, details: array}>
     */
    public function analyze(array $result): array
    {
        $daysRemaining = $this->computeDaysRemaining($result);

        $findings = array_values(array_filter([
            $this->checkCertExpiry($result, $daysRemaining),
            $this->checkTlsVersion($result),
            $this->checkChainError($result),
            $this->checkRsaKeyLength($result),
        ]));

        if (empty($findings)) {
            $findings[] = $this->buildOkFinding($result, $daysRemaining);
        }

        return $findings;
    }

    public function computeDaysRemaining(array $result): ?int
    {
        if (!isset($result['valid_to'])) {
            return null;
        }

        $expiryDate = new \DateTimeImmutable($result['valid_to']);
        $now = new \DateTimeImmutable();

        return (int) $now->diff($expiryDate)->format('%r%a');
    }

    public function checkCertExpiry(array $result, ?int $daysRemaining): ?array
    {
        if ($daysRemaining === null) {
            return null;
        }

        $severity = match (true) {
            $daysRemaining < 0  => Severity::Critical,
            $daysRemaining <= 7  => Severity::High,
            $daysRemaining <= 14 => Severity::Medium,
            $daysRemaining <= 30 => Severity::Low,
            default              => null,
        };

        if ($severity === null) {
            return null;
        }

        return [
            'finding_type' => FindingType::CertExpiry->value,
            'severity'     => $severity->value,
            'details'      => [
                'expiry_date'    => $result['valid_to'],
                'days_remaining' => $daysRemaining,
                'subject'        => $result['subject'] ?? '',
                'issuer'         => $result['issuer'] ?? '',
            ],
        ];
    }

    public function checkTlsVersion(array $result): ?array
    {
        $insecureProtocols = ['TLSv1', 'TLSv1.0', 'TLSv1.1', 'SSLv3', 'SSLv2'];

        if (!isset($result['protocol']) || !in_array($result['protocol'], $insecureProtocols)) {
            return null;
        }

        return [
            'finding_type' => FindingType::TlsVersion->value,
            'severity'     => Severity::High->value,
            'details'      => [
                'protocol' => $result['protocol'],
                'message'  => "Unsichere TLS-Version: {$result['protocol']}",
            ],
        ];
    }

    public function checkChainError(array $result): ?array
    {
        if (empty($result['chain_error'])) {
            return null;
        }

        return [
            'finding_type' => FindingType::ChainError->value,
            'severity'     => Severity::High->value,
            'details'      => ['error' => $result['chain_error']],
        ];
    }

    public function checkRsaKeyLength(array $result): ?array
    {
        if (!isset($result['public_key_type']) || strtoupper($result['public_key_type']) !== 'RSA' || !isset($result['public_key_bits'])) {
            return null;
        }

        $bits = (int) $result['public_key_bits'];

        if ($bits >= $this->config->minRsaKeyBits) {
            return null;
        }

        return [
            'finding_type' => FindingType::RsaKeyLength->value,
            'severity'     => ($bits < 1024 ? Severity::Critical->value : Severity::High->value),
            'details'      => [
                'key_bits' => $bits,
                'message'  => "RSA-Schlüssellänge zu kurz: {$bits} bits (empfohlen >= {$this->config->minRsaKeyBits})",
            ],
        ];
    }

    public function buildOkFinding(array $result, ?int $daysRemaining): array
    {
        return [
            'finding_type' => FindingType::Ok->value,
            'severity'     => Severity::Ok->value,
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
}
