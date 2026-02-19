<?php
$isEdit = isset($domain) && !empty($domain['id']);
$pageTitle = $isEdit ? 'TLS Monitor – Domain bearbeiten' : 'TLS Monitor – Domain anlegen';
$user = $user ?? \App\Service\AuthService::getCurrentUser();
ob_start();
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <h2>
            <i class="bi bi-<?= $isEdit ? 'pencil' : 'plus-lg' ?>"></i>
            <?= $isEdit ? 'Domain bearbeiten' : 'Domain anlegen' ?>
        </h2>

        <div class="card mt-3">
            <div class="card-body">
                <form method="POST" action="/index.php?action=<?= $isEdit ? 'domain_update' : 'domain_store' ?>">
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= $domain['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="fqdn" class="form-label">FQDN <span class="text-danger">*</span></label>
                        <input type="text" class="form-control <?= isset($errors['fqdn']) ? 'is-invalid' : '' ?>"
                               id="fqdn" name="fqdn" value="<?= htmlspecialchars($fqdn ?? '') ?>"
                               placeholder="z.B. example.com" required>
                        <?php if (isset($errors['fqdn'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['fqdn']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="port" class="form-label">Port <span class="text-danger">*</span></label>
                        <input type="number" class="form-control <?= isset($errors['port']) ? 'is-invalid' : '' ?>"
                               id="port" name="port" value="<?= htmlspecialchars($port ?? 443) ?>"
                               min="1" max="65535" required>
                        <?php if (isset($errors['port'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['port']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Beschreibung</label>
                        <textarea class="form-control" id="description" name="description"
                                  rows="3" placeholder="Optionale Beschreibung"><?= htmlspecialchars($description ?? '') ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Speichern' : 'Anlegen' ?>
                        </button>
                        <a href="/index.php?action=domains" class="btn btn-secondary">
                            <i class="bi bi-x-lg"></i> Abbrechen
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../layout.php';
?>
