<?php

namespace App\Repository;

use App\Entity\Domain;
use App\Enum\DomainStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Domain>
 */
class DomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Domain::class);
    }

    /**
     * @return Domain[]
     */
    public function findAllOrderedByFqdn(): array
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.fqdn', 'ASC')
            ->addOrderBy('d.port', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Domain[]
     */
    public function findPaginated(int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);

        return $this->createQueryBuilder('d')
            ->orderBy('d.fqdn', 'ASC')
            ->addOrderBy('d.port', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Domain[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.status = :status')
            ->setParameter('status', DomainStatus::Active->value)
            ->orderBy('d.fqdn', 'ASC')
            ->addOrderBy('d.port', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function isDuplicate(string $fqdn, int $port, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.fqdn = :fqdn')
            ->andWhere('d.port = :port')
            ->setParameter('fqdn', $fqdn)
            ->setParameter('port', $port);

        if ($excludeId !== null) {
            $qb->andWhere('d.id != :id')
               ->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
