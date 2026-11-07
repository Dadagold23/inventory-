<?php
/**
 * Module 7: Customer Management and Receivables Functions
 * Handles customer aging, credit control, statements and communication tracking.
 *
 * Canonical tables:
 *   customers, sales_invoices, customer_payments, customer_statements,
 *   customer_communications, users.
 */

// Prevent direct access
defined('CUSTOMER_MODULE') || define('CUSTOMER_MODULE', 'customer');

// Require foundation
defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';

// ============================================================
// Internal helpers
// ============================================================

if (!function_exists('receivablesCompanyId')) {
    function receivablesCompanyId(): int {
        if (function_exists('auth_company_id')) {
            $companyId = auth_company_id();
            if ($companyId > 0) {
                return $companyId;
            }
        }
        return 1;
    }
}

if (!function_exists('receivablesRound')) {
    function receivablesRound(float $value): float {
        return round($value, 4);
    }
}

if (!function_exists('receivablesCustomerInCompany')) {
    function receivablesCustomerInCompany(PDO $pdo, int $customerId, int $companyId): bool {
        $stmt = $pdo->prepare("SELECT 1 FROM customers WHERE customer_id = ? AND company_id = ?");
        $stmt->execute([$customerId, $companyId]);
        return (bool) $stmt->fetchColumn();
    }
}

// ============================================================
// Customer Balances and Aging
// ============================================================

/**
 * Get all customers with their outstanding balance and overdue position.
 * Inactive customers are included; the view decides how to present them.
 */
function getCustomersByCompanyWithCredit(PDO $pdo, int $companyId = 1): array {
    $stmt = $pdo->prepare("
        SELECT c.*,
               COALESCE(open_invoices.outstanding, 0) AS outstanding,
               COALESCE(open_invoices.overdue_amount, 0) AS overdue_amount,
               COALESCE(open_invoices.open_invoices, 0) AS open_invoices,
               open_invoices.oldest_due_date,
               COALESCE(paid.total_paid, 0) AS total_paid,
               COALESCE(paid.payment_count, 0) AS payment_count,
               COALESCE(last_sale.last_invoice_date, NULL) AS last_invoice_date
        FROM customers c
        LEFT JOIN (
            SELECT si.customer_id,
                   SUM(si.total_amount - si.amount_paid) AS outstanding,
                   SUM(CASE WHEN si.due_date IS NOT NULL AND si.due_date < CURDATE()
                            THEN si.total_amount - si.amount_paid ELSE 0 END) AS overdue_amount,
                   COUNT(*) AS open_invoices,
                   MIN(CASE WHEN si.due_date IS NOT NULL AND si.due_date < CURDATE()
                            THEN si.due_date END) AS oldest_due_date
            FROM sales_invoices si
            WHERE si.company_id = ? AND si.status IN ('pending', 'partial')
            GROUP BY si.customer_id
        ) open_invoices ON open_invoices.customer_id = c.customer_id
        LEFT JOIN (
            SELECT cp.customer_id, SUM(cp.amount) AS total_paid, COUNT(*) AS payment_count
            FROM customer_payments cp
            GROUP BY cp.customer_id
        ) paid ON paid.customer_id = c.customer_id
        LEFT JOIN (
            SELECT si.customer_id, MAX(si.issue_date) AS last_invoice_date
            FROM sales_invoices si
            WHERE si.company_id = ? AND si.status <> 'cancelled'
            GROUP BY si.customer_id
        ) last_sale ON last_sale.customer_id = c.customer_id
        WHERE c.company_id = ?
        ORDER BY c.payment_status, c.name
    ");
    $stmt->execute([$companyId, $companyId, $companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a single customer with aging figures.
 */
function getCustomerAging(PDO $pdo, int $customerId): array {
    $stmt = $pdo->prepare("
        SELECT c.*,
               COALESCE(open_invoices.outstanding, 0) AS outstanding,
               COALESCE(open_invoices.overdue_amount, 0) AS overdue_amount,
               COALESCE(open_invoices.open_invoices, 0) AS open_invoices,
               open_invoices.oldest_due_date,
               COALESCE(paid.total_paid, 0) AS total_paid,
               COALESCE(paid.payment_count, 0) AS payment_count,
               COALESCE(last_sale.last_invoice_date, NULL) AS last_invoice_date
        FROM customers c
        LEFT JOIN (
            SELECT si.customer_id,
                   SUM(si.total_amount - si.amount_paid) AS outstanding,
                   SUM(CASE WHEN si.due_date IS NOT NULL AND si.due_date < CURDATE()
                            THEN si.total_amount - si.amount_paid ELSE 0 END) AS overdue_amount,
                   COUNT(*) AS open_invoices,
                   MIN(CASE WHEN si.due_date IS NOT NULL AND si.due_date < CURDATE()
                            THEN si.due_date END) AS oldest_due_date
            FROM sales_invoices si
            WHERE si.status IN ('pending', 'partial')
            GROUP BY si.customer_id
        ) open_invoices ON open_invoices.customer_id = c.customer_id
        LEFT JOIN (
            SELECT cp.customer_id, SUM(cp.amount) AS total_paid, COUNT(*) AS payment_count
            FROM customer_payments cp
            GROUP BY cp.customer_id
        ) paid ON paid.customer_id = c.customer_id
        LEFT JOIN (
            SELECT si.customer_id, MAX(si.issue_date) AS last_invoice_date
            FROM sales_invoices si
            WHERE si.status <> 'cancelled'
            GROUP BY si.customer_id
        ) last_sale ON last_sale.customer_id = c.customer_id
        WHERE c.customer_id = ?
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Update a customer's credit limit and payment terms.
 */
function updateCustomerCreditTerms(PDO $pdo, int $customerId, float $creditLimit, string $paymentTerms): bool {
    $companyId = receivablesCompanyId();
    $creditLimit = max(0, receivablesRound($creditLimit));
    $allowedTerms = ['Due on Receipt', 'Net 7', 'Net 15', 'Net 30', 'Net 45', 'Net 60', 'Net 90'];
    if (!in_array($paymentTerms, $allowedTerms, true)) {
        $paymentTerms = 'Net 30';
    }

    $stmt = $pdo->prepare("
        UPDATE customers
        SET credit_limit = ?, payment_terms = ?, updated_at = NOW()
        WHERE customer_id = ? AND company_id = ?
    ");
    return $stmt->execute([$creditLimit, $paymentTerms, $customerId, $companyId]);
}

/**
 * Legacy helper retained for compatibility: adjust credit_used by a delta.
 */
function updateCreditUsage(PDO $pdo, int $customerId, float $amount): bool {
    $stmt = $pdo->prepare("
        UPDATE customers SET credit_used = GREATEST(0, credit_used + ?), updated_at = NOW()
        WHERE customer_id = ?
    ");
    return $stmt->execute([$amount, $customerId]);
}

/**
 * Check that a customer has enough credit for a new amount.
 */
function customerHasCreditAvailable(PDO $pdo, int $customerId, float $amount): bool {
    $customer = getCustomerAging($pdo, $customerId);
    if ($customer === []) {
        return false;
    }
    $limit = (float) ($customer['credit_limit'] ?? 0);
    // A zero limit means "no credit facility is configured", not "no credit".
    if ($limit <= 0) {
        return false;
    }
    $used = (float) ($customer['outstanding'] ?? $customer['credit_used'] ?? 0);
    return ($limit - $used) >= $amount;
}

/**
 * Get only customers with overdue invoices.
 */
function getOverdueCustomers(PDO $pdo, int $companyId = 1): array {
    $customers = getCustomersByCompanyWithCredit($pdo, $companyId);
    $overdue = array_filter($customers, static function (array $customer): bool {
        return (float) ($customer['overdue_amount'] ?? 0) > 0;
    });

    usort($overdue, static function (array $a, array $b): int {
        return (float) $b['overdue_amount'] <=> (float) $a['overdue_amount'];
    });

    return $overdue;
}

/**
 * Open invoices for a customer with balance and days overdue.
 */
function getCustomerInvoices(PDO $pdo, int $customerId): array {
    $stmt = $pdo->prepare("
        SELECT si.*,
               GREATEST(0, DATEDIFF(CURDATE(), si.due_date)) AS days_overdue
        FROM sales_invoices si
        WHERE si.customer_id = ? AND si.status <> 'cancelled'
        ORDER BY si.issue_date DESC, si.invoice_id DESC
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Payment history for a customer.
 */
function getCustomerPayments(PDO $pdo, int $customerId): array {
    $stmt = $pdo->prepare("
        SELECT cp.*, si.invoice_number
        FROM customer_payments cp
        LEFT JOIN sales_invoices si ON si.invoice_id = cp.invoice_id
        WHERE cp.customer_id = ?
        ORDER BY cp.payment_date DESC, cp.payment_id DESC
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Days overdue for a customer based on the oldest unpaid due date.
 */
function customerDaysOverdue(array $customer): int {
    if (empty($customer['oldest_due_date'])) {
        return 0;
    }
    $days = (int) floor((strtotime(date('Y-m-d')) - strtotime($customer['oldest_due_date'])) / 86400);
    return max(0, $days);
}

/**
 * Build aging buckets for a list of customers.
 */
function buildAgingBuckets(array $customers): array {
    $buckets = [
        'current' => 0.0,
        'days_1_30' => 0.0,
        'days_31_60' => 0.0,
        'days_61_90' => 0.0,
        'days_over_90' => 0.0,
    ];

    foreach ($customers as $customer) {
        $balance = (float) ($customer['overdue_amount'] ?? 0);
        if ($balance <= 0) {
            $buckets['current'] += (float) ($customer['outstanding'] ?? 0);
            continue;
        }

        $days = customerDaysOverdue($customer);
        if ($days <= 30) {
            $buckets['days_1_30'] += $balance;
        } elseif ($days <= 60) {
            $buckets['days_31_60'] += $balance;
        } elseif ($days <= 90) {
            $buckets['days_61_90'] += $balance;
        } else {
            $buckets['days_over_90'] += $balance;
        }
    }

    return $buckets;
}

// ============================================================
// Customer Statement Functions
// ============================================================

/**
 * Create a customer statement for the requested period with computed totals.
 * Returns the new statement id, or 0 when the customer is invalid.
 */
function createCustomerStatement(PDO $pdo, int $companyId, int $customerId, string $periodStart, string $periodEnd): int {
    if (!receivablesCustomerInCompany($pdo, $customerId, $companyId)) {
        return 0;
    }

    $start = date('Y-m-d', strtotime($periodStart));
    $end = date('Y-m-d', strtotime($periodEnd));
    if (strtotime($start) === false || strtotime($end) === false || $start > $end) {
        return 0;
    }

    $invoiceStmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN si.issue_date < ? THEN si.total_amount ELSE 0 END), 0) AS opening_sales,
               COALESCE(SUM(CASE WHEN si.issue_date BETWEEN ? AND ? THEN si.total_amount ELSE 0 END), 0) AS period_sales
        FROM sales_invoices si
        WHERE si.customer_id = ? AND si.company_id = ?
          AND si.status IN ('pending', 'partial', 'paid')
    ");
    $invoiceStmt->execute([$start, $start, $end, $customerId, $companyId]);
    $invoiceTotals = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: ['opening_sales' => 0, 'period_sales' => 0];

    $paymentStmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN cp.payment_date < ? THEN cp.amount ELSE 0 END), 0) AS opening_payments,
               COALESCE(SUM(CASE WHEN cp.payment_date BETWEEN ? AND ? THEN cp.amount ELSE 0 END), 0) AS period_payments
        FROM customer_payments cp
        INNER JOIN sales_invoices si ON si.invoice_id = cp.invoice_id
        WHERE cp.customer_id = ? AND cp.company_id = ?
          AND si.status <> 'cancelled'
    ");
    $paymentStmt->execute([$start, $start, $end, $customerId, $companyId]);
    $paymentTotals = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: ['opening_payments' => 0, 'period_payments' => 0];

    $openingBalance = receivablesRound((float) $invoiceTotals['opening_sales'] - (float) $paymentTotals['opening_payments']);
    $salesAmount = receivablesRound((float) $invoiceTotals['period_sales']);
    $paymentsReceived = receivablesRound((float) $paymentTotals['period_payments']);
    $endingBalance = receivablesRound($openingBalance + $salesAmount - $paymentsReceived);

    $stmt = $pdo->prepare("
        INSERT INTO customer_statements
            (company_id, customer_id, statement_date, period_start, period_end,
             opening_balance, sales_amount, payments_received, ending_balance, status, created_by)
        VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, 'draft', ?)
    ");
    $stmt->execute([
        $companyId,
        $customerId,
        $start,
        $end,
        $openingBalance,
        $salesAmount,
        $paymentsReceived,
        $endingBalance,
        (int) ($_SESSION['user_id'] ?? 1),
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Get a single statement with its customer.
 */
function getCustomerStatement(PDO $pdo, int $statementId): ?array {
    $stmt = $pdo->prepare("
        SELECT cs.*, c.name AS customer_name, c.email AS customer_email
        FROM customer_statements cs
        LEFT JOIN customers c ON cs.customer_id = c.customer_id
        WHERE cs.statement_id = ?
    ");
    $stmt->execute([$statementId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Get all statements for a customer.
 */
function getStatementsByCustomer(PDO $pdo, int $customerId): array {
    $stmt = $pdo->prepare("
        SELECT cs.*, c.name AS customer_name
        FROM customer_statements cs
        LEFT JOIN customers c ON cs.customer_id = c.customer_id
        WHERE cs.customer_id = ?
        ORDER BY cs.statement_date DESC, cs.statement_id DESC
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Change a statement's status between draft and sent.
 */
function updateStatementStatus(PDO $pdo, int $statementId, string $status): bool {
    if (!in_array($status, ['draft', 'sent'], true)) {
        return false;
    }
    $stmt = $pdo->prepare("UPDATE customer_statements SET status = ? WHERE statement_id = ?");
    return $stmt->execute([$status, $statementId]);
}

// ============================================================
// Communication Functions
// ============================================================

/**
 * Record a customer communication.
 */
function createCustomerCommunication(PDO $pdo, int $companyId, int $customerId, string $type, string $subject, string $body, ?int $createdBy = null): bool {
    if (!receivablesCustomerInCompany($pdo, $customerId, $companyId)) {
        return false;
    }

    if (!in_array($type, ['email', 'phone', 'letter', 'sms'], true)) {
        $type = 'email';
    }

    $contactName = '';
    $customerStmt = $pdo->prepare("SELECT name FROM customers WHERE customer_id = ?");
    $customerStmt->execute([$customerId]);
    $contactName = (string) ($customerStmt->fetchColumn() ?: '');

    $stmt = $pdo->prepare("
        INSERT INTO customer_communications
            (company_id, customer_id, contact_name, communication_type, subject, body, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    return $stmt->execute([
        $companyId,
        $customerId,
        $contactName,
        $type,
        $subject,
        $body,
        $createdBy ?? (int) ($_SESSION['user_id'] ?? 1),
    ]);
}

/**
 * Communication history for a customer.
 */
function getCustomerCommunications(PDO $pdo, int $customerId): array {
    $stmt = $pdo->prepare("
        SELECT cc.*, u.full_name AS created_by_name
        FROM customer_communications cc
        LEFT JOIN users u ON cc.created_by = u.user_id
        WHERE cc.customer_id = ?
        ORDER BY cc.sent_date DESC, cc.communication_id DESC
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// Utility Functions
// ============================================================

/**
 * Aging label for a number of days overdue.
 */
function getAgingCategory(float $daysOverdue): string {
    if ($daysOverdue <= 0) return 'Current';
    if ($daysOverdue <= 30) return '1-30 Days';
    if ($daysOverdue <= 60) return '31-60 Days';
    if ($daysOverdue <= 90) return '61-90 Days';
    return '90+ Days';
}

/**
 * CSS class used to colour aging rows.
 */
function getAgingClass(float $daysOverdue): string {
    if ($daysOverdue <= 0) return 'success';
    if ($daysOverdue <= 30) return 'warning';
    if ($daysOverdue <= 90) return 'orange';
    return 'danger';
}

/**
 * Format an amount using the company currency settings.
 */
function formatStatementCurrency(PDO $pdo, float $price): string {
    return formatPrice($price);
}
