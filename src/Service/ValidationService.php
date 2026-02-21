<?php

namespace App\Service;

use App\Repository\DomainRepository;

class ValidationService
{
    public function __construct(
        private readonly DomainRepository $domainRepository,
        private readonly bool $allowIpAddresses = true,
    ) {
    }

    /**
     * @return array<string, string> Field-name => error-message
     */
    public function validateDomain(string $fqdn, int $port, ?int $excludeId = null): array
    {
        $errors = [];

        if (empty($fqdn)) {
            $errors['fqdn'] = 'FQDN ist ein Pflichtfeld.';
        } elseif (!$this->isValidFqdn($fqdn)) {
            $errors['fqdn'] = 'Ungültiges FQDN-Format. Beispiel: example.com';
        }

        if ($port < 1 || $port > 65535) {
            $errors['port'] = 'Port muss zwischen 1 und 65535 liegen.';
        }

        if (empty($errors) && $this->domainRepository->isDuplicate($fqdn, $port, $excludeId)) {
            $errors['fqdn'] = "Die Kombination {$fqdn}:{$port} existiert bereits.";
        }

        return $errors;
    }

    public function isValidFqdn(string $fqdn): bool
    {
        if ($this->allowIpAddresses && filter_var($fqdn, FILTER_VALIDATE_IP)) {
            return true;
        }

        return (bool) preg_match('/^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/', $fqdn);
    }
}
