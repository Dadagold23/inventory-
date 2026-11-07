<?php
/**
 * Module 13: Financial Report Controller
 * Report catalogue and live financial statements.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('REPORT_MODULE') || define('REPORT_MODULE', 'report');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/report_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $reportWriteAction = match ($postAction) {
        'create_report' => 'create',
        'regenerate_report' => 'update',
        default => 'update',
    };
    require_permission('reports', $reportWriteAction);
} else {
    require_permission('reports', 'view');
}

$reportId = (int) ($_GET['report_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'create_report') {
        $newId = createFinancialReport($pdo, [
            'company_id' => $companyId,
            'report_type' => (string) ($_POST['report_type'] ?? ''),
            'report_name' => trim((string) ($_POST['report_name'] ?? '')),
            'period_start' => (string) ($_POST['period_start'] ?? ''),
            'period_end' => (string) ($_POST['period_end'] ?? ''),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'generated_by' => (int) ($_SESSION['user_id'] ?? 0),
        ]);

        $_SESSION['auth_message'] = $newId > 0
            ? 'Financial report generated from current records.'
            : 'Unable to create the report. Check the type and period dates.';

        redirect($newId > 0 ? '/report?action=list&report_id=' . $newId : '/report?action=list');
    }

    if ($postAction === 'regenerate_report') {
        $targetId = (int) ($_POST['report_id'] ?? 0);
        $existing = $targetId > 0 ? getFinancialReportById($pdo, $targetId) : null;

        if ($existing && (int) $existing['company_id'] === $companyId) {
            regenerateFinancialReport($pdo, $targetId, $companyId);
            $_SESSION['auth_message'] = 'Report regenerated from current records.';
        } else {
            $_SESSION['auth_message'] = 'Report not found.';
        }

        redirect('/report?action=list&report_id=' . $targetId);
    }
}

$reports = getFinancialReportsByCompany($pdo, $companyId);
$branches = getBranchesByCompany($pdo, $companyId);

// A saved report stores the period and the type, not the way it is sliced:
// the dimension and branch are a view choice and can change on every visit.
$reportOptions = [
    'group_by' => (string) ($_GET['group_by'] ?? 'day'),
    'branch_id' => (int) ($_GET['branch_id'] ?? 0),
];

$report = [];
$reportData = [];
if ($reportId > 0) {
    $candidate = getFinancialReportById($pdo, $reportId);
    if ($candidate && (int) $candidate['company_id'] === $companyId) {
        $report = $candidate;
        $reportData = computeReportData($pdo, $report, $reportOptions);
    }
}

$reportTypes = reportTypeLabels();
$reportCounts = array_fill_keys(REPORT_TYPES, 0);
foreach ($reports as $row) {
    if (isset($reportCounts[$row['report_type']])) {
        $reportCounts[$row['report_type']]++;
    }
}

$pageTitle = 'Financial Reports';

$companyCurrency = getCompanyCurrency($pdo);

require APP_ROOT . '/views/report.html';
