<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initiale Datenbankstruktur für den TLS Monitor.
 *
 * Verwendet CREATE TABLE IF NOT EXISTS damit die Migration sowohl auf frischen
 * Installationen als auch auf bestehenden SQLite-Datenbanken sicher ausgeführt
 * werden kann.
 */
final class Version20250101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initiale Datenbankstruktur für den TLS Monitor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE IF NOT EXISTS domains (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                fqdn        TEXT    NOT NULL,
                port        INTEGER NOT NULL,
                description TEXT,
                status      TEXT    DEFAULT 'active',
                created_at  TEXT,
                updated_at  TEXT
            )
        ");

        $this->addSql("
            CREATE TABLE IF NOT EXISTS scan_runs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                started_at  TEXT,
                finished_at TEXT,
                status      TEXT
            )
        ");

        $this->addSql("
            CREATE TABLE IF NOT EXISTS findings (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                domain_id    INTEGER REFERENCES domains(id),
                run_id       INTEGER REFERENCES scan_runs(id),
                checked_at   TEXT,
                finding_type TEXT,
                severity     TEXT,
                details      TEXT,
                status       TEXT    DEFAULT 'new'
            )
        ");

        $this->addSql("
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role          TEXT DEFAULT 'auditor'
            )
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS findings');
        $this->addSql('DROP TABLE IF EXISTS scan_runs');
        $this->addSql('DROP TABLE IF EXISTS domains');
        $this->addSql('DROP TABLE IF EXISTS users');
    }
}
