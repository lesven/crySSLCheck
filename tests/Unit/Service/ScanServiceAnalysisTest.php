<?php

namespace App\Tests\Unit\Service;

use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Repository\FindingRepository;
use App\Repository\ScanRunRepository;
use App\Service\MailService;
use App\Service\ScanService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ScanService::class)]
class ScanServiceAnalysisTest extends TestCase
{
    private ScanService $service;

    protected function setUp(): void
    {
        // All dependencies are irrelevant for pure analysis-method tests, so
        // stubs (no expectations) are the correct choice here.
        $this->service = new ScanService(
            entityManager: $this->createStub(EntityManagerInterface::class),
            domainRepository: $this->createStub(DomainRepository::class),
            findingRepository: $this->createStub(FindingRepository::class),
            scanRunRepository: $this->createStub(ScanRunRepository::class),
            mailService: $this->createStub(MailService::class),
            logger: new NullLogger(),
            scanTimeout: 5,
            retryDelay: 0,
            retryCount: 0,
            notifyOnUnreachable: false,
            minRsaKeyBits: 2048,
        );
    }

    // ── Helper: call private method via reflection ───────────────────────────

    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(ScanService::class, $method);
        return $ref->invoke($this->service, ...$args);
    }

    // ── computeDaysRemaining ─────────────────────────────────────────────────

    public function testComputeDaysRemainingReturnsNullWithoutValidTo(): void
    {
        $result = $this->callPrivate('computeDaysRemaining', []);
        $this->assertNull($result);
    }

    public function testComputeDaysRemainingReturnsPositiveForFutureDate(): void
    {
        $futureDate = (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
        $days = $this->callPrivate('computeDaysRemaining', ['valid_to' => $futureDate]);
        $this->assertIsInt($days);
        $this->assertGreaterThan(0, $days);
    }

    public function testComputeDaysRemainingReturnsNegativeForExpiredDate(): void
    {
        $pastDate = (new \DateTimeImmutable('-5 days'))->format('Y-m-d H:i:s');
        $days = $this->callPrivate('computeDaysRemaining', ['valid_to' => $pastDate]);
        $this->assertIsInt($days);
        $this->assertLessThan(0, $days);
    }

    // ── checkCertExpiry ──────────────────────────────────────────────────────

    public function testCheckCertExpiryReturnsNullWhenDaysRemainingIsNull(): void
    {
        $result = $this->callPrivate('checkCertExpiry', ['valid_to' => '2030-01-01 00:00:00'], null);
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

        $result = $this->callPrivate('checkCertExpiry', $certData, $daysRemaining);

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

        $result = $this->callPrivate('checkCertExpiry', $certData, 5);

        $this->assertSame('example.com', $result['details']['subject']);
        $this->assertSame('Let\'s Encrypt', $result['details']['issuer']);
    }

    // ── checkTlsVersion ──────────────────────────────────────────────────────

    #[DataProvider('insecureProtocolProvider')]
    public function testCheckTlsVersionDetectsInsecureProtocols(string $protocol): void
    {
        $result = $this->callPrivate('checkTlsVersion', ['protocol' => $protocol]);

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
        $result = $this->callPrivate('checkTlsVersion', ['protocol' => $protocol]);
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
        $result = $this->callPrivate('checkTlsVersion', []);
        $this->assertNull($result);
    }

    // ── checkChainError ──────────────────────────────────────────────────────

    public function testCheckChainErrorReturnsNullWhenNoError(): void
    {
        $result = $this->callPrivate('checkChainError', []);
        $this->assertNull($result);
    }

    public function testCheckChainErrorReturnsNullWhenChainErrorIsEmpty(): void
    {
        $result = $this->callPrivate('checkChainError', ['chain_error' => '']);
        $this->assertNull($result);
    }

    public function testCheckChainErrorReturnsFindingWhenErrorPresent(): void
    {
        $result = $this->callPrivate('checkChainError', ['chain_error' => 'self signed certificate']);

        $this->assertNotNull($result);
        $this->assertSame('CHAIN_ERROR', $result['finding_type']);
        $this->assertSame('high', $result['severity']);
        $this->assertSame('self signed certificate', $result['details']['error']);
    }

    // ── checkRsaKeyLength ────────────────────────────────────────────────────

    public function testCheckRsaKeyLengthReturnsNullWhenNoKeyInfo(): void
    {
        $result = $this->callPrivate('checkRsaKeyLength', []);
        $this->assertNull($result);
    }

    public function testCheckRsaKeyLengthIgnoresNonRsaKeys(): void
    {
        $result = $this->callPrivate('checkRsaKeyLength', [
            'public_key_type' => 'EC',
            'public_key_bits' => 256,
        ]);
        $this->assertNull($result);
    }

    public function testCheckRsaKeyLengthReturnsNullForSufficientKeyLength(): void
    {
        $result = $this->callPrivate('checkRsaKeyLength', [
            'public_key_type' => 'RSA',
            'public_key_bits' => 2048,
        ]);
        $this->assertNull($result);
    }

    public function testCheckRsaKeyLengthReturnsNullForLargeKey(): void
    {
        $result = $this->callPrivate('checkRsaKeyLength', [
            'public_key_type' => 'RSA',
            'public_key_bits' => 4096,
        ]);
        $this->assertNull($result);
    }

    public function testCheckRsaKeyLengthReturnsHighSeverityFor1024BitKey(): void
    {
        $result = $this->callPrivate('checkRsaKeyLength', [
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
        $result = $this->callPrivate('checkRsaKeyLength', [
            'public_key_type' => 'RSA',
            'public_key_bits' => 512,
        ]);

        $this->assertNotNull($result);
        $this->assertSame('RSA_KEY_LENGTH', $result['finding_type']);
        $this->assertSame('critical', $result['severity']);
    }

    public function testCheckRsaKeyLengthIsCaseInsensitiveForKeyType(): void
    {
        $resultLower = $this->callPrivate('checkRsaKeyLength', [
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

        $result = $this->callPrivate('buildOkFinding', $certData, 365);

        $this->assertSame('OK', $result['finding_type']);
        $this->assertSame('ok', $result['severity']);
        $this->assertSame('TLSv1.3', $result['details']['protocol']);
        $this->assertSame(365, $result['details']['days_remaining']);
    }

    public function testBuildOkFindingUsesUnknownFallbackForMissingFields(): void
    {
        $result = $this->callPrivate('buildOkFinding', [], null);

        $this->assertSame('OK', $result['finding_type']);
        $this->assertSame('unknown', $result['details']['protocol']);
        $this->assertNull($result['details']['days_remaining']);
    }

    // ── runSingleScan: disabled domain throws exception ───────────────────────

    public function testRunSingleScanThrowsExceptionForInactiveDomain(): void
    {
        $domain = new Domain();
        $domain->setFqdn('example.com');
        $domain->setPort(443);
        $domain->setStatus('inactive');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Deaktivierte/');

        $this->service->runSingleScan($domain);
    }
}
