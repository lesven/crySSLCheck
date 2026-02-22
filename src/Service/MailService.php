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
        $record = [
            'recipients' => $this->alertRecipients,
            'subject'    => $subject,
            'timestamp'  => (new \DateTimeImmutable())->format('H:i:s'),
            'success'    => false,
            'error'      => 'nicht gesendet: ' . $reason,
        ];

        if ($this->session && $this->session->isStarted()) {
            $existing   = $this->session->get('mailer_debug', []);
            $existing[] = $record;
            $this->session->set('mailer_debug', $existing);
        }
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
        // baseline record that we'll enrich below
        $record = [
            'recipients' => $recipients,
            'subject' => $subject,
            'timestamp' => (new \DateTimeImmutable())->format('H:i:s'),
            'success' => null,
            'error' => null,
        ];

        $this->logger->debug('SMTP: sende E-Mail', [
            'recipients' => $recipients,
            'subject' => $subject,
        ]);

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
                $record['success'] = false;
                $record['error'] = 'no valid recipients';
                if ($this->session && $this->session->isStarted()) {
                    $existing = $this->session->get('mailer_debug', []);
                    $existing[] = $record;
                    $this->session->set('mailer_debug', $existing);
                }
                return false;
            }

            $this->mailer->send($email);
            $this->logger->info('E-Mail gesendet: ' . $subject);
            $record['success'] = true;
            if ($this->session && $this->session->isStarted()) {
                $existing = $this->session->get('mailer_debug', []);
                $existing[] = $record;
                $this->session->set('mailer_debug', $existing);
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('E-Mail-Fehler: ' . $e->getMessage(), [
                'recipients' => $recipients,
                'subject' => $subject,
                'mailer_dsn' => getenv('MAILER_DSN') ?: 'n/a',
            ]);
            $record['success'] = false;
            $record['error'] = $e->getMessage();
            if ($this->session && $this->session->isStarted()) {
                $existing = $this->session->get('mailer_debug', []);
                $existing[] = $record;
                $this->session->set('mailer_debug', $existing);
            }
            return false;
        }
    }
}
