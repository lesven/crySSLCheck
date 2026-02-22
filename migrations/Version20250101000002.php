<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fügt die E-Mail-Spalte zur users-Tabelle hinzu.
 * Bestehende Benutzer erhalten den Standardwert example@example.com.
 */
final class Version20250101000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E-Mail-Spalte zur users-Tabelle hinzufügen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD COLUMN email VARCHAR(255) NOT NULL DEFAULT 'example@example.com'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, username, password_hash, role FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) DEFAULT 'auditor' NOT NULL)");
        $this->addSql('INSERT INTO users (id, username, password_hash, role) SELECT id, username, password_hash, role FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9F85E0677 ON users (username)');
    }
}
