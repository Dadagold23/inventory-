<?php
/**
 * Module 9: Expense Controller
 * Expense categories, expenses and payment tracking.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('EXPENSE_MODULE') || define('EXPENSE_MODULE', 'expense');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/inventory_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/ap_functions.php';
require_once APP_ROOT . '/includes/customer_receivables_functions.php';
require_once APP_ROOT . '/includes/income_functions.php';
require_once APP_ROOT . '/includes/expense_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $expenseWriteAction = match ($postAction) {
        'save_category' => (int) ($_POST['category_id'] ?? 0) > 0 ? 'update' : 'create',
        'deactivate_category', 'cancel_expense' => 'delete',
        'save_expense' => (int) ($_POST['expense_id'] ?? 0) > 0 ? 'update' : 'create',
        'record_payment' => 'update',
        default => 'update',
    };
    require_permission('expenses', $expenseWriteAction);
} else {
    require_permission('expenses', 'view');
}

$action = (string) ($_GET['action'] ?? 'categories');
if (!in_array($action, ['categories', 'expenses', 'summary'], true)) {
    $action = 'categories';
}

$expenseId = (int) ($_GET['expense_id'] ?? 0);
$editCategoryId = (int) ($_GET['edit_category'] ?? 0);
$pageTitle = 'Expense Management';

// ============================================================
// POST actions
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'save_category') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $name = trim((string) ($_POST['category_name'] ?? ''));

        if ($name === '') {
            $_SESSION['auth_message'] = 'A category name is required.';
            redirect('/expense?action=categories');
        }

        $payload = [
            'name' => $name,
            'code' => trim((string) ($_POST['category_code'] ?? '')),
            'description' => trim((string) ($_POST['category_description'] ?? '')),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ];

        if ($categoryId > 0) {
            $existing = getExpenseCategoryById($pdo, $categoryId);
            if (!$existing || (int) $existing['company_id'] !== $companyId) {
                $_SESSION['auth_message'] = 'Category not found.';
                redirect('/expense?action=categories');
            }
            updateExpenseCategory($pdo, $categoryId, $payload);
            $_SESSION['auth_message'] = 'Expense category updated.';
        } else {
            $payload['company_id'] = $companyId;
            $categoryId = createExpenseCategory($pdo, $payload);
            $_SESSION['auth_message'] = 'Expense category created.';
        }

        redirect('/expense?action=categories' . ($categoryId > 0 ? '&edit_category=' . $categoryId : ''));
    }

    if ($postAction === 'deactivate_category') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $existing = $categoryId > 0 ? getExpenseCategoryById($pdo, $categoryId) : null;

        if ($existing && (int) $existing['company_id'] === $companyId) {
            deleteExpenseCategory($pdo, $categoryId);
            $_SESSION['auth_message'] = 'Expense category deactivated.';
        } else {
            $_SESSION['auth_message'] = 'Category not found.';
        }

        redirect('/expense?action=categories');
    }

    if ($postAction === 'save_expense') {
        $targetId = (int) ($_POST['expense_id'] ?? 0);
        $description = trim((string) ($_POST['description'] ?? ''));
        $amount = moneyInput($_POST['amount'] ?? 0);
        $taxAmount = moneyInput($_POST['tax_amount'] ?? 0);

        if ($description === '' || $amount < 0) {
            $_SESSION['auth_message'] = 'A description and a valid amount are required.';
            redirect('/expense?action=expenses' . ($targetId > 0 ? '&expense_id=' . $targetId : ''));
        }

        $payload = [
            'category_id' => (int) ($_POST['category_id'] ?? 0),
            'vendor_id' => (int) ($_POST['supplier_id'] ?? 0),
            'employee_id' => (int) ($_POST['employee_id'] ?? 0),
            'expense_number' => trim((string) ($_POST['expense_number'] ?? '')),
            'expense_date' => (string) ($_POST['expense_date'] ?? date('Y-m-d')),
            'description' => $description,
            'amount' => $amount,
            'tax_amount' => $taxAmount,
            'total_amount' => $amount + $taxAmount,
            'receipt_status' => (string) ($_POST['receipt_status'] ?? 'none'),
            'payment_status' => (string) ($_POST['payment_status'] ?? 'pending'),
            'payment_date' => (string) ($_POST['payment_date'] ?? '') ?: null,
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];

        if ($payload['category_id'] <= 0) {
            $_SESSION['auth_message'] = 'Select an expense category.';
            redirect('/expense?action=expenses');
        }

        if ($targetId > 0) {
            $existing = getExpenseById($pdo, $targetId);
            if (!$existing || (int) $existing['company_id'] !== $companyId) {
                $_SESSION['auth_message'] = 'Expense not found.';
                redirect('/expense?action=expenses');
            }
            updateExpense($pdo, $targetId, $payload);
            $_SESSION['auth_message'] = 'Expense updated.';
        } else {
            $payload['company_id'] = $companyId;
            $payload['created_by'] = $_SESSION['user_id'] ?? null;
            $targetId = createExpense($pdo, $payload);
            $_SESSION['auth_message'] = 'Expense recorded.';
        }

        redirect('/expense?action=expenses');
    }

    if ($postAction === 'record_payment') {
        $targetId = (int) ($_POST['expense_id'] ?? 0);
        $status = (string) ($_POST['payment_status'] ?? 'paid');
        $existing = $targetId > 0 ? getExpenseById($pdo, $targetId) : null;

        if ($existing && (int) $existing['company_id'] === $companyId) {
            updateExpensePayment($pdo, $targetId, $status, (string) ($_POST['payment_date'] ?? ''));
            $_SESSION['auth_message'] = 'Expense payment status updated.';
        } else {
            $_SESSION['auth_message'] = 'Expense not found.';
        }

        redirect('/expense?action=expenses');
    }

    if ($postAction === 'cancel_expense') {
        $targetId = (int) ($_POST['expense_id'] ?? 0);
        $existing = $targetId > 0 ? getExpenseById($pdo, $targetId) : null;

        if ($existing && (int) $existing['company_id'] === $companyId) {
            updateExpense($pdo, $targetId, ['payment_status' => 'cancelled']);
            $_SESSION['auth_message'] = 'Expense cancelled.';
        } else {
            $_SESSION['auth_message'] = 'Expense not found.';
        }

        redirect('/expense?action=expenses');
    }
}

// ============================================================
// Load data
// ============================================================

$categories = getExpenseCategoriesByCompany($pdo, $companyId);

$category = [];
if ($editCategoryId > 0) {
    $category = getExpenseCategoryById($pdo, $editCategoryId) ?? [];
    if ($category !== [] && (int) $category['company_id'] !== $companyId) {
        $category = [];
        $editCategoryId = 0;
    }
}

$expenses = getExpensesByCompany($pdo, $companyId);

$expense = [];
if ($expenseId > 0) {
    $expense = getExpenseById($pdo, $expenseId) ?? [];
    if ($expense !== [] && (int) $expense['company_id'] !== $companyId) {
        $expense = [];
        $expenseId = 0;
    }
}

$paymentSummary = getExpensePaymentSummary($pdo, $companyId);
$expenseSummary = getExpenseSummaryByCategory($pdo, $companyId);
$overdueExpenses = getOverdueExpenses($pdo, $companyId);
$employees = getExpenseEmployees($pdo, $companyId);
$suppliers = getSuppliersByCompany($pdo, $companyId);

$totalExpenses = array_sum(array_map(
    static fn(array $row): float => (float) ($row['grand_total'] ?? 0),
    $paymentSummary
));
$totalUnpaid = array_sum(array_map(
    static fn(array $row): float => in_array($row['payment_status'] ?? '', ['pending', 'partial'], true)
        ? (float) ($row['grand_total'] ?? 0) : 0,
    $paymentSummary
));

if ($action === 'expenses') {
    $pageTitle = 'Expenses';
} elseif ($action === 'summary') {
    $pageTitle = 'Expense Summary';
} else {
    $pageTitle = 'Expense Categories';
}

$companyCurrency = getCompanyCurrency($pdo);

require APP_ROOT . '/views/expense.html';
