<?php
/**
 * HTTP smoke test for Phase D: the gateway workspace (payment_gateways.php),
 * the provider callback route (gateway_webhook.php), the gateway field on the
 * payment methods screen and the till taking a card tender through a payment
 * session, exercised through Apache as a browser would.
 *
 * Run: php scripts/http_smoke_gateways.php
 *
 * It writes only rows it removes again: throwaway users, one gateway
 * configuration per provider, the tender that configuration created, and - for
 * the till - a shift, a product, a card session and the one sale it earns. The
 * state the company had before this run is restored at the end, and audit
 * entries are only removed for the rows this run created.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/payment_methods_functions.php';
require_once APP_ROOT . '/includes/gateway_functions.php';
require_once APP_ROOT . '/includes/shift_functions.php';
require_once APP_ROOT . '/includes/crypto_functions.php';

// CLI: let fatals print a real trace instead of the app's generic error page.
restore_error_handler();
restore_exception_handler();

const BASE_URL = 'http://localhost/inventory';

$pdo = db();
$pass = 0;
$fail = 0;
$companyId = 1;
$created = ['users' => [], 'methods' => [], 'references' => [], 'session_files' => [], 'shifts' => [], 'products' => [], 'invoices' => []];

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
    $stmt = $pdo->query($sql);
    return $stmt->fetchColumn();
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
    return ['sid' => $sid, 'csrf' => $csrf, 'file' => $path];
}

/** One HTTP call. Returns ['status'=>int,'body'=>string,'location'=>string,'json'=>?array]. */
function request(string $method, string $path, array $session = [], array $fields = [], array $headers = [], string $rawBody = ''): array
{
    $ch = curl_init(BASE_URL . $path);
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody !== '' ? $rawBody : http_build_query($fields));
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
    return ['status' => $status, 'body' => $body, 'location' => $location, 'json' => is_array($json) ? $json : null];
}

function configRow(PDO $pdo, int $companyId, string $gateway): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM payment_gateways WHERE company_id = ? AND gateway = ?');
    $stmt->execute([$companyId, $gateway]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function methodIdByCode(PDO $pdo, int $companyId, string $code): int
{
    $stmt = $pdo->prepare('SELECT payment_method_id FROM payment_methods WHERE company_id = ? AND code = ?');
    $stmt->execute([$companyId, $code]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function saveConfig(array $session, string $gateway, array $overrides = []): array
{
    $fields = array_merge([
        'csrf_token' => $session['csrf'], 'action' => 'save_config', 'gateway' => $gateway,
        'mode' => 'test', 'is_enabled' => '1', 'sandbox_allowed' => '1',
    ], $overrides);
    return request('POST', '/payment_gateways.php?action=save_config', $session, $fields);
}

/** The sidebar labels inside a rendered page, keyed by href. */
function sidebarLinks(string $html): array
{
    if (!preg_match('#<nav class="side-nav">(.*)</nav>#s', $html, $block)) {
        return [];
    }
    preg_match_all(
        '#<a class="side-link[^"]*"[^>]*href="([^"]+)"[^>]*>\s*<i class="bi [^"]*"></i><span>([^<]*)</span></a>#',
        $block[1],
        $matches,
        PREG_SET_ORDER
    );
    $links = [];
    foreach ($matches as $match) {
        $links[$match[1]] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
    }
    return $links;
}

/**
 * The payment register table only. The reconciliation panel below it is a
 * company-wide view, so searching the whole page for a reference would match
 * a bucket that has nothing to do with the register filters.
 */
function registerTable(string $html): string
{
    $start = strpos($html, 'Payment sessions');
    if ($start === false) {
        return '';
    }
    $end = strpos($html, '</table>', $start);
    return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
}

function makeUser(PDO $pdo, string $username, string $role, int $companyId): int
{
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $existing = (int) ($stmt->fetchColumn() ?: 0);
    if ($existing) {
        $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$existing]);
    }
    $pdo->prepare(
        'INSERT INTO users (company_id, branch_id, username, password_hash, full_name, email, role, is_active)
         VALUES (?, NULL, ?, ?, ?, ?, ?, 1)'
    )->execute([
        $companyId, $username, password_hash('smoke-test-only', PASSWORD_DEFAULT),
        ucwords(str_replace('.', ' ', $username)), $username . '@example.test', $role,
    ]);
    return (int) $pdo->lastInsertId();
}

// ===========================================================================
// Fixtures, and a snapshot of anything this run is allowed to touch
// ===========================================================================

$snapshot = [];
foreach (['paystack', 'flutterwave'] as $gateway) {
    $snapshot[$gateway] = configRow($pdo, $companyId, $gateway);
}
$eventsBefore = (int) sqlOne($pdo, "SELECT COUNT(*) FROM gateway_events WHERE company_id = $companyId");
$paystackTendersBefore = (int) sqlOne($pdo, "SELECT COUNT(*) FROM payment_methods WHERE company_id = $companyId AND gateway = 'paystack'");

$adminId = (int) sqlOne($pdo, "SELECT user_id FROM users WHERE role = 'admin' AND company_id = $companyId ORDER BY user_id LIMIT 1");
$gwCashier = makeUser($pdo, 'gws.cashier', 'cashier', $companyId);
$gwAuditor = makeUser($pdo, 'gws.auditor', 'auditor', $companyId);
$gwForeign = makeUser($pdo, 'gws.foreign', 'admin', 2);
$created['users'] = [$gwCashier, $gwAuditor, $gwForeign];

$admin = craftSession($pdo, $adminId);
$cashier = craftSession($pdo, $gwCashier);
$auditor = craftSession($pdo, $gwAuditor);
$foreign = craftSession($pdo, $gwForeign);
$guest = ['sid' => bin2hex(random_bytes(16)), 'csrf' => 'none'];
file_put_contents(dirname($admin['file']) . DIRECTORY_SEPARATOR . 'sess_' . $guest['sid'], 'csrf_token|' . serialize($guest['csrf']));
foreach ([$admin, $cashier, $auditor, $foreign, $guest] as $session) {
    $created['session_files'][] = dirname($session['file']) . DIRECTORY_SEPARATOR . 'sess_' . $session['sid'];
}

$publicKey = 'pk_test_' . bin2hex(random_bytes(12));
$webhookSecret = 'whsec_' . bin2hex(random_bytes(12));

// ===========================================================================
// 1. Who may open the screen at all
// ===========================================================================

$anon = request('GET', '/payment_gateways.php?action=list', $guest);
check('an anonymous visitor is sent to the login page',
    $anon['status'] === 302 && str_contains($anon['location'], 'login'), json_encode(['status' => $anon['status'], 'to' => $anon['location']]));

$foreignGet = request('GET', '/payment_gateways.php?action=list', $foreign);
check('an administrator of another company gets their own empty workspace',
    $foreignGet['status'] === 200, (string) $foreignGet['status']);

$auditorGet = request('GET', '/payment_gateways.php?action=list', $auditor);
check('a view-only role can read the gateway register', $auditorGet['status'] === 200, (string) $auditorGet['status']);

$cashierGet = request('GET', '/payment_gateways.php?action=list', $cashier);
check('a role without gateways.view is refused', $cashierGet['status'] === 403, (string) $cashierGet['status']);

$noCsrf = request('POST', '/payment_gateways.php?action=save_config', $admin, ['gateway' => 'paystack', 'mode' => 'test']);
check('saving configuration without a CSRF token is refused', $noCsrf['status'] === 403, (string) $noCsrf['status']);

$auditorPost = request('POST', '/payment_gateways.php?action=test', $auditor, ['csrf_token' => $auditor['csrf'], 'gateway' => 'paystack']);
check('a view-only role cannot change gateway configuration', $auditorPost['status'] === 403, (string) $auditorPost['status']);

$cashierVerify = request('POST', '/payment_gateways.php?action=verify', $cashier, ['csrf_token' => $cashier['csrf'], 'transaction_id' => 1]);
check('a cashier cannot run a server-side verification', $cashierVerify['status'] === 403, (string) $cashierVerify['status']);

// ===========================================================================
// 2. The screen renders, and shows no credential
// ===========================================================================

$list = request('GET', '/payment_gateways.php?action=list', $admin);
check('the gateway workspace renders for the administrator',
    $list['status'] === 200 && str_contains($list['body'], 'Payment Gateways'), (string) $list['status']);
check('both providers are offered', str_contains($list['body'], 'Paystack') && str_contains($list['body'], 'Flutterwave'));
check('the callback URL the provider dashboard needs is shown', str_contains($list['body'], 'gateway_webhook.php'));
check('the signature header is named so the administrator can set it', str_contains($list['body'], 'x-paystack-signature'));
check('credential inputs are write-only password boxes',
    substr_count($list['body'], 'type="password"') >= 6, (string) substr_count($list['body'], 'type="password"'));

$bad = saveConfig($admin, 'paystack', ['test_secret_key' => 'not-a-key-at-all']);
check('a credential that is not the provider\'s format is rejected',
    $bad['status'] === 302 && str_contains((string) $bad['location'], 'gateway=paystack'), (string) $bad['location']);
check('the rejected credential wrote no configuration row', configRow($pdo, $companyId, 'paystack') === null);

$noRuntime = saveConfig($admin, 'paystack', ['sandbox_allowed' => '0']);
check('switching a gateway on with nothing behind it is refused',
    $noRuntime['status'] === 302 && configRow($pdo, $companyId, 'paystack') === null,
    json_encode(configRow($pdo, $companyId, 'paystack')));

$enabled = saveConfig($admin, 'paystack', ['test_public_key' => $publicKey, 'test_webhook_secret' => $webhookSecret]);
$row = configRow($pdo, $companyId, 'paystack');
check('a sandbox configuration can be switched on', $enabled['status'] === 302 && (int) ($row['is_enabled'] ?? 0) === 1,
    json_encode(['status' => $enabled['status'], 'enabled' => $row['is_enabled'] ?? null]));
check('the runtime is the simulator, not a live account',
    gatewayRuntimeMode(gatewayResolvedConfig($pdo, $companyId, 'paystack')) === 'sandbox',
    gatewayRuntimeMode(gatewayResolvedConfig($pdo, $companyId, 'paystack')));
check('nothing is stored in the clear',
    $row !== null && !str_contains((string) $row['test_webhook_secret'], $webhookSecret)
    && !str_contains((string) $row['test_public_key'], $publicKey)
    && secretIsEncrypted($row['test_webhook_secret']));

$rendered = request('GET', '/payment_gateways.php?action=list', $admin);
check('the rendered page contains no credential value',
    !str_contains($rendered['body'], $webhookSecret) && !str_contains($rendered['body'], $publicKey),
    json_encode(['secret_in_page' => str_contains($rendered['body'], $webhookSecret)]));
check('the page shows a masked hint instead', str_contains($rendered['body'], substr($publicKey, 0, 8)), (string) $rendered['status']);

$tenderCount = (int) sqlOne($pdo, "SELECT COUNT(*) FROM payment_methods WHERE company_id = $companyId AND gateway = 'paystack'");
check('switching the provider on also created the online tender',
    $tenderCount === $paystackTendersBefore + 1, json_encode(['before' => $paystackTendersBefore, 'now' => $tenderCount]));
$newTenderId = methodIdByCode($pdo, $companyId, 'paystack');
if ($newTenderId) {
    $created['methods'][] = $newTenderId;
}

$mismatch = saveConfig($admin, 'flutterwave', ['mode' => 'live', 'live_secret_key' => 'FLWSECK_TEST-' . bin2hex(random_bytes(8))]);
check('a test key cannot be stored as the live key', $mismatch['status'] === 302);
check('the rejected provider configuration wrote no row', configRow($pdo, $companyId, 'flutterwave') === null);

$testConnection = request('POST', '/payment_gateways.php?action=test', $admin, ['csrf_token' => $admin['csrf'], 'gateway' => 'paystack']);
check('testing the connection answers without calling the provider',
    $testConnection['status'] === 302 && str_contains((string) $testConnection['location'], 'action=list'), (string) $testConnection['location']);
$checked = configRow($pdo, $companyId, 'paystack');
check('the check is recorded for the screen to show',
    (string) ($checked['last_checked_at'] ?? '') !== '' && stripos((string) ($checked['last_check_result'] ?? ''), 'imulat') !== false,
    json_encode($checked['last_check_result'] ?? null));

$clear = saveConfig($admin, 'paystack', ['clear_test_public_key' => '1', 'clear_test_webhook_secret' => '1', 'is_enabled' => '0', 'sandbox_allowed' => '0']);
$cleared = configRow($pdo, $companyId, 'paystack');
check('a stored credential is forgotten only when it is asked for',
    $clear['status'] === 302 && array_key_exists('test_public_key', (array) $cleared) && $cleared['test_public_key'] === null
    && (int) ($cleared['is_enabled'] ?? 1) === 0,
    json_encode(['status' => $clear['status'], 'public' => $cleared['test_public_key'] ?? null, 'enabled' => $cleared['is_enabled'] ?? null]));$keepTest = request('POST', '/payment_gateways.php?action=save_config', $admin, [
    'csrf_token' => $admin['csrf'], 'gateway' => 'paystack', 'mode' => 'test',
    'is_enabled' => '1', 'sandbox_allowed' => '1', 'test_public_key' => $publicKey, 'test_webhook_secret' => $webhookSecret,
]);
check('the configuration can be switched back on for the register checks', $keepTest['status'] === 302);

// ===========================================================================
// 3. The provider callback route
// ===========================================================================

$getState = request('GET', '/gateway_webhook.php?gateway=paystack');
check('the callback refuses GET', $getState['status'] === 405, (string) $getState['status']);

$unknown = request('POST', '/gateway_webhook.php?gateway=bogus', [], [], [], '{"event":"charge.success"}');
check('an unknown provider is refused', $unknown['status'] === 404, (string) $unknown['status']);

$unsigned = request('POST', '/gateway_webhook.php?gateway=paystack', [], [], [], '{"event":"charge.success","data":{"reference":"NONE"}}');
check('a payload with no signature is rejected', $unsigned['status'] === 401, (string) $unsigned['status']);

$forged = request('POST', '/gateway_webhook.php?gateway=paystack', [], [],
    ['x-paystack-signature: ' . str_repeat('0', 128)],
    '{"event":"charge.success","data":{"reference":"NONE","status":"success"}}');
check('a payload signed with the wrong key is rejected', $forged['status'] === 401, (string) $forged['status']);

$garbage = request('POST', '/gateway_webhook.php?gateway=paystack', [], [],
    ['x-paystack-signature: abc'], 'this is not json');
check('a payload that is not JSON is rejected', $garbage['status'] === 400, (string) $garbage['status']);

$eventsAfter = (int) sqlOne($pdo, "SELECT COUNT(*) FROM gateway_events WHERE company_id = $companyId");
check('every rejected callback left no event behind', $eventsAfter === $eventsBefore,
    json_encode(['before' => $eventsBefore, 'after' => $eventsAfter]));

// ===========================================================================
// 4. Detail and form guards
// ===========================================================================

$pendingSession = gatewayCreateTransaction($pdo, $companyId, [
    'gateway' => 'paystack', 'runtime_mode' => 'sandbox', 'amount' => 4321.55, 'currency' => 'NGN',
    'user_id' => $gwCashier, 'customer_email' => 'gws.session@example.test',
    'snapshot' => ['lines' => [['product_id' => 0, 'description' => 'Smoke line', 'quantity' => 1]], 'method' => 'paystack'],
]);
$pendingId = (int) $pendingSession['transaction_id'];
$pendingRef = (string) $pendingSession['reference'];
$created['references'][] = $pendingRef;

$detail = request('GET', '/payment_gateways.php?action=detail&transaction_id=' . $pendingId, $admin);
check('a pending session opens its detail page',
    $detail['status'] === 200 && str_contains($detail['body'], $pendingRef), json_encode(['status' => $detail['status']]));
check('the detail page says what the session is waiting for',
    str_contains($detail['body'], 'Awaiting payment') && str_contains($detail['body'], number_format(4321.55, 2)), (string) $detail['status']);
check('a session with no invoice yet is not presented as a sale',
    str_contains($detail['body'], 'not posted') && str_contains($detail['body'], 'Verification is the only thing that can post this sale.'),
    (string) $detail['status']);

$listWithPending = request('GET', '/payment_gateways.php?action=list', $admin);
check('the register lists the session',
    $listWithPending['status'] === 200 && str_contains(registerTable($listWithPending['body']), $pendingRef), (string) $listWithPending['status']);

$requestRef = static fn (array $who, string $query) => registerTable(request('GET', '/payment_gateways.php?action=list' . $query, $who)['body']);
$paidPage = $requestRef($admin, '&status=paid');
$pendingPage = $requestRef($admin, '&status=pending');
check('the status filter hides a session that does not match it',
    !str_contains($paidPage, $pendingRef) && str_contains($pendingPage, $pendingRef),
    json_encode(['paid_rows' => substr_count($paidPage, '<tr>'), 'pending_has' => str_contains($pendingPage, $pendingRef)]));
$fwPage = $requestRef($admin, '&gateway=flutterwave');
$psPage = $requestRef($admin, '&gateway=paystack');
check('the provider filter hides another provider\'s register',
    !str_contains($fwPage, $pendingRef) && str_contains($psPage, $pendingRef),
    json_encode(['fw_rows' => substr_count($fwPage, '<tr>'), 'paystack_has' => str_contains($psPage, $pendingRef)]));
check('the reference filter finds the session on its own',
    str_contains($requestRef($admin, '&reference=' . rawurlencode($pendingRef)), $pendingRef)
    && !str_contains($requestRef($admin, '&reference=ZZZNOMATCH'), $pendingRef));

$foreignSession = request('GET', '/payment_gateways.php?action=detail&transaction_id=' . $pendingId, $foreign);
check('another company cannot read the session',
    $foreignSession['status'] === 302 && str_contains((string) $foreignSession['location'], 'action=list'), (string) $foreignSession['status']);

$auditorVerify = request('POST', '/payment_gateways.php?action=verify', $auditor, [
    'csrf_token' => $auditor['csrf'], 'transaction_id' => $pendingId,
]);
check('a view-only role cannot ask the provider about a payment', $auditorVerify['status'] === 403, (string) $auditorVerify['status']);

$auditorRefund = request('POST', '/payment_gateways.php?action=refund', $auditor, [
    'csrf_token' => $auditor['csrf'], 'transaction_id' => $pendingId, 'amount' => 10, 'reason' => 'view only',
]);
check('a view-only role cannot refund', $auditorRefund['status'] === 403, (string) $auditorRefund['status']);

$refundPending = request('POST', '/payment_gateways.php?action=refund', $admin, [
    'csrf_token' => $admin['csrf'], 'transaction_id' => $pendingId, 'amount' => 10, 'reason' => 'nothing was paid',
]);
check('a payment the provider never took cannot be refunded',
    $refundPending['status'] === 302 && (int) sqlOne($pdo, "SELECT COUNT(*) FROM gateway_refunds WHERE transaction_id = $pendingId") === 0,
    json_encode(['status' => $refundPending['status']]));

$missingDetail = request('GET', '/payment_gateways.php?action=detail&transaction_id=99999999', $admin);
check('an unknown payment session falls back to the register',
    $missingDetail['status'] === 302 && str_contains((string) $missingDetail['location'], 'action=list'), (string) $missingDetail['location']);

$junkFilter = request('GET', '/payment_gateways.php?action=list&status=nonsense&gateway=oracle', $admin);
check('a junk filter does not break the register', $junkFilter['status'] === 200, (string) $junkFilter['status']);

$refundMissing = request('POST', '/payment_gateways.php?action=refund', $admin, [
    'csrf_token' => $admin['csrf'], 'transaction_id' => 99999999, 'amount' => 10, 'reason' => 'no such payment',
]);
check('refunding a payment that is not ours sends you back',
    $refundMissing['status'] === 302 && str_contains((string) $refundMissing['location'], 'action=list'), (string) $refundMissing['location']);

$expire = request('POST', '/payment_gateways.php?action=expire', $auditor, [
    'csrf_token' => $auditor['csrf'], 'minutes' => 60,
]);
check('a view-only role cannot expire pending sessions', $expire['status'] === 403, (string) $expire['status']);

// ===========================================================================
// 5. The gateway field on the payment methods screen
// ===========================================================================

$methodsPage = request('GET', '/payment_methods.php?action=list', $admin);
check('the payment methods screen offers a gateway choice',
    $methodsPage['status'] === 200 && str_contains($methodsPage['body'], 'payment_method_gateway'), (string) $methodsPage['status']);

$saveMethod = static function (array $fields) use ($admin) {
    return request('POST', '/payment_methods.php?action=save_payment_method', $admin, array_merge([
        'csrf_token' => $admin['csrf'], 'action' => 'save_payment_method', 'payment_method_id' => 0,
        'payment_method_sort_order' => 95, 'payment_method_is_active' => '1',
    ], $fields));
};

$cashWithGateway = $saveMethod([
    'payment_method_name' => 'Smoke Cash Linked', 'payment_method_code' => 'smokecashgw',
    'payment_method_is_cash' => '1', 'payment_method_gateway' => 'paystack',
]);
check('a cash tender cannot be linked to a gateway',
    methodIdByCode($pdo, $companyId, 'smokecashgw') === 0 && $cashWithGateway['status'] === 302,
    json_encode(['status' => $cashWithGateway['status']]));

$saveMethod([
    'payment_method_name' => 'Smoke Bad Gateway', 'payment_method_code' => 'smokebadgw',
    'payment_method_is_online' => '1', 'payment_method_gateway' => 'paypal',
]);
check('an unknown provider cannot be linked', methodIdByCode($pdo, $companyId, 'smokebadgw') === 0);

$saveMethod([
    'payment_method_name' => 'Smoke Online Paystack', 'payment_method_code' => 'smokeonlinegw',
    'payment_method_is_online' => '1', 'payment_method_gateway' => 'paystack',
]);
$onlineId = methodIdByCode($pdo, $companyId, 'smokeonlinegw');
if ($onlineId) {
    $created['methods'][] = $onlineId;
}
check('an online tender can be linked to the provider', $onlineId > 0);
$stmt = $pdo->prepare('SELECT gateway, is_online, is_cash FROM payment_methods WHERE payment_method_id = ?');
$stmt->execute([$onlineId]);
$linked = $stmt->fetch(PDO::FETCH_ASSOC);
check('the link is stored against the tender',
    ($linked['gateway'] ?? '') === 'paystack' && (int) ($linked['is_online'] ?? 0) === 1 && (int) ($linked['is_cash'] ?? 1) === 0,
    json_encode($linked));

request('POST', '/payment_methods.php?action=save_payment_method', $admin, [
    'csrf_token' => $admin['csrf'], 'action' => 'save_payment_method', 'payment_method_id' => $onlineId,
    'payment_method_name' => 'Smoke Online Renamed', 'payment_method_code' => 'smokeonlinegw',
    'payment_method_is_online' => '1', 'payment_method_sort_order' => 95, 'payment_method_is_active' => '1',
]);
$stmt->execute([$onlineId]);
$afterEdit = $stmt->fetch(PDO::FETCH_ASSOC);
check('editing a tender without the gateway field keeps its link',
    stripos((string) $afterEdit['gateway'], 'paystack') !== false, json_encode($afterEdit['gateway'] ?? null));

// ===========================================================================
// 6. The sidebar knows the new screen exists
// ===========================================================================

$adminNav = sidebarLinks(request('GET', '/dashboard.php', $admin)['body']);
$gatewayHrefs = array_keys(array_filter($adminNav, static fn ($label) => $label === 'Payment Gateways'));
check('the administrator sidebar lists Payment Gateways', count($gatewayHrefs) === 1, json_encode(array_values($adminNav)));
check('the sidebar link opens the workspace',
    count($gatewayHrefs) === 1 && request('GET', '/payment_gateways.php?action=list', $admin)['status'] === 200);

$cashierNav = sidebarLinks(request('GET', '/dashboard.php', $cashier)['body']);
check('a cashier is not offered the gateway screen',
    !in_array('Payment Gateways', array_values($cashierNav), true), json_encode(array_values($cashierNav)));

$auditorNav = sidebarLinks(request('GET', '/dashboard.php', $auditor)['body']);
check('a view-only role is offered it and can open it',
    in_array('Payment Gateways', array_values($auditorNav), true)
    && request('GET', '/payment_gateways.php?action=list', $auditor)['status'] === 200,
    json_encode(array_values($auditorNav)));

// ===========================================================================
// 7. The till: a card tender goes to the gateway before it becomes a sale
// ===========================================================================

// The tender that switching the provider on created is the one the till must
// offer, and the cashier the sale is taken under is the throwaway this run owns.
$tillTender = (string) sqlOne($pdo, "SELECT code FROM payment_methods WHERE company_id = $companyId
                                     AND gateway = 'paystack' AND is_online = 1 AND is_active = 1 ORDER BY payment_method_id DESC LIMIT 1");
$txBeforeTill = (int) sqlOne($pdo, "SELECT COUNT(*) FROM gateway_transactions WHERE company_id = $companyId");
$invoicesBeforeTill = (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId");
$onlineTenderCount = (int) sqlOne($pdo, "SELECT COUNT(*) FROM payment_methods WHERE company_id = $companyId AND is_online = 1 AND is_active = 1");

$tillPage = request('GET', '/sales.php?action=pos', $cashier);
check('the till renders with the card panel ready',
    $tillPage['status'] === 200 && str_contains($tillPage['body'], 'id="posOnline"'), (string) $tillPage['status']);
check('the tender list marks which method a gateway runs',
    str_contains($tillPage['body'], 'data-gateway="paystack"'), (string) substr_count($tillPage['body'], 'data-gateway='));
check('the till is told nothing about credentials',
    !str_contains($tillPage['body'], $publicKey) && !str_contains($tillPage['body'], $webhookSecret)
    && !str_contains($tillPage['body'], 'sk_test') && !str_contains($tillPage['body'], 'whsec'),
    json_encode(['secret_in_page' => str_contains($tillPage['body'], $webhookSecret)]));
check('the no-Javascript checkout offers a card session, not a card sale',
    $tillTender !== '' && str_contains($tillPage['body'], 'value="gateway_init"') && str_contains($tillPage['body'], 'Take card payment'),
    json_encode(['online_tenders' => $onlineTenderCount, 'tender' => $tillTender]));

// Which drawer the till sells into is decided by the server, so it is read from
// the screen rather than assumed here.
$branchProbe = request('POST', '/sales.php?action=pos', $cashier,
    ['csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'search', 'q' => '']);
$tillBranchId = (int) ($branchProbe['json']['branch_id'] ?? 0);
check('the till reports the branch it is selling from', $tillBranchId > 0, json_encode($branchProbe['json']['branch_id'] ?? null));

// Drawers left under this throwaway cashier can only come from an interrupted
// run of this same section, and the user cannot be deleted with one open.
$orphanShifts = $pdo->prepare('SELECT shift_id FROM cashier_shifts WHERE user_id = ?');
$orphanShifts->execute([$gwCashier]);
foreach ($orphanShifts->fetchAll(PDO::FETCH_COLUMN) as $orphanId) {
    $pdo->prepare('DELETE FROM shift_movements WHERE shift_id = ?')->execute([(int) $orphanId]);
    $pdo->prepare('DELETE FROM cashier_shifts WHERE shift_id = ?')->execute([(int) $orphanId]);
}
$openShift = shiftOpen($pdo, $gwCashier, $companyId, $tillBranchId, 0, 'Gateway smoke session');
$shiftRowId = (int) ($openShift['shift_id'] ?? 0);
if ($shiftRowId > 0) {
    $created['shifts'][] = $shiftRowId;
}
check('a shift is open for the till to work in', (bool) ($openShift['success'] ?? false), (string) ($openShift['message'] ?? ''));

$pdo->prepare("INSERT INTO products (company_id, category_id, sku, name, sales_price, cost_price, track_inventory, manage_stock, is_active)
               VALUES (?, 1, 'gws.smoke', 'Gateway Smoke Item', 2500.00, 1000.00, 1, 1, 1)")->execute([$companyId]);
$tillProduct = (int) $pdo->lastInsertId();
$created['products'][] = $tillProduct;
$pdo->prepare("INSERT INTO product_locations (company_id, product_id, branch_id, on_hand_quantity, reserved_quantity, available_quantity, on_hand_value)
               VALUES (?, ?, ?, 10, 0, 10, 10000)")->execute([$companyId, $tillProduct, $tillBranchId]);

$emptyInit = request('POST', '/sales.php?action=pos', $cashier,
    ['csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'gateway_init', 'tender' => $tillTender]);
check('an empty cart cannot open a payment session',
    ($emptyInit['json']['ok'] ?? false) === false && str_contains((string) ($emptyInit['json']['message'] ?? ''), 'empty'),
    json_encode($emptyInit['json']['message'] ?? $emptyInit['status']));
check('the refused session left no payment behind',
    (int) sqlOne($pdo, "SELECT COUNT(*) FROM gateway_transactions WHERE company_id = $companyId") === $txBeforeTill);

$addedLine = request('POST', '/sales.php?action=pos', $cashier,
    ['csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'add_item', 'product_id' => $tillProduct, 'quantity' => 1]);
$tillTotal = (float) ($addedLine['json']['cart']['total'] ?? 0);
check('the cart takes the item at the catalogue price',
    ($addedLine['json']['ok'] ?? false) === true && $tillTotal > 0, json_encode($addedLine['json']['cart']['total'] ?? null));

$cardAsCash = request('POST', '/sales.php?action=pos', $cashier, [
    'csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'checkout',
    'payment_method' => [$tillTender], 'payment_amount' => [$tillTotal], 'idempotency_key' => bin2hex(random_bytes(16)),
]);
check('a card tender cannot be posted as if it were money',
    ($cardAsCash['json']['ok'] ?? false) === false && str_contains((string) ($cardAsCash['json']['message'] ?? ''), 'online payment button'),
    json_encode($cardAsCash['json']['message'] ?? $cardAsCash['status']));
$cardAsCashHtml = request('POST', '/sales.php?action=pos', $cashier, [
    'csrf_token' => $cashier['csrf'], 'pos_action' => 'checkout', 'payment_method' => $tillTender, 'payment_amount' => $tillTotal,
]);
check('the no-Javascript checkout is refused the same way',
    $cardAsCashHtml['status'] === 302
    && (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId") === $invoicesBeforeTill,
    json_encode(['status' => $cardAsCashHtml['status'],
                 'invoices' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId")]));
check('the cart survived both refusals',
    (int) ($cardAsCash['json']['cart']['count'] ?? 0) > 0, json_encode($cardAsCash['json']['cart']['count'] ?? null));

$initFirst = request('POST', '/sales.php?action=pos', $cashier,
    ['csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'gateway_init', 'tender' => $tillTender]);
$tillPay = (array) ($initFirst['json']['payment'] ?? []);
$tillRef = (string) ($tillPay['reference'] ?? '');
if ($tillRef !== '') {
    $created['references'][] = $tillRef;
}
check('opening a card session succeeds and quotes the server total',
    ($initFirst['json']['ok'] ?? false) === true && abs((float) ($tillPay['amount'] ?? 0) - $tillTotal) < 0.005,
    json_encode(['ok' => $initFirst['json']['ok'] ?? null, 'quoted' => $tillPay['amount'] ?? null, 'cart' => $tillTotal]));
$tampered = request('POST', '/sales.php?action=pos', $cashier,
    ['csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'gateway_init', 'tender' => $tillTender,
     'amount' => '0.01', 'total' => '0.01', 'payment_amount' => '0.01', 'unit_price' => '0.01']);
$tamperedPay = (array) ($tampered['json']['payment'] ?? []);
check('a posted price or total cannot change what the gateway is asked for',
    abs((float) ($tamperedPay['amount'] ?? 0) - $tillTotal) < 0.005 && (string) ($tamperedPay['reference'] ?? '') === $tillRef,
    json_encode(['posted' => $tamperedPay['amount'] ?? null, 'server' => $tillTotal]));
$tillRow = gatewayTransactionByReference($pdo, $companyId, $tillRef);
$drawerExpected = shiftEnforcementEnabled($pdo) ? $shiftRowId : 0;
check('the session is pending and carries the cart, not a sale',
    (string) ($tillRow['status'] ?? '') === 'pending' && (int) ($tillRow['invoice_id'] ?? 0) === 0
    && (int) ($tillRow['shift_id'] ?? 0) === $drawerExpected && str_contains((string) ($tillRow['payload'] ?? ''), (string) $tillProduct),
    json_encode(['status' => $tillRow['status'] ?? null, 'invoice_id' => $tillRow['invoice_id'] ?? null,
                 'shift_id' => $tillRow['shift_id'] ?? null, 'expected' => $drawerExpected]));
check('the checkout page the browser is sent to is this app\'s simulator',
    str_contains((string) ($tillPay['checkout_url'] ?? ''), 'action=gateway_sandbox')
    && str_contains((string) ($tillPay['checkout_url'] ?? ''), rawurlencode($tillRef)),
    json_encode($tillPay['checkout_url'] ?? null));

$again = request('POST', '/sales.php?action=pos', $cashier,
    ['csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'gateway_init', 'tender' => $tillTender]);
$againPay = (array) ($again['json']['payment'] ?? []);
check('pressing the button twice reopens one session instead of opening two',
    (string) ($againPay['reference'] ?? '') === $tillRef && ($againPay['reused'] ?? false) === true
    && (int) sqlOne($pdo, "SELECT COUNT(*) FROM gateway_transactions WHERE company_id = $companyId AND reference = '$tillRef'") === 1,
    json_encode(['reference' => $againPay['reference'] ?? null, 'reused' => $againPay['reused'] ?? null]));

$statusPending = request('POST', '/sales.php?action=pos', $cashier,
    ['csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'gateway_status', 'reference' => $tillRef]);
$statusBody = (array) ($statusPending['json']['payment'] ?? []);
check('polling says the money has not arrived and nothing is posted',
    ($statusBody['found'] ?? false) === true && ($statusBody['paid'] ?? true) === false && ($statusBody['posted'] ?? true) === false,
    json_encode($statusBody));

$unpaidReturn = request('GET', '/sales.php?action=gateway_return&reference=' . rawurlencode($tillRef), $cashier);
check('coming back from the gateway does not post an unpaid session',
    $unpaidReturn['status'] === 302 && str_contains((string) $unpaidReturn['location'], 'action=pos')
    && (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId") === $invoicesBeforeTill,
    json_encode(['to' => $unpaidReturn['location']]));

$oneMore = request('POST', '/sales.php?action=pos', $cashier,
    ['csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'add_item', 'product_id' => $tillProduct, 'quantity' => 1]);
$newTotal = (float) ($oneMore['json']['cart']['total'] ?? 0);
$supersede = request('POST', '/sales.php?action=pos', $cashier,
    ['csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'gateway_init', 'tender' => $tillTender]);
$supersedePay = (array) ($supersede['json']['payment'] ?? []);
$supersededRef = (string) ($supersedePay['reference'] ?? '');
$created['references'][] = $supersededRef;
$oldRow = gatewayTransactionByReference($pdo, $companyId, $tillRef);
check('a cart that changed supersedes the session waiting at the old total',
    (string) ($oldRow['status'] ?? '') === 'expired' && $supersededRef !== '' && $supersededRef !== $tillRef
    && abs((float) ($supersedePay['amount'] ?? 0) - $newTotal) < 0.005,
    json_encode(['old_status' => $oldRow['status'] ?? null, 'new' => $supersedePay['amount'] ?? null, 'cart' => $newTotal]));
check('only one session is left waiting for this till',
    (int) sqlOne($pdo, "SELECT COUNT(*) FROM gateway_transactions WHERE company_id = $companyId AND status = 'pending'
                       AND user_id = $gwCashier AND branch_id = $tillBranchId") === 1,
    (string) sqlOne($pdo, "SELECT COUNT(*) FROM gateway_transactions WHERE company_id = $companyId AND status = 'pending'"));

$tillStatus = request('POST', '/sales.php?action=pos', $cashier,
    ['csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'gateway_status', 'reference' => 'gws-not-a-reference']);
check('a reference this company does not know is not followed',
    ($tillStatus['json']['payment']['found'] ?? true) === false, json_encode($tillStatus['json']['payment'] ?? null));

$sandboxPage = request('GET', '/sales.php?action=gateway_sandbox&reference=' . rawurlencode($supersededRef), $cashier);
check('the simulator page renders and says it is one',
    $sandboxPage['status'] === 200 && str_contains($sandboxPage['body'], 'Simulator')
    && str_contains($sandboxPage['body'], $supersededRef), json_encode(['status' => $sandboxPage['status'], 'ref' => $supersededRef]));
check('it shows the amount the server quoted, with nowhere to change it',
    str_contains($sandboxPage['body'], number_format($newTotal, 2)), (string) $newTotal);
check('the simulator asks for no card details',
    !str_contains(strtolower($sandboxPage['body']), 'cvv') && !str_contains($sandboxPage['body'], 'cardnumber')
    && substr_count($sandboxPage['body'], 'name="outcome"') === 3, (string) substr_count($sandboxPage['body'], 'name="outcome"'));
check('the simulator leaks no credential either',
    !str_contains($sandboxPage['body'], $webhookSecret) && !str_contains($sandboxPage['body'], $publicKey));
$sandboxForeign = request('GET', '/sales.php?action=gateway_sandbox&reference=' . rawurlencode($supersededRef), $foreign);
check('another company cannot open the payment page',
    $sandboxForeign['status'] === 302 && str_contains((string) $sandboxForeign['location'], 'action=pos'), (string) $sandboxForeign['status']);
$sandboxNoCsrf = request('POST', '/sales.php?action=gateway_sandbox', $cashier, ['reference' => $supersededRef, 'outcome' => 'approve']);
check('the simulator refuses an answer with no CSRF token', $sandboxNoCsrf['status'] === 403, (string) $sandboxNoCsrf['status']);
$sandboxAuditor = request('POST', '/sales.php?action=gateway_sandbox', $auditor,
    ['csrf_token' => $auditor['csrf'], 'reference' => $supersededRef, 'outcome' => 'approve']);
check('a role that cannot sell cannot answer the simulator', $sandboxAuditor['status'] === 403, (string) $sandboxAuditor['status']);
$declined = request('POST', '/sales.php?action=gateway_sandbox', $cashier,
    ['csrf_token' => $cashier['csrf'], 'reference' => $supersededRef, 'outcome' => 'decline']);
$declinedRow = gatewayTransactionByReference($pdo, $companyId, $supersededRef);
check('a declined card leaves no sale behind',
    $declined['status'] === 302 && str_contains((string) $declined['location'], 'action=pos&payment=')
    && (string) ($declinedRow['status'] ?? '') === 'failed' && (int) ($declinedRow['invoice_id'] ?? 0) === 0
    && (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId") === $invoicesBeforeTill,
    json_encode(['status' => $declinedRow['status'] ?? null, 'to' => $declined['location']]));

$paidSession = request('POST', '/sales.php?action=pos', $cashier,
    ['csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'gateway_init', 'tender' => $tillTender]);
$paidRef = (string) ($paidSession['json']['payment']['reference'] ?? '');
$created['references'][] = $paidRef;
$approve = request('POST', '/sales.php?action=gateway_sandbox', $cashier,
    ['csrf_token' => $cashier['csrf'], 'reference' => $paidRef, 'outcome' => 'approve']);
$tillInvoice = 0;
if (preg_match('/sale_id=(\d+)/', (string) $approve['location'], $tillMatch)) {
    $tillInvoice = (int) $tillMatch[1];
}
if ($tillInvoice > 0) {
    $created['invoices'][] = $tillInvoice;
}
check('the provider saying yes is what creates the sale',
    $approve['status'] === 302 && $tillInvoice > 0 && str_contains((string) $approve['location'], 'action=receipt'),
    json_encode(['status' => $approve['status'], 'to' => $approve['location']]));
check('exactly one invoice was written for the payment',
    (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId") === $invoicesBeforeTill + 1,
    (string) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId"));
$invoiceStmt = $pdo->prepare('SELECT * FROM sales_invoices WHERE invoice_id = ?');
$invoiceStmt->execute([$tillInvoice]);
$tillInvoiceRow = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
check('the sale is the cart it quoted, priced from the catalogue',
    abs((float) ($tillInvoiceRow['total_amount'] ?? 0) - $newTotal) < 0.005 && (int) ($tillInvoiceRow['created_by'] ?? 0) === $gwCashier
    && (int) ($tillInvoiceRow['shift_id'] ?? 0) === $drawerExpected,
    json_encode(['total' => $tillInvoiceRow['total_amount'] ?? null, 'cart' => $newTotal,
                 'created_by' => $tillInvoiceRow['created_by'] ?? null]));
$tillTenderCode = (string) sqlOne($pdo, "SELECT payment_method FROM customer_payments WHERE invoice_id = $tillInvoice LIMIT 1");
check('the tender on the sale is the card method it was taken as',
    $tillTenderCode === $tillTender, json_encode($tillTenderCode));
$paidRow = gatewayTransactionByReference($pdo, $companyId, $paidRef);
check('the payment row points at the sale it created and stays marked simulated',
    (int) ($paidRow['invoice_id'] ?? 0) === $tillInvoice && (int) ($paidRow['simulates_payment'] ?? 0) === 1,
    json_encode(['invoice_id' => $paidRow['invoice_id'] ?? null, 'simulated' => $paidRow['simulates_payment'] ?? null]));

$tillPaidStatus = request('POST', '/sales.php?action=pos', $cashier,
    ['csrf_token' => $cashier['csrf'], 'ajax' => '1', 'pos_action' => 'gateway_status', 'reference' => $paidRef]);
$tillPaidBody = (array) ($tillPaidStatus['json']['payment'] ?? []);
check('the till learns the sale is recorded and where the receipt is',
    ($tillPaidBody['posted'] ?? false) === true && (int) ($tillPaidBody['invoice_id'] ?? 0) === $tillInvoice
    && str_contains((string) ($tillPaidBody['receipt_url'] ?? ''), (string) $tillInvoice),
    json_encode($tillPaidBody));
check('the cart is empty again once the payment lands',
    (int) ($tillPaidStatus['json']['cart']['count'] ?? -1) === 0, json_encode($tillPaidStatus['json']['cart']['count'] ?? null));

$approveTwice = request('POST', '/sales.php?action=gateway_sandbox', $cashier,
    ['csrf_token' => $cashier['csrf'], 'reference' => $paidRef, 'outcome' => 'approve']);
$invoicesAfterTwice = (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId");
$twiceInvoice = 0;
if (preg_match('/sale_id=(\d+)/', (string) $approveTwice['location'], $twiceMatch)) {
    $twiceInvoice = (int) $twiceMatch[1];
}
check('answering the same payment twice cannot create a second sale',
    $approveTwice['status'] === 302 && $twiceInvoice === $tillInvoice && $invoicesAfterTwice === $invoicesBeforeTill + 1,
    json_encode(['to' => $approveTwice['location'], 'invoices' => $invoicesAfterTwice]));

$webhookBody = '{"event":"charge.success","data":{"reference":"' . $paidRef . '","status":"success","amount":'
    . (int) round((float) $paidRow['amount'] * 100) . ',"currency":"' . strtoupper((string) $paidRow['currency']) . '"}}';
$webhookReplay = request('POST', '/gateway_webhook.php?gateway=paystack', [], [],
    ['x-paystack-signature: ' . hash_hmac('sha512', $webhookBody, $webhookSecret)], $webhookBody);
$acceptedEvent = (int) sqlOne($pdo, "SELECT COUNT(*) FROM gateway_events WHERE company_id = $companyId AND transaction_ref = '$paidRef' AND signature_valid = 1");
check('a correctly signed callback is accepted over HTTP',
    $webhookReplay['status'] === 200 && $acceptedEvent === 1,
    json_encode(['status' => $webhookReplay['status'], 'body' => substr($webhookReplay['body'], 0, 120), 'events' => $acceptedEvent]));
check('a provider webhook afterwards acknowledges without double-posting',
    (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId") === $invoicesAfterTwice,
    json_encode(['body' => substr($webhookReplay['body'], 0, 120), 'invoices' => $invoicesAfterTwice]));

$returnPaid = request('GET', '/sales.php?action=gateway_return&reference=' . rawurlencode($paidRef), $cashier);
check('the trip back lands on the receipt it already earned',
    $returnPaid['status'] === 302 && str_contains((string) $returnPaid['location'], 'sale_id=' . $tillInvoice)
    && (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId") === $invoicesBeforeTill + 1,
    json_encode(['to' => $returnPaid['location']]));
check('the receipt opens for the sale the gateway paid for',
    request('GET', '/sales.php?action=receipt&sale_id=' . $tillInvoice, $cashier)['status'] === 200);

// ===========================================================================
// Cleanup: the company is left as it was found
// ===========================================================================

foreach ($created['invoices'] as $invoiceId) {
    $pdo->prepare('DELETE FROM customer_payments WHERE invoice_id = ?')->execute([$invoiceId]);
    $pdo->prepare("DELETE FROM inventory_transactions WHERE reference_type = 'sales_invoice' AND reference_id = ?")->execute([$invoiceId]);
    $pdo->prepare('DELETE FROM sales_invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);
    $pdo->prepare('DELETE FROM sales_invoices WHERE invoice_id = ?')->execute([$invoiceId]);
    $pdo->prepare("DELETE FROM audit_log WHERE entity_type = 'sales_invoices' AND entity_id = ?")->execute([$invoiceId]);
}
foreach ($created['shifts'] as $shiftRow) {
    $pdo->prepare('DELETE FROM shift_movements WHERE shift_id = ?')->execute([$shiftRow]);
    $pdo->prepare('DELETE FROM cashier_shifts WHERE shift_id = ?')->execute([$shiftRow]);
    $pdo->prepare("DELETE FROM audit_log WHERE entity_type IN ('cashier_shifts','shift_movements') AND entity_id = ?")->execute([$shiftRow]);
}
foreach ($created['products'] as $productId) {
    $pdo->prepare('DELETE FROM inventory_transactions WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare('DELETE FROM product_locations WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare('DELETE FROM product_barcodes WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare('DELETE FROM sales_invoice_items WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare('DELETE FROM products WHERE product_id = ?')->execute([$productId]);
}
foreach ($created['methods'] as $createdMethodId) {
    $stmt = $pdo->prepare('SELECT code FROM payment_methods WHERE payment_method_id = ?');
    $stmt->execute([$createdMethodId]);
    $code = (string) ($stmt->fetchColumn() ?: '');
    $used = $pdo->prepare('SELECT COUNT(*) FROM customer_payments WHERE payment_method = ?');
    $used->execute([$code]);
    if ((int) $used->fetchColumn() > 0) {
        check("tender \"$code\" is referenced by payments and was left in place", false);
        continue;
    }
    $pdo->prepare('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?')->execute(['payment_methods', $createdMethodId]);
    $pdo->prepare('DELETE FROM payment_methods WHERE payment_method_id = ?')->execute([$createdMethodId]);
}
foreach (['paystack', 'flutterwave'] as $gateway) {
    $current = configRow($pdo, $companyId, $gateway);
    if ($current !== null) {
        $pdo->prepare('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?')
            ->execute(['payment_gateways', (int) $current['gateway_config_id']]);
        $pdo->prepare('DELETE FROM payment_gateways WHERE gateway_config_id = ?')->execute([(int) $current['gateway_config_id']]);
    }
    $before = $snapshot[$gateway];
    if ($before !== null) {
        unset($before['gateway_config_id']);
        $pdo->prepare(
            'INSERT INTO payment_gateways (company_id, gateway, mode, is_enabled, sandbox_allowed,
                test_public_key, test_secret_key, test_webhook_secret, live_public_key, live_secret_key, live_webhook_secret)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array_values($before));
    }
}
foreach ($created['references'] as $reference) {
    $stmt = $pdo->prepare('SELECT transaction_id, invoice_id FROM gateway_transactions WHERE company_id = ? AND reference = ?');
    $stmt->execute([$companyId, $reference]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $txId = (int) $row['transaction_id'];
        $pdo->prepare('DELETE FROM gateway_refunds WHERE transaction_id = ?')->execute([$txId]);
        $pdo->prepare('DELETE FROM audit_log WHERE entity_type IN (?, ?) AND entity_id = ?')->execute(['gateway_transactions', 'gateway_refunds', $txId]);
        $pdo->prepare('DELETE FROM gateway_transactions WHERE transaction_id = ?')->execute([$txId]);
    }
    $pdo->prepare('DELETE FROM gateway_events WHERE company_id = ? AND transaction_ref = ?')->execute([$companyId, $reference]);
}
foreach ($created['users'] as $userId) {
    $pdo->prepare('DELETE FROM audit_log WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$userId]);
}
foreach ($created['session_files'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

$leftovers = [
    'gateway rows' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM payment_gateways WHERE company_id = $companyId"),
    'gateway tenders' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM payment_methods WHERE company_id = $companyId AND gateway = 'paystack'"),
    'smoke methods' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM payment_methods WHERE company_id = $companyId AND code LIKE 'smoke%gw'"),
    'sessions' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM gateway_transactions WHERE company_id = $companyId"),
    'events' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM gateway_events WHERE company_id = $companyId"),
    'smoke users' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM users WHERE username LIKE 'gws.%'"),
    'smoke shifts' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM cashier_shifts WHERE shift_id IN (" . ($created['shifts'] === [] ? '0' : implode(',', array_map('intval', $created['shifts']))) . ")"),
    'till invoices' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE invoice_id IN (" . ($created['invoices'] === [] ? '0' : implode(',', array_map('intval', $created['invoices']))) . ")"),
    'till products' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM products WHERE sku = 'gws.smoke'"),
    'session files' => count(array_filter($created['session_files'], 'file_exists')),
];
$expectedRows = count(array_filter($snapshot));
echo "\nLeft after cleanup: " . json_encode($leftovers) . " (expected gateway rows: $expectedRows)\n";
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
