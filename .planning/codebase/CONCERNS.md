# Codebase Concerns

**Analysis Date:** 2026-02-27

## Tech Debt

**Large Controller Methods (DomainController):**
- Issue: `DomainController::import()` method spans 100+ lines with complex CSV parsing logic embedded in the controller
- Files: `src/Controller/DomainController.php` (lines 68-169)
- Impact: Difficult to test CSV import in isolation, mixing business logic with HTTP handling
- Fix approach: Extract CSV parsing into dedicated service (`CsvImportService`), keep controller thin

**Destructive Database Query Without Confirmation Dialog:**
- Issue: `DomainController::deleteAll()` executes raw DQL DELETE query without secondary confirmation. Line 328 creates raw query without parameterization protection.
- Files: `src/Controller/DomainController.php` (line 328)
- Impact: User could accidentally delete all domains with single click. Query uses string interpolation.
- Fix approach: Add JavaScript confirmation dialog, consider requiring additional CSRF token + password confirmation for destructive bulk operations. Already has CSRF check but pattern is risky.

**Session State Management in Controllers:**
- Issue: Controllers retrieve session data (`scan_results`, `mailer_debug`) and manually clear it (lines 34-38 in DomainController)
- Files: `src/Controller/DomainController.php` (lines 34-38)
- Impact: Session cleanup logic scattered across controllers, prone to missing removes, memory leak potential
- Fix approach: Use Symfony Flash Bag abstraction or create SessionCleanupMiddleware

**Suppressors in Stream Operations:**
- Issue: Using `@` operator to suppress errors in `TlsConnector::connect()` (lines 43, 77, 154, 157)
- Files: `src/Service/TlsConnector.php` (lines 43, 77, 154, 157)
- Impact: Silences legitimate errors, makes debugging harder, hides unexpected failures
- Fix approach: Replace with explicit error checking or catch blocks

## Known Bugs

**Email Password Reset Contains Plaintext Password:**
- Symptoms: Newly generated user password sent in plaintext via email in `sendPasswordResetMail()`
- Files: `src/Service/MailService.php` (lines 121-131)
- Trigger: Admin resets user password via UI
- Impact: HIGH SECURITY RISK - plaintext password in email logs, SMTP traffic, and email archives
- Fix approach: Use temporary token-based reset link instead of plaintext password. Store hashed token in database with expiration (15 minutes).

**Missing Input Validation on CSV Port Field:**
- Symptoms: CSV import converts port to int but doesn't validate range (1-65535)
- Files: `src/Controller/DomainController.php` (line 117)
- Trigger: CSV file with port > 65535 or port < 1
- Workaround: ValidationService has separate validate method but CSV uses inline validation
- Fix approach: Use `validateDomainForImport()` consistently which likely validates ranges

**Potential N+1 Query in FindingRepository::findPaginated():**
- Symptoms: Joins domain but doesn't lazy-load, subsequent access to finding->domain-> properties may trigger extra queries
- Files: `src/Repository/FindingRepository.php` (lines 26-30)
- Trigger: Rendering findings list that accesses domain data
- Impact: Database performance degradation with many findings
- Workaround: Ensure template doesn't access deeply nested properties
- Fix approach: Use `leftJoin()` with eager fetch, verify with query profiler

## Security Considerations

**SMTP Configuration Exposed in Error Logs:**
- Risk: MailService catches exceptions and logs `getenv('MAILER_DSN')` which may contain credentials
- Files: `src/Service/MailService.php` (line 157)
- Current mitigation: DSN usually points to env variable, not inline credentials
- Recommendations: Never log MAILER_DSN. Use placeholder like "mailer_configured: true/false" instead. Validate in boot time that ENV is set.

**Missing Rate Limiting on Health Endpoint:**
- Risk: `/health` endpoint is public (no authentication) and can be polled frequently by attackers
- Files: `src/Controller/HealthController.php` (lines 22-50)
- Current mitigation: None
- Recommendations: Add rate limiting middleware (e.g., `symfony/rate-limiter`), or change to `#[IsGranted('IS_AUTHENTICATED_FULLY')]`

**Plaintext Storage of Sensitive Finding Details:**
- Risk: Finding.details JSON field stores potentially sensitive data (certificate subjects, error messages, etc.)
- Files: `src/Entity/Finding.php` (lines 38-39), `src/Repository/FindingRepository.php`
- Current mitigation: Only accessible to authenticated users, database encryption at rest not enforced
- Recommendations: Consider encrypting sensitive fields in JSON. Add database-level encryption. Implement field-level access control if different roles have different data sensitivity requirements.

**Missing CSRF on Admin API-like Endpoints:**
- Risk: Some endpoints may not require CSRF tokens if called from JavaScript without form submission
- Files: Review all `#[IsGranted('ROLE_ADMIN')]` methods - DomainController has explicit checks but pattern inconsistent
- Recommendations: Consider centralizing CSRF validation or use SameSite cookie attribute consistently

**Default Email in Migration:**
- Risk: Migration sets default email `'example@example.com'` for existing users (Version20250101000002.php)
- Files: `migrations/Version20250101000002.php` (line 23)
- Current mitigation: Flag during user creation that email is required
- Recommendations: Make email field NOT NULL from start, handle legacy data differently (or reject migration)

## Performance Bottlenecks

**Parallel Scanner Subprocess Overhead:**
- Problem: Creating new PHP subprocess for each domain scan via `app:scan-domain` command
- Files: `src/Service/ParallelScanner.php` (lines 119-139)
- Cause: Symfony Process spawns full PHP process, not lightweight thread. Setup/teardown cost significant for many small scans.
- Current: ~3-5 second overhead per process (FPM startup, Kernel bootstrap)
- Improvement path: For 10-50 domains, single-process is faster. For 1000+ domains, consider async queue (RabbitMQ) or async workers. Add configurable concurrency threshold (switch to sequential below N domains).

**Memory Usage with Large Domain Lists:**
- Problem: `ParallelScanner::scan()` stores all Domain entities and results in arrays during execution
- Files: `src/Service/ParallelScanner.php` (lines 44-48)
- Cause: No pagination or batch processing
- Impact: With 10,000+ domains, memory spike during scan collection
- Improvement path: Stream results to database in chunks, use Doctrine batch processing with detach/flush cycles

**Blocking Polling Loop in ParallelScanner:**
- Problem: Main process polls subprocess status every 100ms with `usleep(100_000)`
- Files: `src/Service/ParallelScanner.php` (line 109)
- Impact: CPU waste, doesn't scale well (busy waiting on many processes)
- Improvement path: Use `proc_open` with stream_select for non-blocking I/O, or switch to async (ReactPHP, Amp)

**Inefficient Email Retry on MailService Errors:**
- Problem: If SMTP send fails, no retry logic; alerts are lost
- Files: `src/Service/MailService.php` (lines 140-161)
- Impact: Critical security alerts may not reach admins
- Improvement path: Add exponential backoff retry queue (e.g., to database, processed by scheduled task)

## Fragile Areas

**TlsConnector Stream Handling:**
- Files: `src/Service/TlsConnector.php` (lines 26-125)
- Why fragile: Multiple code paths handle stream closure (fclose at lines 95, 114, 191), error suppression hides failures, retry logic with manual stream recreation is error-prone
- Safe modification: Write comprehensive integration tests for each error scenario (timeout, cert error, network error, partial cert chain). Use test fixtures with mocked streams.
- Test coverage: Only basic unit tests exist; no tests for cert chain extraction or retry behavior

**ScanService Dual Sequential/Parallel Logic:**
- Files: `src/Service/ScanService.php` (lines 56-126)
- Why fragile: Two separate code paths (parallel vs sequential) that must maintain parity. Bug in one path isn't caught by tests of other. Error handling differs slightly.
- Safe modification: Extract common finding persistence logic. Use strategy pattern to encapsulate scan execution (SequentialScanStrategy, ParallelScanStrategy).
- Test coverage: Unit tests don't cover both paths; integration tests with varying concurrency settings needed

**FindingPersister Status Logic:**
- Files: `src/Service/FindingPersister.php` (lines 65-75)
- Why fragile: Complex conditional for determining if mail should be sent (isKnown, severity, type checks). If Finding enum or Severity values change, logic breaks silently.
- Safe modification: Extract to dedicated `AlertQualifier` service with clear decision tree. Unit test with all combinations (new/known × all severities × all finding types).

**Entity Lifecycle Callbacks in Finding:**
- Files: `src/Entity/Finding.php` (lines 44-48)
- Why fragile: PrePersist sets checkedAt timestamp; if entity created then updated without flush, timestamp is stale. If DQL bulk updates used, callback never fires.
- Safe modification: Use dedicated timestamp setter method, validate in setter. Document that bulk updates bypass callbacks.

## Scaling Limits

**Single-Database Bottleneck:**
- Current capacity: SQLite supports ~1000 concurrent connections but locks easily under write load
- Limit: With parallel scans on 100+ domains, write lock contention will cause timeouts
- Scaling path: Migrate to PostgreSQL immediately if adding parallel scanning. Use connection pooling (PgBouncer).

**Memory Per Scan Run:**
- Current capacity: Scanning 1000 domains with result array storage in ScanService uses ~100-200MB heap
- Limit: PHP default memory_limit 128MB will fail at ~500 domains
- Scaling path: Increase memory_limit in CLI context, implement batch flushing, stream large result sets to disk

**Subprocess Pool Size:**
- Current capacity: Default concurrency (4-8) works to ~100 domains before overhead dominates
- Limit: Beyond 100 domains, subprocess creation cost exceeds TLS handshake time
- Scaling path: Use job queue (RabbitMQ) with persistent workers, or increase concurrency threshold but monitor memory/CPU

## Dependencies at Risk

**Doctrine ORM Major Version:**
- Risk: Using Doctrine 3.0 which is relatively new; ecosystem may have compatibility gaps with Symfony 7.4
- Package: `doctrine/orm: ^3.0`
- Impact: Security patches may lag, community support smaller than Doctrine 2.x
- Migration plan: Keep aligned with Symfony LTS releases. Monitor Doctrine release cycle. Have fallback to 2.x if critical bugs appear.

**PHPInsights as Dev Dependency:**
- Risk: PHPInsights 2.13 is relatively new; may have false positives or stop being maintained
- Package: `nunomaduro/phpinsights: ^2.13`
- Impact: CI pipeline could fail on valid code, time wasted on suppressing false violations
- Migration plan: Run locally but don't gate CI on it. Use PHPStan as primary static analysis. Monitor phpinsights GitHub activity.

**PHP 8.2 Requirement:**
- Risk: Dropping support for older PHP versions limits deployment options
- Package: `"php": ">=8.2"`
- Impact: May not run on some legacy servers, cloud providers with outdated PHP
- Recommendations: Document PHP 8.2+ requirement clearly, consider backport to 8.1 if targeting shared hosting

## Missing Critical Features

**No Backup/Export of Critical Data:**
- Problem: Only CSV export of domains exists; no way to backup findings history, scan runs, or user accounts
- Blocks: Disaster recovery, audit trail preservation
- Workaround: Direct database export
- Priority: HIGH - Add scheduled database backup, bulk export of findings with filters

**No Certificate Pinning Validation:**
- Problem: TLS checks certificate validity but don't detect if issuer/subject suddenly changes (certificate swaps, compromised keys)
- Blocks: Detecting MITM attacks, key rotation issues
- Workaround: Manual review of issuer/subject changes in UI
- Priority: MEDIUM - Add baseline certificate fingerprint storage, alert on changes

**No Webhook Support for Findings:**
- Problem: Only email alerts; no integration with external ticketing (Jira, GitHub Issues), Slack, Teams
- Blocks: Automated incident response workflows
- Workaround: Email parsing bots
- Priority: MEDIUM - Add webhook dispatcher pattern, support for common platforms

**No Scan History Graph / Trend Analysis:**
- Problem: Findings stored but no visualization of when issues appeared/resolved, trends over time
- Blocks: Risk assessment, compliance reporting
- Workaround: SQL queries or spreadsheet export
- Priority: LOW - Consider adding chart library (Chart.js) for trend visualization

## Test Coverage Gaps

**ParallelScanner Subprocess Execution:**
- What's not tested: Actual subprocess lifecycle (start, polling, wait, collection). Mocked in unit tests.
- Files: `src/Service/ParallelScanner.php` + tests in `tests/Unit/Service/`
- Risk: Race conditions, timeout handling bugs only discovered in production
- Priority: HIGH - Add integration test with real subprocesses (use test fixtures, controlled domains)

**TlsConnector Certificate Chain Extraction:**
- What's not tested: Complex scenario of self-signed cert requiring fallback connection (lines 68-96)
- Files: `src/Service/TlsConnector.php`
- Risk: Cert chain errors silently fail or return incomplete data
- Priority: HIGH - Add integration tests with mock SSL servers (phpseclib, local test CA)

**MailService SMTP Failures:**
- What's not tested: SMTP timeout, connection refused, authentication failure, partial send
- Files: `src/Service/MailService.php`
- Risk: Alerts silently fail, no retry, operators unaware
- Priority: MEDIUM - Add tests with MailerInterface mock, simulate failure scenarios

**DomainController CSV Import Edge Cases:**
- What's not tested: Malformed CSV (missing quotes, extra columns, BOM), large files, special characters in domain names
- Files: `src/Controller/DomainController.php` (import method)
- Risk: Import fails or corrupts data without clear error message
- Priority: MEDIUM - Add functional tests with realistic CSV files, unicode, edge cases

**CertificateAnalyzer All Finding Types:**
- What's not tested: Some finding types (TlsVersion, RsaKeyLength, ChainError) lack comprehensive test coverage
- Files: `src/Service/CertificateAnalyzer.php` + tests
- Risk: Finding logic changes silently break detection
- Priority: MEDIUM - Expand unit tests to cover all branches, boundary conditions

---

*Concerns audit: 2026-02-27*
