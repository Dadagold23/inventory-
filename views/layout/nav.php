<?php
/**
 * Shared sidebar navigation: one definition of the sections, their links and
 * the permission each link needs. Included by views/layout/header.php and by
 * views/dashboard.html (which keeps its own shell CSS/JS but must never keep a
 * second copy of the link list).
 *
 * Optional before including: $layoutActive — the link key to mark as current.
 */
$navActive = (string) ($layoutActive ?? ($activeNav ?? ''));

$navSections = [
    'Operations' => [
        ['dashboard', '/dashboard', 'bi-grid-1x2-fill', 'Dashboard', 'dashboard', 'view'],
        ['inventory', '/inventory?action=list', 'bi-boxes', 'Inventory', 'inventory', 'view'],
        ['categories', '/categories?action=list', 'bi-tags', 'Categories', 'inventory', 'view'],
        ['barcodes', '/barcodes?action=manage', 'bi-upc-scan', 'Barcodes', 'inventory', 'view'],
        ['sales', '/sales?action=list', 'bi-file-earmark-text', 'Sales', 'sales', 'view'],
        ['shifts', '/shifts?action=list', 'bi-cash-register', 'Shifts & Drawer', 'shifts', 'view'],
        ['receivables', '/customer_receivables?action=list', 'bi-person-lines-fill', 'Receivables', 'receivables', 'view'],
        ['ap', '/ap?action=list', 'bi-truck', 'Suppliers & Purchases', 'payables', 'view'],
    ],
    'Finance' => [
        ['expense', '/expense?action=list', 'bi-receipt', 'Expenses', 'expenses', 'view'],
        ['income', '/income?action=list', 'bi-cash-coin', 'Income', 'income', 'view'],
        ['payment_received', '/payment_received?action=list', 'bi-arrow-down-left-circle', 'Payments Received', 'payments', 'view'],
        ['payment_made', '/payment_made?action=list', 'bi-arrow-up-right-circle', 'Payments Made', 'payments', 'view'],
        ['bank', '/bank?action=list', 'bi-bank', 'Banking', 'bank', 'view'],
        ['ledger', '/ledger?action=list', 'bi-journal-text', 'General Ledger', 'ledger', 'view'],
        ['tax', '/tax?action=list', 'bi-percent', 'Tax', 'tax', 'view'],
        ['payroll', '/payroll?action=list', 'bi-people', 'Payroll', 'payroll', 'view'],
    ],
    'Control' => [
        ['approval', '/approval?action=list', 'bi-check2-square', 'Approvals', 'approvals', 'view'],
        ['report', '/report?action=list', 'bi-graph-up-arrow', 'Reports', 'reports', 'view'],
        ['audit', '/audit?action=list', 'bi-clock-history', 'Audit Log', 'audit', 'view'],
    ],
    'Administration' => [
        ['company', '/company?action=list', 'bi-building', 'Companies & Branches', 'company', 'view'],
        ['users', '/admin?action=users', 'bi-person-gear', 'Users & Roles', 'users', 'view'],
        ['payment_methods', '/payment_methods?action=list', 'bi-credit-card', 'Payment Methods', 'payment_methods', 'view'],
        ['gateways', '/payment_gateways?action=list', 'bi-tencent-qq', 'Payment Gateways', 'gateways', 'view'],
        ['qr_payments', '/qr_payments?action=list', 'bi-qr-code', 'QR Scan-to-Pay', 'qr_payments', 'view'],
        ['settings', '/system_settings?action=list', 'bi-gear', 'System Settings', 'settings', 'view'],
    ],
];
?>
<nav class="side-nav">
    <?php foreach ($navSections as $navSectionLabel => $navLinks): ?>
        <?php
        $navVisible = array_values(array_filter(
            $navLinks,
            static fn (array $link): bool => can($link[4], $link[5])
        ));
        if ($navVisible === []) {
            continue;
        }
        ?>
        <div class="side-label"><?php echo e($navSectionLabel); ?></div>
        <?php foreach ($navVisible as $navItem): ?>
            <?php $navCurrent = $navActive === $navItem[0]; ?>
            <a class="side-link<?php echo $navCurrent ? ' active' : ''; ?>"
               href="<?php echo e(url($navItem[1])); ?>"<?php echo $navCurrent ? ' aria-current="page"' : ''; ?>>
                <i class="bi <?php echo e($navItem[2]); ?>"></i><span><?php echo e($navItem[3]); ?></span></a>
        <?php endforeach; ?>
    <?php endforeach; ?>
</nav>
