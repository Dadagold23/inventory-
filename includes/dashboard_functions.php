<?php
/**
 * Dashboard aggregates computed directly from live transaction tables.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/includes/sales_functions.php';

if (!function_exists('dashboard_scalar')) {
    function dashboard_scalar(PDO $pdo, string $sql, array $params = []): float
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (float) $stmt->fetchColumn();
        } catch (PDOException $e) {
            app_log('warning', 'Dashboard query failed: ' . $e->getMessage());
            return 0.0;
        }
    }
}

if (!function_exists('getDashboardSummary')) {
    /**
     * Company-scoped dashboard metrics. Missing optional tables yield zero.
     */
    function getDashboardSummary(PDO $pdo, int $companyId = 1): array
    {
        $inventoryValue = dashboard_scalar(
            $pdo,
            "SELECT COALESCE(SUM(pl.on_hand_value), 0)
             FROM product_locations pl
             JOIN products p ON p.product_id = pl.product_id
             WHERE p.company_id = ? AND p.is_active = 1",
            [$companyId]
        );

        $totalSales = dashboard_scalar(
            $pdo,
            "SELECT COALESCE(SUM(total_amount), 0)
             FROM sales_invoices
             WHERE company_id = ? AND " . salesRevenueStatusSql()
             . " AND DATE_FORMAT(issue_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
            [$companyId]
        );

        $totalIncome = dashboard_scalar(
            $pdo,
            "SELECT COALESCE(SUM(amount), 0)
             FROM income_records
             WHERE company_id = ? AND DATE_FORMAT(income_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
            [$companyId]
        );

        $totalExpenses = dashboard_scalar(
            $pdo,
            "SELECT COALESCE(SUM(amount), 0)
             FROM expenses
             WHERE company_id = ? AND is_active = 1
               AND DATE_FORMAT(expense_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
            [$companyId]
        );

        $cashBalance = dashboard_scalar(
            $pdo,
            "SELECT COALESCE(SUM(balance), 0) FROM banks WHERE company_id = ? AND is_active = 1",
            [$companyId]
        );

        $receivables = dashboard_scalar(
            $pdo,
            "SELECT COALESCE(SUM(GREATEST(si.total_amount - COALESCE(pay.paid, 0), 0)), 0)
             FROM sales_invoices si
             LEFT JOIN (
                 SELECT invoice_id, SUM(amount) AS paid
                 FROM customer_payments
                 GROUP BY invoice_id
             ) pay ON pay.invoice_id = si.invoice_id
             WHERE si.company_id = ? AND si.status IN ('pending', 'partial')",
            [$companyId]
        );

        $payables = dashboard_scalar(
            $pdo,
            "SELECT COALESCE(SUM(GREATEST(po.total_amount - COALESCE(pm.paid, 0), 0)), 0)
             FROM purchase_orders po
             LEFT JOIN (
                 SELECT purchase_order_id, SUM(amount) AS paid
                 FROM payment_made
                 GROUP BY purchase_order_id
             ) pm ON pm.purchase_order_id = po.purchase_order_id
             WHERE po.company_id = ? AND po.status IN ('pending', 'partial')",
            [$companyId]
        );

        $employees = dashboard_scalar(
            $pdo,
            "SELECT COUNT(*)
             FROM employees
             WHERE company_id = ? AND is_active = 1
               AND (termination_date IS NULL OR termination_date > CURDATE())",
            [$companyId]
        );

        return [
            'inventory_value' => $inventoryValue,
            'total_sales' => $totalSales,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'cash_balance' => $cashBalance,
            'outstanding_receivables' => $receivables,
            'outstanding_payables' => $payables,
            'active_employees' => (int) $employees,
        ];
    }
}

if (!function_exists('getDashboardMonthlyTrend')) {
    /**
     * Sales and expense totals for the most recent months, zero-filled.
     */
    function getDashboardMonthlyTrend(PDO $pdo, int $companyId = 1, int $months = 6): array
    {
        $months = max(3, min(12, $months));
        $firstMonth = new DateTimeImmutable('first day of this month');
        $keys = [];
        $labels = [];

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $month = $firstMonth->modify('-' . $offset . ' months');
            $keys[] = $month->format('Y-m');
            $labels[] = $month->format('M Y');
        }

        $sales = array_fill_keys($keys, 0.0);
        $expenses = array_fill_keys($keys, 0.0);
        $startDate = $keys[0] . '-01';

        try {
            $stmt = $pdo->prepare(
                "SELECT DATE_FORMAT(issue_date, '%Y-%m') AS month_key, COALESCE(SUM(total_amount), 0) AS total
                 FROM sales_invoices
                 WHERE company_id = ? AND " . salesRevenueStatusSql() . " AND issue_date >= ?
                 GROUP BY month_key"
            );
            $stmt->execute([$companyId, $startDate]);
            foreach ($stmt->fetchAll() as $row) {
                if (isset($sales[$row['month_key']])) {
                    $sales[$row['month_key']] = (float) $row['total'];
                }
            }

            $stmt = $pdo->prepare(
                "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month_key, COALESCE(SUM(amount), 0) AS total
                 FROM expenses
                 WHERE company_id = ? AND is_active = 1 AND expense_date >= ?
                 GROUP BY month_key"
            );
            $stmt->execute([$companyId, $startDate]);
            foreach ($stmt->fetchAll() as $row) {
                if (isset($expenses[$row['month_key']])) {
                    $expenses[$row['month_key']] = (float) $row['total'];
                }
            }
        } catch (PDOException $e) {
            app_log('warning', 'Dashboard trend query failed: ' . $e->getMessage());
        }

        return [
            'labels' => $labels,
            'sales' => array_values($sales),
            'expenses' => array_values($expenses),
        ];
    }
}

if (!function_exists('getDashboardInvoiceStatusBreakdown')) {
    function getDashboardInvoiceStatusBreakdown(PDO $pdo, int $companyId = 1): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT status, COUNT(*) AS invoice_count, COALESCE(SUM(total_amount), 0) AS total_amount
                 FROM sales_invoices
                 WHERE company_id = ?
                 GROUP BY status
                 ORDER BY FIELD(status, 'draft', 'pending', 'partial', 'paid', 'cancelled')"
            );
            $stmt->execute([$companyId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            app_log('warning', 'Dashboard invoice status query failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getRecentSalesInvoices')) {
    function getRecentSalesInvoices(PDO $pdo, int $companyId = 1, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        try {
            $stmt = $pdo->prepare(
                "SELECT si.invoice_id, si.invoice_number, si.issue_date, si.due_date,
                        si.total_amount, si.status, c.name AS customer_name
                 FROM sales_invoices si
                 LEFT JOIN customers c ON c.customer_id = si.customer_id
                 WHERE si.company_id = ?
                 ORDER BY si.issue_date DESC, si.invoice_id DESC
                 LIMIT $limit"
            );
            $stmt->execute([$companyId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            app_log('warning', 'Recent invoices query failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getLowStockProducts')) {
    function getLowStockProducts(PDO $pdo, int $companyId = 1, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        try {
            $stmt = $pdo->prepare(
                "SELECT p.product_id, p.name, p.sku, p.reorder_level,
                        COALESCE(SUM(pl.on_hand_quantity), 0) AS on_hand
                 FROM products p
                 LEFT JOIN product_locations pl ON pl.product_id = p.product_id
                 WHERE p.company_id = ? AND p.is_active = 1 AND p.manage_stock = 1
                 GROUP BY p.product_id, p.name, p.sku, p.reorder_level
                 HAVING on_hand <= p.reorder_level
                 ORDER BY p.name
                 LIMIT $limit"
            );
            $stmt->execute([$companyId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            app_log('warning', 'Low stock query failed: ' . $e->getMessage());
            return [];
        }
    }
}
