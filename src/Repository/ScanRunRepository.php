<?php

namespace App\Repository;

use App\Entity\ScanRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScanRun>
 */
class ScanRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScanRun::class);
    }

    public function findLatestFinished(): ?ScanRun
    {
        return $this->createQueryBuilder('sr')
            ->where('sr.finishedAt IS NOT NULL')
            ->orderBy('sr.finishedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestSuccessfulToday(): ?ScanRun
    {
        $today = new \DateTimeImmutable('today');
        $tomorrow = new \DateTimeImmutable('tomorrow');

        return $this->createQueryBuilder('sr')
            ->where('sr.startedAt >= :today')
            ->andWhere('sr.startedAt < :tomorrow')
            ->andWhere('sr.status != :running')
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->setParameter('running', 'running')
            ->orderBy('sr.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
