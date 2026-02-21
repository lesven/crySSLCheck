<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schema-Anpassung an die Doctrine ORM-Typen.
 *
 * Aktualisiert die bestehenden Tabellen mit korrekten Typen, NOT NULL-Constraints
 * und Indizes, damit die Doctrine ORM-Mapping-Validierung erfolgreich ist.
 */
final class Version20250101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schema-Anpassung: Doctrine-konforme Typen, Constraints und Indizes';
    }

    public function up(Schema $schema): void
    {
        // Domains-Tabelle aktualisieren
        $this->addSql('CREATE TEMPORARY TABLE __temp__domains AS SELECT id, fqdn, port, description, status, created_at, updated_at FROM domains');
        $this->addSql('DROP TABLE domains');
        $this->addSql("CREATE TABLE domains (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, fqdn VARCHAR(255) NOT NULL, port INTEGER NOT NULL, description CLOB DEFAULT NULL, status VARCHAR(20) DEFAULT 'active' NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL)");
        $this->addSql('INSERT INTO domains (id, fqdn, port, description, status, created_at, updated_at) SELECT id, fqdn, port, description, status, created_at, updated_at FROM __temp__domains');
        $this->addSql('DROP TABLE __temp__domains');

        // Users-Tabelle aktualisieren
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, username, password_hash, role FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) DEFAULT 'auditor' NOT NULL)");
        $this->addSql('INSERT INTO users (id, username, password_hash, role) SELECT id, username, password_hash, role FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9F85E0677 ON users (username)');

        // Scan-Runs-Tabelle aktualisieren
        $this->addSql('CREATE TEMPORARY TABLE __temp__scan_runs AS SELECT id, started_at, finished_at, status FROM scan_runs');
        $this->addSql('DROP TABLE scan_runs');
        $this->addSql('CREATE TABLE scan_runs (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, status VARCHAR(20) DEFAULT NULL)');
        $this->addSql('INSERT INTO scan_runs (id, started_at, finished_at, status) SELECT id, started_at, finished_at, status FROM __temp__scan_runs');
        $this->addSql('DROP TABLE __temp__scan_runs');

        // Findings-Tabelle aktualisieren (nach scan_runs wegen Foreign Key)
        $this->addSql('CREATE TEMPORARY TABLE __temp__findings AS SELECT id, domain_id, run_id, checked_at, finding_type, severity, details, status FROM findings');
        $this->addSql('DROP TABLE findings');
        $this->addSql("CREATE TABLE findings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, domain_id INTEGER NOT NULL, run_id INTEGER NOT NULL, checked_at DATETIME DEFAULT NULL, finding_type VARCHAR(50) NOT NULL, severity VARCHAR(20) NOT NULL, details CLOB NOT NULL, status VARCHAR(20) DEFAULT 'new' NOT NULL, FOREIGN KEY (domain_id) REFERENCES domains (id) NOT DEFERRABLE INITIALLY IMMEDIATE, FOREIGN KEY (run_id) REFERENCES scan_runs (id) NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('INSERT INTO findings (id, domain_id, run_id, checked_at, finding_type, severity, details, status) SELECT id, domain_id, run_id, checked_at, finding_type, severity, details, status FROM __temp__findings');
        $this->addSql('DROP TABLE __temp__findings');
        $this->addSql('CREATE INDEX IDX_D4134381115F0EE5 ON findings (domain_id)');
        $this->addSql('CREATE INDEX IDX_D413438184E3FEC4 ON findings (run_id)');
    }

    public function down(Schema $schema): void
    {
        // Rollback zu einfachen TEXT-Typen
        $this->addSql('CREATE TEMPORARY TABLE __temp__findings AS SELECT id, domain_id, run_id, checked_at, finding_type, severity, details, status FROM findings');
        $this->addSql('DROP TABLE findings');
        $this->addSql("CREATE TABLE findings (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER REFERENCES domains(id), run_id INTEGER REFERENCES scan_runs(id), checked_at TEXT, finding_type TEXT, severity TEXT, details TEXT, status TEXT DEFAULT 'new')");
        $this->addSql('INSERT INTO findings SELECT id, domain_id, run_id, checked_at, finding_type, severity, details, status FROM __temp__findings');
        $this->addSql('DROP TABLE __temp__findings');
    }
}
