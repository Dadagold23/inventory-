<?php
/**
 * Module 14: Customer payments received.
 * Uses the canonical customer_payments table.
 */

defined('PAYMENT_RECEIVED_MODULE') || define('PAYMENT_RECEIVED_MODULE', 'payment_received');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/database.php';

/**
 * List payments received with customer and invoice context.
 */
function getPaymentsReceivedByCompany(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare(
        "SELECT cp.*, c.name AS customer_name,
                si.invoice_number, si.total_amount AS invoice_total
         FROM customer_payments cp
         LEFT JOIN customers c ON cp.customer_id = c.customer_id
         LEFT JOIN sales_invoices si ON cp.invoice_id = si.invoice_id
         WHERE cp.company_id = ?
         ORDER BY cp.payment_date DESC, cp.payment_id DESC"
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch one payment.
 */
function getPaymentById(PDO $pdo, int $paymentId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM customer_payments WHERE payment_id = ?');
    $stmt->execute([$paymentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Record a customer payment and refresh the invoice payment status.
 */
function recordPaymentReceived(PDO $pdo, array $paymentData): int
{
    $invoiceId = (int) ($paymentData['invoice_id'] ?? 0);
    $customerId = (int) ($paymentData['customer_id'] ?? 0);
    $companyId = (int) ($paymentData['company_id'] ?? 1);
    $amount = (float) ($paymentData['amount'] ?? 0);

    if ($invoiceId <= 0 || $customerId <= 0 || $amount <= 0) {
        return 0;
    }

    $check = $pdo->prepare(
        'SELECT customer_id, company_id, status FROM sales_invoices WHERE invoice_id = ?'
    );
    $check->execute([$invoiceId]);
    $invoice = $check->fetch(PDO::FETCH_ASSOC);
    if (!$invoice
        || (int) $invoice['company_id'] !== $companyId
        || (int) $invoice['customer_id'] !== $customerId
        || $invoice['status'] === 'cancelled') {
        return 0;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO customer_payments
                (company_id, customer_id, invoice_id, amount, payment_date, payment_method, reference_number, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $companyId,
            $customerId,
            $invoiceId,
            $amount,
            $paymentData['payment_date'] ?? date('Y-m-d'),
            $paymentData['payment_method'] ?? 'bank_transfer',
            $paymentData['reference_number'] ?? '',
            $paymentData['notes'] ?? '',
        ]);
        $newPaymentId = (int) $pdo->lastInsertId();

        refreshInvoicePaymentStatus($pdo, $invoiceId);

        $pdo->commit();
        return $newPaymentId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Refresh an invoice's paid amount, balance and status from its payments.
 */
function refreshInvoicePaymentStatus(PDO $pdo, int $invoiceId): void
{
    $stmt = $pdo->prepare(
        'SELECT si.total_amount,
                COALESCE((SELECT SUM(cp.amount) FROM customer_payments cp WHERE cp.invoice_id = si.invoice_id), 0) AS paid
         FROM sales_invoices si
         WHERE si.invoice_id = ?'
    );
    $stmt->execute([$invoiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $total = (float) $row['total_amount'];
    $paid = (float) $row['paid'];
    $balance = max($total - $paid, 0);

    $status = 'pending';
    if ($total > 0 && $paid + 0.0001 >= $total) {
        $status = 'paid';
    } elseif ($paid > 0) {
        $status = 'partial';
    }

    $pdo->prepare(
        'UPDATE sales_invoices
         SET status = ?, amount_paid = ?, amount_owed = ?, paid_date = ?
         WHERE invoice_id = ?'
    )->execute([
        $status,
        $paid,
        $balance,
        $status === 'paid' ? date('Y-m-d') : null,
        $invoiceId,
    ]);
}

/**
 * Outstanding balance per customer.
 */
function getOutstandingReceivables(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare(
        "SELECT c.customer_id, c.name AS customer_name,
                SUM(GREATEST(si.total_amount - COALESCE(pay.paid, 0), 0)) AS outstanding_amount
         FROM sales_invoices si
         INNER JOIN customers c ON c.customer_id = si.customer_id
         LEFT JOIN (
             SELECT invoice_id, SUM(amount) AS paid
             FROM customer_payments
             WHERE company_id = ?
             GROUP BY invoice_id
         ) pay ON pay.invoice_id = si.invoice_id
         WHERE si.company_id = ? AND si.status IN ('pending', 'partial')
         GROUP BY c.customer_id, c.name
         HAVING outstanding_amount > 0
         ORDER BY outstanding_amount DESC"
    );
    $stmt->execute([$companyId, $companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Totals by payment method.
 */
function getPaymentSummaryByMethod(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare(
        'SELECT payment_method, COUNT(*) AS transaction_count, SUM(amount) AS total_amount
         FROM customer_payments
         WHERE company_id = ?
         GROUP BY payment_method'
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Whether any payment exists for an invoice.
 */
function paymentExistsForInvoice(PDO $pdo, int $invoiceId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM customer_payments WHERE invoice_id = ?');
    $stmt->execute([$invoiceId]);
    return (int) $stmt->fetchColumn() > 0;
}
