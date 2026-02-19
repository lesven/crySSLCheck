<?php

namespace App\Model;

use App\Database;
use PDO;

class ScanRun
{
    public static function create(): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("INSERT INTO scan_runs (started_at, status) VALUES (datetime('now'), 'running')");
        $stmt->execute();
        return (int) $pdo->lastInsertId();
    }

    public static function finish(int $id, string $status): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE scan_runs SET finished_at = datetime('now'), status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM scan_runs WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function findLatest(): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM scan_runs WHERE finished_at IS NOT NULL ORDER BY finished_at DESC LIMIT 1");
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function findLatestToday(): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM scan_runs WHERE date(started_at) = date('now') AND status != 'running' ORDER BY started_at DESC LIMIT 1");
        $result = $stmt->fetch();
        return $result ?: null;
    }
}
