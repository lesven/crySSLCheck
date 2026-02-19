<?php

namespace App\Controller;

use App\Database;
use App\Model\ScanRun;
use App\Service\MailService;

class HealthController
{
    public function index(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $response = [
            'status'           => 'ok',
            'db'               => 'ok',
            'last_scan_run'    => null,
            'last_scan_status' => null,
            'smtp'             => MailService::isConfigured() ? 'configured' : 'not_configured',
        ];

        $httpStatus = 200;

        // Check database
        if (!Database::isAvailable()) {
            $response['status'] = 'error';
            $response['db'] = 'error';
            $httpStatus = 503;
        } else {
            // Get last scan run
            try {
                $lastRun = ScanRun::findLatest();
                if ($lastRun) {
                    $response['last_scan_run'] = $lastRun['finished_at'];
                    $response['last_scan_status'] = $lastRun['status'];
                }
            } catch (\Exception $e) {
                $response['status'] = 'error';
                $response['db'] = 'error';
                $httpStatus = 503;
            }
        }

        http_response_code($httpStatus);
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
