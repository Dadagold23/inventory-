<?php
/**
 * Module 9: Expense Functions
 * Expense categories and expenses using the canonical tables
 * expense_categories, expenses, vendors and employees.
 */

// Prevent direct access
defined('EXPENSE_MODULE') || define('EXPENSE_MODULE', 'expense');

// Require foundation
defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';

if (!function_exists('expenseCompanyId')) {
    function expenseCompanyId(): int
    {
        return function_exists('currentCompanyId') ? currentCompanyId() : 1;
    }
}

if (!function_exists('expenseOverdueDays')) {
    function expenseOverdueDays(): int
    {
        return 30;
    }
}

if (!function_exists('expensePartialUpdate')) {
    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    function expensePartialUpdate(array $data, array $allowed): array
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

// ============================================================
// Expense category functions
// ============================================================

function getExpenseCategoriesByCompany(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT ec.*,
               COUNT(e.expense_id) AS expense_count,
               COALESCE(SUM(e.total_amount), 0) AS grand_total
        FROM expense_categories ec
        LEFT JOIN expenses e
               ON e.category_id = ec.category_id AND e.company_id = ec.company_id
        WHERE ec.company_id = ?
        GROUP BY ec.category_id
        ORDER BY ec.sort_order, ec.name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getExpenseCategoryById(PDO $pdo, int $categoryId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM expense_categories WHERE category_id = ?");
    $stmt->execute([$categoryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createExpenseCategory(PDO $pdo, array $categoryData): int
{
    $name = trim((string) ($categoryData['name'] ?? ''));
    if ($name === '') {
        return 0;
    }

    $stmt = $pdo->prepare("
        INSERT INTO expense_categories (company_id, name, code, description, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $categoryData['company_id'] ?? expenseCompanyId(),
        $name,
        $categoryData['code'] ?? null,
        $categoryData['description'] ?? null,
        array_key_exists('is_active', $categoryData) ? (int) (bool) $categoryData['is_active'] : 1,
        (int) ($categoryData['sort_order'] ?? 0),
    ]);

    return (int) $pdo->lastInsertId();
}

function updateExpenseCategory(PDO $pdo, int $categoryId, array $categoryData): bool
{
    $allowed = ['name', 'code', 'description', 'is_active', 'sort_order'];
    if (array_key_exists('is_active', $categoryData)) {
        $categoryData['is_active'] = (int) (bool) $categoryData['is_active'];
    }
    if (array_key_exists('name', $categoryData)) {
        $categoryData['name'] = trim((string) $categoryData['name']);
        if ($categoryData['name'] === '') {
            unset($categoryData['name']);
        }
    }

    [$set, $params] = expensePartialUpdate($categoryData, $allowed);
    if ($set === '') {
        return true;
    }

    $params[] = $categoryId;
    $stmt = $pdo->prepare("UPDATE expense_categories SET $set, updated_at = NOW() WHERE category_id = ?");
    return $stmt->execute($params);
}

function deleteExpenseCategory(PDO $pdo, int $categoryId): bool
{
    $stmt = $pdo->prepare("UPDATE expense_categories SET is_active = 0, updated_at = NOW() WHERE category_id = ?");
    return $stmt->execute([$categoryId]);
}

// ============================================================
// Expense functions
// ============================================================

function getExpensesByCompany(PDO $pdo, int $companyId = 1, ?string $status = null): array
{
    $cond = $status ? 'AND e.payment_status = ?' : '';
    $params = $status ? [$status] : [];

    $stmt = $pdo->prepare("
        SELECT e.*, ec.name AS category_name, v.name AS supplier_name,
               COALESCE(
                   NULLIF(TRIM(CONCAT(COALESCE(emp.first_name, ''), ' ', COALESCE(emp.last_name, ''))), ''),
                   u.full_name
               ) AS employee_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.category_id
        LEFT JOIN vendors v ON e.vendor_id = v.vendor_id
        LEFT JOIN employees emp ON e.employee_id = emp.employee_id
        LEFT JOIN users u ON emp.user_id = u.user_id
        WHERE e.company_id = ? $cond
        ORDER BY e.expense_date DESC, e.expense_id DESC
    ");
    array_unshift($params, $companyId);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getExpenseById(PDO $pdo, int $expenseId): ?array
{
    $stmt = $pdo->prepare("
        SELECT e.*, ec.name AS category_name, v.name AS supplier_name,
               COALESCE(
                   NULLIF(TRIM(CONCAT(COALESCE(emp.first_name, ''), ' ', COALESCE(emp.last_name, ''))), ''),
                   u.full_name
               ) AS employee_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.category_id
        LEFT JOIN vendors v ON e.vendor_id = v.vendor_id
        LEFT JOIN employees emp ON e.employee_id = emp.employee_id
        LEFT JOIN users u ON emp.user_id = u.user_id
        WHERE e.expense_id = ?
    ");
    $stmt->execute([$expenseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createExpense(PDO $pdo, array $expenseData): int
{
    $description = trim((string) ($expenseData['description'] ?? ''));
    if ($description === '') {
        return 0;
    }

    $companyId = (int) ($expenseData['company_id'] ?? expenseCompanyId());
    $categoryId = (int) ($expenseData['category_id'] ?? 0);
    $categoryName = null;
    if ($categoryId > 0) {
        $categoryStmt = $pdo->prepare("SELECT name FROM expense_categories WHERE category_id = ? AND company_id = ?");
        $categoryStmt->execute([$categoryId, $companyId]);
        $categoryName = $categoryStmt->fetchColumn() ?: null;
    }

    $amount = (float) ($expenseData['amount'] ?? 0);
    $taxAmount = (float) ($expenseData['tax_amount'] ?? 0);
    $totalAmount = $expenseData['total_amount'] ?? ($amount + $taxAmount);
    $expenseNumber = trim((string) ($expenseData['expense_number'] ?? ''));
    if ($expenseNumber === '') {
        $expenseNumber = 'EXP-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    $stmt = $pdo->prepare("
        INSERT INTO expenses
            (company_id, category_id, vendor_id, employee_id, expense_number, expense_date,
             description, amount, tax_amount, total_amount, category, receipt_path,
             receipt_status, payment_status, payment_date, approved_by, created_by, notes, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $companyId,
        $categoryId > 0 ? $categoryId : null,
        (int) ($expenseData['vendor_id'] ?? $expenseData['supplier_id'] ?? 0) ?: null,
        (int) ($expenseData['employee_id'] ?? 0) ?: null,
        $expenseNumber,
        $expenseData['expense_date'] ?? date('Y-m-d'),
        $description,
        $amount,
        $taxAmount,
        $totalAmount,
        $categoryName,
        $expenseData['receipt_path'] ?? null,
        $expenseData['receipt_status'] ?? 'none',
        $expenseData['payment_status'] ?? 'pending',
        !empty($expenseData['payment_date']) ? $expenseData['payment_date'] : null,
        $expenseData['approved_by'] ?? null,
        $expenseData['created_by'] ?? ($_SESSION['user_id'] ?? null),
        $expenseData['notes'] ?? null,
        array_key_exists('is_active', $expenseData) ? (int) (bool) $expenseData['is_active'] : 1,
    ]);

    return (int) $pdo->lastInsertId();
}

function updateExpense(PDO $pdo, int $expenseId, array $expenseData): bool
{
    $allowed = [
        'category_id', 'vendor_id', 'employee_id', 'expense_number', 'expense_date',
        'description', 'amount', 'tax_amount', 'total_amount', 'category', 'receipt_path',
        'receipt_status', 'payment_status', 'payment_date', 'approved_by', 'notes', 'is_active',
    ];

    if (array_key_exists('supplier_id', $expenseData) && !array_key_exists('vendor_id', $expenseData)) {
        $expenseData['vendor_id'] = $expenseData['supplier_id'];
    }
    if (array_key_exists('category_id', $expenseData)) {
        $categoryId = (int) $expenseData['category_id'];
        if ($categoryId > 0) {
            $categoryStmt = $pdo->prepare("SELECT name FROM expense_categories WHERE category_id = ?");
            $categoryStmt->execute([$categoryId]);
            $expenseData['category'] = $categoryStmt->fetchColumn() ?: null;
        } else {
            $expenseData['category_id'] = null;
            $expenseData['category'] = null;
        }
    }
    if (array_key_exists('amount', $expenseData) || array_key_exists('tax_amount', $expenseData)) {
        $current = getExpenseById($pdo, $expenseId);
        if ($current) {
            $amount = (float) ($expenseData['amount'] ?? $current['amount']);
            $tax = (float) ($expenseData['tax_amount'] ?? $current['tax_amount']);
            if (!array_key_exists('total_amount', $expenseData)) {
                $expenseData['total_amount'] = $amount + $tax;
            }
        }
    }
    foreach (['vendor_id', 'employee_id'] as $nullableFk) {
        if (array_key_exists($nullableFk, $expenseData) && (int) $expenseData[$nullableFk] <= 0) {
            $expenseData[$nullableFk] = null;
        }
    }

    [$set, $params] = expensePartialUpdate($expenseData, $allowed);
    if ($set === '') {
        return true;
    }

    $params[] = $expenseId;
    $stmt = $pdo->prepare("UPDATE expenses SET $set, updated_at = NOW() WHERE expense_id = ?");
    return $stmt->execute($params);
}

function updateExpensePayment(PDO $pdo, int $expenseId, string $paymentStatus, string $paymentDate = '', string $notes = ''): bool
{
    $data = ['payment_status' => $paymentStatus];
    if ($paymentDate !== '') {
        $data['payment_date'] = $paymentDate;
    } elseif ($paymentStatus === 'paid') {
        $data['payment_date'] = date('Y-m-d');
    }
    if ($notes !== '') {
        $data['notes'] = $notes;
    }

    return updateExpense($pdo, $expenseId, $data);
}

function getExpenseSummaryByCategory(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT ec.name AS category_name,
               COALESCE(SUM(e.amount), 0) AS total_amount,
               COALESCE(SUM(e.tax_amount), 0) AS total_tax,
               COALESCE(SUM(e.total_amount), 0) AS grand_total,
               COUNT(e.expense_id) AS expense_count
        FROM expense_categories ec
        LEFT JOIN expenses e ON ec.category_id = e.category_id AND e.company_id = ?
        WHERE ec.company_id = ?
        GROUP BY ec.category_id
        ORDER BY ec.sort_order, ec.name
    ");
    $stmt->execute([$companyId, $companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getExpensePaymentSummary(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT payment_status, COUNT(*) AS count,
               COALESCE(SUM(amount), 0) AS total_amount,
               COALESCE(SUM(total_amount), 0) AS grand_total
        FROM expenses
        WHERE company_id = ?
        GROUP BY payment_status
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getOverdueExpenses(PDO $pdo, int $companyId = 1): array
{
    $days = expenseOverdueDays();
    $stmt = $pdo->prepare("
        SELECT e.*, ec.name AS category_name, v.name AS supplier_name,
               DATEDIFF(CURDATE(), e.expense_date) AS days_overdue
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.category_id
        LEFT JOIN vendors v ON e.vendor_id = v.vendor_id
        WHERE e.company_id = ?
          AND e.payment_status IN ('pending', 'partial')
          AND e.expense_date <= DATE_SUB(CURDATE(), INTERVAL $days DAY)
        ORDER BY e.expense_date ASC
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Employees available for expense attribution.
 */
function getExpenseEmployees(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT e.employee_id, e.first_name, e.last_name, e.employee_code,
               COALESCE(
                   NULLIF(TRIM(CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, ''))), ''),
                   u.full_name
               ) AS display_name
        FROM employees e
        LEFT JOIN users u ON e.user_id = u.user_id
        WHERE e.company_id = ? AND e.is_active = 1
        ORDER BY e.first_name, e.last_name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
