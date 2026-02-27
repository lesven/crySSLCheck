<?php

namespace App\Tests\Integration\Controller;

use App\Controller\FindingController;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScanRun;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(FindingController::class)]
class FindingControllerTest extends WebTestCase
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

    private function loginUser(KernelBrowser $client, string $role = 'admin'): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername('testuser');
        $user->setEmail('test@example.com');
        $user->setRole($role);
        $user->setPassword($hasher->hashPassword($user, 'Test123!@#'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $user;
    }

    private function createDomainAndFindings(int $count, string $type = 'CERT_EXPIRY'): ScanRun
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $domain = new Domain();
        $domain->setFqdn('pagination-test.example.com');
        $domain->setPort(443);
        $em->persist($domain);

        $scanRun = new ScanRun();
        $scanRun->finish('success');
        $em->persist($scanRun);

        for ($i = 0; $i < $count; $i++) {
            $finding = new Finding();
            $finding->setDomain($domain);
            $finding->setScanRun($scanRun);
            $finding->setFindingType($type);
            $finding->setSeverity('high');
            $finding->setStatus('new');
            $finding->setDetails([]);
            $em->persist($finding);
        }

        $em->flush();

        return $scanRun;
    }

    // ── Grundlegender Zugriff ─────────────────────────────────────────────────

    public function testIndexRequiresAuthentication(): void
    {
        $client = $this->buildClient();
        $client->request('GET', '/findings');
        $this->assertResponseRedirects('/login');
    }

    public function testIndexRendersForAuthenticatedUser(): void
    {
        $client = $this->buildClient();
        $this->loginUser($client);
        $client->request('GET', '/findings');
        $this->assertResponseIsSuccessful();
    }

    // ── Page-Clamping Regression ──────────────────────────────────────────────
    // Reproduziert den Bug: page=999 mit aktivem Filter → leere Seite trotz Daten

    public function testPageOutOfRangeIsClampedToLastPage(): void
    {
        $client = $this->buildClient();
        $this->loginUser($client);

        // 3 Findings → 1 Seite (PAGE_SIZE=50)
        $this->createDomainAndFindings(3, 'CERT_EXPIRY');

        $client->request('GET', '/findings?page=999&problems_only=1');
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        // Muss "3 von 3" oder ähnlich zeigen – NICHT "0 von 3"
        $this->assertStringContainsString('3 von 3', $content);
    }

    public function testPageOutOfRangeWithCurrentRunFilter(): void
    {
        $client = $this->buildClient();
        $this->loginUser($client);

        $this->createDomainAndFindings(2, 'CERT_EXPIRY');

        $client->request('GET', '/findings?page=999&current_run=1');
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('2 von 2', $content);
    }

    public function testPageOutOfRangeWithBothFilters(): void
    {
        $client = $this->buildClient();
        $this->loginUser($client);

        $em = static::getContainer()->get(EntityManagerInterface::class);

        $domain = new Domain();
        $domain->setFqdn('combined-test.example.com');
        $domain->setPort(443);
        $em->persist($domain);

        $scanRun = new ScanRun();
        $scanRun->finish('success');
        $em->persist($scanRun);

        // 1x OK + 2x Problem im aktuellen Run
        foreach (['OK' => 'ok', 'CERT_EXPIRY' => 'high', 'TLS_VERSION' => 'medium'] as $type => $severity) {
            $finding = new Finding();
            $finding->setDomain($domain);
            $finding->setScanRun($scanRun);
            $finding->setFindingType($type);
            $finding->setSeverity($severity);
            $finding->setStatus('new');
            $finding->setDetails([]);
            $em->persist($finding);
        }
        $em->flush();

        $client->request('GET', '/findings?page=999&problems_only=1&current_run=1');
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        // Nur 2 Probleme im aktuellen Run
        $this->assertStringContainsString('2 von 2', $content);
    }

    // ── Null-RunId Edge-Case ──────────────────────────────────────────────────
    // Wenn current_run=1 aber kein abgeschlossener Run existiert → 0 Ergebnisse

    public function testCurrentRunFilterWithNoFinishedRunShowsEmptyResult(): void
    {
        $client = $this->buildClient();
        $this->loginUser($client);

        // Kein ScanRun in der DB
        $client->request('GET', '/findings?current_run=1');
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('Keine Findings vorhanden', $content);
    }

    // ── Korrekte Pagination bei aktiven Filtern ───────────────────────────────

    public function testPaginationLinksPreserveProblemsOnlyFilter(): void
    {
        $client = $this->buildClient();
        $this->loginUser($client);

        // 55 Problem-Findings → 2 Seiten (PAGE_SIZE=50)
        $this->createDomainAndFindings(55, 'CERT_EXPIRY');

        $client->request('GET', '/findings?problems_only=1');
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        // Seite 2 muss als Link vorhanden sein und den Filter erhalten
        $this->assertStringContainsString('problems_only=1', $content);
        $this->assertStringContainsString('page=2', $content);
    }

    public function testSearchFiltersFindingsAndPreservesParameters(): void
    {
        $client = $this->buildClient();
        $this->loginUser($client);

        $em = static::getContainer()->get(EntityManagerInterface::class);

        $matchingDomain = new Domain();
        $matchingDomain->setFqdn('search-example.com');
        $matchingDomain->setPort(443);
        $em->persist($matchingDomain);

        $nonMatchingDomain = new Domain();
        $nonMatchingDomain->setFqdn('other-domain.net');
        $nonMatchingDomain->setPort(443);
        $em->persist($nonMatchingDomain);

        $scanRun = new ScanRun();
        $scanRun->finish('success');
        $em->persist($scanRun);

        $matchingFinding = new Finding();
        $matchingFinding->setDomain($matchingDomain);
        $matchingFinding->setScanRun($scanRun);
        $matchingFinding->setFindingType('CERT_EXPIRY');
        $matchingFinding->setSeverity('high');
        $matchingFinding->setStatus('new');
        $matchingFinding->setDetails([]);
        $em->persist($matchingFinding);

        $otherFinding = new Finding();
        $otherFinding->setDomain($nonMatchingDomain);
        $otherFinding->setScanRun($scanRun);
        $otherFinding->setFindingType('CERT_EXPIRY');
        $otherFinding->setSeverity('high');
        $otherFinding->setStatus('new');
        $otherFinding->setDetails([]);
        $em->persist($otherFinding);

        $em->flush();

        $client->request('GET', '/findings?search=example&current_run=1&problems_only=1');
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('search-example.com', $content);
        $this->assertStringNotContainsString('other-domain.net', $content);
    }

    public function testPaginationLinksPreserveCurrentRunFilter(): void
    {
        $client = $this->buildClient();
        $this->loginUser($client);

        // 55 Findings → 2 Seiten
        $this->createDomainAndFindings(55, 'CERT_EXPIRY');

        $client->request('GET', '/findings?current_run=1');
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('current_run=1', $content);
        $this->assertStringContainsString('page=2', $content);
    }
}
