<?php

namespace App\Service;

class MailService
{
    private static function log(string $message): void
    {
        $logFile = __DIR__ . '/../../logs/scan.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] [MAIL] $message\n", FILE_APPEND);
    }

    public static function isConfigured(): bool
    {
        return !empty(SMTP_HOST) && !empty(SMTP_FROM);
    }

    public static function sendFindingAlert(array $domain, array $finding): bool
    {
        if (!self::isConfigured()) {
            self::log("SMTP nicht konfiguriert – E-Mail wird nicht gesendet.");
            return false;
        }

        if (empty(ALERT_RECIPIENTS)) {
            self::log("Keine Empfänger konfiguriert – E-Mail wird nicht gesendet.");
            return false;
        }

        $subject = "[TLS Monitor] {$finding['severity']} – {$finding['finding_type']} für {$domain['fqdn']}:{$domain['port']}";

        $details = $finding['details'];
        $detailsText = '';
        if (is_array($details)) {
            foreach ($details as $key => $value) {
                $detailsText .= "  $key: $value\n";
            }
        }

        $body = "TLS Monitor – Neues Finding\n";
        $body .= "=============================\n\n";
        $body .= "Domain:       {$domain['fqdn']}\n";
        $body .= "Port:         {$domain['port']}\n";
        $body .= "Finding-Typ:  {$finding['finding_type']}\n";
        $body .= "Severity:     {$finding['severity']}\n";
        $body .= "Zeitpunkt:    " . date('Y-m-d H:i:s') . "\n";
        if (isset($details['days_remaining'])) {
            $body .= "Verbleibend:  {$details['days_remaining']} Tage\n";
        }
        $body .= "\nDetails:\n$detailsText\n";

        return self::send(ALERT_RECIPIENTS, $subject, $body);
    }

    public static function sendTestMail(string $recipient): bool
    {
        if (!self::isConfigured()) {
            throw new \RuntimeException("SMTP ist nicht konfiguriert. Bitte config.php prüfen.");
        }

        return self::send(
            [$recipient],
            '[TLS Monitor] Test-Mail',
            "Dies ist eine Test-Mail vom TLS Monitor.\n\nZeitpunkt: " . date('Y-m-d H:i:s') . "\n\nSMTP-Konfiguration ist funktionsfähig."
        );
    }

    private static function send(array $recipients, string $subject, string $body): bool
    {
        try {
            // Check if PHPMailer is available
            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = SMTP_HOST;
                $mail->Port = SMTP_PORT;
                $mail->CharSet = 'UTF-8';

                if (!empty(SMTP_USER)) {
                    $mail->SMTPAuth = true;
                    $mail->Username = SMTP_USER;
                    $mail->Password = SMTP_PASS;
                }

                switch (SMTP_ENCRYPTION) {
                    case 'tls':
                        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                        break;
                    case 'ssl':
                        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                        break;
                    default:
                        $mail->SMTPSecure = '';
                        $mail->SMTPAutoTLS = false;
                        break;
                }

                $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
                foreach ($recipients as $recipient) {
                    $mail->addAddress($recipient);
                }

                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->send();

                self::log("E-Mail gesendet: $subject");
                return true;
            } else {
                // Fallback: use PHP mail()
                $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

                foreach ($recipients as $recipient) {
                    mail($recipient, $subject, $body, $headers);
                }

                self::log("E-Mail gesendet (mail()): $subject");
                return true;
            }
        } catch (\Exception $e) {
            self::log("E-Mail-Fehler: " . $e->getMessage());
            return false;
        }
    }
}
