<?php
$pageTitle = 'TLS Monitor – Findings';
$user = $user ?? \App\Service\AuthService::getCurrentUser();
ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-list-check"></i> Findings</h2>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="/index.php" class="row g-3 align-items-center">
            <input type="hidden" name="action" value="findings">

            <div class="col-auto">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="current_run" name="current_run" value="1"
                           <?= $currentRunOnly ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="form-check-label" for="current_run">Nur aktueller Run</label>
                </div>
            </div>

            <div class="col-auto">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="problems_only" name="problems_only" value="1"
                           <?= $problemsOnly ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="form-check-label" for="problems_only">Nur Probleme anzeigen</label>
                </div>
            </div>

            <div class="col-auto">
                <noscript><button type="submit" class="btn btn-sm btn-primary">Filter anwenden</button></noscript>
            </div>
        </form>
    </div>
</div>

<?php if (empty($findings)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> Keine Findings vorhanden.
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-dark">
                <tr>
                    <th>Domain</th>
                    <th>Port</th>
                    <th>Finding-Typ</th>
                    <th>Severity</th>
                    <th>Datum</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($findings as $finding): ?>
                    <tr>
                        <td><?= htmlspecialchars($finding['fqdn']) ?></td>
                        <td><?= htmlspecialchars($finding['port']) ?></td>
                        <td><?= htmlspecialchars($finding['finding_type']) ?></td>
                        <td>
                            <?php
                            $badgeClass = match ($finding['severity']) {
                                'critical' => 'danger',
                                'high'     => 'warning',
                                'medium'   => 'info',
                                'low'      => 'secondary',
                                default    => 'success',
                            };
                            ?>
                            <span class="badge bg-<?= $badgeClass ?>"><?= htmlspecialchars($finding['severity']) ?></span>
                        </td>
                        <td><?= htmlspecialchars($finding['checked_at'] ?? '') ?></td>
                        <td>
                            <?php
                            $statusBadge = match ($finding['status']) {
                                'new'      => 'danger',
                                'known'    => 'warning',
                                'resolved' => 'success',
                                default    => 'secondary',
                            };
                            ?>
                            <span class="badge bg-<?= $statusBadge ?>"><?= htmlspecialchars($finding['status']) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav>
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="/index.php?action=findings&page=<?= $page - 1 ?>&problems_only=<?= $problemsOnly ? '1' : '0' ?>&current_run=<?= $currentRunOnly ? '1' : '0' ?>">
                        &laquo; Zurück
                    </a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="/index.php?action=findings&page=<?= $i ?>&problems_only=<?= $problemsOnly ? '1' : '0' ?>&current_run=<?= $currentRunOnly ? '1' : '0' ?>">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="/index.php?action=findings&page=<?= $page + 1 ?>&problems_only=<?= $problemsOnly ? '1' : '0' ?>&current_run=<?= $currentRunOnly ? '1' : '0' ?>">
                        Weiter &raquo;
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>

    <p class="text-muted text-center small">
        Zeige <?= count($findings) ?> von <?= $totalCount ?> Einträgen (Seite <?= $page ?> von <?= $totalPages ?>)
    </p>
<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
?>
