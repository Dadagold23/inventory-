<?php
/**
 * Module 5: Sales Functions
 * Handles customers, quotes, sales invoices and point of sale.
 *
 * Canonical tables:
 *   customers, sales_invoices, sales_invoice_items, customer_payments,
 *   quotes, quote_lines, product_locations, inventory_transactions.
 */

// Prevent direct access
defined('SALES_MODULE') || define('SALES_MODULE', 'sales');

// Require foundation
defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/payment_methods_functions.php';
require_once APP_ROOT . '/includes/inventory_functions.php';

// ============================================================
// Internal helpers
// ============================================================

if (!function_exists('salesCompanyId')) {
    function salesCompanyId(): int {
        if (function_exists('auth_company_id')) {
            $companyId = auth_company_id();
            if ($companyId > 0) {
                return $companyId;
            }
        }
        return 1;
    }
}

if (!function_exists('salesSetting')) {
    function salesSetting(PDO $pdo, string $key, string $default = ''): string {
        try {
            $companyId = function_exists('auth_company_id') ? max(1, auth_company_id()) : 1;
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = ? AND company_id = ? LIMIT 1");
            $stmt->execute([$key, $companyId]);
            $value = $stmt->fetchColumn();
            if ($value === false && $companyId !== 1) {
                $fallback = $pdo->prepare("SELECT value FROM settings WHERE `key` = ? AND company_id = 1 LIMIT 1");
                $fallback->execute([$key]);
                $value = $fallback->fetchColumn();
            }
            return $value !== false ? (string) $value : $default;
        } catch (PDOException $e) {
            return $default;
        }
    }
}

if (!function_exists('salesUserId')) {
    function salesUserId(): int {
        return (int) ($_SESSION['user_id'] ?? 1);
    }
}

if (!function_exists('salesDefaultBranchId')) {
    function salesDefaultBranchId(PDO $pdo, int $companyId): ?int {
        $branchId = function_exists('auth_branch_id') ? auth_branch_id() : null;
        if ($branchId) {
            return $branchId;
        }

        $stmt = $pdo->prepare(
            "SELECT branch_id FROM branches WHERE company_id = ? AND is_active = 1
             ORDER BY sort_order, branch_id LIMIT 1"
        );
        $stmt->execute([$companyId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }
}

if (!function_exists('salesNextNumber')) {
    function salesNextNumber(PDO $pdo, string $table, string $column, string $prefix): string {
        do {
            $number = $prefix . '-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
            $stmt->execute([$number]);
        } while ((int) $stmt->fetchColumn() > 0);

        return $number;
    }
}

if (!function_exists('salesRound')) {
    function salesRound(float $value): float {
        return round($value, 4);
    }
}

if (!function_exists('salesRevenueStatusSql')) {
    /**
     * The one rule for what counts as a sale: a draft is a sale nobody has made
     * yet and a cancelled one has been taken back, so neither belongs in a
     * revenue figure. Every screen that adds sales up has to agree on that,
     * which is why the rule lives here rather than in each query.
     */
    function salesRevenueStatusSql(string $column = 'status'): string {
        return $column . " IN ('pending', 'partial', 'paid')";
    }
}

if (!function_exists('salesPaymentMethodOptions')) {
    /**
     * Active payment methods (code => name) for validation and dropdowns.
     * Falls back to the legacy hardcoded list when the payment_methods table
     * is missing or has no active rows, so sales never lose their options.
     */
    function salesPaymentMethodOptions(PDO $pdo, ?int $companyId = null): array {
        $companyId = $companyId ?? salesCompanyId();
        $options = getActivePaymentMethodNames($pdo, $companyId);
        if ($options === []) {
            $options = [
                'cash' => 'Cash',
                'credit_card' => 'Credit Card',
                'bank_transfer' => 'Bank Transfer',
                'check' => 'Check',
            ];
        }
        return $options;
    }
}

// ============================================================
// Customer Functions
// ============================================================

/**
 * Get all customers for a company with payment and balance totals.
 */
function getCustomersByCompany(PDO $pdo, int $companyId = 1): array {
    $stmt = $pdo->prepare("
        SELECT c.*,
               (SELECT COALESCE(SUM(cp.amount), 0)
                  FROM customer_payments cp WHERE cp.customer_id = c.customer_id) AS total_paid,
               (SELECT COALESCE(SUM(si.total_amount - si.amount_paid), 0)
                  FROM sales_invoices si
                 WHERE si.customer_id = c.customer_id
                   AND si.status IN ('pending','partial')) AS outstanding
        FROM customers c
        WHERE c.company_id = ?
        ORDER BY c.name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a specific customer.
 */
function getCustomerById(PDO $pdo, int $customerId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Create a customer.
 */
function createCustomer(PDO $pdo, array $customerData): int {
    $stmt = $pdo->prepare("
        INSERT INTO customers (company_id, name, email, phone, tax_id, address,
               city, state, postal_code, country, credit_limit, credit_used,
               payment_terms, is_active, is_tax_exempt, notes, payment_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $customerData['company_id'] ?? salesCompanyId(),
        $customerData['name'] ?? '',
        $customerData['email'] ?? '',
        $customerData['phone'] ?? '',
        $customerData['tax_id'] ?? '',
        $customerData['address'] ?? '',
        $customerData['city'] ?? '',
        $customerData['state'] ?? '',
        $customerData['postal_code'] ?? '',
        $customerData['country'] ?? '',
        (float) ($customerData['credit_limit'] ?? 0),
        (float) ($customerData['credit_used'] ?? 0),
        $customerData['payment_terms'] ?? 'Net 30',
        !empty($customerData['is_active']) ? 1 : 0,
        !empty($customerData['is_tax_exempt']) ? 1 : 0,
        $customerData['notes'] ?? '',
        $customerData['payment_status'] ?? 'current',
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Update a customer.
 */
function updateCustomer(PDO $pdo, int $customerId, array $customerData): bool {
    $current = getCustomerById($pdo, $customerId);
    if (!$current) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE customers SET name = ?, email = ?, phone = ?, tax_id = ?,
            address = ?, city = ?, state = ?, postal_code = ?, country = ?,
            credit_limit = ?, credit_used = ?, payment_terms = ?, is_active = ?,
            is_tax_exempt = ?, notes = ?, updated_at = NOW()
        WHERE customer_id = ? AND company_id = ?
    ");

    return $stmt->execute([
        $customerData['name'] ?? $current['name'],
        $customerData['email'] ?? $current['email'],
        $customerData['phone'] ?? $current['phone'],
        $customerData['tax_id'] ?? $current['tax_id'],
        $customerData['address'] ?? $current['address'],
        $customerData['city'] ?? $current['city'],
        $customerData['state'] ?? $current['state'],
        $customerData['postal_code'] ?? $current['postal_code'],
        $customerData['country'] ?? $current['country'],
        $customerData['credit_limit'] ?? $current['credit_limit'],
        $customerData['credit_used'] ?? $current['credit_used'],
        $customerData['payment_terms'] ?? $current['payment_terms'],
        array_key_exists('is_active', $customerData) ? (!empty($customerData['is_active']) ? 1 : 0) : (int) $current['is_active'],
        $customerData['is_tax_exempt'] ?? $current['is_tax_exempt'],
        $customerData['notes'] ?? $current['notes'],
        $customerId,
        $current['company_id'],
    ]);
}

/**
 * Recalculate a customer's credit usage and payment status from open invoices.
 */
function salesRecalcCustomerCredit(PDO $pdo, int $customerId): void {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount - amount_paid), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE()
                                  THEN total_amount - amount_paid ELSE 0 END), 0) AS overdue
         FROM sales_invoices
         WHERE customer_id = ? AND status IN ('pending','partial')"
    );
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['outstanding' => 0, 'overdue' => 0];

    $status = 'current';
    if ((float) $row['overdue'] > 0) {
        $status = 'overdue';
    } elseif ((float) $row['outstanding'] > 0) {
        $status = 'open';
    }

    $pdo->prepare(
        "UPDATE customers SET credit_used = ?, payment_status = ?, updated_at = NOW() WHERE customer_id = ?"
    )->execute([$row['outstanding'], $status, $customerId]);
}

/**
 * Get customer outstanding balance summary.
 */
function getCustomerBalance(PDO $pdo, int $customerId): array {
    $stmt = $pdo->prepare("
        SELECT
            (SELECT COALESCE(SUM(total_amount), 0) FROM quotes
              WHERE customer_id = ? AND status IN ('sent','accepted')) AS quote_total,
            (SELECT COALESCE(SUM(total_amount), 0) FROM sales_invoices
              WHERE customer_id = ? AND status IN ('pending','partial')) AS sale_total,
            (SELECT COALESCE(SUM(amount), 0) FROM customer_payments
              WHERE customer_id = ?) AS payments_received,
            (SELECT COALESCE(SUM(total_amount - amount_paid), 0) FROM sales_invoices
              WHERE customer_id = ? AND status IN ('pending','partial')) AS amount_owed
    ");
    $stmt->execute([$customerId, $customerId, $customerId, $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $row['net_balance'] = (float) ($row['amount_owed'] ?? 0);
    return $row;
}

// ============================================================
// Quote Functions
// ============================================================

/**
 * Get quotes for a company.
 */
function getQuotesByCompany(PDO $pdo, int $companyId = 1, ?string $status = null): array {
    $cond = $status !== null ? "AND q.status = ?" : "";
    $params = $status !== null ? [$companyId, $status] : [$companyId];

    $stmt = $pdo->prepare("
        SELECT q.*, c.name AS customer_name, c.email AS customer_email,
               b.name AS branch_name, u.full_name AS created_by_name
        FROM quotes q
        LEFT JOIN customers c ON q.customer_id = c.customer_id
        LEFT JOIN users u ON q.created_by = u.user_id
        LEFT JOIN branches b ON q.branch_id = b.branch_id
        WHERE q.company_id = ? $cond
        ORDER BY q.issue_date DESC, q.quote_id DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a specific quote.
 */
function getQuoteById(PDO $pdo, int $quoteId): ?array {
    $stmt = $pdo->prepare("
        SELECT q.*, c.name AS customer_name, c.email AS customer_email,
               b.name AS branch_name
        FROM quotes q
        LEFT JOIN customers c ON q.customer_id = c.customer_id
        LEFT JOIN branches b ON q.branch_id = b.branch_id
        WHERE q.quote_id = ?
    ");
    $stmt->execute([$quoteId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Get quote lines.
 */
function getQuoteLines(PDO $pdo, int $quoteId): array {
    $stmt = $pdo->prepare("
        SELECT ql.*, p.name AS product_name, p.sku
        FROM quote_lines ql
        LEFT JOIN products p ON ql.product_id = p.product_id
        WHERE ql.quote_id = ?
        ORDER BY ql.line_id
    ");
    $stmt->execute([$quoteId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Normalise posted line arrays into usable line data.
 */
function salesNormaliseLines(PDO $pdo, array $lines): array {
    $normalised = [];

    foreach ($lines as $line) {
        $productId = (int) ($line['product_id'] ?? 0);
        $quantity = (float) ($line['quantity'] ?? 0);
        if ($quantity <= 0) {
            continue;
        }

        $product = $productId > 0 ? getProductRecord($pdo, $productId) : null;
        $unitPrice = array_key_exists('unit_price', $line) && $line['unit_price'] !== ''
            ? (float) $line['unit_price']
            : (float) ($product['sales_price'] ?? 0);
        $description = trim((string) ($line['description'] ?? ''))
            ?: (string) ($product['name'] ?? 'Item');
        $discountPercent = max(0, min(100, (float) ($line['discount_percent'] ?? 0)));

        $gross = salesRound($quantity * $unitPrice);
        $discountAmount = salesRound($gross * $discountPercent / 100);
        $lineTotal = salesRound($gross - $discountAmount);

        $normalised[] = [
            'product_id' => $productId > 0 ? $productId : null,
            'description' => $description,
            'quantity' => salesRound($quantity),
            'unit_price' => salesRound($unitPrice),
            'unit_cost' => salesRound((float) ($product['cost_price'] ?? 0)),
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'line_total' => $lineTotal,
        ];
    }

    return $normalised;
}

if (!function_exists('getProductRecord')) {
    /**
     * Minimal product lookup so sales functions do not hard-depend on inventory_functions.
     */
    function getProductRecord(PDO $pdo, int $productId): ?array {
        if (function_exists('getProductById')) {
            return getProductById($pdo, $productId);
        }
        $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
        $stmt->execute([$productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

/**
 * Create a quote with optional lines.
 */
function createQuote(PDO $pdo, array $quoteData): int {
    $companyId = (int) ($quoteData['company_id'] ?? salesCompanyId());
    $lines = salesNormaliseLines($pdo, $quoteData['lines'] ?? []);

    $subtotal = 0.0;
    foreach ($lines as $line) {
        $subtotal += $line['line_total'];
    }

    $discountPercent = max(0, min(100, (float) ($quoteData['discount_percent'] ?? 0)));
    $discountAmount = $lines !== []
        ? salesRound($subtotal * $discountPercent / 100)
        : (float) ($quoteData['discount_amount'] ?? 0);
    $taxRate = (float) ($quoteData['tax_rate'] ?? 0);
    $taxable = $subtotal - $discountAmount;
    $taxAmount = array_key_exists('tax_amount', $quoteData) && $quoteData['tax_amount'] !== ''
        ? (float) $quoteData['tax_amount']
        : salesRound($taxable * $taxRate);
    $totalAmount = salesRound($taxable + $taxAmount);
    $quoteNumber = trim((string) ($quoteData['quote_number'] ?? ''))
        ?: salesNextNumber($pdo, 'quotes', 'quote_number', 'QT');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO quotes (company_id, customer_id, branch_id, quote_number, status,
                issue_date, expiry_date, subtotal, discount_percent, discount_amount,
                tax_rate, tax_amount, total_amount, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId,
            (int) ($quoteData['customer_id'] ?? 0),
            !empty($quoteData['branch_id']) ? (int) $quoteData['branch_id'] : salesDefaultBranchId($pdo, $companyId),
            $quoteNumber,
            $quoteData['status'] ?? 'draft',
            $quoteData['issue_date'] ?? date('Y-m-d'),
            $quoteData['expiry_date'] ?? date('Y-m-d', strtotime('+30 days')),
            salesRound($subtotal),
            $discountPercent,
            salesRound($discountAmount),
            $taxRate,
            salesRound($taxAmount),
            $totalAmount,
            $quoteData['notes'] ?? '',
            (int) ($quoteData['created_by'] ?? salesUserId()),
        ]);

        $quoteId = (int) $pdo->lastInsertId();
        salesInsertQuoteLines($pdo, $quoteId, $lines);

        $pdo->commit();
        return $quoteId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_log('error', 'Quote creation failed: ' . $e->getMessage());
        return 0;
    }
}

if (!function_exists('salesInsertQuoteLines')) {
    function salesInsertQuoteLines(PDO $pdo, int $quoteId, array $lines): void {
        $stmt = $pdo->prepare("
            INSERT INTO quote_lines (quote_id, product_id, description, quantity, unit_price,
                discount_percent, discount_amount, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($lines as $line) {
            $stmt->execute([
                $quoteId,
                $line['product_id'],
                $line['description'],
                $line['quantity'],
                $line['unit_price'],
                $line['discount_percent'],
                $line['discount_amount'],
                $line['line_total'],
            ]);
        }
    }
}

/**
 * Update a quote header and, when supplied, replace its lines.
 */
function updateQuote(PDO $pdo, int $quoteId, array $quoteData): bool {
    $quote = getQuoteById($pdo, $quoteId);
    if (!$quote) {
        return false;
    }

    $lines = array_key_exists('lines', $quoteData)
        ? salesNormaliseLines($pdo, $quoteData['lines'])
        : null;

    $pdo->beginTransaction();
    try {
        if ($lines !== null && $lines !== []) {
            $subtotal = 0.0;
            foreach ($lines as $line) {
                $subtotal += $line['line_total'];
            }
            $discountPercent = max(0, min(100, (float) ($quoteData['discount_percent'] ?? $quote['discount_percent'])));
            $discountAmount = salesRound($subtotal * $discountPercent / 100);
            $taxRate = (float) ($quoteData['tax_rate'] ?? $quote['tax_rate']);
            $taxAmount = salesRound(($subtotal - $discountAmount) * $taxRate);
            $totalAmount = salesRound($subtotal - $discountAmount + $taxAmount);

            $pdo->prepare("DELETE FROM quote_lines WHERE quote_id = ?")->execute([$quoteId]);
            salesInsertQuoteLines($pdo, $quoteId, $lines);
        } else {
            $subtotal = (float) ($quoteData['subtotal'] ?? $quote['subtotal']);
            $discountPercent = (float) ($quoteData['discount_percent'] ?? $quote['discount_percent']);
            $discountAmount = (float) ($quoteData['discount_amount'] ?? $quote['discount_amount']);
            $taxRate = (float) ($quoteData['tax_rate'] ?? $quote['tax_rate']);
            $taxAmount = (float) ($quoteData['tax_amount'] ?? $quote['tax_amount']);
            $totalAmount = (float) ($quoteData['total_amount'] ?? $quote['total_amount']);
        }

        $stmt = $pdo->prepare("
            UPDATE quotes SET customer_id = ?, branch_id = ?, status = ?,
                issue_date = ?, expiry_date = ?, subtotal = ?, discount_percent = ?,
                discount_amount = ?, tax_rate = ?, tax_amount = ?, total_amount = ?,
                notes = ?, updated_at = NOW()
            WHERE quote_id = ?
        ");
        $stmt->execute([
            (int) ($quoteData['customer_id'] ?? $quote['customer_id']),
            !empty($quoteData['branch_id']) ? (int) $quoteData['branch_id'] : $quote['branch_id'],
            $quoteData['status'] ?? $quote['status'],
            $quoteData['issue_date'] ?? $quote['issue_date'],
            $quoteData['expiry_date'] ?? $quote['expiry_date'],
            salesRound($subtotal),
            $discountPercent,
            salesRound($discountAmount),
            $taxRate,
            salesRound($taxAmount),
            salesRound($totalAmount),
            $quoteData['notes'] ?? $quote['notes'],
            $quoteId,
        ]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_log('error', 'Quote update failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Convert an accepted/sent quote into a pending sales invoice.
 */
function convertQuoteToSale(PDO $pdo, int $quoteId): bool {
    $quote = getQuoteById($pdo, $quoteId);
    if (!$quote || in_array($quote['status'], ['converted', 'rejected', 'expired'], true)) {
        return false;
    }

    $lines = getQuoteLines($pdo, $quoteId);
    if ($lines === []) {
        return false;
    }

    $result = createSalesInvoice($pdo, [
        'company_id' => (int) $quote['company_id'],
        'customer_id' => (int) $quote['customer_id'],
        'branch_id' => (int) ($quote['branch_id'] ?? 0) ?: null,
        'issue_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+30 days')),
        'tax_rate' => (float) $quote['tax_rate'],
        'discount_percent' => (float) $quote['discount_percent'],
        'notes' => (string) ($quote['notes'] ?? '') . "\nConverted from quote " . $quote['quote_number'],
        'status' => 'pending',
        'lines' => $lines,
    ]);

    if (!$result['success']) {
        return false;
    }

    $pdo->prepare(
        "UPDATE quotes SET status = 'converted', converted_invoice_id = ?, updated_at = NOW() WHERE quote_id = ?"
    )->execute([$result['invoice_id'], $quoteId]);

    return true;
}

// ============================================================
// Sales Invoice Functions
// ============================================================

/**
 * Get sales invoices for a company.
 */
function getSalesByCompany(PDO $pdo, int $companyId = 1, ?string $status = null): array {
    $cond = $status !== null ? "AND si.status = ?" : "";
    $params = $status !== null ? [$companyId, $status] : [$companyId];

    $stmt = $pdo->prepare("
        SELECT si.*, c.name AS customer_name, c.email AS customer_email,
               b.name AS branch_name, u.full_name AS created_by_name
        FROM sales_invoices si
        LEFT JOIN customers c ON si.customer_id = c.customer_id
        LEFT JOIN branches b ON si.branch_id = b.branch_id
        LEFT JOIN users u ON si.created_by = u.user_id
        WHERE si.company_id = ? $cond
        ORDER BY si.issue_date DESC, si.invoice_id DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a specific sales invoice.
 */
function getSaleById(PDO $pdo, int $saleId): ?array {
    $stmt = $pdo->prepare("
        SELECT si.*, c.name AS customer_name, c.email AS customer_email,
               b.name AS branch_name, u.full_name AS created_by_name
        FROM sales_invoices si
        LEFT JOIN customers c ON si.customer_id = c.customer_id
        LEFT JOIN branches b ON si.branch_id = b.branch_id
        LEFT JOIN users u ON si.created_by = u.user_id
        WHERE si.invoice_id = ?
    ");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        return null;
    }
    $sale['items'] = getSaleItems($pdo, $saleId);
    return $sale;
}

/**
 * Get sales invoice lines.
 */
function getSaleItems(PDO $pdo, int $invoiceId): array {
    $stmt = $pdo->prepare("
        SELECT sii.*, p.name AS product_name, p.sku
        FROM sales_invoice_items sii
        LEFT JOIN products p ON sii.product_id = p.product_id
        WHERE sii.invoice_id = ?
        ORDER BY sii.item_id
    ");
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create a sales invoice with lines, stock movement and optional immediate payment.
 *
 * Returns ['success' => bool, 'invoice_id' => int, 'message' => string, 'total' => float].
 */
function createSalesInvoice(PDO $pdo, array $data): array {
    $companyId = (int) ($data['company_id'] ?? salesCompanyId());
    $customerId = (int) ($data['customer_id'] ?? 0);
    $branchId = (int) ($data['branch_id'] ?? 0) ?: salesDefaultBranchId($pdo, $companyId);
    $lines = salesNormaliseLines($pdo, $data['lines'] ?? []);
    $shiftId = (int) ($data['shift_id'] ?? 0);
    $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));

    // A retried checkout must return the sale it already created, never a
    // second one. Checked before any writing so a duplicate costs nothing.
    if ($idempotencyKey !== '') {
        $replay = salesFindByIdempotencyKey($pdo, $companyId, $idempotencyKey);
        if ($replay !== null) {
            return $replay;
        }
    }

    if ($customerId <= 0) {
        return ['success' => false, 'invoice_id' => 0, 'message' => 'A customer is required.', 'total' => 0];
    }
    if ($lines === []) {
        return ['success' => false, 'invoice_id' => 0, 'message' => 'At least one line item is required.', 'total' => 0];
    }

    $customerStmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id = ? AND company_id = ?");
    $customerStmt->execute([$customerId, $companyId]);
    if (!$customerStmt->fetchColumn()) {
        return ['success' => false, 'invoice_id' => 0, 'message' => 'Customer not found.', 'total' => 0];
    }

    $subtotal = 0.0;
    foreach ($lines as $line) {
        $subtotal += $line['line_total'];
    }
    $discountPercent = max(0, min(100, (float) ($data['discount_percent'] ?? 0)));
    $discountAmount = salesRound($subtotal * $discountPercent / 100);
    $taxRate = array_key_exists('tax_rate', $data) && $data['tax_rate'] !== ''
        ? (float) $data['tax_rate']
        : (float) salesSetting($pdo, 'default_tax_rate', '0');
    $taxable = $subtotal - $discountAmount;
    $taxAmount = salesRound($taxable * $taxRate);
    $totalAmount = salesRound($taxable + $taxAmount);

    $amountPaid = min(max(0.0, (float) ($data['amount_paid'] ?? 0)), $totalAmount);
    $amountOwed = salesRound($totalAmount - $amountPaid);
    $status = $data['status'] ?? ($amountOwed <= 0 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'pending'));
    if (!in_array($status, ['draft', 'pending', 'partial', 'paid', 'cancelled'], true)) {
        $status = 'pending';
    }

    $tendered = 0.0;
    $changeGiven = 0.0;

    // Split payments: when tender lines are supplied they decide how much was
    // collected. Cash handed over above the amount owed is change returned to
    // the customer, not a payment - so the drawer needs no payout row for it
    // (float + receipts already equals the notes left inside).
    $tenderLines = salesNormaliseTenders($data['payments'] ?? []);
    if ($tenderLines !== []) {
        $applied = 0.0;
        foreach ($tenderLines as &$tender) {
            $remaining = salesRound($totalAmount - $applied);
            $tender['applied'] = salesRound(min($tender['amount'], max(0.0, $remaining)));
            $applied = salesRound($applied + $tender['applied']);
            $tendered = salesRound($tendered + $tender['amount']);
        }
        unset($tender);

        $amountPaid = $applied;
        $amountOwed = salesRound($totalAmount - $amountPaid);
        $changeGiven = salesRound(max(0, $tendered - $totalAmount));
        $status = $amountOwed <= 0 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'pending');
    }

    $allowNegative = salesSetting($pdo, 'allow_negative_stock', '0') === '1';
    // A gateway webhook finalises a sale with no signed-in user, so the sale's
    // own initiator is passed in rather than read from the session.
    $userId = (int) ($data['created_by'] ?? 0) ?: salesUserId();

    $pdo->beginTransaction();
    try {
        // Reserve stock first so the invoice is never persisted without its stock movement.
        foreach ($lines as $index => $line) {
            if ($line['product_id'] === null) {
                continue;
            }

            $locStmt = $pdo->prepare(
                "SELECT * FROM product_locations WHERE product_id = ? AND branch_id = ? FOR UPDATE"
            );
            $locStmt->execute([$line['product_id'], $branchId]);
            $location = $locStmt->fetch(PDO::FETCH_ASSOC);

            if (!$location) {
                if (!$allowNegative) {
                    throw new RuntimeException('No stock record exists for "' . $line['description'] . '" at this branch.');
                }
                $pdo->prepare(
                    "INSERT INTO product_locations (company_id, product_id, branch_id, on_hand_quantity, reserved_quantity, available_quantity, on_hand_value)
                     VALUES (?, ?, ?, 0, 0, 0, 0)"
                )->execute([$companyId, $line['product_id'], $branchId]);
                $locStmt->execute([$line['product_id'], $branchId]);
                $location = $locStmt->fetch(PDO::FETCH_ASSOC);
            }

            $onHand = (float) $location['on_hand_quantity'];
            if ($onHand < $line['quantity'] && !$allowNegative) {
                throw new RuntimeException(
                    'Insufficient stock for "' . $line['description'] . '". Available: ' . number_format($onHand, 2) . '.'
                );
            }

            $avgCost = $onHand > 0
                ? (float) $location['on_hand_value'] / $onHand
                : $line['unit_cost'];
            $lines[$index]['unit_cost'] = salesRound($avgCost);
            $lines[$index]['total_cost'] = salesRound($avgCost * $line['quantity']);
        }

        $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''))
            ?: salesNextNumber($pdo, 'sales_invoices', 'invoice_number', 'INV');

        $stmt = $pdo->prepare("
            INSERT INTO sales_invoices (company_id, customer_id, branch_id, shift_id, invoice_number,
                issue_date, due_date, subtotal, discount_percent, discount_amount,
                tax_amount, total_amount, amount_paid, amount_owed, payment_method,
                status, notes, created_by, idempotency_key, paid_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId,
            $customerId,
            $branchId,
            $shiftId > 0 ? $shiftId : null,
            $invoiceNumber,
            $data['issue_date'] ?? date('Y-m-d'),
            $data['due_date'] ?? date('Y-m-d', strtotime('+30 days')),
            salesRound($subtotal),
            $discountPercent,
            $discountAmount,
            $taxAmount,
            $totalAmount,
            $amountPaid,
            $amountOwed,
            $tenderLines !== [] ? salesTenderLabel($tenderLines, $data['payment_method'] ?? null) : ($data['payment_method'] ?? null),
            $status,
            $data['notes'] ?? '',
            $userId,
            $idempotencyKey !== '' ? $idempotencyKey : null,
            $status === 'paid' ? date('Y-m-d') : null,
        ]);

        $invoiceId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare("
            INSERT INTO sales_invoice_items (invoice_id, product_id, description, quantity,
                unit_price, unit_cost, discount_percent, discount_amount, tax_amount, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
        ");
        $stockStmt = $pdo->prepare("
            UPDATE product_locations SET on_hand_quantity = on_hand_quantity - ?,
                on_hand_value = GREATEST(0, on_hand_value - ?),
                available_quantity = on_hand_quantity - reserved_quantity,
                last_counted = NOW(), updated_at = NOW()
            WHERE product_id = ? AND branch_id = ?
        ");
        $txnStmt = $pdo->prepare("
            INSERT INTO inventory_transactions (company_id, product_id, branch_id, transaction_type,
                quantity, unit_cost, total_value, reference_type, reference_id, user_id, notes)
            VALUES (?, ?, ?, 'sale', ?, ?, ?, 'sales_invoice', ?, ?, ?)
        ");

        foreach ($lines as $line) {
            $itemStmt->execute([
                $invoiceId,
                $line['product_id'],
                $line['description'],
                $line['quantity'],
                $line['unit_price'],
                $line['unit_cost'],
                $line['discount_percent'],
                $line['discount_amount'],
                $line['line_total'],
            ]);

            if ($line['product_id'] === null) {
                continue;
            }

            $stockStmt->execute([
                $line['quantity'],
                $line['total_cost'],
                $line['product_id'],
                $branchId,
            ]);
            $txnStmt->execute([
                $companyId,
                $line['product_id'],
                $branchId,
                $line['quantity'],
                $line['unit_cost'],
                $line['total_cost'],
                $invoiceId,
                $userId,
                'Sale ' . $invoiceNumber,
            ]);
        }

        if ($tenderLines !== []) {
            foreach ($tenderLines as $tender) {
                if ($tender['applied'] <= 0) {
                    continue;
                }
                salesInsertPayment($pdo, $companyId, $customerId, $invoiceId, $tender['applied'],
                    $tender['method'], $tender['reference'], $data['notes'] ?? '', $shiftId, (float) $tender['amount']);
            }
        } elseif ($amountPaid > 0) {
            salesInsertPayment($pdo, $companyId, $customerId, $invoiceId, $amountPaid,
                $data['payment_method'] ?? 'cash', $data['reference_number'] ?? '', $data['notes'] ?? '', $shiftId);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();

        // Concurrent retry of the same checkout: the unique idempotency index
        // rejects the second insert, which means the first one succeeded.
        if ($idempotencyKey !== '' && str_contains($e->getMessage(), 'uq_sales_idempotency')) {
            $replay = salesFindByIdempotencyKey($pdo, $companyId, $idempotencyKey);
            if ($replay !== null) {
                return $replay;
            }
        }

        app_log('warning', 'Sales invoice creation failed: ' . $e->getMessage());
        return ['success' => false, 'invoice_id' => 0, 'message' => $e->getMessage(), 'total' => 0];
    }

    salesRecalcCustomerCredit($pdo, $customerId);

    return [
        'success' => true,
        'invoice_id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'message' => 'Sale ' . $invoiceNumber . ' recorded.',
        'total' => $totalAmount,
        'amount_paid' => $amountPaid,
        'amount_owed' => $amountOwed,
        'status' => $status,
        'tendered' => $tendered,
        'change' => $changeGiven,
        'duplicate' => false,
    ];
}

if (!function_exists('salesFindByIdempotencyKey')) {
    /** The sale a previous attempt with this key already produced, if any. */
    function salesFindByIdempotencyKey(PDO $pdo, int $companyId, string $key): ?array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT invoice_id, invoice_number, total_amount, amount_paid, amount_owed, status
                 FROM sales_invoices WHERE company_id = ? AND idempotency_key = ? LIMIT 1"
            );
            $stmt->execute([$companyId, $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            app_log('warning', 'Idempotency lookup failed: ' . $e->getMessage());
            return null;
        }
        if (!$row) {
            return null;
        }

        return [
            'success' => true,
            'invoice_id' => (int) $row['invoice_id'],
            'invoice_number' => (string) $row['invoice_number'],
            'message' => 'Sale ' . $row['invoice_number'] . ' was already recorded for this request.',
            'total' => (float) $row['total_amount'],
            'amount_paid' => (float) $row['amount_paid'],
            'amount_owed' => (float) $row['amount_owed'],
            'status' => (string) $row['status'],
            'tendered' => (float) $row['amount_paid'],
            'change' => 0.0,
            'duplicate' => true,
        ];
    }
}

if (!function_exists('salesNormaliseTenders')) {
    /**
     * Posted tender lines -> [{method, amount, reference, applied}]. Amounts
     * are what the customer handed over; applied is filled in by the caller
     * against the invoice total.
     */
    function salesNormaliseTenders(mixed $payments): array
    {
        if (!is_array($payments)) {
            return [];
        }
        $lines = [];
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $method = trim((string) ($payment['method'] ?? $payment['payment_method'] ?? ''));
            $amount = salesRound((float) ($payment['amount'] ?? 0));
            if ($method === '' || $amount <= 0) {
                continue;
            }
            $lines[] = [
                'method' => $method,
                'amount' => $amount,
                'reference' => trim((string) ($payment['reference'] ?? '')),
                'applied' => 0.0,
            ];
        }
        return $lines;
    }
}

if (!function_exists('salesTenderLabel')) {
    /** How the invoice records the way it was paid (short, fits varchar(30)). */
    function salesTenderLabel(array $tenderLines, ?string $fallback = null): string
    {
        $methods = array_values(array_unique(array_map(static fn ($t) => (string) $t['method'], $tenderLines)));
        if (count($methods) <= 1) {
            return $methods[0] ?? (string) $fallback;
        }
        return 'split';
    }
}

/**
 * Backwards-compatible wrapper returning the new invoice id (0 on failure).
 */
function createSale(PDO $pdo, array $data): int {
    $result = createSalesInvoice($pdo, $data);
    return $result['success'] ? (int) $result['invoice_id'] : 0;
}

if (!function_exists('salesInsertPayment')) {
    function salesInsertPayment(PDO $pdo, int $companyId, int $customerId, int $invoiceId,
        float $amount, string $method, string $reference = '', string $notes = '', int $shiftId = 0,
        ?float $tendered = null): void {
        // DB-backed list of active methods for the company; the legacy hardcoded
        // list remains the fallback when the table is missing or empty, and an
        // unknown/inactive code still falls back to bank_transfer as before.
        $allowedMethods = getActivePaymentMethodCodes($pdo, $companyId);
        if ($allowedMethods === []) {
            $allowedMethods = ['cash', 'check', 'bank_transfer', 'credit_card'];
        }
        $method = in_array($method, $allowedMethods, true) ? $method : 'bank_transfer';

        // amount is what the bill absorbed; tendered is what came across the
        // counter. Keeping them apart is what lets a reprint show the change
        // that was handed back, and what keeps the drawer formula honest.
        $tenderedOut = null;
        if ($tendered !== null && $tendered > 0 && $tendered + 0.005 >= $amount) {
            $tenderedOut = salesRound($tendered);
        }

        $stmt = $pdo->prepare("
            INSERT INTO customer_payments (company_id, customer_id, invoice_id, shift_id, amount,
                tendered_amount, payment_date, payment_method, reference_number, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId,
            $customerId,
            $invoiceId,
            $shiftId > 0 ? $shiftId : null,
            salesRound($amount),
            $tenderedOut,
            date('Y-m-d'),
            $method,
            $reference,
            $notes,
        ]);
    }
}

/**
 * Recompute invoice paid/owed/status from its payment rows.
 */
function refreshInvoicePaymentStatus(PDO $pdo, int $invoiceId): bool {
    $stmt = $pdo->prepare("SELECT total_amount FROM sales_invoices WHERE invoice_id = ?");
    $stmt->execute([$invoiceId]);
    $total = $stmt->fetchColumn();
    if ($total === false) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM customer_payments WHERE invoice_id = ?");
    $stmt->execute([$invoiceId]);
    $paid = salesRound((float) $stmt->fetchColumn());
    $owed = salesRound(max(0, (float) $total - $paid));

    if ($owed <= 0) {
        $status = 'paid';
    } elseif ($paid > 0) {
        $status = 'partial';
    } else {
        $status = 'pending';
    }

    $stmt = $pdo->prepare("
        UPDATE sales_invoices SET amount_paid = ?, amount_owed = ?, status = ?,
            paid_date = ?, updated_at = NOW()
        WHERE invoice_id = ?
    ");
    return $stmt->execute([
        $paid,
        $owed,
        $status,
        $status === 'paid' ? date('Y-m-d') : null,
        $invoiceId,
    ]);
}

/**
 * Record a customer payment against an invoice.
 */
function recordPayment(PDO $pdo, int $customerId, ?int $saleId, float $amount, string $paymentMethod,
    string $referenceNumber = '', string $notes = ''): bool {
    $amount = salesRound($amount);
    if ($amount <= 0 || !$saleId) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT invoice_id, company_id, customer_id, total_amount, amount_paid, status
        FROM sales_invoices WHERE invoice_id = ? FOR UPDATE
    ");

    $pdo->beginTransaction();
    try {
        $stmt->execute([$saleId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice || (int) $invoice['customer_id'] !== $customerId || $invoice['status'] === 'cancelled') {
            $pdo->rollBack();
            return false;
        }

        $remaining = salesRound((float) $invoice['total_amount'] - (float) $invoice['amount_paid']);
        if ($amount > $remaining + 0.0001) {
            $amount = $remaining;
        }
        if ($amount <= 0) {
            $pdo->rollBack();
            return false;
        }

        salesInsertPayment($pdo, (int) $invoice['company_id'], $customerId, $saleId,
            $amount, $paymentMethod, $referenceNumber, $notes);
        refreshInvoicePaymentStatus($pdo, $saleId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_log('error', 'Payment recording failed: ' . $e->getMessage());
        return false;
    }

    salesRecalcCustomerCredit($pdo, $customerId);
    return true;
}

/**
 * Cancel a sale, restore stock and prevent double processing.
 */
function updateSaleStatus(PDO $pdo, int $saleId, string $status): bool {
    if ($status !== 'cancelled') {
        $stmt = $pdo->prepare(
            "UPDATE sales_invoices SET status = ?, updated_at = NOW() WHERE invoice_id = ?"
        );
        return $stmt->execute([$status, $saleId]);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM sales_invoices WHERE invoice_id = ? FOR UPDATE");
        $stmt->execute([$saleId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice || $invoice['status'] === 'cancelled') {
            $pdo->rollBack();
            return false;
        }

        $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM customer_payments WHERE invoice_id = ?");
        $paidStmt->execute([$saleId]);
        if ((float) $paidStmt->fetchColumn() > 0.0001) {
            $pdo->rollBack();
            return false;
        }

        $items = getSaleItems($pdo, $saleId);
        $restockStmt = $pdo->prepare("
            UPDATE product_locations SET on_hand_quantity = on_hand_quantity + ?,
                on_hand_value = on_hand_value + ?,
                available_quantity = on_hand_quantity - reserved_quantity,
                last_counted = NOW(), updated_at = NOW()
            WHERE product_id = ? AND branch_id = ?
        ");
        $txnStmt = $pdo->prepare("
            INSERT INTO inventory_transactions (company_id, product_id, branch_id, transaction_type,
                quantity, unit_cost, total_value, reference_type, reference_id, user_id, notes)
            VALUES (?, ?, ?, 'adjustment', ?, ?, ?, 'sale_cancellation', ?, ?, ?)
        ");

        foreach ($items as $item) {
            if (empty($item['product_id']) || !$invoice['branch_id']) {
                continue;
            }
            $cost = (float) $item['unit_cost'];
            $value = salesRound($cost * (float) $item['quantity']);
            $restockStmt->execute([
                $item['quantity'], $value, $item['product_id'], $invoice['branch_id'],
            ]);
            $txnStmt->execute([
                $invoice['company_id'],
                $item['product_id'],
                $invoice['branch_id'],
                $item['quantity'],
                $cost,
                $value,
                $saleId,
                salesUserId(),
                'Cancellation of ' . $invoice['invoice_number'],
            ]);
        }

        $pdo->prepare(
            "UPDATE sales_invoices SET status = 'cancelled', amount_owed = 0, updated_at = NOW()
             WHERE invoice_id = ?"
        )->execute([$saleId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_log('error', 'Sale cancellation failed: ' . $e->getMessage());
        return false;
    }

    salesRecalcCustomerCredit($pdo, (int) $invoice['customer_id']);
    return true;
}

// ============================================================
// POS (Point of Sale) Functions
// ============================================================

/**
 * Get POS session summary.
 */
function getPosSession(PDO $pdo): array {
    return [
        'items' => $_SESSION['pos_cart'] ?? [],
        'subtotal' => (float) ($_SESSION['pos_subtotal'] ?? 0),
        'discount' => (float) ($_SESSION['pos_discount'] ?? 0),
        'tax_rate' => (float) ($_SESSION['pos_tax_rate'] ?? (float) salesSetting($pdo, 'default_tax_rate', '0')),
        'tax' => (float) ($_SESSION['pos_tax'] ?? 0),
        'total' => (float) ($_SESSION['pos_total'] ?? 0),
    ];
}

/**
 * Add a product to the POS cart.
 */
function posAddItem(PDO $pdo, int $productId, float $quantity, ?int $branchId = null): bool {
    return posAddLine($pdo, $productId, $quantity, $branchId)['success'];
}

/**
 * Add a cart line with a reason when it fails, for the JSON POS endpoints.
 *
 * @return array{success:bool,message:string,cart:array}
 */
function posAddLine(PDO $pdo, int $productId, float $quantity, ?int $branchId = null, float $discountPercent = 0): array
{
    if ($quantity <= 0) {
        return ['success' => false, 'message' => 'Enter a quantity greater than zero.', 'cart' => getPosSession($pdo)];
    }

    $product = getProductRecord($pdo, $productId);
    if (!$product) {
        return ['success' => false, 'message' => 'Product not found.', 'cart' => getPosSession($pdo)];
    }

    // The cart can only ever hold products from the signed-in company.
    $companyId = salesCompanyId();
    if ((int) ($product['company_id'] ?? $companyId) !== $companyId) {
        return ['success' => false, 'message' => 'That product does not belong to your company.', 'cart' => getPosSession($pdo)];
    }
    if ((int) ($product['is_active'] ?? 1) !== 1) {
        return ['success' => false, 'message' => 'That product is inactive.', 'cart' => getPosSession($pdo)];
    }

    $branchId = $branchId ?: salesDefaultBranchId($pdo, $companyId);
    $onHand = 0.0;
    if ($branchId) {
        $stock = getStockLevels($pdo, $productId, $branchId);
        $onHand = (float) ($stock[0]['on_hand_quantity'] ?? 0);
    }

    $cart = $_SESSION['pos_cart'] ?? [];
    $wanted = $quantity;
    foreach ($cart as $item) {
        if ((int) $item['product_id'] === $productId) {
            $wanted += (float) $item['quantity'];
        }
    }

    if ($onHand < $wanted && salesSetting($pdo, 'allow_negative_stock', '0') !== '1') {
        return [
            'success' => false,
            'message' => 'Not enough stock on hand. Available: ' . number_format($onHand, 2)
                . ', cart wants: ' . number_format($wanted, 2) . '.',
            'cart' => getPosSession($pdo),
        ];
    }

    // Price and cost always come from the product record, never the request.
    $found = false;
    foreach ($cart as &$item) {
        if ((int) $item['product_id'] === $productId) {
            $item['quantity'] = salesRound((float) $item['quantity'] + $quantity);
            $item['discount_percent'] = max(0, min(100, $discountPercent > 0 ? $discountPercent : (float) ($item['discount_percent'] ?? 0)));
            $found = true;
            break;
        }
    }
    unset($item);

    if (!$found) {
        $cart[] = [
            'product_id' => $productId,
            'product_name' => $product['name'],
            'sku' => $product['sku'] ?? '',
            'barcode' => $product['barcode'] ?? '',
            'quantity' => salesRound($quantity),
            'unit_price' => salesRound((float) ($product['sales_price'] ?? 0)),
            'unit_cost' => salesRound((float) ($product['cost_price'] ?? 0)),
            'discount_percent' => max(0, min(100, $discountPercent)),
        ];
    }
    $_SESSION['pos_cart'] = $cart;

    recalculatePosTotals($pdo);
    return ['success' => true, 'message' => 'Item added to cart.', 'cart' => getPosSession($pdo)];
}

if (!function_exists('posSetLineQuantity')) {
    /**
     * Set an existing line's quantity (the POS stepper). Validates stock the
     * same way an add does, so a typed quantity cannot bypass availability.
     */
    function posSetLineQuantity(PDO $pdo, int $index, float $quantity, ?int $branchId = null): array
    {
        $cart = $_SESSION['pos_cart'] ?? [];
        if (!isset($cart[$index])) {
            return ['success' => false, 'message' => 'That cart line no longer exists.', 'cart' => getPosSession($pdo)];
        }
        if ($quantity <= 0) {
            posRemoveItem($pdo, $index);
            return ['success' => true, 'message' => 'Line removed.', 'cart' => getPosSession($pdo)];
        }

        $productId = (int) $cart[$index]['product_id'];
        $companyId = salesCompanyId();
        $branchId = $branchId ?: salesDefaultBranchId($pdo, $companyId);
        $onHand = 0.0;
        if ($branchId) {
            $stock = getStockLevels($pdo, $productId, $branchId);
            $onHand = (float) ($stock[0]['on_hand_quantity'] ?? 0);
        }
        if ($onHand < $quantity && salesSetting($pdo, 'allow_negative_stock', '0') !== '1') {
            return ['success' => false, 'message' => 'Only ' . number_format($onHand, 2) . ' on hand.', 'cart' => getPosSession($pdo)];
        }

        $cart[$index]['quantity'] = salesRound($quantity);
        $_SESSION['pos_cart'] = $cart;
        recalculatePosTotals($pdo);
        return ['success' => true, 'message' => 'Quantity updated.', 'cart' => getPosSession($pdo)];
    }
}

if (!function_exists('posSetLineDiscount')) {
    /** Line discount percent, clamped 0-100 like the invoice lines. */
    function posSetLineDiscount(PDO $pdo, int $index, float $discountPercent): array
    {
        $cart = $_SESSION['pos_cart'] ?? [];
        if (!isset($cart[$index])) {
            return ['success' => false, 'message' => 'That cart line no longer exists.', 'cart' => getPosSession($pdo)];
        }
        $cart[$index]['discount_percent'] = max(0.0, min(100.0, $discountPercent));
        $_SESSION['pos_cart'] = $cart;
        recalculatePosTotals($pdo);
        return ['success' => true, 'message' => 'Discount updated.', 'cart' => getPosSession($pdo)];
    }
}

if (!function_exists('posSearchProducts')) {
    /**
     * Product lookup for the POS grid/search box: name, SKU or barcode, with
     * the branch stock figure so the cashier sees availability immediately.
     */
    function posSearchProducts(PDO $pdo, string $term, int $companyId, ?int $branchId = null, int $limit = 25): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $branchId = $branchId ?: salesDefaultBranchId($pdo, $companyId);
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

        $stmt = $pdo->prepare("
            SELECT p.product_id, p.name, p.sku, p.barcode, p.sales_price, p.cost_price,
                   p.category_id, c.name AS category_name,
                   COALESCE(pl.on_hand_quantity, 0) AS on_hand
            FROM products p
            LEFT JOIN categories c ON c.category_id = p.category_id
            LEFT JOIN product_locations pl ON pl.product_id = p.product_id AND pl.branch_id = ?
            WHERE p.company_id = ? AND p.is_active = 1
              AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)
            ORDER BY (p.name LIKE ?) DESC, p.name
            LIMIT " . $limit
        );
        $stmt->execute([$branchId, $companyId, $like, $like, $like, $like . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('posCartPayload')) {
    /**
     * The cart as the JSON endpoints return it. Totals come from
     * recalculatePosTotals(), which re-reads prices from the database.
     */
    function posCartPayload(PDO $pdo): array
    {
        $session = getPosSession($pdo);
        $lines = [];
        foreach ($session['items'] as $index => $item) {
            $gross = (float) $item['unit_price'] * (float) $item['quantity'];
            $discountAmount = $gross * ((float) ($item['discount_percent'] ?? 0) / 100);
            $lines[] = [
                'index' => (int) $index,
                'product_id' => (int) $item['product_id'],
                'name' => (string) $item['product_name'],
                'sku' => (string) ($item['sku'] ?? ''),
                'barcode' => (string) ($item['barcode'] ?? ''),
                'quantity' => (float) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'discount_percent' => (float) ($item['discount_percent'] ?? 0),
                'line_total' => round($gross - $discountAmount, 2),
            ];
        }

        return [
            'lines' => $lines,
            'count' => count($lines),
            'subtotal' => $session['subtotal'],
            'tax_rate' => $session['tax_rate'],
            'tax' => $session['tax'],
            'total' => $session['total'],
            'item_quantity' => array_sum(array_map(static fn ($l) => (float) $l['quantity'], $lines)),
        ];
    }
}

/**
 * Recalculate POS totals from the cart.
 */
function recalculatePosTotals(PDO $pdo): void {
    $cart = $_SESSION['pos_cart'] ?? [];

    // Re-price every line from the product table. The session is server-side,
    // but a cart that outlives a price change would otherwise sell at the old
    // figure, and a hand-edited session value would be trusted.
    if ($cart !== []) {
        $ids = array_values(array_unique(array_map(static fn ($i) => (int) $i['product_id'], $cart)));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT product_id, name, sku, barcode, sales_price, cost_price FROM products WHERE product_id IN ($in)");
        $stmt->execute($ids);
        $fresh = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fresh[(int) $row['product_id']] = $row;
        }
        foreach ($cart as &$item) {
            $row = $fresh[(int) $item['product_id']] ?? null;
            if ($row) {
                $item['unit_price'] = salesRound((float) $row['sales_price']);
                $item['unit_cost'] = salesRound((float) $row['cost_price']);
                $item['product_name'] = (string) $row['name'];
                $item['sku'] = (string) ($row['sku'] ?? '');
                $item['barcode'] = (string) ($row['barcode'] ?? '');
            }
        }
        unset($item);
        $_SESSION['pos_cart'] = $cart;
    }

    $subtotal = 0.0;

    foreach ($cart as $item) {
        $line = (float) $item['unit_price'] * (float) $item['quantity'];
        $line -= $line * ((float) ($item['discount_percent'] ?? 0) / 100);
        $subtotal += $line;
    }

    $taxRate = (float) salesSetting($pdo, 'default_tax_rate', '0');
    $subtotal = salesRound($subtotal);
    $taxAmount = salesRound($subtotal * $taxRate);

    $_SESSION['pos_subtotal'] = $subtotal;
    $_SESSION['pos_discount'] = 0.0;
    $_SESSION['pos_tax_rate'] = $taxRate;
    $_SESSION['pos_tax'] = $taxAmount;
    $_SESSION['pos_total'] = salesRound($subtotal + $taxAmount);
}

/**
 * Remove a cart line by index.
 */
function posRemoveItem(PDO $pdo, int $index): bool {
    $cart = $_SESSION['pos_cart'] ?? [];
    if (!isset($cart[$index])) {
        return false;
    }
    unset($cart[$index]);
    $_SESSION['pos_cart'] = array_values($cart);
    recalculatePosTotals($pdo);
    return true;
}

/**
 * Clear the POS cart.
 */
function posClearCart(): void {
    $_SESSION['pos_cart'] = [];
    $_SESSION['pos_subtotal'] = 0.0;
    $_SESSION['pos_discount'] = 0.0;
    $_SESSION['pos_tax_rate'] = 0.0;
    $_SESSION['pos_tax'] = 0.0;
    $_SESSION['pos_total'] = 0.0;
}

/**
 * Find or create the walk-in customer for POS sales.
 */
function getOrCreateWalkInCustomer(PDO $pdo, int $companyId): int {
    $stmt = $pdo->prepare(
        "SELECT customer_id FROM customers WHERE company_id = ? AND name = 'Walk-in Customer' LIMIT 1"
    );
    $stmt->execute([$companyId]);
    $customerId = $stmt->fetchColumn();
    if ($customerId !== false) {
        return (int) $customerId;
    }

    return createCustomer($pdo, [
        'company_id' => $companyId,
        'name' => 'Walk-in Customer',
        'is_active' => 1,
        'payment_terms' => 'Due on Receipt',
    ]);
}

/**
 * Complete a POS sale: verify the shift, price from the database, take the
 * tender(s), create the invoice and clear the cart.
 *
 * Accepted $paymentData keys:
 *   branch_id        int    drawer/branch the sale belongs to
 *   payment_method   string single-method tender (legacy path)
 *   payment_amount   float  cash handed over for a single-method tender
 *   reference_number string
 *   notes            string
 *   payments         array  split tender: [['method','amount','reference'], ...]
 *   idempotency_key  string client-generated key so a retried POST cannot
 *                          create a second sale
 *
 * @return array{success:bool,message:string,invoice_id:int,total:float,change:float}
 */
function posCheckout(PDO $pdo, array $paymentData = []): array {
    $companyId = salesCompanyId();
    $idempotencyKey = trim((string) ($paymentData['idempotency_key'] ?? ''));
    if ($idempotencyKey !== '') {
        // Resolved before the cart check: a retry arrives after the first
        // attempt already cleared the cart, and must still see its own sale.
        $replay = salesFindByIdempotencyKey($pdo, $companyId, $idempotencyKey);
        if ($replay !== null) {
            return $replay;
        }
    }

    $cart = $_SESSION['pos_cart'] ?? [];
    if ($cart === []) {
        return ['success' => false, 'invoice_id' => 0, 'message' => 'The cart is empty.', 'total' => 0, 'change' => 0];
    }

    // Recalculate from the current settings so the displayed totals and the
    // invoice totals can never drift apart through a stale session value.
    recalculatePosTotals($pdo);

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $branchId = (int) ($paymentData['branch_id'] ?? 0) ?: salesDefaultBranchId($pdo, $companyId);
    $total = (float) ($_SESSION['pos_total'] ?? 0);

    // Cash only leaves the drawer it was counted into: no open shift, no sale.
    require_once APP_ROOT . '/includes/shift_functions.php';
    $shiftId = 0;
    if (shiftEnforcementEnabled($pdo)) {
        try {
            $shift = shiftRequireOpen($pdo, $userId, $companyId, (int) $branchId);
        } catch (RuntimeException $e) {
            return ['success' => false, 'invoice_id' => 0, 'message' => $e->getMessage(), 'total' => $total, 'change' => 0];
        }
        $shiftId = (int) $shift['shift_id'];
    }

    $customerId = getOrCreateWalkInCustomer($pdo, $companyId);
    $taxRate = (float) ($_SESSION['pos_tax_rate'] ?? 0);

    $lines = [];
    foreach ($cart as $item) {
        // No unit_price: createSalesInvoice() prices each line from products.
        $lines[] = [
            'product_id' => (int) $item['product_id'],
            'description' => (string) $item['product_name'],
            'quantity' => (float) $item['quantity'],
            'discount_percent' => (float) ($item['discount_percent'] ?? 0),
        ];
    }

    $allowed = getActivePaymentMethodCodes($pdo, $companyId);
    if ($allowed === []) {
        $allowed = ['cash', 'check', 'bank_transfer', 'credit_card'];
    }

    $tenders = salesNormaliseTenders($paymentData['payments'] ?? []);
    if ($tenders === []) {
        $method = (string) ($paymentData['payment_method'] ?? 'cash');
        $tenders = [[
            'method' => $method,
            'amount' => salesRound((float) ($paymentData['payment_amount'] ?? $total)),
            'reference' => trim((string) ($paymentData['reference_number'] ?? '')),
            'applied' => 0.0,
        ]];
    }
    if (count($tenders) > 1) {
        // An exact split tender must cover the bill; leaving a remainder on a
        // walk-in sale would hide an unpaid balance inside a closed shift.
        $sum = array_sum(array_map(static fn ($t) => (float) $t['amount'], $tenders));
        if ($sum + 0.0001 < $total) {
            return [
                'success' => false, 'invoice_id' => 0, 'total' => $total, 'change' => 0,
                'message' => 'The payments entered total ' . number_format($sum, 2) . ' but the sale is '
                    . number_format($total, 2) . '.',
            ];
        }
    }
    foreach ($tenders as $tender) {
        if (!in_array($tender['method'], $allowed, true)) {
            return [
                'success' => false, 'invoice_id' => 0, 'total' => $total, 'change' => 0,
                'message' => 'Unknown payment method "' . $tender['method'] . '".',
            ];
        }
        if ($tender['amount'] <= 0) {
            return ['success' => false, 'invoice_id' => 0, 'total' => $total, 'change' => 0,
                'message' => 'Every payment needs an amount greater than zero.'];
        }
    }

    // A gateway tender is not money until the provider has confirmed it, so it
    // cannot be posted here at all. The till opens a payment session instead;
    // the sale is written by that verification, from the snapshot it carries.
    if (function_exists('gatewayOnlineMethods')) {
        $onlineCodes = array_keys(gatewayOnlineMethods($pdo, $companyId));
        foreach ($tenders as $tender) {
            if (in_array($tender['method'], $onlineCodes, true)) {
                return ['success' => false, 'invoice_id' => 0, 'total' => $total, 'change' => 0,
                    'message' => '"' . $tender['method'] . '" is a card payment: take it with the online payment button, '
                        . 'which asks the gateway before anything is recorded as a sale.'];
            }
        }
    }

    $result = createSalesInvoice($pdo, [
        'company_id' => $companyId,
        'customer_id' => $customerId,
        'branch_id' => (int) $branchId,
        'shift_id' => $shiftId,
        'idempotency_key' => $idempotencyKey,
        'issue_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d'),
        'tax_rate' => $taxRate,
        'payment_method' => $tenders[0]['method'],
        'reference_number' => $tenders[0]['reference'],
        'notes' => $paymentData['notes'] ?? 'POS sale',
        'payments' => $tenders,
        'lines' => $lines,
    ]);

    if ($result['success']) {
        posClearCart();
        $_SESSION['pos_last_invoice_id'] = (int) $result['invoice_id'];
    }

    return $result + ['change' => (float) ($result['change'] ?? 0)];
}

if (!function_exists('posWantsJson')) {
    /**
     * True when the POS request expects a JSON answer instead of the classic
     * post/redirect/render cycle. One route, two response envelopes.
     */
    function posWantsJson(): bool
    {
        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            return true;
        }
        if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
            return true;
        }
        $requested = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        return $requested === 'xmlhttprequest';
    }
}

if (!function_exists('posNewIdempotencyKey')) {
    /**
     * Key for one checkout attempt. The client sends its own key so a browser
     * retry reuses it; a server-generated key covers the no-JS path, where the
     * form carries the key that was minted when the cart was last touched.
     */
    function posNewIdempotencyKey(): string
    {
        $posted = trim((string) ($_POST['idempotency_key'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $posted)) {
            return $posted;
        }
        return bin2hex(random_bytes(16));
    }
}

// ============================================================
// Utility Functions
// ============================================================

/**
 * Get the configured default tax rate (as a decimal, e.g. 0.10).
 */
function getDefaultTaxRate(PDO $pdo): float {
    return (float) salesSetting($pdo, 'default_tax_rate', '0');
}

/**
 * Check if a customer has enough available credit.
 */
function customerHasCredit(PDO $pdo, int $customerId, float $amount): bool {
    $customer = getCustomerById($pdo, $customerId);
    if (!$customer) {
        return false;
    }
    $available = (float) ($customer['credit_limit'] ?? 0) - (float) ($customer['credit_used'] ?? 0);
    return $available >= $amount;
}
