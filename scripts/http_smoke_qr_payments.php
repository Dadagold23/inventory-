<?php
/**
 * HTTP smoke test for QR Code Scan-to-Pay: the admin screen, its forms, the
 * SVG preview/download and the permission and tenancy edges, exercised through
 * Apache the way a browser reaches them.
 *
 * Run: php scripts/http_smoke_qr_payments.php
 *
 * It writes only rows it removes again: one branch, one tender, three users,
 * the pay points created through the screen, and the audit rows those writes
 * leave behind.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/qr_payment_functions.php';

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

function pointIdFromLocation(string $location): int
{
    return preg_match('/[?&]edit=(\d+)/', $location, $m) === 1 ? (int) $m[1] : 0;
}

// ===========================================================================
// What already exists, so it can be put back
// ===========================================================================

$mark = [
    'point_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(qr_point_id), 0) FROM qr_payment_points'),
    'branch_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(branch_id), 0) FROM branches'),
    'method_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(payment_method_id), 0) FROM payment_methods'),
    'user_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(user_id), 0) FROM users'),
    'audit_id' => (int) sqlOne($pdo, 'SELECT COALESCE(MAX(audit_id), 0) FROM audit_log'),
];

$teardownDone = false;
$teardown = static function () use ($pdo, $companyId, $otherCompanyId, $mark, &$teardownDone): void {
    if ($teardownDone) {
        return;
    }
    $teardownDone = true;
    try {
        $pdo->exec("DELETE FROM audit_log WHERE audit_id > {$mark['audit_id']} AND entity_type = 'qr_payment_points'");
        $pdo->exec("DELETE FROM qr_payment_points WHERE qr_point_id > {$mark['point_id']}");
        $pdo->exec("DELETE FROM qr_payment_points WHERE code LIKE 'qr-http-%'");
        $pdo->exec("DELETE FROM payment_methods WHERE code LIKE 'qr_http_%'");
        $pdo->exec("DELETE FROM branches WHERE code LIKE 'QR-HTTP%'");
        $pdo->exec("DELETE FROM users WHERE username IN ('qr.http.staff', 'qr.http.other')");

        $leftPoints = (int) $pdo->query('SELECT COUNT(*) FROM qr_payment_points')->fetchColumn();
        $leftUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username IN ('qr.http.staff', 'qr.http.other')")->fetchColumn();
        echo "TEARDOWN  qr points {$leftPoints}, test users {$leftUsers}\n";
    } catch (Throwable $e) {
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

$pdo->exec("DELETE FROM qr_payment_points WHERE code LIKE 'qr-http-%'");
$pdo->exec("DELETE FROM payment_methods WHERE code LIKE 'qr_http_%'");
$pdo->exec("DELETE FROM branches WHERE code LIKE 'QR-HTTP%'");
$pdo->exec("DELETE FROM users WHERE username IN ('qr.http.staff', 'qr.http.other')");

$pdo->prepare('INSERT INTO branches (company_id, name, code, is_active, sort_order) VALUES (?, ?, ?, 1, 90)')
    ->execute([$companyId, 'QR HTTP Branch', 'QR-HTTP']);
$branchId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO payment_methods (company_id, code, name, is_cash, is_online, is_active, sort_order)
               VALUES (?, ?, ?, 0, 1, 1, 0)')
    ->execute([$companyId, 'qr_http_tender', 'QR HTTP Tender']);
$methodId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO users (company_id, branch_id, username, password_hash, full_name, email, role, is_active)
               VALUES (?, NULL, ?, ?, ?, ?, ?, 1)')
    ->execute([$companyId, 'qr.http.staff', password_hash('smoke-test-only', PASSWORD_DEFAULT),
        'QR HTTP Staff', 'qr.http.staff@example.test', 'staff']);
$staffId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO users (company_id, branch_id, username, password_hash, full_name, email, role, is_active)
               VALUES (?, NULL, ?, ?, ?, ?, ?, 1)')
    ->execute([$otherCompanyId, 'qr.http.other', password_hash('smoke-test-only', PASSWORD_DEFAULT),
        'QR Elsewhere Admin', 'qr.http.other@example.test', 'admin']);
$otherAdminId = (int) $pdo->lastInsertId();

$adminId = (int) sqlOne($pdo, "SELECT user_id FROM users WHERE role = 'admin' AND company_id = $companyId ORDER BY user_id LIMIT 1");
$admin = craftSession($pdo, $adminId);
$staff = craftSession($pdo, $staffId);
$elsewhere = craftSession($pdo, $otherAdminId);

$listPath = '/qr_payments?action=list';

$baseFields = static function (array $overrides = []) use ($branchId, $methodId): array {
    return array_merge([
        'action' => 'save_qr_point',
        'qr_point_id' => '0',
        'name' => 'HTTP Counter Point',
        'code' => 'qr-http-open',
        'branch_id' => (string) $branchId,
        'payment_method_id' => (string) $methodId,
        'merchant_reference' => 'HTTP-REF',
        'scan_url' => 'https://pay.example.test/checkout?store=HTTP',
        'amount_type' => 'open',
        'amount' => '',
        'ecc' => 'M',
        'description' => 'Created by the HTTP smoke test',
        'is_active' => '1',
        'sort_order' => '10',
    ], $overrides);
};

// ===========================================================================
// A. Who may open the screen
// ===========================================================================

echo "\n== A. who may open the screen ==\n";

$anon = request('GET', $listPath);
check('an anonymous visitor is sent to the login page',
    $anon['status'] === 302 && str_contains($anon['location'], 'login'),
    json_encode(['status' => $anon['status'], 'to' => $anon['location']]));

$adminPage = request('GET', $listPath, $admin);
check('the administrator gets the QR pay point screen', $adminPage['status'] === 200, (string) $adminPage['status']);
check('the page is named and linked from the administration navigation',
    str_contains($adminPage['body'], 'QR Code Scan-to-Pay')
    && str_contains($adminPage['body'], 'QR Scan-to-Pay')
    && str_contains($adminPage['body'], 'href="/inventory/qr_payments?action=list"'));
check('the page explains what the QR actually contains',
    str_contains($adminPage['body'], 'How a scan-to-pay code works')
    && str_contains($adminPage['body'], 'amount')
    && str_contains($adminPage['body'], 'reference'));
check('the create form is offered to an administrator',
    str_contains($adminPage['body'], 'Add QR Pay Point')
    && str_contains($adminPage['body'], 'name="scan_url"')
    && str_contains($adminPage['body'], 'name="amount_type"')
    && str_contains($adminPage['body'], 'name="ecc"'));

$staffPage = request('GET', $listPath, $staff);
check('a staff user with view permission can open the screen',
    $staffPage['status'] === 200 && str_contains($staffPage['body'], 'Your role can view QR pay points'),
    (string) $staffPage['status']);
check('a view-only staff user is not offered the create form',
    !str_contains($staffPage['body'], 'Add QR Pay Point'));

$staffPost = request('POST', $listPath, $staff, array_merge($baseFields(), ['csrf_token' => $staff['csrf']]));
check('a view-only staff user cannot create a point', $staffPost['status'] === 403, (string) $staffPost['status']);

// ===========================================================================
// B. Create, preview and download
// ===========================================================================

echo "\n== B. create, preview and download ==\n";

$create = request('POST', $listPath, $admin, array_merge($baseFields(), ['csrf_token' => $admin['csrf']]));
$openId = pointIdFromLocation($create['location']);
check('creating an open-amount point redirects to its edit/preview page',
    $create['status'] === 302 && $openId > 0 && str_contains($create['location'], 'action=list&edit='),
    json_encode(['status' => $create['status'], 'to' => $create['location']]));
check('the created point is in the database with the submitted branch and tender',
    (int) sqlOne($pdo, "SELECT COUNT(*) FROM qr_payment_points WHERE qr_point_id = $openId
                         AND company_id = $companyId AND branch_id = $branchId AND payment_method_id = $methodId") === 1);

$editPage = request('GET', '/qr_payments?action=list&edit=' . $openId, $admin);
check('the edit page shows the saved name, code, branch and tender',
    $editPage['status'] === 200
    && str_contains($editPage['body'], 'HTTP Counter Point')
    && str_contains($editPage['body'], 'qr-http-open')
    && str_contains($editPage['body'], 'QR HTTP Branch')
    && str_contains($editPage['body'], 'QR HTTP Tender'));
check('the edit page renders a preview and the encoded destination',
    str_contains($editPage['body'], 'Preview: HTTP Counter Point')
    && str_contains($editPage['body'], '<svg')
    && str_contains($editPage['body'], 'https://pay.example.test/checkout?store=HTTP'));
check('the edit page offers a full-size preview and a download',
    str_contains($editPage['body'], 'action=preview&amp;id=' . $openId)
    && str_contains($editPage['body'], 'action=download&amp;id=' . $openId)
    && str_contains($editPage['body'], 'Download SVG'));

$preview = request('GET', '/qr_payments?action=preview&id=' . $openId, $admin);
check('the preview route answers with an SVG',
    $preview['status'] === 200
    && str_contains((string) ($preview['headers']['content-type'] ?? ''), 'image/svg+xml')
    && str_contains($preview['body'], '<svg')
    && str_contains($preview['body'], 'viewBox'),
    json_encode(['status' => $preview['status'], 'type' => $preview['headers']['content-type'] ?? '']));

$download = request('GET', '/qr_payments?action=download&id=' . $openId, $admin);
check('the download route is an attachment named after the point code',
    $download['status'] === 200
    && str_contains((string) ($download['headers']['content-disposition'] ?? ''), 'attachment')
    && str_contains((string) ($download['headers']['content-disposition'] ?? ''), 'qr-pay-qr-http-open.svg')
    && str_contains($download['body'], '<svg'),
    json_encode(['status' => $download['status'], 'disposition' => $download['headers']['content-disposition'] ?? '']));

$anonPreview = request('GET', '/qr_payments?action=preview&id=' . $openId);
check('an anonymous visitor cannot fetch a QR preview',
    $anonPreview['status'] === 302 && str_contains($anonPreview['location'], 'login'),
    (string) $anonPreview['status']);

$elsewherePreview = request('GET', '/qr_payments?action=preview&id=' . $openId, $elsewhere);
check('another company cannot fetch the preview', $elsewherePreview['status'] === 404, (string) $elsewherePreview['status']);

// ===========================================================================
// C. Fixed amount
// ===========================================================================

echo "\n== C. fixed amount ==\n";

$fixedCreate = request('POST', $listPath, $admin, array_merge($baseFields(), [
    'csrf_token' => $admin['csrf'],
    'name' => 'HTTP Fixed Point',
    'code' => 'qr-http-fixed',
    'merchant_reference' => 'HTTP-REF',
    'scan_url' => 'https://pay.example.test/deposit?currency=NGN#form',
    'amount_type' => 'fixed',
    'amount' => '125.50',
    'ecc' => 'Q',
]));
$fixedId = pointIdFromLocation($fixedCreate['location']);
check('creating a fixed-amount point redirects to its page', $fixedCreate['status'] === 302 && $fixedId > 0,
    json_encode(['status' => $fixedCreate['status'], 'to' => $fixedCreate['location']]));
$fixedPage = request('GET', '/qr_payments?action=list&edit=' . $fixedId, $admin);
check('the fixed amount and reference are shown in the encoded destination',
    str_contains($fixedPage['body'], '125.50')
    && str_contains($fixedPage['body'], 'amount=125.50')
    && str_contains($fixedPage['body'], 'reference=HTTP-REF'),
    'fixed page');
check('the fixed point is stored as fixed with its error-correction level',
    (int) sqlOne($pdo, "SELECT COUNT(*) FROM qr_payment_points WHERE qr_point_id = $fixedId
                         AND amount_type = 'fixed' AND amount = 125.50 AND ecc = 'Q'") === 1);
$fixedPreview = request('GET', '/qr_payments?action=preview&id=' . $fixedId, $admin);
check('the fixed point previews as an SVG too',
    $fixedPreview['status'] === 200 && str_contains($fixedPreview['body'], '<svg'));

// ===========================================================================
// D. Update and toggle
// ===========================================================================

echo "\n== D. update and toggle ==\n";

$updateFields = array_merge($baseFields(), [
    'csrf_token' => $admin['csrf'],
    'qr_point_id' => (string) $openId,
    'name' => 'HTTP Counter Renamed',
    'amount_type' => 'fixed',
    'amount' => '42.00',
    'ecc' => 'H',
    'sort_order' => '5',
]);
// A real unchecked checkbox is absent from the POST body, not "0".
unset($updateFields['is_active']);
$update = request('POST', $listPath, $admin, $updateFields);
check('updating a point redirects back to it',
    $update['status'] === 302 && pointIdFromLocation($update['location']) === $openId,
    json_encode(['status' => $update['status'], 'to' => $update['location']]));
check('the update changed the name, amount rule, error correction and active flag',
    (int) sqlOne($pdo, "SELECT COUNT(*) FROM qr_payment_points WHERE qr_point_id = $openId
                         AND name = 'HTTP Counter Renamed' AND amount_type = 'fixed'
                         AND amount = 42.00 AND ecc = 'H' AND is_active = 0") === 1);
$updatedPage = request('GET', '/qr_payments?action=list&edit=' . $openId, $admin);
check('the edit page shows the new values and the inactive badge',
    str_contains($updatedPage['body'], 'HTTP Counter Renamed')
    && str_contains($updatedPage['body'], 'amount=42.00')
    && str_contains($updatedPage['body'], 'Inactive'));

$activate = request('POST', $listPath, $admin, [
    'csrf_token' => $admin['csrf'],
    'action' => 'toggle_qr_point',
    'qr_point_id' => (string) $openId,
    'target_active' => '1',
]);
check('a point can be activated from the list', $activate['status'] === 302
    && (int) sqlOne($pdo, "SELECT is_active FROM qr_payment_points WHERE qr_point_id = $openId") === 1);
$activatedPage = request('GET', $listPath, $admin);
check('the activation is confirmed on the next page',
    str_contains($activatedPage['body'], 'activated'));

$deactivate = request('POST', $listPath, $admin, [
    'csrf_token' => $admin['csrf'],
    'action' => 'toggle_qr_point',
    'qr_point_id' => (string) $openId,
    'target_active' => '0',
]);
check('a point can be deactivated without losing the row', $deactivate['status'] === 302
    && (int) sqlOne($pdo, "SELECT COUNT(*) FROM qr_payment_points WHERE qr_point_id = $openId AND is_active = 0") === 1);

// ===========================================================================
// E. Validation, CSRF and duplicate codes
// ===========================================================================

echo "\n== E. validation, CSRF and duplicate codes ==\n";

$badCode = request('POST', $listPath, $admin, array_merge($baseFields(), [
    'csrf_token' => $admin['csrf'],
    'name' => 'HTTP Bad Code',
    'code' => 'Bad Code',
]));
check('an invalid code is refused with a redirect back to the form',
    $badCode['status'] === 302 && str_contains($badCode['location'], 'action=list&new=1'),
    json_encode(['status' => $badCode['status'], 'to' => $badCode['location']]));
$badPage = request('GET', '/qr_payments?action=list&new=1', $admin);
check('the validation message is shown and the typed name is kept',
    str_contains($badPage['body'], 'must start with a lowercase letter')
    && str_contains($badPage['body'], 'value="HTTP Bad Code"'),
    'validation page');

$duplicate = request('POST', $listPath, $admin, array_merge($baseFields(), [
    'csrf_token' => $admin['csrf'],
    'name' => 'HTTP Duplicate',
    'code' => 'qr-http-fixed',
]));
$duplicatePage = request('GET', $listPath, $admin);
check('a duplicate code is refused by name',
    $duplicate['status'] === 302 && str_contains($duplicatePage['body'], 'already exists'));

$noCsrf = request('POST', $listPath, $admin, array_merge($baseFields(), ['code' => 'qr-http-nocsrf']));
check('a POST without a CSRF token is rejected', $noCsrf['status'] === 403, (string) $noCsrf['status']);
check('the rejected POST wrote nothing',
    (int) sqlOne($pdo, "SELECT COUNT(*) FROM qr_payment_points WHERE code = 'qr-http-nocsrf'") === 0);

// ===========================================================================
// F. Another company
// ===========================================================================

echo "\n== F. another company ==\n";

$elsewhereList = request('GET', $listPath, $elsewhere);
check('another company sees an empty list, not this company\'s points',
    $elsewhereList['status'] === 200
    && !str_contains($elsewhereList['body'], 'HTTP Counter Renamed')
    && !str_contains($elsewhereList['body'], 'HTTP Fixed Point'),
    (string) $elsewhereList['status']);

$elsewhereEdit = request('GET', '/qr_payments?action=list&edit=' . $openId, $elsewhere);
check('another company cannot open this company\'s edit form',
    $elsewhereEdit['status'] === 200
    && !str_contains($elsewhereEdit['body'], 'HTTP Counter Renamed')
    && !str_contains($elsewhereEdit['body'], 'Preview: HTTP Counter Renamed'));

$elsewhereUpdate = request('POST', $listPath, $elsewhere, array_merge($baseFields(), [
    'csrf_token' => $elsewhere['csrf'],
    'qr_point_id' => (string) $openId,
    'name' => 'Stolen Point',
    'code' => 'qr-http-open',
]));
check('another company cannot update this company\'s point',
    $elsewhereUpdate['status'] === 302
    && (int) sqlOne($pdo, "SELECT COUNT(*) FROM qr_payment_points WHERE company_id = $otherCompanyId
                           AND code = 'qr-http-open'") === 0);
check('the original point is unchanged by the foreign attempt',
    (int) sqlOne($pdo, "SELECT COUNT(*) FROM qr_payment_points WHERE qr_point_id = $openId
                         AND company_id = $companyId AND name = 'HTTP Counter Renamed'") === 1);

$elsewhereCreate = request('POST', $listPath, $elsewhere, array_merge($baseFields(), [
    'csrf_token' => $elsewhere['csrf'],
    'name' => 'Elsewhere Point',
    'code' => 'qr-http-elsewhere',
    'branch_id' => '0',
    'payment_method_id' => '0',
]));
$elsewherePointId = pointIdFromLocation($elsewhereCreate['location']);
check('another company can create its own point with the same code pattern',
    $elsewhereCreate['status'] === 302 && $elsewherePointId > 0
    && (int) sqlOne($pdo, "SELECT COUNT(*) FROM qr_payment_points WHERE qr_point_id = $elsewherePointId
                           AND company_id = $otherCompanyId") === 1);
$elsewhereDelete = request('POST', $listPath, $elsewhere, [
    'csrf_token' => $elsewhere['csrf'],
    'action' => 'delete_qr_point',
    'qr_point_id' => (string) $elsewherePointId,
]);
check('that company can delete its own point', $elsewhereDelete['status'] === 302
    && (int) sqlOne($pdo, "SELECT COUNT(*) FROM qr_payment_points WHERE qr_point_id = $elsewherePointId") === 0);

// ===========================================================================
// G. Delete
// ===========================================================================

echo "\n== G. delete ==\n";

$deleteOpen = request('POST', $listPath, $admin, [
    'csrf_token' => $admin['csrf'],
    'action' => 'delete_qr_point',
    'qr_point_id' => (string) $openId,
]);
$deleteFixed = request('POST', $listPath, $admin, [
    'csrf_token' => $admin['csrf'],
    'action' => 'delete_qr_point',
    'qr_point_id' => (string) $fixedId,
]);
check('both points can be deleted',
    $deleteOpen['status'] === 302 && $deleteFixed['status'] === 302
    && (int) sqlOne($pdo, "SELECT COUNT(*) FROM qr_payment_points WHERE qr_point_id IN ($openId, $fixedId)") === 0);
$afterDelete = request('GET', $listPath, $admin);
// The first GET consumes the deletion flash, which names the points; the
// second is the clean list a user sees afterwards.
$afterDelete = request('GET', $listPath, $admin);
check('the deleted points are gone from the list',
    !str_contains($afterDelete['body'], 'HTTP Counter Renamed')
    && !str_contains($afterDelete['body'], 'HTTP Fixed Point'));

// ===========================================================================
// Teardown evidence
// ===========================================================================

echo "\n== teardown evidence ==\n";

$ours = [
    'points' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM qr_payment_points WHERE qr_point_id > {$mark['point_id']}"),
    'branches' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM branches WHERE code LIKE 'QR-HTTP%'"),
    'methods' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM payment_methods WHERE code LIKE 'qr_http_%'"),
    'users' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM users WHERE username IN ('qr.http.staff', 'qr.http.other')"),
    'audit' => (int) sqlOne($pdo, "SELECT COUNT(*) FROM audit_log WHERE audit_id > {$mark['audit_id']} AND entity_type = 'qr_payment_points'"),
];
check('both points were deleted through the screen and no test point remains',
    $ours['points'] === 0, json_encode($ours));
check('the sweep removes the branch, tender, users and audit rows',
    $ours['branches'] === 1 && $ours['methods'] === 1
    && $ours['users'] === 2 && $ours['audit'] >= 5,
    json_encode($ours));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
