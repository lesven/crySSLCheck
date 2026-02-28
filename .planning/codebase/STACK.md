# Technology Stack

**Analysis Date:** 2026-02-27

## Languages

**Primary:**
- **PHP** 8.2+ - Core application language, TLS/SSL certificate monitoring system
- **SQL** - SQLite (dev/test), schema managed via Doctrine migrations in `migrations/`

**Secondary:**
- **Twig** 3.0+ - HTML templating for web UI in `templates/`
- **YAML** - Configuration files in `config/`
- **Shell** - Entrypoint and cron scripts in `docker/`

## Runtime

**Environment:**
- **PHP 8.4-apache** (from Dockerfile) - Apache 2 with PHP FPM integration
- **Docker** - Containerized deployment with compose orchestration

**Package Manager:**
- **Composer** 2.x - PHP dependency manager
- **Lockfile:** `composer.lock` present - dependency versions frozen

## Frameworks

**Core:**
- **Symfony 7.4 LTS** - Web framework (`symfony/framework-bundle`, `symfony/routing`)
  - Security: `symfony/security-bundle` with bcrypt password hashing
  - Forms: `symfony/form` for domain/user management UI
  - Validation: `symfony/validator` for input validation
  - Logging: PSR-3 compliant via Symfony's logger interface
  - Templating: `twig/twig` 3.0+, `twig/extra-bundle`

**Database:**
- **Doctrine ORM** 3.0+ - Object-relational mapping
  - `doctrine/doctrine-bundle` - Symfony integration
  - `doctrine/doctrine-migrations-bundle` - Schema versioning
  - Attribute-based entity mapping (modern PHP 8+ style)
  - Underscore naming strategy for columns/tables

**Mail:**
- **Symfony Mailer** 7.4 - Email transport abstraction
  - SMTP, SMTPS, or null (dev/test) transports
  - Configured via `MAILER_DSN` env var (see `config/packages/mailer.yaml`)

**Testing:**
- **PHPUnit** 12.0+ - Unit/integration testing framework
- **Symfony Test utilities** - `symfony/browser-kit`, `symfony/css-selector` for functional testing

**Build/Dev:**
- **PHPStan** 2.1+ - Static type analysis (level 6)
  - Extension: `phpstan/phpstan-symfony` for Symfony-aware checks
  - Config: `phpstan.neon` with baseline in `phpstan-baseline.neon`
- **PHPInsights** 2.13+ - Code quality checker
  - Config: `phpinsights.php` with Symfony preset

## Key Dependencies

**Critical:**
- `doctrine/doctrine-bundle` 2.11+ - ORM core, manages entity persistence
- `symfony/security-bundle` 7.4 - Authentication/authorization (bcrypt + form login)
- `symfony/mailer` 7.4 - Alert notifications via SMTP

**Infrastructure:**
- `symfony/console` 7.4 - CLI commands for scanning and setup (`bin/console`)
- `symfony/process` 7.4 - Shell subprocess execution
- `symfony/yaml` 7.4 - Config file parsing
- `symfony/dotenv` 7.4 - .env file loading

**PHP Extensions (built-in):**
- `ext-openssl` - TLS certificate parsing and validation (core feature)
- `ext-ctype` - Character type checking
- `ext-iconv` - Character encoding conversion
- `ext-pdo_sqlite` - SQLite database driver (dev/test)

**Development Tools:**
- `pcov` - Code coverage collection (installed in Dockerfile)

## Configuration

**Environment:**
- `.env` - Base configuration (version controlled sample)
- `.env.dev` - Development-specific overrides (optional)
- `.env.local` - Local machine overrides (gitignored)
- `.env.test` - Test environment (frozen for PHPUnit)

**Key Env Variables:**
- `APP_ENV` - Execution mode (dev, test, prod)
- `APP_DEBUG` - Debug mode toggle
- `APP_SECRET` - Encryption key for sessions/CSRF
- `DATABASE_URL` - Doctrine connection string
- `MAILER_DSN` - SMTP transport DSN
- `SCAN_TIMEOUT`, `RETRY_DELAY`, `RETRY_COUNT` - TLS scan parameters
- `MIN_RSA_KEY_BITS`, `SCAN_CONCURRENCY` - Security/performance tuning
- `ALLOW_IP_ADDRESSES`, `DOMAINS_PER_PAGE` - Feature flags
- `ALERT_RECIPIENTS`, `MAILER_FROM_EMAIL`, `MAILER_FROM_NAME` - Notification config

**Build/Config Files:**
- `Dockerfile` - PHP 8.4-apache image with Composer, cron, extensions
- `docker-compose.yml` - Single service (tls-monitor) with volume mounts
- `config/bundles.php` - Symfony bundle registry
- `config/services.yaml` - DI container configuration (autowiring enabled)
- `config/packages/doctrine.yaml` - ORM settings, migration auto-mapping
- `config/packages/security.yaml` - Auth providers, firewalls, password hashing
- `config/packages/mailer.yaml` - SMTP transport setup

## Platform Requirements

**Development:**
- Docker + Docker Compose (for containerized development)
- PHP 8.2+ (if running outside Docker)
- Composer 2.x (if running outside Docker)
- SQLite 3 (dev database, file-based)

**Production:**
- Docker with PHP 8.4-apache image
- Persistent volume for `data/` (SQLite database) and `var/log/` (logs)
- SMTP server reachable for alert notifications
- Optional: External PostgreSQL/MariaDB (DATABASE_URL env var can swap database)

**CI/CD:**
- PHPUnit test execution (configured via `phpunit.dist.xml`)
- PHPStan analysis (baseline drift detection)
- PHPInsights code quality checks

---

*Stack analysis: 2026-02-27*
