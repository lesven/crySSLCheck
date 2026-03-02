# External Integrations

**Analysis Date:** 2026-02-27

## APIs & External Services

**TLS Certificate Checking:**
- **OpenSSL Socket API** - Direct TLS connection and certificate inspection
  - Implementation: `src/Service/TlsConnector.php`
  - Protocol: PHP `stream_socket_client()` + `stream_context_create()` for SSL context
  - No external SDK - uses PHP's native OpenSSL extension
  - Captures peer certificates, cipher details, handshake information
  - Handles certificate validation failures with fallback (verify_peer toggle)

## Data Storage

**Databases:**
- **SQLite 3** (default, development/test)
  - Connection: `DATABASE_URL=sqlite:///%kernel.project_dir%/data/tls_monitor.sqlite`
  - Client: Doctrine ORM via `doctrine/doctrine-bundle` with `ext-pdo_sqlite`
  - Schema: Managed via migrations in `migrations/` (three migrations as of 2025-01-02)
  - Env override: Test database auto-suffixed with `_test` token (PHPUnit fixture)

- **PostgreSQL/MariaDB** (optionally supported)
  - Connection: Swappable via `DATABASE_URL` env var (Doctrine DBAL abstraction)
  - Identity generation configured for PostgreSQL platform in `config/packages/doctrine.yaml`
  - Not pre-configured but supported through DBAL driver plugging

**File Storage:**
- **Local filesystem only**
  - SQLite database: `data/tls_monitor.sqlite`
  - Logs: `var/log/` (mounted volume in Docker)
  - Caches: `var/cache/` (Symfony cache)
  - No cloud storage or CDN integration

**Caching:**
- **Symfony Cache** (in-memory + file-based)
  - Dev/test: File adapter (default)
  - Prod: Configurable pools (`doctrine.result_cache_pool`, `doctrine.system_cache_pool`)
  - Used for Doctrine query/result caching
  - No Redis or Memcached integration

## Authentication & Identity

**Auth Provider:**
- **Custom Form Login** (built with Symfony Security)
  - Implementation: `src/Security/UserProvider.php`
  - Approach: Form-based login with session remember_me (3600s lifetime)
  - Password hashing: bcrypt (auto-cost in prod, cost=4 in tests)
  - User persistence: Database-backed (`src/Entity/User.php`)
  - No OAuth/SSO/LDAP integration

**User Entity:**
- Location: `src/Entity/User.php`
- Implements: `UserInterface`, `PasswordAuthenticatedUserInterface`
- Roles: admin, auditor (role-based access control via security.yaml)

## Monitoring & Observability

**Error Tracking:**
- **None** - Errors logged locally via Symfony Logger
  - No Sentry, Bugsnag, or external error tracking

**Logs:**
- **Symfony Monolog** (PSR-3 logger)
  - File output: `var/log/` directory
  - Debug channel logs TLS connection details
  - Info/warning levels for SMTP and certificate findings
  - Docker volume mount enables log persistence

**DataCollector:**
- Custom mailer data collector at `src/DataCollector/MailDataCollector.php`
  - Tracks SMTP send attempts for debugging in Symfony toolbar (dev mode)
  - Stores records in session for request lifecycle visibility

## CI/CD & Deployment

**Hosting:**
- **Docker** (containerized)
  - Image: `php:8.4-apache` base with composer, extensions, cron
  - Port: 8443 (mapped from container port 80)
  - Volumes: code bind-mount + persistent volumes (vendor, data, logs)
  - Compose: Single service `tls-monitor` in `docker-compose.yml`

**CI Pipeline:**
- **None detected** - No GitHub Actions, GitLab CI, Jenkins, etc.
- Manual testing via `make test`, `make lint`, `make insights`

**Deployment:**
- Script: `docker/entrypoint.sh` - Container startup orchestration
- Apache config: `docker/apache.conf` - Virtual host setup
- Cron config: `docker/crontab` - Scheduled scan jobs

## Environment Configuration

**Required env vars:**
- `APP_ENV` - dev|test|prod
- `APP_SECRET` - 32-char encryption key
- `DATABASE_URL` - DBAL connection string
- `MAILER_DSN` - SMTP transport DSN
- `MAILER_FROM_EMAIL`, `MAILER_FROM_NAME` - Sender identity
- `ALERT_RECIPIENTS` - Comma-separated email list (critical findings only)

**Optional env vars:**
- `SCAN_TIMEOUT` - Connection timeout in seconds (default 10)
- `RETRY_DELAY`, `RETRY_COUNT` - Failure retry behavior
- `NOTIFY_ON_UNREACHABLE` - Send alerts for unreachable domains (boolean)
- `MIN_RSA_KEY_BITS` - Minimum RSA key strength enforcement
- `SCAN_CONCURRENCY` - Parallel domain scan limit
- `ALLOW_IP_ADDRESSES` - Accept raw IPs as domain entries
- `DOMAINS_PER_PAGE` - UI pagination size

**Secrets location:**
- `.env` (version controlled sample) - non-secret values
- `.env.local` (gitignored) - local overrides with real secrets
- `.env.dev`, `.env.test` - environment-specific configs
- Docker Compose env_file loading: `.env` → `.env.dev` → `.env.local` (cascade priority)

## Webhooks & Callbacks

**Incoming:**
- **None** - Scan triggering is manual CLI command or cron-scheduled

**Outgoing:**
- **Email notifications** to `ALERT_RECIPIENTS` when findings occur
  - Service: `src/Service/MailService.php`
  - Trigger: Certificate expiration warnings, weak crypto detected
  - Format: Plain text emails with domain, finding type, severity
  - SMTP transport configured via `MAILER_DSN`

**Scheduled Tasks:**
- Cron entry in `docker/crontab` - Periodic domain scanning
- Symfony commands: `app:scan` (manual), `app:scan-domain` (single domain), `app:create-user` (setup)

---

*Integration audit: 2026-02-27*
