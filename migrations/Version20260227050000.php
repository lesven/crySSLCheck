<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260227050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fügt notify_alerts zur users-Tabelle hinzu';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN notify_alerts BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__users AS SELECT id, username, password_hash, role, email FROM users');
        $this->addSql('DROP TABLE users');
        $this->addSql("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) DEFAULT 'auditor' NOT NULL, email VARCHAR(255) DEFAULT 'example@example.com' NOT NULL)");
        $this->addSql('INSERT INTO users (id, username, password_hash, role, email) SELECT id, username, password_hash, role, email FROM __temp__users');
        $this->addSql('DROP TABLE __temp__users');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9F85E0677 ON users (username)');
    }
}
