# E2E Tests – crySSLCheck / TLS Monitor

End-to-End-Tests mit [TestCafe](https://testcafe.io/) für die Web-Oberfläche von TLS Monitor.

## Voraussetzungen

- Node.js ≥ 18
- Chromium / Chrome installiert
- Laufende Symfony-Applikation (Symfony Dev-Server oder Docker)
- Geladene Doctrine-Fixtures (Testdaten)

## Setup

```bash
# Im e2e-Verzeichnis
cd e2e
npm install
```

## Testdaten laden (Fixtures)

Vor dem ersten Testlauf müssen die PHP-Fixtures geladen werden:

```bash
# Datenbank-Migrationen
php bin/console doctrine:migrations:migrate --no-interaction

# Fixtures laden (löscht vorhandene Daten!)
php bin/console doctrine:fixtures:load --no-interaction

# Für Testumgebung
php bin/console doctrine:fixtures:load --env=test --no-interaction
```

### Fixture-Benutzer

| Benutzername | Passwort     | Rolle   |
|-------------|--------------|---------|
| `admin`     | `admin123`   | Admin   |
| `auditor`   | `auditor123` | Auditor |

### Fixture-Domains

| FQDN                         | Port | Status   |
|-----------------------------|------|----------|
| `google.com`                | 443  | Aktiv    |
| `github.com`                | 443  | Aktiv    |
| `expired.badssl.com`        | 443  | Aktiv    |
| `disabled-monitor.internal` | 443  | Inaktiv  |
| `intranet.example.local`    | 8443 | Aktiv    |

## Applikation starten

```bash
# Symfony Dev-Server
php bin/console server:start --no-tls

# Oder: Docker
docker compose up -d
```

## Tests ausführen

```bash
cd e2e

# Alle Tests (headless Chrome)
npm test

# Einzelne Test-Suiten
npm run test:login
npm run test:domains
npm run test:findings
npm run test:users

# Mit sichtbarem Browser
npm run test

# Anderer Browser
npm run test:firefox
```

## Konfiguration

Die Basis-URL kann über die Umgebungsvariable `APP_URL` gesetzt werden:

```bash
APP_URL=http://localhost:8080 npm test
```

Oder in `.testcaferc.json` (`baseUrl`).

## Struktur

```
e2e/
├── .testcaferc.json       # TestCafe-Konfiguration
├── package.json           # npm-Abhängigkeiten
├── fixtures/              # Testdaten (JSON)
│   ├── users.json
│   └── domains.json
├── helpers/               # Wiederverwendbare Hilfsfunktionen
│   └── auth.js            # Login / Logout
└── tests/                 # Testdateien
    ├── login.test.js      # Authentifizierungs-Tests
    ├── domains.test.js    # Domain-Verwaltungs-Tests
    ├── findings.test.js   # Findings-Übersicht-Tests
    └── users.test.js      # Benutzerverwaltungs-Tests
```

## CI-Integration

```yaml
# Beispiel GitHub Actions
- name: Start Symfony server
  run: php bin/console server:start --no-tls &

- name: Load fixtures
  run: php bin/console doctrine:fixtures:load --no-interaction

- name: Run E2E tests
  run: cd e2e && npm ci && npm run test:headless
```
