<?php

namespace App\Tests\Unit\Service;

use App\Repository\DomainRepository;
use App\Service\ValidationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ValidationService::class)]
class ValidationServiceTest extends TestCase
{
    private MockObject&DomainRepository $domainRepository;
    private ValidationService $service;

    protected function setUp(): void
    {
        $this->domainRepository = $this->createMock(DomainRepository::class);
        $this->service = new ValidationService($this->domainRepository, allowIpAddresses: true);
    }

    // ── isValidFqdn ─────────────────────────────────────────────────────────

    #[DataProvider('validFqdnProvider')]
    public function testIsValidFqdnAcceptsValidValues(string $fqdn): void
    {
        $this->assertTrue($this->service->isValidFqdn($fqdn));
    }

    public static function validFqdnProvider(): array
    {
        return [
            'simple domain'          => ['example.com'],
            'subdomain'              => ['sub.example.com'],
            'deep subdomain'         => ['a.b.c.example.org'],
            'domain with hyphen'     => ['my-server.example.com'],
            'country TLD'            => ['example.de'],
            'two-char TLD'           => ['example.io'],
        ];
    }

    #[DataProvider('validIpProvider')]
    public function testIsValidFqdnAcceptsIpAddressesWhenAllowed(string $ip): void
    {
        $this->assertTrue($this->service->isValidFqdn($ip));
    }

    public static function validIpProvider(): array
    {
        return [
            'IPv4'          => ['192.168.1.1'],
            'localhost IP'  => ['127.0.0.1'],
            'public IPv4'   => ['8.8.8.8'],
        ];
    }

    public function testIsValidFqdnRejectsIpWhenNotAllowed(): void
    {
        $serviceNoIp = new ValidationService($this->domainRepository, allowIpAddresses: false);
        $this->assertFalse($serviceNoIp->isValidFqdn('192.168.1.1'));
    }

    #[DataProvider('invalidFqdnProvider')]
    public function testIsValidFqdnRejectsInvalidValues(string $fqdn): void
    {
        $this->assertFalse($this->service->isValidFqdn($fqdn));
    }

    public static function invalidFqdnProvider(): array
    {
        return [
            'empty string'              => [''],
            'no TLD'                    => ['localhost'],
            'only TLD'                  => ['.com'],
            'leading dot'               => ['.example.com'],
            'trailing dot'              => ['example.com.'],
            'single-char TLD'           => ['example.c'],
            'spaces'                    => ['exa mple.com'],
            'special chars'             => ['exam@ple.com'],
            'double dot'                => ['exam..ple.com'],
        ];
    }

    // ── validateDomain ───────────────────────────────────────────────────────

    public function testValidateDomainReturnsNoErrorsForValidInput(): void
    {
        $this->domainRepository->method('isDuplicate')->willReturn(false);

        $errors = $this->service->validateDomain('example.com', 443);
        $this->assertEmpty($errors);
    }

    public function testValidateDomainRequiresFqdn(): void
    {
        $errors = $this->service->validateDomain('', 443);
        $this->assertArrayHasKey('fqdn', $errors);
        $this->assertStringContainsString('Pflichtfeld', $errors['fqdn']);
    }

    public function testValidateDomainRejectsInvalidFqdn(): void
    {
        $errors = $this->service->validateDomain('not_a_valid_domain', 443);
        $this->assertArrayHasKey('fqdn', $errors);
    }

    #[DataProvider('invalidPortProvider')]
    public function testValidateDomainRejectsInvalidPort(int $port): void
    {
        $this->domainRepository->method('isDuplicate')->willReturn(false);

        $errors = $this->service->validateDomain('example.com', $port);
        $this->assertArrayHasKey('port', $errors);
    }

    public static function invalidPortProvider(): array
    {
        return [
            'zero port'         => [0],
            'negative port'     => [-1],
            'too large port'    => [65536],
            'way too large'     => [99999],
        ];
    }

    public function testValidateDomainAcceptsBoundaryPorts(): void
    {
        $this->domainRepository->method('isDuplicate')->willReturn(false);

        $errorsMin = $this->service->validateDomain('example.com', 1);
        $errorsMax = $this->service->validateDomain('example.com', 65535);

        $this->assertArrayNotHasKey('port', $errorsMin);
        $this->assertArrayNotHasKey('port', $errorsMax);
    }

    public function testValidateDomainRejectsDuplicateCombination(): void
    {
        $this->domainRepository->method('isDuplicate')->willReturn(true);

        $errors = $this->service->validateDomain('example.com', 443);
        $this->assertArrayHasKey('fqdn', $errors);
        $this->assertStringContainsString('existiert bereits', $errors['fqdn']);
    }

    public function testValidateDomainPassesExcludeIdToDuplicateCheck(): void
    {
        $this->domainRepository
            ->expects($this->once())
            ->method('isDuplicate')
            ->with('example.com', 443, 42)
            ->willReturn(false);

        $errors = $this->service->validateDomain('example.com', 443, excludeId: 42);
        $this->assertEmpty($errors);
    }

    public function testValidateDomainSkipsDuplicateCheckWhenFqdnIsInvalid(): void
    {
        $this->domainRepository->expects($this->never())->method('isDuplicate');

        $this->service->validateDomain('', 443);
    }

    // ── validateDomainForImport ──────────────────────────────────────────────

    public function testValidateDomainForImportReturnsEmptyArrayForValidInput(): void
    {
        $errors = $this->service->validateDomainForImport('example.com', 443);
        $this->assertEmpty($errors);
    }

    public function testValidateDomainForImportRequiresFqdn(): void
    {
        $errors = $this->service->validateDomainForImport('', 443);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Pflichtfeld', $errors[0]);
    }

    public function testValidateDomainForImportRejectsInvalidFqdn(): void
    {
        $errors = $this->service->validateDomainForImport('invalid_fqdn', 443);
        $this->assertNotEmpty($errors);
    }

    public function testValidateDomainForImportRejectsInvalidPort(): void
    {
        $errors = $this->service->validateDomainForImport('example.com', 0);
        $this->assertNotEmpty($errors);
    }

    public function testValidateDomainForImportCanReturnMultipleErrors(): void
    {
        $errors = $this->service->validateDomainForImport('', 0);
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    public function testValidateDomainForImportNoDuplicateCheckPerformed(): void
    {
        // DomainRepository should never be called for import validation
        $this->domainRepository->expects($this->never())->method('isDuplicate');

        $this->service->validateDomainForImport('example.com', 443);
    }

    // ── validatePasswordStrength ─────────────────────────────────────────────

    public function testValidPasswordReturnsNoErrors(): void
    {
        $errors = $this->service->validatePasswordStrength('SecurePass1!');
        $this->assertEmpty($errors);
    }

    public function testPasswordTooShortIsRejected(): void
    {
        $errors = $this->service->validatePasswordStrength('Short1!');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('12 Zeichen', $errors[0]);
    }

    public function testPasswordWithoutUppercaseIsRejected(): void
    {
        $errors = $this->service->validatePasswordStrength('nouppercase1!xxx');
        $errorMessages = implode(' ', $errors);
        $this->assertStringContainsString('Großbuchstaben', $errorMessages);
    }

    public function testPasswordWithoutLowercaseIsRejected(): void
    {
        $errors = $this->service->validatePasswordStrength('NOLOWERCASE1!XXX');
        $errorMessages = implode(' ', $errors);
        $this->assertStringContainsString('Kleinbuchstaben', $errorMessages);
    }

    public function testPasswordWithoutDigitIsRejected(): void
    {
        $errors = $this->service->validatePasswordStrength('NoDigitPassword!');
        $errorMessages = implode(' ', $errors);
        $this->assertStringContainsString('Ziffer', $errorMessages);
    }

    public function testPasswordWithoutSpecialCharIsRejected(): void
    {
        $errors = $this->service->validatePasswordStrength('NoSpecialChar12');
        $errorMessages = implode(' ', $errors);
        $this->assertStringContainsString('Sonderzeichen', $errorMessages);
    }

    public function testPasswordAtExactMinLengthIsAccepted(): void
    {
        // exactly 12 chars: uppercase, lowercase, digit, special
        $errors = $this->service->validatePasswordStrength('SecureP@ss1!');
        $this->assertEmpty($errors);
    }

    public function testPasswordWith11CharsIsRejectedForLength(): void
    {
        $errors = $this->service->validatePasswordStrength('SecureP@ss1');
        $lengthErrors = array_filter($errors, fn($e) => str_contains($e, '12 Zeichen'));
        $this->assertNotEmpty($lengthErrors);
    }

    public function testWeakPasswordCanHaveMultipleErrors(): void
    {
        // short, no uppercase, no digit, no special char
        $errors = $this->service->validatePasswordStrength('abc');
        $this->assertGreaterThan(1, count($errors));
    }
}
