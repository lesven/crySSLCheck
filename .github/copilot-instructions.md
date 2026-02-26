# Copilot Instructions – crySSLCheck / TLS Monitor

## Project Overview
Symfony 7 PHP web application that monitors TLS/SSL certificates for configured domains, stores findings in SQLite, and sends email alerts for critical issues. Deployed exclusively via Docker.

## Architecture & Data Flow

**Core flow:** `ScanCommand` (cron/manual) → `ScanService::runFullScan()` → `ParallelScanner::scan()` (batched subprocesses) → `ScanDomainCommand` → `ScanService::scanDomainByFqdn()` → `TlsConnector::connect()` via `TlsConnectorInterface` → `CertificateAnalyzer::analyze()` → JSON results collected → `FindingPersister::persistFindings()` → `MailService::sendFindingAlert()` for qualifying findings. When `SCAN_CONCURRENCY=1`, falls back to sequential in-process scanning.

**Service responsibilities:**
- `TlsConnector` – raw socket I/O only; implements `TlsConnectorInterface` (mockable in unit tests)
- `CertificateAnalyzer` – pure, stateless analysis; no side effects; converts raw TLS result arrays into finding arrays
- `FindingPersister` – persists `Finding` entities, determines `new`/`known`/`resolved` status, triggers mail alerts
- `ParallelScanner` – orchestrates subprocess workers; spawns `app:scan-domain` subprocesses, collects JSON stdout
- `ValidationService` – all domain/port/password validation logic; controllers call it directly

**Entities:**
- `Domain` – FQDN + port combination to monitor (`status`: `active`/`inactive`)
- `ScanRun` – one execution pass (`status`: `success`/`partial`/`failed`)
- `Finding` – result per domain per scan (`finding_type`, `severity`, `status`)
- `User` – two roles: `admin`, `auditor`

**Finding types:** `OK`, `CERT_EXPIRY`, `TLS_VERSION`, `CHAIN_ERROR`, `RSA_KEY_LENGTH`, `UNREACHABLE`, `ERROR`  
**Severities:** `ok` < `low` < `medium` < `high` < `critical`  
**Finding statuses:** `new` (first occurrence), `known` (seen before), `resolved` (no longer appearing)

Mail alerts fire only for `status=new` findings with severity `high`/`critical` (or `UNREACHABLE`/`ERROR` if `NOTIFY_ON_UNREACHABLE=true`).

## Developer Workflows

All commands run **inside the Docker container** via `make`. Never run `php bin/phpunit` or `vendor/bin/phpstan` directly on the host.

```sh
make install          # first-time setup: build + up + composer install + migrations
make test             # all PHPUnit tests (APP_ENV=test)
make test-unit        # tests/Unit only
make test-integration # tests/Integration only
make test-coverage    # HTML coverage → var/coverage/
make lint             # PHPStan static analysis (must pass cleanly)
make scan             # manual scan run
make scan-force       # scan ignoring today's successful run
make shell            # bash inside container
make console CMD="doctrine:migrations:diff"   # arbitrary Symfony console command
make create-user USERNAME=alice PASSWORD=secret ROLE=admin
make db-backup        # copies SQLite file with timestamp suffix
```

**After every change: `make test` and `make lint` must both pass without errors.**

## Testing Conventions

- **Unit tests** (`tests/Unit/`): plain PHPUnit, no database, mock all repositories/services. Inject a mock `TlsConnectorInterface` into `ScanService` to test without real network I/O.
- **Integration tests** (`tests/Integration/`): extend `IntegrationTestCase`, which creates a fresh SQLite schema via `SchemaTool` in `setUp()` and drops it in `tearDown()`. No fixtures — seed data manually through `$this->em`.
- `CertificateAnalyzer` is fully stateless — unit-test it by passing raw result arrays directly to `analyze()`.

## PHPStan / Type Annotations

PHPStan runs at a strict level (see `phpstan.neon`). Known pre-existing violations are suppressed in `phpstan-baseline.neon`.

- Never use bare `array` as a type; always specify value types: `array<string, mixed>`, `array<int, array{finding_type: string, severity: string, details: array<string, mixed>}>`, etc.
- Never use constants as ternary fallbacks when the constant is always defined (e.g. `PHP_BINARY` — use it directly, not `PHP_BINARY ?: 'php'`).
- New violations must be fixed in code, not added to the baseline.

## Configuration via Environment Variables

All scan behaviour is driven by env vars (see `config/services.yaml` → `parameters:`), which are injected into `ScanConfiguration` (a `final readonly` value object) as constructor defaults. Services receive `ScanConfiguration` as a typed constructor argument.

| Variable | Default | Purpose |
|---|---|---|
| `SCAN_TIMEOUT` | 10 | TCP/TLS connect timeout (seconds) |
| `RETRY_DELAY` | 5 | Seconds between retries |
| `RETRY_COUNT` | 1 | Number of retries on unreachable |
| `MIN_RSA_KEY_BITS` | 2048 | RSA key length threshold |
| `NOTIFY_ON_UNREACHABLE` | false | Send alert on unreachable/error findings |
| `SCAN_CONCURRENCY` | 5 | Max parallel domain scans (subprocesses) |
| `ALLOW_IP_ADDRESSES` | true | Accept IP addresses as FQDN |
| `ALERT_RECIPIENTS` | – | Comma-separated mail recipients |

## Key Patterns

**TLS Check recovery:** When `stream_socket_client` fails with a certificate error, `ScanService::performTlsCheck()` retries with `verify_peer=false` to still extract cert metadata and reports a `CHAIN_ERROR` finding rather than `UNREACHABLE`.

**`scanDomain()` returns raw arrays**, not entity objects. `FindingPersister::persistFindings()` converts them to `Finding` entities, determines `new`/`known` status via `FindingRepository::isKnownFinding()`, and resolves previous findings that no longer appear in the current scan.

**Parallel scanning:** `ParallelScanner` spawns Symfony Process subprocesses running `app:scan-domain {fqdn} {port}`. Each subprocess outputs JSON findings to stdout. The main process collects results and persists findings sequentially to avoid SQLite write-lock conflicts. Domains are processed in chunks of `SCAN_CONCURRENCY` size.

**Validation** is centralised in `ValidationService` (FQDN regex, port range, duplicate check). Use `validateDomain()` for create/edit (with optional `$excludeId`), and `validateDomainForImport()` for CSV bulk import (no duplicate-rejection, duplicates are updated). No Symfony Form Validator constraints on entities.

**Access control:** Fine-grained via `#[IsGranted('ROLE_ADMIN')]` attribute on individual controller methods (not class-level). `config/packages/security.yaml` `access_control` only handles coarse route-prefix authentication. No Voters used.

**Session-based result passing:** After a scan triggered via the web UI, results and mailer debug info are written to the session and read once on the next request (flash-like pattern using `$session->get()` / `$session->remove()`).

## Database

SQLite file at `data/tls_monitor.sqlite`. Migrations live in `migrations/` and follow the naming convention `Version<YYYYMMDDNNNNNN>.php`. Generate new migrations with:
```sh
make console CMD="doctrine:migrations:diff"
make console CMD="doctrine:migrations:migrate --no-interaction"
```


