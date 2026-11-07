<?php
/**
 * Module 15: Payment Made Controller
 * Vendor payments made and accounts payable.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('PAYMENT_MADE_MODULE') || define('PAYMENT_MADE_MODULE', 'payment_made');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/ap_functions.php';
require_once APP_ROOT . '/includes/payment_made_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();

require_permission('payments', $_SERVER['REQUEST_METHOD'] === 'POST' ? 'create' : 'view');

$action = (string) ($_GET['action'] ?? 'list');
if (!in_array($action, ['list'], true)) {
    $action = 'list';
}

$pageTitle = 'Payments Made';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'record_payment') {
        $vendorId = (int) ($_POST['vendor_id'] ?? $_POST['supplier_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $poId = (int) ($_POST['purchase_order_id'] ?? 0);
        $irId = (int) ($_POST['invoice_receipt_id'] ?? 0);

        $supplier = $vendorId > 0 ? getSupplierById($pdo, $vendorId) : null;
        if (!$supplier || (int) $supplier['company_id'] !== $companyId) {
            $_SESSION['auth_message'] = 'Select a valid supplier.';
            redirect('/payment_made?action=list');
        }

        if ($amount <= 0) {
            $_SESSION['auth_message'] = 'Enter a payment amount greater than zero.';
            redirect('/payment_made?action=list');
        }

        if ($irId > 0) {
            $ir = getInvoiceReceiptById($pdo, $irId);
            if (!$ir
                || (int) $ir['company_id'] !== $companyId
                || (int) $ir['vendor_id'] !== $vendorId) {
                $_SESSION['auth_message'] = 'The selected invoice does not belong to this supplier.';
                redirect('/payment_made?action=list');
            }
            if ($poId <= 0 && !empty($ir['purchase_order_id'])) {
                $poId = (int) $ir['purchase_order_id'];
            }
        }

        if ($poId > 0) {
            $po = getPurchaseOrderById($pdo, $poId);
            if (!$po
                || (int) $po['company_id'] !== $companyId
                || (int) $po['vendor_id'] !== $vendorId) {
                $_SESSION['auth_message'] = 'The selected purchase order does not belong to this supplier.';
                redirect('/payment_made?action=list');
            }
        }

        $paymentMethod = (string) ($_POST['payment_method'] ?? 'bank_transfer');
        if (!in_array($paymentMethod, ['cash', 'check', 'bank_transfer', 'credit_card', 'debit_card'], true)) {
            $paymentMethod = 'bank_transfer';
        }

        try {
            $pdo->beginTransaction();
            $paymentId = recordPaymentMade($pdo, [
                'company_id' => $companyId,
                'vendor_id' => $vendorId,
                'purchase_order_id' => $poId,
                'invoice_receipt_id' => $irId,
                'payment_date' => (string) ($_POST['payment_date'] ?? date('Y-m-d')),
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference_number' => trim((string) ($_POST['reference_number'] ?? '')),
                'notes' => trim((string) ($_POST['notes'] ?? '')),
                'created_by' => $_SESSION['user_id'] ?? null,
            ]);
            if ($paymentId <= 0) {
                throw new RuntimeException('The payment could not be recorded.');
            }
            $pdo->commit();
            $_SESSION['auth_message'] = 'Payment made recorded successfully.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('payment_made: ' . $e->getMessage());
            $_SESSION['auth_message'] = 'Unable to record the payment. Please verify the details.';
        }

        redirect('/payment_made?action=list');
    }
}

$suppliers = getSuppliersByCompany($pdo, $companyId);
$payments = getPaymentsMadeByCompany($pdo, $companyId);
$outstanding = getOutstandingPayables($pdo, $companyId);
$paymentSummary = getPaymentsMadeSummaryByMethod($pdo, $companyId);
$totals = getPaymentsMadeTotals($pdo, $companyId);

$purchaseOrders = array_values(array_filter(
    getPurchaseOrdersByCompany($pdo, $companyId),
    static fn(array $po): bool => !in_array($po['status'], ['cancelled', 'completed'], true)
        && (float) ($po['amount_owed'] ?? 0) > 0
));

$openInvoices = array_values(array_filter(
    getInvoiceReceiptsByCompany($pdo, $companyId),
    static fn(array $ir): bool => (int) ($ir['paid'] ?? 0) === 0
        && (float) ($ir['amount_due'] ?? 0) > 0
));

$totalOutstanding = array_sum(array_map(
    static fn(array $row): float => (float) ($row['outstanding_amount'] ?? 0),
    $outstanding
));

require APP_ROOT . '/views/payment_made.html';
