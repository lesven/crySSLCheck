# Architecture

**Analysis Date:** 2026-02-27

## Pattern Overview

**Overall:** Service-oriented Symfony application with clear separation of concerns between scanning logic, persistence, and presentation layers.

**Key Characteristics:**
- Modular service architecture extracting business logic from controllers
- Entity-based persistence with Doctrine ORM
- Deterministic, side-effect-free analysis services for testability
- Parallel scanning via subprocess workers using process pool pattern
- Value objects for immutable configuration bundling

## Layers

**Presentation (Controllers):**
- Purpose: Handle HTTP requests, manage user input, render responses
- Location: `src/Controller/`
- Contains: Request handlers for domains, findings, scans, user management, security
- Depends on: Services, repositories, entities, validation
- Used by: HTTP clients via Symfony routing

**Service Layer:**
- Purpose: Core business logic, orchestration, TLS scanning, analysis, persistence
- Location: `src/Service/`
- Contains:
  - `ScanService` – orchestrates full/single scans, coordinates analysis and persistence
  - `CertificateAnalyzer` – pure analysis logic, deterministic findings generation
  - `TlsConnector` – TLS certificate extraction via OpenSSL/stream sockets
  - `ParallelScanner` – process pool for concurrent domain scanning
  - `FindingPersister` – finding storage, known/new determination, alerts
  - `MailService` – SMTP integration for alerts and debugging
  - `ValidationService` – input validation for domains and imports
- Depends on: Repositories, entities, configuration, external services
- Used by: Controllers, commands

**Domain (Entities):**
- Purpose: Data models representing core domain concepts
- Location: `src/Entity/`
- Contains: Domain, ScanRun, Finding, User (Doctrine ORM entities)
- Depends on: Enums, Doctrine annotations
- Used by: Services, repositories, controllers

**Value Objects:**
- Purpose: Immutable configuration and data structures
- Location: `src/ValueObject/`
- Contains: ScanConfiguration (scan timeout, retry params, key bits requirements, concurrency), Password
- Depends on: None
- Used by: Services for configuration injection

**Persistence (Repositories):**
- Purpose: Abstract database access, provide domain-specific queries
- Location: `src/Repository/`
- Contains: DomainRepository, ScanRunRepository, FindingRepository, UserRepository
- Depends on: Entities, Doctrine
- Used by: Services, commands

**Enumerations:**
- Purpose: Type-safe domain enums
- Location: `src/Enum/`
- Contains: FindingType (OK, CERT_EXPIRY, TLS_VERSION, CHAIN_ERROR, RSA_KEY_LENGTH, UNREACHABLE, ERROR), Severity (ok, low, medium, high, critical), FindingStatus (new, known, resolved), DomainStatus (active, inactive), ScanRunStatus (running, success, partial, failed)
- Depends on: None
- Used by: Entities, services, views

**Commands (CLI):**
- Purpose: Scheduled and manual scan execution
- Location: `src/Command/`
- Contains: ScanCommand (full scan), ScanDomainCommand (single domain for subprocess), CreateUserCommand, SetupCommand
- Depends on: Services, repositories
- Used by: Cronjob scheduler, developers

**Security:**
- Purpose: User authentication and authorization
- Location: `src/Security/`
- Contains: UserProvider (custom authentication provider)
- Depends on: User entity, security configuration
- Used by: Symfony security system

**Data Collector (Profiler):**
- Purpose: Debug information collection
- Location: `src/DataCollector/`
- Contains: MailDataCollector (SMTP event tracking for web profiler)
- Depends on: Services
- Used by: Symfony debug toolbar

## Data Flow

**Scan Execution (Full Scan via CLI):**

1. `ScanCommand` invoked via cron/CLI
2. Checks for recent successful scan (unless `--force`)
3. Calls `ScanService::runFullScan()`
4. ScanService retrieves active domains via `DomainRepository::findActive()`
5. Creates `ScanRun` entity, persists with "Running" status
6. **Sequential path** (concurrency=1): For each domain, calls `ScanService::scanDomain()`
7. **Parallel path** (concurrency>1): `ParallelScanner` spawns subprocess workers (`app:scan-domain` command) in process pool pattern
8. Each domain scan:
   - `TlsConnector::connect()` establishes SSL/TLS connection
   - Extracts certificate info (validity, key type/bits, issuer/subject)
   - Extracts stream metadata (protocol, cipher)
   - Returns raw TLS result array
9. `CertificateAnalyzer::analyze()` evaluates result, produces typed findings (deterministic logic, no side effects)
10. `FindingPersister::persistFindings()`:
    - Stores findings as Finding entities
    - Determines if finding is "known" (exists in previous runs) via `FindingRepository::isKnownFinding()`
    - Sets status: New (triggers alert) or Known (silent)
    - Resolves previous findings that no longer appear
    - Calls `MailService::sendFindingAlert()` for High/Critical or Unreachable (if enabled)
11. ScanRun status set: Success (no errors), Partial (some errors), or Failed (all failed)
12. CLI displays summary table

**Single Domain Scan (via Web UI):**

1. `ScanController::scan()` POST action
2. Calls `ScanService::runSingleScan(Domain)`
3. Creates new ScanRun, scans domain using same flow as full scan
4. Stores results in session, redirects to domain index
5. Controller renders results

**Finding Retrieval & Display:**

1. `FindingController::index()` GET request
2. Parses filters: `problems_only` (exclude OK findings), `current_run` (latest run only), `search` (domain FQDN)
3. Calls `FindingRepository::findPaginated()` with limit/offset
4. Renders paginated findings list in Twig template

**Domain Management (Create/Edit):**

1. `DomainController::new()` or `edit()` GET/POST
2. Validates input via `ValidationService::validateDomain()` (FQDN format, port range, no duplicates)
3. Persists Domain entity via EntityManager
4. Unique constraint on (fqdn, port) pair

## Key Abstractions

**ScanConfiguration (Value Object):**
- Purpose: Immutable bundle of all scan parameters
- Examples: `src/ValueObject/ScanConfiguration.php`
- Pattern: Constructor injection via services.yaml with environment variable mapping
- Properties: scanTimeout, retryDelay, retryCount, notifyOnUnreachable, minRsaKeyBits, scanConcurrency
- Ensures: Single source of truth for scan behavior across ScanService, CertificateAnalyzer, ParallelScanner

**TlsConnectorInterface + TlsConnector:**
- Purpose: Abstract TLS connection logic for testability
- Examples: `src/Service/TlsConnectorInterface.php`, `src/Service/TlsConnector.php`
- Pattern: Dependency injection allows mocking in unit tests
- Implementation: Stream socket client with dual-mode fallback (verify peer → no verify for self-signed detection)

**Finding Determination Logic:**
- Purpose: Deterministic analysis decoupled from persistence
- Examples: `src/Service/CertificateAnalyzer.php`
- Pattern: Pure functions with no side effects (stateless)
- Checks: Certificate expiry (days remaining mapped to severity), TLS version (reject <1.2), certificate chain errors, RSA key length

**Parallel Scanning Pool:**
- Purpose: Distribute load via subprocess workers
- Examples: `src/Service/ParallelScanner.php`, `src/Command/ScanDomainCommand.php`
- Pattern: Producer/consumer with fixed pool size
- Process lifecycle: Fork subprocess → poll until termination → collect JSON output → refill pool slot immediately
- Communication: JSON stdout from subprocess, JSON parsing in main process

## Entry Points

**Web Application (HTTP):**
- Location: `src/Kernel.php` (MicroKernel)
- Triggers: HTTP requests to /domains, /findings, /scan, etc.
- Responsibilities: Routes to controllers, handles sessions, security checks

**CLI Scan Trigger:**
- Location: `src/Command/ScanCommand.php` (app:scan)
- Triggers: Cronjob or manual `bin/console app:scan`
- Responsibilities: Entry point for scheduled/forced scans, displays progress bar, outputs summary

**Single-Domain Subprocess:**
- Location: `src/Command/ScanDomainCommand.php` (app:scan-domain FQDN PORT)
- Triggers: Spawned by ParallelScanner via Symfony Process
- Responsibilities: Scans one domain, outputs JSON findings to stdout (no DB persistence at this stage)

**Setup Command:**
- Location: `src/Command/SetupCommand.php`
- Triggers: Initial setup via `bin/console app:setup`
- Responsibilities: Creates initial admin user

## Error Handling

**Strategy:** Explicit error collection with graceful degradation.

**Patterns:**

- **Connection Errors:** `TlsConnector::connect()` returns null on timeout, returns array with 'error' key on non-timeout failures
- **Retry Logic:** ScanService retries up to `RETRY_COUNT` times with `RETRY_DELAY` between attempts (applied before declaring unreachable)
- **Cascade Errors:** If domain scan throws exception, caught in ScanService, persisted as Error finding with details, scan continues
- **Subprocess Failures:** ParallelScanner catches process timeout/exit code, logs error, returns error array for that domain
- **Validation Errors:** Controllers validate input, display flash messages (error/success/warning), redirect with form re-population
- **Alert Failures:** If mail fails, logged as warning but scan continues (doesn't fail entire batch)

## Cross-Cutting Concerns

**Logging:** PSR LoggerInterface injected into services, detailed info/debug/error/warning level messages, structured with context arrays

**Validation:** Input sanitized in controllers and repositories (LIKE escape in FindingRepository), CSRF tokens checked, domain/port bounds validated

**Authentication:** Symfony Security with custom UserProvider, session-based, role-based access control (ROLE_ADMIN for scans/domains, ROLE_USER for findings view)

**Database Transactions:** EntityManager::flush() called after entity operations, Doctrine handles transaction boundaries

---

*Architecture analysis: 2026-02-27*
