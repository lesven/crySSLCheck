<?php

namespace App\Controller;

use App\Repository\ScanRunRepository;
use App\Service\MailService;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ScanRunRepository $scanRunRepository,
        private readonly MailService $mailService,
    ) {
    }

    #[Route('/health', name: 'health')]
    public function index(): JsonResponse
    {
        $response = [
            'status'           => 'ok',
            'db'               => 'ok',
            'last_scan_run'    => null,
            'last_scan_status' => null,
            'smtp'             => $this->mailService->isConfigured() ? 'configured' : 'not_configured',
        ];

        $httpStatus = 200;

        try {
            $this->entityManager->getConnection()->executeQuery('SELECT 1');

            $lastRun = $this->scanRunRepository->findLatestFinished();
            if ($lastRun) {
                $response['last_scan_run'] = $lastRun->getFinishedAt()?->format('Y-m-d H:i:s');
                $response['last_scan_status'] = $lastRun->getStatus();
            }
        } catch (\Throwable) {
            $response['status'] = 'error';
            $response['db'] = 'error';
            $httpStatus = 503;
        }

        return new JsonResponse($response, $httpStatus);
    }
}
