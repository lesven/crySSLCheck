<?php

namespace App\Model;

use App\Database;
use PDO;

class Finding
{
    public static function create(int $domainId, int $runId, string $findingType, string $severity, array $details, string $status = 'new'): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO findings (domain_id, run_id, checked_at, finding_type, severity, details, status)
            VALUES (?, ?, datetime('now'), ?, ?, ?, ?)
        ");
        $stmt->execute([$domainId, $runId, $findingType, $severity, json_encode($details), $status]);
        return (int) $pdo->lastInsertId();
    }

    public static function findByRunId(int $runId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT f.*, d.fqdn, d.port
            FROM findings f
            JOIN domains d ON d.id = f.domain_id
            WHERE f.run_id = ?
            ORDER BY f.checked_at DESC
        ");
        $stmt->execute([$runId]);
        return $stmt->fetchAll();
    }

    public static function findByDomainId(int $domainId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT f.*, d.fqdn, d.port
            FROM findings f
            JOIN domains d ON d.id = f.domain_id
            WHERE f.domain_id = ?
            ORDER BY f.checked_at DESC
        ");
        $stmt->execute([$domainId]);
        return $stmt->fetchAll();
    }

    public static function findAll(int $limit = 50, int $offset = 0, bool $problemsOnly = false, ?int $runId = null): array
    {
        $pdo = Database::getConnection();
        $where = [];
        $params = [];

        if ($problemsOnly) {
            $where[] = "f.finding_type != 'OK'";
        }
        if ($runId !== null) {
            $where[] = "f.run_id = ?";
            $params[] = $runId;
        }

        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT f.*, d.fqdn, d.port
            FROM findings f
            JOIN domains d ON d.id = f.domain_id
            $whereClause
            ORDER BY f.checked_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countAll(bool $problemsOnly = false, ?int $runId = null): int
    {
        $pdo = Database::getConnection();
        $where = [];
        $params = [];

        if ($problemsOnly) {
            $where[] = "f.finding_type != 'OK'";
        }
        if ($runId !== null) {
            $where[] = "f.run_id = ?";
            $params[] = $runId;
        }

        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM findings f $whereClause");
        $stmt->execute($params);
        return (int) $stmt->fetch()['cnt'];
    }

    public static function findPreviousRunFindings(int $domainId, int $currentRunId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT f.*
            FROM findings f
            JOIN scan_runs sr ON sr.id = f.run_id
            WHERE f.domain_id = ?
              AND f.run_id != ?
              AND f.status != 'resolved'
              AND f.finding_type NOT IN ('OK', 'UNREACHABLE', 'ERROR')
            ORDER BY sr.started_at DESC
        ");
        $stmt->execute([$domainId, $currentRunId]);
        return $stmt->fetchAll();
    }

    public static function markResolved(int $id): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE findings SET status = 'resolved' WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function isKnownFinding(int $domainId, string $findingType, int $currentRunId): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt
            FROM findings
            WHERE domain_id = ?
              AND finding_type = ?
              AND run_id != ?
              AND status IN ('new', 'known')
        ");
        $stmt->execute([$domainId, $findingType, $currentRunId]);
        return $stmt->fetch()['cnt'] > 0;
    }

    public static function getLatestRunId(): ?int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT id FROM scan_runs WHERE finished_at IS NOT NULL ORDER BY finished_at DESC LIMIT 1");
        $result = $stmt->fetch();
        return $result ? (int) $result['id'] : null;
    }
}
