# Codebase Structure

**Analysis Date:** 2026-02-27

## Directory Layout

```
/home/svenheising/crySSLCheck/
├── bin/                       # Console application entry point
├── config/                    # Symfony framework configuration
│   ├── packages/              # Bundle and service configurations
│   ├── routes.yaml            # Route definitions
│   └── services.yaml          # Service container and parameter setup
├── docker/                    # Docker build context
├── migrations/                # Doctrine database migration files
├── public/                    # Web-accessible directory
├── src/                       # Application source code (PSR-4 App\)
│   ├── Command/               # CLI commands
│   ├── Controller/            # HTTP request handlers
│   ├── DataCollector/         # Debug toolbar collectors
│   ├── Entity/                # Doctrine ORM entities
│   ├── Enum/                  # Type-safe enumerations
│   ├── Repository/            # Database query interfaces
│   ├── Security/              # Authentication providers
│   ├── Service/               # Business logic services
│   ├── ValueObject/           # Immutable data structures
│   └── Kernel.php             # Symfony kernel (MicroKernel)
├── templates/                 # Twig view templates
├── tests/                     # PHPUnit test suites
│   ├── Integration/           # Database-dependent tests
│   ├── Unit/                  # Isolated component tests
│   └── bootstrap.php          # Test bootstrap
├── var/                       # Runtime data (cache, logs, coverage)
├── vendor/                    # Composer dependencies (not committed)
├── Dockerfile                 # Container image build definition
├── Makefile                   # Development commands
├── README.md                  # Project documentation
├── composer.json              # PHP dependency manifest
├── composer.lock              # Locked dependency versions
├── phpstan.neon               # Static analysis configuration
├── phpunit.dist.xml           # Test runner configuration
├── phpinsights.php            # Code quality checker configuration
└── symfony.lock               # Symfony recipe lock file
```

## Directory Purposes

**bin/:**
- Purpose: Executable scripts
- Contains: console (Symfony console application entry point)
- Key files: `bin/console` (invoked as `php bin/console app:scan`)

**config/:**
- Purpose: Framework-level configuration
- Contains: Symfony bundle configs, service definitions, routing, database connection
- Key files:
  - `config/services.yaml` – DI container, parameter mapping from .env, service configuration
  - `config/routes.yaml` – Attribute route loading
  - `config/packages/doctrine.php` – ORM configuration
  - `config/packages/security.yaml` – Authentication/authorization rules

**src/:**
- Purpose: Application source code
- Contains: All PHP classes organized by layer
- Key files: Each detailed below by subdirectory

**src/Command/:**
- Purpose: CLI commands invoked via `bin/console`
- Contains:
  - `ScanCommand.php` – Full scan orchestrator (entry point for cron/manual scan)
  - `ScanDomainCommand.php` – Single domain scan JSON producer (subprocess worker)
  - `CreateUserCommand.php` – User creation CLI tool
  - `SetupCommand.php` – Initial setup (admin user creation)

**src/Controller/:**
- Purpose: HTTP request handling and view rendering
- Contains:
  - `DomainController.php` – Domain CRUD, import/export, pagination
  - `ScanController.php` – Scan initiation, SMTP test
  - `FindingController.php` – Finding list with filters/pagination
  - `UserController.php` – User management (admin)
  - `SecurityController.php` – Login, logout, password reset
  - `HealthController.php` – Health check endpoint
  - `ForgotPasswordController.php` – Password reset flow

**src/Entity/:**
- Purpose: Doctrine ORM entity definitions
- Contains:
  - `Domain.php` – Monitored domain (fqdn, port, status, timestamps)
  - `ScanRun.php` – Scan execution record (startedAt, finishedAt, status)
  - `Finding.php` – Vulnerability/issue discovered (type, severity, details, status)
  - `User.php` – Application user (email, roles, password)

**src/Enum/:**
- Purpose: Type-safe enumeration constants
- Contains:
  - `FindingType.php` – OK, CERT_EXPIRY, TLS_VERSION, CHAIN_ERROR, RSA_KEY_LENGTH, UNREACHABLE, ERROR
  - `Severity.php` – ok, low, medium, high, critical (severity levels)
  - `FindingStatus.php` – new, known, resolved (acknowledgment status)
  - `DomainStatus.php` – active, inactive (monitoring status)
  - `ScanRunStatus.php` – running, success, partial, failed (scan outcome)

**src/Repository/:**
- Purpose: Database query abstraction
- Contains:
  - `DomainRepository.php` – Active domains, pagination, filtering, count
  - `ScanRunRepository.php` – Latest run queries, status checks
  - `FindingRepository.php` – Paginated findings, known finding detection, previous runs
  - `UserRepository.php` – User lookup by email
- Pattern: Extend ServiceEntityRepository, implement domain-specific queries

**src/Service/:**
- Purpose: Core business logic
- Contains:
  - `ScanService.php` – Orchestrates full/single scans, coordinates analyzer and persister
  - `CertificateAnalyzer.php` – Pure analysis logic (expiry, TLS version, key length checks)
  - `TlsConnector.php` – Stream socket TLS connection, certificate extraction
  - `TlsConnectorInterface.php` – Interface for dependency injection
  - `ParallelScanner.php` – Process pool for concurrent scans via Symfony Process
  - `FindingPersister.php` – Persists findings, determines known/new status, triggers alerts
  - `MailService.php` – SMTP integration for alerts and debug mail collection
  - `ValidationService.php` – Input validation for domains and CSV imports

**src/Security/:**
- Purpose: Authentication and authorization
- Contains:
  - `UserProvider.php` – Custom authentication provider for login

**src/ValueObject/:**
- Purpose: Immutable value objects
- Contains:
  - `ScanConfiguration.php` – Readonly class bundling scan parameters (timeout, retry count, min key bits, concurrency)
  - `Password.php` – Readonly password value object with hashing

**src/DataCollector/:**
- Purpose: Symfony profiler/debug toolbar integration
- Contains:
  - `MailDataCollector.php` – Collects SMTP events for profiler display

**templates/:**
- Purpose: Twig template files for view rendering
- Contains:
  - `base.html.twig` – Master layout
  - `domain/index.html.twig` – Domain list (main dashboard)
  - `domain/form.html.twig` – Domain create/edit form
  - `domain/import.html.twig` – CSV import page
  - `finding/index.html.twig` – Finding list with filters
  - `security/login.html.twig` – Login form
  - `security/forgot_password.html.twig` – Password reset form
  - `security/change_password.html.twig` – Password change form
  - `user/index.html.twig` – User management list
  - `user/new.html.twig`, `user/edit.html.twig` – User forms

**tests/:**
- Purpose: PHPUnit test suites
- Contains:
  - `tests/Unit/` – Fast, isolated unit tests (services, analysis, security)
  - `tests/Integration/` – Database-dependent integration tests (commands, controllers, repositories)
  - `tests/bootstrap.php` – Test environment setup
- Key files:
  - `tests/Unit/Service/ScanServiceAnalysisTest.php` – Certificate analyzer logic tests
  - `tests/Unit/Service/CertificateAnalyzerTest.php` – Finding determination tests
  - `tests/Integration/Controller/ScanControllerTest.php` – Web request tests
  - `tests/Integration/Repository/FindingRepositoryTest.php` – Query tests

**config/, migrations/, public/, var/, vendor/:**
- Maintained by Symfony framework
- migrations/ contains Doctrine migration files for schema evolution
- var/ contains runtime caches and logs (not committed)
- vendor/ contains Composer dependencies (not committed)

## Key File Locations

**Entry Points:**
- `src/Kernel.php` – Symfony kernel bootstrap
- `bin/console` – CLI entry point
- `public/index.php` – Web application entry point (generated by Symfony)

**Configuration:**
- `config/services.yaml` – Service container DI setup and parameter configuration
- `.env`, `.env.local` – Environment variables (database, mail, scan settings)
- `phpstan.neon` – Static analyzer configuration
- `phpunit.dist.xml` – Test runner configuration
- `composer.json` – Dependency definitions

**Core Logic:**
- `src/Service/ScanService.php` – Full and single scan orchestration
- `src/Service/CertificateAnalyzer.php` – Analysis logic (findings generation)
- `src/Service/ParallelScanner.php` – Parallel execution via process pool
- `src/Service/FindingPersister.php` – Persistence and alert triggering

**Persistence:**
- `src/Entity/Domain.php` – Domain model
- `src/Entity/Finding.php` – Finding model
- `src/Entity/ScanRun.php` – Scan run model
- `src/Repository/FindingRepository.php` – Finding queries
- `migrations/` – Doctrine migration files

**Web Interface:**
- `src/Controller/DomainController.php` – Main domain management
- `templates/domain/index.html.twig` – Main dashboard
- `templates/finding/index.html.twig` – Finding list view

## Naming Conventions

**Files:**
- Entity files: PascalCase.php (e.g., `Domain.php`)
- Service files: PascalCase.php (e.g., `ScanService.php`)
- Command files: PascalCase.php (e.g., `ScanCommand.php`)
- Template files: snake_case.html.twig (e.g., `domain/index.html.twig`)
- Migration files: YYYY-MM-DD-HHmmss-action.php (generated by Doctrine)

**Classes:**
- Controllers: PascalCase ending in `Controller` (e.g., `DomainController`)
- Services: PascalCase ending in `Service` (e.g., `ScanService`)
- Repositories: PascalCase ending in `Repository` (e.g., `FindingRepository`)
- Entities: PascalCase singular (e.g., `Domain`, `Finding`)
- Enums: PascalCase (e.g., `FindingType`, `Severity`)
- Commands: PascalCase ending in `Command` (e.g., `ScanCommand`)

**Methods:**
- Public getters/setters: camelCase get/set + PascalCase property (e.g., `getFqdn()`, `setFqdn()`)
- Business methods: camelCase verb form (e.g., `scan()`, `persistFindings()`, `toggleStatus()`)
- Private methods: camelCase (e.g., `extractCertificateInfo()`)

**Routes:**
- Named routes: snake_case (e.g., `domain_index`, `domain_scan`, `finding_index`)
- URL paths: lowercase with hyphens (e.g., `/domains`, `/findings`, `/scan`)

## Where to Add New Code

**New Feature / Use Case:**
- Primary business logic: `src/Service/NewFeatureService.php`
- If model changes needed: Add properties to `src/Entity/` and create migration in `migrations/`
- If API/form exposure: Add controller action to `src/Controller/`
- If persistence query: Add method to `src/Repository/YourEntityRepository.php`
- Tests: `tests/Unit/Service/NewFeatureServiceTest.php` or `tests/Integration/Controller/YourControllerTest.php`

**New HTTP Endpoint:**
- Controller action in `src/Controller/` with Symfony routing attributes (e.g., `#[Route(...)]`)
- Twig template in `templates/action_name.html.twig`
- Service dependency injection in controller constructor
- Add routes are auto-discovered from Controller attributes

**New Database Entity:**
- Entity class in `src/Entity/YourEntity.php` with ORM annotations
- Create Doctrine migration: `bin/console make:migration`
- Repository class in `src/Repository/YourEntityRepository.php` (auto-generated)
- Add to service configuration if needing custom queries

**New Validation Rule:**
- Add method to `src/Service/ValidationService.php`
- Call from controller before entity persistence

**New CLI Command:**
- Class in `src/Command/YourCommand.php` extending Command
- Use `#[AsCommand(name: 'app:command-name')]` attribute
- Injected services via constructor
- Accessible via `bin/console app:command-name`

**New Value Object / Enum:**
- Immutable class in `src/ValueObject/` or enum in `src/Enum/`
- Inject via constructor as dependency in services needing it
- Value objects configured in `services.yaml` with `arguments:` section

**New Test:**
- Unit test in `tests/Unit/` mirroring source structure
- Integration test in `tests/Integration/` for database-dependent code
- Extend `TestCase` for unit, `IntegrationTestCase` for integration tests
- Follow arrange-act-assert pattern

## Special Directories

**migrations/:**
- Purpose: Database schema versioning
- Generated: Yes (via `bin/console make:migration`)
- Committed: Yes (version control audit trail)
- Usage: Doctrine auto-executes on `bin/console doctrine:migrations:migrate`

**var/:**
- Purpose: Runtime data (caches, logs, file uploads, coverage reports)
- Generated: Yes (at runtime)
- Committed: No (gitignored)
- Subdirs: `var/cache/`, `var/log/`, `var/coverage/`

**public/:**
- Purpose: Web-accessible files
- Contains: index.php (entry point), assets (if any)
- Committed: Yes (but usually minimal)

**vendor/:**
- Purpose: Composer-installed dependencies
- Generated: Yes (`composer install`)
- Committed: No (gitignored; composer.lock is committed)

**config/packages/:**
- Purpose: Bundle-specific configurations loaded by Flex
- Committed: Yes
- Files created/modified by: `composer require` commands (Symfony Flex recipes)

---

*Structure analysis: 2026-02-27*
