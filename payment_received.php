<?php
/**
 * Module 14: Payment Received Controller
 * Customer payments received and accounts receivable.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('PAYMENT_RECEIVED_MODULE') || define('PAYMENT_RECEIVED_MODULE', 'payment_received');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/payment_received_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();

require_permission('payments', $_SERVER['REQUEST_METHOD'] === 'POST' ? 'create' : 'view');

$action = (string) ($_GET['action'] ?? 'list');
if (!in_array($action, ['list'], true)) {
    $action = 'list';
}

$pageTitle = 'Payments Received';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'record_payment') {
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);

        if ($customerId <= 0 || $invoiceId <= 0 || $amount <= 0) {
            $_SESSION['auth_message'] = 'Select a customer and invoice, then enter a positive amount.';
            redirect('/payment_received?action=list');
        }

        $invoiceStmt = $pdo->prepare(
            "SELECT si.invoice_id, si.company_id, si.customer_id, si.total_amount, si.status,
                    COALESCE((SELECT SUM(cp.amount) FROM customer_payments cp
                               WHERE cp.invoice_id = si.invoice_id), 0) AS paid
             FROM sales_invoices si
             WHERE si.invoice_id = ?"
        );
        $invoiceStmt->execute([$invoiceId]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice
            || (int) $invoice['company_id'] !== $companyId
            || (int) $invoice['customer_id'] !== $customerId
            || $invoice['status'] === 'cancelled') {
            $_SESSION['auth_message'] = 'The selected invoice does not belong to this customer.';
            redirect('/payment_received?action=list');
        }

        $remaining = (float) $invoice['total_amount'] - (float) $invoice['paid'];
        if ($amount > $remaining + 0.0001) {
            $_SESSION['auth_message'] = 'The amount exceeds the invoice balance of ' . formatPrice($remaining) . '.';
            redirect('/payment_received?action=list');
        }

        $paymentMethod = (string) ($_POST['payment_method'] ?? 'bank_transfer');
        if (!in_array($paymentMethod, ['cash', 'check', 'bank_transfer', 'credit_card'], true)) {
            $paymentMethod = 'bank_transfer';
        }

        try {
            $paymentId = recordPaymentReceived($pdo, [
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'invoice_id' => $invoiceId,
                'payment_date' => (string) ($_POST['payment_date'] ?? date('Y-m-d')),
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference_number' => trim((string) ($_POST['reference_number'] ?? '')),
                'notes' => trim((string) ($_POST['notes'] ?? '')),
            ]);

            $_SESSION['auth_message'] = $paymentId > 0
                ? 'Payment received recorded successfully.'
                : 'Unable to record the payment.';
        } catch (Throwable $e) {
            error_log('payment_received: ' . $e->getMessage());
            $_SESSION['auth_message'] = 'Unable to record the payment. Please verify the details.';
        }

        redirect('/payment_received?action=list');
    }
}

$payments = getPaymentsReceivedByCompany($pdo, $companyId);
$outstanding = getOutstandingReceivables($pdo, $companyId);
$paymentSummary = getPaymentSummaryByMethod($pdo, $companyId);

$customerStmt = $pdo->prepare(
    'SELECT customer_id, name FROM customers
     WHERE company_id = ? AND is_active = 1
     ORDER BY name'
);
$customerStmt->execute([$companyId]);
$customers = $customerStmt->fetchAll(PDO::FETCH_ASSOC);

$invoiceStmt = $pdo->prepare(
    "SELECT si.invoice_id, si.invoice_number, si.customer_id, si.total_amount,
            c.name AS customer_name,
            COALESCE((SELECT SUM(cp.amount) FROM customer_payments cp
                       WHERE cp.invoice_id = si.invoice_id), 0) AS paid
     FROM sales_invoices si
     INNER JOIN customers c ON si.customer_id = c.customer_id
     WHERE si.company_id = ? AND si.status IN ('pending', 'partial')
     ORDER BY si.issue_date DESC, si.invoice_id DESC
     LIMIT 500"
);
$invoiceStmt->execute([$companyId]);
$invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);

$totalReceived = array_sum(array_map(
    static fn(array $row): float => (float) ($row['amount'] ?? 0),
    $payments
));
$totalOutstanding = array_sum(array_map(
    static fn(array $row): float => (float) ($row['outstanding_amount'] ?? 0),
    $outstanding
));

require APP_ROOT . '/views/payment_received.html';
