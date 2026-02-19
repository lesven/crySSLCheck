<?php

namespace App\Model;

use App\Database;
use PDO;

class Domain
{
    public static function findAll(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM domains ORDER BY fqdn ASC, port ASC");
        return $stmt->fetchAll();
    }

    public static function findActive(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM domains WHERE status = 'active' ORDER BY fqdn ASC, port ASC");
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM domains WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function create(string $fqdn, int $port, ?string $description): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("INSERT INTO domains (fqdn, port, description) VALUES (?, ?, ?)");
        $stmt->execute([$fqdn, $port, $description]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $fqdn, int $port, ?string $description): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE domains SET fqdn = ?, port = ?, description = ?, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$fqdn, $port, $description, $id]);
    }

    public static function toggleStatus(int $id): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE domains SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function isDuplicate(string $fqdn, int $port, ?int $excludeId = null): bool
    {
        $pdo = Database::getConnection();
        if ($excludeId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM domains WHERE fqdn = ? AND port = ? AND id != ?");
            $stmt->execute([$fqdn, $port, $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM domains WHERE fqdn = ? AND port = ?");
            $stmt->execute([$fqdn, $port]);
        }
        return $stmt->fetch()['cnt'] > 0;
    }
}
