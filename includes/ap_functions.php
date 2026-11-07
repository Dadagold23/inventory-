<?php
/**
 * Module 6: Accounts Payable Functions
 * Suppliers (vendors), purchase orders, invoice receipts and vendor payments.
 * Uses the canonical tables: vendors, purchase_orders, purchase_order_items,
 * invoice_receipts and payment_made.
 */

// Prevent direct access
defined('AP_MODULE') || define('AP_MODULE', 'ap');

// Require foundation
defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';

if (!function_exists('apCompanyId')) {
    function apCompanyId(): int
    {
        return function_exists('currentCompanyId') ? currentCompanyId() : 1;
    }
}

if (!function_exists('apColumnList')) {
    /**
     * Build an UPDATE assignment list from the allowed keys that were supplied.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    function apColumnList(array $data, array $allowed): array
    {
        $sets = [];
        $params = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $data)) {
                $sets[] = $column . ' = ?';
                $params[] = $data[$column];
            }
        }
        return [implode(', ', $sets), $params];
    }
}

if (!function_exists('apValidPoStatus')) {
    function apValidPoStatus(string $status): string
    {
        $allowed = ['draft', 'sent', 'pending', 'partial', 'partial_received', 'received', 'completed', 'cancelled'];
        return in_array($status, $allowed, true) ? $status : 'draft';
    }
}

// ============================================================
// Supplier (vendor) functions
// ============================================================

/**
 * Get all suppliers for a company.
 */
function getSuppliersByCompany(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT v.*, v.vendor_id AS supplier_id,
               (SELECT COUNT(*) FROM purchase_orders po
                 WHERE po.vendor_id = v.vendor_id
                   AND po.status IN ('sent','pending','partial','partial_received','received','completed')) AS po_count,
               (SELECT COALESCE(SUM(ir.amount_due), 0) FROM invoice_receipts ir
                 WHERE ir.vendor_id = v.vendor_id AND ir.paid = 0) AS total_outstanding
        FROM vendors v
        WHERE v.company_id = ?
        ORDER BY v.is_active DESC, v.name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a specific supplier.
 */
function getSupplierById(PDO $pdo, int $supplierId): ?array
{
    $stmt = $pdo->prepare("SELECT v.*, v.vendor_id AS supplier_id FROM vendors v WHERE v.vendor_id = ?");
    $stmt->execute([$supplierId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Create a supplier.
 */
function createSupplier(PDO $pdo, array $supplierData): int
{
    $stmt = $pdo->prepare("
        INSERT INTO vendors (company_id, name, contact_name, tax_id, email, phone,
                             address, city, state, postal_code, country, payment_terms,
                             is_tax_exempt, default_currency, notes, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $supplierData['company_id'] ?? apCompanyId(),
        $supplierData['name'] ?? '',
        $supplierData['contact_name'] ?? null,
        $supplierData['tax_id'] ?? null,
        $supplierData['email'] ?? null,
        $supplierData['phone'] ?? null,
        $supplierData['address'] ?? null,
        $supplierData['city'] ?? null,
        $supplierData['state'] ?? null,
        $supplierData['postal_code'] ?? null,
        $supplierData['country'] ?? null,
        $supplierData['payment_terms'] ?? 'Net 30',
        !empty($supplierData['is_tax_exempt']) ? 1 : 0,
        $supplierData['default_currency'] ?? 'USD',
        $supplierData['notes'] ?? null,
        array_key_exists('is_active', $supplierData) ? (int) (bool) $supplierData['is_active'] : 1,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Update a supplier. Only supplied fields are written so partial updates
 * (for example deactivation) cannot blank out the rest of the record.
 */
function updateSupplier(PDO $pdo, int $supplierId, array $supplierData): bool
{
    $allowed = [
        'name', 'contact_name', 'tax_id', 'email', 'phone', 'address', 'city',
        'state', 'postal_code', 'country', 'payment_terms', 'is_tax_exempt',
        'default_currency', 'notes', 'is_active',
    ];

    if (array_key_exists('is_tax_exempt', $supplierData)) {
        $supplierData['is_tax_exempt'] = !empty($supplierData['is_tax_exempt']) ? 1 : 0;
    }
    if (array_key_exists('is_active', $supplierData)) {
        $supplierData['is_active'] = (int) (bool) $supplierData['is_active'];
    }

    [$set, $params] = apColumnList($supplierData, $allowed);
    if ($set === '') {
        return true;
    }

    $params[] = $supplierId;
    $stmt = $pdo->prepare("UPDATE vendors SET $set, updated_at = NOW() WHERE vendor_id = ?");
    return $stmt->execute($params);
}

// ============================================================
// Purchase order functions
// ============================================================

function getPurchaseOrdersByCompany(PDO $pdo, int $companyId = 1, ?string $status = null): array
{
    $cond = $status ? 'AND po.status = ?' : '';
    $params = $status ? [$status] : [];

    $stmt = $pdo->prepare("
        SELECT po.*, v.name AS supplier_name, v.vendor_id AS supplier_id,
               cu.full_name AS created_by_name, b.name AS branch_name,
               (SELECT COALESCE(SUM(poi.received_quantity), 0) FROM purchase_order_items poi
                 WHERE poi.purchase_order_id = po.purchase_order_id) AS total_received
        FROM purchase_orders po
        LEFT JOIN vendors v ON po.vendor_id = v.vendor_id
        LEFT JOIN users cu ON po.created_by = cu.user_id
        LEFT JOIN branches b ON po.branch_id = b.branch_id
        WHERE po.company_id = ? $cond
        ORDER BY po.issue_date DESC, po.purchase_order_id DESC
    ");
    array_unshift($params, $companyId);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPurchaseOrderById(PDO $pdo, int $poId): ?array
{
    $stmt = $pdo->prepare("
        SELECT po.*, v.name AS supplier_name, v.vendor_id AS supplier_id,
               cu.full_name AS created_by_name, b.name AS branch_name,
               (SELECT COALESCE(SUM(poi.received_quantity), 0) FROM purchase_order_items poi
                 WHERE poi.purchase_order_id = po.purchase_order_id) AS total_received
        FROM purchase_orders po
        LEFT JOIN vendors v ON po.vendor_id = v.vendor_id
        LEFT JOIN users cu ON po.created_by = cu.user_id
        LEFT JOIN branches b ON po.branch_id = b.branch_id
        WHERE po.purchase_order_id = ?
    ");
    $stmt->execute([$poId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createPurchaseOrder(PDO $pdo, array $poData): int
{
    $poNumber = trim((string) ($poData['po_number'] ?? ''));
    if ($poNumber === '') {
        $poNumber = 'PO-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    $total = (float) ($poData['total_amount'] ?? 0);
    $received = (float) ($poData['amount_received'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO purchase_orders
            (company_id, branch_id, vendor_id, po_number, status, issue_date, due_date,
             required_date, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
             total_amount, amount_owed, amount_received, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $poData['company_id'] ?? apCompanyId(),
        $poData['branch_id'] ?? null,
        $poData['vendor_id'] ?? $poData['supplier_id'] ?? 0,
        $poNumber,
        apValidPoStatus((string) ($poData['status'] ?? 'draft')),
        $poData['issue_date'] ?? date('Y-m-d'),
        $poData['due_date'] ?? null,
        $poData['required_date'] ?? null,
        $poData['subtotal'] ?? 0,
        $poData['discount_percent'] ?? 0,
        $poData['discount_amount'] ?? 0,
        $poData['tax_rate'] ?? 0,
        $poData['tax_amount'] ?? 0,
        $total,
        $poData['amount_owed'] ?? max($total - $received, 0),
        $received,
        $poData['notes'] ?? null,
        $poData['created_by'] ?? ($_SESSION['user_id'] ?? null),
    ]);

    $newPoId = (int) $pdo->lastInsertId();

    if (!empty($poData['product_ids']) && is_array($poData['product_ids'])) {
        foreach ($poData['product_ids'] as $idx => $productId) {
            addPurchaseOrderLine($pdo, $newPoId, [
                'product_id' => (int) $productId,
                'quantity' => $poData['quantities'][$idx] ?? 1,
                'unit_price' => $poData['unit_prices'][$idx] ?? 0,
                'unit_cost' => $poData['unit_costs'][$idx] ?? 0,
            ]);
        }
    }

    updatePoOutstanding($pdo, $newPoId);
    return $newPoId;
}

/**
 * Update a purchase order. Missing keys are preserved.
 */
function updatePurchaseOrder(PDO $pdo, int $poId, array $poData): bool
{
    $allowed = [
        'vendor_id', 'branch_id', 'po_number', 'status', 'issue_date', 'due_date',
        'required_date', 'subtotal', 'discount_percent', 'discount_amount', 'tax_rate',
        'tax_amount', 'total_amount', 'amount_owed', 'amount_received', 'notes',
        'approved_by', 'approved_date',
    ];

    if (array_key_exists('supplier_id', $poData) && !array_key_exists('vendor_id', $poData)) {
        $poData['vendor_id'] = $poData['supplier_id'];
    }
    if (array_key_exists('status', $poData)) {
        $poData['status'] = apValidPoStatus((string) $poData['status']);
        if ($poData['status'] === 'received' || $poData['status'] === 'completed') {
            $poData['approved_date'] = $poData['approved_date'] ?? date('Y-m-d H:i:s');
        }
    }

    [$set, $params] = apColumnList($poData, $allowed);
    if ($set === '') {
        return true;
    }

    $params[] = $poId;
    $stmt = $pdo->prepare("UPDATE purchase_orders SET $set, updated_at = NOW() WHERE purchase_order_id = ?");
    $result = $stmt->execute($params);

    if ($result) {
        updatePoOutstanding($pdo, $poId);
    }
    return $result;
}

/**
 * Recalculate the outstanding and paid amounts for a purchase order from its
 * invoice receipts and recorded payments.
 */
function updatePoOutstanding(PDO $pdo, int $poId): bool
{
    if ($poId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT po.total_amount,
               (SELECT COALESCE(SUM(ir.invoice_total), 0) FROM invoice_receipts ir
                 WHERE ir.purchase_order_id = po.purchase_order_id) AS invoiced,
               (SELECT COALESCE(SUM(pm.amount), 0) FROM payment_made pm
                 LEFT JOIN invoice_receipts pir ON pm.invoice_receipt_id = pir.invoice_receipt_id
                 WHERE pm.purchase_order_id = po.purchase_order_id
                    OR pir.purchase_order_id = po.purchase_order_id) AS paid
        FROM purchase_orders po
        WHERE po.purchase_order_id = ?
    ");
    $stmt->execute([$poId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    $base = (float) $row['invoiced'] > 0 ? (float) $row['invoiced'] : (float) $row['total_amount'];
    $paid = (float) $row['paid'];
    $owed = max($base - $paid, 0);

    $update = $pdo->prepare("
        UPDATE purchase_orders SET amount_owed = ?, amount_received = ?, updated_at = NOW()
        WHERE purchase_order_id = ?
    ");
    return $update->execute([$owed, $paid, $poId]);
}

function getPoLines(PDO $pdo, int $poId): array
{
    $stmt = $pdo->prepare("
        SELECT poi.*, p.name AS product_name, p.sku AS product_sku
        FROM purchase_order_items poi
        LEFT JOIN products p ON poi.product_id = p.product_id
        WHERE poi.purchase_order_id = ?
        ORDER BY poi.item_id
    ");
    $stmt->execute([$poId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Add a single line to a purchase order and refresh the order total.
 */
function addPurchaseOrderLine(PDO $pdo, int $poId, array $lineData): int
{
    $productId = (int) ($lineData['product_id'] ?? 0);
    $quantity = (float) ($lineData['quantity'] ?? 0);
    $unitPrice = (float) ($lineData['unit_price'] ?? 0);
    $unitCost = (float) ($lineData['unit_cost'] ?? 0);
    $discountPercent = (float) ($lineData['discount_percent'] ?? 0);

    if ($poId <= 0 || $productId <= 0 || $quantity <= 0) {
        return 0;
    }

    $productStmt = $pdo->prepare("SELECT name, sku FROM products WHERE product_id = ?");
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);
    $description = $product
        ? trim((string) $product['name'] . ($product['sku'] ? ' (' . $product['sku'] . ')' : ''))
        : 'Item #' . $productId;

    $lineTotal = $unitPrice * $quantity * (1 - $discountPercent / 100);

    $stmt = $pdo->prepare("
        INSERT INTO purchase_order_items
            (purchase_order_id, product_id, description, quantity, unit_price, unit_cost,
             discount_percent, discount_amount, line_total, total_price, total_cost, received_quantity)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
    ");
    $stmt->execute([
        $poId, $productId, $description, $quantity, $unitPrice, $unitCost,
        $discountPercent, $unitPrice * $quantity * ($discountPercent / 100),
        $lineTotal, $lineTotal, $unitCost * $quantity,
    ]);

    recalculatePurchaseOrderTotals($pdo, $poId);
    return (int) $pdo->lastInsertId();
}

/**
 * Rebuild purchase order subtotal/tax/total from its lines.
 */
function recalculatePurchaseOrderTotals(PDO $pdo, int $poId): void
{
    $stmt = $pdo->prepare("
        SELECT po.tax_rate,
               COALESCE(SUM(poi.line_total), 0) AS subtotal,
               COALESCE(SUM(poi.discount_amount), 0) AS discount
        FROM purchase_orders po
        LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.purchase_order_id
        WHERE po.purchase_order_id = ?
        GROUP BY po.purchase_order_id, po.tax_rate
    ");
    $stmt->execute([$poId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $subtotal = (float) $row['subtotal'];
    $discount = (float) $row['discount'];
    $taxRate = (float) $row['tax_rate'];
    $taxable = max($subtotal - $discount, 0);
    $tax = $taxable * $taxRate;
    $total = $taxable + $tax;

    $pdo->prepare("
        UPDATE purchase_orders
        SET subtotal = ?, discount_amount = ?, tax_amount = ?, total_amount = ?, updated_at = NOW()
        WHERE purchase_order_id = ?
    ")->execute([$subtotal, $discount, $tax, $total, $poId]);

    updatePoOutstanding($pdo, $poId);
}

// ============================================================
// Invoice receipt (three-way match) functions
// ============================================================

function getInvoiceReceiptsByCompany(PDO $pdo, int $companyId = 1, ?string $status = null): array
{
    $cond = $status ? 'AND ir.status = ?' : '';
    $params = $status ? [$status] : [];

    $stmt = $pdo->prepare("
        SELECT ir.*, v.name AS supplier_name, v.vendor_id AS supplier_id,
               po.po_number, cu.full_name AS created_by_name
        FROM invoice_receipts ir
        LEFT JOIN vendors v ON ir.vendor_id = v.vendor_id
        LEFT JOIN purchase_orders po ON ir.purchase_order_id = po.purchase_order_id
        LEFT JOIN users cu ON ir.created_by = cu.user_id
        WHERE ir.company_id = ? $cond
        ORDER BY ir.invoice_date DESC, ir.invoice_receipt_id DESC
    ");
    array_unshift($params, $companyId);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getInvoiceReceiptById(PDO $pdo, int $irId): ?array
{
    $stmt = $pdo->prepare("
        SELECT ir.*, v.name AS supplier_name, v.vendor_id AS supplier_id,
               po.po_number, cu.full_name AS created_by_name
        FROM invoice_receipts ir
        LEFT JOIN vendors v ON ir.vendor_id = v.vendor_id
        LEFT JOIN purchase_orders po ON ir.purchase_order_id = po.purchase_order_id
        LEFT JOIN users cu ON ir.created_by = cu.user_id
        WHERE ir.invoice_receipt_id = ?
    ");
    $stmt->execute([$irId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Determine the three-way match status for an invoice/receipt pair.
 */
function apMatchStatus(float $invoiceTotal, float $receiptTotal, float $invoicedQty, float $receivedQty): string
{
    $tolerance = 0.01;
    $totalsMatch = abs($invoiceTotal - $receiptTotal) < $tolerance;
    $qtyMatch = abs($invoicedQty - $receivedQty) < $tolerance;

    if ($totalsMatch && $qtyMatch) {
        return 'full_match';
    }
    if ($totalsMatch) {
        return 'partial_match';
    }
    return 'mismatch';
}

function createInvoiceReceipt(PDO $pdo, array $irData): int
{
    $invoiceTotal = (float) ($irData['invoice_total'] ?? 0);
    $receiptTotal = (float) ($irData['receipt_total'] ?? 0);
    $invoicedQty = (float) ($irData['invoiced_quantity'] ?? $irData['invoiced_qty'] ?? 0);
    $receivedQty = (float) ($irData['received_quantity'] ?? $irData['received_qty'] ?? 0);

    $status = (string) ($irData['status'] ?? '');
    if (!in_array($status, ['matching', 'full_match', 'partial_match', 'mismatch', 'paid'], true)) {
        $status = apMatchStatus($invoiceTotal, $receiptTotal, $invoicedQty, $receivedQty);
    }

    $stmt = $pdo->prepare("
        INSERT INTO invoice_receipts
            (company_id, purchase_order_id, vendor_id, invoice_number, receipt_number,
             invoice_date, receipt_date, status, invoice_total, receipt_total,
             invoiced_quantity, received_quantity, quantity_variance, amount_due,
             paid, payment_date, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $irData['company_id'] ?? apCompanyId(),
        $irData['purchase_order_id'] ?? null,
        $irData['vendor_id'] ?? $irData['supplier_id'] ?? 0,
        $irData['invoice_number'] ?? ('INV-' . date('YmdHis')),
        $irData['receipt_number'] ?? null,
        $irData['invoice_date'] ?? date('Y-m-d'),
        $irData['receipt_date'] ?? null,
        $status,
        $invoiceTotal,
        $receiptTotal,
        $invoicedQty,
        $receivedQty,
        $invoicedQty - $receivedQty,
        $invoiceTotal,
        !empty($irData['paid']) ? 1 : 0,
        !empty($irData['paid']) ? ($irData['payment_date'] ?? date('Y-m-d')) : null,
        $irData['notes'] ?? null,
        $irData['created_by'] ?? ($_SESSION['user_id'] ?? null),
    ]);

    $irId = (int) $pdo->lastInsertId();
    $poId = (int) ($irData['purchase_order_id'] ?? 0);
    if ($poId > 0) {
        updatePoOutstanding($pdo, $poId);
    }
    return $irId;
}

/**
 * Update an invoice receipt. Missing keys are preserved.
 */
function updateInvoiceReceipt(PDO $pdo, int $irId, array $irData): bool
{
    $existing = getInvoiceReceiptById($pdo, $irId);
    if (!$existing) {
        return false;
    }

    $merged = array_merge($existing, $irData);
    $invoiceTotal = (float) $merged['invoice_total'];
    $receiptTotal = (float) $merged['receipt_total'];
    $invoicedQty = (float) $merged['invoiced_quantity'];
    $receivedQty = (float) $merged['received_quantity'];

    $allowed = [
        'purchase_order_id', 'vendor_id', 'invoice_number', 'receipt_number',
        'invoice_date', 'receipt_date', 'status', 'invoice_total', 'receipt_total',
        'invoiced_quantity', 'received_quantity', 'amount_due', 'paid', 'payment_date', 'notes',
    ];

    if (!array_key_exists('status', $irData)) {
        $irData['status'] = apMatchStatus($invoiceTotal, $receiptTotal, $invoicedQty, $receivedQty);
    }
    $irData['quantity_variance'] = $invoicedQty - $receivedQty;
    if (!empty($irData['paid'])) {
        $irData['payment_date'] = $irData['payment_date'] ?? date('Y-m-d');
        $irData['paid'] = 1;
    }

    $allowed[] = 'quantity_variance';
    [$set, $params] = apColumnList($irData, $allowed);
    if ($set === '') {
        return true;
    }

    $params[] = $irId;
    $stmt = $pdo->prepare("UPDATE invoice_receipts SET $set, updated_at = NOW() WHERE invoice_receipt_id = ?");
    $result = $stmt->execute($params);

    $poId = (int) ($merged['purchase_order_id'] ?? 0);
    if ($result && $poId > 0) {
        updatePoOutstanding($pdo, $poId);
    }
    return $result;
}

function checkThreeWayMatch(PDO $pdo, int $poId): array
{
    $stmt = $pdo->prepare("
        SELECT ir.*, po.total_amount AS po_total,
               (SELECT COALESCE(SUM(poi.quantity), 0) FROM purchase_order_items poi
                 WHERE poi.purchase_order_id = po.purchase_order_id) AS total_ordered,
               (SELECT COALESCE(SUM(poi.received_quantity), 0) FROM purchase_order_items poi
                 WHERE poi.purchase_order_id = po.purchase_order_id) AS total_received
        FROM invoice_receipts ir
        LEFT JOIN purchase_orders po ON ir.purchase_order_id = po.purchase_order_id
        WHERE ir.purchase_order_id = ?
        ORDER BY ir.invoice_receipt_id
    ");
    $stmt->execute([$poId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// Vendor payment functions
// ============================================================

function getPaymentsSuppliersByCompany(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT pm.*, v.name AS supplier_name, v.vendor_id AS supplier_id,
               po.po_number, ir.invoice_number, cu.full_name AS created_by_name
        FROM payment_made pm
        LEFT JOIN vendors v ON pm.vendor_id = v.vendor_id
        LEFT JOIN purchase_orders po ON pm.purchase_order_id = po.purchase_order_id
        LEFT JOIN invoice_receipts ir ON pm.invoice_receipt_id = ir.invoice_receipt_id
        LEFT JOIN users cu ON pm.created_by = cu.user_id
        WHERE pm.company_id = ?
        ORDER BY pm.payment_date DESC, pm.payment_id DESC
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Record a payment to a vendor and refresh the related invoice and PO.
 *
 * @return int New payment id, or 0 when the input is invalid.
 */
function recordSupplierPayment(PDO $pdo, array $paymentData): int
{
    $vendorId = (int) ($paymentData['vendor_id'] ?? $paymentData['supplier_id'] ?? 0);
    $amount = (float) ($paymentData['amount'] ?? 0);

    if ($vendorId <= 0 || $amount <= 0) {
        return 0;
    }

    $poId = (int) ($paymentData['purchase_order_id'] ?? 0);
    $irId = (int) ($paymentData['invoice_receipt_id'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO payment_made
            (company_id, vendor_id, purchase_order_id, invoice_receipt_id, payment_date,
             amount, payment_method, reference_number, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $paymentData['company_id'] ?? apCompanyId(),
        $vendorId,
        $poId > 0 ? $poId : null,
        $irId > 0 ? $irId : null,
        $paymentData['payment_date'] ?? date('Y-m-d'),
        $amount,
        $paymentData['payment_method'] ?? 'bank_transfer',
        $paymentData['reference_number'] ?? null,
        $paymentData['notes'] ?? null,
        $paymentData['created_by'] ?? ($_SESSION['user_id'] ?? null),
    ]);

    $paymentId = (int) $pdo->lastInsertId();

    if ($irId > 0) {
        $ir = getInvoiceReceiptById($pdo, $irId);
        if ($ir) {
            $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payment_made WHERE invoice_receipt_id = ?");
            $paidStmt->execute([$irId]);
            $paidTotal = (float) $paidStmt->fetchColumn();

            if ($paidTotal + 0.0001 >= (float) $ir['amount_due']) {
                $pdo->prepare("UPDATE invoice_receipts SET paid = 1, payment_date = ?, status = 'paid' WHERE invoice_receipt_id = ?")
                    ->execute([$paymentData['payment_date'] ?? date('Y-m-d'), $irId]);
            }
        }
    }

    if ($poId > 0) {
        updatePoOutstanding($pdo, $poId);
    }

    return $paymentId;
}

// ============================================================
// Utility functions
// ============================================================

function getApTerms(PDO $pdo): string
{
    return getCompanySetting($pdo, 'default_ap_terms', 'Net 30');
}

function getSupplierOutstanding(PDO $pdo, int $supplierId): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_due), 0) AS outstanding
        FROM invoice_receipts
        WHERE vendor_id = ? AND paid = 0
    ");
    $stmt->execute([$supplierId]);
    return (float) $stmt->fetchColumn();
}

function getSupplierPaymentHistory(PDO $pdo, int $supplierId): array
{
    $stmt = $pdo->prepare("
        SELECT pm.*, po.po_number, ir.invoice_number
        FROM payment_made pm
        LEFT JOIN purchase_orders po ON pm.purchase_order_id = po.purchase_order_id
        LEFT JOIN invoice_receipts ir ON pm.invoice_receipt_id = ir.invoice_receipt_id
        WHERE pm.vendor_id = ?
        ORDER BY pm.payment_date DESC, pm.payment_id DESC
    ");
    $stmt->execute([$supplierId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Totals paid to vendors, grouped by payment method.
 */
function getSupplierPaymentSummaryByMethod(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT payment_method, COUNT(*) AS transaction_count, SUM(amount) AS total_amount
        FROM payment_made
        WHERE company_id = ?
        GROUP BY payment_method
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
