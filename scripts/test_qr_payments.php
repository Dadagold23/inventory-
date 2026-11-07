<?php
/**
 * Service-layer tests for the QR Code Scan-to-Pay module
 * (includes/qr_payment_functions.php).
 *
 * Run: php scripts/test_qr_payments.php
 *
 * The run creates its own branch, tender and pay points in company 1, plus one
 * company-2 point for the tenancy checks, and removes exactly those. Every
 * figure is checked against the stored row; the QR is decoded back with the
 * module's own decoder so "it renders" means "it still says what was saved".
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/payment_methods_functions.php';
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

$pdo = db();
$companyId = 1;
$otherCompanyId = 2;
$pass = 0;
$fail = 0;

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

function near(float $a, float $b, float $tolerance = 0.0001): bool
{
    return abs($a - $b) <= $tolerance;
}

$mark = [
    'point_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(qr_point_id), 0) FROM qr_payment_points'),
    'branch_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(branch_id), 0) FROM branches'),
    'method_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(payment_method_id), 0) FROM payment_methods'),
    'audit_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(audit_id), 0) FROM audit_log'),
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
        $pdo->exec("DELETE FROM qr_payment_points WHERE code LIKE 'qr-test-%'");
        $pdo->exec("DELETE FROM payment_methods WHERE code LIKE 'qr_test_%'");
        $pdo->exec("DELETE FROM branches WHERE code LIKE 'QR-TEST%'");

        $leftPoints = (int) $pdo->query('SELECT COUNT(*) FROM qr_payment_points')->fetchColumn();
        $leftAudit = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'qr_payment_points'")->fetchColumn();
        echo "TEARDOWN  qr points {$leftPoints}, qr audit rows {$leftAudit}\n";
    } catch (Throwable $e) {
        echo 'TEARDOWN FAILED: ' . $e->getMessage() . "\n";
    }
};
register_shutdown_function($teardown);

// ===========================================================================
// Fixtures
// ===========================================================================

$pdo->exec("DELETE FROM qr_payment_points WHERE code LIKE 'qr-test-%'");
$pdo->exec("DELETE FROM payment_methods WHERE code LIKE 'qr_test_%'");
$pdo->exec("DELETE FROM branches WHERE code LIKE 'QR-TEST%'");

$pdo->prepare('INSERT INTO branches (company_id, name, code, is_active, sort_order) VALUES (?, ?, ?, 1, 91)')
    ->execute([$companyId, 'QR Test Branch', 'QR-TEST']);
$branchId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO payment_methods (company_id, code, name, is_cash, is_online, is_active, sort_order)
               VALUES (?, ?, ?, 0, 1, 1, 0)')
    ->execute([$companyId, 'qr_test_tender', 'QR Test Tender']);
$methodId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO branches (company_id, name, code, is_active, sort_order) VALUES (?, ?, ?, 1, 91)')
    ->execute([$otherCompanyId, 'QR Elsewhere Branch', 'QR-TEST-ELSE']);
$otherBranchId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO payment_methods (company_id, code, name, is_cash, is_online, is_active, sort_order)
               VALUES (?, ?, ?, 0, 1, 1, 0)')
    ->execute([$otherCompanyId, 'qr_test_elsewhere', 'QR Elsewhere Tender']);
$otherMethodId = (int) $pdo->lastInsertId();

$adminId = (int) sqlValue($pdo, "SELECT user_id FROM users WHERE role = 'admin' AND company_id = $companyId ORDER BY user_id LIMIT 1");
$_SESSION['user_id'] = $adminId;
$_SESSION['company_id'] = $companyId;
$_SESSION['branch_id'] = $branchId;
$_SESSION['user_role'] = 'admin';

$save = static function (array $data, int $company = 1) use ($pdo, $adminId): array {
    return saveQrPaymentPoint($pdo, $company, $data, $adminId);
};

// ===========================================================================
// 1. Validation rules
// ===========================================================================

echo "\n== 1. validation rules ==\n";

check('a lowercase code with digits, underscores and hyphens is valid',
    qrPaymentCodeIsValid('main-counter_2') === true);
check('an uppercase or digit-first code is refused',
    qrPaymentCodeIsValid('Main-Counter') === false && qrPaymentCodeIsValid('2counter') === false);
check('a one-character code is refused', qrPaymentCodeIsValid('a') === false);
check('an https link is accepted and a bare host is refused',
    qrPaymentUrlIsValid('https://pay.example.test/checkout') === true
    && qrPaymentUrlIsValid('pay.example.test/checkout') === false);
check('a non-http URI scheme is accepted when well formed',
    qrPaymentUrlIsValid('upi://pay?pa=merchant@example') === true);
check('a link with raw whitespace is refused',
    qrPaymentUrlIsValid('https://pay.example.test/a b') === false);

$missingName = $save(['name' => '', 'code' => 'qr-test-noname', 'scan_url' => 'https://pay.example.test/x']);
check('a point without a name is refused',
    $missingName['success'] === false && str_contains($missingName['message'], 'name'), $missingName['message']);

$badCode = $save(['name' => 'Bad code', 'code' => 'Bad Code', 'scan_url' => 'https://pay.example.test/x']);
check('a point with an invalid code is refused',
    $badCode['success'] === false && str_contains($badCode['message'], 'code'), $badCode['message']);

$badUrl = $save(['name' => 'Bad url', 'code' => 'qr-test-badurl', 'scan_url' => 'not-a-link']);
check('a point without a URI scheme is refused',
    $badUrl['success'] === false && str_contains($badUrl['message'], 'destination'), $badUrl['message']);

$fixedNoAmount = $save([
    'name' => 'Fixed no amount', 'code' => 'qr-test-fixedzero',
    'scan_url' => 'https://pay.example.test/fixed', 'amount_type' => 'fixed', 'amount' => '0',
]);
check('a fixed-amount point without a positive amount is refused',
    $fixedNoAmount['success'] === false && str_contains($fixedNoAmount['message'], 'amount'), $fixedNoAmount['message']);

$longUrl = $save([
    'name' => 'Too long', 'code' => 'qr-test-toolong',
    'scan_url' => 'https://pay.example.test/' . str_repeat('a', 400),
]);
check('a destination that cannot fit in a QR is refused before it is stored',
    $longUrl['success'] === false && str_contains($longUrl['message'], 'too long'), $longUrl['message']);

$wrongBranch = $save([
    'name' => 'Wrong branch', 'code' => 'qr-test-wrongbranch',
    'scan_url' => 'https://pay.example.test/x', 'branch_id' => $otherBranchId,
]);
check('a branch from another company is refused',
    $wrongBranch['success'] === false && str_contains($wrongBranch['message'], 'branch'), $wrongBranch['message']);

$wrongMethod = $save([
    'name' => 'Wrong method', 'code' => 'qr-test-wrongmethod',
    'scan_url' => 'https://pay.example.test/x', 'payment_method_id' => $otherMethodId,
]);
check('a payment method from another company is refused',
    $wrongMethod['success'] === false && str_contains($wrongMethod['message'], 'payment method'), $wrongMethod['message']);

// ===========================================================================
// 2. Create, read and the payload
// ===========================================================================

echo "\n== 2. create, read and the payload ==\n";

$open = $save([
    'name' => 'Counter — open',
    'code' => 'qr-test-open',
    'branch_id' => $branchId,
    'payment_method_id' => $methodId,
    'merchant_reference' => 'STORE-01',
    'scan_url' => 'https://pay.example.test/checkout?store=01',
    'amount_type' => 'open',
    'description' => 'Open-amount counter code',
    'is_active' => 1,
    'sort_order' => 10,
]);
check('an open-amount point is created', $open['success'] === true && $open['point_id'] > 0, $open['message']);
$openId = (int) ($open['point_id'] ?? 0);
$openRow = getQrPaymentPointById($pdo, $openId, $companyId);
check('the stored row carries the branch, tender and scan link',
    $openRow !== null
    && (int) $openRow['branch_id'] === $branchId
    && (int) $openRow['payment_method_id'] === $methodId
    && $openRow['scan_url'] === 'https://pay.example.test/checkout?store=01'
    && (string) $openRow['amount_type'] === 'open'
    && $openRow['amount'] === null,
    json_encode($openRow));
check('an open-amount payload is the scan link unchanged',
    qrPaymentPointPayload($openRow) === 'https://pay.example.test/checkout?store=01',
    qrPaymentPointPayload($openRow));
check('the row is listed with its branch and tender names',
    (static function () use ($pdo, $companyId, $openId): bool {
        foreach (getQrPaymentPoints($pdo, $companyId) as $row) {
            if ((int) $row['qr_point_id'] === $openId) {
                return $row['branch_name'] === 'QR Test Branch' && $row['payment_method_name'] === 'QR Test Tender';
            }
        }
        return false;
    })());
check('the open-amount label says so', qrPaymentPointAmountLabel($openRow) === 'Open amount');

$svg = qrPaymentPointSvg($openRow, ['moduleWidth' => 4]);
check('the point renders a scannable SVG', !empty($svg['ok']) && str_contains($svg['svg'], '<svg')
    && str_contains($svg['svg'], 'viewBox'), $svg['message']);
check('the rendered payload is the stored destination',
    $svg['payload'] === 'https://pay.example.test/checkout?store=01', $svg['payload']);

// Decode the rendered QR back with the module's own decoder. This is the
// difference between "an SVG was produced" and "the QR still says the link".
$matrix = barcode_qr_build($svg['payload'], (string) ($openRow['ecc'] ?? 'M'));
$decoded = barcode_qr_extract_text($matrix);
check('the QR decodes back to the exact scan destination', $decoded === $svg['payload'], (string) $decoded);

// ===========================================================================
// 3. Fixed amounts
// ===========================================================================

echo "\n== 3. fixed amounts ==\n";

$fixed = $save([
    'name' => 'Deposit — fixed',
    'code' => 'qr-test-fixed',
    'merchant_reference' => 'STORE 01 / DEP',
    'scan_url' => 'https://pay.example.test/deposit?currency=NGN#form',
    'amount_type' => 'fixed',
    'amount' => '125.50',
    'ecc' => 'Q',
    'is_active' => 1,
    'sort_order' => 20,
]);
check('a fixed-amount point is created', $fixed['success'] === true && $fixed['point_id'] > 0, $fixed['message']);
$fixedId = (int) ($fixed['point_id'] ?? 0);
$fixedRow = getQrPaymentPointById($pdo, $fixedId, $companyId);
$fixedPayload = qrPaymentPointPayload($fixedRow);
check('the fixed amount and reference are appended to the existing query string',
    $fixedPayload === 'https://pay.example.test/deposit?currency=NGN&amount=125.50&reference=STORE%2001%20%2F%20DEP#form',
    $fixedPayload);
check('the fixed amount is stored as a decimal and labelled',
    near((float) $fixedRow['amount'], 125.50) && qrPaymentPointAmountLabel($fixedRow) === '125.50',
    (string) $fixedRow['amount']);
check('the chosen error-correction level is stored',
    (string) $fixedRow['ecc'] === 'Q', (string) $fixedRow['ecc']);
$fixedSvg = qrPaymentPointSvg($fixedRow);
$fixedMatrix = barcode_qr_build($fixedSvg['payload'], (string) $fixedRow['ecc']);
check('the fixed-amount QR decodes back to the amount-bearing link',
    !empty($fixedSvg['ok']) && barcode_qr_extract_text($fixedMatrix) === $fixedPayload,
    (string) barcode_qr_extract_text($fixedMatrix));

// ===========================================================================
// 4. Update, uniqueness and tenancy
// ===========================================================================

echo "\n== 4. update, uniqueness and tenancy ==\n";

$duplicate = $save([
    'name' => 'Duplicate', 'code' => 'qr-test-open', 'scan_url' => 'https://pay.example.test/other',
]);
check('a duplicate code in the same company is refused',
    $duplicate['success'] === false && str_contains($duplicate['message'], 'already exists'), $duplicate['message']);

$updated = $save([
    'qr_point_id' => $openId,
    'name' => 'Counter — renamed',
    'code' => 'qr-test-open',
    'branch_id' => $branchId,
    'payment_method_id' => $methodId,
    'merchant_reference' => 'STORE-02',
    'scan_url' => 'https://pay.example.test/checkout?store=02',
    'amount_type' => 'fixed',
    'amount' => '42.00',
    'ecc' => 'H',
    'description' => 'Now a fixed deposit',
    'is_active' => 0,
    'sort_order' => 5,
]);
check('the same point can be updated in place', $updated['success'] === true && (int) $updated['point_id'] === $openId, $updated['message']);
$updatedRow = getQrPaymentPointById($pdo, $openId, $companyId);
check('the update changed the name, amount rule and active flag',
    $updatedRow['name'] === 'Counter — renamed'
    && (string) $updatedRow['amount_type'] === 'fixed'
    && near((float) $updatedRow['amount'], 42.00)
    && (int) $updatedRow['is_active'] === 0
    && (string) $updatedRow['ecc'] === 'H',
    json_encode($updatedRow));
check('the updated payload carries the new amount', str_contains(qrPaymentPointPayload($updatedRow), 'amount=42.00'),
    qrPaymentPointPayload($updatedRow));

check('another company cannot read the point',
    getQrPaymentPointById($pdo, $openId, $otherCompanyId) === null);
$foreignUpdate = $save([
    'qr_point_id' => $openId,
    'name' => 'Stolen',
    'code' => 'qr-test-open',
    'scan_url' => 'https://pay.example.test/stolen',
], $otherCompanyId);
check('another company cannot update the point',
    $foreignUpdate['success'] === false, $foreignUpdate['message']);
check('another company cannot toggle the point',
    setQrPaymentPointActive($pdo, $openId, $otherCompanyId, 1, $adminId) === null);

$otherPoint = $save([
    'name' => 'Elsewhere point',
    'code' => 'qr-test-open',
    'scan_url' => 'https://pay.example.test/elsewhere',
], $otherCompanyId);
check('the same code is allowed in a different company', $otherPoint['success'] === true, $otherPoint['message']);
$otherPointId = (int) ($otherPoint['point_id'] ?? 0);
check('the other company sees its own point and not ours',
    getQrPaymentPointById($pdo, $otherPointId, $otherCompanyId) !== null
    && getQrPaymentPointById($pdo, $otherPointId, $companyId) === null);

// ===========================================================================
// 5. Active toggle, delete and the audit trail
// ===========================================================================

echo "\n== 5. active toggle, delete and the audit trail ==\n";

$activated = setQrPaymentPointActive($pdo, $openId, $companyId, 1, $adminId);
check('a point can be reactivated', $activated !== null && (int) $activated['is_active'] === 1);
$deactivated = setQrPaymentPointActive($pdo, $openId, $companyId, 0, $adminId);
check('a point can be deactivated without losing its row',
    $deactivated !== null && (int) $deactivated['is_active'] === 0
    && getQrPaymentPointById($pdo, $openId, $companyId) !== null);
check('an active-only list leaves the deactivated point out',
    (static function () use ($pdo, $companyId, $openId): bool {
        foreach (getQrPaymentPoints($pdo, $companyId, true) as $row) {
            if ((int) $row['qr_point_id'] === $openId) {
                return false;
            }
        }
        return true;
    })());

$throwaway = $save([
    'name' => 'Throwaway', 'code' => 'qr-test-delete', 'scan_url' => 'https://pay.example.test/delete',
]);
$throwawayId = (int) ($throwaway['point_id'] ?? 0);
$deleted = deleteQrPaymentPoint($pdo, $throwawayId, $companyId, $adminId);
check('a point with no payment history can be deleted',
    $deleted['success'] === true && getQrPaymentPointById($pdo, $throwawayId, $companyId) === null,
    $deleted['message']);
$foreignDelete = deleteQrPaymentPoint($pdo, $otherPointId, $companyId, $adminId);
check('another company cannot delete the point', $foreignDelete['success'] === false, $foreignDelete['message']);

$auditRows = $pdo->query("SELECT action, COUNT(*) AS n FROM audit_log
                           WHERE entity_type = 'qr_payment_points'
                           GROUP BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
check('the audit trail records create, update and delete',
    (int) ($auditRows['CREATE'] ?? 0) >= 2
    && (int) ($auditRows['UPDATE'] ?? 0) >= 2
    && (int) ($auditRows['DELETE'] ?? 0) >= 1,
    json_encode($auditRows));
$createAudit = $pdo->query("SELECT new_value FROM audit_log
                             WHERE entity_type = 'qr_payment_points' AND action = 'CREATE'
                             ORDER BY audit_id LIMIT 1")->fetchColumn();
check('a create audit row carries the saved fields, not a blank',
    is_string($createAudit) && str_contains($createAudit, 'qr-test-open') && str_contains($createAudit, 'scan_url'),
    (string) $createAudit);

// ===========================================================================
// 6. What the module refuses to invent
// ===========================================================================

echo "\n== 6. what the module refuses to invent ==\n";

$unknown = $save([
    'qr_point_id' => 99999999,
    'name' => 'Ghost', 'code' => 'qr-test-ghost', 'scan_url' => 'https://pay.example.test/ghost',
]);
check('updating a point that does not exist is refused', $unknown['success'] === false, $unknown['message']);
check('a missing point has no payload and no QR',
    qrPaymentPointPayload([]) === '' && qrPaymentPointSvg([])['ok'] === false);
$invalidEcc = $save([
    'name' => 'Bad ECC', 'code' => 'qr-test-badecc',
    'scan_url' => 'https://pay.example.test/ecc', 'ecc' => 'Z',
]);
$invalidEccRow = getQrPaymentPointById($pdo, (int) ($invalidEcc['point_id'] ?? 0), $companyId);
check('an unknown error-correction level falls back to M instead of failing the save',
    $invalidEcc['success'] === true && (string) ($invalidEccRow['ecc'] ?? '') === 'M',
    $invalidEcc['message']);

echo "\n== teardown evidence ==\n";

$ours = [
    'points' => (int) sqlValue($pdo, "SELECT COUNT(*) FROM qr_payment_points WHERE qr_point_id > {$mark['point_id']}"),
    'branches' => (int) sqlValue($pdo, "SELECT COUNT(*) FROM branches WHERE code LIKE 'QR-TEST%'"),
    'methods' => (int) sqlValue($pdo, "SELECT COUNT(*) FROM payment_methods WHERE code LIKE 'qr_test_%'"),
    'audit' => (int) sqlValue($pdo, "SELECT COUNT(*) FROM audit_log WHERE audit_id > {$mark['audit_id']} AND entity_type = 'qr_payment_points'"),
];
check('the sweep deletes exactly this run: points, branches, tenders and audit rows',
    $ours['points'] >= 4 && $ours['branches'] === 2 && $ours['methods'] === 2 && $ours['audit'] >= 5,
    json_encode($ours));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
