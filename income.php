<?php
/**
 * Module 8: Income Management Controller
 * Income sources and income records.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('INCOME_MODULE') || define('INCOME_MODULE', 'income');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/inventory_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/ap_functions.php';
require_once APP_ROOT . '/includes/customer_receivables_functions.php';
require_once APP_ROOT . '/includes/income_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $incomeWriteAction = match ($postAction) {
        'save_source' => (int) ($_POST['source_id'] ?? 0) > 0 ? 'update' : 'create',
        'deactivate_source', 'cancel_transaction' => 'delete',
        'save_transaction' => (int) ($_POST['transaction_id'] ?? 0) > 0 ? 'update' : 'create',
        'recognize_income', 'record_payment' => 'update',
        default => 'update',
    };
    require_permission('income', $incomeWriteAction);
} else {
    require_permission('income', 'view');
}

$action = (string) ($_GET['action'] ?? 'sources');
if (!in_array($action, ['sources', 'transactions'], true)) {
    $action = 'sources';
}

$sourceId = (int) ($_GET['source_id'] ?? 0);
$transactionId = (int) ($_GET['transaction_id'] ?? 0);
$pageTitle = 'Income Management';

// ============================================================
// POST actions
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'save_source') {
        $targetId = (int) ($_POST['source_id'] ?? 0);
        $name = trim((string) ($_POST['source_name'] ?? ''));

        if ($name === '') {
            $_SESSION['auth_message'] = 'An income source name is required.';
            redirect('/income?action=sources');
        }

        $payload = [
            'name' => $name,
            'code' => trim((string) ($_POST['source_code'] ?? '')),
            'description' => trim((string) ($_POST['source_description'] ?? '')),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ];

        if ($targetId > 0) {
            $existing = getIncomeSourceById($pdo, $targetId);
            if (!$existing || (int) $existing['company_id'] !== $companyId) {
                $_SESSION['auth_message'] = 'Income source not found.';
                redirect('/income?action=sources');
            }
            updateIncomeSource($pdo, $targetId, $payload);
            $_SESSION['auth_message'] = 'Income source updated.';
        } else {
            $payload['company_id'] = $companyId;
            $targetId = createIncomeSource($pdo, $payload);
            $_SESSION['auth_message'] = 'Income source created.';
        }

        redirect('/income?action=sources' . ($targetId > 0 ? '&source_id=' . $targetId : ''));
    }

    if ($postAction === 'deactivate_source') {
        $targetId = (int) ($_POST['source_id'] ?? 0);
        $existing = $targetId > 0 ? getIncomeSourceById($pdo, $targetId) : null;

        if ($existing && (int) $existing['company_id'] === $companyId) {
            deleteIncomeSource($pdo, $targetId);
            $_SESSION['auth_message'] = 'Income source deactivated.';
        } else {
            $_SESSION['auth_message'] = 'Income source not found.';
        }

        redirect('/income?action=sources');
    }

    if ($postAction === 'save_transaction') {
        $targetId = (int) ($_POST['transaction_id'] ?? 0);
        $targetSourceId = (int) ($_POST['source_id'] ?? 0);
        $source = $targetSourceId > 0 ? getIncomeSourceById($pdo, $targetSourceId) : null;

        if (!$source || (int) $source['company_id'] !== $companyId) {
            $_SESSION['auth_message'] = 'Select a valid income source.';
            redirect('/income?action=transactions');
        }

        $gross = moneyInput($_POST['gross_amount'] ?? 0);
        $discount = moneyInput($_POST['discount_amount'] ?? 0);
        $tax = moneyInput($_POST['tax_amount'] ?? 0);
        $net = $gross - $discount;
        $total = $net + $tax;

        auth_guard_branch($pdo, (int) ($_POST['branch_id'] ?? 0));

        $payload = [
            'source_id' => $targetSourceId,
            'customer_id' => (int) ($_POST['customer_id'] ?? 0),
            'sales_invoice_id' => (int) ($_POST['sales_invoice_id'] ?? 0),
            'branch_id' => (int) ($_POST['branch_id'] ?? 0),
            'transaction_number' => trim((string) ($_POST['transaction_number'] ?? '')),
            'income_date' => (string) ($_POST['income_date'] ?? date('Y-m-d')),
            'payment_status' => (string) ($_POST['payment_status'] ?? 'pending'),
            'gross_amount' => $gross,
            'discount_amount' => $discount,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'description' => trim((string) ($_POST['description'] ?? '')),
            'recognized' => !empty($_POST['recognized']) ? 1 : 0,
            'recognition_date' => (string) ($_POST['recognition_date'] ?? '') ?: null,
        ];

        if ($targetId > 0) {
            $existing = getIncomeTransactionById($pdo, $targetId);
            if (!$existing || (int) $existing['company_id'] !== $companyId) {
                $_SESSION['auth_message'] = 'Income transaction not found.';
                redirect('/income?action=transactions');
            }
            updateIncomeTransaction($pdo, $targetId, $payload);
            $_SESSION['auth_message'] = 'Income transaction updated.';
        } else {
            $payload['company_id'] = $companyId;
            $payload['created_by'] = $_SESSION['user_id'] ?? null;
            $targetId = createIncomeTransaction($pdo, $payload);
            $_SESSION['auth_message'] = $targetId > 0
                ? 'Income transaction recorded.'
                : 'Unable to record the income transaction.';
        }

        redirect('/income?action=transactions');
    }

    if ($postAction === 'recognize_income') {
        $targetId = (int) ($_POST['transaction_id'] ?? 0);
        $existing = $targetId > 0 ? getIncomeTransactionById($pdo, $targetId) : null;

        if ($existing && (int) $existing['company_id'] === $companyId) {
            recognizeIncome($pdo, $targetId);
            $_SESSION['auth_message'] = 'Income recognized.';
        } else {
            $_SESSION['auth_message'] = 'Income transaction not found.';
        }

        redirect('/income?action=transactions');
    }

    if ($postAction === 'record_payment') {
        $targetId = (int) ($_POST['transaction_id'] ?? 0);
        $status = (string) ($_POST['payment_status'] ?? 'paid');
        if (!in_array($status, ['pending', 'partial', 'paid', 'write_off', 'cancelled'], true)) {
            $status = 'paid';
        }

        $existing = $targetId > 0 ? getIncomeTransactionById($pdo, $targetId) : null;
        if ($existing && (int) $existing['company_id'] === $companyId) {
            updateIncomeTransaction($pdo, $targetId, ['payment_status' => $status]);
            $_SESSION['auth_message'] = 'Income payment status updated.';
        } else {
            $_SESSION['auth_message'] = 'Income transaction not found.';
        }

        redirect('/income?action=transactions');
    }

    if ($postAction === 'cancel_transaction') {
        $targetId = (int) ($_POST['transaction_id'] ?? 0);
        $existing = $targetId > 0 ? getIncomeTransactionById($pdo, $targetId) : null;

        if ($existing && (int) $existing['company_id'] === $companyId) {
            updateIncomeTransaction($pdo, $targetId, ['payment_status' => 'cancelled']);
            $_SESSION['auth_message'] = 'Income transaction cancelled.';
        } else {
            $_SESSION['auth_message'] = 'Income transaction not found.';
        }

        redirect('/income?action=transactions');
    }
}

// ============================================================
// Load data
// ============================================================

$incomeSources = getIncomeSourcesByCompany($pdo, $companyId);

$source = [];
if ($sourceId > 0) {
    $source = getIncomeSourceById($pdo, $sourceId) ?? [];
    if ($source !== [] && (int) $source['company_id'] !== $companyId) {
        $source = [];
        $sourceId = 0;
    }
}

$transactions = getIncomeTransactionsByCompany($pdo, $companyId);

$transaction = [];
if ($transactionId > 0) {
    $transaction = getIncomeTransactionById($pdo, $transactionId) ?? [];
    if ($transaction !== [] && (int) $transaction['company_id'] !== $companyId) {
        $transaction = [];
        $transactionId = 0;
    }
}

$paymentSummary = getPaymentStatusSummary($pdo, $companyId);
$customers = getCustomersByCompanyWithCredit($pdo, $companyId);
$branches = getBranchesByCompany($pdo, $companyId);
$defaultSourceId = getDefaultIncomeSource($pdo);

$invoiceStmt = $pdo->prepare("
    SELECT invoice_id, invoice_number, total_amount, customer_id, status
    FROM sales_invoices
    WHERE company_id = ? AND status <> 'cancelled'
    ORDER BY issue_date DESC, invoice_id DESC
    LIMIT 200
");
$invoiceStmt->execute([$companyId]);
$invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);

$totalIncome = array_sum(array_map(
    static fn(array $row): float => (float) ($row['total_amount'] ?? 0),
    $paymentSummary
));
$totalReceived = array_sum(array_map(
    static fn(array $row): float => ($row['payment_status'] ?? '') === 'paid' ? (float) ($row['total_amount'] ?? 0) : 0,
    $paymentSummary
));

$pageTitle = $action === 'transactions' ? 'Income Transactions' : 'Income Sources';

$companyCurrency = getCompanyCurrency($pdo);

require APP_ROOT . '/views/income.html';
