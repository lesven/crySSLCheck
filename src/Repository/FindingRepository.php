<?php

namespace App\Repository;

use App\Entity\Finding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Finding>
 */
class FindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Finding::class);
    }

    /**
     * @return Finding[]
     */
    public function findPaginated(int $limit, int $offset, bool $problemsOnly = false, ?int $runId = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->join('f.domain', 'd')
            ->orderBy('f.checkedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($problemsOnly) {
            $qb->andWhere('f.findingType != :ok')
               ->setParameter('ok', 'OK');
        }

        if ($runId !== null) {
            $qb->andWhere('f.scanRun = :runId')
               ->setParameter('runId', $runId);
        }

        return $qb->getQuery()->getResult();
    }

    public function countFiltered(bool $problemsOnly = false, ?int $runId = null): int
    {
        $qb = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)');

        if ($problemsOnly) {
            $qb->andWhere('f.findingType != :ok')
               ->setParameter('ok', 'OK');
        }

        if ($runId !== null) {
            $qb->andWhere('f.scanRun = :runId')
               ->setParameter('runId', $runId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return Finding[]
     */
    public function findByRunId(int $runId): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.domain', 'd')
            ->where('f.scanRun = :runId')
            ->setParameter('runId', $runId)
            ->orderBy('f.checkedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns unresolved findings for a domain from previous runs.
     *
     * @return Finding[]
     */
    public function findPreviousRunFindings(int $domainId, int $currentRunId): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.domain = :domainId')
            ->andWhere('f.scanRun != :currentRunId')
            ->andWhere('f.status != :resolved')
            ->andWhere('f.findingType != :ok')
            ->setParameter('domainId', $domainId)
            ->setParameter('currentRunId', $currentRunId)
            ->setParameter('resolved', 'resolved')
            ->setParameter('ok', 'OK')
            ->orderBy('f.checkedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function isKnownFinding(int $domainId, string $findingType, int $currentRunId): bool
    {
        $count = (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.domain = :domainId')
            ->andWhere('f.findingType = :findingType')
            ->andWhere('f.scanRun != :currentRunId')
            ->andWhere('f.status IN (:statuses)')
            ->setParameter('domainId', $domainId)
            ->setParameter('findingType', $findingType)
            ->setParameter('currentRunId', $currentRunId)
            ->setParameter('statuses', ['new', 'known'])
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function findLatestRunId(): ?int
    {
        $result = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.scanRun) as run_id')
            ->join('f.scanRun', 'sr')
            ->where('sr.finishedAt IS NOT NULL')
            ->orderBy('sr.finishedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result ? (int) $result['run_id'] : null;
    }
}
