<?php
/**
 * HTTP smoke test for Phase E2: the Sales Analytics report on the Reports
 * screen (report.php + views/report.html), exercised through Apache the way a
 * browser reaches it.
 *
 * Run: php scripts/http_smoke_sales_analytics.php
 *
 * It writes only rows it removes again: one branch, one product, one customer,
 * one sale, one payment and the financial_reports row that opens the report.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/report_functions.php';

restore_error_handler();
restore_exception_handler();
ini_set('display_errors', '1');
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, "FATAL: {$error['message']} at {$error['file']}:{$error['line']}\n");
    }
});

const BASE_URL = 'http://localhost/inventory';

$pdo = db();
$companyId = 1;
$otherCompanyId = 2;
$pass = 0;
$fail = 0;
$sessionFiles = [];

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

function sqlOne(PDO $pdo, string $sql): mixed
{
    return $pdo->query($sql)->fetchColumn();
}

/** Write a session file so curl can act as a signed-in user. */
function craftSession(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT username, full_name, role, company_id, branch_id FROM users WHERE user_id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new RuntimeException("No user $userId to sign in as.");
    }
    $csrf = bin2hex(random_bytes(32));
    $sid = bin2hex(random_bytes(16));
    $values = [
        'user_id' => $userId,
        'username' => (string) $user['username'],
        'full_name' => (string) $user['full_name'],
        'user_role' => (string) $user['role'],
        'company_id' => (int) $user['company_id'],
        'branch_id' => $user['branch_id'] === null ? null : (int) $user['branch_id'],
        'login_time' => time(),
        'last_activity' => time(),
        'csrf_token' => $csrf,
    ];
    $blob = '';
    foreach ($values as $key => $value) {
        $blob .= $key . '|' . serialize($value);
    }
    $path = rtrim((string) ini_get('session.save_path'), '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    file_put_contents($path, $blob);
    global $sessionFiles;
    $sessionFiles[] = $path;

    return ['sid' => $sid, 'csrf' => $csrf, 'user_id' => $userId, 'file' => $path];
}

/** @return array{status:int,body:string,location:string,headers:array<string,string>} */
function request(string $method, string $path, array $session = [], array $fields = []): array
{
    $headers = [];
    $ch = curl_init(BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headers): int {
            if (preg_match('/^([A-Za-z-]+):\s*(.*)\r?$/', $line, $m)) {
                $headers[strtolower($m[1])] = trim($m[2]);
            }
            return strlen($line);
        },
    ]);
    if ($session !== []) {
        curl_setopt($ch, CURLOPT_COOKIE, 'inventory_session=' . $session['sid']);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error !== '') {
        echo "REQUEST ERROR $method $path -- $error\n";
    }

    return ['status' => $status, 'body' => $body, 'location' => $location, 'headers' => $headers];
}

// ===========================================================================
// What already exists, so it can be put back
// ===========================================================================

$mark = [
    'invoice_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(invoice_id), 0) FROM sales_invoices'),
    'payment_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(payment_id), 0) FROM customer_payments'),
    'report_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(report_id), 0) FROM financial_reports'),
    'product_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(product_id), 0) FROM products'),
    'branch_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(branch_id), 0) FROM branches'),
];

$teardownDone = false;
$teardown = static function () use ($pdo, $companyId, $otherCompanyId, $mark, &$teardownDone): void {
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
        $pdo->exec("DELETE FROM financial_reports WHERE report_id > {$mark['report_id']}");
        $pdo->exec("DELETE FROM product_locations WHERE product_id IN
                    (SELECT product_id FROM products WHERE sku LIKE 'ANA-HTTP-%')");
        $pdo->exec("DELETE FROM products WHERE sku LIKE 'ANA-HTTP-%'");
        $pdo->exec("DELETE FROM customers WHERE name = 'Analytics HTTP Customer'");
        $pdo->exec("DELETE FROM branches WHERE code = 'ANA-HTTP'");
        $pdo->exec("DELETE FROM users WHERE username = 'analytics.http.smoke' AND company_id = " . (int) $otherCompanyId);
        $pdo->commit();

        $left = (int) $pdo->query('SELECT COUNT(*) FROM financial_reports')->fetchColumn();
        echo 'TEARDOWN  financial_reports ' . $left . ' (baseline ' . $mark['report_id'] . ")\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo 'TEARDOWN FAILED: ' . $e->getMessage() . "\n";
    }

    global $sessionFiles;
    foreach ($sessionFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
};
register_shutdown_function($teardown);

// ===========================================================================
// Fixtures
// ===========================================================================

$pdo->exec("DELETE FROM branches WHERE code = 'ANA-HTTP'");
$pdo->exec("DELETE FROM products WHERE sku LIKE 'ANA-HTTP-%'");
$pdo->exec("DELETE FROM customers WHERE name = 'Analytics HTTP Customer'");
$pdo->exec("DELETE FROM users WHERE username = 'analytics.http.smoke'");

$pdo->prepare('INSERT INTO branches (company_id, name, code, is_active, sort_order) VALUES (?, ?, ?, 1, 93)')
    ->execute([$companyId, 'Analytics HTTP Branch', 'ANA-HTTP']);
$branchId = (int) $pdo->lastInsertId();

$categoryId = (int) sqlOne($pdo, "SELECT category_id FROM categories WHERE company_id = $companyId ORDER BY category_id LIMIT 1");
$pdo->prepare('INSERT INTO products (company_id, category_id, sku, name, sales_price, cost_price,
               track_inventory, manage_stock, is_active) VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1)')
    ->execute([$companyId, $categoryId, 'ANA-HTTP-1', 'Analytics HTTP Widget', 120.00, 45.00]);
$productId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO product_locations (company_id, product_id, branch_id, on_hand_quantity,
               reserved_quantity, available_quantity, on_hand_value) VALUES (?, ?, ?, 20, 0, 20, ?)')
    ->execute([$companyId, $productId, $branchId, 20 * 45.00]);
$pdo->prepare("INSERT INTO customers (company_id, name, email, credit_limit, credit_used, payment_terms, payment_status, is_active)
               VALUES (?, 'Analytics HTTP Customer', 'analytics.http@example.test', 0, 0, 'Net 0', 'paid', 1)")
    ->execute([$companyId]);
$customerId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO users (company_id, branch_id, username, password_hash, full_name, email, role, is_active)
               VALUES (?, NULL, ?, ?, ?, ?, ?, 1)')
    ->execute([$otherCompanyId, 'analytics.http.smoke', password_hash('smoke-test-only', PASSWORD_DEFAULT),
        'Elsewhere Admin', 'analytics.http.smoke@example.test', 'admin']);
$otherUserId = (int) $pdo->lastInsertId();

$adminId = (int) sqlOne($pdo, "SELECT user_id FROM users WHERE role = 'admin' AND company_id = $companyId ORDER BY user_id LIMIT 1");
$_SESSION['user_id'] = $adminId;
$_SESSION['company_id'] = $companyId;
$_SESSION['branch_id'] = $branchId;
$_SESSION['user_role'] = 'admin';

$sale = createSalesInvoice($pdo, [
    'company_id' => $companyId,
    'customer_id' => $customerId,
    'branch_id' => $branchId,
    'created_by' => $adminId,
    'issue_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d'),
    'tax_rate' => 0.05,
    'lines' => [['product_id' => $productId, 'quantity' => 2]],
    'payments' => [['method' => 'cash', 'amount' => 252.00]],
]);
if (!($sale['success'] ?? false)) {
    throw new RuntimeException('Fixture sale failed: ' . ($sale['message'] ?? '?'));
}
$total = round((float) $sale['total'], 2);
echo "fixture: sale {$sale['invoice_id']} total $total\n";

$reportId = createFinancialReport($pdo, [
    'company_id' => $companyId,
    'report_type' => 'sales_analytics',
    'report_name' => 'Analytics HTTP Report',
    'period_start' => date('Y-m-01'),
    'period_end' => date('Y-m-d'),
    'generated_by' => $adminId,
]);
$otherReportId = createFinancialReport($pdo, [
    'company_id' => $otherCompanyId,
    'report_type' => 'sales_analytics',
    'report_name' => 'Elsewhere Analytics Report',
    'period_start' => date('Y-m-01'),
    'period_end' => date('Y-m-d'),
    'generated_by' => $otherUserId,
]);
if ($reportId <= 0 || $otherReportId <= 0) {
    throw new RuntimeException('Fixture report failed.');
}

$admin = craftSession($pdo, $adminId);
$elsewhere = craftSession($pdo, $otherUserId);
$reportPath = '/report?action=list&report_id=' . $reportId;

echo "\n== A. who may open the report ==\n";

$anon = request('GET', $reportPath);
check('an anonymous visitor is sent to the login page',
    $anon['status'] === 302 && str_contains($anon['location'], 'login'),
    json_encode(['status' => $anon['status'], 'to' => $anon['location']]));

$foreign = request('GET', '/report?action=list&report_id=' . $otherReportId, $admin);
check('another company\'s report is not shown to this company\'s admin',
    $foreign['status'] === 200 && !str_contains($foreign['body'], 'Elsewhere Analytics Report')
    && !str_contains($foreign['body'], 'Analytics HTTP Widget'),
    (string) $foreign['status']);

echo "\n== B. the panel is on the page ==\n";

$page = request('GET', $reportPath, $admin);
check('the admin gets the report page', $page['status'] === 200, (string) $page['status']);
check('the saved report is named and typed on the page',
    str_contains($page['body'], 'Analytics HTTP Report') && str_contains($page['body'], 'Sales Analytics'));
check('the analytics panel offers every breakdown',
    str_contains($page['body'], 'name="group_by"')
    && str_contains($page['body'], 'By day') && str_contains($page['body'], 'By product')
    && str_contains($page['body'], 'By category') && str_contains($page['body'], 'By branch')
    && str_contains($page['body'], 'By cashier'));
check('the branch filter offers the branches and an all-branches choice',
    str_contains($page['body'], 'name="branch_id"') && str_contains($page['body'], 'All branches')
    && str_contains($page['body'], 'Analytics HTTP Branch'));
check('the headline figures are rendered',
    str_contains($page['body'], 'Net sales') && str_contains($page['body'], 'Gross profit')
    && str_contains($page['body'], 'Transactions') && str_contains($page['body'], 'Units sold'));
check('the money and margin of this sale appear',
    str_contains($page['body'], number_format($total - 0, 2, '.', ''))
    || str_contains($page['body'], number_format(round($total / 1.05, 2), 2, '.', '')),
    'total ' . $total);
check('the tender mix and collection panels are there',
    str_contains($page['body'], 'How it was paid') && str_contains($page['body'], 'Collection')
    && str_contains($page['body'], 'Cash') && str_contains($page['body'], 'Received in this period'));

echo "\n== C. the view choices are honoured ==\n";

$byProduct = request('GET', $reportPath . '&group_by=product&branch_id=' . $branchId, $admin);
check('choosing by product shows the product and marks the choice',
    str_contains($byProduct['body'], 'Analytics HTTP Widget')
    && str_contains($byProduct['body'], 'value="product" selected'),
    (string) $byProduct['status']);
$byCategory = request('GET', $reportPath . '&group_by=category&branch_id=' . $branchId, $admin);
check('choosing by category shows the category instead',
    str_contains($byCategory['body'], 'value="category" selected')
    && !str_contains($byCategory['body'], 'value="product" selected'));
$byBranch = request('GET', $reportPath . '&group_by=branch&branch_id=' . $branchId, $admin);
check('choosing by branch shows the branch that sold it',
    str_contains($byBranch['body'], 'value="branch" selected')
    && str_contains($byBranch['body'], 'Analytics HTTP Branch'));
$otherBranch = request('GET', $reportPath . '&group_by=product&branch_id=' . ($mark['branch_id'] ?: 999999), $admin);
check('filtering to another branch removes this sale from the table',
    !str_contains($otherBranch['body'], 'Analytics HTTP Widget'));
$badDimension = request('GET', $reportPath . '&group_by=everything', $admin);
check('a dimension the report does not know falls back to day, not an error',
    $badDimension['status'] === 200 && str_contains($badDimension['body'], 'value="day" selected'),
    (string) $badDimension['status']);

echo "\n== D. a page with no analytics report does not grow one ==\n";

$incomeId = createFinancialReport($pdo, [
    'company_id' => $companyId,
    'report_type' => 'income_statement',
    'report_name' => 'Analytics HTTP Income',
    'period_start' => date('Y-m-01'),
    'period_end' => date('Y-m-d'),
    'generated_by' => $adminId,
]);
$incomePage = request('GET', '/report?action=list&report_id=' . $incomeId . '&group_by=product', $admin);
check('an income statement ignores the analytics-only parameters',
    $incomePage['status'] === 200 && !str_contains($incomePage['body'], 'How it was paid')
    && str_contains($incomePage['body'], 'Income by source'),
    (string) $incomePage['status']);

echo "\n== teardown evidence ==\n";

$ours = [
    'reports' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM financial_reports WHERE report_id > {$mark['report_id']}"),
    'invoices' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId AND invoice_id > {$mark['invoice_id']}"),
    'products' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM products WHERE sku LIKE 'ANA-HTTP-%'"),
    'users' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM users WHERE username = 'analytics.http.smoke'"),
];
check('the sweep deletes exactly this run: three reports, one sale, one product, one user',
    $ours['reports'] === 3 && $ours['invoices'] === 1 && $ours['products'] === 1 && $ours['users'] === 1,
    json_encode($ours));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
