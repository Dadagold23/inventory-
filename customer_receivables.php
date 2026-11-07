<?php
/**
 * Module 7: Customer Management and Receivables Controller
 * Handles customer aging, credit control, statements and communication tracking.
 * Extends the shared authentication, company and sales foundation.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('CUSTOMER_MODULE') || define('CUSTOMER_MODULE', 'customer');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/inventory_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/customer_receivables_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $receivablesWriteAction = match ($postAction) {
        'update_credit', 'mark_statement_sent' => 'update',
        'deactivate_customer' => 'delete',
        'create_statement', 'record_communication' => 'create',
        default => 'update',
    };
    require_permission('receivables', $receivablesWriteAction);
} else {
    require_permission('receivables', 'view');
}

$action = (string) ($_GET['action'] ?? 'aging');
if (!in_array($action, ['aging', 'statements', 'communications', 'customers'], true)) {
    $action = 'aging';
}
$customerId = (int) ($_GET['customer_id'] ?? 0);

$pageTitle = 'Customer Receivables';
$creditTerms = ['Due on Receipt', 'Net 7', 'Net 15', 'Net 30', 'Net 45', 'Net 60', 'Net 90'];

// ============================================================
// POST actions
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'update_credit') {
        $targetId = (int) ($_POST['customer_id'] ?? 0);
        $creditLimit = (float) ($_POST['credit_limit'] ?? 0);
        $paymentTerms = (string) ($_POST['payment_terms'] ?? 'Net 30');

        if ($targetId > 0 && receivablesCustomerInCompany($pdo, $targetId, $companyId)) {
            if (updateCustomerCreditTerms($pdo, $targetId, $creditLimit, $paymentTerms)) {
                $_SESSION['auth_message'] = 'Credit terms updated successfully.';
            } else {
                $_SESSION['auth_message'] = 'Unable to update the credit terms.';
            }
        } else {
            $_SESSION['auth_message'] = 'Customer not found.';
        }

        redirect('/customer_receivables?action=' . $action);
    }

    if ($postAction === 'deactivate_customer') {
        $targetId = (int) ($_POST['customer_id'] ?? 0);
        if ($targetId > 0 && receivablesCustomerInCompany($pdo, $targetId, $companyId)) {
            updateCustomer($pdo, $targetId, ['is_active' => 0]);
            $_SESSION['auth_message'] = 'Customer deactivated successfully.';
        } else {
            $_SESSION['auth_message'] = 'Customer not found.';
        }
        redirect('/customer_receivables?action=customers');
    }

    if ($postAction === 'create_statement') {
        $targetId = (int) ($_POST['customer_id'] ?? 0);
        $periodStart = (string) ($_POST['period_start'] ?? date('Y-m-01', strtotime('-1 month')));
        $periodEnd = (string) ($_POST['period_end'] ?? date('Y-m-t', strtotime('-1 month')));

        $statementId = createCustomerStatement($pdo, $companyId, $targetId, $periodStart, $periodEnd);
        if ($statementId > 0) {
            $_SESSION['auth_message'] = 'Customer statement generated successfully.';
        } else {
            $_SESSION['auth_message'] = 'Unable to generate the statement. Check the customer and period.';
        }

        redirect('/customer_receivables?action=statements' . ($targetId > 0 ? '&customer_id=' . $targetId : ''));
    }

    if ($postAction === 'mark_statement_sent') {
        $statementId = (int) ($_POST['statement_id'] ?? 0);
        $targetId = (int) ($_POST['customer_id'] ?? 0);

        $statement = getCustomerStatement($pdo, $statementId);
        if ($statement && (int) $statement['company_id'] === $companyId) {
            updateStatementStatus($pdo, $statementId, 'sent');
            $_SESSION['auth_message'] = 'Statement marked as sent.';
        } else {
            $_SESSION['auth_message'] = 'Statement not found.';
        }

        redirect('/customer_receivables?action=statements' . ($targetId > 0 ? '&customer_id=' . $targetId : ''));
    }

    if ($postAction === 'record_communication') {
        $targetId = (int) ($_POST['customer_id'] ?? 0);
        $type = (string) ($_POST['communication_type'] ?? 'email');
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($targetId <= 0 || $subject === '') {
            $_SESSION['auth_message'] = 'A customer and subject are required.';
        } elseif (createCustomerCommunication($pdo, $companyId, $targetId, $type, $subject, $body)) {
            $_SESSION['auth_message'] = 'Communication recorded successfully.';
        } else {
            $_SESSION['auth_message'] = 'Unable to record the communication.';
        }

        redirect('/customer_receivables?action=communications' . ($targetId > 0 ? '&customer_id=' . $targetId : ''));
    }
}

// ============================================================
// Load data
// ============================================================

$customers = getCustomersByCompanyWithCredit($pdo, $companyId);

$customer = [];
if ($customerId > 0) {
    $customer = getCustomerAging($pdo, $customerId);
    if ($customer !== [] && (int) $customer['company_id'] !== $companyId) {
        $customer = [];
        $customerId = 0;
    }
}

$statements = [];
$communications = [];
$customerInvoices = [];
$customerPayments = [];
if ($customerId > 0 && $customer !== []) {
    $customerInvoices = getCustomerInvoices($pdo, $customerId);
    $customerPayments = getCustomerPayments($pdo, $customerId);
    if ($action === 'statements') {
        $statements = getStatementsByCustomer($pdo, $customerId);
    }
    if ($action === 'communications') {
        $communications = getCustomerCommunications($pdo, $customerId);
    }
}

$overdueCustomers = array_values(array_filter($customers, static function (array $row): bool {
    return (float) ($row['overdue_amount'] ?? 0) > 0;
}));
usort($overdueCustomers, static function (array $a, array $b): int {
    return (float) $b['overdue_amount'] <=> (float) $a['overdue_amount'];
});

$agingBuckets = buildAgingBuckets($customers);
$totalOutstanding = array_sum(array_map(static fn(array $row): float => (float) ($row['outstanding'] ?? 0), $customers));
$totalOverdue = array_sum(array_map(static fn(array $row): float => (float) ($row['overdue_amount'] ?? 0), $customers));
$activeCustomerCount = count(array_filter($customers, static fn(array $row): bool => (int) ($row['is_active'] ?? 0) === 1));

if ($action === 'statements') {
    $pageTitle = 'Customer Statements';
} elseif ($action === 'communications') {
    $pageTitle = 'Customer Communications';
} elseif ($action === 'customers') {
    $pageTitle = 'Customer Credit Control';
}

// Resolve company currency once — used in the view for totals/summaries and
// as the fallback for customers whose currency is not explicitly set.
require_once APP_ROOT . '/includes/company_functions.php';
$companyCurrency = getCompanyCurrency($pdo);

require APP_ROOT . '/views/customer_receivables.html';
