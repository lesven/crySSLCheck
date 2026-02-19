<?php

namespace App\Service;

class ValidationService
{
    public static function validateDomain(string $fqdn, int $port, ?int $excludeId = null): array
    {
        $errors = [];

        // FQDN validation
        if (empty($fqdn)) {
            $errors['fqdn'] = 'FQDN ist ein Pflichtfeld.';
        } elseif (!self::isValidFqdn($fqdn)) {
            $errors['fqdn'] = 'Ungültiges FQDN-Format. Beispiel: example.com';
        }

        // Port validation
        if ($port < 1 || $port > 65535) {
            $errors['port'] = 'Port muss zwischen 1 und 65535 liegen.';
        }

        // Duplicate check (server-side)
        if (empty($errors)) {
            if (\App\Model\Domain::isDuplicate($fqdn, $port, $excludeId)) {
                $errors['fqdn'] = "Die Kombination {$fqdn}:{$port} existiert bereits.";
            }
        }

        return $errors;
    }

    public static function isValidFqdn(string $fqdn): bool
    {
        // Check if IP addresses are allowed
        if (ALLOW_IP_ADDRESSES && filter_var($fqdn, FILTER_VALIDATE_IP)) {
            return true;
        }

        // FQDN regex validation
        return (bool) preg_match('/^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/', $fqdn);
    }
}
