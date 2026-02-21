# TLS Monitor – POC Anforderungen

**Tech-Stack:** PHP (plain), SQLite, Bootstrap 5, OS-Cronjob, PHPMailer  
**Ziel:** Lauffähiger POC mit Kernfunktion „Domain eintragen → automatisch scannen → bei Problem per Mail informieren → Ergebnis einsehen."

**Entwicklung**

Das Projekt wird in einem Docker‑Image ausgeliefert. Für die Entwicklung binden wir
jedoch das Quellverzeichnis in den Container ein (`docker-compose` verwendet
`- ./:/var/www/html:delegated`). Änderungen im Host-Dateisystem wirken sofort –
kein wiederholtes `make install`/`make build` nötig.

> ⚠️ Da das Bind‑Mount das im Image vorhandene `vendor/`-Verzeichnis überdeckt,
müssen die PHP‑Abhängigkeiten auf dem Host installiert werden (oder der
Container erledigt das beim Start; die Entrypoint‑Skript prüft nun automatisch
auf fehlende `vendor/` und führt `composer install` aus).

```sh
# ab jetzt zum ersten Start bzw. nach Anpassung des Compose-Files:
make install   # baut das Image und startet Container
make restart   # startet Container neu, der Mount bleibt bestehen
```

---

## Inhaltsverzeichnis

1. [Epics & Stories im Scope](#epics--stories-im-scope)
2. [Epic: Domain-Verwaltung](#epic-domain-verwaltung)
3. [Epic: Scan-Engine & Scheduler](#epic-scan-engine--scheduler)
4. [Epic: Reporting & E-Mail-Benachrichtigungen](#epic-reporting--e-mail-benachrichtigungen)
5. [Epic: Rollen, Rechte & Nachvollziehbarkeit](#epic-rollen-rechte--nachvollziehbarkeit)
6. [Epic: Konfiguration & Betrieb](#epic-konfiguration--betrieb)
7. [Datenbank-Schema](#datenbank-schema)
8. [Empfohlene Dateistruktur](#empfohlene-dateistruktur)
9. [Out-of-Scope (Ausbaustufe 1)](#out-of-scope-ausbaustufe-1)

---

## Epics & Stories im Scope

| # | Story | Epic |
|---|-------|------|
| 1 | Domains anlegen | Domain-Verwaltung |
| 5a | Domain bearbeiten | Domain-Verwaltung |
| 5b | Domain deaktivieren / reaktivieren | Domain-Verwaltung |
| 7 | Validierung von Domain-Einträgen | Domain-Verwaltung |
| 8 | Automatischer Scan per Cronjob | Scan-Engine |
| 10 | Manueller ad-hoc Scan | Scan-Engine |
| 12 | Timeout-Handling | Scan-Engine |
| 33 | Retry-Logik | Scan-Engine |
| 15 | Findings speichern | Reporting |
| 16 | Schwellwerte für Zertifikatsablauf | Reporting |
| 17 | E-Mail bei kritischen Findings | Reporting |
| 20 | Findings-Liste im Web-UI | Reporting |
| 23 | Read-only-Rolle (Auditor) | Rollen & Rechte |
| 24 | Admin-Rolle | Rollen & Rechte |
| 26 | Login / Authentifizierung | Rollen & Rechte |
| 27 | SMTP-Konfiguration | Konfiguration & Betrieb |
| 29 | Health-Check-Endpoint | Konfiguration & Betrieb |

---

## Epic: Domain-Verwaltung

---

### #1 – Domains anlegen

> Als Security-Verantwortlicher möchte ich neue Domains und Ports anlegen können, um alle zu prüfenden Endpunkte zentral zu verwalten.

**Akzeptanzkriterien:**

- Formular enthält Pflichtfelder: FQDN, Port (1–65535)
- Optionales Freitextfeld „Beschreibung"
- Bei Speichern: Validierung auf FQDN-Format (Regex) und Port-Range
- Duplikat-Check auf Kombination FQDN + Port – bei Konflikt: Fehlermeldung, kein doppelter Eintrag
- Erfolgreiche Anlage: Weiterleitung zur Domainliste mit Bootstrap-Erfolgs-Alert
- Domain wird mit Status `aktiv` und Timestamp `created_at` in SQLite gespeichert

---

### #5a – Domain bearbeiten

> Als Security-Verantwortlicher möchte ich bestehende Domains und deren Ports bearbeiten können, um Korrekturen vorzunehmen.

**Akzeptanzkriterien:**

- Bearbeitungsformular ist mit bestehenden Werten vorbelegt
- Gleiche Validierungsregeln wie beim Anlegen
- Duplikat-Check schließt die aktuell bearbeitete Domain aus
- Änderung wird mit `updated_at`-Timestamp gespeichert
- Abbrechen führt zurück zur Liste ohne Änderung

---

### #5b – Domain deaktivieren / reaktivieren

> Als Security-Verantwortlicher möchte ich Domains deaktivieren oder reaktivieren können, ohne die Scan-Historie zu verlieren.

**Akzeptanzkriterien:**

- Toggle-Button in der Domainliste (Aktiv / Inaktiv)
- Deaktivierte Domains werden in der Liste grau dargestellt (Bootstrap `text-muted`)
- Deaktivierte Domains werden vom Scheduler nicht gescannt
- Deaktivierte Domains bleiben mit ihren Findings in der DB erhalten
- Manueller ad-hoc Scan auf deaktivierte Domains ist gesperrt (Button disabled + Tooltip)

---

### #7 – Validierung von Domain-Einträgen

> Als Security-Verantwortlicher möchte ich bei der Eingabe Plausibilitätsprüfungen haben, um Fehler und Dubletten zu vermeiden.

**Akzeptanzkriterien:**

- FQDN-Validierung via Regex: `^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$`
- IP-Adressen: explizit erlaubt oder ausgeschlossen (Entscheidung in `config.php` festhalten)
- Port: nur Integer, 1–65535, Pflichtfeld
- Fehlermeldungen werden inline am jeweiligen Feld angezeigt (Bootstrap `invalid-feedback`)
- Duplikat-Check serverseitig (kein reines JS)
- Kein Eintrag wird bei Validierungsfehler gespeichert

---

## Epic: Scan-Engine & Scheduler

---

### #8 – Automatischer Scan per Cronjob

> Als Systembetreiber möchte ich, dass alle aktiven Domains täglich automatisch gescannt werden, um frühzeitig Probleme mit Zertifikaten und TLS-Konfiguration zu erkennen.

**Akzeptanzkriterien:**

- PHP-CLI-Script `scan.php` ist vorhanden und lauffähig
- Script liest alle aktiven Domains aus SQLite
- Pro Domain wird ein TLS-Check durchgeführt (Ablaufdatum, TLS-Version, Kettenfehler)
- Jeder Scan-Run wird mit `run_id`, `started_at`, `finished_at`, `status` (`success` / `partial` / `failed`) in der DB gespeichert
- Cronjob-Eintrag ist in der Dokumentation beschrieben: `0 2 * * * php /path/to/scan.php`
- Script ist idempotent – mehrfaches Ausführen erzeugt keine Duplikate für denselben Tag

---

### #10 – Manueller ad-hoc Scan

> Als Security-Verantwortlicher möchte ich einzelne Domains manuell scannen können, um nach Änderungen sofort den aktuellen Zustand zu sehen.

**Akzeptanzkriterien:**

- Button „Jetzt scannen" in der Domainliste und Detailansicht
- Scan läuft synchron (für POC akzeptabel) und zeigt Ergebnis nach Abschluss
- Ergebnis wird als reguläres Finding gespeichert (gleiche Struktur wie automatischer Scan)
- Ladeindikator (Bootstrap Spinner) während des Scans
- Bei Fehler: Fehlermeldung im UI, kein stiller Fail
- Button ist für deaktivierte Domains disabled (s. #5b)

---

### #12 – Timeout-Handling

> Als Systembetreiber möchte ich, dass der Scanner bei nicht erreichbaren Hosts saubere Fehlerzustände protokolliert, um Netzwerkprobleme von echten TLS-Findings unterscheiden zu können.

**Akzeptanzkriterien:**

- Verbindungs-Timeout konfigurierbar in `config.php` (Default: 10 Sekunden)
- Bei Timeout: Finding mit Status `UNREACHABLE` und Fehlermeldung gespeichert
- Bei sonstigem Verbindungsfehler (z.B. DNS-Fehler): Finding mit Status `ERROR` + Exception-Message
- `UNREACHABLE`- und `ERROR`-Findings lösen keine TLS-spezifischen Alarme aus
- Ob `UNREACHABLE`/`ERROR` eine separate Mail auslöst, ist in `config.php` konfigurierbar (ja/nein)
- Im Scan-Log wird die Ursache lesbar protokolliert

---

### #33 – Retry-Logik

> Als Systembetreiber möchte ich, dass fehlgeschlagene Scans einmal wiederholt werden, um transiente Netzwerkfehler herauszufiltern.

**Akzeptanzkriterien:**

- Bei `UNREACHABLE` oder `ERROR`: automatisch 1 Retry nach 30 Sekunden (konfigurierbar in `config.php`)
- Erst nach erneutem Fehlschlag wird das Finding final gespeichert
- Retry-Verhalten wird im Scan-Log dokumentiert: `Retry 1/1 für domain.tld`
- Kein Retry bei erfolgreichen Scans oder bei deaktivierten Domains

---

## Epic: Reporting & E-Mail-Benachrichtigungen

---

### #15 – Findings speichern

> Als Security-Verantwortlicher möchte ich, dass alle Findings pro Domain und Scan-Run historisch gespeichert werden, um Entwicklungen und wiederkehrende Probleme nachvollziehen zu können.

**Akzeptanzkriterien:**

- SQLite-Tabelle `findings` mit: `id`, `domain_id`, `run_id`, `checked_at`, `finding_type`, `severity`, `details` (JSON), `status`
- Pro Scan-Run und Domain wird mindestens ein Eintrag erzeugt (auch bei „alles OK" mit Status `OK`)
- Findings werden nicht überschrieben – jeder Run erzeugt neue Einträge
- `resolved`-Status wird automatisch gesetzt, wenn ein Finding aus dem Vorrun im aktuellen Run nicht mehr auftritt

**Finding-Typen:**

| Typ | Beschreibung |
|-----|-------------|
| `CERT_EXPIRY` | Zertifikat läuft ab oder ist abgelaufen |
| `TLS_VERSION` | Unsichere TLS-Version aktiv (z.B. TLS 1.0) |
| `CHAIN_ERROR` | Fehler in der Zertifikatskette |
| `UNREACHABLE` | Host nicht erreichbar (Timeout) |
| `ERROR` | Sonstiger Verbindungsfehler |
| `OK` | Kein Problem festgestellt |

---

### #16 – Schwellwerte für Zertifikatsablauf

> Als Security-Verantwortlicher möchte ich Schwellwerte konfigurieren können, ab wann ein Zertifikatsablauf als Finding gewertet wird, um frühzeitig reagieren zu können.

**Akzeptanzkriterien:**

- Schwellwerte in `config.php` definiert: `CERT_WARN_DAYS = [30, 14, 7]`
- Bei Unterschreitung eines Schwellwerts: Finding mit entsprechendem Severity-Level

| Verbleibende Tage | Severity |
|-------------------|----------|
| ≤ 30 Tage | `low` |
| ≤ 14 Tage | `medium` |
| ≤ 7 Tage | `high` |
| Bereits abgelaufen | `critical` |

- Ablaufdatum und verbleibende Tage werden im Finding-Detail gespeichert

---

### #17 – E-Mail bei kritischen Findings

> Als Security-Verantwortlicher möchte ich bei neu aufgetretenen kritischen Findings automatisch per E-Mail informiert werden, um schnell Maßnahmen einleiten zu können.

**Akzeptanzkriterien:**

- Mail wird **nur bei neu aufgetretenen Findings** versandt (Status `new`) – nicht bei jedem Scan für bekannte, bereits gemeldete Findings
- Trigger: Severity `high` oder `critical`
- Mail enthält: Domain, Port, Finding-Typ, Severity, Timestamp, verbleibende Tage (bei `CERT_EXPIRY`)
- Versand via PHPMailer, Empfänger aus `config.php` (globale Liste für POC)
- Bei Mail-Versandfehler: Fehler wird geloggt, Scan-Run wird nicht abgebrochen
- Kein TLS-Alarm-Mail bei `UNREACHABLE`/`ERROR`-Status (separate Logik, s. #12)

---

### #20 – Findings-Liste im Web-UI

> Als Security-Verantwortlicher möchte ich aktuelle und historische Findings in einer Übersicht sehen können, um gezielt Analysen durchzuführen.

**Akzeptanzkriterien:**

- Tabelle zeigt: Domain, Port, Finding-Typ, Severity, Datum, Status
- Severity wird farblich per Bootstrap `badge` hervorgehoben:
  - `critical` → `danger`
  - `high` → `warning`
  - `medium` → `info`
  - `low` → `secondary`
- Standardmäßig sortiert nach Datum absteigend
- Toggle: „Nur aktueller Run" vs. „Alle historischen Findings"
- Checkbox: „Nur Probleme anzeigen" (blendet `OK`-Findings aus)
- Pagination bei mehr als 50 Einträgen

---

## Epic: Rollen, Rechte & Nachvollziehbarkeit

---

### #26 – Login / Authentifizierung

> Als Systembetreiber möchte ich, dass nur authentifizierte Benutzer Zugriff auf das System haben, um unautorisierte Änderungen oder Einsicht in Sicherheitsdaten zu verhindern.

**Akzeptanzkriterien:**

- Login-Formular (Username + Passwort) als Einstiegsseite
- Passwörter werden mit `password_hash()` (bcrypt) gespeichert
- Session wird nach Login gesetzt, bei Logout zerstört
- Alle Routen prüfen Session – unauthentifizierte Requests werden auf Login weitergeleitet
- Session-Timeout nach 60 Minuten Inaktivität (konfigurierbar in `config.php`)
- Kein Self-Registration – Accounts werden nur vom Admin angelegt (für POC: via CLI-Script oder direkt in DB)

---

### #23 – Read-only-Rolle (Auditor)

> Als Auditor möchte ich Konfiguration, Domainliste und Findings einsehen können, ohne Änderungen vorzunehmen.

**Akzeptanzkriterien:**

- Rolle `auditor` in der User-Tabelle als Enum (`admin`, `auditor`)
- Auditor sieht alle Seiten, aber alle Schreib-Aktionen (Buttons, Formulare) sind ausgeblendet oder disabled
- Serverseitig: Schreib-Endpunkte prüfen Rolle und antworten bei Auditor mit HTTP 403
- Auditor kann Findings-Liste und Domainliste einsehen
- Auditor kann keinen manuellen Scan auslösen

---

### #24 – Admin-Rolle

> Als Security-Verantwortlicher möchte ich eine Admin-Rolle haben, die alle Funktionen des Systems steuern kann.

**Akzeptanzkriterien:**

- Rolle `admin` hat Zugriff auf alle Funktionen ohne Einschränkung
- Admin kann Domains anlegen, bearbeiten, deaktivieren
- Admin kann manuellen Scan auslösen
- Admin kann SMTP-Konfiguration per Test-Mail-Button prüfen
- Mindestens ein Admin-Account muss immer existieren – Löschung des letzten Admins wird verhindert

---

## Epic: Konfiguration & Betrieb

---

### #27 – SMTP-Konfiguration

> Als Systembetreiber möchte ich SMTP-Parameter konfigurieren können, um E-Mail-Benachrichtigungen über unsere bestehende Mail-Infrastruktur zu versenden.

**Akzeptanzkriterien:**

- Konfiguration in `config.php`: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `SMTP_ENCRYPTION` (`tls` / `ssl` / `none`)

> **Hinweis für Entwickler:** der Container lädt Umgebungsvariablen aus `.env`;
> für lokale Tests können Sie außerdem eine `.env.dev` anlegen. Sie wird
> über `docker-compose.yml` in den Container gemountet (`- ./.env.dev:/var/www/html/.env.dev:ro`)
> und beim Start durch `docker/entrypoint.sh` eingelesen, so dass Werte wie
> `ALERT_RECIPIENTS` wirksam werden. Führen Sie nach Änderung einen
> `docker-compose down && docker-compose up -d --build` aus, damit die
> neuen Einstellungen übernommen werden.
- PHPMailer wird als Composer-Dependency eingebunden
- Admin-UI enthält „Test-Mail senden"-Button – sendet Testmail an konfigurierten Empfänger und zeigt Erfolg/Fehler im UI
- SMTP-Passwort wird nicht geloggt
- Bei fehlender oder fehlerhafter Konfiguration: deutliche Fehlermeldung, kein stiller Fail

---

### #29 – Health-Check-Endpoint

> Als Systembetreiber möchte ich einen Health-Check-Endpoint haben, um den Betriebszustand des Tools überwachen zu können.

**Akzeptanzkriterien:**

- `GET /health` liefert JSON-Response:

```json
{
  "status": "ok",
  "db": "ok",
  "last_scan_run": "2025-02-18T02:00:00",
  "last_scan_status": "success",
  "smtp": "configured"
}
```

- HTTP 200 bei OK, HTTP 503 wenn DB nicht erreichbar
- Endpoint ist ohne Authentifizierung erreichbar (für Monitoring-Tools)
- `last_scan_run` = Timestamp des letzten abgeschlossenen Runs aus der DB

---

## Datenbank-Schema

```sql
CREATE TABLE domains (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  fqdn        TEXT    NOT NULL,
  port        INTEGER NOT NULL,
  description TEXT,
  status      TEXT    DEFAULT 'active',   -- active | inactive
  created_at  TEXT    DEFAULT (datetime('now')),
  updated_at  TEXT
);

CREATE TABLE scan_runs (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  started_at  TEXT,
  finished_at TEXT,
  status      TEXT    -- success | partial | failed
);

CREATE TABLE findings (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  domain_id    INTEGER REFERENCES domains(id),
  run_id       INTEGER REFERENCES scan_runs(id),
  checked_at   TEXT,
  finding_type TEXT,  -- CERT_EXPIRY | TLS_VERSION | CHAIN_ERROR | UNREACHABLE | ERROR | OK
  severity     TEXT,  -- critical | high | medium | low | ok
  details      TEXT,  -- JSON (Rohdaten: Ablaufdatum, Protokoll, etc.)
  status       TEXT   -- new | known | resolved
);

CREATE TABLE users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT    UNIQUE NOT NULL,
  password_hash TEXT    NOT NULL,
  role          TEXT    DEFAULT 'auditor'  -- admin | auditor
);
```

---

## Empfohlene Dateistruktur

```
/tls-monitor
├── public/
│   ├── index.php               ← Entry-Point, Routing
│   └── css/                    ← Bootstrap (lokal oder CDN)
├── src/
│   ├── Controller/
│   │   ├── DomainController.php
│   │   ├── ScanController.php
│   │   └── FindingController.php
│   ├── Service/
│   │   ├── ScanService.php
│   │   └── MailService.php
│   └── Model/
│       ├── Domain.php
│       ├── Finding.php
│       ├── ScanRun.php
│       └── User.php
├── templates/                  ← PHP-HTML-Templates
├── cli/
│   └── scan.php                ← CLI-Script für Cronjob
├── logs/
│   └── scan.log
├── config.php                  ← Alle Konfigurationsparameter
└── composer.json               ← PHPMailer-Dependency
```
