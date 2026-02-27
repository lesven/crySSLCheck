<?php

namespace App\Tests\Unit\Service;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScanRun;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\MailService;
use App\Service\ValidationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(MailService::class)]
class MailServiceTest extends TestCase
{
    private MockObject&MailerInterface $mailer;
    private MockObject&UserRepository $userRepository;
    private ValidationService $validationService;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $domainRepository = $this->createMock(\App\Repository\DomainRepository::class);
        $this->validationService = new ValidationService($domainRepository);
    }

    /** @param list<string> $recipients */
    private function createService(array $recipients = ['alert@example.com'], string $fromEmail = 'from@example.com'): MailService
    {
        return new MailService(
            mailer: $this->mailer,
            logger: new NullLogger(),
            fromEmail: $fromEmail,
            fromName: 'TLS Monitor',
            userRepository: $this->userRepository,
            alertRecipients: $recipients,
            validationService: $this->validationService,
        );
    }

    private function createFinding(): array
    {
        $domain = new Domain();
        $domain->setFqdn('example.com');
        $domain->setPort(443);

        $scanRun = new ScanRun();

        $finding = new Finding();
        $finding->setDomain($domain);
        $finding->setScanRun($scanRun);
        $finding->setFindingType('CERT_EXPIRY');
        $finding->setSeverity('high');
        $finding->setDetails(['days_remaining' => 5]);

        return [$domain, $finding];
    }

    public function testResolveRecipientsFallsBackToEnvWhenDbIsEmpty(): void
    {
        $this->userRepository->method('findAlertRecipients')->willReturn([]);
        $service = $this->createService(['env@example.com']);

        $this->assertSame(['env@example.com'], $service->getAlertRecipients());
        $this->assertTrue($service->isConfigured());
    }

    public function testResolveRecipientsUsesDbWhenAvailable(): void
    {
        $dbUser = (new User())
            ->setUsername('db')
            ->setPassword('x')
            ->setRole('auditor')
            ->setEmail('db@example.com')
            ->setNotifyAlerts(true);

        $this->userRepository->method('findAlertRecipients')->willReturn([$dbUser]);
        $service = $this->createService(['env@example.com']);

        $this->assertSame(['db@example.com'], $service->getAlertRecipients());
    }

    public function testIsConfiguredReturnsFalseWhenNoDbAndNoEnvRecipients(): void
    {
        $this->userRepository->method('findAlertRecipients')->willReturn([]);
        $service = $this->createService([]);

        $this->assertFalse($service->isConfigured());
    }

    public function testSendFindingAlertUsesResolvedRecipients(): void
    {
        $this->userRepository->method('findAlertRecipients')->willReturn([]);
        $service = $this->createService(['env@example.com']);
        [$domain, $finding] = $this->createFinding();

        $sentEmail = null;
        $this->mailer->expects($this->once())->method('send')->with($this->callback(function (Email $email) use (&$sentEmail) {
            $sentEmail = $email;
            return true;
        }));

        $result = $service->sendFindingAlert($domain, $finding);

        $this->assertTrue($result);
        $this->assertNotNull($sentEmail);
        $toAddresses = array_map(fn ($addr) => $addr->getAddress(), $sentEmail->getTo());
        $this->assertSame(['env@example.com'], $toAddresses);
    }
}
