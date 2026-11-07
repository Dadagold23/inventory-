<?php
/**
 * Test harness for Phase C: cashier shifts, drawer movements, reconciliation
 * and the POS checkout path (shift gate, idempotency, split tender, change).
 *
 * Run: php scripts/test_shift_pos.php
 * Creates throwaway products/branches/shifts/sales in company 1 and deletes
 * everything it made, including any pre-existing TEST-marked leftovers.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/inventory_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/shift_functions.php';
require_once APP_ROOT . '/includes/barcode_functions.php';

// CLI: let fatals print a real trace instead of the app's generic error page.
restore_error_handler();
restore_exception_handler();

$pdo = db();
$pass = 0;
$fail = 0;
$created = [
    'invoices' => [],
    'shifts' => [],
    'branches' => [],
    'products' => [],
    'users' => [],
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

function asUser(PDO $pdo, int $userId, string $username, string $fullName, string $role): void
{
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['full_name'] = $fullName;
    $_SESSION['user_role'] = $role;
    $_SESSION['last_activity'] = time();
}

$companyId = 1;
$branchId = 1;
$cashierId = 24;      // seeded cashier
$otherUserId = 19;    // staff: shifts.update but not shifts.delete
$managerId = 1;       // admin bypass

$originalTax = salesSetting($pdo, 'default_tax_rate', '0');
$originalRequire = salesSetting($pdo, 'pos_requires_shift', '1');

// --- Fixtures ---------------------------------------------------------------

$_SESSION['company_id'] = $companyId;
$_SESSION['branch_id'] = $branchId;
$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
asUser($pdo, $cashierId, 'shifttest.cashier', 'Shift Test Cashier', 'cashier');

// Two priced products with known stock, so totals are predictable.
function makeTestProduct(PDO $pdo, int $companyId, string $sku, float $price, float $cost, float $stock, int $branchId): int
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
    )->execute([$companyId, $sku, 'Shift test ' . $sku, $price, $cost]);
    $productId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO product_locations (company_id, product_id, branch_id, on_hand_quantity, reserved_quantity, available_quantity, on_hand_value)
         VALUES (?, ?, ?, ?, 0, ?, ?)"
    )->execute([$companyId, $productId, $branchId, $stock, $stock, $stock * $cost]);
    return $productId;
}

$productA = makeTestProduct($pdo, $companyId, 'SHIFT-A', 40.00, 10.00, 100.0, $branchId);
$productB = makeTestProduct($pdo, $companyId, 'SHIFT-B', 7.50, 3.00, 50.0, $branchId);
$created['products'] = [$productA, $productB];

// A second branch, to prove shifts are scoped per branch.
$pdo->prepare("INSERT INTO branches (company_id, name, code, is_active, sort_order) VALUES (?, 'Shift Test Branch', 'SHIFT-BR', 1, 99)")
    ->execute([$companyId]);
$altBranch = (int) $pdo->lastInsertId();
$created['branches'] = [$altBranch];

$pdo->prepare("INSERT INTO product_locations (company_id, product_id, branch_id, on_hand_quantity, reserved_quantity, available_quantity, on_hand_value)
               VALUES (?, ?, ?, 20, 0, 20, ?)")->execute([$companyId, $productA, $altBranch, 200.0]);

$invoiceCount = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM sales_invoices")->fetchColumn();
$stockOnHand = static function (int $productId, int $branch) use ($pdo): float {
    $stmt = $pdo->prepare("SELECT on_hand_quantity FROM product_locations WHERE product_id = ? AND branch_id = ?");
    $stmt->execute([$productId, $branch]);
    return (float) $stmt->fetchColumn();
};
$shiftRow = static function (int $shiftId) use ($pdo): ?array {
    $stmt = $pdo->prepare("SELECT * FROM cashier_shifts WHERE shift_id = ?");
    $stmt->execute([$shiftId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

// ===========================================================================
// 1. Opening a shift
// ===========================================================================

$open = shiftOpen($pdo, $cashierId, $companyId, $branchId, 50.00, 'Test float');
check('shift opens', $open['success'] && $open['shift_id'] > 0, json_encode($open));
$created['shifts'][] = $open['shift_id'];
$shift = $shiftRow((int) $open['shift_id']);
check('shift row is open with the posted float',
    $shift && $shift['status'] === 'open' && (float) $shift['opening_float'] === 50.00);
check('the opening float is not also a movement (no double counting)',
    (int) $pdo->query("SELECT COUNT(*) FROM shift_movements WHERE shift_id = " . (int) $open['shift_id'])->fetchColumn() === 0);
check('shift is audited',
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='cashier_shifts' AND entity_id=" . (int) $open['shift_id'])->fetchColumn() > 0);

$again = shiftOpen($pdo, $cashierId, $companyId, $branchId, 25.00);
check('a second shift for the same cashier+branch is refused',
    !$again['success'] && str_contains($again['message'], 'already open') && (int) $again['shift_id'] === (int) $open['shift_id'],
    json_encode($again));

$otherBranch = shiftOpen($pdo, $cashierId, $companyId, $altBranch, 10.00);
check('the same cashier may hold a shift at another branch', $otherBranch['success']);
if ($otherBranch['success']) {
    $created['shifts'][] = $otherBranch['shift_id'];
}

$negative = shiftOpen($pdo, $cashierId, $companyId, $altBranch, -5.0);
check('negative float is refused', !$negative['success']);

$noUser = shiftOpen($pdo, 0, $companyId, $branchId, 10.0);
check('a shift needs a signed-in cashier', !$noUser['success']);

$foreignShift = shiftGet($pdo, (int) $open['shift_id'], 999);
check('another company cannot read the shift', $foreignShift === null);

// ===========================================================================
// 2. Drawer movements and the reconciliation formula
// ===========================================================================

$totals = shiftTotals($pdo, $shift);
check('fresh shift expects exactly its float', (float) $totals['expected_cash'] === 50.0, json_encode($totals['expected_cash']));

check('paid in accepted', shiftAddMovement($pdo, (int) $open['shift_id'], 'paid_in', 20.0, $companyId, 'Owner top-up')['success']);
check('paid out accepted', shiftAddMovement($pdo, (int) $open['shift_id'], 'paid_out', 5.0, $companyId, 'Stakeholder petty cash')['success']);
check('cash refund accepted', shiftAddMovement($pdo, (int) $open['shift_id'], 'cash_refund', 3.0, $companyId, 'Customer returned an item')['success']);

$shift = $shiftRow((int) $open['shift_id']);
$totals = shiftTotals($pdo, $shift);
// Expected = Float + Cash Receipts + Paid In - Cash Refunds - Paid Out
check('reconciliation: 50 + 0 + 20 - 3 - 5 = 62', (float) $totals['expected_cash'] === 62.0, json_encode([
    'float' => $totals['float'], 'cash' => $totals['cash_receipts'], 'in' => $totals['paid_in'],
    'refund' => $totals['cash_refunds'], 'out' => $totals['paid_out'], 'expected' => $totals['expected_cash'],
]));
check('zero amount refused', !shiftAddMovement($pdo, (int) $open['shift_id'], 'paid_in', 0.0, $companyId)['success']);
check('negative amount refused', !shiftAddMovement($pdo, (int) $open['shift_id'], 'paid_out', -4.0, $companyId)['success']);
check('unknown movement type refused', !shiftAddMovement($pdo, (int) $open['shift_id'], 'write_off', 4.0, $companyId)['success']);
check('movement on another company shift refused', !shiftAddMovement($pdo, 999999, 'paid_in', 4.0, $companyId)['success']);
check('movement referencing a foreign invoice refused',
    !shiftAddMovement($pdo, (int) $open['shift_id'], 'cash_refund', 4.0, $companyId, 'x', 999999)['success']);

// ===========================================================================
// 3. POS: the shift gate
// ===========================================================================

posClearCart();
$gateBefore = $invoiceCount();
asUser($pdo, $otherUserId, 'shifttest.other', 'Other User', 'staff');
$gateAdd = posAddLine($pdo, $productA, 1);
check('cart can be built without a shift', $gateAdd['success']);
$gate = posCheckout($pdo, ['payment_method' => 'cash', 'idempotency_key' => 'gatecheck0001']);
check('checkout without an open shift is refused', !$gate['success'] && stripos($gate['message'], 'shift') !== false, json_encode($gate));
// Compared with a baseline, not zero: a refused checkout must add nothing, but
// the table is not empty on an install that has ever sold anything.
$gateInvoices = $invoiceCount();
check('refused checkout created no invoice', $gateInvoices === $gateBefore);
asUser($pdo, $cashierId, 'shifttest.cashier', 'Shift Test Cashier', 'cashier');

// ===========================================================================
// 4. POS: prices are the server's, never the browser's
// ===========================================================================

posClearCart();
$add = posAddLine($pdo, $productA, 2);
check('two units added', $add['success'], json_encode($add));
$_SESSION['pos_cart'][0]['unit_price'] = 1.00;    // simulate a tampered session
$_SESSION['pos_total'] = 0.01;
recalculatePosTotals($pdo);
$expectedTotal = 2 * 40.00 * (1 + (float) $originalTax);
check('tampered cart price is overwritten from the product table',
    abs((float) $_SESSION['pos_total'] - $expectedTotal) < 0.001,
    'total ' . $_SESSION['pos_total'] . ' expected ' . $expectedTotal);

$tooMuch = posAddLine($pdo, $productB, 9999);
check('over-stock quantity refused at add', !$tooMuch['success'] && str_contains($tooMuch['message'], 'stock'), json_encode($tooMuch));

$overCart = $_SESSION['pos_cart'];
$overCart[] = ['product_id' => $productB, 'product_name' => 'x', 'sku' => '', 'quantity' => 9999.0, 'unit_price' => 7.5, 'unit_cost' => 3, 'discount_percent' => 0];
$_SESSION['pos_cart'] = $overCart;
$steal = posCheckout($pdo, ['payment_method' => 'cash', 'idempotency_key' => 'overstock000001']);
check('checkout cannot oversell past on-hand stock', !$steal['success'] && str_contains($steal['message'], 'stock'), json_encode($steal));
check('failed checkout left stock untouched', $stockOnHand($productB, $branchId) === 50.0);

// ===========================================================================
// 5. POS: cash sale with change, attributed to the shift
// ===========================================================================

posClearCart();
posAddLine($pdo, $productA, 2);
$cartTotal = (float) $_SESSION['pos_total'];
$invoicesBefore = $invoiceCount();
$stockBefore = $stockOnHand($productA, $branchId);

$key = 'phasectest' . bin2hex(random_bytes(8));
$sale = posCheckout($pdo, [
    'payment_method' => 'cash',
    'payment_amount' => $cartTotal + 20.00,   // customer hands over 20 more than owed
    'reference_number' => 'TEST-CASH',
    'notes' => 'Phase C test sale',
    'idempotency_key' => $key,
]);
check('cash checkout succeeds', $sale['success'], json_encode($sale));
if ($sale['success']) {
    $created['invoices'][] = (int) $sale['invoice_id'];
}
check('change is the tender above the bill', isset($sale['change']) && abs((float) $sale['change'] - 20.0) < 0.001, json_encode($sale['change'] ?? null));
check('cart is cleared after checkout', ($_SESSION['pos_cart'] ?? []) === []);
check('exactly one invoice was created', $invoiceCount() === $invoicesBefore + 1);

$invoice = $pdo->prepare("SELECT * FROM sales_invoices WHERE invoice_id = ?");
$invoice->execute([(int) $sale['invoice_id']]);
$invoice = $invoice->fetch(PDO::FETCH_ASSOC);
check('invoice is bound to the open shift', $invoice && (int) $invoice['shift_id'] === (int) $open['shift_id']);
check('invoice is fully paid', $invoice && $invoice['status'] === 'paid' && (float) $invoice['amount_owed'] === 0.0);
check('invoice total matches the server cart', $invoice && abs((float) $invoice['total_amount'] - $cartTotal) < 0.001);

$payStmt = $pdo->prepare("SELECT * FROM customer_payments WHERE invoice_id = ?");
$payStmt->execute([(int) $sale['invoice_id']]);
$payment = $payStmt->fetch(PDO::FETCH_ASSOC);
check('payment row carries the shift', $payment && (int) $payment['shift_id'] === (int) $open['shift_id']);
check('cash receipt booked at the bill, not the tender',
    $payment && abs((float) $payment['amount'] - $cartTotal) < 0.001, $payment ? (string) $payment['amount'] : 'none');

$shift = $shiftRow((int) $open['shift_id']);
$totals = shiftTotals($pdo, $shift);
check('cash receipts pick up the sale', abs((float) $totals['cash_receipts'] - $cartTotal) < 0.001, json_encode($totals['cash_receipts']));
check('expected after sale = 62 + bill', abs((float) $totals['expected_cash'] - (62.0 + $cartTotal)) < 0.001, json_encode($totals['expected_cash']));
check('stock was decremented by the sale', $stockOnHand($productA, $branchId) === $stockBefore - 2.0);
check('a sale ledger row exists', (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_type='sales_invoice' AND reference_id=" . (int) $sale['invoice_id'])->fetchColumn() === 1);

// --- idempotency ---
$replay = posCheckout($pdo, [
    'payment_method' => 'cash',
    'payment_amount' => $cartTotal + 20.00,
    'idempotency_key' => $key,
]);
check('the same key replays the original sale', $replay['success'] && !empty($replay['duplicate']) && (int) $replay['invoice_id'] === (int) $sale['invoice_id'], json_encode($replay));
check('replay adds no invoice', $invoiceCount() === $invoicesBefore + 1);
check('replay adds no stock movement', $stockOnHand($productA, $branchId) === $stockBefore - 2.0);
$afterReplay = shiftTotals($pdo, $shiftRow((int) $open['shift_id']));
check('replay does not double the receipts', abs((float) $afterReplay['cash_receipts'] - $cartTotal) < 0.001);

// ===========================================================================
// 6. POS: split tender and non-cash takings
// ===========================================================================

posClearCart();
posAddLine($pdo, $productA, 1);   // 40 (+tax)
posAddLine($pdo, $productB, 2);   // 15 (+tax)
$splitTotal = (float) $_SESSION['pos_total'];
$cashPart = round($splitTotal / 2, 2);

$before = shiftTotals($pdo, $shiftRow((int) $open['shift_id']));
$split = posCheckout($pdo, [
    'payments' => [
        ['method' => 'cash', 'amount' => $cashPart],
        ['method' => 'bank_transfer', 'amount' => $splitTotal - $cashPart],
    ],
    'notes' => 'Phase C split test',
    'idempotency_key' => 'split' . bin2hex(random_bytes(8)),
]);
check('split tender succeeds', $split['success'], json_encode($split));
if ($split['success']) {
    $created['invoices'][] = (int) $split['invoice_id'];
}
$payStmt = $pdo->prepare("SELECT payment_method, amount FROM customer_payments WHERE invoice_id = ? ORDER BY payment_id");
$payStmt->execute([(int) ($split['invoice_id'] ?? 0)]);
$parts = $payStmt->fetchAll(PDO::FETCH_ASSOC);
check('two payment rows are stored', count($parts) === 2, json_encode($parts));

$splitInvoice = $pdo->prepare("SELECT payment_method, status, amount_paid, total_amount FROM sales_invoices WHERE invoice_id = ?");
$splitInvoice->execute([(int) ($split['invoice_id'] ?? 0)]);
$splitInvoice = $splitInvoice->fetch(PDO::FETCH_ASSOC);
check('split invoice is marked split and paid',
    $splitInvoice && $splitInvoice['payment_method'] === 'split' && $splitInvoice['status'] === 'paid'
    && abs((float) $splitInvoice['amount_paid'] - (float) $splitInvoice['total_amount']) < 0.001,
    json_encode($splitInvoice));

$after = shiftTotals($pdo, $shiftRow((int) $open['shift_id']));
check('only the cash half moves the drawer',
    abs((float) $after['cash_receipts'] - ((float) $before['cash_receipts'] + $cashPart)) < 0.001,
    json_encode(['before' => $before['cash_receipts'], 'after' => $after['cash_receipts'], 'half' => $cashPart]));
check('non-cash receipts are reported separately',
    abs((float) $after['non_cash_receipts'] - ($splitTotal - $cashPart)) < 0.001, json_encode($after['non_cash_receipts']));

// under-payment on a split tender is refused
posClearCart();
posAddLine($pdo, $productA, 1);
$shortTotal = (float) $_SESSION['pos_total'];
$short = posCheckout($pdo, [
    'payments' => [
        ['method' => 'cash', 'amount' => 1.0],
        ['method' => 'bank_transfer', 'amount' => 1.0],
    ],
    'idempotency_key' => 'short' . bin2hex(random_bytes(8)),
]);
check('a split tender that does not cover the bill is refused',
    !$short['success'] && str_contains($short['message'], 'total'), json_encode(['msg' => $short['message'], 'bill' => $shortTotal]));

$badMethod = posCheckout($pdo, ['payment_method' => 'crypto', 'idempotency_key' => 'badmethod00001']);
check('an unknown payment method is refused', !$badMethod['success'] && str_contains($badMethod['message'], 'crypto'), json_encode($badMethod['message'] ?? ''));
posClearCart();

// ===========================================================================
// 7. Cross-company isolation
// ===========================================================================

$foreignCompany = (int) $pdo->query("SELECT company_id FROM companies WHERE company_id <> {$companyId} ORDER BY company_id LIMIT 1")->fetchColumn();
if ($foreignCompany) {
    $foreignProduct = makeTestProduct($pdo, $foreignCompany, 'SHIFT-FOREIGN', 12.00, 4.00, 25.0, $branchId);
    $created['products'][] = $foreignProduct;
    $cross = posAddLine($pdo, $foreignProduct, 1);
    check('another company product cannot be added to the cart',
        !$cross['success'] && str_contains((string) ($cross['message'] ?? ''), 'your company'), json_encode($cross['message'] ?? ''));
    $searchForeign = posSearchProducts($pdo, 'SHIFT-FOREIGN', $companyId, $branchId);
    check('POS search hides another company product',
        count(array_filter($searchForeign, static fn ($r) => (int) $r['product_id'] === $foreignProduct)) === 0,
        json_encode(array_column($searchForeign, 'product_id')));
    $scanForeign = bcLookup($pdo, (string) $foreignProduct, $companyId);
    check('barcode lookup is scoped to the signed-in company',
        $scanForeign === null || (int) $scanForeign['product_id'] !== $foreignProduct);
} else {
    echo "SKIP  cross-company isolation (only one company in the database)\n";
}

// ===========================================================================
// 8. Closing, voiding and the enforcement switch
// ===========================================================================

$shift = $shiftRow((int) $open['shift_id']);
$expected = (float) shiftTotals($pdo, $shift)['expected_cash'];
$shortCount = shiftClose($pdo, (int) $open['shift_id'], $companyId, $expected - 2.0, 'Two notes missing');
check('closing counts the drawer', $shortCount['success'], json_encode($shortCount['message']));
$closed = $shiftRow((int) $open['shift_id']);
check('variance is counted minus expected', $closed && abs((float) $closed['variance'] + 2.0) < 0.001, json_encode($closed['variance'] ?? null));
check('closed shift stores expected cash', $closed && (float) $closed['expected_cash'] === $expected);
check('closing is audited',
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type='cashier_shifts' AND action='UPDATE' AND entity_id=" . (int) $open['shift_id'])->fetchColumn() > 0);

$reclose = shiftClose($pdo, (int) $open['shift_id'], $companyId, 0.0, 'again');
check('a closed shift cannot be closed twice', !$reclose['success']);
$movAfterClose = shiftAddMovement($pdo, (int) $open['shift_id'], 'paid_in', 5.0, $companyId);
check('movements are refused on a closed shift', !$movAfterClose['success']);
$voidWithSales = shiftVoidIfEmpty($pdo, (int) $open['shift_id'], $companyId);
check('a shift with sales cannot be voided', !$voidWithSales['success'] && str_contains($voidWithSales['message'], 'close'), json_encode($voidWithSales['message']));

// another cashier may not close someone else's shift
asUser($pdo, $otherUserId, 'shifttest.other', 'Other User', 'staff');
$theirs = shiftOpen($pdo, $otherUserId, $companyId, $branchId, 20.0);
$created['shifts'][] = $theirs['shift_id'];
asUser($pdo, $cashierId, 'shifttest.cashier', 'Shift Test Cashier', 'cashier');
$notMine = shiftClose($pdo, (int) $theirs['shift_id'], $companyId, 20.0, 'takeover');
check('another cashier cannot close my shift', !$notMine['success'], json_encode($notMine['message']));
check('cashier cannot read another cashier shift', shiftGet($pdo, (int) $theirs['shift_id'], $companyId) === null);
asUser($pdo, $managerId, 'admin', 'Admin', 'admin');
$forced = shiftClose($pdo, (int) $theirs['shift_id'], $companyId, null, 'manager cleanup', true);
check('a manager can force close', $forced['success'], json_encode($forced['message']));
$forcedRow = $shiftRow((int) $theirs['shift_id']);
check('force close is flagged and leaves variance unknown',
    $forcedRow && (int) $forcedRow['force_closed'] === 1 && $forcedRow['variance'] === null);

// empty shift can be voided
asUser($pdo, $otherUserId, 'shifttest.other', 'Other Test User', 'staff');
$empty = shiftOpen($pdo, $otherUserId, $companyId, $branchId, 0.0);
$created['shifts'][] = $empty['shift_id'];
$voided = shiftVoidIfEmpty($pdo, (int) $empty['shift_id'], $companyId);
check('an untouched shift can be voided', $voided['success'], json_encode($voided['message']));
check('voided shift is gone', $shiftRow((int) $empty['shift_id']) === null);

// enforcement switch
asUser($pdo, $managerId, 'admin', 'Admin', 'admin');
$pdo->prepare("INSERT INTO settings (`key`, value, company_id) VALUES ('pos_requires_shift', '0', ?)
               ON DUPLICATE KEY UPDATE value = '0'")->execute([$companyId]);
asUser($pdo, $otherUserId, 'shifttest.other', 'Other User', 'staff');
posClearCart();
posAddLine($pdo, $productA, 1);
$noShiftSale = posCheckout($pdo, ['payment_method' => 'cash', 'idempotency_key' => 'nope' . bin2hex(random_bytes(6))]);
check('enforcement can be switched off for the company', $noShiftSale['success'], json_encode($noShiftSale['message']));
if ($noShiftSale['success']) {
    $created['invoices'][] = (int) $noShiftSale['invoice_id'];
    $row = $pdo->prepare("SELECT shift_id FROM sales_invoices WHERE invoice_id = ?");
    $row->execute([(int) $noShiftSale['invoice_id']]);
    check('a shiftless sale records no shift', $row->fetchColumn() === null);
}
$defs = appSettingDefinitions($pdo);
check('the enforcement switch is editable from Application Settings',
    isset($defs['pos_requires_shift']) && $defs['pos_requires_shift']['type'] === 'bool' && $defs['pos_requires_shift']['default'] === '1');
$savedSwitch = saveAppSettings($pdo, $companyId, ['pos_requires_shift' => $originalRequire]);
check('the settings service writes the switch back', $savedSwitch['ok'], json_encode($savedSwitch['message'] ?? ''));
check('enforcement is restored through the admin path', shiftEnforcementEnabled($pdo) === ($originalRequire !== '0'));

$summary = shiftSummaryForUser($pdo, $companyId, $branchId);
check('summary reports no open shift for that user', $summary['open'] === false && $summary['allowed'] === false, json_encode($summary));

asUser($pdo, $managerId, 'admin', 'Admin', 'admin');
posClearCart();
$list = shiftList($pdo, $companyId, ['status' => 'closed']);
$allClosed = $list !== [] && count(array_filter($list, static fn ($r) => $r['status'] === 'closed')) === count($list);
check('shift register filters by status', $allClosed);
check('shift register rows carry recomputed totals', isset($list[0]['totals']['expected_cash']));
check('shift register is company-scoped', count(array_filter($list, static fn ($r) => (int) $r['company_id'] !== $companyId)) === 0);

// ===========================================================================
// Cleanup
// ===========================================================================

$pdo->beginTransaction();
try {
    foreach ($created['invoices'] as $invoiceId) {
        $pdo->prepare("DELETE FROM customer_payments WHERE invoice_id = ?")->execute([$invoiceId]);
        $pdo->prepare("DELETE FROM inventory_transactions WHERE reference_type = 'sales_invoice' AND reference_id = ?")->execute([$invoiceId]);
        $pdo->prepare("DELETE FROM sales_invoice_items WHERE invoice_id = ?")->execute([$invoiceId]);
        $pdo->prepare("DELETE FROM sales_invoices WHERE invoice_id = ?")->execute([$invoiceId]);
        // Only this test's own audit rows: history of real records stays.
        $pdo->prepare("DELETE FROM audit_log WHERE entity_type = 'sales_invoices' AND entity_id = ?")->execute([$invoiceId]);
    }
    foreach ($created['shifts'] as $sid) {
        $pdo->prepare("DELETE FROM shift_movements WHERE shift_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM cashier_shifts WHERE shift_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM audit_log WHERE entity_type IN ('cashier_shifts','shift_movements') AND entity_id = ?")->execute([$sid]);
    }
    foreach ($created['products'] as $pid) {
        $pdo->prepare("DELETE FROM product_locations WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM product_barcodes WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$pid]);
    }
    foreach ($created['branches'] as $bid) {
        $pdo->prepare("DELETE FROM product_locations WHERE branch_id = ?")->execute([$bid]);
        $pdo->prepare("DELETE FROM branches WHERE branch_id = ?")->execute([$bid]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo 'CLEANUP FAILED: ' . $e->getMessage() . "\n";
}

$leftShifts = (int) $pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE shift_id IN (" . implode(',', array_map('intval', $created['shifts'])) . ")")->fetchColumn();
$leftInvoices = (int) $pdo->query("SELECT COUNT(*) FROM sales_invoices WHERE invoice_id IN (" . implode(',', array_map('intval', $created['invoices'])) . ")")->fetchColumn();
$leftProducts = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE product_id IN (" . implode(',', array_map('intval', $created['products'])) . ")")->fetchColumn();
$leftPayments = (int) $pdo->query("SELECT COUNT(*) FROM customer_payments WHERE shift_id IS NOT NULL")->fetchColumn();
echo "\nLeft after cleanup: shifts={$leftShifts} invoices={$leftInvoices} products={$leftProducts} shift-tagged payments={$leftPayments}\n";
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
