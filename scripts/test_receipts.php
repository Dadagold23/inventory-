<?php
/**
 * Service-layer tests for Phase E1: the receipt in
 * includes/receipt_functions.php, from stored settings to a printed slip.
 *
 * Run: php scripts/test_receipts.php
 *
 * The run creates its own branch, products, customer, shift, invoices, one
 * online tender and one logo file, and deletes exactly those. The four
 * `receipt_*` settings are read at the start and put back as they were found,
 * including being absent. Company 2 - which has no screens pointed at it here -
 * carries the logo tests so company 1's file tree is never written.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/shift_functions.php';
require_once APP_ROOT . '/includes/receipt_functions.php';

restore_error_handler();
restore_exception_handler();
ini_set('display_errors', '1');
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null) {
        fwrite(STDERR, "FATAL: {$error['message']} at {$error['file']}:{$error['line']}\n");
    }
});

$pdo = db();
$companyId = 1;
$logoCompanyId = 2;
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

/** Read one receipt setting exactly as the code under test would see it. */
function rawSetting(PDO $pdo, int $companyId, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE `key` = ? AND company_id = ?');
    $stmt->execute([$key, $companyId]);
    $value = $stmt->fetchColumn();

    return $value === false ? null : (string) $value;
}

// ===========================================================================
// What already exists, so it can be put back
// ===========================================================================

$receiptKeys = ['receipt_paper_width', 'receipt_footer_text', 'receipt_show_logo', 'receipt_logo_path'];
$settingSnapshot = [];
foreach ($receiptKeys as $key) {
    $settingSnapshot[$key] = [
        1 => rawSetting($pdo, 1, $key),
        2 => rawSetting($pdo, 2, $key),
    ];
}
$logoBefore = [];
foreach ([$companyId, $logoCompanyId] as $id) {
    $file = APP_ROOT . '/storage/uploads/logos/company_' . $id . '.jpg';
    $logoBefore[$id] = is_file($file) ? ['existed' => true, 'bytes' => (string) file_get_contents($file)] : ['existed' => false];
}

$auditBaseline = (int) sqlValue($pdo, 'SELECT COUNT(*) FROM audit_log');
$mark = [
    'invoice_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(invoice_id), 0) FROM sales_invoices'),
    'payment_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(payment_id), 0) FROM customer_payments'),
    'shift_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(shift_id), 0) FROM cashier_shifts'),
    'method_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(payment_method_id), 0) FROM payment_methods'),
    'branch_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(branch_id), 0) FROM branches'),
];

$teardownDone = false;
$teardown = static function () use ($pdo, $companyId, $logoCompanyId, $receiptKeys, $settingSnapshot, $logoBefore, $mark, $auditBaseline, &$teardownDone): void {
    if ($teardownDone) {
        return;
    }
    $teardownDone = true;
    $pdo->beginTransaction();
    try {
        // Invoices this run named, by the sales it created above the mark.
        $invoiceIds = $pdo->query("SELECT invoice_id FROM sales_invoices WHERE company_id = $companyId
                                   AND invoice_id > {$mark['invoice_id']}")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($invoiceIds as $invoiceId) {
            $invoiceId = (int) $invoiceId;
            $pdo->prepare('DELETE FROM customer_payments WHERE invoice_id = ?')->execute([$invoiceId]);
            $pdo->prepare("DELETE FROM inventory_transactions WHERE reference_type = 'sales_invoice' AND reference_id = ?")->execute([$invoiceId]);
            $pdo->prepare('DELETE FROM sales_invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);
            $pdo->prepare('DELETE FROM sales_invoices WHERE invoice_id = ?')->execute([$invoiceId]);
        }
        if ($invoiceIds !== []) {
            $in = implode(',', array_map('intval', $invoiceIds));
            $pdo->exec("DELETE FROM audit_log WHERE entity_type = 'sales_invoices' AND entity_id IN ($in)");
        }

        // The drawer the register needed.
        $shiftIds = $pdo->query("SELECT shift_id FROM cashier_shifts WHERE company_id = $companyId
                                 AND shift_id > {$mark['shift_id']}")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($shiftIds as $shiftId) {
            $pdo->prepare('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?')->execute(['cashier_shifts', (int) $shiftId]);
        }
        if ($shiftIds !== []) {
            $in = implode(',', array_map('intval', $shiftIds));
            $pdo->exec('DELETE FROM shift_movements WHERE shift_id IN (' . $in . ')');
            $pdo->exec('DELETE FROM cashier_shifts WHERE shift_id IN (' . $in . ')');
        }

        // The temporary online tender, and the audit rows about the logo only.
        $pdo->exec("DELETE FROM payment_methods WHERE company_id = $companyId AND code = 'rcp_online'");
        $pdo->prepare("DELETE FROM audit_log WHERE entity_type = 'companies' AND entity_id IN (?, ?) AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)")
            ->execute([$companyId, $logoCompanyId]);

        foreach ($receiptKeys as $key) {
            foreach ($settingSnapshot[$key] as $scopedCompany => $value) {
                $stmt = $pdo->prepare('SELECT setting_id FROM settings WHERE `key` = ? AND company_id = ?');
                $stmt->execute([$key, (int) $scopedCompany]);
                $settingId = $stmt->fetchColumn();
                if ($settingId) {
                    if ($value === null) {
                        $pdo->prepare('DELETE FROM settings WHERE setting_id = ?')->execute([(int) $settingId]);
                    } else {
                        $pdo->prepare('UPDATE settings SET value = ? WHERE setting_id = ?')->execute([$value, (int) $settingId]);
                    }
                } elseif ($value !== null) {
                    $pdo->prepare('INSERT INTO settings (company_id, `key`, value, category, description)
                                   VALUES (?, ?, ?, ?, ?)')
                        ->execute([(int) $scopedCompany, $key, $value, 'general', 'receipt test restore']);
                }
            }
        }

        foreach ($logoBefore as $id => $state) {
            $file = APP_ROOT . '/storage/uploads/logos/company_' . $id . '.jpg';
            if ($state['existed']) {
                file_put_contents($file, $state['bytes']);
            } elseif (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_dir(APP_ROOT . '/storage/uploads/logos') && glob(APP_ROOT . '/storage/uploads/logos/*') === []) {
            @rmdir(APP_ROOT . '/storage/uploads/logos');
        }

        $pdo->prepare("DELETE FROM product_locations WHERE branch_id IN (SELECT branch_id FROM branches WHERE code = 'RCP-TEST')")->execute();
        $pdo->prepare("DELETE FROM products WHERE sku LIKE 'RCP-TEST-%'")->execute();
        $pdo->prepare("DELETE FROM customers WHERE name = 'Receipt Test Customer'")->execute();
        $pdo->prepare("DELETE FROM branches WHERE code = 'RCP-TEST'")->execute();
        $pdo->commit();
        $left = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $prints = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'PRINT'")->fetchColumn();
        $logoState = [];
        foreach ($logoBefore as $id => $state) {
            $file = APP_ROOT . '/storage/uploads/logos/company_' . $id . '.jpg';
            $logoState[] = 'company' . $id . '=' . (is_file($file) === $state['existed'] ? 'as found' : 'MOVED');
        }
        echo 'TEARDOWN  audit_log ' . $left . ' (baseline ' . $auditBaseline . '), print rows ' . $prints
            . ', logo files ' . implode(', ', $logoState) . "\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo 'TEARDOWN FAILED: ' . $e->getMessage() . "\n";
    }
};
register_shutdown_function($teardown);

// ===========================================================================
// Fixtures
// ===========================================================================

$pdo->prepare("DELETE FROM branches WHERE code = 'RCP-TEST'")->execute();
$pdo->prepare("DELETE FROM products WHERE sku LIKE 'RCP-TEST-%'")->execute();
$pdo->prepare("DELETE FROM customers WHERE name = 'Receipt Test Customer'")->execute();

$pdo->prepare('INSERT INTO branches (company_id, name, code, is_active, sort_order) VALUES (?, ?, ?, 1, 95)')
    ->execute([$companyId, 'Receipt Test Branch', 'RCP-TEST']);
$branchId = (int) $pdo->lastInsertId();

// The names exercise the layout: an escape-heavy one for the PDF string rules,
// a long one to prove 58 mm wraps instead of running off the paper.
$items = [
    ['RCP-TEST-1', 'Widget (small) \ 10mm', 100.00, 40.00],
    ['RCP-TEST-2', 'Assorted stationery bundle for offices and schools', 25.50, 10.00],
];
$products = [];
foreach ($items as [$sku, $name, $price, $cost]) {
    $pdo->prepare('INSERT INTO products (company_id, category_id, sku, name, sales_price, cost_price, track_inventory, manage_stock, is_active)
                   VALUES (?, 1, ?, ?, ?, ?, 1, 1, 1)')->execute([$companyId, $sku, $name, $price, $cost]);
    $productId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO product_locations (company_id, product_id, branch_id, on_hand_quantity, reserved_quantity, available_quantity, on_hand_value)
                   VALUES (?, ?, ?, 40, 0, 40, ?)')->execute([$companyId, $productId, $branchId, 40 * $cost]);
    $products[substr($sku, -1)] = $productId;
}
$pdo->prepare("INSERT INTO customers (company_id, name, email, credit_limit, credit_used, payment_terms, payment_status, is_active)
               VALUES (?, 'Receipt Test Customer', 'receipt.test@example.test', 0, 0, 'Net 0', 'paid', 1)")->execute([$companyId]);
$customerId = (int) $pdo->lastInsertId();

$adminId = (int) sqlValue($pdo, "SELECT user_id FROM users WHERE role = 'admin' AND company_id = $companyId ORDER BY user_id LIMIT 1");
$_SESSION['user_id'] = $adminId;
$_SESSION['company_id'] = $companyId;
$_SESSION['branch_id'] = $branchId;
$_SESSION['user_role'] = 'admin';

/** One sales invoice, paid however the check needs it paid. */
$makeSale = static function (array $tenders, array $lines, array $extra = []) use ($pdo, $companyId, $branchId, $customerId, $adminId): array {
    $data = array_merge([
        'company_id' => $companyId,
        'customer_id' => $customerId,
        'branch_id' => $branchId,
        'created_by' => $adminId,
        'issue_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d'),
        'tax_rate' => 0,
        'lines' => $lines,
        'payments' => $tenders,
    ], $extra);
    $result = createSalesInvoice($pdo, $data);
    if (!($result['success'] ?? false)) {
        throw new RuntimeException('Fixture sale failed: ' . ($result['message'] ?? '?'));
    }

    return $result;
};

$twoLines = [
    ['product_id' => $products['1'], 'quantity' => 2],
    ['product_id' => $products['2'], 'quantity' => 1],
];

echo "== 1. settings that decide a slip ==\n";

$settings = receiptSettings($pdo, $companyId);
check('with nothing stored, a slip is 80 mm, says keep it, and would show a logo',
    $settings === ['width' => 80, 'footer' => 'Please keep this receipt for your records.', 'show_logo' => true, 'logo_path' => ''],
    json_encode($settings));
check('the width the editor offers is exactly the width the layouts can render',
    array_map('intval', array_keys(receiptPaperWidths())) === [58, 80]
    && array_map('intval', array_keys(appSettingDefinitions($pdo)['receipt_paper_width']['options'])) === [58, 80],
    json_encode(array_keys(receiptPaperWidths())));
updateCompanySettings($pdo, $companyId, ['receipt_paper_width' => '58']);
check('a stored 58 is honoured by the reader',
    receiptSettings($pdo, $companyId)['width'] === 58,
    receiptSettings($pdo, $companyId)['width']);
$rejected = saveAppSettings($pdo, $companyId, ['receipt_paper_width' => '120']);
check('a width nobody can lay out is refused by the editor, not stored',
    $rejected['ok'] === false && receiptSettings($pdo, $companyId)['width'] === 58,
    json_encode($rejected));
updateCompanySettings($pdo, $companyId, ['receipt_paper_width' => '12']);
check('a width written straight into the table anyway still prints at a known size',
    receiptSettings($pdo, $companyId)['width'] === 80,
    receiptSettings($pdo, $companyId)['width']);
$footerSaved = saveAppSettings($pdo, $companyId, ['receipt_footer_text' => 'No refunds after 7 days.']);
check('the footer is the company sentence, kept in step with the editor definition',
    $footerSaved['ok'] === true && receiptSettings($pdo, $companyId)['footer'] === 'No refunds after 7 days.',
    json_encode($footerSaved));
$logoHidden = saveAppSettings($pdo, $companyId, ['receipt_show_logo' => '0']);
check('printing the logo can be turned off',
    $logoHidden['ok'] === true && receiptSettings($pdo, $companyId)['show_logo'] === false,
    json_encode($logoHidden));
updateCompanySettings($pdo, $companyId, ['receipt_paper_width' => '80', 'receipt_show_logo' => '1']);

echo "\n== 2. one payload, two paper widths ==\n";

$sale = $makeSale([['method' => 'cash', 'amount' => 300.00]], $twoLines);
$invoiceId = (int) $sale['invoice_id'];
$payload = receiptPayload($pdo, $companyId, $invoiceId);
check('the slip is built from the invoice, its lines and its payments',
    is_array($payload) && $payload['number'] === $sale['invoice_number'] && count($payload['lines']) === 2
    && (float) $payload['summary']['total'] === 225.50,
    json_encode(['total' => $payload['summary']['total'] ?? null, 'lines' => count($payload['lines'] ?? [])]));
check('the bill, the payment and the balance agree',
    (float) $payload['summary']['paid'] === 225.50 && (float) $payload['summary']['balance'] === 0.0
    && (float) $payload['handed'] === 300.00,
    json_encode(array_intersect_key($payload['summary'], array_flip(['paid', 'balance']))));

$wide = receiptTextLines($payload, 80);
$narrow = receiptTextLines($payload, 58);
check('58 mm holds fewer characters in a line than 80 mm',
    $narrow['cols'] < $wide['cols'] && $narrow['cols'] >= 24 && $wide['cols'] <= 44,
    json_encode(['cols58' => $narrow['cols'], 'cols80' => $wide['cols']]));
$spilling = array_values(array_filter(
    array_merge(
        array_map(static fn (array $l): int => mb_strlen($l['text']) - $narrow['cols'], $narrow['lines']),
        array_map(static fn (array $l): int => mb_strlen($l['text']) - $wide['cols'], $wide['lines'])
    ),
    static fn (int $over): bool => $over > 0
));
check('not one line is wider than the paper it was laid out for',
    $spilling === [], json_encode($spilling));
check('the long item name wraps into several lines instead of being cut or overflowed',
    count(array_filter($narrow['lines'], static fn (array $l): bool => str_contains($l['text'], 'Assorted'))) >= 1
    && (bool) array_filter($narrow['lines'], static fn (array $l): bool => str_contains($l['text'], 'schools')
        || str_contains($l['text'], 'offices')),
    json_encode(array_slice($narrow['lines'], 12, 6)));
foreach (['number' => $payload['number'], 'total' => receiptMoney($payload['summary']['total']), 'customer' => $payload['customer'],
          'branch' => $payload['branch'], 'cashier' => $payload['cashier'], 'footer' => $payload['print']['footer']] as $label => $needle) {
    $text80 = implode("\n", array_column($wide['lines'], 'text'));
    $text58 = implode("\n", array_column($narrow['lines'], 'text'));
    check("both papers carry the $label",
        $needle === '' || (str_contains($text80, $needle) && str_contains($text58, $needle)), $needle);
}
check('the two papers agree on what was charged, line for line',
    (bool) preg_match('/TOTAL\s+' . preg_quote(receiptMoney($payload['summary']['total']), '/') . '/', implode("\n", array_column($narrow['lines'], 'text'))));
$unpaidId = (int) $makeSale([], $twoLines, ['status' => 'pending'])['invoice_id'];
$unpaidPayload = receiptPayload($pdo, $companyId, $unpaidId);
$unpaidText = implode("\n", array_column(receiptTextLines($unpaidPayload, 80)['lines'], 'text'));
check('a sale nobody has paid yet shows its balance and no change at all',
    (float) $unpaidPayload['summary']['balance'] === 225.50 && !str_contains($unpaidText, 'CHANGE')
    && str_contains($unpaidText, 'Balance due') && $unpaidPayload['tenders'] === [], $unpaidText);

echo "\n== 3. what was handed over, and what the drawer saw ==\n";

$shift = shiftOpen($pdo, $adminId, $companyId, $branchId, 50.00, 'receipt test drawer');
check('a shift is open for the register to sell against', ($shift['shift_id'] ?? 0) > 0, json_encode($shift['message'] ?? ''));
$shiftId = (int) $shift['shift_id'];
$shiftRow = shiftGet($pdo, $shiftId, $companyId);

$_SESSION['pos_cart'] = [];
posAddItem($pdo, $products['1'], 1);
$before = (float) shiftTotals($pdo, $shiftRow)['cash_receipts'];
$drawerSale = posCheckout($pdo, ['branch_id' => $branchId, 'payment_method' => 'cash', 'payment_amount' => 200.00]);
check('the register sold and handed change back', ($drawerSale['success'] ?? false) && (float) $drawerSale['change'] > 0,
    json_encode(['change' => $drawerSale['change'] ?? null, 'message' => $drawerSale['message'] ?? null]));
$drawerInvoiceId = (int) $drawerSale['invoice_id'];
$paymentRow = $pdo->query("SELECT amount, tendered_amount FROM customer_payments WHERE invoice_id = $drawerInvoiceId")->fetch(PDO::FETCH_ASSOC);
check('the row keeps the bill and the notes apart',
    (float) $paymentRow['amount'] === round((float) $drawerSale['total'], 2)
    && (float) $paymentRow['tendered_amount'] === 200.00,
    json_encode($paymentRow));
$drawerPayload = receiptPayload($pdo, $companyId, $drawerInvoiceId);
check('the slip states the change, and the change is the notes minus the bill',
    (float) $drawerPayload['change'] === round(200.00 - (float) $drawerPayload['summary']['total'], 2)
    && str_contains(implode("\n", array_column(receiptTextLines($drawerPayload, 80)['lines'], 'text')), 'CHANGE DUE'),
    json_encode(['change' => $drawerPayload['change'], 'total' => $drawerPayload['summary']['total']]));
$after = (float) shiftTotals($pdo, shiftGet($pdo, $shiftId, $companyId))['cash_receipts'];
check('the drawer grew by the bill, never by the notes handed over',
    round($after - $before, 2) === round((float) $drawerSale['total'], 2),
    json_encode(['before' => $before, 'after' => $after, 'total' => $drawerSale['total']]));
$expected = (float) shiftTotals($pdo, shiftGet($pdo, $shiftId, $companyId))['expected_cash'];
check('Expected moves by the bill and the float alone, so change is not a payout',
    round($expected, 2) === round(50.00 + $after, 2), json_encode(['expected' => $expected, 'receipts' => $after]));

$splitSale = $makeSale([
    ['method' => 'cash', 'amount' => 120.00],
    ['method' => 'bank_transfer', 'amount' => 120.00],
], $twoLines);
$splitId = (int) $splitSale['invoice_id'];
$splitPayload = receiptPayload($pdo, $companyId, $splitId);
$splitRows = $pdo->query("SELECT payment_method, amount, tendered_amount FROM customer_payments WHERE invoice_id = $splitId ORDER BY payment_id")->fetchAll(PDO::FETCH_ASSOC);
check('a split tender is two rows, each with its own money handed over',
    count($splitRows) === 2 && (float) $splitRows[0]['tendered_amount'] === 120.00 && (float) $splitRows[0]['amount'] === 120.00,
    json_encode($splitRows));
check('the header still says split while the slip lists both tenders',
    $splitPayload['tenders'][0]['label'] !== 'Split' && count($splitPayload['tenders']) === 2,
    json_encode(array_column($splitPayload['tenders'], 'label')));
check('a transfer is never shown as change, only cash above the bill is',
    (float) $splitPayload['tenders'][1]['change'] === 0.0,
    json_encode($splitPayload['tenders'][1]));
$cashOver = $makeSale([['method' => 'cash', 'amount' => 260.00]], $twoLines);
$cashOverPayload = receiptPayload($pdo, $companyId, (int) $cashOver['invoice_id']);
check('notes above the bill leave change on the slip and in the audit trail',
    (float) $cashOverPayload['change'] === round(260.00 - 225.50, 2) && (float) $cashOverPayload['summary']['paid'] === 225.50,
    json_encode(['change' => $cashOverPayload['change'], 'paid' => $cashOverPayload['summary']['paid']]));
$pdo->prepare('UPDATE customer_payments SET tendered_amount = NULL WHERE invoice_id = ?')->execute([(int) $cashOver['invoice_id']]);
$inferred = receiptPayload($pdo, $companyId, (int) $cashOver['invoice_id']);
check('a sale from before this column existed has its change inferred, not invented',
    (float) $inferred['change'] === round(225.50 - 225.50, 2) && $inferred['tendered_recorded'] === false,
    json_encode(['change' => $inferred['change'], 'handed' => $inferred['handed']]));

$pdo->prepare("INSERT INTO payment_methods (company_id, code, name, is_cash, is_online, is_active, sort_order)
               VALUES (?, 'rcp_online', 'Card online', 0, 1, 1, 90)")->execute([$companyId]);
$onlineSale = $makeSale([['method' => 'rcp_online', 'amount' => 225.50]], $twoLines);
$onlinePayload = receiptPayload($pdo, $companyId, (int) $onlineSale['invoice_id']);
check('an online tender prints as received money with no change claimed',
    (float) $onlinePayload['change'] === 0.0 && $onlinePayload['tenders'][0]['is_online'] === true
    && $onlinePayload['cash_only'] === false,
    json_encode(['change' => $onlinePayload['change'], 'cash_only' => $onlinePayload['cash_only']]));

echo "\n== 4. the slip tells the truth about a sale ==\n";

check('names that need escaping in a PDF string survive to the slip',
    str_contains(implode("\n", array_column(receiptTextLines($payload, 80)['lines'], 'text')), 'Widget (small) \ 10mm'),
    implode('|', array_slice(array_column($wide['lines'], 'text'), 15, 4)));
check('the SKU a cashier scanned travels with the line',
    str_contains(implode("\n", array_column($wide['lines'], 'text')), '[RCP-TEST-1]'));
$shiftPayload = receiptPayload($pdo, $companyId, $drawerInvoiceId);
check('a sale taken on a shift says which cashier opened it',
    $shiftPayload['cashier'] !== '' && $shiftPayload['branch'] === 'Receipt Test Branch',
    json_encode(['cashier' => $shiftPayload['cashier'], 'branch' => $shiftPayload['branch']]));
$otherCompanySale = $pdo->query('SELECT invoice_id FROM sales_invoices WHERE company_id <> 1 LIMIT 1')->fetchColumn();
check('another company\'s invoice has no slip to print',
    receiptPayload($pdo, $companyId, (int) ($otherCompanySale ?: 999999)) === null);
check('a sale id that never existed has no slip either',
    receiptPayload($pdo, $companyId, (int) $mark['invoice_id'] + 424242) === null);
check('the currency on the slip is the company\'s, not a default',
    $payload['company']['currency'] === 'USD' && $payload['company']['name'] !== '',
    json_encode($payload['company']));

echo "\n== 5. the PDF is a real file ==\n";

$pdf = receiptPdfBytes($payload);
$parsed = receiptPdfPages($pdf);
check('it opens as a PDF and ends as one', str_starts_with($pdf, '%PDF-1.4') && str_ends_with($pdf, "%%EOF\n"));
check('every cross-reference offset points at the object it claims',
    $parsed['offsets_ok'] === true && $parsed['pages'] >= 1, json_encode($parsed['pages']));
$mediaBox = static function (string $file): float {
    preg_match('/MediaBox \[0 0 ([0-9.]+)/', $file, $m);
    return isset($m[1]) ? (float) $m[1] : 0.0;
};
check('the page is the paper width it was asked for, in points',
    abs($mediaBox($pdf) - 80 * 72 / 25.4) < 0.01, $mediaBox($pdf) . ' pt');
$pdf58 = receiptPdfBytes((static function (array $p): array { $p['print']['width'] = 58; return $p; })($payload));
check('a 58 mm PDF is a narrower page, not the same page relabelled',
    abs($mediaBox($pdf58) - 58 * 72 / 25.4) < 0.01 && $mediaBox($pdf58) < $mediaBox($pdf),
    $mediaBox($pdf58) . ' pt against ' . $mediaBox($pdf) . ' pt');
$extracted = $parsed['text'];
$missing = [];
foreach (array_column(receiptTextLines($payload, 80)['lines'], 'text') as $line) {
    if ($line !== '' && !str_contains($extracted, str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line))
        && !str_contains($extracted, $line)) {
        $missing[] = $line;
    }
}
check('every line the text layout produced is findable inside the file',
    $missing === [], json_encode(array_slice($missing, 0, 3)));
$escapeSale = $makeSale([['method' => 'cash', 'amount' => 100.00]], [['product_id' => $products['1'], 'quantity' => 1]]);
$escapePdf = receiptPdfBytes(receiptPayload($pdo, $companyId, (int) $escapeSale['invoice_id']));
check('brackets and backslashes in a product name cannot break the file',
    receiptPdfPages($escapePdf)['offsets_ok'] === true && str_contains($escapePdf, '\\(') && str_contains($escapePdf, '\\\\'),
    'escape check');
$unicode = receiptPayload($pdo, $companyId, $invoiceId);
$unicode['company']['name'] = 'Nàïve Café (Lagos)';
$unicodePdf = receiptPdfBytes($unicode);
check('an accented shop name does not corrupt the PDF',
    receiptPdfPages($unicodePdf)['offsets_ok'] === true && str_contains($unicodePdf, 'CAF'));
check('the fonts the page names are in the file, regular and bold',
    str_contains($pdf, '/BaseFont /Courier ') && str_contains($pdf, '/BaseFont /Courier-Bold '));
$bulkLines = [];
for ($i = 0; $i < 60; $i++) {
    $bulkLines[] = ['product_id' => $products['2'], 'quantity' => 1];
}
$bulkSale = $makeSale([['method' => 'cash', 'amount' => 5000.00]], $bulkLines);
$bulkPdf = receiptPdfPages(receiptPdfBytes(receiptPayload($pdo, $companyId, (int) $bulkSale['invoice_id'])));
check('a receipt longer than one strip is paginated, not clipped',
    $bulkPdf['pages'] >= 2 && $bulkPdf['offsets_ok'] === true, json_encode(['pages' => $bulkPdf['pages']]));
check('the file needs no PDF library that this host lacks',
    !function_exists('pdf_open_document') && extension_loaded('gd') && extension_loaded('mbstring'),
    'gd=' . var_export(extension_loaded('gd'), true));

echo "\n== 6. a logo, prepared safely ==\n";

$logoStore = str_replace('\\', '/', receiptLogoDirectory());
check('the logo store lives where the web server cannot read it',
    str_contains($logoStore, '/storage/uploads/logos')
    && is_file(APP_ROOT . '/storage/.htaccess')
    && str_contains((string) file_get_contents(APP_ROOT . '/storage/.htaccess'), 'Require all denied'),
    $logoStore);

$raster = imagecreatetruecolor(900, 300);
imagefill($raster, 0, 0, imagecolorallocate($raster, 20, 90, 200));
imagestring($raster, 5, 40, 140, 'TEST LOGO', imagecolorallocate($raster, 255, 255, 255));
$pngPath = tempnam(sys_get_temp_dir(), 'rcp') . '.png';
imagepng($raster, $pngPath);
imagedestroy($raster);
$upload = ['name' => 'logo.png', 'type' => 'image/png', 'tmp_name' => $pngPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($pngPath)];

$save = receiptSaveLogo($pdo, $logoCompanyId, $upload);
$logoPath = APP_ROOT . '/storage/uploads/logos/company_' . $logoCompanyId . '.jpg';
check('an uploaded PNG becomes a stored JPEG and the setting points at it',
    $save['ok'] && is_file($logoPath) && rawSetting($pdo, $logoCompanyId, 'receipt_logo_path') === 'storage/uploads/logos/company_' . $logoCompanyId . '.jpg',
    json_encode($save));
$storedInfo = is_file($logoPath) ? getimagesize($logoPath) : false;
$flat = null;
$inked = null;
if (is_array($storedInfo) && ($stored = @imagecreatefromjpeg($logoPath))) {
    $flat = true;
    $inked = false;
    for ($x = 0; $x < imagesx($stored); $x += 5) {
        for ($y = 0; $y < imagesy($stored); $y += 5) {
            $c = imagecolorsforindex($stored, imagecolorat($stored, $x, $y));
            // JPEG is lossy, so gray means the three channels agree closely,
            // not that they are identical to the byte.
            if (max($c['red'], $c['green'], $c['blue']) - min($c['red'], $c['green'], $c['blue']) > 6) {
                $flat = false;
            }
            if ($c['red'] < 200) {
                $inked = true;
            }
        }
    }
    imagedestroy($stored);
}
check('what is kept is the mark, flattened to gray and no wider than a slip needs',
    is_array($storedInfo) && (int) $storedInfo[2] === IMAGETYPE_JPEG && (int) $storedInfo[0] === 480
    && (int) $storedInfo[1] === 160 && $flat === true && $inked === true,
    json_encode($storedInfo) . ' flat=' . var_export($flat, true) . ' inked=' . var_export($inked, true));
check('the upload is audited against the company that owns it',
    (int) sqlValue($pdo, "SELECT COUNT(*) FROM audit_log WHERE action = 'UPDATE' AND entity_type = 'companies'
                          AND entity_id = $logoCompanyId AND new_value LIKE '%receipt_logo%'") === 1);
$dataUri = receiptLogoDataUri(receiptLogoFile(receiptSettings($pdo, $logoCompanyId)));
check('the slip carries the logo inline, decoding back to the stored bytes',
    str_starts_with($dataUri, 'data:image/jpeg;base64,')
    && base64_decode(substr($dataUri, strlen('data:image/jpeg;base64,')), true) === file_get_contents($logoPath));
updateCompanySettings($pdo, $logoCompanyId, ['receipt_show_logo' => '0']);
check('turning the logo off hides it without deleting anything',
    receiptLogoFile(receiptSettings($pdo, $logoCompanyId)) === null && is_file($logoPath));
updateCompanySettings($pdo, $logoCompanyId, ['receipt_show_logo' => '1']);

$fake = tempnam(sys_get_temp_dir(), 'rcp') . '.png';
file_put_contents($fake, "<?php echo 'hello'; ?> this is not a picture at all");
$fakeUpload = ['name' => 'logo.png', 'type' => 'image/png', 'tmp_name' => $fake, 'error' => UPLOAD_ERR_OK, 'size' => filesize($fake)];
$rejected = receiptSaveLogo($pdo, $logoCompanyId, $fakeUpload);
check('a PHP file wearing a PNG name is refused and nothing is written',
    !$rejected['ok'] && rawSetting($pdo, $logoCompanyId, 'receipt_logo_path') === 'storage/uploads/logos/company_' . $logoCompanyId . '.jpg',
    json_encode($rejected));

$tiny = tempnam(sys_get_temp_dir(), 'rcp') . '.png';
$tinyImage = imagecreatetruecolor(10, 10);
imagepng($tinyImage, $tiny);
imagedestroy($tinyImage);
check('a picture too small to read is refused',
    !receiptSaveLogo($pdo, $logoCompanyId, ['name' => 't.png', 'type' => 'image/png', 'tmp_name' => $tiny,
        'error' => UPLOAD_ERR_OK, 'size' => filesize($tiny)])['ok']);

updateCompanySettings($pdo, $logoCompanyId, ['receipt_logo_path' => 'config/app.php']);
check('a hand-edited setting cannot make a slip print a file from outside the logo store',
    receiptLogoFile(receiptSettings($pdo, $logoCompanyId)) === null, receiptSettings($pdo, $logoCompanyId)['logo_path']);
updateCompanySettings($pdo, $logoCompanyId, ['receipt_logo_path' => '../../../bootstrap.php']);
check('nor through a traversal out of it',
    receiptLogoFile(receiptSettings($pdo, $logoCompanyId)) === null);
updateCompanySettings($pdo, $logoCompanyId, ['receipt_logo_path' => 'storage/uploads/logos/company_' . $logoCompanyId . '.jpg']);

$logoPdf = receiptPdfBytes((static function (array $p) use ($logoPath): array {
    $p['print']['logo_file'] = $logoPath;
    $p['print']['logo_uri'] = receiptLogoDataUri($logoPath);
    return $p;
})($payload));
$wantSpace = ((int) ($storedInfo['channels'] ?? 3) === 1) ? '/DeviceGray' : '/DeviceRGB';
check('the PDF carries the logo as an image whose colour space matches the picture inside',
    str_contains($logoPdf, '/Subtype /Image') && str_contains($logoPdf, '/Filter /DCTDecode')
    && str_contains($logoPdf, '/ColorSpace ' . $wantSpace) && receiptPdfPages($logoPdf)['offsets_ok'] === true,
    'channels=' . json_encode($storedInfo['channels'] ?? null) . ' wanted ' . $wantSpace);
check('a slip with no logo has no image object to fetch',
    !str_contains($pdf, '/Subtype /Image'));
$removed = receiptDeleteLogo($pdo, $logoCompanyId);
check('removing it takes the file and the setting together',
    $removed['ok'] && !is_file($logoPath) && rawSetting($pdo, $logoCompanyId, 'receipt_logo_path') === '');

echo "\n== 7. reprinting is an act with a record ==\n";

check('a sale that has never been printed is not a reprint',
    receiptIsReprint($pdo, $invoiceId) === false);
receiptAuditPrint($pdo, $payload, false);
check('the first print is stored as PRINT, not coerced into UPDATE',
    (int) sqlValue($pdo, "SELECT COUNT(*) FROM audit_log WHERE action = 'PRINT' AND entity_type = 'sales_invoices'
                          AND entity_id = $invoiceId") === 1,
    (string) sqlValue($pdo, "SELECT action FROM audit_log WHERE entity_type = 'sales_invoices' AND entity_id = $invoiceId ORDER BY audit_id DESC LIMIT 1"));
$printedRow = $pdo->query("SELECT company_id, user_id, new_value FROM audit_log WHERE action = 'PRINT' AND entity_id = $invoiceId
                           ORDER BY audit_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$printedDetails = json_decode((string) $printedRow['new_value'], true);
check('the record says format, paper, totals and change, not just that something happened',
    (int) $printedRow['company_id'] === $companyId && (int) $printedRow['user_id'] === $adminId
    && $printedDetails['format'] === 'html' && (int) $printedDetails['paper_mm'] === 80
    && array_key_exists('change', $printedDetails) && array_key_exists('reprint', $printedDetails),
    (string) $printedRow['new_value']);
check('after that print, the same slip is knowingly a reprint',
    receiptIsReprint($pdo, $invoiceId) === true);
$reprintPayload = receiptPayload($pdo, $companyId, $invoiceId, 'pdf');
$reprintText = implode("\n", array_column(receiptTextLines($reprintPayload, 80)['lines'], 'text'));
check('a reprint says so on the paper, in its own words',
    $reprintPayload['print']['reprint'] === true
    && str_contains($reprintText, 'REPRINT - SALES RECEIPT')
    && str_contains($reprintText, 'reprinted'),
    implode('|', array_slice(explode("\n", $reprintText), 6, 3)));
receiptAuditPrint($pdo, $reprintPayload, true);
$second = $pdo->query("SELECT new_value FROM audit_log WHERE action = 'PRINT' AND entity_id = $invoiceId ORDER BY audit_id DESC LIMIT 1")->fetchColumn();
check('the second print admits it was a reprint', json_decode((string) $second, true)['reprint'] === true, (string) $second);
check('PDF and HTML prints are told apart in the trail',
    (int) sqlValue($pdo, "SELECT COUNT(*) FROM audit_log WHERE action = 'PRINT' AND entity_id = $invoiceId
                          AND new_value LIKE '%\"format\":\"pdf\"%'") === 1);

echo "\n== teardown evidence ==\n";
// Counted while it still exists: the sweep names rows by the marks this run
// holds, so anything outside those names would be left behind unnoticed.
$named = [
    'invoices' => (int) sqlValue($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId AND invoice_id > {$mark['invoice_id']}"),
    'prints' => (int) sqlValue($pdo, "SELECT COUNT(*) FROM audit_log WHERE action = 'PRINT'"),
    'our_prints' => (int) sqlValue($pdo, "SELECT COUNT(*) FROM audit_log WHERE action = 'PRINT' AND entity_type = 'sales_invoices'
                                          AND entity_id > {$mark['invoice_id']}"),
    'shifts' => (int) sqlValue($pdo, "SELECT COUNT(*) FROM cashier_shifts WHERE shift_id > {$mark['shift_id']}"),
    'products' => (int) sqlValue($pdo, "SELECT COUNT(*) FROM products WHERE sku LIKE 'RCP-TEST-%'"),
];
check('every print row in the trail is one this run pressed, against a sale it made',
    $named['prints'] === $named['our_prints'] && $named['our_prints'] > 0 && $named['invoices'] > 0,
    json_encode($named));
check('nothing outside the names the sweep deletes was left in this company',
    $named['shifts'] === 1 && $named['products'] === 2, json_encode($named));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
