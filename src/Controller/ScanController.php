<?php

namespace App\Controller;

use App\Repository\DomainRepository;
use App\Service\MailService;
use App\Service\ScanService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/scan')]
#[IsGranted('ROLE_ADMIN')]
class ScanController extends AbstractController
{
    public function __construct(
        private readonly ScanService $scanService,
        private readonly DomainRepository $domainRepository,
        private readonly MailService $mailService,
    ) {
    }

    #[Route('/{id}', name: 'domain_scan', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function scan(int $id, Request $request): Response
    {
        $domain = $this->domainRepository->find($id);

        if (!$domain) {
            $this->addFlash('error', 'Domain nicht gefunden.');
            return $this->redirectToRoute('domain_index');
        }

        if (!$domain->isActive()) {
            $this->addFlash('error', 'Deaktivierte Domains können nicht gescannt werden.');
            return $this->redirectToRoute('domain_index');
        }

        try {
            $results = $this->scanService->runSingleScan($domain);
            $this->addFlash('success', "Scan für {$domain->getFqdn()}:{$domain->getPort()} abgeschlossen.");
            $request->getSession()->set('scan_results', $results);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Scan-Fehler: ' . $e->getMessage());
        }

        return $this->redirectToRoute('domain_index');
    }

    #[Route('/smtp-test', name: 'smtp_test', methods: ['POST'])]
    public function smtpTest(Request $request): Response
    {
        try {
            $recipient = $request->request->get('recipient', '');
            if (empty($recipient)) {
                throw new \RuntimeException('Kein Empfänger angegeben.');
            }
            $this->mailService->sendTestMail($recipient);
            $this->addFlash('success', 'Test-Mail wurde erfolgreich gesendet.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Test-Mail fehlgeschlagen: ' . $e->getMessage());
        }

        return $this->redirectToRoute('domain_index');
    }
}
