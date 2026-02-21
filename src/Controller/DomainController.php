<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Service\ValidationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/domains')]
class DomainController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DomainRepository $domainRepository,
        private readonly ValidationService $validationService,
    ) {
    }

    #[Route('', name: 'domain_index')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $scanResults = $session->get('scan_results');
        $session->remove('scan_results');

        return $this->render('domain/index.html.twig', [
            'domains'      => $this->domainRepository->findAllOrderedByFqdn(),
            'scan_results' => $scanResults,
        ]);
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
}
