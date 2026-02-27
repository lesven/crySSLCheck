<?php

namespace App\Controller;

use App\Repository\FindingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/findings')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class FindingController extends AbstractController
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly FindingRepository $findingRepository,
    ) {
    }

    #[Route('', name: 'finding_index')]
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $problemsOnly = $request->query->getBoolean('problems_only', false);
        $currentRunOnly = $request->query->getBoolean('current_run', false);

        $runId = null;
        if ($currentRunOnly) {
            $runId = $this->findingRepository->findLatestRunId();
            // Kein abgeschlossener Run vorhanden → leere Ergebnismenge
            if ($runId === null) {
                return $this->render('finding/index.html.twig', [
                    'findings'       => [],
                    'totalCount'     => 0,
                    'totalPages'     => 1,
                    'page'           => 1,
                    'problemsOnly'   => $problemsOnly,
                    'currentRunOnly' => $currentRunOnly,
                ]);
            }
        }

        // Erst Count, dann Page clampen, dann Offset berechnen – Reihenfolge ist entscheidend
        $totalCount = $this->findingRepository->countFiltered($problemsOnly, $runId);
        $totalPages = max(1, (int) ceil($totalCount / self::PAGE_SIZE));

        // Page auf gültigen Bereich begrenzen, damit kein leerer Offset entsteht
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PAGE_SIZE;

        $findings = $this->findingRepository->findPaginated(self::PAGE_SIZE, $offset, $problemsOnly, $runId);

        return $this->render('finding/index.html.twig', [
            'findings'       => $findings,
            'totalCount'     => $totalCount,
            'totalPages'     => $totalPages,
            'page'           => $page,
            'problemsOnly'   => $problemsOnly,
            'currentRunOnly' => $currentRunOnly,
        ]);
    }
}
