<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DomainRepository::class)]
class DomainRepositoryTest extends IntegrationTestCase
{
    private DomainRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(DomainRepository::class);
    }

    private function createDomain(string $fqdn, int $port = 443, string $status = 'active'): Domain
    {
        $domain = new Domain();
        $domain->setFqdn($fqdn);
        $domain->setPort($port);
        $domain->setStatus($status);
        $this->em->persist($domain);

        return $domain;
    }

    // ── findAllOrderedByFqdn ──────────────────────────────────────────────────

    public function testFindAllOrderedByFqdnReturnsEmptyArrayWhenNoDomainsExist(): void
    {
        $result = $this->repository->findAllOrderedByFqdn();
        $this->assertSame([], $result);
    }

    public function testFindAllOrderedByFqdnReturnsDomainsSortedAlphabetically(): void
    {
        $this->createDomain('z-server.example.com');
        $this->createDomain('a-server.example.com');
        $this->createDomain('m-server.example.com');
        $this->em->flush();

        $result = $this->repository->findAllOrderedByFqdn();

        $this->assertCount(3, $result);
        $this->assertSame('a-server.example.com', $result[0]->getFqdn());
        $this->assertSame('m-server.example.com', $result[1]->getFqdn());
        $this->assertSame('z-server.example.com', $result[2]->getFqdn());
    }

    public function testFindAllOrderedByFqdnSortsByPortWhenFqdnIsEqual(): void
    {
        $this->createDomain('example.com', 8443);
        $this->createDomain('example.com', 443);
        $this->createDomain('example.com', 9000);
        $this->em->flush();

        $result = $this->repository->findAllOrderedByFqdn();

        $this->assertSame(443, $result[0]->getPort());
        $this->assertSame(8443, $result[1]->getPort());
        $this->assertSame(9000, $result[2]->getPort());
    }

    public function testFindAllOrderedByFqdnIncludesBothActiveAndInactiveDomains(): void
    {
        $this->createDomain('active.example.com', 443, 'active');
        $this->createDomain('inactive.example.com', 443, 'inactive');
        $this->em->flush();

        $result = $this->repository->findAllOrderedByFqdn();
        $this->assertCount(2, $result);
    }

    // ── findActive ────────────────────────────────────────────────────────────

    public function testFindActiveReturnsEmptyArrayWhenNoDomainsExist(): void
    {
        $result = $this->repository->findActive();
        $this->assertSame([], $result);
    }

    public function testFindActiveReturnsOnlyActiveDomains(): void
    {
        $this->createDomain('active.example.com', 443, 'active');
        $this->createDomain('inactive.example.com', 443, 'inactive');
        $this->em->flush();

        $result = $this->repository->findActive();

        $this->assertCount(1, $result);
        $this->assertSame('active.example.com', $result[0]->getFqdn());
    }

    public function testFindActiveReturnsEmptyArrayWhenAllDomainsAreInactive(): void
    {
        $this->createDomain('server1.example.com', 443, 'inactive');
        $this->createDomain('server2.example.com', 443, 'inactive');
        $this->em->flush();

        $result = $this->repository->findActive();
        $this->assertSame([], $result);
    }

    public function testFindActiveReturnsDomainsSortedByFqdn(): void
    {
        $this->createDomain('z.example.com', 443, 'active');
        $this->createDomain('a.example.com', 443, 'active');
        $this->em->flush();

        $result = $this->repository->findActive();

        $this->assertSame('a.example.com', $result[0]->getFqdn());
        $this->assertSame('z.example.com', $result[1]->getFqdn());
    }

    // ── isDuplicate ───────────────────────────────────────────────────────────

    public function testIsDuplicateReturnsFalseWhenNoDomainExists(): void
    {
        $isDuplicate = $this->repository->isDuplicate('example.com', 443);
        $this->assertFalse($isDuplicate);
    }

    public function testIsDuplicateReturnsTrueForExactMatch(): void
    {
        $this->createDomain('example.com', 443);
        $this->em->flush();

        $isDuplicate = $this->repository->isDuplicate('example.com', 443);
        $this->assertTrue($isDuplicate);
    }

    public function testIsDuplicateReturnsFalseWhenOnlyFqdnMatchesButPortDiffers(): void
    {
        $this->createDomain('example.com', 443);
        $this->em->flush();

        $isDuplicate = $this->repository->isDuplicate('example.com', 8443);
        $this->assertFalse($isDuplicate);
    }

    public function testIsDuplicateReturnsFalseWhenOnlyPortMatchesButFqdnDiffers(): void
    {
        $this->createDomain('example.com', 443);
        $this->em->flush();

        $isDuplicate = $this->repository->isDuplicate('other.com', 443);
        $this->assertFalse($isDuplicate);
    }

    public function testIsDuplicateExcludesSpecifiedId(): void
    {
        $domain = $this->createDomain('example.com', 443);
        $this->em->flush();

        // Editing the same domain – should not be detected as a duplicate
        $isDuplicate = $this->repository->isDuplicate('example.com', 443, $domain->getId());
        $this->assertFalse($isDuplicate);
    }

    public function testIsDuplicateDetectsDuplicateIgnoringOtherDomainId(): void
    {
        $domain1 = $this->createDomain('example.com', 443);
        $this->createDomain('other.com', 8443);
        $this->em->flush();

        // Checking if 'example.com:443' is taken when editing the second domain
        $isDuplicate = $this->repository->isDuplicate('example.com', 443, $domain1->getId() + 1);

        // domain1 itself is NOT excluded, so it's a duplicate
        $this->assertTrue($isDuplicate);
    }

    // ── countAll ──────────────────────────────────────────────────────────────

    public function testCountAllReturnsZeroWhenEmpty(): void
    {
        $this->assertSame(0, $this->repository->countAll());
    }

    public function testCountAllReturnsCorrectCount(): void
    {
        $this->createDomain('a.example.com');
        $this->createDomain('b.example.com');
        $this->createDomain('c.example.com');
        $this->em->flush();

        $this->assertSame(3, $this->repository->countAll());
    }

    public function testCountAllIncludesBothActiveAndInactiveDomains(): void
    {
        $this->createDomain('active.example.com', 443, 'active');
        $this->createDomain('inactive.example.com', 443, 'inactive');
        $this->em->flush();

        $this->assertSame(2, $this->repository->countAll());
    }

    // ── findPaginated ─────────────────────────────────────────────────────────

    public function testFindPaginatedReturnsFirstPage(): void
    {
        foreach (['c.example.com', 'a.example.com', 'b.example.com', 'e.example.com', 'd.example.com'] as $fqdn) {
            $this->createDomain($fqdn);
        }
        $this->em->flush();

        $result = $this->repository->findPaginated(1, 2);

        $this->assertCount(2, $result);
        $this->assertSame('a.example.com', $result[0]->getFqdn());
        $this->assertSame('b.example.com', $result[1]->getFqdn());
    }

    public function testFindPaginatedReturnsSecondPage(): void
    {
        foreach (['c.example.com', 'a.example.com', 'b.example.com', 'e.example.com', 'd.example.com'] as $fqdn) {
            $this->createDomain($fqdn);
        }
        $this->em->flush();

        $result = $this->repository->findPaginated(2, 2);

        $this->assertCount(2, $result);
        $this->assertSame('c.example.com', $result[0]->getFqdn());
        $this->assertSame('d.example.com', $result[1]->getFqdn());
    }

    public function testFindPaginatedReturnsLastPageWithRemainder(): void
    {
        foreach (['a.example.com', 'b.example.com', 'c.example.com'] as $fqdn) {
            $this->createDomain($fqdn);
        }
        $this->em->flush();

        $result = $this->repository->findPaginated(2, 2);

        $this->assertCount(1, $result);
        $this->assertSame('c.example.com', $result[0]->getFqdn());
    }

    public function testFindPaginatedReturnsEmptyArrayForOutOfRangePage(): void
    {
        $this->createDomain('a.example.com');
        $this->em->flush();

        $result = $this->repository->findPaginated(99, 10);

        $this->assertSame([], $result);
    }

    public function testFindPaginatedClampsPageToMinimumOfOne(): void
    {
        $this->createDomain('a.example.com');
        $this->em->flush();

        $result = $this->repository->findPaginated(0, 10);

        $this->assertCount(1, $result);
        $this->assertSame('a.example.com', $result[0]->getFqdn());
    }
}
