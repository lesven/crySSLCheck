<?php

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            $dbDir = dirname(DB_PATH);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }

            self::$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::$pdo->exec('PRAGMA foreign_keys=ON');
        }

        return self::$pdo;
    }

    public static function initialize(): void
    {
        $pdo = self::getConnection();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS domains (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                fqdn        TEXT    NOT NULL,
                port        INTEGER NOT NULL,
                description TEXT,
                status      TEXT    DEFAULT 'active',
                created_at  TEXT    DEFAULT (datetime('now')),
                updated_at  TEXT
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS scan_runs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                started_at  TEXT,
                finished_at TEXT,
                status      TEXT
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS findings (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                domain_id    INTEGER REFERENCES domains(id),
                run_id       INTEGER REFERENCES scan_runs(id),
                checked_at   TEXT,
                finding_type TEXT,
                severity     TEXT,
                details      TEXT,
                status       TEXT
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT    UNIQUE NOT NULL,
                password_hash TEXT    NOT NULL,
                role          TEXT    DEFAULT 'auditor'
            )
        ");

        // Create default admin if no users exist
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM users");
        $count = $stmt->fetch()['cnt'];
        if ($count == 0) {
            $hash = password_hash('admin', PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
            $stmt->execute(['admin', $hash]);
        }
    }

    public static function isAvailable(): bool
    {
        try {
            self::getConnection();
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}
