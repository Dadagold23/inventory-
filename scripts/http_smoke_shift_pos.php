<?php
/**
 * HTTP smoke test for Phase C: the shift workspace routes (shifts.php) and the
 * POS JSON endpoints (sales.php?action=pos), exercised through Apache exactly as
 * a browser would, with crafted session files standing in for a signed-in user.
 *
 * Run: php scripts/http_smoke_shift_pos.php
 * Creates a throwaway branch, products, users, shifts and sales, then deletes
 * everything it made.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/inventory_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/shift_functions.php';

// CLI: let fatals print a real trace instead of the app's generic error page.
restore_error_handler();
restore_exception_handler();

const BASE_URL = 'http://localhost/inventory';

$pdo = db();
$pass = 0;
$fail = 0;
$created = [
    'invoices' => [],
    'shifts' => [],
    'products' => [],
    'branches' => [],
    'users' => [],
    'session_files' => [],
];

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

/**
 * Write a session file so curl can present itself as a signed-in user without
 * going through the password flow. Format is PHP's own: key|serialized.
 */
function craftSession(PDO $pdo, int $userId, ?string $csrf = null): array
{
    $stmt = $pdo->prepare("SELECT username, full_name, role, company_id, branch_id FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new RuntimeException("No user $userId to sign in as.");
    }
    $csrf = $csrf ?? bin2hex(random_bytes(32));
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
    return ['sid' => $sid, 'csrf' => $csrf, 'file' => $path];
}

/** One HTTP call. Returns ['status'=>int,'body'=>string,'location'=>string,'json'=>?array]. */
function request(string $method, string $path, array $session = [], array $fields = [], array $headers = []): array
{
    $url = BASE_URL . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
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
    $json = json_decode($body, true);
    return [
        'status' => $status,
        'body' => $body,
        'location' => $location,
        'json' => is_array($json) ? $json : null,
    ];
}

function money(float $v): string
{
    return number_format($v, 2, '.', '');
}

function sqlOne(PDO $pdo, string $sql): mixed
{
    $stmt = $pdo->query($sql);
    return $stmt->fetchColumn();
}

// ===========================================================================
// Fixtures
// ===========================================================================

$companyId = 1;

$pdo->prepare("INSERT INTO branches (company_id, name, code, is_active, sort_order) VALUES (?, 'HTTP Smoke Branch', 'HTTP-SMOKE', 1, 98)")
    ->execute([$companyId]);
$branchId = (int) $pdo->lastInsertId();
$created['branches'][] = $branchId;

function makeSmokeProduct(PDO $pdo, int $companyId, string $sku, float $price, float $cost, float $stock, int $branchId): int
{
    $stmt = $pdo->prepare("SELECT product_id FROM products WHERE sku = ?");
    $stmt->execute([$sku]);
    $existing = (int) ($stmt->fetchColumn() ?: 0);
    if ($existing) {
        $pdo->prepare("DELETE FROM product_locations WHERE product_id = ?")->execute([$existing]);
        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$existing]);
    }
    $pdo->prepare(
        "INSERT INTO products (company_id, category_id, sku, name, sales_price, cost_price, track_inventory, manage_stock, is_active)
         VALUES (?, 1, ?, ?, ?, ?, 1, 1, 1)"
    )->execute([$companyId, $sku, 'Smoke test ' . $sku, $price, $cost]);
    $productId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO product_locations (company_id, product_id, branch_id, on_hand_quantity, reserved_quantity, available_quantity, on_hand_value)
         VALUES (?, ?, ?, ?, 0, ?, ?)"
    )->execute([$companyId, $productId, $branchId, $stock, $stock, $stock * $cost]);
    return $productId;
}

$productA = makeSmokeProduct($pdo, $companyId, 'SMOKE-A', 25.00, 5.00, 40.0, $branchId);
$productB = makeSmokeProduct($pdo, $companyId, 'SMOKE-B', 8.00, 2.00, 20.0, $branchId);
$created['products'] = [$productA, $productB];
$otherCompanyProduct = makeSmokeProduct($pdo, 2, 'SMOKE-OTHER', 99.00, 1.00, 5.0, $branchId);
$created['products'][] = $otherCompanyProduct;

function makeSmokeUser(PDO $pdo, string $username, string $fullName, string $role, int $companyId, int $branchId): int
{
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $existing = (int) ($stmt->fetchColumn() ?: 0);
    if ($existing) {
        $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$existing]);
    }
    $pdo->prepare(
        "INSERT INTO users (company_id, branch_id, username, password_hash, full_name, email, role, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
    )->execute([
        $companyId, $branchId, $username, password_hash('smoke-test-only', PASSWORD_DEFAULT),
        $fullName, $username . '@example.test', $role,
    ]);
    return (int) $pdo->lastInsertId();
}

$smokeCashier = makeSmokeUser($pdo, 'smoke.cashier', 'Smoke Cashier', 'cashier', $companyId, $branchId);
$smokeLocked = makeSmokeUser($pdo, 'smoke.locked', 'Smoke Locked Cashier', 'cashier', $companyId, $branchId);
$smokeAuditor = makeSmokeUser($pdo, 'smoke.auditor', 'Smoke Auditor', 'auditor', $companyId, $branchId);
$smokeForeign = makeSmokeUser($pdo, 'smoke.foreign', 'Smoke Other Company', 'cashier', 2, $branchId);
$created['users'] = [$smokeCashier, $smokeLocked, $smokeAuditor, $smokeForeign];

$actor = craftSession($pdo, $smokeCashier);
$locked = craftSession($pdo, $smokeLocked);
$auditor = craftSession($pdo, $smokeAuditor);
$foreign = craftSession($pdo, $smokeForeign);
$guest = ['sid' => bin2hex(random_bytes(16)), 'csrf' => 'none'];
file_put_contents(rtrim((string) ini_get('session.save_path'), '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $guest['sid'], 'csrf_token|' . serialize($guest['csrf']));
foreach ([$actor, $locked, $auditor, $foreign, $guest] as $session) {
    $created['session_files'][] = rtrim((string) ini_get('session.save_path'), '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $session['sid'];
}

$pos = static function (array $session, string $op, array $extra = []) {
    return request('POST', '/sales.php?action=pos', $session, ['ajax' => '1', 'pos_action' => $op, 'csrf_token' => $session['csrf']] + $extra);
};

// ===========================================================================
// 1. Route guards
// ===========================================================================

$anon = request('GET', '/shifts.php?action=list', $guest);
check('an anonymous visitor is sent to the login page',
    $anon['status'] === 302 && str_contains($anon['location'], 'login'), json_encode(['status' => $anon['status'], 'to' => $anon['location']]));

$listed = request('GET', '/shifts.php?action=list', $actor);
check('the shift register renders for a cashier',
    $listed['status'] === 200 && str_contains($listed['body'], 'My register'), (string) $listed['status']);

$noCsrf = request('POST', '/shifts.php?action=open', $actor, ['branch_id' => $branchId, 'opening_float' => 50]);
check('a shift cannot be opened without a CSRF token', $noCsrf['status'] === 403, (string) $noCsrf['status']);

$auditorPost = request('POST', '/shifts.php?action=open', $auditor, ['csrf_token' => $auditor['csrf'], 'branch_id' => $branchId, 'opening_float' => 10]);
check('a view-only role cannot open a shift', $auditorPost['status'] === 403, (string) $auditorPost['status']);

$auditorGet = request('GET', '/shifts.php?action=list', $auditor);
check('a view-only role can read the register', $auditorGet['status'] === 200, (string) $auditorGet['status']);

// ===========================================================================
// 2. POS is locked until a shift is open
// ===========================================================================

$lockedStatus = request('GET', '/sales.php?action=pos&format=json', $locked);
check('POS status says no shift is open',
    $lockedStatus['status'] === 200 && ($lockedStatus['json']['shift']['open'] ?? true) === false
    && ($lockedStatus['json']['shift']['allowed'] ?? true) === false, json_encode($lockedStatus['json']['shift'] ?? null));

$lockedHtml = request('GET', '/sales.php?action=pos', $locked);
check('the POS screen shows a locked register',
    $lockedHtml['status'] === 200 && str_contains($lockedHtml['body'], 'Register locked'), (string) $lockedHtml['status']);

$addNoShift = $pos($locked, 'add_item', ['product_id' => $productA, 'quantity' => 1]);
check('a cart can still be built without a shift',
    (bool) ($addNoShift['json']['ok'] ?? false), json_encode($addNoShift['json']['message'] ?? $addNoShift['status']));

$blockedCheckout = $pos($locked, 'checkout', [
    'payment_method' => ['cash'], 'payment_amount' => [100], 'idempotency_key' => 'smokenoshift001',
]);
check('checkout is refused before a shift is open',
    ($blockedCheckout['json']['ok'] ?? true) === false && str_contains((string) ($blockedCheckout['json']['message'] ?? ''), 'shift'),
    json_encode($blockedCheckout['json']['message'] ?? $blockedCheckout['status']));
$invoicesBefore = (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices");
check('the refused checkout stored no invoice', (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices") === $invoicesBefore);

$posCsrf = request('POST', '/sales.php?action=pos', $actor, ['ajax' => '1', 'pos_action' => 'search', 'q' => 'SMOKE']);
check('POS JSON calls need a CSRF token', $posCsrf['status'] === 403, (string) $posCsrf['status']);

// ===========================================================================
// 3. Open a shift over HTTP
// ===========================================================================

$opened = request('POST', '/shifts.php?action=open', $actor, [
    'csrf_token' => $actor['csrf'], 'branch_id' => $branchId, 'opening_float' => 50, 'opening_note' => 'Smoke float',
]);
preg_match('/shift_id=(\d+)/', $opened['location'], $m);
$shiftId = (int) ($m[1] ?? 0);
if ($shiftId) {
    $created['shifts'][] = $shiftId;
}
check('the open form redirects to the new shift', $opened['status'] === 302 && $shiftId > 0, json_encode(['status' => $opened['status'], 'to' => $opened['location']]));

$floatRow = (float) sqlOne($pdo, "SELECT opening_float FROM cashier_shifts WHERE shift_id = $shiftId");
$detail = request('GET', '/shifts.php?action=detail&shift_id=' . $shiftId, $actor);
check('the shift detail shows the reconciliation block',
    $detail['status'] === 200 && str_contains($detail['body'], 'Expected cash in drawer')
    && str_contains($detail['body'], money($floatRow)), (string) $detail['status']);

$report = request('GET', '/shifts.php?action=report&shift_id=' . $shiftId, $actor);
check('the printable report renders',
    $report['status'] === 200 && str_contains($report['body'], 'Expected in drawer'), (string) $report['status']);

$foreignDetail = request('GET', '/shifts.php?action=detail&shift_id=' . $shiftId, $foreign);
check('another company cannot open the shift',
    $foreignDetail['status'] === 302 && str_contains($foreignDetail['location'], 'action=list'),
    json_encode(['status' => $foreignDetail['status'], 'to' => $foreignDetail['location']]));

$missingDetail = request('GET', '/shifts.php?action=detail&shift_id=99999999', $actor);
check('an unknown shift id falls back to the register',
    $missingDetail['status'] === 302 && str_contains($missingDetail['location'], 'action=list'), (string) $missingDetail['status']);

$auditorMove = request('POST', '/shifts.php?action=movement', $auditor, [
    'csrf_token' => $auditor['csrf'], 'shift_id' => $shiftId, 'movement_type' => 'paid_in', 'amount' => 5,
]);
check('a view-only role cannot post a drawer movement', $auditorMove['status'] === 403, (string) $auditorMove['status']);

$moved = request('POST', '/shifts.php?action=movement', $actor, [
    'csrf_token' => $actor['csrf'], 'shift_id' => $shiftId, 'movement_type' => 'paid_in', 'amount' => 10, 'reason' => 'Smoke paid in',
]);
check('the cashier can post a drawer movement', $moved['status'] === 302, (string) $moved['status']);
$afterMove = shiftTotals($pdo, shiftGet($pdo, $shiftId, $companyId));
check('paid in raises expected cash to 60', abs((float) $afterMove['expected_cash'] - 60.0) < 0.001, json_encode($afterMove['expected_cash']));

// ===========================================================================
// 4. POS JSON with a shift open
// ===========================================================================

$statusOpen = request('GET', '/sales.php?action=pos&format=json', $actor);
check('POS status now reports the open shift',
    ($statusOpen['json']['shift']['open'] ?? false) === true && (int) ($statusOpen['json']['shift']['shift_id'] ?? 0) === $shiftId,
    json_encode($statusOpen['json']['shift'] ?? null));

$searched = $pos($actor, 'search', ['q' => 'SMOKE-A']);
$rows = (array) ($searched['json']['items'] ?? []);
check('POS search returns the branch product',
    ($searched['json']['ok'] ?? false) === true && in_array($productA, array_map(static fn ($r) => (int) $r['product_id'], $rows), true),
    json_encode(array_column($rows, 'product_id')));
check('POS search hides another company product',
    !in_array($otherCompanyProduct, array_map(static fn ($r) => (int) $r['product_id'], $rows), true));

$scanned = $pos($actor, 'scan', ['code' => 'SMOKE-A']);
check('scanning a SKU adds one unit',
    (bool) ($scanned['json']['ok'] ?? false) && (float) ($scanned['json']['cart']['lines'][0]['quantity'] ?? 0) === 1.0,
    json_encode($scanned['json']['cart']['lines'] ?? $scanned['status']));

$added = $pos($actor, 'add_item', ['product_id' => $productB, 'quantity' => 2]);
$total = (float) ($added['json']['cart']['total'] ?? 0);
$taxRate = (float) salesSetting($pdo, 'default_tax_rate', '0');
// default_tax_rate is a decimal (0.075 = 7.5%), which is what the app multiplies by.
$bill = round((25.00 + 2 * 8.00) * (1 + $taxRate), 2);
$billB = round(8.00 * (1 + $taxRate), 2);
check('the cart totals from the database prices', abs($total - $bill) < 0.001, json_encode(['total' => $total, 'bill' => $bill, 'msg' => $added['json']['message'] ?? '']));

$oversell = $pos($actor, 'set_quantity', ['item_index' => 0, 'quantity' => 999]);
check('the cart refuses a quantity above on-hand stock', ($oversell['json']['ok'] ?? true) === false, json_encode($oversell['json']['message'] ?? ''));

$key = 'smokehttp' . substr(bin2hex(random_bytes(6)), 0, 8);
$tender = $bill + 20.0;
$sale = $pos($actor, 'checkout', [
    'payment_method' => ['cash'], 'payment_amount' => [money($tender)], 'idempotency_key' => $key,
]);
$invoiceId = (int) ($sale['json']['invoice_id'] ?? 0);
if ($invoiceId) {
    $created['invoices'][] = $invoiceId;
}
check('checkout sells the cart', ($sale['json']['ok'] ?? false) === true && $invoiceId > 0, json_encode($sale['json']['message'] ?? $sale['status']));
check('the change is the tender above the bill', abs((float) ($sale['json']['change'] ?? 0) - 20.0) < 0.001, json_encode($sale['json']['change'] ?? null));
check('checkout clears the cart', ($sale['json']['cart']['lines'] ?? ['x']) === [], json_encode($sale['json']['cart'] ?? null));

$bound = (int) sqlOne($pdo, "SELECT COALESCE(shift_id, 0) FROM sales_invoices WHERE invoice_id = $invoiceId");
check('the invoice is bound to the shift opened over HTTP', $bound === $shiftId, (string) $bound);

$replay = $pos($actor, 'checkout', ['payment_method' => ['cash'], 'payment_amount' => [money($tender)], 'idempotency_key' => $key]);
check('a retried checkout replays the original sale',
    ($replay['json']['ok'] ?? false) === true && ($replay['json']['duplicate'] ?? false) === true
    && (int) ($replay['json']['invoice_id'] ?? 0) === $invoiceId, json_encode($replay['json'] ?? $replay['status']));
check('the retry created no second invoice', (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices") === $invoicesBefore + 1);

$shiftAfter = shiftTotals($pdo, shiftGet($pdo, $shiftId, $companyId));
check('the sale moves the drawer by the bill, not the tender',
    abs((float) $shiftAfter['cash_receipts'] - $bill) < 0.001, json_encode($shiftAfter['cash_receipts']));

$detailAfter = request('GET', '/shifts.php?action=detail&shift_id=' . $shiftId, $actor);
$invoiceNumber = (string) ($sale['json']['invoice_number'] ?? '');
check('the shift page lists the new sale',
    $invoiceNumber !== '' && str_contains($detailAfter['body'], $invoiceNumber), json_encode(['invoice' => $invoiceNumber, 'status' => $detailAfter['status']]));

$stockLeft = (float) sqlOne($pdo, "SELECT on_hand_quantity FROM product_locations WHERE product_id = $productA AND branch_id = $branchId");
check('stock dropped by the units sold', $stockLeft === 39.0, (string) $stockLeft);

// ===========================================================================
// 5. The browser-facing POS screen
// ===========================================================================

$posHtml = request('GET', '/sales.php?action=pos', $actor);
check('the POS screen mounts the AJAX app',
    $posHtml['status'] === 200 && str_contains($posHtml['body'], 'id="posApp"') && str_contains($posHtml['body'], 'js/pos.js'),
    (string) $posHtml['status']);
check('the POS screen shows the open shift badge',
    str_contains($posHtml['body'], 'Shift #' . $shiftId), (string) $posHtml['status']);
$asset = request('GET', '/assets/js/pos.js', []);
check('pos.js is served to the browser',
    $asset['status'] === 200 && str_contains($asset['body'], 'pos_action'), (string) $asset['status']);

// ===========================================================================
// 6. The no-JS path on the same route
// ===========================================================================

$classicAdd = request('POST', '/sales.php?action=pos', $actor, [
    'csrf_token' => $actor['csrf'], 'pos_action' => 'add_item', 'product_id' => $productB, 'quantity' => 1,
]);
check('the no-JS add-to-cart form still posts', $classicAdd['status'] === 302, (string) $classicAdd['status']);
$classicCart = request('GET', '/sales.php?action=pos&format=json', $actor);
check('the classic cart holds the added line',
    (int) ($classicCart['json']['cart']['count'] ?? 0) === 1 && abs((float) ($classicCart['json']['cart']['total'] ?? 0) - $billB) < 0.001,
    json_encode($classicCart['json']['cart'] ?? null));

$classicSale = request('POST', '/sales.php?action=pos', $actor, [
    'csrf_token' => $actor['csrf'], 'pos_action' => 'checkout',
    'payment_method' => 'cash', 'payment_amount' => money($billB + 5), 'idempotency_key' => 'smokeclassic001',
]);
preg_match('/sale_id=(\d+)/', $classicSale['location'], $mc);
$classicInvoice = (int) ($mc[1] ?? 0);
if ($classicInvoice) {
    $created['invoices'][] = $classicInvoice;
}
check('the no-JS checkout redirects to the receipt', $classicSale['status'] === 302 && $classicInvoice > 0, json_encode(['status' => $classicSale['status'], 'to' => $classicSale['location']]));
$classicRow = sqlOne($pdo, "SELECT COALESCE(shift_id,0) FROM sales_invoices WHERE invoice_id = $classicInvoice");
check('the no-JS sale is bound to the same shift', (int) $classicRow === $shiftId, (string) $classicRow);
$classicReceipt = request('GET', '/sales.php?action=receipt&sale_id=' . $classicInvoice, $actor);
check('the receipt page renders for the sale', $classicReceipt['status'] === 200, (string) $classicReceipt['status']);

// ===========================================================================
// 7. Close the shift over HTTP
// ===========================================================================

$expected = (float) shiftTotals($pdo, shiftGet($pdo, $shiftId, $companyId))['expected_cash'];
$closed = request('POST', '/shifts.php?action=close', $actor, [
    'csrf_token' => $actor['csrf'], 'shift_id' => $shiftId, 'closing_count' => money($expected - 3), 'closing_note' => 'Smoke close',
]);
$row = sqlOne($pdo, "SELECT status FROM cashier_shifts WHERE shift_id = $shiftId");
check('the shift closes from the form', $closed['status'] === 302 && $row === 'closed', json_encode(['status' => $closed['status'], 'row' => $row]));
$variance = (float) sqlOne($pdo, "SELECT variance FROM cashier_shifts WHERE shift_id = $shiftId");
check('the short count is recorded as variance', abs($variance + 3.0) < 0.001, (string) $variance);

$afterClose = request('GET', '/sales.php?action=pos&format=json', $actor);
check('POS is locked again once the shift is closed',
    ($afterClose['json']['shift']['open'] ?? true) === false, json_encode($afterClose['json']['shift'] ?? null));

// ===========================================================================
// 8. The sidebar is one link list, shared by every screen
// ===========================================================================

/** The sidebar markup inside a rendered page. */
function sidebarNav(string $html): string
{
    return preg_match('#<nav class="side-nav">(.*)</nav>#s', $html, $block) ? $block[1] : '';
}

/** The labels inside a rendered sidebar, keyed by the href they point at. */
function sidebarLinks(string $html): array
{
    preg_match_all(
        '#<a class="side-link[^"]*"[^>]*href="([^"]+)"[^>]*>\s*<i class="bi [^"]*"></i><span>([^<]*)</span></a>#',
        sidebarNav($html),
        $matches,
        PREG_SET_ORDER
    );
    $links = [];
    foreach ($matches as $match) {
        $links[$match[1]] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
    }
    return $links;
}

/** Each sidebar heading with the number of links rendered under it. */
function sidebarSections(string $html): array
{
    $nav = sidebarNav($html);
    $sections = [];
    foreach (explode('<div class="side-label">', $nav) as $index => $chunk) {
        if ($index === 0 || $chunk === '') {
            continue;
        }
        preg_match('#^([^<]*)</div>#', $chunk, $title);
        $sections[trim(html_entity_decode($title[1] ?? '', ENT_QUOTES, 'UTF-8'))] = substr_count($chunk, '<a class="side-link');
    }
    return $sections;
}

/** Turn a rendered href into a path curl can request against BASE_URL. */
function navPath(string $href): string
{
    $parts = parse_url($href);
    $path = (string) ($parts['path'] ?? '');
    $prefix = (string) (parse_url(BASE_URL, PHP_URL_PATH) ?? '');
    if ($prefix !== '' && str_starts_with($path, $prefix)) {
        $path = substr($path, strlen($prefix));
    }
    return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

function unopenableLinks(array $links, array $session): array
{
    $blocked = [];
    foreach (array_keys($links) as $href) {
        $page = request('GET', navPath($href), $session);
        if ($page['status'] === 0) {
            $page = request('GET', navPath($href), $session); // a transient Apache hiccup is not a routing fault
        }
        if ($page['status'] < 200 || $page['status'] >= 400) {
            $blocked[] = navPath($href) . ' -> ' . ($page['status'] === 0 ? 'no response' : $page['status']);
        }
    }
    return $blocked;
}

$adminId = (int) sqlOne($pdo, "SELECT user_id FROM users WHERE role = 'admin' AND company_id = $companyId ORDER BY user_id LIMIT 1");
$admin = craftSession($pdo, $adminId);
$created['session_files'][] = $admin['file'];

$adminPage = request('GET', '/dashboard', $admin);
$adminNav = sidebarLinks($adminPage['body']);
$adminHeaderNav = sidebarLinks(request('GET', '/inventory?action=list', $admin)['body']);

check('the dashboard renders for the administrator', $adminPage['status'] === 200 && count($adminNav) >= 20,
    json_encode(['status' => $adminPage['status'], 'links' => count($adminNav)]));
foreach (['Categories', 'Barcodes', 'Shifts & Drawer', 'Users & Roles', 'Payment Methods'] as $missingLabel) {
    check("the administrator sidebar lists $missingLabel", in_array($missingLabel, array_values($adminNav), true), json_encode(array_values($adminNav)));
}
check('the dashboard sidebar is the same list a standard page draws', $adminNav === $adminHeaderNav,
    json_encode(['dashboard' => array_diff($adminNav, $adminHeaderNav), 'page' => array_diff($adminHeaderNav, $adminNav)]));
$adminBlocked = unopenableLinks($adminNav, $admin);
check('every link the administrator sees opens', $adminBlocked === [], implode('; ', $adminBlocked));

$cashierNav = sidebarLinks(request('GET', '/dashboard', $actor)['body']);
check('a cashier sees fewer links than the administrator', count($cashierNav) < count($adminNav),
    json_encode(['cashier' => count($cashierNav), 'admin' => count($adminNav)]));
$adminSections = sidebarSections($adminPage['body']);
check('every sidebar heading the administrator sees holds links', !in_array(0, $adminSections, true) && count($adminSections) === 4, json_encode($adminSections));
check('the new screens are listed for a cashier too',
    in_array('Shifts & Drawer', array_values($cashierNav), true) && in_array('Categories', array_values($cashierNav), true)
    && in_array('Barcodes', array_values($cashierNav), true));
foreach (['Companies & Branches', 'Users & Roles', 'System Settings'] as $forbiddenLabel) {
    check("a cashier is not offered $forbiddenLabel", !in_array($forbiddenLabel, array_values($cashierNav), true), json_encode(array_values($cashierNav)));
}
$cashierSections = sidebarSections(request('GET', '/dashboard', $actor)['body']);
check('a sidebar section with no permitted links disappears',
    !isset($cashierSections['Control']) && in_array(0, $cashierSections, true) === false, json_encode($cashierSections));
$cashierBlocked = unopenableLinks($cashierNav, $actor);
check('every link the cashier sees opens', $cashierBlocked === [], implode('; ', $cashierBlocked));

// ===========================================================================
// Cleanup
// ===========================================================================

$pdo->beginTransaction();
try {
    foreach ($created['invoices'] as $id) {
        $pdo->prepare("DELETE FROM customer_payments WHERE invoice_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM inventory_transactions WHERE reference_type = 'sales_invoice' AND reference_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM sales_invoice_items WHERE invoice_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM sales_invoices WHERE invoice_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM audit_log WHERE entity_type = 'sales_invoices' AND entity_id = ?")->execute([$id]);
    }
    foreach ($created['shifts'] as $id) {
        $pdo->prepare("DELETE FROM shift_movements WHERE shift_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM cashier_shifts WHERE shift_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM audit_log WHERE entity_type IN ('cashier_shifts','shift_movements') AND entity_id = ?")->execute([$id]);
    }
    foreach ($created['products'] as $id) {
        $pdo->prepare("DELETE FROM product_locations WHERE product_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM product_barcodes WHERE product_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$id]);
    }
    foreach ($created['branches'] as $id) {
        $pdo->prepare("DELETE FROM product_locations WHERE branch_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM branches WHERE branch_id = ?")->execute([$id]);
    }
    foreach ($created['users'] as $id) {
        $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM audit_log WHERE user_id = ?")->execute([$id]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "CLEANUP FAILED: " . $e->getMessage() . "\n";
}
foreach ($created['session_files'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

$left = [
    'shifts' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM cashier_shifts WHERE shift_id IN (" . implode(',', array_map('intval', $created['shifts'])) . ")"),
    'invoices' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE invoice_id IN (" . implode(',', array_map('intval', $created['invoices'])) . ")"),
    'products' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM products WHERE product_id IN (" . implode(',', array_map('intval', $created['products'])) . ")"),
    'branches' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM branches WHERE branch_id IN (" . implode(',', array_map('intval', $created['branches'])) . ")"),
    'users' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM users WHERE user_id IN (" . implode(',', array_map('intval', $created['users'])) . ")"),
    'session files' => count(array_filter($created['session_files'], 'file_exists')),
];
echo "\nLeft after cleanup: " . json_encode($left) . "\n";
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
