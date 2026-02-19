<?php
$pageTitle = 'TLS Monitor – Domains';
$user = $user ?? \App\Service\AuthService::getCurrentUser();
ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-globe"></i> Domains</h2>
    <?php if ($user && $user['role'] === 'admin'): ?>
        <a href="/index.php?action=domain_create" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Domain anlegen
        </a>
    <?php endif; ?>
</div>

<?php if (!empty($success)): ?>
    <?php
    $messages = [
        'created' => 'Domain erfolgreich angelegt.',
        'updated' => 'Domain erfolgreich aktualisiert.',
        'toggled' => 'Domain-Status erfolgreich geändert.',
    ];
    ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= htmlspecialchars($messages[$success] ?? 'Aktion erfolgreich.') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($_SESSION['scan_results'])): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <strong>Scan-Ergebnisse:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($_SESSION['scan_results'] as $result): ?>
                <li>
                    <span class="badge bg-<?= match($result['severity']) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'secondary',
                        default => 'success',
                    } ?>"><?= htmlspecialchars($result['severity']) ?></span>
                    <?= htmlspecialchars($result['finding_type']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['scan_results']); ?>
<?php endif; ?>

<?php if (empty($domains)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> Noch keine Domains vorhanden.
        <?php if ($user && $user['role'] === 'admin'): ?>
            <a href="/index.php?action=domain_create">Jetzt erste Domain anlegen.</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-dark">
                <tr>
                    <th>FQDN</th>
                    <th>Port</th>
                    <th>Beschreibung</th>
                    <th>Status</th>
                    <th>Erstellt</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($domains as $domain): ?>
                    <tr class="<?= $domain['status'] === 'inactive' ? 'text-muted' : '' ?>">
                        <td><?= htmlspecialchars($domain['fqdn']) ?></td>
                        <td><?= htmlspecialchars($domain['port']) ?></td>
                        <td><?= htmlspecialchars($domain['description'] ?? '–') ?></td>
                        <td>
                            <?php if ($domain['status'] === 'active'): ?>
                                <span class="badge bg-success">Aktiv</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($domain['created_at'] ?? '') ?></td>
                        <td>
                            <?php if ($user && $user['role'] === 'admin'): ?>
                                <div class="btn-group btn-group-sm">
                                    <a href="/index.php?action=domain_edit&id=<?= $domain['id'] ?>" class="btn btn-outline-primary" title="Bearbeiten">
                                        <i class="bi bi-pencil"></i>
                                    </a>

                                    <form method="POST" action="/index.php?action=domain_toggle" class="d-inline">
                                        <input type="hidden" name="id" value="<?= $domain['id'] ?>">
                                        <button type="submit" class="btn btn-outline-<?= $domain['status'] === 'active' ? 'warning' : 'success' ?>"
                                                title="<?= $domain['status'] === 'active' ? 'Deaktivieren' : 'Reaktivieren' ?>">
                                            <i class="bi bi-<?= $domain['status'] === 'active' ? 'pause-circle' : 'play-circle' ?>"></i>
                                        </button>
                                    </form>

                                    <form method="POST" action="/index.php?action=scan" class="d-inline">
                                        <input type="hidden" name="domain_id" value="<?= $domain['id'] ?>">
                                        <button type="submit" class="btn btn-outline-info"
                                                <?= $domain['status'] !== 'active' ? 'disabled' : '' ?>
                                                title="<?= $domain['status'] !== 'active' ? 'Scan für deaktivierte Domains nicht möglich' : 'Jetzt scannen' ?>">
                                            <i class="bi bi-search"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
?>
