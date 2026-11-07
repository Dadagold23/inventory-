<?php
/**
 * Dashboard controller.
 */

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/dashboard_functions.php';
require_once APP_ROOT . '/includes/company_functions.php';

auth_require();
require_permission('dashboard', 'view');

$pdo = get_db_connection();
if (!$pdo instanceof PDO) {
    http_response_code(503);
    exit('The database is unavailable. Please contact your administrator.');
}

$companyId = auth_company_id();
$user = auth_user();

$companyName = APP_NAME;
try {
    $companyStmt = $pdo->prepare('SELECT name FROM companies WHERE company_id = ? LIMIT 1');
    $companyStmt->execute([$companyId]);
    $companyRow = $companyStmt->fetchColumn();
    if (is_string($companyRow) && trim($companyRow) !== '') {
        $companyName = trim($companyRow);
    }
} catch (Throwable $exception) {
    $companyName = APP_NAME;
}

$summary = getDashboardSummary($pdo, $companyId);
$recentInvoices = getRecentSalesInvoices($pdo, $companyId, 5);
$lowStock = getLowStockProducts($pdo, $companyId, 5);
$monthlyTrend = getDashboardMonthlyTrend($pdo, $companyId, 6);
$invoiceStatus = getDashboardInvoiceStatusBreakdown($pdo, $companyId);
$pageTitle = 'Dashboard';

$companyCurrency = getCompanyCurrency($pdo);

require APP_ROOT . '/views/dashboard.html';
