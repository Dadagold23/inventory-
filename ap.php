<?php
/**
 * Module 6: Accounts Payable Controller
 * Suppliers, purchase orders, invoice receipts and vendor payments.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('AP_MODULE') || define('AP_MODULE', 'ap');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/inventory_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/ap_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    [$payablesResource, $payablesWriteAction] = match ($postAction) {
        'save_supplier' => ['payables', (int) ($_POST['supplier_id'] ?? 0) > 0 ? 'update' : 'create'],
        'deactivate_supplier' => ['payables', 'delete'],
        'save_po' => ['payables', (int) ($_POST['po_id'] ?? 0) > 0 ? 'update' : 'create'],
        'add_po_line', 'update_ir' => ['payables', 'update'],
        'record_ir' => ['payables', 'create'],
        'record_sp_payment' => ['payments', 'create'],
        default => ['payables', 'update'],
    };
    require_permission($payablesResource, $payablesWriteAction);
} else {
    require_permission('payables', 'view');
}

$action = (string) ($_GET['action'] ?? 'suppliers');
if (!in_array($action, ['suppliers', 'purchase_orders', 'invoices', 'payments'], true)) {
    $action = 'suppliers';
}

$supplierId = (int) ($_GET['supplier_id'] ?? 0);
$poId = (int) ($_GET['po_id'] ?? 0);
$irId = (int) ($_GET['ir_id'] ?? 0);

$pageTitle = 'Accounts Payable';

// ============================================================
// POST actions
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'save_supplier') {
        $targetId = (int) ($_POST['supplier_id'] ?? 0);
        $name = trim((string) ($_POST['supplier_name'] ?? ''));

        if ($name === '') {
            $_SESSION['auth_message'] = 'A supplier name is required.';
            redirect('/ap?action=suppliers');
        }

        $payload = [
            'company_id' => $companyId,
            'name' => $name,
            'contact_name' => trim((string) ($_POST['contact_name'] ?? '')),
            'tax_id' => trim((string) ($_POST['tax_id'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'city' => trim((string) ($_POST['city'] ?? '')),
            'state' => trim((string) ($_POST['state'] ?? '')),
            'postal_code' => trim((string) ($_POST['postal_code'] ?? '')),
            'country' => trim((string) ($_POST['country'] ?? '')),
            'payment_terms' => trim((string) ($_POST['payment_terms'] ?? 'Net 30')),
            'default_currency' => trim((string) ($_POST['default_currency'] ?? 'USD')),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ];

        if ($targetId > 0) {
            $existing = getSupplierById($pdo, $targetId);
            if (!$existing || (int) $existing['company_id'] !== $companyId) {
                $_SESSION['auth_message'] = 'Supplier not found.';
                redirect('/ap?action=suppliers');
            }
            updateSupplier($pdo, $targetId, $payload);
            $_SESSION['auth_message'] = 'Supplier updated successfully.';
        } else {
            $targetId = createSupplier($pdo, $payload);
            $_SESSION['auth_message'] = 'Supplier created successfully.';
        }

        redirect('/ap?action=suppliers' . ($targetId > 0 ? '&supplier_id=' . $targetId : ''));
    }

    if ($postAction === 'deactivate_supplier') {
        $targetId = (int) ($_POST['supplier_id'] ?? 0);
        $existing = $targetId > 0 ? getSupplierById($pdo, $targetId) : null;

        if ($existing && (int) $existing['company_id'] === $companyId) {
            updateSupplier($pdo, $targetId, ['is_active' => 0]);
            $_SESSION['auth_message'] = 'Supplier deactivated.';
        } else {
            $_SESSION['auth_message'] = 'Supplier not found.';
        }

        redirect('/ap?action=suppliers');
    }

    if ($postAction === 'save_po') {
        $targetId = (int) ($_POST['po_id'] ?? 0);
        $vendorId = (int) ($_POST['supplier_id'] ?? 0);
        $subtotal = (float) ($_POST['po_subtotal'] ?? 0);
        $discountPercent = (float) ($_POST['po_discount_percent'] ?? 0);
        $discountAmount = (float) ($_POST['po_discount_amount'] ?? 0);
        $taxRate = (float) ($_POST['po_tax_rate'] ?? 0);

        if ($discountAmount <= 0 && $discountPercent > 0 && $subtotal > 0) {
            $discountAmount = $subtotal * ($discountPercent / 100);
        }
        $taxable = max($subtotal - $discountAmount, 0);
        $taxAmount = $taxable * $taxRate;
        $totalAmount = $taxable + $taxAmount;

        if ($vendorId <= 0) {
            $_SESSION['auth_message'] = 'Select a supplier for the purchase order.';
            redirect('/ap?action=purchase_orders');
        }

        auth_guard_branch($pdo, (int) ($_POST['branch_id'] ?? 0));

        $payload = [
            'company_id' => $companyId,
            'vendor_id' => $vendorId,
            'branch_id' => (int) ($_POST['branch_id'] ?? 0) ?: null,            'status' => (string) ($_POST['po_status'] ?? 'draft'),
            'issue_date' => (string) ($_POST['issue_date'] ?? date('Y-m-d')),
            'required_date' => (string) ($_POST['required_date'] ?? '') ?: null,
            'subtotal' => $subtotal,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'amount_owed' => $totalAmount,
            'notes' => trim((string) ($_POST['po_notes'] ?? '')),
        ];

        if ($targetId > 0) {
            $existing = getPurchaseOrderById($pdo, $targetId);
            if (!$existing || (int) $existing['company_id'] !== $companyId) {
                $_SESSION['auth_message'] = 'Purchase order not found.';
                redirect('/ap?action=purchase_orders');
            }
            unset($payload['company_id']);
            $payload['approved_by'] = in_array($payload['status'], ['received', 'completed'], true)
                ? ($_SESSION['user_id'] ?? null)
                : null;
            updatePurchaseOrder($pdo, $targetId, $payload);
            $_SESSION['auth_message'] = 'Purchase order updated successfully.';
        } else {
            $payload['created_by'] = $_SESSION['user_id'] ?? null;
            $targetId = createPurchaseOrder($pdo, $payload);
            $_SESSION['auth_message'] = 'Purchase order created successfully.';
        }

        redirect('/ap?action=purchase_orders&po_id=' . $targetId);
    }

    if ($postAction === 'add_po_line') {
        $targetId = (int) ($_POST['po_id'] ?? 0);
        $existing = $targetId > 0 ? getPurchaseOrderById($pdo, $targetId) : null;

        if (!$existing || (int) $existing['company_id'] !== $companyId) {
            $_SESSION['auth_message'] = 'Purchase order not found.';
            redirect('/ap?action=purchase_orders');
        }

        $lineId = addPurchaseOrderLine($pdo, $targetId, [
            'product_id' => (int) ($_POST['product_id'] ?? 0),
            'quantity' => (float) ($_POST['quantity'] ?? 0),
            'unit_price' => (float) ($_POST['unit_price'] ?? 0),
            'unit_cost' => (float) ($_POST['unit_cost'] ?? 0),
        ]);

        $_SESSION['auth_message'] = $lineId > 0
            ? 'Purchase order line added.'
            : 'Select a product and a quantity greater than zero.';

        redirect('/ap?action=purchase_orders&po_id=' . $targetId);
    }

    if ($postAction === 'record_ir') {
        $targetPoId = (int) ($_POST['po_id'] ?? 0);
        $po = $targetPoId > 0 ? getPurchaseOrderById($pdo, $targetPoId) : null;

        if (!$po || (int) $po['company_id'] !== $companyId) {
            $_SESSION['auth_message'] = 'Purchase order not found.';
            redirect('/ap?action=purchase_orders');
        }

        $invoiceNumber = trim((string) ($_POST['invoice_number'] ?? ''));
        if ($invoiceNumber === '') {
            $_SESSION['auth_message'] = 'An invoice number is required.';
            redirect('/ap?action=purchase_orders&po_id=' . $targetPoId);
        }

        $irId = createInvoiceReceipt($pdo, [
            'company_id' => $companyId,
            'purchase_order_id' => $targetPoId,
            'vendor_id' => (int) $po['vendor_id'],
            'invoice_number' => $invoiceNumber,
            'receipt_number' => trim((string) ($_POST['receipt_number'] ?? '')),
            'invoice_date' => (string) ($_POST['invoice_date'] ?? date('Y-m-d')),
            'receipt_date' => (string) ($_POST['receipt_date'] ?? date('Y-m-d')),
            'invoice_total' => (float) ($_POST['invoice_total'] ?? 0),
            'receipt_total' => (float) ($_POST['receipt_total'] ?? 0),
            'invoiced_quantity' => (float) ($_POST['invoiced_qty'] ?? 0),
            'received_quantity' => (float) ($_POST['received_qty'] ?? 0),
            'notes' => trim((string) ($_POST['ir_notes'] ?? '')),
        ]);

        $_SESSION['auth_message'] = $irId > 0
            ? 'Invoice/receipt recorded successfully.'
            : 'Unable to record the invoice/receipt.';

        redirect('/ap?action=purchase_orders&po_id=' . $targetPoId);
    }

    if ($postAction === 'record_sp_payment') {
        $vendorId = (int) ($_POST['supplier_id'] ?? 0);
        $amount = (float) ($_POST['payment_amount'] ?? 0);

        $supplier = $vendorId > 0 ? getSupplierById($pdo, $vendorId) : null;
        if (!$supplier || (int) $supplier['company_id'] !== $companyId) {
            $_SESSION['auth_message'] = 'Supplier not found.';
            redirect('/ap?action=payments');
        }

        if ($amount <= 0) {
            $_SESSION['auth_message'] = 'Enter a payment amount greater than zero.';
            redirect('/ap?action=payments');
        }

        $paymentId = recordSupplierPayment($pdo, [
            'company_id' => $companyId,
            'vendor_id' => $vendorId,
            'purchase_order_id' => (int) ($_POST['purchase_order_id'] ?? 0),
            'invoice_receipt_id' => (int) ($_POST['invoice_receipt_id'] ?? 0),
            'payment_date' => (string) ($_POST['payment_date'] ?? date('Y-m-d')),
            'amount' => $amount,
            'payment_method' => (string) ($_POST['payment_method'] ?? 'bank_transfer'),
            'reference_number' => trim((string) ($_POST['payment_reference'] ?? '')),
            'notes' => trim((string) ($_POST['payment_notes'] ?? '')),
        ]);

        $_SESSION['auth_message'] = $paymentId > 0
            ? 'Supplier payment recorded.'
            : 'Unable to record the supplier payment.';

        redirect('/ap?action=payments');
    }

    if ($postAction === 'update_ir') {
        $targetIrId = (int) ($_POST['ir_id'] ?? 0);
        $ir = $targetIrId > 0 ? getInvoiceReceiptById($pdo, $targetIrId) : null;

        if (!$ir || (int) $ir['company_id'] !== $companyId) {
            $_SESSION['auth_message'] = 'Invoice/receipt not found.';
            redirect('/ap?action=invoices');
        }

        $paid = !empty($_POST['ir_paid']) ? 1 : 0;
        $status = (string) ($_POST['ir_status'] ?? $ir['status']);

        updateInvoiceReceipt($pdo, $targetIrId, [
            'status' => $status,
            'paid' => $paid,
            'payment_date' => $paid ? date('Y-m-d') : null,
        ]);

        $_SESSION['auth_message'] = 'Invoice/receipt updated.';
        redirect('/ap?action=invoices');
    }
}

// ============================================================
// Load data
// ============================================================

$suppliers = getSuppliersByCompany($pdo, $companyId);
$supplier = [];
if ($supplierId > 0) {
    $supplier = getSupplierById($pdo, $supplierId) ?? [];
    if ($supplier !== [] && (int) $supplier['company_id'] !== $companyId) {
        $supplier = [];
        $supplierId = 0;
    }
}

$pos = getPurchaseOrdersByCompany($pdo, $companyId);
$po = [];
$poLines = [];
if ($poId > 0) {
    $po = getPurchaseOrderById($pdo, $poId) ?? [];
    if ($po !== [] && (int) $po['company_id'] !== $companyId) {
        $po = [];
        $poId = 0;
    } else {
        $poLines = getPoLines($pdo, $poId);
    }
}

$invoices = getInvoiceReceiptsByCompany($pdo, $companyId);
$invoice = [];
if ($irId > 0) {
    $invoice = getInvoiceReceiptById($pdo, $irId) ?? [];
    if ($invoice !== [] && (int) $invoice['company_id'] !== $companyId) {
        $invoice = [];
        $irId = 0;
    }
}

$payments = getPaymentsSuppliersByCompany($pdo, $companyId);
$supplierPaymentSummary = getSupplierPaymentSummaryByMethod($pdo, $companyId);
$supplierOutstanding = $supplierId > 0 ? getSupplierOutstanding($pdo, $supplierId) : 0.0;
$defaultTaxRate = (float) getCompanySetting($pdo, 'default_tax_rate', 0.0);
$apTerms = getApTerms($pdo);
$branches = getBranchesByCompany($pdo, $companyId);
$products = getProductsByCompany($pdo, $companyId);

$totalOutstanding = array_sum(array_map(
    static fn(array $row): float => (float) ($row['total_outstanding'] ?? 0),
    $suppliers
));
$totalPaid = array_sum(array_map(
    static fn(array $row): float => (float) ($row['amount'] ?? 0),
    $payments
));

if ($action === 'purchase_orders') {
    $pageTitle = 'Purchase Orders';
} elseif ($action === 'invoices') {
    $pageTitle = 'Invoice Receipts';
} elseif ($action === 'payments') {
    $pageTitle = 'Supplier Payments';
} else {
    $pageTitle = 'Suppliers';
}

require APP_ROOT . '/views/ap.html';
