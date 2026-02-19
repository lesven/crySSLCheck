<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'TLS Monitor') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="/index.php?action=domains">
                <i class="bi bi-shield-lock"></i> TLS Monitor
            </a>
            <?php if (isset($user) && $user): ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= ($_GET['action'] ?? '') === 'domains' ? 'active' : '' ?>" href="/index.php?action=domains">
                            <i class="bi bi-globe"></i> Domains
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($_GET['action'] ?? '') === 'findings' ? 'active' : '' ?>" href="/index.php?action=findings">
                            <i class="bi bi-list-check"></i> Findings
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <span class="nav-link text-light">
                            <i class="bi bi-person"></i> <?= htmlspecialchars($user['username']) ?>
                            <span class="badge bg-<?= $user['role'] === 'admin' ? 'primary' : 'secondary' ?>"><?= htmlspecialchars($user['role']) ?></span>
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/index.php?action=logout">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </nav>

    <div class="container mt-4">
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= htmlspecialchars($_SESSION['flash_success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= htmlspecialchars($_SESSION['flash_error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <?= $content ?? '' ?>
    </div>

    <footer class="container mt-5 mb-3">
        <hr>
        <p class="text-muted text-center small">TLS Monitor POC &copy; <?= date('Y') ?></p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
