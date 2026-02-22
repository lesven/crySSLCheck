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
        private readonly ?\Symfony\Component\HttpFoundation\Session\SessionInterface $session = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return !empty($this->alertRecipients) && !empty($this->fromEmail);
    }

    public function getAlertRecipients(): array
    {
        return $this->alertRecipients;
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

        $success = $this->send($this->alertRecipients, $subject, $body);
        if (!$success) {
            $this->logger->warning('SMTP: Alarm-Mail konnte nicht gesendet werden', [
                'domain' => $domain->getFqdn(),
                'port' => $domain->getPort(),
                'recipients' => $this->alertRecipients,
                'subject' => $subject,
                'config_errors' => $this->getConfigurationErrors(),
            ]);
        }

        return $success;
    }

    /**
     * Schreibt einen "nicht gesendet"-Eintrag in den Session-Debug-Log.
     */
    public function recordSkipped(string $subject, string $reason): void
    {
        $this->logToSession($this->alertRecipients, $subject, false, 'nicht gesendet: ' . $reason);
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
        $this->logger->debug('SMTP: sende E-Mail', [
            'recipients' => $recipients,
            'subject' => $subject,
        ]);

        try {
            $email = $this->buildEmail($recipients, $subject, $body);

            if (empty($email->getTo())) {
                $this->logger->info('SMTP: Keine gültigen Empfänger – E-Mail wird nicht gesendet.');
                $this->logToSession($recipients, $subject, false, 'no valid recipients');
                return false;
            }

            $this->mailer->send($email);
            $this->logger->info('E-Mail gesendet: ' . $subject);
            $this->logToSession($recipients, $subject, true);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('E-Mail-Fehler: ' . $e->getMessage(), [
                'recipients' => $recipients,
                'subject' => $subject,
                'mailer_dsn' => getenv('MAILER_DSN') ?: 'n/a',
            ]);
            $this->logToSession($recipients, $subject, false, $e->getMessage());
            return false;
        }
    }

    /**
     * Builds an Email object from the given recipients, subject, and body.
     * Only non-empty trimmed recipients are added as To-addresses.
     */
    private function buildEmail(array $recipients, string $subject, string $body): Email
    {
        $email = (new Email())
            ->from(sprintf('"%s" <%s>', $this->fromName, $this->fromEmail))
            ->subject($subject)
            ->text($body);

        foreach ($recipients as $recipient) {
            if (!empty(trim($recipient))) {
                $email->addTo(trim($recipient));
            }
        }

        return $email;
    }

    /**
     * Appends a debug record for this send attempt to the session mailer_debug log.
     * Does nothing when no session is available or not yet started.
     */
    private function logToSession(array $recipients, string $subject, bool $success, ?string $error = null): void
    {
        if (!$this->session || !$this->session->isStarted()) {
            return;
        }

        $record = [
            'recipients' => $recipients,
            'subject'    => $subject,
            'timestamp'  => (new \DateTimeImmutable())->format('H:i:s'),
            'success'    => $success,
            'error'      => $error,
        ];

        $existing   = $this->session->get('mailer_debug', []);
        $existing[] = $record;
        $this->session->set('mailer_debug', $existing);
    }
}
