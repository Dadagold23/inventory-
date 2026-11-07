<?php
/**
 * HTTP smoke test for Phase E1: the receipt page (sales.php?action=receipt),
 * the paper switch, the PDF it hands back, the reprint record and the three
 * receipt settings on the System Settings screen - exercised through Apache the
 * way a cashier's browser reaches them.
 *
 * Run: php scripts/http_smoke_receipts.php
 *
 * It writes only rows it removes again: one throwaway user in another company,
 * one branch, one product, one customer, one paid sale and the audit entries
 * those prints leave behind. The four receipt settings are put back exactly as
 * they were found, including the ones that did not exist.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/receipt_functions.php';

// CLI: let fatals print a real trace instead of the app's generic error page.
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
const PAPER_KEYS = ['receipt_paper_width', 'receipt_footer_text', 'receipt_show_logo', 'receipt_logo_path'];

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

function rawSetting(PDO $pdo, int $companyId, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE company_id = ? AND `key` = ?');
    $stmt->execute([$companyId, $key]);
    $value = $stmt->fetchColumn();

    return $value === false ? null : (string) $value;
}

/** Print rows this run's sale has left in the trail, oldest first. */
function printRows(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare("
        SELECT audit_id, user_id, new_value FROM audit_log
        WHERE action = 'PRINT' AND entity_type = 'sales_invoices' AND entity_id = ?
        ORDER BY audit_id
    ");
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

/**
 * One HTTP call, headers included: the PDF is only correct if the response says
 * it is a PDF.
 *
 * @return array{status:int,body:string,location:string,headers:array<string,string>}
 */
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

$settingSnapshot = [];
foreach (PAPER_KEYS as $key) {
    $settingSnapshot[$key] = rawSetting($pdo, $companyId, $key);
}
$auditBaseline = (int) sqlOne($pdo, 'SELECT COUNT(*) FROM audit_log');
$marks = [
    'invoice_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(invoice_id), 0) FROM sales_invoices'),
    'payment_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(payment_id), 0) FROM customer_payments'),
    'user_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(user_id), 0) FROM users'),
];
echo "baseline: audit rows $auditBaseline\n";

$teardownDone = false;
$teardown = static function () use ($pdo, $companyId, $otherCompanyId, $settingSnapshot, $marks, $auditBaseline, &$teardownDone): void {
    if ($teardownDone) {
        return;
    }
    $teardownDone = true;
    $pdo->beginTransaction();
    try {
        // The sale this run made, and only this run's, plus what it touched.
        $invoiceIds = $pdo->query(
            "SELECT invoice_id FROM sales_invoices WHERE company_id = $companyId AND invoice_id > {$marks['invoice_id']}"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($invoiceIds !== []) {
            $in = implode(',', array_map('intval', $invoiceIds));
            $pdo->exec("DELETE FROM customer_payments WHERE invoice_id IN ($in)");
            $pdo->exec("DELETE FROM sales_invoice_items WHERE invoice_id IN ($in)");
            $pdo->exec("DELETE FROM inventory_transactions WHERE reference_type = 'sales_invoice' AND reference_id IN ($in)");
            $pdo->exec("DELETE FROM audit_log WHERE action = 'PRINT' AND entity_type = 'sales_invoices' AND entity_id IN ($in)");
            $pdo->exec("DELETE FROM sales_invoices WHERE invoice_id IN ($in)");
        }
        // The stock this run sold, and the rows that hold it.
        $pdo->exec("DELETE FROM product_locations WHERE product_id IN (SELECT product_id FROM products WHERE sku LIKE 'RCP-HTTP-%')");
        $pdo->exec("DELETE FROM products WHERE sku LIKE 'RCP-HTTP-%'");
        $pdo->exec("DELETE FROM customers WHERE name = 'Receipt HTTP Customer'");
        $pdo->exec("DELETE FROM branches WHERE code = 'RCP-HTTP'");
        $pdo->exec("DELETE FROM users WHERE username = 'receipt.http.smoke' AND company_id = " . (int) $otherCompanyId);

        // The four settings, exactly as they were found.
        foreach ($settingSnapshot as $key => $value) {
            if ($value === null) {
                $stmt = $pdo->prepare('DELETE FROM settings WHERE company_id = ? AND `key` = ?');
                $stmt->execute([$companyId, $key]);
            } else {
                $stmt = $pdo->prepare('UPDATE settings SET value = ? WHERE company_id = ? AND `key` = ?');
                $stmt->execute([$value, $companyId, $key]);
            }
        }
        $pdo->commit();
        $left = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $prints = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'PRINT'")->fetchColumn();
        $rows = (int) $pdo->query('SELECT COUNT(*) FROM settings WHERE company_id = 1 AND `key` LIKE \'receipt_%\'')->fetchColumn();
        echo 'TEARDOWN  audit_log ' . $left . ' (baseline ' . $auditBaseline . '), print rows ' . $prints
            . ', receipt settings ' . $rows . " (found " . count(array_filter($settingSnapshot, static fn ($v) => $v !== null)) . ")\n";
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
// Fixtures: one sale to print, one admin from elsewhere
// ===========================================================================

$pdo->exec("DELETE FROM branches WHERE code = 'RCP-HTTP'");
$pdo->exec("DELETE FROM products WHERE sku LIKE 'RCP-HTTP-%'");
$pdo->exec("DELETE FROM customers WHERE name = 'Receipt HTTP Customer'");
$pdo->exec("DELETE FROM users WHERE username = 'receipt.http.smoke'");

$pdo->prepare('INSERT INTO branches (company_id, name, code, is_active, sort_order) VALUES (?, ?, ?, 1, 96)')
    ->execute([$companyId, 'Receipt HTTP Branch', 'RCP-HTTP']);
$branchId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO products (company_id, category_id, sku, name, sales_price, cost_price, track_inventory, manage_stock, is_active)
               VALUES (?, 1, ?, ?, ?, ?, 1, 1, 1)')
    ->execute([$companyId, 'RCP-HTTP-1', 'Counter test item', 100.00, 40.00]);
$productId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO product_locations (company_id, product_id, branch_id, on_hand_quantity, reserved_quantity, available_quantity, on_hand_value)
               VALUES (?, ?, ?, 20, 0, 20, ?)')->execute([$companyId, $productId, $branchId, 800.00]);
$pdo->prepare("INSERT INTO customers (company_id, name, email, credit_limit, credit_used, payment_terms, payment_status, is_active)
               VALUES (?, 'Receipt HTTP Customer', 'receipt.http@example.test', 0, 0, 'Net 0', 'paid', 1)")->execute([$companyId]);
$customerId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO users (company_id, branch_id, username, password_hash, full_name, email, role, is_active)
               VALUES (?, NULL, ?, ?, ?, ?, ?, 1)')
    ->execute([$otherCompanyId, 'receipt.http.smoke', password_hash('smoke-test-only', PASSWORD_DEFAULT),
        'Elsewhere Admin', 'receipt.http.smoke@example.test', 'admin']);
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
    'tax_rate' => 0,
    'lines' => [['product_id' => $productId, 'quantity' => 2]],
    'payments' => [['method' => 'cash', 'amount' => 250.00]],
]);
if (!($sale['success'] ?? false)) {
    throw new RuntimeException('Fixture sale failed: ' . ($sale['message'] ?? '?'));
}
$invoiceId = (int) $sale['invoice_id'];
$invoiceNumber = (string) $sale['invoice_number'];
$total = round((float) $sale['total'], 2);
$change = round(250.00 - $total, 2);
echo "fixture: invoice $invoiceId ($invoiceNumber), total $total, change $change\n";

$admin = craftSession($pdo, $adminId);
$elsewhere = craftSession($pdo, $otherUserId);
$receiptPath = '/sales.php?action=receipt&sale_id=' . $invoiceId;

echo "\n== A. who is handed a slip ==\n";

$anon = request('GET', $receiptPath);
check('an anonymous visitor is sent to the login page',
    $anon['status'] === 302 && str_contains($anon['location'], 'login'),
    json_encode(['status' => $anon['status'], 'to' => $anon['location']]));
check('nothing is printed for someone who never got in', printRows($pdo, $invoiceId) === []);

$foreign = request('GET', $receiptPath, $elsewhere);
check('an admin of another company is turned away from this slip',
    $foreign['status'] === 302 && str_contains((string) $foreign['location'], 'action=sales'),
    json_encode(['status' => $foreign['status'], 'to' => $foreign['location']]));
check('and no slip of theirs is left in the print trail', printRows($pdo, $invoiceId) === []);
check('a sale id that belongs to no one has no slip either',
    request('GET', '/sales.php?action=receipt&sale_id=' . ($marks['invoice_id'] + 987654), $admin)['status'] === 302);

$html = request('GET', $receiptPath, $admin);
check('the cashier who owns the sale is handed the slip',
    $html['status'] === 200 && str_contains($html['body'], $invoiceNumber), (string) $html['status']);

echo "\n== B. the paper the page is laid out for ==\n";

check('the default page is the company 80 mm paper',
    str_contains($html['body'], 'class="paper-80"') && str_contains($html['body'], '@page { size: 80mm auto;'),
    (string) preg_match('/@page \{[^}]*\}/', $html['body'], $m) ? $m[0] : 'no @page');
$on58 = request('GET', $receiptPath . '&width=58', $admin);
check('the width switch relays the same slip for a 58 mm roll',
    $on58['status'] === 200 && str_contains($on58['body'], 'class="paper-58"')
    && str_contains($on58['body'], '@page { size: 58mm auto;') && !str_contains($on58['body'], 'size: 80mm'),
    (string) preg_match('/@page \{[^}]*\}/', $on58['body'], $m) ? $m[0] : 'no @page');
$on120 = request('GET', $receiptPath . '&width=120', $admin);
check('a width no printer is sold with is refused, and the configured paper is used',
    str_contains($on120['body'], 'class="paper-80"') && !str_contains($on120['body'], 'size: 120mm'),
    (string) preg_match('/@page \{[^}]*\}/', $on120['body'], $m) ? $m[0] : 'no @page');
check('the slip carries what the customer came for',
    str_contains($html['body'], 'Counter test item') && str_contains($html['body'], 'Receipt HTTP Customer')
    && str_contains($html['body'], 'Receipt HTTP Branch'), 'line, customer and branch');
$money = number_format($total, 2, '.', '');
check('the bill and the change are both on the page',
    str_contains($html['body'], $money) && str_contains($html['body'], 'Change due')
    && str_contains($html['body'], number_format($change, 2, '.', '')), $money . ' / change ' . $change);
check('the picture is carried inside the page, never as a fetchable address',
    !str_contains($html['body'], 'src="storage/') && !str_contains($html['body'], '/storage/uploads'),
    'logo path leaked into markup');

echo "\n== C. the file a counter hands over ==\n";

$pdf = request('GET', $receiptPath . '&format=pdf', $admin);
$cleanNumber = preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceNumber);
check('a PDF is offered as a PDF, with its own length',
    $pdf['status'] === 200 && str_starts_with((string) ($pdf['headers']['content-type'] ?? ''), 'application/pdf')
    && (int) ($pdf['headers']['content-length'] ?? -1) === strlen($pdf['body']),
    json_encode($pdf['headers']));
check('the file is a PDF from first line to last',
    str_starts_with($pdf['body'], '%PDF-1.4') && str_ends_with($pdf['body'], "%%EOF\n"), (string) strlen($pdf['body']));
check('it is named for the sale it proves',
    str_contains((string) ($pdf['headers']['content-disposition'] ?? ''), 'RECEIPT-' . $cleanNumber . '.pdf'),
    (string) ($pdf['headers']['content-disposition'] ?? 'no disposition'));
$pdf58 = request('GET', $receiptPath . '&format=pdf&width=58', $admin);
preg_match('/MediaBox \[0 0 ([0-9.]+)/', $pdf['body'], $wideBox);
preg_match('/MediaBox \[0 0 ([0-9.]+)/', $pdf58['body'], $narrowBox);
check('the 58 mm file is a narrower page, not the 80 mm page relabelled',
    isset($wideBox[1], $narrowBox[1]) && (float) $narrowBox[1] < (float) $wideBox[1]
    && abs((float) $narrowBox[1] - 58 * 72 / 25.4) < 0.01,
    json_encode(['80' => $wideBox[1] ?? null, '58' => $narrowBox[1] ?? null]));
$foreignPdf = request('GET', $receiptPath . '&format=pdf', $elsewhere);
check('the download door keeps the same key as the page',
    $foreignPdf['status'] === 302 && substr($foreignPdf['body'], 0, 5) !== '%PDF-',
    json_encode(['status' => $foreignPdf['status'], 'bytes' => strlen($foreignPdf['body'])]));
$weirdFormat = request('GET', $receiptPath . '&format=jpg', $admin);
check('a format nobody prints falls back to the page, not to a broken file',
    $weirdFormat['status'] === 200 && str_contains($weirdFormat['body'], '<!DOCTYPE html>'),
    (string) $weirdFormat['status']);

echo "\n== D. printing leaves a record, and says so ==\n";

$rows = printRows($pdo, $invoiceId);
$first = json_decode((string) ($rows[0]['new_value'] ?? ''), true);
check('the first slip is stored as PRINT against the sale',
    count($rows) >= 1 && $first !== null && $first['reprint'] === false && (int) $first['tenders'] === 1,
    json_encode(['rows' => count($rows), 'first' => $first]));
$reprint = request('GET', $receiptPath, $admin);
check('the same sale printed again admits on the paper that it is a reprint',
    str_contains($reprint['body'], '** reprint **'), (string) $reprint['status']);
$again = printRows($pdo, $invoiceId);
$last = json_decode((string) (end($again)['new_value'] ?? ''), true);
check('and the trail knows it too', is_array($last) && $last['reprint'] === true, json_encode($last));
$formats = array_values(array_filter(array_map(
    static fn (array $r): ?string => json_decode((string) $r['new_value'], true)['format'] ?? null,
    $again
)));
check('a download and a print are told apart in the trail',
    in_array('pdf', $formats, true) && in_array('html', $formats, true), json_encode($formats));
check('every print row in the trail was left by this run, under the user who pressed it',
    (int) sqlOne($pdo, "SELECT COUNT(*) FROM audit_log WHERE action = 'PRINT'") === count($again)
    && count(array_unique(array_map(static fn (array $r): int => (int) $r['user_id'], $again))) === 1,
    json_encode(['total_prints' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM audit_log WHERE action = 'PRINT'"),
        'ours' => count($again)]));

echo "\n== E. the settings that decide the slip ==\n";

$settingsPage = request('GET', '/system_settings.php?action=list', $admin);
check('the settings screen offers the paper, the footer and the logo switch',
    $settingsPage['status'] === 200 && str_contains($settingsPage['body'], 'app_receipt_paper_width')
    && str_contains($settingsPage['body'], 'app_receipt_footer_text')
    && str_contains($settingsPage['body'], 'app_receipt_show_logo'), (string) $settingsPage['status']);
check('and the logo upload form is on the page, picture and all',
    str_contains($settingsPage['body'], 'enctype="multipart/form-data"')
    && str_contains($settingsPage['body'], 'name="receipt_logo"')
    && str_contains($settingsPage['body'], 'value="save_receipt_logo"'), 'logo form');

$saved = request('POST', '/system_settings.php?action=list', $admin, [
    'csrf_token' => $admin['csrf'], 'action' => 'save_app_settings',
    'app_settings' => ['receipt_paper_width' => '58', 'receipt_footer_text' => 'Return within 7 days with the slip.'],
]);
$storedWidth = rawSetting($pdo, $companyId, 'receipt_paper_width');
check('choosing the roll the printer actually holds is kept',
    $saved['status'] === 302 && $storedWidth === '58', (string) $storedWidth);
$defaults58 = request('GET', $receiptPath, $admin);
check('and every slip from then on is laid out for it without being asked',
    str_contains($defaults58['body'], 'class="paper-58"'), 'still 80 mm');
$badWidth = request('POST', '/system_settings.php?action=list', $admin, [
    'csrf_token' => $admin['csrf'], 'action' => 'save_app_settings',
    'app_settings' => ['receipt_paper_width' => '120'],
]);
check('a paper the layouts cannot render is refused over HTTP too',
    $badWidth['status'] === 302 && rawSetting($pdo, $companyId, 'receipt_paper_width') === '58',
    (string) rawSetting($pdo, $companyId, 'receipt_paper_width'));
$noToken = request('POST', '/system_settings.php?action=list', $admin, [
    'action' => 'save_receipt_logo', 'csrf_token' => 'not-the-session-token',
]);
check('a logo POST without the page token is refused outright',
    $noToken['status'] === 403 && rawSetting($pdo, $companyId, 'receipt_logo_path') === $settingSnapshot['receipt_logo_path'],
    json_encode(['status' => $noToken['status'], 'setting' => rawSetting($pdo, $companyId, 'receipt_logo_path')]));

// ===========================================================================
echo "\n== teardown evidence ==\n";

// The sweep can only name rows this run made, so what it is about to remove is
// counted here, while it still exists, and nothing else matches those names.
$oursPrints = count(printRows($pdo, $invoiceId));
$willRemove = [
    'invoices' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId AND invoice_id > {$marks['invoice_id']}"),
    'prints' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM audit_log WHERE action = 'PRINT'"),
    'ours_prints' => $oursPrints,
    'products' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM products WHERE sku LIKE 'RCP-HTTP-%'"),
    'branches' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM branches WHERE code = 'RCP-HTTP'"),
    'users' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM users WHERE username = 'receipt.http.smoke'"),
];
check('what the sweep deletes is exactly this run: one sale, one product, one branch, one user',
    $willRemove['invoices'] === 1 && $willRemove['products'] === 1 && $willRemove['branches'] === 1
    && $willRemove['users'] === 1, json_encode($willRemove));
check('and every print row in the trail belongs to the sale being removed',
    $willRemove['prints'] === $willRemove['ours_prints'] && $willRemove['ours_prints'] > 0,
    json_encode($willRemove));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
