<?php
/**
 * Shared authenticated layout: document head, sidebar, topbar and page header.
 *
 * Expected variables (all optional except $pageTitle from the controller):
 *   $pageTitle        Browser title.
 *   $pageHeading      Short page heading rendered in the page header (defaults to page title).
 *   $pageEyebrow      Small lime label above the heading.
 *   $pageDescription  Intro copy below the heading (trusted authored HTML).
 *   $pageActions      Raw HTML for header actions.
 *   $activeNav        Sidebar key to highlight.
 */

$layoutUser = auth_user();
if (!$layoutUser) {
    auth_require();
    $layoutUser = auth_user();
}

$layoutDisplayName = trim((string) ($layoutUser['full_name'] ?? $layoutUser['username'] ?? 'User'));
if ($layoutDisplayName === '') {
    $layoutDisplayName = 'User';
}
$layoutInitials = '';
foreach (preg_split('/\s+/', $layoutDisplayName) as $layoutNamePart) {
    if ($layoutNamePart === '') {
        continue;
    }
    $layoutInitials .= strtoupper(substr($layoutNamePart, 0, 1));
    if (strlen($layoutInitials) >= 2) {
        break;
    }
}
$layoutInitials = $layoutInitials !== '' ? $layoutInitials : 'U';

$layoutCompanyName = APP_NAME;
try {
    $layoutPdo = get_db_connection();
    if ($layoutPdo instanceof PDO) {
        $layoutCompanyStmt = $layoutPdo->prepare('SELECT name FROM companies WHERE company_id = ? LIMIT 1');
        $layoutCompanyStmt->execute([auth_company_id()]);
        $layoutCompanyRow = $layoutCompanyStmt->fetchColumn();
        if (is_string($layoutCompanyRow) && trim($layoutCompanyRow) !== '') {
            $layoutCompanyName = trim($layoutCompanyRow);
        }
    }
} catch (Throwable $layoutCompanyException) {
    $layoutCompanyName = APP_NAME;
}

$layoutToday = new DateTimeImmutable('now');
$layoutHeading = trim((string) ($pageHeading ?? $pageTitle ?? 'Workspace'));
if ($layoutHeading === '') {
    $layoutHeading = 'Workspace';
}
$layoutEyebrow = trim((string) ($pageEyebrow ?? 'Workspace'));
$layoutDescription = $pageDescription ?? '';
$layoutActions = $pageActions ?? '';
$layoutActive = (string) ($activeNav ?? '');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark" class="dash-root">
<head>
    <script>
        /*
         * Preloader policy: this page shows the loader only when it was reached
         * from a sidebar link. app.js (and the dashboard) leave a short-lived
         * flag for exactly that navigation; any other page load — a Save
         * button, an Edit link, a filter — stays instant. The homepage,
         * dashboard, login and logout carry their own loader.
         */
        (function () {
            try {
                var ts = parseInt(sessionStorage.getItem('inv:navLoader') || '0', 10);
                sessionStorage.removeItem('inv:navLoader');
                if (ts > 0 && Date.now() - ts < 15000) {
                    document.documentElement.classList.add('loader-on');
                }
            } catch (e) { /* storage unavailable: never block the page */ }
        })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle ?? $layoutHeading); ?> - <?php echo e(APP_NAME); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,300&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css"
          integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css"
          integrity="sha384-/rJKQnzOkEo+daG0jMjU1IwwY9unxt1NBw3Ef2fmOJ3PW/TfAg2KXVoWwMZQZtw9" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo e(asset('vendor/sweetalert2/sweetalert2.min.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(asset('css/app.css')); ?>?v=<?php echo e(APP_VERSION); ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"
            integrity="sha384-CI3ELBVUz9XQO+97x6nwMDPosPR5XvsxW2ua7N1Xeygeh1IxtgqtCkGfQY9WWdHu" crossorigin="anonymous"></script>
</head>
<body>

<noscript><style>#loader{display:none!important}</style></noscript>

<div id="loader" aria-hidden="true">
    <div class="ld-name"><?php echo e($layoutCompanyName); ?></div>
    <div class="ld-spinner">
        <div class="ld-ring"></div>
        <div class="ld-ring"></div>
        <div class="ld-ring"></div>
    </div>
    <div class="ld-progress"><div class="ld-fill"></div></div>
    <div class="ld-msg">Loading workspace</div>
</div>

<canvas id="bg-canvas" aria-hidden="true"></canvas>
<div class="noise" aria-hidden="true"></div>
<div class="grid" aria-hidden="true"></div>

<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-head">
        <a class="brand" href="<?php echo e(url('/dashboard')); ?>">
            <span class="brand-mark"><i class="bi bi-box-seam-fill"></i></span>
            <span class="brand-text">
                <span class="brand-name" title="<?php echo e($layoutCompanyName); ?>"><?php echo e($layoutCompanyName); ?></span>
                <span class="brand-sub">Inventory System</span>
            </span>
        </a>
        <button class="sidebar-close" id="sidebarClose" type="button" aria-label="Close navigation">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <?php require APP_ROOT . '/views/layout/nav.php'; ?>

    <div class="sidebar-user">
        <div class="avatar"><?php echo e($layoutInitials); ?></div>
        <div class="u-meta">
            <div class="u-name"><?php echo e($layoutDisplayName); ?></div>
            <div class="u-role"><?php echo e(ucfirst((string) ($layoutUser['role'] ?? 'staff'))); ?></div>
        </div>
        <a class="u-pass" href="<?php echo e(url('/auth?action=change_password')); ?>" title="Change password" aria-label="Change password">
            <i class="bi bi-key"></i>
        </a>
    </div>
    <form class="signout" action="<?php echo e(url('/auth?action=logout')); ?>" method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="logout">
        <button type="submit"><i class="bi bi-box-arrow-right"></i> Sign out</button>
    </form>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

<div class="app-main">
    <header class="topbar">
        <button class="menu-btn" id="sidebarOpen" type="button" aria-label="Open navigation"
                aria-controls="sidebar" aria-expanded="false">
            <i class="bi bi-list"></i>
        </button>
        <div class="topbar-crumb">
            <span>Workspace</span>
            <i class="bi bi-chevron-right"></i>
            <strong><?php echo e($layoutHeading); ?></strong>
        </div>
        <div class="topbar-right">
            <span class="topbar-status"><span class="dot"></span> Secure session</span>
            <span class="topbar-user"><i class="bi bi-person-circle"></i><span><?php echo e($layoutDisplayName); ?></span></span>
        </div>
    </header>

    <main class="content">
        <section class="page-head" data-aos="fade-up">
            <div>
                <div class="eyebrow"><?php echo e($layoutEyebrow); ?></div>
                <h1><?php echo e($layoutHeading); ?></h1>
                <?php if ($layoutDescription !== ''): ?>
                    <p><?php echo $layoutDescription; ?></p>
                <?php endif; ?>
            </div>
            <div class="page-head-side">
                <?php if (trim((string) $layoutActions) !== ''): ?>
                    <div class="page-actions"><?php echo $layoutActions; ?></div>
                <?php endif; ?>
                <div class="head-date">
                    <span class="hd-day"><?php echo e($layoutToday->format('d')); ?></span>
                    <span class="hd-meta">
                        <b><?php echo e($layoutToday->format('F Y')); ?></b>
                        <span><?php echo e($layoutToday->format('l')); ?></span>
                    </span>
                </div>
            </div>
        </section>
