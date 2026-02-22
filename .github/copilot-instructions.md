# Copilot Instructions – crySSLCheck / TLS Monitor

## Project Overview
Symfony 7 PHP web application that monitors TLS/SSL certificates for configured domains, stores findings in SQLite, and sends email alerts for critical issues. Deployed exclusively via Docker.

## Architecture & Data Flow

**Core flow:** `ScanCommand` (cron/manual) → `ScanService::runFullScan()` → `ScanService::scanDomain()` → PHP `stream_socket_client` with SSL context → findings persisted → `MailService::sendFindingAlert()` for qualifying findings.

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

All commands run **inside the Docker container** via `make`:

```sh
make install          # first-time setup: build + up + composer install + migrations
make test             # all PHPUnit tests (APP_ENV=test)
make test-unit        # tests/Unit only
make test-integration # tests/Integration only
make test-coverage    # HTML coverage → var/coverage/
make scan             # manual scan run
make scan-force       # scan ignoring today's successful run
make shell            # bash inside container
make create-user USERNAME=alice PASSWORD=secret ROLE=admin
```

Tests run inside the container with `APP_ENV=test` injected. Never run `php bin/phpunit` directly on the host.

## Testing Conventions

- **Unit tests** (`tests/Unit/`): plain PHPUnit, no database, mock all repositories/services.
- **Integration tests** (`tests/Integration/`): extend `IntegrationTestCase`, which creates a fresh SQLite schema via `SchemaTool` in `setUp()` and drops it in `tearDown()`. No fixtures — seed data manually through the entity manager.

## Configuration via Environment Variables

All scan behaviour is driven by env vars (see `config/services.yaml` → `parameters:`):

| Variable | Default | Purpose |
|---|---|---|
| `SCAN_TIMEOUT` | 10 | TCP/TLS connect timeout (seconds) |
| `RETRY_DELAY` | 5 | Seconds between retries |
| `RETRY_COUNT` | 1 | Number of retries on unreachable |
| `MIN_RSA_KEY_BITS` | 2048 | RSA key length threshold |
| `NOTIFY_ON_UNREACHABLE` | false | Send alert on unreachable/error findings |
| `ALLOW_IP_ADDRESSES` | true | Accept IP addresses as FQDN |
| `ALERT_RECIPIENTS` | – | Comma-separated mail recipients |

## Key Patterns

**TLS Check recovery:** When `stream_socket_client` fails with a certificate error, `ScanService::performTlsCheck()` retries with `verify_peer=false` to still extract cert metadata and reports a `CHAIN_ERROR` finding rather than `UNREACHABLE`.

**`scanDomain()` returns raw arrays**, not entity objects. `persistFindings()` converts them to `Finding` entities, determines `new`/`known` status via `FindingRepository::isKnownFinding()`, and resolves previous findings that no longer appear in the current scan.

**Validation** is centralised in `ValidationService` (FQDN regex, port range, duplicate check). Controllers call it directly — no Symfony Form Validator constraints on entities.

**Roles** are enforced via `access_control` in `config/packages/security.yaml`. Voter/annotation-based access checks are not used; controllers check `$this->isGranted('ROLE_ADMIN')` inline.

## Database

SQLite file at `data/tls_monitor.sqlite`. Migrations live in `migrations/` and follow the naming convention `Version<YYYYMMDDNNNNNN>.php`. Generate new migrations with:
```sh
make console CMD="doctrine:migrations:diff"
make console CMD="doctrine:migrations:migrate --no-interaction"
```
