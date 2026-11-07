<?php
/**
 * Test harness for includes/barcode_functions.php.
 * Run: php scripts/test_barcode_functions.php
 * Uses a scratch product and cleans up after itself.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/barcode_functions.php';

$pdo = db();
$companyId = 1;
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

function aliasId(PDO $pdo, int $productId, string $code, int $companyId): int
{
    $stmt = $pdo->prepare('SELECT barcode_id FROM product_barcodes WHERE product_id = ? AND code = ? AND company_id = ?');
    $stmt->execute([$productId, $code, $companyId]);
    return (int) $stmt->fetchColumn();
}

// --- Check digit algorithms -------------------------------------------------
check('EAN-13 check digit of 400638133393 is 1', bcEan13CheckDigit('400638133393') === 1, (string) bcEan13CheckDigit('400638133393'));
check('EAN-13 accepts 4006381333931', bcIsEan13('4006381333931'));
check('EAN-13 rejects bad check digit', !bcIsEan13('4006381333932'));
check('EAN-8 accepts 59012344', bcIsEan8('59012344'));
check('EAN-8 rejects 59012343', !bcIsEan8('59012343'));

// --- Normalisation ----------------------------------------------------------
$n = bcNormaliseGtin('4006381333931');
check('normalise valid EAN-13', $n['ok'] && $n['code'] === '4006381333931' && $n['symbology'] === 'ean13');
$n = bcNormaliseGtin(' 4006-3813-3393-1 ');
check('normalise strips spaces/dashes', $n['ok'] && $n['code'] === '4006381333931');
$n = bcNormaliseGtin('4006381333932');
check('normalise rejects bad EAN-13 check digit', !$n['ok']);
$n = bcNormaliseGtin('59012344');
check('normalise EAN-8', $n['ok'] && $n['symbology'] === 'ean8');
$n = bcNormaliseGtin('042100005264');
check('normalise 12-digit as upc_a', $n['ok'] && $n['symbology'] === 'upc_a');
$n = bcNormaliseGtin('ABC-123');
check('normalise ASCII as code128 (dashes stripped, as on scan)', $n['ok'] && $n['symbology'] === 'code128' && $n['code'] === 'ABC123', $n['code']);
$n = bcNormaliseGtin(str_repeat('9', 61));
check('normalise rejects overlong code', !$n['ok']);
$n = bcNormaliseGtin('');
check('normalise rejects empty', !$n['ok']);

// --- Internal code generation ----------------------------------------------
check('internal code for product 1 matches stored value', bcGenerateInternalCode(1) === '2000000000015', bcGenerateInternalCode(1));
check('internal code is a valid GTIN-13', bcIsEan13(bcGenerateInternalCode(40102)));
check('internal code uses GS1 internal-use 2xx prefix', str_starts_with(bcGenerateInternalCode(99), '2'));

// --- Lookup against real seeded data ---------------------------------------
$hit = bcLookup($pdo, '2000000000015', $companyId);
check('lookup by primary barcode', $hit !== null && (int) $hit['product_id'] === 1 && $hit['matched_by'] === 'barcode');
$hit = bcLookup($pdo, 'WID-002', $companyId);
check('lookup by SKU', $hit !== null && (int) $hit['product_id'] === 2 && $hit['matched_by'] === 'sku');
$hit = bcLookup($pdo, '9999999999999', $companyId);
check('lookup of unknown code returns null', $hit === null);
$hit = bcLookup($pdo, '2000000000015', 999);
check('lookup is company-scoped', $hit === null);
$branchId = (int) $pdo->query('SELECT branch_id FROM product_locations WHERE company_id = 1 ORDER BY on_hand_quantity DESC LIMIT 1')->fetchColumn();
$hit = bcLookup($pdo, '2000000000015', $companyId, $branchId ?: null);
check('branch-scoped lookup resolves with numeric stock figure', $hit !== null && (int) $hit['product_id'] === 1 && is_numeric($hit['on_hand'] ?? null), 'branch ' . $branchId);
$all = bcLookup($pdo, '2000000000015', $companyId);
$scoped = bcLookup($pdo, '2000000000015', $companyId, $branchId);
check('branch scope reduces stock to that branch', $branchId <= 0 || (float) $scoped['on_hand'] <= (float) $all['on_hand'], "{$scoped['on_hand']} vs {$all['on_hand']}");

// --- Alias add / primary switch / removal (scratch product) ----------------
// Audit rows require an authenticated actor (audit_log.user_id has an FK).
$_SESSION['user_id'] = 1;
$_SESSION['company_id'] = $companyId;
$scratch = '__bc_test_' . getmypid();
$pdo->prepare('INSERT INTO products (company_id, sku, name, track_inventory, manage_stock, sales_price, cost_price, is_active) VALUES (?,?,?,?,?,?,?,1)')
    ->execute([$companyId, $scratch, $scratch, 0, 0, 1, 1]);
$pid = (int) $pdo->lastInsertId();

try {
    $internal = bcEnsureProductBarcode($pdo, $pid, $companyId);
    check('ensure internal barcode on new product', $internal === bcGenerateInternalCode($pid), $internal);
    check('ensure is idempotent', bcEnsureProductBarcode($pdo, $pid, $companyId) === $internal);

    $res = bcAddCode($pdo, $pid, $companyId, '4006381333931', 'gs1');
    check('add external EAN-13', !empty($res['ok']), $res['message'] ?? '');
    $stmt = $pdo->prepare('SELECT barcode FROM products WHERE product_id = ?');
    $stmt->execute([$pid]);
    check('first EAN-13 becomes primary', $stmt->fetchColumn() === '4006381333931');
    check('internal code retained as alias', aliasId($pdo, $pid, $internal, $companyId) > 0);

    $dup = bcAddCode($pdo, $pid, $companyId, '4006381333931');
    check('re-adding same code stays ok (idempotent)', !empty($dup['ok']), $dup['message'] ?? '');

    $bad = bcAddCode($pdo, $pid, $companyId, '4006381333932');
    check('invalid check digit refused', empty($bad['ok']));

    $other = bcAddCode($pdo, 1, $companyId, '4006381333931');
    check('cross-product collision refused', empty($other['ok']), $other['message'] ?? '');
    if (!empty($other['ok'])) {
        echo "      (cleanup needed: code leaked to product 1)\n";
    }

    $extId = aliasId($pdo, $pid, '4006381333931', $companyId);
    $intId = aliasId($pdo, $pid, $internal, $companyId);
    check('both codes present in alias table', $extId > 0 && $intId > 0);

    $rmPrimary = bcRemoveCode($pdo, $extId, $companyId);
    check('removing the primary code is refused', empty($rmPrimary['ok']), $rmPrimary['message'] ?? '');

    $set = bcSetPrimary($pdo, $intId, $companyId);
    check('set internal code primary', !empty($set['ok']), $set['message'] ?? '');
    $stmt = $pdo->prepare('SELECT barcode FROM products WHERE product_id = ?');
    $stmt->execute([$pid]);
    check('primary now internal code', $stmt->fetchColumn() === $internal);

    $rm = bcRemoveCode($pdo, $extId, $companyId);
    check('remove non-primary alias', !empty($rm['ok']), $rm['message'] ?? '');
    $hit = bcLookup($pdo, '4006381333931', $companyId);
    check('removed alias no longer resolves', $hit === null || (int) $hit['product_id'] !== $pid);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE entity_type = ?');
    $stmt->execute(['product_barcodes']);
    check('barcode changes audited', (int) $stmt->fetchColumn() >= 3);
} finally {
    $pdo->prepare('DELETE FROM product_barcodes WHERE product_id = ?')->execute([$pid]);
    $pdo->prepare('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?')->execute(['product_barcodes', $pid]);
    $pdo->prepare('DELETE FROM products WHERE product_id = ?')->execute([$pid]);
}

$left = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE sku LIKE "__bc_test_%"')->fetchColumn();
check('scratch product cleaned up', $left === 0);
$orphans = (int) $pdo->query('SELECT COUNT(*) FROM product_barcodes pb LEFT JOIN products p ON p.product_id = pb.product_id WHERE p.product_id IS NULL')->fetchColumn();
check('no orphan alias rows', $orphans === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
