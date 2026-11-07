<?php
/**
 * Module 15: vendor payments made.
 * Uses the canonical payment_made, vendors, purchase_orders and
 * invoice_receipts tables. Delegates writes to the AP library.
 */

defined('PAYMENT_MADE_MODULE') || define('PAYMENT_MADE_MODULE', 'payment_made');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/ap_functions.php';

/**
 * List vendor payments with supplier, PO and invoice context.
 */
function getPaymentsMadeByCompany(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare(
        "SELECT pm.*, v.name AS supplier_name, v.vendor_id AS supplier_id,
                po.po_number, ir.invoice_number, cu.full_name AS created_by_name
         FROM payment_made pm
         LEFT JOIN vendors v ON pm.vendor_id = v.vendor_id
         LEFT JOIN purchase_orders po ON pm.purchase_order_id = po.purchase_order_id
         LEFT JOIN invoice_receipts ir ON pm.invoice_receipt_id = ir.invoice_receipt_id
         LEFT JOIN users cu ON pm.created_by = cu.user_id
         WHERE pm.company_id = ?
         ORDER BY pm.payment_date DESC, pm.payment_id DESC"
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch one vendor payment with context.
 */
function getPaymentMadeById(PDO $pdo, int $paymentId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT pm.*, v.name AS supplier_name, v.vendor_id AS supplier_id,
                po.po_number, ir.invoice_number, cu.full_name AS created_by_name
         FROM payment_made pm
         LEFT JOIN vendors v ON pm.vendor_id = v.vendor_id
         LEFT JOIN purchase_orders po ON pm.purchase_order_id = po.purchase_order_id
         LEFT JOIN invoice_receipts ir ON pm.invoice_receipt_id = ir.invoice_receipt_id
         LEFT JOIN users cu ON pm.created_by = cu.user_id
         WHERE pm.payment_id = ?"
    );
    $stmt->execute([$paymentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Record a vendor payment. Returns the new payment id, or 0 on invalid input.
 */
function recordPaymentMade(PDO $pdo, array $paymentData): int
{
    $vendorId = (int) ($paymentData['vendor_id'] ?? $paymentData['supplier_id'] ?? 0);
    $amount = (float) ($paymentData['amount'] ?? 0);

    if ($vendorId <= 0 || $amount <= 0) {
        return 0;
    }

    $paymentData['vendor_id'] = $vendorId;
    $paymentData['amount'] = $amount;

    return recordSupplierPayment($pdo, $paymentData);
}

/**
 * Outstanding balance per supplier, based on unpaid invoice receipts.
 */
function getOutstandingPayables(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare(
        "SELECT v.vendor_id, v.vendor_id AS supplier_id, v.name AS supplier_name,
                COALESCE(SUM(ir.invoice_total), 0) AS invoice_total,
                COALESCE(SUM(ir.invoice_total - ir.amount_due), 0) AS paid_amount,
                COALESCE(SUM(ir.amount_due), 0) AS outstanding_amount
         FROM vendors v
         INNER JOIN invoice_receipts ir
                 ON ir.vendor_id = v.vendor_id AND ir.company_id = ? AND ir.paid = 0
         WHERE v.company_id = ?
         GROUP BY v.vendor_id, v.name
         HAVING outstanding_amount > 0
         ORDER BY outstanding_amount DESC"
    );
    $stmt->execute([$companyId, $companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Totals paid to vendors, grouped by payment method.
 */
function getPaymentsMadeSummaryByMethod(PDO $pdo, int $companyId = 1): array
{
    return getSupplierPaymentSummaryByMethod($pdo, $companyId);
}

/**
 * Aggregate totals for the payments-made page.
 */
function getPaymentsMadeTotals(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS payment_count, COALESCE(SUM(amount), 0) AS total_amount
         FROM payment_made WHERE company_id = ?'
    );
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'payment_count' => (int) ($row['payment_count'] ?? 0),
        'total_amount' => (float) ($row['total_amount'] ?? 0),
    ];
}

/**
 * Whether any payment exists for a purchase order.
 */
function paymentExistsForPO(PDO $pdo, int $poId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment_made WHERE purchase_order_id = ?');
    $stmt->execute([$poId]);
    return (int) $stmt->fetchColumn() > 0;
}
