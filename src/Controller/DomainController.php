<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Service\MailService;
use App\Service\ValidationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/domains')]
class DomainController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DomainRepository $domainRepository,
        private readonly ValidationService $validationService,
        private readonly MailService $mailService,
    ) {
    }

    #[Route('', name: 'domain_index')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $scanResults  = $session->get('scan_results');
        $mailerDebug  = $session->get('mailer_debug');
        $session->remove('scan_results');
        $session->remove('mailer_debug');

        return $this->render('domain/index.html.twig', [
            'domains'           => $this->domainRepository->findAllOrderedByFqdn(),
            'scan_results'      => $scanResults,
            'mailer_debug'      => $mailerDebug,
            'mailer_configured' => $this->mailService->isConfigured(),
            'alert_recipients'  => implode(', ', $this->mailService->getAlertRecipients()),
        ]);
    }

    #[Route('/import', name: 'domain_import', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function import(Request $request): Response
    {
        $results = null;

        if ($request->isMethod('POST')) {
            $file = $request->files->get('csv_file');

            if (!$file || !$file->isValid()) {
                $this->addFlash('danger', 'Bitte eine gültige CSV-Datei hochladen.');
                return $this->redirectToRoute('domain_import');
            }

            $results = ['created' => 0, 'updated' => 0, 'errors' => []];
            $handle = fopen($file->getPathname(), 'r');

            if ($handle === false) {
                $this->addFlash('danger', 'Datei konnte nicht gelesen werden.');
                return $this->redirectToRoute('domain_import');
            }

            try {
                $header = fgetcsv($handle);
                if ($header === false) {
                    $this->addFlash('danger', 'CSV-Datei ist leer oder ungültig.');
                    return $this->redirectToRoute('domain_import');
                }

                // Normalize header keys (trim + lowercase)
                $header = array_map(fn (string $h) => strtolower(trim($h)), $header);

                $fqdnCol        = array_search('fqdn', $header, true);
                $portCol        = array_search('port', $header, true);
                $descriptionCol = array_search('beschreibung', $header, true);
                $statusCol      = array_search('status', $header, true);

                if ($fqdnCol === false || $portCol === false) {
                    $this->addFlash('danger', 'CSV muss mindestens die Spalten "FQDN" und "Port" enthalten.');
                    return $this->redirectToRoute('domain_import');
                }

                $lineNumber = 1;
                while (($row = fgetcsv($handle)) !== false) {
                    ++$lineNumber;

                    if (empty(array_filter($row, fn ($v) => $v !== null && $v !== ''))) {
                        continue;
                    }

                    $fqdn = trim($row[$fqdnCol] ?? '');
                    $port = isset($row[$portCol]) ? (int) trim($row[$portCol]) : 0;

                    $validationErrors = $this->validationService->validateDomainForImport($fqdn, $port);
                    if (!empty($validationErrors)) {
                        $results['errors'][] = sprintf('Zeile %d (%s:%d): %s', $lineNumber, $fqdn, $port, implode(', ', $validationErrors));
                        continue;
                    }

                    $description = null;
                    if ($descriptionCol !== false) {
                        $rawDescription = trim($row[$descriptionCol] ?? '');
                        $description    = $rawDescription !== '' ? $rawDescription : null;
                    }

                    $status = null;
                    if ($statusCol !== false) {
                        $rawStatus = strtolower(trim($row[$statusCol] ?? ''));
                        $status    = in_array($rawStatus, ['active', 'inactive'], true) ? $rawStatus : null;
                    }

                    $existing = $this->domainRepository->findOneBy(['fqdn' => $fqdn, 'port' => $port]);

                    if ($existing !== null) {
                        if ($descriptionCol !== false) {
                            $existing->setDescription($description);
                        }
                        if ($status !== null) {
                            $existing->setStatus($status);
                        }
                        ++$results['updated'];
                    } else {
                        $domain = new Domain();
                        $domain->setFqdn($fqdn);
                        $domain->setPort($port);
                        $domain->setDescription($description);
                        if ($status !== null) {
                            $domain->setStatus($status);
                        }
                        $this->entityManager->persist($domain);
                        ++$results['created'];
                    }
                }
            } finally {
                fclose($handle);
            }

            $this->entityManager->flush();
        }

        return $this->render('domain/import.html.twig', [
            'results' => $results,
        ]);
    }

    #[Route('/export', name: 'domain_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function export(): StreamedResponse
    {
        $domains = $this->domainRepository->findAllOrderedByFqdn();

        $response = new StreamedResponse(function () use ($domains): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }
            fputcsv($handle, ['FQDN', 'Port', 'Beschreibung', 'Status']);
            foreach ($domains as $domain) {
                fputcsv($handle, [
                    $domain->getFqdn(),
                    $domain->getPort(),
                    $domain->getDescription() ?? '',
                    $domain->isActive() ? 'active' : 'inactive',
                ]);
            }
            fclose($handle);
        });

        $filename = 'domains_' . (new \DateTimeImmutable())->format('Y-m-d_H-i-s') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    #[Route('/new', name: 'domain_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $errors = [];
        $fqdn = '';
        $port = 443;
        $description = '';

        if ($request->isMethod('POST')) {
            $fqdn = trim($request->request->get('fqdn', ''));
            $port = (int) $request->request->get('port', 0);
            $description = trim($request->request->get('description', ''));

            $errors = $this->validationService->validateDomain($fqdn, $port);

            if (empty($errors)) {
                $domain = new Domain();
                $domain->setFqdn($fqdn);
                $domain->setPort($port);
                $domain->setDescription($description ?: null);
                $this->entityManager->persist($domain);
                $this->entityManager->flush();

                $this->addFlash('success', 'Domain erfolgreich angelegt.');
                return $this->redirectToRoute('domain_index');
            }
        }

        return $this->render('domain/form.html.twig', [
            'isEdit'      => false,
            'errors'      => $errors,
            'fqdn'        => $fqdn,
            'port'        => $port,
            'description' => $description,
            'domain'      => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'domain_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(int $id, Request $request): Response
    {
        $domain = $this->domainRepository->find($id);
        if (!$domain) {
            throw $this->createNotFoundException('Domain nicht gefunden.');
        }

        $errors = [];
        $fqdn = $domain->getFqdn();
        $port = $domain->getPort();
        $description = $domain->getDescription() ?? '';

        if ($request->isMethod('POST')) {
            $fqdn = trim($request->request->get('fqdn', ''));
            $port = (int) $request->request->get('port', 0);
            $description = trim($request->request->get('description', ''));

            $errors = $this->validationService->validateDomain($fqdn, $port, $id);

            if (empty($errors)) {
                $domain->setFqdn($fqdn);
                $domain->setPort($port);
                $domain->setDescription($description ?: null);
                $this->entityManager->flush();

                $this->addFlash('success', 'Domain erfolgreich aktualisiert.');
                return $this->redirectToRoute('domain_index');
            }
        }

        return $this->render('domain/form.html.twig', [
            'isEdit'      => true,
            'errors'      => $errors,
            'fqdn'        => $fqdn,
            'port'        => $port,
            'description' => $description,
            'domain'      => $domain,
        ]);
    }

    #[Route('/{id}/toggle', name: 'domain_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggle(int $id): Response
    {
        $domain = $this->domainRepository->find($id);
        if (!$domain) {
            throw $this->createNotFoundException('Domain nicht gefunden.');
        }

        $domain->toggleStatus();
        $this->entityManager->flush();

        $this->addFlash('success', 'Domain-Status erfolgreich geändert.');
        return $this->redirectToRoute('domain_index');
    }

    #[Route('/{id}/delete', name: 'domain_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id, Request $request): Response
    {
        $domain = $this->domainRepository->find($id);
        if (!$domain) {
            throw $this->createNotFoundException('Domain nicht gefunden.');
        }

        if (!$this->isCsrfTokenValid('delete-domain-' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('domain_index');
        }

        $this->entityManager->remove($domain);
        $this->entityManager->flush();

        $this->addFlash('success', 'Domain erfolgreich gelöscht.');
        return $this->redirectToRoute('domain_index');
    }

    #[Route('/delete-all', name: 'domain_delete_all', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteAll(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-all-domains', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Ungültiges CSRF-Token.');
            return $this->redirectToRoute('domain_index');
        }

        $this->entityManager->createQuery('DELETE FROM App\Entity\Domain d')->execute();

        $this->addFlash('success', 'Alle Domains wurden erfolgreich gelöscht.');
        return $this->redirectToRoute('domain_index');
    }
}
