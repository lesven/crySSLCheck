<?php

namespace App\Tests\Integration\Controller;

use App\Controller\DomainController;
use App\Entity\Domain;
use App\Entity\User;
use App\Repository\DomainRepository;
use App\Service\TlsConnectorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(DomainController::class)]
class DomainControllerTest extends WebTestCase
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

    private function createTestDomain(string $fqdn = 'example.com', int $port = 443): Domain
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $domain = new Domain();
        $domain->setFqdn($fqdn);
        $domain->setPort($port);
        $domain->setDescription('Test domain');

        $em->persist($domain);
        $em->flush();

        return $domain;
    }

    public function testIndexPageRequiresAuthentication(): void
    {
        $client = $this->buildClient();
        
        $client->request('GET', '/domains');

        $this->assertResponseRedirects('/login');
    }

    public function testIndexPageRendersForAuthenticatedUser(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser();

        $client->loginUser($user);
        $client->request('GET', '/domains');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1, h2', 'Domain');
    }

    public function testIndexPageDisplaysDomains(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser();
        $this->createTestDomain('example.com', 443);
        $this->createTestDomain('test.org', 8443);

        $client->loginUser($user);
        $client->request('GET', '/domains');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'example.com');
        $this->assertSelectorTextContains('body', 'test.org');
    }

    public function testNewDomainPageRequiresAdminRole(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('auditor', 'auditor');

        $client->loginUser($user);
        $client->request('GET', '/domains/new');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNewDomainPageRendersForAdmin(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');

        $client->loginUser($user);
        $client->request('GET', '/domains/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="fqdn"]');
        $this->assertSelectorExists('input[name="port"]');
        $this->assertSelectorExists('textarea[name="description"]');
    }

    public function testCreateDomainWithValidData(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/domains/new');

        $form = $crawler->selectButton('Anlegen')->form([
            'fqdn' => 'newdomain.com',
            'port' => '443',
            'description' => 'New test domain',
        ]);

        $client->submit($form);

        $this->assertResponseRedirects('/domains');
        
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);
        $domain = $domainRepo->findOneBy(['fqdn' => 'newdomain.com', 'port' => 443]);

        $this->assertNotNull($domain);
        $this->assertSame('newdomain.com', $domain->getFqdn());
        $this->assertSame(443, $domain->getPort());
        $this->assertSame('New test domain', $domain->getDescription());
    }

    public function testCreateDomainWithInvalidData(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/domains/new');

        $form = $crawler->selectButton('Anlegen')->form([
            'fqdn' => 'invalid-domain',
            'port' => '99999',
            'description' => '',
        ]);

        $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error, .alert-danger, ul li');
    }

    public function testEditDomainPageRendersForAdmin(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');
        $domain = $this->createTestDomain('example.com', 443);

        $client->loginUser($user);
        $client->request('GET', '/domains/' . $domain->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="fqdn"][value="example.com"]');
        $this->assertSelectorExists('input[name="port"][value="443"]');
    }

    public function testEditDomainWithValidData(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');
        $domain = $this->createTestDomain('example.com', 443);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/domains/' . $domain->getId() . '/edit');

        $form = $crawler->selectButton('Speichern')->form([
            'fqdn' => 'updated-example.com',
            'port' => '8443',
            'description' => 'Updated description',
        ]);

        $client->submit($form);

        $this->assertResponseRedirects('/domains');
        
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);
        $updatedDomain = $domainRepo->find($domain->getId());

        $this->assertSame('updated-example.com', $updatedDomain->getFqdn());
        $this->assertSame(8443, $updatedDomain->getPort());
        $this->assertSame('Updated description', $updatedDomain->getDescription());
    }

    public function testEditNonExistentDomainThrowsException(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');

        $client->loginUser($user);
        $client->request('GET', '/domains/99999/edit');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testToggleDomainStatus(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');
        $domain = $this->createTestDomain('example.com', 443);

        $this->assertTrue($domain->isActive());

        $client->loginUser($user);
        $client->request('POST', '/domains/' . $domain->getId() . '/toggle');

        $this->assertResponseRedirects('/domains');
        
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);
        $toggledDomain = $domainRepo->find($domain->getId());

        $this->assertFalse($toggledDomain->isActive());
    }

    public function testDeleteDomainWithValidCsrfToken(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');
        $domain = $this->createTestDomain('example.com', 443);
        $domainId = $domain->getId();

        $client->loginUser($user);
        
        // Get CSRF token from the specific delete form
        $crawler = $client->request('GET', '/domains');
        $token = $crawler->filter('form[action*="/delete"] input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/domains/' . $domainId . '/delete', [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/domains');
        
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);
        $deletedDomain = $domainRepo->find($domainId);

        $this->assertNull($deletedDomain);
    }

    public function testDeleteDomainWithInvalidCsrfToken(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');
        $domain = $this->createTestDomain('example.com', 443);
        $domainId = $domain->getId();

        $client->loginUser($user);
        
        $client->request('POST', '/domains/' . $domainId . '/delete', [
            '_token' => 'invalid-token',
        ]);

        $this->assertResponseRedirects('/domains');
        
        // Domain should still exist
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);
        $existingDomain = $domainRepo->find($domainId);

        $this->assertNotNull($existingDomain);
    }

    public function testDeleteAllDomainsWithValidCsrfToken(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');
        $this->createTestDomain('example.com', 443);
        $this->createTestDomain('test.org', 8443);

        $client->loginUser($user);
        
        // Get CSRF token for delete-all
        $crawler = $client->request('GET', '/domains');
        $token = $crawler->filter('form[action*="delete-all"] input[name="_token"]')->attr('value');

        $client->request('POST', '/domains/delete-all', [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/domains');
        
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);
        $remainingDomains = $domainRepo->findAll();

        $this->assertCount(0, $remainingDomains);
    }

    public function testExportDomainsAsCsv(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');
        $this->createTestDomain('example.com', 443);
        $this->createTestDomain('test.org', 8443);

        $client->loginUser($user);
        $client->request('GET', '/domains/export');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
        
        $contentDisposition = $client->getResponse()->headers->get('content-disposition');
        $this->assertMatchesRegularExpression('/attachment.*\.csv/', $contentDisposition);
        $this->assertMatchesRegularExpression('/domains_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.csv/', $contentDisposition);
    }

    public function testImportPageRequiresAdminRole(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('auditor', 'auditor');

        $client->loginUser($user);
        $client->request('GET', '/domains/import');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testImportPageRendersForAdmin(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');

        $client->loginUser($user);
        $client->request('GET', '/domains/import');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[type="file"][name="csv_file"]');
    }

    public function testImportDomainsFromCsv(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('admin', 'admin');

        // Create a temporary CSV file
        $csvContent = "FQDN,Port,Beschreibung,Status\n";
        $csvContent .= "import1.com,443,Imported domain 1,active\n";
        $csvContent .= "import2.org,8443,Imported domain 2,inactive\n";

        $tmpFile = tmpfile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];
        fwrite($tmpFile, $csvContent);
        fseek($tmpFile, 0);

        $uploadedFile = new UploadedFile(
            $tmpPath,
            'domains.csv',
            'text/csv',
            null,
            true
        );

        $client->loginUser($user);
        $client->request('POST', '/domains/import', [], [
            'csv_file' => $uploadedFile,
        ]);

        $this->assertResponseIsSuccessful();
        
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);
        
        $domain1 = $domainRepo->findOneBy(['fqdn' => 'import1.com']);
        $this->assertNotNull($domain1);
        $this->assertSame(443, $domain1->getPort());
        $this->assertSame('Imported domain 1', $domain1->getDescription());
        $this->assertTrue($domain1->isActive());

        $domain2 = $domainRepo->findOneBy(['fqdn' => 'import2.org']);
        $this->assertNotNull($domain2);
        $this->assertSame(8443, $domain2->getPort());
        $this->assertSame('Imported domain 2', $domain2->getDescription());
        $this->assertFalse($domain2->isActive());

        fclose($tmpFile);
    }

    public function testAdminActionsRequireAdminRole(): void
    {
        $client = $this->buildClient();
        $user = $this->createTestUser('auditor', 'auditor');
        $domain = $this->createTestDomain('example.com', 443);

        $client->loginUser($user);

        // Test various admin routes
        $adminRoutes = [
            '/domains/new',
            '/domains/' . $domain->getId() . '/edit',
            '/domains/import',
            '/domains/export',
        ];

        foreach ($adminRoutes as $route) {
            $client->request('GET', $route);
            $this->assertResponseStatusCodeSame(403, "Route $route should require admin role");
        }
    }

    // ── Pagination ────────────────────────────────────────────────────────────

    public function testIndexPageShowsNoPaginationForFewDomains(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser();

        // DOMAINS_PER_PAGE=5 in .env.test; create fewer domains
        foreach (['a.example.com', 'b.example.com'] as $fqdn) {
            $this->createTestDomain($fqdn);
        }

        $client->loginUser($user);
        $client->request('GET', '/domains');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('nav[aria-label="Domain-Seitennavigation"]');
    }

    public function testIndexPageShowsPaginationWhenExceedingPerPage(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser();

        // DOMAINS_PER_PAGE=5 – create 6 domains to trigger pagination
        foreach (range(1, 6) as $i) {
            $this->createTestDomain(sprintf('domain%02d.example.com', $i));
        }

        $client->loginUser($user);
        $client->request('GET', '/domains');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('nav[aria-label="Domain-Seitennavigation"]');
    }

    public function testIndexPageDefaultsToPageOne(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser();

        foreach (range(1, 6) as $i) {
            $this->createTestDomain(sprintf('domain%02d.example.com', $i));
        }

        $client->loginUser($user);
        $client->request('GET', '/domains');

        $this->assertResponseIsSuccessful();
        // First 5 domains visible (page 1), last one not
        $this->assertSelectorTextContains('body', 'domain01.example.com');
        $this->assertSelectorTextContains('body', 'domain05.example.com');
        $this->assertStringNotContainsString('domain06.example.com', (string) $client->getResponse()->getContent());
    }

    public function testIndexPageTwoShowsCorrectDomains(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser();

        foreach (range(1, 6) as $i) {
            $this->createTestDomain(sprintf('domain%02d.example.com', $i));
        }

        $client->loginUser($user);
        $client->request('GET', '/domains?page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'domain06.example.com');
        $this->assertStringNotContainsString('domain01.example.com', (string) $client->getResponse()->getContent());
    }

    public function testIndexPageWithInvalidPageParamDefaultsToPageOne(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser();
        $this->createTestDomain('only.example.com');

        $client->loginUser($user);
        $client->request('GET', '/domains?page=99');

        $this->assertResponseIsSuccessful();
        // Still on page 1 (clamped) – domain visible
        $this->assertSelectorTextContains('body', 'only.example.com');
    }

    public function testIndexPageShowsTotalDomainCount(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser();

        foreach (range(1, 6) as $i) {
            $this->createTestDomain(sprintf('domain%02d.example.com', $i));
        }

        $client->loginUser($user);
        $client->request('GET', '/domains');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '6 Domains gesamt');
    }


    public function testIndexWithSearchReturnsFilteredResults(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser();

        $this->createTestDomain('alpha.example.com');
        $this->createTestDomain('beta.example.com');

        $client->loginUser($user);
        $client->request('GET', '/domains?search=alpha');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'alpha.example.com');
        $this->assertStringNotContainsString('beta.example.com', (string) $client->getResponse()->getContent());
        $this->assertSelectorTextContains('body', '1 Ergebnis');
    }

    public function testIndexWithEmptySearchBehavesLikeNoFilter(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser();

        $this->createTestDomain('alpha.example.com');
        $this->createTestDomain('beta.example.com');

        $client->loginUser($user);
        $client->request('GET', '/domains?search=');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'alpha.example.com');
        $this->assertSelectorTextContains('body', 'beta.example.com');
    }

    public function testSearchTermIsPreservedOnPagination(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser();

        foreach (range(1, 6) as $i) {
            $this->createTestDomain(sprintf('app%02d.example.com', $i));
        }
        $this->createTestDomain('other.example.com');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/domains?search=app');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('nav[aria-label="Domain-Seitennavigation"]');

        $nextLink = $crawler->filter('nav[aria-label="Domain-Seitennavigation"] a[aria-label="Vor"]')->attr('href');
        $this->assertStringContainsString('search=app', (string) $nextLink);

        $client->request('GET', '/domains?search=app&page=2');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'app06.example.com');
    }

    public function testResetSearchReturnsFullList(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser();

        $this->createTestDomain('alpha.example.com');
        $this->createTestDomain('beta.example.com');

        $client->loginUser($user);
        $client->request('GET', '/domains?search=alpha');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('beta.example.com', (string) $client->getResponse()->getContent());

        $client->request('GET', '/domains');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'alpha.example.com');
        $this->assertSelectorTextContains('body', 'beta.example.com');
    }

    // ── Entry Zero Import ─────────────────────────────────────────────────────

    private function buildEntryZeroCsvFile(string $content): UploadedFile
    {
        $tmpFile = tmpfile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];
        fwrite($tmpFile, $content);
        fseek($tmpFile, 0);

        // Keep a reference so the resource isn't garbage-collected during the request
        $this->tmpFileHandles[] = $tmpFile;

        return new UploadedFile($tmpPath, 'entry_zero.csv', 'text/csv', null, true);
    }

    /**
     * Install a stub TlsConnector in the test container.
     *
     * @param array<string, mixed>|null $tlsReturn  null = unreachable, array = reachable
     */
    private function mockTlsConnector(array|null $tlsReturn = ['subject' => 'CN=test']): void
    {
        $stub = $this->createStub(TlsConnectorInterface::class);
        $stub->method('connect')->willReturn($tlsReturn);
        static::getContainer()->set(TlsConnectorInterface::class, $stub);
    }

    /** @var resource[] */
    private array $tmpFileHandles = [];

    public function testImportEntryZeroPageRendersForAdmin(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser('admin', 'admin');

        $client->loginUser($user);
        $client->request('GET', '/domains/import-entry-zero');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[type="file"][name="csv_file"]');
        $this->assertSelectorTextContains('h2', 'Entry Zero Import');
    }

    public function testImportEntryZeroRequiresAdminRole(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser('auditor', 'auditor');

        $client->loginUser($user);
        $client->request('GET', '/domains/import-entry-zero');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testImportEntryZeroCreatesDomainsFromJsonArray(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser('admin', 'admin');
        $this->mockTlsConnector();

        $csvContent  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $csvContent .= "\"domain1.de\",2,\"[\"\"shop.domain1.de\"\",\"\"www.domain1.de\"\"]\",DE,\"Firma Eins\"\n";

        $client->loginUser($user);
        $client->request('POST', '/domains/import-entry-zero', [], [
            'csv_file' => $this->buildEntryZeroCsvFile($csvContent),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Neu angelegt');

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);

        $d1 = $domainRepo->findOneBy(['fqdn' => 'shop.domain1.de', 'port' => 443]);
        $this->assertNotNull($d1);
        $this->assertSame('Firma Eins', $d1->getDescription());
        $this->assertTrue($d1->isActive());

        $d2 = $domainRepo->findOneBy(['fqdn' => 'www.domain1.de', 'port' => 443]);
        $this->assertNotNull($d2);
        $this->assertSame('Firma Eins', $d2->getDescription());
    }

    public function testImportEntryZeroHandlesSingleSubdomain(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser('admin', 'admin');
        $this->mockTlsConnector();

        $csvContent  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $csvContent .= "\"domain4.de\",1,\"www.domain4.de\",BE,\"Firma Vier\"\n";

        $client->loginUser($user);
        $client->request('POST', '/domains/import-entry-zero', [], [
            'csv_file' => $this->buildEntryZeroCsvFile($csvContent),
        ]);

        $this->assertResponseIsSuccessful();

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);

        $domain = $domainRepo->findOneBy(['fqdn' => 'www.domain4.de', 'port' => 443]);
        $this->assertNotNull($domain);
        $this->assertSame('Firma Vier', $domain->getDescription());
    }

    public function testImportEntryZeroDuplicateUpdatesDescription(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser('admin', 'admin');
        $this->mockTlsConnector();

        // Pre-create the domain
        $this->createTestDomain('www.existing.de', 443);

        $csvContent  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $csvContent .= "\"existing.de\",1,\"www.existing.de\",DE,\"Aktualisierte Firma\"\n";

        $client->loginUser($user);
        $client->request('POST', '/domains/import-entry-zero', [], [
            'csv_file' => $this->buildEntryZeroCsvFile($csvContent),
        ]);

        $this->assertResponseIsSuccessful();

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);

        // Only 1 domain with this FQDN must exist (no duplicate)
        $all = $domainRepo->findBy(['fqdn' => 'www.existing.de', 'port' => 443]);
        $this->assertCount(1, $all);
        $this->assertSame('Aktualisierte Firma', $all[0]->getDescription());

        $this->assertSelectorTextContains('body', 'Aktualisiert');
    }

    public function testImportEntryZeroRejectsInvalidFqdns(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser('admin', 'admin');

        $csvContent  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $csvContent .= "\"bad.de\",1,\"not_a_valid_domain\",DE,\"Fehler Firma\"\n";

        $client->loginUser($user);
        $client->request('POST', '/domains/import-entry-zero', [], [
            'csv_file' => $this->buildEntryZeroCsvFile($csvContent),
        ]);

        $this->assertResponseIsSuccessful();

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);

        $this->assertCount(0, $domainRepo->findAll());
        $this->assertSelectorTextContains('body', 'Fehler');
    }

    public function testImportEntryZeroRejectsFileWithoutSubdomainsColumn(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser('admin', 'admin');

        $csvContent = "\"Main Domain\",Country,Company\n";
        $csvContent .= "\"domain1.de\",DE,\"Firma Eins\"\n";

        $client->loginUser($user);
        $client->request('POST', '/domains/import-entry-zero', [], [
            'csv_file' => $this->buildEntryZeroCsvFile($csvContent),
        ]);

        // Should redirect back with a flash error
        $this->assertResponseRedirects('/domains/import-entry-zero');
    }

    public function testImportEntryZeroFullExampleFile(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser('admin', 'admin');
        $this->mockTlsConnector();

        // Reproduces the EntryZeroImportExample.csv structure:
        // domain1: 4 subdomains, domain2: 2, domain3: 2, domain4: 1 (plain string) → 9 total
        $csvContent  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $csvContent .= "\"domain1.de\",4,\"[\"\"shop-dev.domain1.de\"\",\"\"shop-test.domain1.de\"\",\"\"shop.domain1.de\"\",\"\"www.domain1.de\"\"]\",DE,\"Company 1\"\n";
        $csvContent .= "\"domain2.info\",2,\"[\"\"dev.domain2.info\"\",\"\"test.domain2.info\"\"]\",DE,\"Company 2\"\n";
        $csvContent .= "\"domain3.de\",2,\"[\"\"news.domain3.de\"\",\"\"www.domain3.de\"\"]\",DE,\"Company 3\"\n";
        $csvContent .= "\"domain4.de\",1,\"www.domain4.de\",BE,\"Company 4\"\n";

        $client->loginUser($user);
        $client->request('POST', '/domains/import-entry-zero', [], [
            'csv_file' => $this->buildEntryZeroCsvFile($csvContent),
        ]);

        $this->assertResponseIsSuccessful();

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);

        $this->assertCount(9, $domainRepo->findAll());

        // Spot-check one domain per source row
        $this->assertNotNull($domainRepo->findOneBy(['fqdn' => 'shop.domain1.de',  'port' => 443]));
        $this->assertNotNull($domainRepo->findOneBy(['fqdn' => 'dev.domain2.info', 'port' => 443]));
        $this->assertNotNull($domainRepo->findOneBy(['fqdn' => 'news.domain3.de',  'port' => 443]));
        $this->assertNotNull($domainRepo->findOneBy(['fqdn' => 'www.domain4.de',   'port' => 443]));
    }

    public function testImportEntryZeroSkipsUnreachableDomains(): void
    {
        $client = $this->buildClient();
        $user   = $this->createTestUser('admin', 'admin');
        // Connector returns null = unreachable for all domains
        $this->mockTlsConnector(null);

        $csvContent  = "\"Main Domain\",\"Subdomains Count\",Subdomains,Country,Company\n";
        $csvContent .= "\"domain1.de\",2,\"[\"\"shop.domain1.de\"\",\"\"www.domain1.de\"\"]\",DE,\"Firma Eins\"\n";

        $client->loginUser($user);
        $client->request('POST', '/domains/import-entry-zero', [], [
            'csv_file' => $this->buildEntryZeroCsvFile($csvContent),
        ]);

        $this->assertResponseIsSuccessful();

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $domainRepo = $em->getRepository(Domain::class);

        // No domains must have been persisted
        $this->assertCount(0, $domainRepo->findAll());
        // Page must show the "not reachable" section
        $this->assertSelectorTextContains('body', 'Nicht erreichbar');
    }

}
