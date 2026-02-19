<?php

namespace App\Controller;

use App\Model\Domain;
use App\Service\AuthService;
use App\Service\ScanService;

class ScanController
{
    public function scan(): void
    {
        AuthService::requireAdmin();

        $id = (int) ($_POST['domain_id'] ?? 0);
        $domain = Domain::findById($id);

        if (!$domain) {
            $_SESSION['flash_error'] = 'Domain nicht gefunden.';
            header('Location: /index.php?action=domains');
            exit;
        }

        if ($domain['status'] !== 'active') {
            $_SESSION['flash_error'] = 'Deaktivierte Domains können nicht gescannt werden.';
            header('Location: /index.php?action=domains');
            exit;
        }

        try {
            $results = ScanService::runSingleScan($id);
            $_SESSION['flash_success'] = "Scan für {$domain['fqdn']}:{$domain['port']} abgeschlossen.";
            $_SESSION['scan_results'] = $results;
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = "Scan-Fehler: " . $e->getMessage();
        }

        header('Location: /index.php?action=domains');
        exit;
    }
}
