<?php

namespace App\Repository;

use App\Entity\Domain;
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

    /**
     * @return Domain[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.status = :status')
            ->setParameter('status', 'active')
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
