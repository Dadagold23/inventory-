<?php
/**
 * Module 17: Tax Controller
 * Tax rate configuration, tax transactions and tax summaries.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('TAX_MODULE') || define('TAX_MODULE', 'tax');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/tax_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $taxWriteAction = match ($postAction) {
        'save_taxrate' => (int) ($_POST['tax_rate_id'] ?? 0) > 0 ? 'update' : 'create',
        'create_transaction' => 'create',
        'toggle_taxrate', 'reconcile_transaction' => 'update',
        default => 'update',
    };
    require_permission('tax', $taxWriteAction);
} else {
    require_permission('tax', 'view');
}

$view = (string) ($_GET['action'] ?? 'rates');
$viewMap = [
    'list' => 'rates',
    'manage_taxrates' => 'rates',
    'payments' => 'transactions',
    'manage_taxpayments' => 'transactions',
];
if (isset($viewMap[$view])) {
    $view = $viewMap[$view];
}
if (!in_array($view, ['rates', 'transactions', 'summary'], true)) {
    $view = 'rates';
}

$editRateId = (int) ($_GET['tax_rate_id'] ?? 0);
$filterRateId = (int) ($_GET['rate_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'save_taxrate') {
        $rateId = (int) ($_POST['tax_rate_id'] ?? 0);
        $payload = [
            'company_id' => $companyId,
            'name' => trim((string) ($_POST['name'] ?? '')),
            'tax_type' => (string) ($_POST['tax_type'] ?? 'sales_tax'),
            'rate' => (float) ($_POST['rate'] ?? 0),
            'jurisdiction' => trim((string) ($_POST['jurisdiction'] ?? '')),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'effective_date' => (string) ($_POST['effective_date'] ?? date('Y-m-d')),
            'end_date' => trim((string) ($_POST['end_date'] ?? '')),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];

        if ($rateId > 0) {
            $existing = getTaxRateById($pdo, $rateId);
            if (!$existing || (int) $existing['company_id'] !== $companyId) {
                $_SESSION['auth_message'] = 'Tax rate not found.';
            } else {
                $_SESSION['auth_message'] = updateTaxRate($pdo, $rateId, $payload)
                    ? 'Tax rate updated successfully.'
                    : 'Unable to update the tax rate. Check the name, type and rate (0 < rate <= 1).';
            }
        } else {
            $_SESSION['auth_message'] = createTaxRate($pdo, $payload) > 0
                ? 'Tax rate created successfully.'
                : 'Unable to create the tax rate. Check for duplicates and that 0 < rate <= 1.';
        }

        redirect('/tax?action=rates');
    }

    if ($postAction === 'toggle_taxrate') {
        $rateId = (int) ($_POST['tax_rate_id'] ?? 0);
        $rate = $rateId > 0 ? getTaxRateById($pdo, $rateId) : null;

        if ($rate && (int) $rate['company_id'] === $companyId) {
            toggleTaxRateStatus($pdo, $rateId);
            $_SESSION['auth_message'] = 'Tax rate status updated.';
        } else {
            $_SESSION['auth_message'] = 'Tax rate not found.';
        }

        redirect('/tax?action=rates');
    }

    if ($postAction === 'create_transaction') {
        $transactionId = createTaxTransaction($pdo, [
            'company_id' => $companyId,
            'tax_rate_id' => (int) ($_POST['tax_rate_id'] ?? 0),
            'taxable_amount' => moneyInput($_POST['taxable_amount'] ?? 0),
            'tax_amount' => moneyInput($_POST['tax_amount'] ?? 0),
            'transaction_type' => (string) ($_POST['transaction_type'] ?? 'sale'),
            'transaction_date' => (string) ($_POST['transaction_date'] ?? date('Y-m-d')),
            'reference_type' => trim((string) ($_POST['reference_type'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
        ]);

        $_SESSION['auth_message'] = $transactionId > 0
            ? 'Tax transaction recorded successfully.'
            : 'Unable to record the tax transaction. Choose an active rate and a taxable or tax amount.';

        redirect('/tax?action=transactions');
    }

    if ($postAction === 'reconcile_transaction') {
        $transactionId = (int) ($_POST['transaction_id'] ?? 0);
        $reconciled = !empty($_POST['reconciled']);
        $transaction = $transactionId > 0 ? getTaxTransactionById($pdo, $transactionId) : null;

        if ($transaction && (int) $transaction['company_id'] === $companyId) {
            setTaxTransactionReconciled($pdo, $transactionId, $reconciled);
            $_SESSION['auth_message'] = $reconciled
                ? 'Tax transaction marked as reconciled.'
                : 'Tax transaction marked as unreconciled.';
        } else {
            $_SESSION['auth_message'] = 'Tax transaction not found.';
        }

        redirect('/tax?action=transactions');
    }
}

$taxRates = getTaxRatesByCompany($pdo, $companyId);
$activeTaxRates = getActiveTaxRates($pdo, $companyId);
$taxRate = [];
if ($editRateId > 0) {
    $candidate = getTaxRateById($pdo, $editRateId);
    if ($candidate && (int) $candidate['company_id'] === $companyId) {
        $taxRate = $candidate;
    }
}

$transactions = getTaxTransactions($pdo, $companyId, $filterRateId > 0 ? $filterRateId : null);
$taxSummary = getTaxSummaryByType($pdo, $companyId);
$totals = getTaxTotals($pdo, $companyId);
$activeRateCount = count($activeTaxRates);
$unreconciledCount = max(0, (int) $totals['transaction_count'] - (int) $totals['reconciled_count']);

$pageTitle = $view === 'transactions'
    ? 'Tax Transactions'
    : ($view === 'summary' ? 'Tax Summary' : 'Tax Rates');

$companyCurrency = getCompanyCurrency($pdo);

require APP_ROOT . '/views/tax.html';
