<?php

namespace App\Tests\Integration\Controller;

use App\Controller\HealthController;
use App\Entity\ScanRun;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(HealthController::class)]
class HealthControllerTest extends WebTestCase
{
    /**
     * Creates a fresh client + clean database schema for each test.
     * In Symfony 7 WebTestCase, createClient() must be called only ONCE per
     * test – subsequent calls try to reboot an already-running kernel.
     */
    private function buildClient(): KernelBrowser
    {
        $client = static::createClient();

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($em);
        $metadata   = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        return $client;
    }

    public function testHealthEndpointReturnsOkStatus(): void
    {
        $client = $this->buildClient();
        $client->request('GET', '/health');

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(200);
    }

    public function testHealthEndpointReturnsJson(): void
    {
        $client = $this->buildClient();
        $client->request('GET', '/health');

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testHealthEndpointStructureWithoutScanRuns(): void
    {
        $client = $this->buildClient();
        $client->request('GET', '/health');

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('db', $data);
        $this->assertArrayHasKey('last_scan_run', $data);
        $this->assertArrayHasKey('last_scan_status', $data);
        $this->assertArrayHasKey('smtp', $data);

        $this->assertSame('ok', $data['status']);
        $this->assertSame('ok', $data['db']);
        $this->assertNull($data['last_scan_run']);
        $this->assertNull($data['last_scan_status']);
    }

    public function testHealthEndpointReflectsLastScanRun(): void
    {
        $client = $this->buildClient();

        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $scanRun = new ScanRun();
        $scanRun->finish('success');
        $scanRun->setFinishedAt(new \DateTimeImmutable('2025-06-15 14:30:00'));
        $em->persist($scanRun);
        $em->flush();

        $client->request('GET', '/health');

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame('ok', $data['status']);
        $this->assertSame('2025-06-15 14:30:00', $data['last_scan_run']);
        $this->assertSame('success', $data['last_scan_status']);
    }

    public function testHealthEndpointShowsSmtpConfigurationStatus(): void
    {
        $client = $this->buildClient();
        $client->request('GET', '/health');

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertContains($data['smtp'], ['configured', 'not_configured']);
    }

    public function testHealthEndpointIsAccessibleWithoutAuthentication(): void
    {
        $client = $this->buildClient();
        $client->request('GET', '/health');

        // Must not redirect to login (302) or return 401/403
        $this->assertResponseStatusCodeSame(200);
    }

    public function testHealthEndpointAcceptsAllHttpMethods(): void
    {
        // Route is defined without method restrictions; POST must also return 200
        $client = $this->buildClient();
        $client->request('POST', '/health');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $data);
    }
}
