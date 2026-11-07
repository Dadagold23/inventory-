<?php
/**
 * Module 13: Financial Report Functions
 * Report catalogue plus live computations from income, expense, payment and
 * journal-entry data.
 */

defined('REPORT_MODULE') || define('REPORT_MODULE', 'report');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/sales_functions.php';

const REPORT_TYPES = ['income_statement', 'balance_sheet', 'cash_flow', 'trial_balance', 'general_ledger', 'sales_analytics'];
const REPORT_STATUSES = ['draft', 'generated', 'exported'];

function reportTypeLabels(): array
{
    return [
        'income_statement' => 'Income Statement',
        'balance_sheet' => 'Balance Sheet',
        'cash_flow' => 'Cash Flow Statement',
        'trial_balance' => 'Trial Balance',
        'general_ledger' => 'General Ledger Extract',
        'sales_analytics' => 'Sales Analytics',
    ];
}

/** Dimensions the sales analytics report can break a period down by. */
function reportAnalyticsDimensions(): array
{
    return [
        'day' => 'By day',
        'product' => 'By product',
        'category' => 'By category',
        'branch' => 'By branch',
        'cashier' => 'By cashier',
    ];
}

function reportTypeLabel(string $type): string
{
    return reportTypeLabels()[$type] ?? $type;
}

function reportValidDate(string $date): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

// ============================================================
// Report catalogue
// ============================================================

function getFinancialReportsByCompany(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT fr.*, u.full_name AS generated_by_name
        FROM financial_reports fr
        LEFT JOIN users u ON fr.generated_by = u.user_id
        WHERE fr.company_id = ?
        ORDER BY fr.generated_at DESC, fr.report_id DESC
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getFinancialReportById(PDO $pdo, int $reportId): ?array
{
    $stmt = $pdo->prepare("
        SELECT fr.*, u.full_name AS generated_by_name
        FROM financial_reports fr
        LEFT JOIN users u ON fr.generated_by = u.user_id
        WHERE fr.report_id = ?
    ");
    $stmt->execute([$reportId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createFinancialReport(PDO $pdo, array $data): int
{
    $companyId = (int) ($data['company_id'] ?? 1);
    $type = (string) ($data['report_type'] ?? '');
    $start = (string) ($data['period_start'] ?? '');
    $end = (string) ($data['period_end'] ?? '');
    $generatedBy = (int) ($data['generated_by'] ?? ($_SESSION['user_id'] ?? 0));

    if (!in_array($type, REPORT_TYPES, true) || $generatedBy <= 0) {
        return 0;
    }
    if (!reportValidDate($start) || !reportValidDate($end) || $start > $end) {
        return 0;
    }

    $name = trim((string) ($data['report_name'] ?? ''));
    if ($name === '') {
        $name = reportTypeLabel($type) . ' ' . $start . ' to ' . $end;
    }

    $stmt = $pdo->prepare("
        INSERT INTO financial_reports
            (company_id, report_name, report_type, period_start, period_end,
             generated_by, status, description)
        VALUES (?, ?, ?, ?, ?, ?, 'generated', ?)
    ");
    $stmt->execute([
        $companyId,
        substr($name, 0, 100),
        $type,
        $start,
        $end,
        $generatedBy,
        trim((string) ($data['description'] ?? '')) ?: null,
    ]);

    return (int) $pdo->lastInsertId();
}

function regenerateFinancialReport(PDO $pdo, int $reportId, int $companyId = 1): bool
{
    $stmt = $pdo->prepare("
        UPDATE financial_reports
           SET status = 'generated', generated_at = NOW()
         WHERE report_id = ? AND company_id = ?
    ");
    return $stmt->execute([$reportId, $companyId]);
}

function getReportColumns(PDO $pdo, int $reportId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM report_columns WHERE report_id = ? ORDER BY display_order, column_id'
    );
    $stmt->execute([$reportId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// Live report computation
// ============================================================

function computeReportData(PDO $pdo, array $report, array $options = []): array
{
    $companyId = (int) $report['company_id'];
    $start = (string) $report['period_start'];
    $end = (string) $report['period_end'];

    return match ($report['report_type']) {
        'income_statement' => reportIncomeStatement($pdo, $companyId, $start, $end),
        'balance_sheet' => reportBalanceSheet($pdo, $companyId, $end),
        'cash_flow' => reportCashFlow($pdo, $companyId, $start, $end),
        'trial_balance' => reportTrialBalance($pdo, $companyId, $start, $end),
        'general_ledger' => reportGeneralLedger($pdo, $companyId, $start, $end),
        'sales_analytics' => reportSalesAnalytics($pdo, $companyId, $start, $end, $options),
        default => ['note' => 'Unknown report type.'],
    };
}

function reportIncomeStatement(PDO $pdo, int $companyId, string $start, string $end): array
{
    $incomeStmt = $pdo->prepare("
        SELECT COALESCE(s.name, ir.source, 'Uncategorised') AS label,
               COALESCE(SUM(ir.total_amount), 0) AS amount
        FROM income_records ir
        LEFT JOIN income_sources s ON ir.source_id = s.income_source_id
        WHERE ir.company_id = ? AND ir.income_date BETWEEN ? AND ?
          AND ir.payment_status <> 'cancelled'
        GROUP BY COALESCE(s.name, ir.source, 'Uncategorised')
        ORDER BY amount DESC
    ");
    $incomeStmt->execute([$companyId, $start, $end]);
    $incomeRows = $incomeStmt->fetchAll(PDO::FETCH_ASSOC);

    $expenseStmt = $pdo->prepare("
        SELECT COALESCE(ec.name, e.category, 'Uncategorised') AS label,
               COALESCE(SUM(e.total_amount), 0) AS amount
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.category_id
        WHERE e.company_id = ? AND e.expense_date BETWEEN ? AND ?
          AND e.is_active = 1 AND e.payment_status <> 'cancelled'
        GROUP BY COALESCE(ec.name, e.category, 'Uncategorised')
        ORDER BY amount DESC
    ");
    $expenseStmt->execute([$companyId, $start, $end]);
    $expenseRows = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);

    $incomeTotal = array_sum(array_map(static fn(array $row): float => (float) $row['amount'], $incomeRows));
    $expenseTotal = array_sum(array_map(static fn(array $row): float => (float) $row['amount'], $expenseRows));

    return [
        'note' => 'Computed from income records and expenses dated within the period.',
        'income_rows' => $incomeRows,
        'income_total' => $incomeTotal,
        'expense_rows' => $expenseRows,
        'expense_total' => $expenseTotal,
        'net_income' => $incomeTotal - $expenseTotal,
    ];
}

function reportBalanceSheet(PDO $pdo, int $companyId, string $asOf): array
{
    $stmt = $pdo->prepare("
        SELECT a.account_code, a.account_name, a.account_type,
               COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL THEN jl.debit_amount ELSE 0 END), 0) AS debit_total,
               COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL THEN jl.credit_amount ELSE 0 END), 0) AS credit_total
        FROM chart_of_accounts a
        LEFT JOIN journal_entry_lines jl ON jl.account_id = a.account_id
        LEFT JOIN journal_entries je ON je.entry_id = jl.entry_id
             AND je.status = 'posted' AND je.entry_date <= ?
        WHERE a.company_id = ?
        GROUP BY a.account_id, a.account_code, a.account_name, a.account_type
        ORDER BY a.account_type, a.account_code
    ");
    $stmt->execute([$asOf, $companyId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $assets = [];
    $liabilities = [];
    $equity = [];
    $retained = 0.0;

    foreach ($accounts as $account) {
        $debit = (float) $account['debit_total'];
        $credit = (float) $account['credit_total'];
        $row = [
            'account_code' => $account['account_code'],
            'account_name' => $account['account_name'],
            'balance' => 0.0,
        ];

        switch ($account['account_type']) {
            case 'asset':
                $row['balance'] = $debit - $credit;
                $assets[] = $row;
                break;
            case 'liability':
                $row['balance'] = $credit - $debit;
                $liabilities[] = $row;
                break;
            case 'equity':
                $row['balance'] = $credit - $debit;
                $equity[] = $row;
                break;
            case 'income':
                $retained += $credit - $debit;
                break;
            case 'expense':
                $retained -= $debit - $credit;
                break;
        }
    }

    $assetTotal = array_sum(array_column($assets, 'balance'));
    $liabilityTotal = array_sum(array_column($liabilities, 'balance'));
    $equityTotal = array_sum(array_column($equity, 'balance'));

    return [
        'note' => 'Account balances from posted journal entries up to ' . $asOf . ', plus retained earnings from income and expense accounts.',
        'asset_rows' => $assets,
        'asset_total' => $assetTotal,
        'liability_rows' => $liabilities,
        'liability_total' => $liabilityTotal,
        'equity_rows' => $equity,
        'equity_total' => $equityTotal,
        'retained_earnings' => $retained,
        'total_equity' => $equityTotal + $retained,
        'balance_check' => $assetTotal - ($liabilityTotal + $equityTotal + $retained),
    ];
}

function reportCashFlow(PDO $pdo, int $companyId, string $start, string $end): array
{
    $inflows = [];

    $scopeUserId = auth_sales_scope_user_id();
    $paymentScopeSql = $scopeUserId === null ? '' : ' AND si.created_by = ?';
    $paymentParams = [$companyId, $start, $end];
    if ($scopeUserId !== null) {
        $paymentParams[] = $scopeUserId;
    }
    $stmt = $pdo->prepare("
        SELECT CONCAT('Customer payments (', REPLACE(p.payment_method, '_', ' '), ')') AS label,
               COALESCE(SUM(p.base_amount), 0) AS amount
        FROM customer_payments p
        INNER JOIN sales_invoices si ON si.invoice_id = p.invoice_id
        WHERE p.company_id = ? AND p.payment_date BETWEEN ? AND ? $paymentScopeSql
        GROUP BY payment_method
        ORDER BY amount DESC
    ");
    $stmt->execute($paymentParams);
    $inflows = array_merge($inflows, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare("
        SELECT 'Income receipts (unallocated)' AS label,
               COALESCE(SUM(total_amount), 0) AS amount
        FROM income_records
        WHERE company_id = ? AND income_date BETWEEN ? AND ?
          AND payment_status = 'paid' AND sales_invoice_id IS NULL
        HAVING amount > 0
    ");
    $stmt->execute([$companyId, $start, $end]);
    $inflows = array_merge($inflows, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $outflows = [];

    $stmt = $pdo->prepare("
        SELECT CONCAT('Supplier payments (', REPLACE(payment_method, '_', ' '), ')') AS label,
               COALESCE(SUM(amount), 0) AS amount
        FROM payment_made
        WHERE company_id = ? AND payment_date BETWEEN ? AND ?
        GROUP BY payment_method
        ORDER BY amount DESC
    ");
    $stmt->execute([$companyId, $start, $end]);
    $outflows = array_merge($outflows, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->prepare("
        SELECT COALESCE(ec.name, e.category, 'Uncategorised expenses') AS label,
               COALESCE(SUM(e.total_amount), 0) AS amount
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.category_id
        WHERE e.company_id = ? AND e.payment_status = 'paid'
          AND e.payment_date BETWEEN ? AND ? AND e.is_active = 1
        GROUP BY COALESCE(ec.name, e.category, 'Uncategorised expenses')
        ORDER BY amount DESC
    ");
    $stmt->execute([$companyId, $start, $end]);
    $outflows = array_merge($outflows, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $inflowTotal = array_sum(array_map(static fn(array $row): float => (float) $row['amount'], $inflows));
    $outflowTotal = array_sum(array_map(static fn(array $row): float => (float) $row['amount'], $outflows));

    return [
        'note' => 'Cash-basis view of recorded customer receipts, supplier payments, paid expenses and unallocated income receipts.',
        'inflow_rows' => $inflows,
        'inflow_total' => $inflowTotal,
        'outflow_rows' => $outflows,
        'outflow_total' => $outflowTotal,
        'net_cash_flow' => $inflowTotal - $outflowTotal,
    ];
}

function reportTrialBalance(PDO $pdo, int $companyId, string $start, string $end): array
{
    $stmt = $pdo->prepare("
        SELECT a.account_code, a.account_name, a.account_type,
               COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL THEN jl.debit_amount ELSE 0 END), 0) AS debit_total,
               COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL THEN jl.credit_amount ELSE 0 END), 0) AS credit_total
        FROM chart_of_accounts a
        LEFT JOIN journal_entry_lines jl ON jl.account_id = a.account_id
        LEFT JOIN journal_entries je ON je.entry_id = jl.entry_id
             AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
        WHERE a.company_id = ?
        GROUP BY a.account_id, a.account_code, a.account_name, a.account_type
        ORDER BY a.account_type, a.account_code
    ");
    $stmt->execute([$start, $end, $companyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalDebit = array_sum(array_map(static fn(array $row): float => (float) $row['debit_total'], $rows));
    $totalCredit = array_sum(array_map(static fn(array $row): float => (float) $row['credit_total'], $rows));

    return [
        'note' => 'Posted journal entry totals by account for the selected period.',
        'rows' => $rows,
        'total_debit' => $totalDebit,
        'total_credit' => $totalCredit,
    ];
}

function reportGeneralLedger(PDO $pdo, int $companyId, string $start, string $end): array
{
    $stmt = $pdo->prepare("
        SELECT je.entry_date, je.entry_number, je.description AS entry_description,
               a.account_code, a.account_name, jl.description,
               jl.debit_amount, jl.credit_amount
        FROM journal_entry_lines jl
        INNER JOIN journal_entries je ON je.entry_id = jl.entry_id
        LEFT JOIN chart_of_accounts a ON jl.account_id = a.account_id
        WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
        ORDER BY je.entry_date, je.entry_id, jl.sort_order, jl.line_id
        LIMIT 500
    ");
    $stmt->execute([$companyId, $start, $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalDebit = array_sum(array_map(static fn(array $row): float => (float) $row['debit_amount'], $rows));
    $totalCredit = array_sum(array_map(static fn(array $row): float => (float) $row['credit_amount'], $rows));

    return [
        'note' => 'Posted journal entry lines for the period (first 500 lines).',
        'rows' => $rows,
        'total_debit' => $totalDebit,
        'total_credit' => $totalCredit,
        'truncated' => count($rows) >= 500,
    ];
}

/**
 * What sold, at what margin, by whom and where - computed from the invoice
 * lines and the payment rows themselves.
 *
 * An invoice-level discount or tax is charged once for the whole bill, so a
 * breakdown by product or day can only show it by sharing it out. The share is
 * each line's own net against the bill's net before the header came off, which
 * is exactly what the customer was charged for that line - and it makes the
 * dimension rows add back up to the invoice totals.
 *
 * Money received is dated by payment, not by invoice: a payment against an
 * older bill is still cash this period. Outstanding, on the other hand, is the
 * unpaid balance of the invoices dated in this period.
 */
function reportSalesAnalytics(PDO $pdo, int $companyId, string $start, string $end, array $options = []): array
{
    $dimensions = reportAnalyticsDimensions();
    $groupBy = (string) ($options['group_by'] ?? 'day');
    if (!isset($dimensions[$groupBy])) {
        $groupBy = 'day';
    }
    $branchId = (int) ($options['branch_id'] ?? 0);
    $revenue = salesRevenueStatusSql('si.status');

    // Draft and cancelled bills are excluded from both the bill and the money
    // side, so a cancelled sale can never inflate either figure.
    $scope = "si.company_id = ? AND si.issue_date BETWEEN ? AND ? AND $revenue";
    $scopeParams = [$companyId, $start, $end];
    $scopeUserId = auth_sales_scope_user_id();
    if ($scopeUserId !== null) {
        $scope .= ' AND si.created_by = ?';
        $scopeParams[] = $scopeUserId;
    }
    if ($branchId > 0) {
        $scope .= ' AND si.branch_id = ?';
        $scopeParams[] = $branchId;
    }

    $baseRate = 'COALESCE(NULLIF(si.exchange_rate, 0), 1)';
    $lineNet = 'sil.line_total * ' . $baseRate . ' * COALESCE(1 - si.discount_amount / NULLIF(si.subtotal, 0), 1)';
    $lineTax = '(' . $lineNet . ') * COALESCE(si.tax_amount / NULLIF(si.subtotal - si.discount_amount, 0), 0)';

    // Bill-level totals: what was invoiced, the header discount and the tax.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS transactions,
               COALESCE(SUM(si.base_subtotal), 0) AS subtotal,
               COALESCE(SUM(si.base_discount_amount), 0) AS header_discounts,
               COALESCE(SUM(si.base_tax_amount), 0) AS tax,
               COALESCE(SUM(si.base_total_amount), 0) AS invoiced
        FROM sales_invoices si
        WHERE $scope
    ");
    $stmt->execute($scopeParams);
    $bill = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Line-level totals: units, gross, line discounts, cost and net of the
    // header discount. Net here reconciles with subtotal - header discount.
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(sil.quantity), 0) AS units,
               COALESCE(SUM(sil.quantity * sil.unit_price * {$baseRate}), 0) AS gross,
               COALESCE(SUM(sil.discount_amount * {$baseRate}), 0) AS line_discounts,
               COALESCE(SUM(sil.quantity * sil.unit_cost), 0) AS cogs,
               COALESCE(SUM($lineNet), 0) AS net
        FROM sales_invoice_items sil
        INNER JOIN sales_invoices si ON si.invoice_id = sil.invoice_id
        WHERE $scope
    ");
    $stmt->execute($scopeParams);
    $lines = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $transactions = (int) ($bill['transactions'] ?? 0);
    $invoiced = (float) ($bill['invoiced'] ?? 0);
    $headerDiscounts = (float) ($bill['header_discounts'] ?? 0);
    $tax = (float) ($bill['tax'] ?? 0);
    $units = (float) ($lines['units'] ?? 0);
    $gross = (float) ($lines['gross'] ?? 0);
    $lineDiscounts = (float) ($lines['line_discounts'] ?? 0);
    $cogs = (float) ($lines['cogs'] ?? 0);
    $net = (float) ($lines['net'] ?? 0);
    $discountTotal = $lineDiscounts + $headerDiscounts;
    $grossProfit = $net - $cogs;

    $summary = [
        'transactions' => $transactions,
        'units' => $units,
        'gross' => $gross,
        'line_discounts' => $lineDiscounts,
        'header_discounts' => $headerDiscounts,
        'discount_total' => $discountTotal,
        'discount_rate' => $gross > 0 ? $discountTotal / $gross * 100 : 0.0,
        'net' => $net,
        'tax' => $tax,
        'invoiced' => $invoiced,
        'cogs' => $cogs,
        'gross_profit' => $grossProfit,
        'margin' => $net > 0 ? $grossProfit / $net * 100 : 0.0,
        'markup' => $cogs > 0 ? $grossProfit / $cogs * 100 : 0.0,
        'average_sale' => $transactions > 0 ? $net / $transactions : 0.0,
    ];

    $select = [
        'day' => [
            'label' => "DATE_FORMAT(si.issue_date, '%Y-%m-%d')",
            'join' => '',
        ],
        'product' => [
            'label' => "COALESCE(p.name, sil.description, 'Off-catalogue item')",
            'join' => 'LEFT JOIN products p ON p.product_id = sil.product_id',
        ],
        'category' => [
            'label' => "COALESCE(c.name, 'Uncategorised')",
            'join' => 'LEFT JOIN products p ON p.product_id = sil.product_id
                       LEFT JOIN categories c ON c.category_id = p.category_id',
        ],
        'branch' => [
            'label' => "COALESCE(b.name, 'No branch recorded')",
            'join' => 'LEFT JOIN branches b ON b.branch_id = si.branch_id',
        ],
        'cashier' => [
            'label' => "COALESCE(u.full_name, 'Unknown cashier')",
            'join' => 'LEFT JOIN users u ON u.user_id = si.created_by',
        ],
    ];
    $dimension = $select[$groupBy];
    $order = $groupBy === 'day' ? 'label ASC' : 'net DESC';

    $stmt = $pdo->prepare("
        SELECT {$dimension['label']} AS label,
               COALESCE(SUM(sil.quantity), 0) AS units,
               COALESCE(SUM(sil.quantity * sil.unit_price * {$baseRate}), 0) AS gross,
               COALESCE(SUM(sil.discount_amount * {$baseRate}), 0) AS line_discounts,
               COALESCE(SUM(sil.line_total * {$baseRate}) - SUM($lineNet), 0) AS header_discounts,
               COALESCE(SUM($lineNet), 0) AS net,
               COALESCE(SUM($lineTax), 0) AS tax,
               COALESCE(SUM(sil.quantity * sil.unit_cost), 0) AS cogs,
               COUNT(DISTINCT sil.invoice_id) AS transactions
        FROM sales_invoice_items sil
        INNER JOIN sales_invoices si ON si.invoice_id = sil.invoice_id
        {$dimension['join']}
        WHERE $scope
        GROUP BY label
        ORDER BY $order
        LIMIT 400
    ");
    $stmt->execute($scopeParams);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowNet = (float) $row['net'];
        $rowCogs = (float) $row['cogs'];
        $rowProfit = $rowNet - $rowCogs;
        $rowLineDiscounts = (float) $row['line_discounts'];
        $rowHeaderDiscounts = (float) $row['header_discounts'];
        $rows[] = [
            'label' => (string) $row['label'],
            'transactions' => (int) $row['transactions'],
            'units' => (float) $row['units'],
            'gross' => (float) $row['gross'],
            'line_discounts' => $rowLineDiscounts,
            'header_discounts' => $rowHeaderDiscounts,
            'discount_total' => $rowLineDiscounts + $rowHeaderDiscounts,
            'net' => $rowNet,
            'tax' => (float) $row['tax'],
            'cogs' => $rowCogs,
            'gross_profit' => $rowProfit,
            'margin' => $rowNet > 0 ? $rowProfit / $rowNet * 100 : 0.0,
        ];
    }

    // The tender mix comes from the payment rows, never the invoice header: a
    // split sale says 'split' there while the real methods are on these rows.
    $tenderScope = "p.company_id = ? AND p.payment_date BETWEEN ? AND ? AND $revenue";
    $tenderParams = [$companyId, $start, $end];
    if ($branchId > 0) {
        $tenderScope .= ' AND si.branch_id = ?';
        $tenderParams[] = $branchId;
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(m.name, CONCAT('Payment method (', REPLACE(p.payment_method, '_', ' '), ')')) AS label,
               COALESCE(m.is_cash, CASE WHEN p.payment_method = 'cash' THEN 1 ELSE 0 END) AS is_cash,
               COALESCE(SUM(p.base_amount), 0) AS amount,
               COUNT(*) AS payment_count
        FROM customer_payments p
        INNER JOIN sales_invoices si ON si.invoice_id = p.invoice_id
        LEFT JOIN payment_methods m ON m.code = p.payment_method AND m.company_id = p.company_id
        WHERE $tenderScope
        GROUP BY label, is_cash
        ORDER BY amount DESC
    ");
    $stmt->execute($tenderParams);

    $tenders = [];
    $tenderTotal = 0.0;
    $cashTotal = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $amount = (float) $row['amount'];
        $isCash = (int) $row['is_cash'] === 1;
        $tenders[] = [
            'label' => (string) $row['label'],
            'is_cash' => $isCash,
            'amount' => $amount,
            'payment_count' => (int) $row['payment_count'],
        ];
        $tenderTotal += $amount;
        if ($isCash) {
            $cashTotal += $amount;
        }
    }

    // Outstanding is this period's bills minus every payment ever applied to
    // them, floored per invoice so an overpayment cannot hide another debt.
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(si.base_amount_owed), 0) AS outstanding,
               COALESCE(SUM(CASE WHEN si.base_amount_owed > 0.005 THEN 1 ELSE 0 END), 0) AS unpaid_invoices,
               COALESCE(SUM(COALESCE(pay.paid, 0)), 0) AS paid_on_period_invoices
        FROM sales_invoices si
        LEFT JOIN (
            SELECT invoice_id, SUM(base_amount) AS paid
            FROM customer_payments
            WHERE company_id = ?
            GROUP BY invoice_id
        ) pay ON pay.invoice_id = si.invoice_id
        WHERE $scope
    ");
    $stmt->execute(array_merge([$companyId], $scopeParams));
    $collection = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'note' => 'Computed from invoice lines and payment rows for sales that are not draft or cancelled. '
            . 'Bill-level discounts and tax are shared across lines in proportion to their value, so the rows '
            . 'add back up to the invoice totals. Money received is dated by payment and can include payments '
            . 'against earlier invoices; outstanding covers the invoices dated in this period.',
        'group_by' => $groupBy,
        'group_by_label' => $dimensions[$groupBy],
        'branch_id' => $branchId,
        'summary' => $summary,
        'rows' => $rows,
        'truncated' => count($rows) >= 400,
        'tenders' => $tenders,
        'tender_total' => $tenderTotal,
        'cash_total' => $cashTotal,
        'non_cash_total' => $tenderTotal - $cashTotal,
        'collection' => [
            'collected_in_period' => $tenderTotal,
            'invoiced' => $invoiced,
            'outstanding' => (float) ($collection['outstanding'] ?? 0),
            'unpaid_invoices' => (int) ($collection['unpaid_invoices'] ?? 0),
            'paid_on_period_invoices' => (float) ($collection['paid_on_period_invoices'] ?? 0),
        ],
    ];
}
