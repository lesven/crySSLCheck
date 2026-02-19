<?php

namespace App\Controller;

use App\Model\Finding;
use App\Service\AuthService;

class FindingController
{
    public function index(): void
    {
        AuthService::requireLogin();
        $user = AuthService::getCurrentUser();

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $problemsOnly = isset($_GET['problems_only']) && $_GET['problems_only'] === '1';
        $currentRunOnly = isset($_GET['current_run']) && $_GET['current_run'] === '1';

        $runId = null;
        if ($currentRunOnly) {
            $runId = Finding::getLatestRunId();
        }

        $limit = 50;
        $offset = ($page - 1) * $limit;

        $findings = Finding::findAll($limit, $offset, $problemsOnly, $runId);
        $totalCount = Finding::countAll($problemsOnly, $runId);
        $totalPages = max(1, (int) ceil($totalCount / $limit));

        require __DIR__ . '/../../templates/findings/list.php';
    }
}
