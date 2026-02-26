<?php

namespace App\Tests\Unit\Service;

use App\Service\CertificateAnalyzer;
use App\ValueObject\ScanConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CertificateAnalyzer – the extracted analysis logic.
 * These tests were migrated from ScanServiceAnalysisTest and now test
 * the public methods directly (no more reflection needed).
 */
#[CoversClass(CertificateAnalyzer::class)]
class CertificateAnalyzerTest extends TestCase
{
    private CertificateAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new CertificateAnalyzer(
            config: new ScanConfiguration(minRsaKeyBits: 2048),
        );
    }

    // ── computeDaysRemaining ─────────────────────────────────────────────────

    public function testComputeDaysRemainingReturnsNullWithoutValidTo(): void
    {
        $result = $this->analyzer->computeDaysRemaining([]);
        $this->assertNull($result);
    }

    public function testComputeDaysRemainingReturnsPositiveForFutureDate(): void
    {
        $futureDate = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
        $days = $this->analyzer->computeDaysRemaining(['valid_to' => $futureDate]);
        $this->assertIsInt($days);
        $this->assertGreaterThan(0, $days);
    }

    public function testComputeDaysRemainingReturnsNegativeForExpiredDate(): void
    {
        $pastDate = (new \DateTimeImmutable('-5 days'))->format('Y-m-d H:i:s');
        $days = $this->analyzer->computeDaysRemaining(['valid_to' => $pastDate]);
        $this->assertIsInt($days);
        $this->assertLessThan(0, $days);
    }

    // ── checkCertExpiry ──────────────────────────────────────────────────────

    public function testCheckCertExpiryReturnsNullWhenDaysRemainingIsNull(): void
    {
        $result = $this->analyzer->checkCertExpiry(['valid_to' => '2030-01-01 00:00:00'], null);
        $this->assertNull($result);
    }

    #[DataProvider('certExpiryProvider')]
    public function testCheckCertExpiryMapsToCorrectSeverity(int $daysRemaining, ?string $expectedSeverity): void
    {
        $certData = [
            'valid_to' => '2030-01-01 00:00:00',
            'subject'  => 'CN=example.com',
            'issuer'   => 'CN=TestCA',
        ];

        $result = $this->analyzer->checkCertExpiry($certData, $daysRemaining);

        if ($expectedSeverity === null) {
            $this->assertNull($result, "Expected null for $daysRemaining days remaining");
        } else {
            $this->assertNotNull($result);
            $this->assertSame('CERT_EXPIRY', $result['finding_type']);
            $this->assertSame($expectedSeverity, $result['severity']);
            $this->assertSame($daysRemaining, $result['details']['days_remaining']);
        }
    }

    public static function certExpiryProvider(): array
    {
        return [
            'already expired (-1 day) → critical'    => [-1, 'critical'],
            'expires today (0 days) → high'          => [0, 'high'],
            'expired 5 days ago → critical'           => [-5, 'critical'],
            'expires in 1 day → high'                => [1, 'high'],
            'expires in 7 days → high'               => [7, 'high'],
            'expires in 8 days → medium'             => [8, 'medium'],
            'expires in 14 days → medium'            => [14, 'medium'],
            'expires in 15 days → low'               => [15, 'low'],
            'expires in 30 days → low'               => [30, 'low'],
            'expires in 31 days → no finding (null)' => [31, null],
            'expires in 90 days → no finding (null)' => [90, null],
        ];
    }

    public function testCheckCertExpiryIncludesSubjectAndIssuerInDetails(): void
    {
        $certData = [
            'valid_to' => '2030-01-01 00:00:00',
            'subject'  => 'example.com',
            'issuer'   => 'Let\'s Encrypt',
        ];

        $result = $this->analyzer->checkCertExpiry($certData, 5);

        $this->assertSame('example.com', $result['details']['subject']);
        $this->assertSame('Let\'s Encrypt', $result['details']['issuer']);
    }

    // ── checkTlsVersion ──────────────────────────────────────────────────────

    #[DataProvider('insecureProtocolProvider')]
    public function testCheckTlsVersionDetectsInsecureProtocols(string $protocol): void
    {
        $result = $this->analyzer->checkTlsVersion(['protocol' => $protocol]);

        $this->assertNotNull($result);
        $this->assertSame('TLS_VERSION', $result['finding_type']);
        $this->assertSame('high', $result['severity']);
        $this->assertSame($protocol, $result['details']['protocol']);
    }

    public static function insecureProtocolProvider(): array
    {
        return [
            'TLSv1'   => ['TLSv1'],
            'TLSv1.0' => ['TLSv1.0'],
            'TLSv1.1' => ['TLSv1.1'],
            'SSLv3'   => ['SSLv3'],
            'SSLv2'   => ['SSLv2'],
        ];
    }

    #[DataProvider('secureProtocolProvider')]
    public function testCheckTlsVersionAllowsSecureProtocols(string $protocol): void
    {
        $result = $this->analyzer->checkTlsVersion(['protocol' => $protocol]);
        $this->assertNull($result);
    }

    public static function secureProtocolProvider(): array
    {
        return [
            'TLSv1.2' => ['TLSv1.2'],
            'TLSv1.3' => ['TLSv1.3'],
        ];
    }

    public function testCheckTlsVersionReturnsNullWhenProtocolNotSet(): void
    {
        $result = $this->analyzer->checkTlsVersion([]);
        $this->assertNull($result);
    }

    // ── checkChainError ──────────────────────────────────────────────────────

    public function testCheckChainErrorReturnsNullWhenNoError(): void
    {
        $result = $this->analyzer->checkChainError([]);
        $this->assertNull($result);
    }

    public function testCheckChainErrorReturnsNullWhenChainErrorIsEmpty(): void
    {
        $result = $this->analyzer->checkChainError(['chain_error' => '']);
        $this->assertNull($result);
    }

    public function testCheckChainErrorReturnsFindingWhenErrorPresent(): void
    {
        $result = $this->analyzer->checkChainError(['chain_error' => 'self signed certificate']);

        $this->assertNotNull($result);
        $this->assertSame('CHAIN_ERROR', $result['finding_type']);
        $this->assertSame('high', $result['severity']);
        $this->assertSame('self signed certificate', $result['details']['error']);
    }

    // ── checkRsaKeyLength ────────────────────────────────────────────────────

    public function testCheckRsaKeyLengthReturnsNullWhenNoKeyInfo(): void
    {
        $result = $this->analyzer->checkRsaKeyLength([]);
        $this->assertNull($result);
    }

    public function testCheckRsaKeyLengthIgnoresNonRsaKeys(): void
    {
        $result = $this->analyzer->checkRsaKeyLength([
            'public_key_type' => 'EC',
            'public_key_bits' => 256,
        ]);
        $this->assertNull($result);
    }

    public function testCheckRsaKeyLengthReturnsNullForSufficientKeyLength(): void
    {
        $result = $this->analyzer->checkRsaKeyLength([
            'public_key_type' => 'RSA',
            'public_key_bits' => 2048,
        ]);
        $this->assertNull($result);
    }

    public function testCheckRsaKeyLengthReturnsNullForLargeKey(): void
    {
        $result = $this->analyzer->checkRsaKeyLength([
            'public_key_type' => 'RSA',
            'public_key_bits' => 4096,
        ]);
        $this->assertNull($result);
    }

    public function testCheckRsaKeyLengthReturnsHighSeverityFor1024BitKey(): void
    {
        $result = $this->analyzer->checkRsaKeyLength([
            'public_key_type' => 'RSA',
            'public_key_bits' => 1024,
        ]);

        $this->assertNotNull($result);
        $this->assertSame('RSA_KEY_LENGTH', $result['finding_type']);
        $this->assertSame('high', $result['severity']);
        $this->assertSame(1024, $result['details']['key_bits']);
    }

    public function testCheckRsaKeyLengthReturnsCriticalSeverityForVeryShortKey(): void
    {
        $result = $this->analyzer->checkRsaKeyLength([
            'public_key_type' => 'RSA',
            'public_key_bits' => 512,
        ]);

        $this->assertNotNull($result);
        $this->assertSame('RSA_KEY_LENGTH', $result['finding_type']);
        $this->assertSame('critical', $result['severity']);
    }

    public function testCheckRsaKeyLengthIsCaseInsensitiveForKeyType(): void
    {
        $resultLower = $this->analyzer->checkRsaKeyLength([
            'public_key_type' => 'rsa',
            'public_key_bits' => 1024,
        ]);

        $this->assertNotNull($resultLower);
        $this->assertSame('RSA_KEY_LENGTH', $resultLower['finding_type']);
    }

    // ── buildOkFinding ───────────────────────────────────────────────────────

    public function testBuildOkFindingReturnsOkFinding(): void
    {
        $certData = [
            'protocol'       => 'TLSv1.3',
            'cipher_name'    => 'TLS_AES_256_GCM_SHA384',
            'cipher_bits'    => 256,
            'cipher_version' => 'TLSv1.3',
            'valid_to'       => '2026-12-31 00:00:00',
            'valid_from'     => '2025-01-01 00:00:00',
            'subject'        => 'example.com',
            'issuer'         => 'Let\'s Encrypt',
            'public_key_type'=> 'RSA',
            'public_key_bits'=> 2048,
        ];

        $result = $this->analyzer->buildOkFinding($certData, 365);

        $this->assertSame('OK', $result['finding_type']);
        $this->assertSame('ok', $result['severity']);
        $this->assertSame('TLSv1.3', $result['details']['protocol']);
        $this->assertSame(365, $result['details']['days_remaining']);
    }

    public function testBuildOkFindingUsesUnknownFallbackForMissingFields(): void
    {
        $result = $this->analyzer->buildOkFinding([], null);

        $this->assertSame('OK', $result['finding_type']);
        $this->assertSame('unknown', $result['details']['protocol']);
        $this->assertNull($result['details']['days_remaining']);
    }

    // ── analyze (integration of all checks) ──────────────────────────────────

    public function testAnalyzeReturnsOkWhenNothingWrong(): void
    {
        $result = [
            'valid_to'        => (new \DateTimeImmutable('+90 days'))->format('Y-m-d H:i:s'),
            'valid_from'      => (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s'),
            'protocol'        => 'TLSv1.3',
            'cipher_name'     => 'TLS_AES_256_GCM_SHA384',
            'subject'         => 'example.com',
            'issuer'          => 'Let\'s Encrypt',
            'public_key_type' => 'RSA',
            'public_key_bits' => 4096,
        ];

        $findings = $this->analyzer->analyze($result);

        $this->assertCount(1, $findings);
        $this->assertSame('OK', $findings[0]['finding_type']);
    }

    public function testAnalyzeReturnsMultipleFindings(): void
    {
        $result = [
            'valid_to'        => (new \DateTimeImmutable('+5 days'))->format('Y-m-d H:i:s'),
            'protocol'        => 'TLSv1.0',
            'public_key_type' => 'RSA',
            'public_key_bits' => 1024,
        ];

        $findings = $this->analyzer->analyze($result);

        $types = array_column($findings, 'finding_type');
        $this->assertContains('CERT_EXPIRY', $types);
        $this->assertContains('TLS_VERSION', $types);
        $this->assertContains('RSA_KEY_LENGTH', $types);
        $this->assertNotContains('OK', $types);
    }

    public function testAnalyzeReturnsChainError(): void
    {
        $result = [
            'valid_to'    => (new \DateTimeImmutable('+90 days'))->format('Y-m-d H:i:s'),
            'protocol'    => 'TLSv1.3',
            'chain_error' => 'self signed certificate',
        ];

        $findings = $this->analyzer->analyze($result);

        $types = array_column($findings, 'finding_type');
        $this->assertContains('CHAIN_ERROR', $types);
        $this->assertNotContains('OK', $types);
    }
}
