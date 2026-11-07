<?php
/**
 * Service-layer tests for Phase E2: deep sales analytics
 * (includes/report_functions.php: reportSalesAnalytics()).
 *
 * Run: php scripts/test_sales_analytics.php
 *
 * The run creates its own branch, products, customer and invoices, and deletes
 * exactly those. Every figure is checked against arithmetic the test can do
 * itself: net sales reconcile with the bill, the rows add back up to the
 * summary, and the tender mix is read from payment rows rather than the
 * invoice header.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/report_functions.php';
require_once APP_ROOT . '/includes/dashboard_functions.php';

restore_error_handler();
restore_exception_handler();
ini_set('display_errors', '1');
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, "FATAL: {$error['message']} at {$error['file']}:{$error['line']}\n");
    }
});

$pdo = db();
$companyId = 1;
$pass = 0;
$fail = 0;
$branchCode = 'ANA-TEST';
$customerName = 'Analytics Test Customer';

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  $label\n";
    } else {
        $fail++;
        echo "FAIL  $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}

function sqlValue(PDO $pdo, string $sql): mixed
{
    return $pdo->query($sql)->fetchColumn();
}

function money(float $value): float
{
    return round($value, 4);
}

function near(float $a, float $b, float $tolerance = 0.005): bool
{
    return abs($a - $b) <= $tolerance;
}

$start = date('Y-m-01');
$end = date('Y-m-d');
$lastStart = date('Y-m-01', strtotime('first day of last month'));
$lastEnd = date('Y-m-t', strtotime('first day of last month'));

// ===========================================================================
// What already exists, so it can be put back
// ===========================================================================

$mark = [
    'invoice_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(invoice_id), 0) FROM sales_invoices'),
    'payment_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(payment_id), 0) FROM customer_payments'),
    'branch_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(branch_id), 0) FROM branches'),
    'product_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(product_id), 0) FROM products'),
];

$teardownDone = false;
$teardown = static function () use ($pdo, $companyId, $branchCode, $customerName, $mark, &$teardownDone): void {
    if ($teardownDone) {
        return;
    }
    $teardownDone = true;
    $pdo->beginTransaction();
    try {
        $invoiceIds = $pdo->query("SELECT invoice_id FROM sales_invoices WHERE company_id = $companyId
                                   AND invoice_id > {$mark['invoice_id']}")->fetchAll(PDO::FETCH_COLUMN);
        if ($invoiceIds !== []) {
            $in = implode(',', array_map('intval', $invoiceIds));
            $pdo->exec("DELETE FROM customer_payments WHERE invoice_id IN ($in)");
            $pdo->exec("DELETE FROM sales_invoice_items WHERE invoice_id IN ($in)");
            $pdo->exec("DELETE FROM inventory_transactions WHERE reference_type IN ('sales_invoice', 'sale_cancellation')
                        AND reference_id IN ($in)");
            $pdo->exec("DELETE FROM sales_invoices WHERE invoice_id IN ($in)");
        }

        $pdo->prepare("DELETE FROM product_locations WHERE product_id IN
                       (SELECT product_id FROM products WHERE sku LIKE 'ANA-TEST-%')")->execute();
        $pdo->prepare("DELETE FROM products WHERE sku LIKE 'ANA-TEST-%'")->execute();
        $pdo->prepare('DELETE FROM customers WHERE name = ?')->execute([$customerName]);
        $pdo->prepare('DELETE FROM branches WHERE code = ?')->execute([$branchCode]);
        $pdo->commit();

        $left = (int) $pdo->query("SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId
                                   AND invoice_id > {$mark['invoice_id']}")->fetchColumn();
        echo 'TEARDOWN  leftover test invoices ' . $left . "\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo 'TEARDOWN FAILED: ' . $e->getMessage() . "\n";
    }
};
register_shutdown_function($teardown);

// ===========================================================================
// Fixtures
// ===========================================================================

$pdo->prepare("DELETE FROM product_locations WHERE product_id IN
               (SELECT product_id FROM products WHERE sku LIKE 'ANA-TEST-%')")->execute();
$pdo->prepare("DELETE FROM products WHERE sku LIKE 'ANA-TEST-%'")->execute();
$pdo->prepare('DELETE FROM customers WHERE name = ?')->execute([$customerName]);
$pdo->prepare('DELETE FROM branches WHERE code = ?')->execute([$branchCode]);

$pdo->prepare('INSERT INTO branches (company_id, name, code, is_active, sort_order) VALUES (?, ?, ?, 1, 94)')
    ->execute([$companyId, 'Analytics Test Branch', $branchCode]);
$branchId = (int) $pdo->lastInsertId();

$categoryId = (int) sqlValue($pdo, "SELECT category_id FROM categories WHERE company_id = $companyId ORDER BY category_id LIMIT 1");
$categoryName = (string) sqlValue($pdo, "SELECT name FROM categories WHERE category_id = $categoryId");

$products = [];
foreach ([
    ['A', 'Analytics Widget A', 100.00, 40.00],
    ['B', 'Analytics Widget B', 50.00, 20.00],
] as [$key, $name, $price, $cost]) {
    $pdo->prepare('INSERT INTO products (company_id, category_id, sku, name, sales_price, cost_price,
                   track_inventory, manage_stock, is_active) VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1)')
        ->execute([$companyId, $categoryId, 'ANA-TEST-' . $key, $name, $price, $cost]);
    $productId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO product_locations (company_id, product_id, branch_id, on_hand_quantity,
                   reserved_quantity, available_quantity, on_hand_value) VALUES (?, ?, ?, 100, 0, 100, ?)')
        ->execute([$companyId, $productId, $branchId, 100 * $cost]);
    $products[$key] = $productId;
}

$pdo->prepare("INSERT INTO customers (company_id, name, email, credit_limit, credit_used, payment_terms, payment_status, is_active)
               VALUES (?, ?, 'analytics.test@example.test', 0, 0, 'Net 0', 'paid', 1)")
    ->execute([$companyId, $customerName]);
$customerId = (int) $pdo->lastInsertId();

$adminId = (int) sqlValue($pdo, "SELECT user_id FROM users WHERE role = 'admin' AND company_id = $companyId ORDER BY user_id LIMIT 1");
$adminName = (string) sqlValue($pdo, "SELECT full_name FROM users WHERE user_id = $adminId");
$_SESSION['user_id'] = $adminId;
$_SESSION['company_id'] = $companyId;
$_SESSION['branch_id'] = $branchId;
$_SESSION['user_role'] = 'admin';

$makeSale = static function (array $lines, array $extra = []) use ($pdo, $companyId, $branchId, $customerId, $adminId): array {
    $data = array_merge([
        'company_id' => $companyId,
        'customer_id' => $customerId,
        'branch_id' => $branchId,
        'created_by' => $adminId,
        'issue_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d'),
        'tax_rate' => 0,
        'lines' => $lines,
    ], $extra);
    $result = createSalesInvoice($pdo, $data);
    if (!($result['success'] ?? false)) {
        throw new RuntimeException('Fixture sale failed: ' . ($result['message'] ?? '?'));
    }
    return $result;
};

// One discounted, taxed two-line sale paid in cash.
$sale1 = $makeSale(
    [
        ['product_id' => $products['A'], 'quantity' => 2],
        ['product_id' => $products['B'], 'quantity' => 1],
    ],
    [
        'discount_percent' => 10,
        'tax_rate' => 0.10,
        'payments' => [['method' => 'cash', 'amount' => 247.50]],
    ]
);
// One unpaid sale, so outstanding has something to report.
$sale2 = $makeSale([['product_id' => $products['A'], 'quantity' => 1]], ['status' => 'pending']);
// One split tender: the header says split, the payment rows know better.
$sale3 = $makeSale(
    [['product_id' => $products['B'], 'quantity' => 2]],
    ['payments' => [
        ['method' => 'cash', 'amount' => 40.00],
        ['method' => 'bank_transfer', 'amount' => 60.00],
    ]]
);
// Draft and cancelled bills must not become revenue.
$draft = $makeSale([['product_id' => $products['A'], 'quantity' => 1]], ['status' => 'draft']);
$cancelled = $makeSale([['product_id' => $products['A'], 'quantity' => 1]], ['status' => 'pending']);
$cancelOk = updateSaleStatus($pdo, (int) $cancelled['invoice_id'], 'cancelled');

echo "fixture: sales {$sale1['invoice_id']}, {$sale2['invoice_id']}, {$sale3['invoice_id']}; "
    . "draft {$draft['invoice_id']}, cancelled {$cancelled['invoice_id']} (" . ($cancelOk ? 'ok' : 'failed') . ")\n";

$analytics = static function (string $groupBy = 'day', int $branch = 0, ?string $from = null, ?string $to = null)
use ($pdo, $companyId, $start, $end): array {
    return reportSalesAnalytics($pdo, $companyId, $from ?? $start, $to ?? $end, [
        'group_by' => $groupBy,
        'branch_id' => $branch,
    ]);
};

// ===========================================================================
// 1. What counts as a sale
// ===========================================================================

echo "\n== 1. what counts as a sale ==\n";

$branch = $analytics('day', $branchId);
$summary = $branch['summary'];

check('three real bills are counted, the draft and the cancelled one are not',
    $summary['transactions'] === 3, (string) $summary['transactions']);
check('the cancelled bill really was cancelled by the sales service', $cancelOk === true);
check('the draft and cancelled bills contributed no money',
    near((float) $summary['invoiced'], 447.50), (string) $summary['invoiced']);
check('the shared rule excludes draft and cancelled, and nothing else',
    salesRevenueStatusSql() === "status IN ('pending', 'partial', 'paid')", salesRevenueStatusSql());

$companyWide = reportSalesAnalytics($pdo, $companyId, $start, $end);
$dash = getDashboardSummary($pdo, $companyId);
check('the dashboard and the analytics report agree on this month\'s sales',
    near((float) $dash['total_sales'], (float) $companyWide['summary']['invoiced']),
    json_encode(['dashboard' => $dash['total_sales'], 'analytics' => $companyWide['summary']['invoiced']]));
$trend = getDashboardMonthlyTrend($pdo, $companyId, 6);
$lastTrend = (float) end($trend['sales']);
check('the trend chart agrees too, and a draft/cancelled sale cannot lift it',
    near($lastTrend, (float) $companyWide['summary']['invoiced']),
    json_encode(['trend' => $lastTrend, 'analytics' => $companyWide['summary']['invoiced']]));

// ===========================================================================
// 2. The bill reconciles, line for line
// ===========================================================================

echo "\n== 2. the bill reconciles, line for line ==\n";

check('net sales are the bill minus its header discount',
    near((float) $summary['net'], 425.00) && near((float) $summary['header_discounts'], 25.00),
    json_encode(['net' => $summary['net'], 'header_discount' => $summary['header_discounts']]));
check('net plus tax is exactly what was invoiced',
    near((float) $summary['net'] + (float) $summary['tax'], (float) $summary['invoiced']),
    json_encode(['net' => $summary['net'], 'tax' => $summary['tax'], 'invoiced' => $summary['invoiced']]));
check('gross sales, line discounts and the header discount tell one story',
    near((float) $summary['gross'] - (float) $summary['line_discounts'] - (float) $summary['header_discounts'],
        (float) $summary['net'])
    && near((float) $summary['discount_rate'], 25 / 450 * 100),
    json_encode(['gross' => $summary['gross'], 'discount_rate' => $summary['discount_rate']]));
check('cost of goods and gross profit come from the lines',
    near((float) $summary['cogs'], 180.00) && near((float) $summary['gross_profit'], 245.00)
    && near((float) $summary['margin'], 245 / 425 * 100),
    json_encode(['cogs' => $summary['cogs'], 'profit' => $summary['gross_profit'], 'margin' => $summary['margin']]));
check('units, transactions and the average sale are consistent',
    near((float) $summary['units'], 6) && $summary['transactions'] === 3
    && near((float) $summary['average_sale'], 425 / 3),
    json_encode(['units' => $summary['units'], 'avg' => $summary['average_sale']]));

foreach (array_keys(reportAnalyticsDimensions()) as $dimension) {
    $data = $analytics($dimension, $branchId);
    $rowNet = array_sum(array_map(static fn (array $r): float => (float) $r['net'], $data['rows']));
    $rowTax = array_sum(array_map(static fn (array $r): float => (float) $r['tax'], $data['rows']));
    $rowUnits = array_sum(array_map(static fn (array $r): float => (float) $r['units'], $data['rows']));
    $rowCogs = array_sum(array_map(static fn (array $r): float => (float) $r['cogs'], $data['rows']));
    $rowGross = array_sum(array_map(static fn (array $r): float => (float) $r['gross'], $data['rows']));
    $rowDiscounts = array_sum(array_map(static fn (array $r): float => (float) $r['discount_total'], $data['rows']));
    check("by $dimension the rows add back up to the summary",
        near($rowNet, (float) $summary['net'])
        && near($rowTax, (float) $summary['tax'])
        && near($rowUnits, (float) $summary['units'])
        && near($rowCogs, (float) $summary['cogs'])
        && near($rowGross, (float) $summary['gross'])
        && near($rowDiscounts, (float) $summary['discount_total']),
        json_encode(['net' => [$rowNet, $summary['net']], 'tax' => [$rowTax, $summary['tax']],
            'units' => [$rowUnits, $summary['units']]]));
}

// ===========================================================================
// 3. The dimensions say what they claim
// ===========================================================================

echo "\n== 3. the dimensions say what they claim ==\n";

$byProduct = $analytics('product', $branchId);
$productRows = [];
foreach ($byProduct['rows'] as $row) {
    $productRows[$row['label']] = $row;
}
check('a product row carries the units, net and margin of that product',
    isset($productRows['Analytics Widget A'], $productRows['Analytics Widget B'])
    && near((float) $productRows['Analytics Widget A']['units'], 3)
    && near((float) $productRows['Analytics Widget A']['net'], 280.00)
    && near((float) $productRows['Analytics Widget A']['cogs'], 120.00)
    && near((float) $productRows['Analytics Widget A']['margin'], 160 / 280 * 100)
    && near((float) $productRows['Analytics Widget B']['units'], 3)
    && near((float) $productRows['Analytics Widget B']['net'], 145.00),
    json_encode(array_values($productRows)));
check('the header discount and tax are shared across the two lines, not dumped on one',
    near((float) $productRows['Analytics Widget A']['header_discounts'], 20.00)
    && near((float) $productRows['Analytics Widget A']['tax'], 18.00)
    && near((float) $productRows['Analytics Widget B']['header_discounts'], 5.00)
    && near((float) $productRows['Analytics Widget B']['tax'], 4.50),
    json_encode([
        'A' => [$productRows['Analytics Widget A']['header_discounts'], $productRows['Analytics Widget A']['tax']],
        'B' => [$productRows['Analytics Widget B']['header_discounts'], $productRows['Analytics Widget B']['tax']],
    ]));

$byCategory = $analytics('category', $branchId);
check('category rows use the catalogue name, not the id',
    count($byCategory['rows']) === 1 && $byCategory['rows'][0]['label'] === $categoryName,
    json_encode(array_column($byCategory['rows'], 'label')));
$byBranch = $analytics('branch', $branchId);
check('branch rows name the branch the sale was taken at',
    count($byBranch['rows']) === 1 && $byBranch['rows'][0]['label'] === 'Analytics Test Branch',
    json_encode(array_column($byBranch['rows'], 'label')));
$byCashier = $analytics('cashier', $branchId);
check('cashier rows name the user who created the bill',
    count($byCashier['rows']) === 1 && $byCashier['rows'][0]['label'] === $adminName,
    json_encode(array_column($byCashier['rows'], 'label')));
$byDay = $analytics('day', $branchId);
check('day rows are dated and ordered oldest first',
    count($byDay['rows']) === 1 && $byDay['rows'][0]['label'] === date('Y-m-d'),
    json_encode(array_column($byDay['rows'], 'label')));
check('an unknown dimension falls back to day instead of breaking the report',
    $analytics('not-a-dimension', $branchId)['group_by'] === 'day');

$otherBranchId = (int) sqlValue($pdo, "SELECT branch_id FROM branches WHERE company_id = $companyId AND branch_id <> $branchId ORDER BY branch_id LIMIT 1");
$otherBranch = $analytics('branch', $otherBranchId);
check('the branch filter leaves out every other branch',
    !in_array('Analytics Test Branch', array_column($otherBranch['rows'], 'label'), true)
    && near((float) $otherBranch['summary']['invoiced'], (float) $analytics('day', 0)['summary']['invoiced'] - (float) $analytics('day', $branchId)['summary']['invoiced']),
    json_encode(['rows' => array_column($otherBranch['rows'], 'label'),
        'invoiced' => $otherBranch['summary']['invoiced']]));
check('filtering by the test branch hides the rest of the company',
    $analytics('day', $branchId)['summary']['transactions'] === 3
    && $analytics('day', 0)['summary']['transactions'] > 3,
    json_encode(['branch' => $analytics('day', $branchId)['summary']['transactions'],
        'company' => $analytics('day', 0)['summary']['transactions']]));

// ===========================================================================
// 4. The money was read from the payment rows
// ===========================================================================

echo "\n== 4. the money was read from the payment rows ==\n";

$splitHeader = (string) sqlValue($pdo, "SELECT payment_method FROM sales_invoices WHERE invoice_id = " . (int) $sale3['invoice_id']);
check('the split sale really does say split on its header', $splitHeader === 'split', $splitHeader);
check('yet the tender mix lists both methods, because it reads the payment rows',
    count($branch['tenders']) === 2
    && near((float) $branch['tender_total'], 347.50)
    && near((float) $branch['cash_total'], 287.50)
    && near((float) $branch['non_cash_total'], 60.00),
    json_encode(['tenders' => $branch['tenders'], 'total' => $branch['tender_total']]));
$cashTender = null;
foreach ($branch['tenders'] as $tender) {
    if ($tender['is_cash']) {
        $cashTender = $tender;
    }
}
check('cash is flagged as cash and the transfer is not',
    $cashTender !== null && near((float) $cashTender['amount'], 287.50)
    && count(array_filter($branch['tenders'], static fn (array $t): bool => !$t['is_cash'])) === 1,
    json_encode($branch['tenders']));
check('collected in the period is the sum of the payment rows, not the header',
    near((float) $branch['collection']['collected_in_period'], 347.50),
    (string) $branch['collection']['collected_in_period']);
check('outstanding is the unpaid bill and its count',
    near((float) $branch['collection']['outstanding'], 100.00)
    && $branch['collection']['unpaid_invoices'] === 1,
    json_encode($branch['collection']));

// ===========================================================================
// 5. A payment belongs to the day it was taken
// ===========================================================================

echo "\n== 5. a payment belongs to the day it was taken ==\n";

$beforeCollected = (float) $analytics('day', $branchId)['collection']['collected_in_period'];
$beforeInvoiced = (float) $analytics('day', $branchId)['collection']['invoiced'];
$oldSale = $makeSale(
    [['product_id' => $products['B'], 'quantity' => 1]],
    [
        'issue_date' => $lastStart,
        'due_date' => $lastEnd,
        'payments' => [['method' => 'cash', 'amount' => 50.00]],
    ]
);
$afterCurrent = $analytics('day', $branchId);
$lastPeriod = $analytics('day', $branchId, $lastStart, $lastEnd);
check('a payment against an older bill is cash this period',
    near((float) $afterCurrent['collection']['collected_in_period'], $beforeCollected + 50.00),
    json_encode(['before' => $beforeCollected, 'after' => $afterCurrent['collection']['collected_in_period']]));
check('but the older bill is not invoiced again this period',
    near((float) $afterCurrent['collection']['invoiced'], $beforeInvoiced)
    && $afterCurrent['summary']['transactions'] === 3,
    json_encode(['before' => $beforeInvoiced, 'after' => $afterCurrent['collection']['invoiced']]));
check('in its own period the older bill is invoiced and already settled',
    $lastPeriod['summary']['transactions'] === 1
    && near((float) $lastPeriod['summary']['invoiced'], 50.00)
    && near((float) $lastPeriod['collection']['outstanding'], 0.00)
    && near((float) $lastPeriod['collection']['collected_in_period'], 0.00),
    json_encode(['summary' => $lastPeriod['summary'], 'collection' => $lastPeriod['collection']]));

// ===========================================================================
// 6. What the report refuses to invent
// ===========================================================================

echo "\n== 6. what the report refuses to invent ==\n";

$empty = $analytics('day', $branchId, '1999-01-01', '1999-01-02');
check('an empty period returns zeroes and no rows, not errors',
    $empty['summary']['transactions'] === 0 && $empty['rows'] === []
    && near((float) $empty['summary']['net'], 0.0)
    && near((float) $empty['summary']['margin'], 0.0)
    && near((float) $empty['summary']['average_sale'], 0.0),
    json_encode($empty['summary']));
check('an empty period has no tender rows and nothing outstanding',
    $empty['tenders'] === [] && near((float) $empty['collection']['outstanding'], 0.0),
    json_encode($empty['collection']));

echo "\n== teardown evidence ==\n";

$ours = [
    'invoices' => (int) sqlValue($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId
                                        AND invoice_id > {$mark['invoice_id']}"),
    'products' => (int) sqlValue($pdo, "SELECT COUNT(*) FROM products WHERE sku LIKE 'ANA-TEST-%'"),
    'branches' => (int) sqlValue($pdo, "SELECT COUNT(*) FROM branches WHERE code = '$branchCode'"),
];
check('the sweep deletes exactly this run: six bills, two products, one branch',
    $ours['invoices'] === 6 && $ours['products'] === 2 && $ours['branches'] === 1,
    json_encode($ours));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
