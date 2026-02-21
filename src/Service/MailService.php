<?php

namespace App\Service;

use App\Entity\Domain;
use App\Entity\Finding;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class MailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly array $alertRecipients,
    ) {
    }

    public function isConfigured(): bool
    {
        return !empty($this->alertRecipients) && !empty($this->fromEmail);
    }

    /**
     * Returns an array of problems that prevent SMTP from working.
     * The caller can use this for tooltips or health checks.
     *
     * @return string[]
     */
    public function getConfigurationErrors(): array
    {
        $errors = [];

        if (empty($this->fromEmail)) {
            $errors[] = 'Absenderadresse fehlt';
        }
        if (empty($this->alertRecipients)) {
            $errors[] = 'Empfängerliste leer';
        }

        return $errors;
    }

    public function sendFindingAlert(Domain $domain, Finding $finding): bool
    {
        if (empty($this->alertRecipients)) {
            $this->logger->info('SMTP: Keine Empfänger konfiguriert – E-Mail wird nicht gesendet.');
            return false;
        }

        $subject = sprintf(
            '[TLS Monitor] %s – %s für %s:%d',
            $finding->getSeverity(),
            $finding->getFindingType(),
            $domain->getFqdn(),
            $domain->getPort(),
        );

        $details = $finding->getDetails();
        $detailsText = '';
        foreach ($details as $key => $value) {
            $detailsText .= "  {$key}: {$value}\n";
        }

        $body = "TLS Monitor – Neues Finding\n"
            . "=============================\n\n"
            . "Domain:       {$domain->getFqdn()}\n"
            . "Port:         {$domain->getPort()}\n"
            . "Finding-Typ:  {$finding->getFindingType()}\n"
            . "Severity:     {$finding->getSeverity()}\n"
            . "Zeitpunkt:    " . (new \DateTimeImmutable())->format('Y-m-d H:i:s') . "\n";

        if (isset($details['days_remaining'])) {
            $body .= "Verbleibend:  {$details['days_remaining']} Tage\n";
        }

        $body .= "\nDetails:\n{$detailsText}\n";

        return $this->send($this->alertRecipients, $subject, $body);
    }

    public function sendTestMail(string $recipient): bool
    {
        return $this->send(
            [$recipient],
            '[TLS Monitor] Test-Mail',
            "Dies ist eine Test-Mail vom TLS Monitor.\n\n"
            . "Zeitpunkt: " . (new \DateTimeImmutable())->format('Y-m-d H:i:s') . "\n\n"
            . "SMTP-Konfiguration ist funktionsfähig."
        );
    }

    private function send(array $recipients, string $subject, string $body): bool
    {
        try {
            $email = (new Email())
                ->from(sprintf('"%s" <%s>', $this->fromName, $this->fromEmail))
                ->subject($subject)
                ->text($body);

            foreach ($recipients as $recipient) {
                if (!empty(trim($recipient))) {
                    $email->addTo(trim($recipient));
                }
            }

            if (empty($email->getTo())) {
                $this->logger->info('SMTP: Keine gültigen Empfänger – E-Mail wird nicht gesendet.');
                return false;
            }

            $this->mailer->send($email);
            $this->logger->info('E-Mail gesendet: ' . $subject);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('E-Mail-Fehler: ' . $e->getMessage());
            return false;
        }
    }
}
