# Coding Conventions

**Analysis Date:** 2026-02-27

## Naming Patterns

**Files:**
- PHP classes: PascalCase (e.g., `Domain.php`, `ScanService.php`, `CertificateAnalyzer.php`)
- Commands: Verb-based (e.g., `ScanCommand.php`, `CreateUserCommand.php`)
- Repositories: Entity-based with `Repository` suffix (e.g., `DomainRepository.php`)
- Tests: Matching class name with `Test` suffix (e.g., `DomainTest.php`, `CertificateAnalyzerTest.php`)
- Enums: Singular descriptive names (e.g., `Severity.php`, `FindingType.php`, `DomainStatus.php`)

**Functions/Methods:**
- camelCase for all methods
- Getter methods: `get<PropertyName>()` (e.g., `getFqdn()`, `getStatus()`)
- Setter methods: `set<PropertyName>()` return fluent `static` for chaining (e.g., `setPort(int $port): static`)
- Boolean methods: `is<State>()` (e.g., `isActive()`, `isDuplicate()`)
- Action methods: verb-based (e.g., `analyze()`, `computeDaysRemaining()`, `toggleStatus()`)
- Lifecycle callbacks: `on<Hook>()` (e.g., `onPrePersist()`, `onPreUpdate()`)

**Variables:**
- camelCase throughout (e.g., `$scanRun`, `$findingPersister`, `$daysRemaining`)
- Private/protected properties: lowercase prefix `$` directly (e.g., `private int $port`)
- Constructor property promotion: common for readonly dependencies (e.g., `private readonly EntityManagerInterface $entityManager`)

**Types:**
- Enum cases: PascalCase (e.g., `Critical`, `Medium`, `Low` in `Severity` enum)
- Value Objects: readonly final classes (e.g., `ScanConfiguration`)
- Interface names: descriptive with `Interface` suffix (e.g., `TlsConnectorInterface`)

## Code Style

**Formatting:**
- PSR-12 compliant (Symfony style)
- Tool: PHPInsights configured with `symfony` preset in `phpinsights.php`
- Line endings: LF (Unix-style)
- Indentation: 4 spaces (no tabs)
- Max line length: ~120 characters (not strictly enforced but observed)

**Linting:**
- Tool: PHPStan v2.1+ at level 6
- Config: `phpstan.neon` at project root
- Baseline: `phpstan-baseline.neon` for known issues
- Symfony extension enabled: `phpstan/phpstan-symfony`
- Container XML cache path: `var/cache/dev/App_KernelDevDebugContainer.xml`
- Excluded paths: `src/Kernel.php`

**Declaration Statement:**
- All files start with `declare(strict_types=1);` after opening PHP tag
- Example from `src/Enum/Severity.php`:
  ```php
  <?php

  namespace App\Enum;

  declare(strict_types=1);
  ```

## Import Organization

**Order:**
1. Standard `use` declarations for namespaces/classes (framework first)
2. Domain/Entity classes
3. Service classes
4. Repository classes

**Path Aliases:**
- PSR-4 autoloading: `App\\` → `src/`
- Test namespace: `App\\Tests\\` → `tests/`
- No path aliases configured; imports use full namespaces

**Example from `src/Service/ScanService.php`:**
```php
use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScanRun;
use App\Enum\FindingStatus;
use App\Enum\FindingType;
use App\Enum\ScanRunStatus;
use App\Enum\Severity;
use App\Repository\DomainRepository;
use App\Repository\ScanRunRepository;
use App\ValueObject\ScanConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
```

## Error Handling

**Patterns:**
- Domain/business logic throws `\RuntimeException` with descriptive German messages
- Controllers catch validation errors and return error arrays in templates, not exceptions
- Services return null for "not found" cases (e.g., `TlsConnector::connect()` returns null on timeout)
- Services return error arrays in results: `['error' => $message]`
- Exceptions logged via PSR-3 LoggerInterface before re-throwing or converting to findings

**Example from `src/Service/ScanService.php` (line 140):**
```php
if (!$domain->isActive()) {
    throw new \RuntimeException('Deaktivierte Domains können nicht gescannt werden.');
}
```

**Example from `src/Controller/DomainController.php` (line 75):**
```php
if (!$file || !$file->isValid()) {
    $this->addFlash('danger', 'Bitte eine gültige CSV-Datei hochladen.');
    return $this->redirectToRoute('domain_import');
}
```

**Finding Results Pattern:**
- Errors in scan results become Finding entities with type `FindingType::Error` and severity `Severity::Low`
- See `src/Service/ScanService.php` lines 67-76 for error-to-finding conversion

## Logging

**Framework:** PSR-3 LoggerInterface injected via constructor DI

**Patterns:**
- Info level: normal operation progress (e.g., "Scan-Run #{id} gestartet")
- Error level: exceptions and failures requiring attention
- All logs include domain:port context where applicable
- German messages for user-facing content, English for technical details

**Example from `src/Service/ScanService.php`:**
```php
$this->logger->info("Scan-Run #{$scanRun->getId()} gestartet mit " . count($domains) . ' Domains.');
$this->logger->error("Fehler beim Scannen von {$domain->getFqdn()}:{$domain->getPort()}: " . $result['error']);
```

## Comments

**When to Comment:**
- Class-level: Always provide a brief description of purpose (e.g., "Pure analysis logic extracted from ScanService")
- Method-level: For complex logic or non-obvious decisions
- Inline: Only for "why" not "what" (code should read itself)
- Section dividers: Use `// ── methodName ──────────` pattern in test files to organize related tests

**JSDoc/PHPDoc:**
- Method signatures with complex return types always include `@return` annotation
- Type hints use inline `@param` and `@return` only when needed for clarity
- Generic collection types use array notation: `@return array<int, Domain>`
- Method-level comment always above method signature

**Example from `src/Service/CertificateAnalyzer.php`:**
```php
/**
 * Analyzes raw TLS result data and returns an array of finding arrays.
 *
 * @param array $result Raw TLS check result from TlsConnector
 * @return array<array{finding_type: string, severity: string, details: array}>
 */
public function analyze(array $result): array
```

**Example from test file organization** in `tests/Unit/Service/CertificateAnalyzerTest.php`:
```php
// ── computeDaysRemaining ─────────────────────────────────────────────────

public function testComputeDaysRemainingReturnsNullWithoutValidTo(): void
```

## Function Design

**Size:**
- Small, focused methods (10-30 lines typical)
- Analyzer methods follow single-check pattern: `checkCertExpiry()`, `checkTlsVersion()`, etc.
- Each returns either a finding array or null

**Parameters:**
- Constructor injection for dependencies (readonly recommended)
- Method parameters typed explicitly (strict_types=1 enforced)
- No positional optional parameters; use constructor defaults for configuration
- Array parameters documented with shape in PHPDoc when complex

**Return Values:**
- Explicit void or typed return (never implicit null)
- Nullable types marked with `?` prefix: `?int`, `?string`
- Collections typed as `Collection<int, Entity>` (Doctrine) or `array<int, Type>`
- Fluent setters return `static` for method chaining
- Array results include consistent keys: `['finding_type' => ..., 'severity' => ..., 'details' => [...]]`

## Module Design

**Exports:**
- Public methods: only those intended for external use
- Private/protected for internal helpers
- Final classes for value objects: `final readonly class ScanConfiguration`
- Service classes are concrete, not abstract (some extend `AbstractController`)

**Barrel Files:**
- Not used in this codebase; each class imported explicitly

**Readonly Properties:**
- All injected dependencies use `private readonly` in services and controllers
- Value objects use `final readonly class` with promoted constructor properties
- Example from `src/ValueObject/ScanConfiguration.php`:
  ```php
  final readonly class ScanConfiguration
  {
      public function __construct(
          public int $scanTimeout = 10,
          public int $retryDelay = 5,
          public int $retryCount = 1,
          public bool $notifyOnUnreachable = false,
          public int $minRsaKeyBits = 2048,
          public int $scanConcurrency = 5,
      ) {
      }
  }
  ```

**Doctrine Attributes:**
- ORM mapping via PHP 8 attributes (not XML/YAML)
- Repository class specified in attribute: `#[ORM\Entity(repositoryClass: DomainRepository::class)]`
- Lifecycle callbacks marked with attributes: `#[ORM\PrePersist]`, `#[ORM\PreUpdate]`

## Language & Domain Terms

**User-Facing Text:**
- German throughout: class names in domain logic (e.g., `Pflegeeinsatz`), UI labels, error messages
- Example: Domain import validation error: "CSV muss mindestens die Spalten "FQDN" und "Port" enthalten."

**Technical Comments:**
- English for implementation details and docstrings
- German for user-facing error/log messages

---

*Convention analysis: 2026-02-27*
