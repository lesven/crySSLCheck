# Testing Patterns

**Analysis Date:** 2026-02-27

## Test Framework

**Runner:**
- PHPUnit 12.0+
- Config: `phpunit.dist.xml` at project root
- Strict mode: `failOnDeprecation="true"` and `failOnWarning="true"` enabled

**Assertion Library:**
- PHPUnit's built-in assertions (no additional library)

**Run Commands:**
```bash
make test                   # Run all tests (Unit + Integration)
make test-unit             # Run only Unit tests
make test-integration      # Run only Integration tests
make test-coverage         # Generate HTML coverage report (var/coverage/)
```

**Configuration Details from `phpunit.dist.xml`:**
- Bootstrap: `tests/bootstrap.php`
- Cache directory: `.phpunit.cache`
- APP_ENV forced to `test`
- Strict deprecation/notice/warning handling enabled

## Test File Organization

**Location:**
- Separate from source: Unit tests in `tests/Unit/`, Integration tests in `tests/Integration/`
- Mirror source structure: `src/Service/CertificateAnalyzer.php` → `tests/Unit/Service/CertificateAnalyzerTest.php`

**Naming:**
- Class name + `Test` suffix (e.g., `DomainTest`, `ScanServiceAnalysisTest`)
- Method prefix: `test` followed by what's being tested in PascalCase (e.g., `testComputeDaysRemainingReturnsNullWithoutValidTo()`)

**Structure:**
```
tests/
├── Unit/                                   # Pure unit tests (no framework, no DB)
│   ├── Command/                            # Command tests with mocks
│   ├── Entity/                             # Entity logic tests
│   ├── Service/                            # Service/business logic tests
│   ├── ValueObject/                        # Value object tests
│   └── Security/                           # Security-specific tests
├── Integration/                            # Requires Symfony kernel, database
│   ├── Command/                            # Command integration tests
│   ├── Controller/                         # Controller tests with full request/response
│   ├── Repository/                         # Repository persistence tests
│   ├── IntegrationTestCase.php             # Base class for integration tests
│   └── [feature folders]
└── bootstrap.php                           # PHPUnit bootstrap (Dotenv setup)
```

## Test Structure

**Suite Organization:**

Unit test example from `tests/Unit/Service/CertificateAnalyzerTest.php`:
```php
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
    // ... more related tests ...

    // ── checkCertExpiry ──────────────────────────────────────────────────────
    public function testCheckCertExpiryReturnsNullWhenDaysRemainingIsNull(): void
    {
        // ...
    }
}
```

**Patterns:**
- setUp(): Initialize SUT (System Under Test) and its dependencies
- No tearDown() unless cleanup needed (DB tests override)
- Each test method is independent and self-contained
- Section dividers organize related tests: `// ── methodName ────────────`

**Integration Test Base:**

From `tests/Integration/IntegrationTestCase.php`:
```php
abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($this->em);
        $metadata   = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        parent::tearDown();
    }
}
```
- Recreates schema for each test (clean slate)
- Drops schema after teardown

## Mocking

**Framework:** PHPUnit's native `createMock()` and `getMockBuilder()`

**Patterns:**

From `tests/Unit/Command/ScanDomainCommandTest.php`:
```php
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ScanDomainCommand::class)]
class ScanDomainCommandTest extends TestCase
{
    private MockObject&ScanService $scanService;

    protected function setUp(): void
    {
        $this->scanService = $this->createMock(ScanService::class);

        $this->scanService
            ->expects($this->once())
            ->method('scanDomainByFqdn')
            ->with('example.com', 443)
            ->willReturn($findings);

        // Execute and assert
    }
}
```

**What to Mock:**
- External services (e.g., `ScanService`, `TlsConnector` when testing commands)
- Framework interfaces when testing domain logic in isolation
- Repository dependencies in service tests (if integration not needed)

**What NOT to Mock:**
- Value Objects (they're data; test real instances)
- Entities in unit tests (test real instances unless testing behavior with mocks)
- Pure calculation methods (e.g., `CertificateAnalyzer::computeDaysRemaining()` - no mocks)

**Mock Expectations:**
- `expects($this->once())`: Call must happen exactly once
- `expects($this->any())`: Default if omitted
- `with(...)`: Assert arguments passed
- `willReturn(...)`: Mock return value
- `willThrowException(...)`: Mock exception

## Fixtures and Factories

**Test Data:**

Repository tests use factory methods:
```php
private function createDomain(string $fqdn, int $port = 443, string $status = 'active'): Domain
{
    $domain = new Domain();
    $domain->setFqdn($fqdn);
    $domain->setPort($port);
    $domain->setStatus($status);
    $this->em->persist($domain);

    return $domain;
}
```

Data Provider pattern for parameterized tests:
```php
#[DataProvider('certExpiryProvider')]
public function testCheckCertExpiryMapsToCorrectSeverity(int $daysRemaining, ?string $expectedSeverity): void
{
    // ... test logic ...
}

public static function certExpiryProvider(): array
{
    return [
        'already expired (-1 day) → critical'    => [-1, 'critical'],
        'expires today (0 days) → high'          => [0, 'high'],
        'expires in 31 days → no finding (null)' => [31, null],
    ];
}
```

**Location:**
- Embedded in test class as static methods returning arrays
- Use `#[DataProvider('methodName')]` attribute for parameterized tests
- No separate fixture files; inline data creation preferred

## Coverage

**Requirements:**
- No enforced target; PHPUnit configured to measure but not fail on coverage
- Coverage reports: run `make test-coverage` → generates `var/coverage/index.html`

**View Coverage:**
```bash
make test-coverage
# Then open var/coverage/index.html in browser
```

## Test Types

**Unit Tests:**
- Scope: Single class in isolation (or closely related utility methods)
- Approach: Mock external dependencies, test business logic deterministically
- Location: `tests/Unit/`
- Examples:
  - `tests/Unit/Service/CertificateAnalyzerTest.php`: Pure analysis logic, no DB
  - `tests/Unit/Entity/DomainTest.php`: Entity getters/setters and lifecycle callbacks
  - `tests/Unit/Command/ScanDomainCommandTest.php`: Command output with mocked service

**Integration Tests:**
- Scope: Class + its real dependencies (DB, repositories, services working together)
- Approach: Boot Symfony kernel, use real database schema, verify persistence
- Location: `tests/Integration/`
- Examples:
  - `tests/Integration/Repository/DomainRepositoryTest.php`: Real QueryBuilder, real schema
  - `tests/Integration/Controller/DomainControllerTest.php`: Real request/response cycle
  - `tests/Integration/Command/ScanDomainCommandTest.php`: Real command execution

**E2E Tests:**
- Not used; relying on integration tests for coverage

## Common Patterns

**Async Testing:**
Not applicable (PHP/Symfony synchronous).

**Error Testing:**

From `tests/Unit/Command/ScanDomainCommandTest.php`:
```php
public function testReturnsFailureOnException(): void
{
    $this->scanService
        ->method('scanDomainByFqdn')
        ->willThrowException(new \RuntimeException('TLS handshake failed'));

    $exitCode = $this->commandTester->execute([
        'fqdn' => 'broken.example.com',
        'port' => '443',
    ]);

    $this->assertSame(1, $exitCode);
}
```

**Repository Query Testing:**

From `tests/Integration/Repository/DomainRepositoryTest.php`:
```php
public function testFindActiveReturnsOnlyActiveDomains(): void
{
    $this->createDomain('active.example.com', 443, 'active');
    $this->createDomain('inactive.example.com', 443, 'inactive');
    $this->em->flush();

    $result = $this->repository->findActive();

    $this->assertCount(1, $result);
    $this->assertSame('active.example.com', $result[0]->getFqdn());
}
```

**Data Provider with Named Cases:**

From `tests/Unit/Service/CertificateAnalyzerTest.php`:
```php
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

#[DataProvider('insecureProtocolProvider')]
public function testCheckTlsVersionDetectsInsecureProtocols(string $protocol): void
{
    $result = $this->analyzer->checkTlsVersion(['protocol' => $protocol]);
    $this->assertNotNull($result);
    $this->assertSame('TLS_VERSION', $result['finding_type']);
}
```

**Assertion Patterns:**

Common assertions used:
- `assertSame($expected, $actual)` - strict equality (preferred)
- `assertEquals($expected, $actual)` - loose equality
- `assertNull($value)` - checks for null
- `assertNotNull($value)` - checks not null
- `assertCount($count, $array)` - array element count
- `assertContains($needle, $array)` - array contains value
- `assertTrue($condition)` / `assertFalse($condition)` - boolean checks
- `assertInstanceOf(Class::class, $object)` - type checking
- `assertIsArray($value)` - type check

**PHPUnit Attributes (PHP 8):**

From test files:
```php
#[CoversClass(CertificateAnalyzer::class)]              // Coverage declaration
#[AllowMockObjectsWithoutExpectations]                  // Suppress warning for unused mocks
#[DataProvider('certExpiryProvider')]                   // Parameterized test data
```

## Bootstrap and Environment

**Bootstrap file** (`tests/bootstrap.php`):
- Sets `APP_ENV=test` before loading `.env`
- Loads Dotenv to read `.env.test` configuration
- Sets error reporting to strict mode

**Test Environment Config** (`.env.test`):
- Contains test-specific settings (database URL for test DB)
- Not committed; gitignored

---

*Testing analysis: 2026-02-27*
