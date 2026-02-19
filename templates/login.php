<?php $pageTitle = 'TLS Monitor – Login'; ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-5">
                <div class="card shadow">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <h3><i class="bi bi-shield-lock"></i> TLS Monitor</h3>
                            <p class="text-muted">Bitte melden Sie sich an</p>
                        </div>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger">
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="/index.php?action=login">
                            <div class="mb-3">
                                <label for="username" class="form-label">Benutzername</label>
                                <input type="text" class="form-control" id="username" name="username"
                                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Passwort</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-box-arrow-in-right"></i> Anmelden
                            </button>
                        </form>
                    </div>
                </div>
                <p class="text-center text-muted mt-3 small">
                    Standard-Login: admin / admin
                </p>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
