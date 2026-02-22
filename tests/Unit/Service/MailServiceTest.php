<?php

namespace App\Tests\Unit\Service;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Entity\ScanRun;
use App\Service\MailService;
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

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
    }

    private function createService(
        array $recipients = ['alert@example.com'],
        string $fromEmail = 'from@example.com',
        string $fromName = 'TLS Monitor',
    ): MailService {
        return new MailService(
            mailer: $this->mailer,
            logger: new NullLogger(),
            fromEmail: $fromEmail,
            fromName: $fromName,
            alertRecipients: $recipients,
        );
    }

    private function createFinding(string $type = 'CERT_EXPIRY', string $severity = 'high'): array
    {
        $domain = new Domain();
        $domain->setFqdn('example.com');
        $domain->setPort(443);

        $scanRun = new ScanRun();

        $finding = new Finding();
        $finding->setDomain($domain);
        $finding->setScanRun($scanRun);
        $finding->setFindingType($type);
        $finding->setSeverity($severity);
        $finding->setDetails(['days_remaining' => 5, 'expiry_date' => '2026-01-01']);

        return [$domain, $finding];
    }

    // ── isConfigured ─────────────────────────────────────────────────────────

    public function testIsConfiguredReturnsTrueWithRecipientsAndFromEmail(): void
    {
        $service = $this->createService(['alert@example.com'], 'from@example.com');
        $this->assertTrue($service->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenNoRecipients(): void
    {
        $service = $this->createService([], 'from@example.com');
        $this->assertFalse($service->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenFromEmailIsEmpty(): void
    {
        $service = $this->createService(['alert@example.com'], '');
        $this->assertFalse($service->isConfigured());
    }

    // ── getAlertRecipients ────────────────────────────────────────────────────

    public function testGetAlertRecipientsReturnsConfiguredList(): void
    {
        $recipients = ['a@example.com', 'b@example.com'];
        $service = $this->createService($recipients);
        $this->assertSame($recipients, $service->getAlertRecipients());
    }

    // ── getConfigurationErrors ────────────────────────────────────────────────

    public function testGetConfigurationErrorsReturnsEmptyWhenFullyConfigured(): void
    {
        $service = $this->createService(['alert@example.com'], 'from@example.com');
        $this->assertEmpty($service->getConfigurationErrors());
    }

    public function testGetConfigurationErrorsReportsMissingFromEmail(): void
    {
        $service = $this->createService(['alert@example.com'], '');
        $errors = $service->getConfigurationErrors();
        $this->assertNotEmpty($errors);
        $errorText = implode(' ', $errors);
        $this->assertStringContainsString('Absenderadresse', $errorText);
    }

    public function testGetConfigurationErrorsReportsEmptyRecipientList(): void
    {
        $service = $this->createService([], 'from@example.com');
        $errors = $service->getConfigurationErrors();
        $this->assertNotEmpty($errors);
        $errorText = implode(' ', $errors);
        $this->assertStringContainsString('leer', $errorText);
    }

    public function testGetConfigurationErrorsReportsBothMissingFields(): void
    {
        $service = $this->createService([], '');
        $errors = $service->getConfigurationErrors();
        $this->assertCount(2, $errors);
    }

    // ── sendFindingAlert ──────────────────────────────────────────────────────

    public function testSendFindingAlertReturnsFalseWhenNoRecipients(): void
    {
        $service = $this->createService([]);
        [$domain, $finding] = $this->createFinding();

        $this->mailer->expects($this->never())->method('send');
        $result = $service->sendFindingAlert($domain, $finding);

        $this->assertFalse($result);
    }

    public function testSendFindingAlertSendsMailAndReturnsTrue(): void
    {
        $service = $this->createService(['alert@example.com']);
        [$domain, $finding] = $this->createFinding('CERT_EXPIRY', 'high');

        $this->mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(Email::class));

        $result = $service->sendFindingAlert($domain, $finding);
        $this->assertTrue($result);
    }

    public function testSendFindingAlertReturnsFalseOnMailerException(): void
    {
        $service = $this->createService(['alert@example.com']);
        [$domain, $finding] = $this->createFinding();

        $this->mailer
            ->method('send')
            ->willThrowException(new \RuntimeException('SMTP connection failed'));

        $result = $service->sendFindingAlert($domain, $finding);
        $this->assertFalse($result);
    }

    public function testSendFindingAlertSkipsEmptyRecipients(): void
    {
        // One valid, one empty - should send to only the valid one
        $service = $this->createService(['', 'valid@example.com', '  ']);
        [$domain, $finding] = $this->createFinding();

        $sentEmail = null;
        $this->mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email) use (&$sentEmail) {
                $sentEmail = $email;
                return true;
            }));

        $service->sendFindingAlert($domain, $finding);

        $this->assertNotNull($sentEmail);
        $toAddresses = array_map(fn($addr) => $addr->getAddress(), $sentEmail->getTo());
        $this->assertContains('valid@example.com', $toAddresses);
        $this->assertCount(1, $toAddresses);
    }

    // ── sendTestMail ──────────────────────────────────────────────────────────

    public function testSendTestMailSendsToRecipient(): void
    {
        $service = $this->createService(['alert@example.com']);

        $sentEmail = null;
        $this->mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email) use (&$sentEmail) {
                $sentEmail = $email;
                return true;
            }));

        $result = $service->sendTestMail('test@example.com');

        $this->assertTrue($result);
        $this->assertNotNull($sentEmail);
        $toAddresses = array_map(fn($addr) => $addr->getAddress(), $sentEmail->getTo());
        $this->assertContains('test@example.com', $toAddresses);
    }

    public function testSendTestMailReturnsFalseOnMailerException(): void
    {
        $service = $this->createService(['alert@example.com']);

        $this->mailer
            ->method('send')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $result = $service->sendTestMail('test@example.com');
        $this->assertFalse($result);
    }

    // ── recordSkipped ─────────────────────────────────────────────────────────

    public function testRecordSkippedDoesNotSendEmail(): void
    {
        $service = $this->createService(['alert@example.com']);

        $this->mailer->expects($this->never())->method('send');

        $service->recordSkipped('[TLS Monitor] high – CERT_EXPIRY für example.com:443', 'already known');
    }
}
