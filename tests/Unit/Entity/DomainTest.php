<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Domain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Domain::class)]
class DomainTest extends TestCase
{
    private function createDomain(string $fqdn = 'example.com', int $port = 443): Domain
    {
        $domain = new Domain();
        $domain->setFqdn($fqdn);
        $domain->setPort($port);

        return $domain;
    }

    public function testDefaultStatusIsActive(): void
    {
        $domain = new Domain();
        $this->assertSame('active', $domain->getStatus());
        $this->assertTrue($domain->isActive());
    }

    public function testDefaultPortIs443(): void
    {
        $domain = new Domain();
        $this->assertSame(443, $domain->getPort());
    }

    public function testIdIsNullBeforePersist(): void
    {
        $domain = new Domain();
        $this->assertNull($domain->getId());
    }

    public function testSetAndGetFqdn(): void
    {
        $domain = $this->createDomain('sub.example.org');
        $this->assertSame('sub.example.org', $domain->getFqdn());
    }

    public function testSetAndGetPort(): void
    {
        $domain = $this->createDomain('example.com', 8443);
        $this->assertSame(8443, $domain->getPort());
    }

    public function testSetAndGetDescription(): void
    {
        $domain = $this->createDomain();
        $this->assertNull($domain->getDescription());

        $domain->setDescription('Production Webserver');
        $this->assertSame('Production Webserver', $domain->getDescription());
    }

    public function testSetDescriptionToNull(): void
    {
        $domain = $this->createDomain();
        $domain->setDescription('some description');
        $domain->setDescription(null);
        $this->assertNull($domain->getDescription());
    }

    public function testIsActiveWhenStatusIsActive(): void
    {
        $domain = $this->createDomain();
        $domain->setStatus('active');
        $this->assertTrue($domain->isActive());
    }

    public function testIsNotActiveWhenStatusIsInactive(): void
    {
        $domain = $this->createDomain();
        $domain->setStatus('inactive');
        $this->assertFalse($domain->isActive());
    }

    public function testToggleStatusFromActiveToInactive(): void
    {
        $domain = $this->createDomain();
        $this->assertSame('active', $domain->getStatus());

        $domain->toggleStatus();
        $this->assertSame('inactive', $domain->getStatus());
        $this->assertFalse($domain->isActive());
    }

    public function testToggleStatusFromInactiveToActive(): void
    {
        $domain = $this->createDomain();
        $domain->setStatus('inactive');

        $domain->toggleStatus();
        $this->assertSame('active', $domain->getStatus());
        $this->assertTrue($domain->isActive());
    }

    public function testToggleStatusTwiceRestoresOriginal(): void
    {
        $domain = $this->createDomain();
        $domain->toggleStatus();
        $domain->toggleStatus();
        $this->assertSame('active', $domain->getStatus());
    }

    public function testOnPrePersistSetsCreatedAt(): void
    {
        $domain = $this->createDomain();
        $this->assertNull($domain->getCreatedAt());

        $before = new \DateTimeImmutable();
        $domain->onPrePersist();
        $after = new \DateTimeImmutable();

        $this->assertNotNull($domain->getCreatedAt());
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $domain->getCreatedAt()->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $domain->getCreatedAt()->getTimestamp());
    }

    public function testOnPreUpdateSetsUpdatedAt(): void
    {
        $domain = $this->createDomain();
        $this->assertNull($domain->getUpdatedAt());

        $before = new \DateTimeImmutable();
        $domain->onPreUpdate();
        $after = new \DateTimeImmutable();

        $this->assertNotNull($domain->getUpdatedAt());
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $domain->getUpdatedAt()->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $domain->getUpdatedAt()->getTimestamp());
    }

    public function testFindingsCollectionIsEmptyByDefault(): void
    {
        $domain = new Domain();
        $this->assertCount(0, $domain->getFindings());
    }

    public function testToStringReturnsFormattedString(): void
    {
        $domain = $this->createDomain('example.com', 8443);
        $this->assertSame('example.com:8443', (string) $domain);
    }

    #[DataProvider('fqdnProvider')]
    public function testSetFqdnVariants(string $fqdn): void
    {
        $domain = new Domain();
        $domain->setFqdn($fqdn);
        $this->assertSame($fqdn, $domain->getFqdn());
    }

    public static function fqdnProvider(): array
    {
        return [
            'simple domain' => ['example.com'],
            'subdomain'     => ['sub.example.com'],
            'deep subdomain' => ['a.b.c.example.org'],
            'IP address'    => ['192.168.1.1'],
            'localhost'     => ['localhost'],
        ];
    }
}
