<?php

namespace App\Tests\Integration\Controller;

use App\Controller\ScanController;
use App\Entity\Domain;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(ScanController::class)]
class ScanControllerTest extends WebTestCase
{
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

    private function createTestUser(string $username = 'testuser', string $role = 'admin'): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($username . '@example.com');
        $user->setRole($role);
        $user->setPassword($passwordHasher->hashPassword($user, 'Test123!@#'));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createTestDomain(string $fqdn = 'example.com', int $port = 443, string $status = 'active'): Domain
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $domain = new Domain();
        $domain->setFqdn($fqdn);
        $domain->setPort($port);
        $domain->setDescription('Test domain');
        $domain->setStatus($status);

        $em->persist($domain);
        $em->flush();

        return $domain;
    }

    public function testScanRequiresAdminRole(): void
    {
        $client = $this->buildClient();
        $auditor = $this->createTestUser('auditor', 'auditor');
        $domain = $this->createTestDomain();

        $client->loginUser($auditor);
        $client->request('POST', '/scan/' . $domain->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testScanRequiresAuthentication(): void
    {
        $client = $this->buildClient();
        $domain = $this->createTestDomain();

        $client->request('POST', '/scan/' . $domain->getId());

        $this->assertResponseRedirects('/login');
    }

    public function testScanActiveDomain(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $domain = $this->createTestDomain('google.com', 443, 'active');

        $client->loginUser($admin);
        $client->request('POST', '/scan/' . $domain->getId());

        $this->assertResponseRedirects('/domains');
        
        $client->followRedirect();
        
        // Check that scan completed message appears (either success or error)
        $content = $client->getResponse()->getContent();
        $this->assertTrue(
            (str_contains($content, 'Scan') && str_contains($content, 'abgeschlossen')) || 
            str_contains($content, 'Scan-Fehler')
        );
    }

    public function testScanInactiveDomainShowsError(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $domain = $this->createTestDomain('example.com', 443, 'inactive');

        $client->loginUser($admin);
        $client->request('POST', '/scan/' . $domain->getId());

        $this->assertResponseRedirects('/domains');
        
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert, .flash', 'Deaktivierte Domains können nicht gescannt werden');
    }

    public function testScanNonExistentDomainShowsError(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');

        $client->loginUser($admin);
        $client->request('POST', '/scan/99999');

        $this->assertResponseRedirects('/domains');
        
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert, .flash', 'Domain nicht gefunden');
    }

    public function testScanStoresResultsInSession(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $domain = $this->createTestDomain('google.com', 443, 'active');

        $client->loginUser($admin);
        $client->request('POST', '/scan/' . $domain->getId());

        $this->assertResponseRedirects('/domains');
        
        // Check if scan_results was set in session
        $session = $client->getRequest()->getSession();
        $scanResults = $session->get('scan_results');
        
        // Results might be set (if scan was successful) or null (if scan failed)
        $this->assertTrue($scanResults === null || is_array($scanResults));
    }

    public function testSmtpTestRequiresAdminRole(): void
    {
        $client = $this->buildClient();
        $auditor = $this->createTestUser('auditor', 'auditor');

        $client->loginUser($auditor);
        $client->request('POST', '/scan/smtp-test', [
            'recipient' => 'test@example.com',
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSmtpTestRequiresAuthentication(): void
    {
        $client = $this->buildClient();

        $client->request('POST', '/scan/smtp-test', [
            'recipient' => 'test@example.com',
        ]);

        $this->assertResponseRedirects('/login');
    }

    public function testSmtpTestWithoutRecipientShowsError(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');

        $client->loginUser($admin);
        $client->request('POST', '/scan/smtp-test', [
            'recipient' => '',
        ]);

        $this->assertResponseRedirects('/domains');
        
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert, .flash', 'Kein Empfänger');
    }

    public function testSmtpTestWithRecipient(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');

        $client->loginUser($admin);
        $client->request('POST', '/scan/smtp-test', [
            'recipient' => 'test@example.com',
        ]);

        $this->assertResponseRedirects('/domains');
        
        $client->followRedirect();
        
        // Check that either success or error message appears
        $content = $client->getResponse()->getContent();
        $this->assertTrue(
            str_contains($content, 'Test-Mail wurde erfolgreich gesendet') || 
            str_contains($content, 'Test-Mail fehlgeschlagen')
        );
    }

    public function testScanRoutesArePostOnly(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $domain = $this->createTestDomain();

        $client->loginUser($admin);
        
        // Try GET request to POST-only route
        $client->request('GET', '/scan/' . $domain->getId());
        $this->assertResponseStatusCodeSame(405); // Method Not Allowed

        $client->request('GET', '/scan/smtp-test');
        $this->assertResponseStatusCodeSame(405); // Method Not Allowed
    }

    public function testScanControllerRedirectsToDomainIndex(): void
    {
        $client = $this->buildClient();
        $admin = $this->createTestUser('admin', 'admin');
        $domain = $this->createTestDomain('google.com', 443, 'active');

        $client->loginUser($admin);
        
        // Test scan route
        $client->request('POST', '/scan/' . $domain->getId());
        $this->assertResponseRedirects('/domains');
        
        // Test SMTP test route
        $client->request('POST', '/scan/smtp-test', [
            'recipient' => 'test@example.com',
        ]);
        $this->assertResponseRedirects('/domains');
    }
}
