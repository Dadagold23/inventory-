<?php
/**
 * Service-layer tests for Phase D: the gateway code in
 * includes/gateway_functions.php, from stored credentials to a posted sale.
 *
 * Run: php scripts/test_gateways.php
 *
 * Everything it touches belongs to the run: a branch, a product, a customer, a
 * gateway configuration, the tender it creates, the payment sessions, the one
 * sale they earn and the audit rows about them. Records the company already had
 * are read, never changed - the gateway configuration rows found at the start
 * are put back exactly as they were found.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/gateway_functions.php';
require_once APP_ROOT . '/includes/crypto_functions.php';

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

// ===========================================================================
// Fixtures, and the high-water marks of everything this run can create
// ===========================================================================

$configSnapshot = [];
foreach (['paystack', 'flutterwave'] as $gateway) {
    $stmt = $pdo->prepare('SELECT * FROM payment_gateways WHERE company_id = ? AND gateway = ?');
    $stmt->execute([$companyId, $gateway]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $configSnapshot[$gateway] = $row === false ? null : $row;
}

$mark = [
    'invoice_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(invoice_id), 0) FROM sales_invoices'),
    'transaction_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(transaction_id), 0) FROM gateway_transactions'),
    'log_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(log_id), 0) FROM gateway_events'),
    'refund_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(refund_id), 0) FROM gateway_refunds'),
    'method_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(payment_method_id), 0) FROM payment_methods'),
    'shift_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(shift_id), 0) FROM cashier_shifts'),
    'config_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(gateway_config_id), 0) FROM payment_gateways'),
    'user_id' => (int) sqlValue($pdo, 'SELECT COALESCE(MAX(user_id), 0) FROM users'),
];

$teardownDone = false;
$runInvoices = [];
$teardown = static function () use ($pdo, $companyId, $configSnapshot, $mark, &$teardownDone, &$runInvoices): void {
    if ($teardownDone) {
        return;
    }
    $teardownDone = true;
    $pdo->beginTransaction();
    try {
        // The sales this run's payments earned. Every one of them is named on a
        // payment row this run opened, so nothing a concurrent sale created can
        // be caught in the sweep.
        $invoiceIds = $pdo->query("SELECT DISTINCT invoice_id FROM gateway_transactions
                                   WHERE company_id = $companyId AND invoice_id IS NOT NULL
                                     AND transaction_id > {$mark['transaction_id']}")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($invoiceIds as $invoiceId) {
            $invoiceId = (int) $invoiceId;
            $runInvoices[] = $invoiceId;
        }
        foreach (array_unique($runInvoices) as $invoiceId) {
            $invoiceId = (int) $invoiceId;
            $pdo->prepare('DELETE FROM customer_payments WHERE invoice_id = ?')->execute([$invoiceId]);
            $pdo->prepare("DELETE FROM inventory_transactions WHERE reference_type = 'sales_invoice' AND reference_id = ?")->execute([$invoiceId]);
            $pdo->prepare('DELETE FROM sales_invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);
            $pdo->prepare('DELETE FROM sales_invoices WHERE invoice_id = ?')->execute([$invoiceId]);
            $pdo->prepare("DELETE FROM audit_log WHERE entity_type = 'sales_invoices' AND entity_id = ?")->execute([$invoiceId]);
        }
        foreach (['gateway_refunds' => 'refund_id', 'gateway_transactions' => 'transaction_id'] as $table => $key) {
            $ids = $pdo->query("SELECT $key FROM $table WHERE company_id = $companyId AND $key > {$mark[$key]}")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $pdo->prepare("DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?")->execute([$table, (int) $id]);
            }
            if ($ids !== []) {
                $pdo->exec("DELETE FROM $table WHERE company_id = $companyId AND $key IN (" . implode(',', array_map('intval', $ids)) . ')');
            }
        }
        $pdo->exec("DELETE FROM gateway_events WHERE company_id = $companyId AND log_id > {$mark['log_id']}");

        // The tender the configuration switch created, and the configuration.
        $tenders = $pdo->query("SELECT payment_method_id, code FROM payment_methods WHERE company_id = $companyId
                                 AND payment_method_id > {$mark['method_id']}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tenders as $tender) {
            $pdo->prepare('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?')->execute(['payment_methods', (int) $tender['payment_method_id']]);
            $used = $pdo->prepare('SELECT COUNT(*) FROM customer_payments WHERE payment_method = ?');
            $used->execute([(string) $tender['code']]);
            if ((int) $used->fetchColumn() > 0) {
                continue;  // a recorded payment keeps the tender alive
            }
            $pdo->prepare('DELETE FROM payment_methods WHERE payment_method_id = ?')->execute([(int) $tender['payment_method_id']]);
        }
        $pdo->exec("DELETE FROM payment_gateways WHERE company_id = $companyId AND gateway_config_id > {$mark['config_id']}");
        $pdo->prepare("DELETE FROM audit_log WHERE entity_type = 'payment_gateways' AND entity_id > ?")->execute([$mark['config_id']]);
        foreach ($configSnapshot as $gateway => $row) {
            if ($row === null) {
                continue;  // this company had no configuration to put back
            }
            unset($row['gateway_config_id']);
            $columns = array_keys($row);
            $pdo->prepare('INSERT INTO payment_gateways (`' . implode('`, `', $columns) . '`) VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')')
                ->execute(array_values($row));
        }

        // The drawer the till rule needed, if this run opened one.
        $shiftIds = $pdo->query("SELECT shift_id FROM cashier_shifts WHERE company_id = $companyId
                                  AND shift_id > {$mark['shift_id']}")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($shiftIds as $shiftId) {
            $pdo->prepare('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?')->execute(['cashier_shifts', (int) $shiftId]);
        }
        if ($shiftIds !== []) {
            $in = implode(',', array_map('intval', $shiftIds));
            $pdo->exec("DELETE FROM shift_movements WHERE shift_id IN ($in)");
            $pdo->exec("DELETE FROM cashier_shifts WHERE shift_id IN ($in)");
        }

        $pdo->exec("DELETE FROM users WHERE user_id > {$mark['user_id']}");
        $pdo->prepare("DELETE FROM audit_log WHERE user_id > ?")->execute([$mark['user_id']]);

        // The basket this run sold from.
        $productId = (int) sqlValue($pdo, "SELECT product_id FROM products WHERE sku = 'GW-TEST-1'");
        if ($productId > 0) {
            $pdo->prepare('DELETE FROM product_locations WHERE product_id = ?')->execute([$productId]);
            $pdo->prepare('DELETE FROM product_barcodes WHERE product_id = ?')->execute([$productId]);
            $pdo->prepare('DELETE FROM inventory_transactions WHERE product_id = ?')->execute([$productId]);
            $pdo->prepare('DELETE FROM sales_invoice_items WHERE product_id = ?')->execute([$productId]);
            $pdo->prepare('DELETE FROM products WHERE product_id = ?')->execute([$productId]);
        }
        $pdo->prepare("DELETE FROM customers WHERE name = 'Gateway Test Customer'")->execute();
        $pdo->prepare("DELETE FROM branches WHERE code = 'GW-TEST'")->execute();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo 'TEARDOWN FAILED: ' . $e->getMessage() . "\n";
    }
};
register_shutdown_function($teardown);

$pdo->prepare("DELETE FROM products WHERE sku = 'GW-TEST-1'")->execute();
$pdo->prepare("DELETE FROM customers WHERE name = 'Gateway Test Customer'")->execute();
$pdo->prepare("DELETE FROM branches WHERE code = 'GW-TEST'")->execute();

$pdo->prepare("INSERT INTO branches (company_id, name, code, is_active, sort_order) VALUES (?, 'Gateway Test Branch', 'GW-TEST', 1, 96)")
    ->execute([$companyId]);
$branchId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO products (company_id, category_id, sku, name, sales_price, cost_price, track_inventory, manage_stock, is_active)
               VALUES (?, 1, ?, ?, 1000.00, 400.00, 1, 1, 1)')->execute([$companyId, 'GW-TEST-1', 'Gateway Test Item']);
$productId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO product_locations (company_id, product_id, branch_id, on_hand_quantity, reserved_quantity, available_quantity, on_hand_value)
               VALUES (?, ?, ?, 10, 0, 10, 4000)')->execute([$companyId, $productId, $branchId]);
$pdo->prepare("INSERT INTO customers (company_id, name, email, credit_limit, credit_used, payment_terms, payment_status, is_active)
               VALUES (?, 'Gateway Test Customer', 'gateway.test@example.test', 0, 0, 'Net 0', 'paid', 1)")->execute([$companyId]);
$customerId = (int) $pdo->lastInsertId();

$adminId = (int) sqlValue($pdo, "SELECT user_id FROM users WHERE role = 'admin' AND company_id = $companyId ORDER BY user_id LIMIT 1");
$_SESSION['user_id'] = $adminId;
$_SESSION['company_id'] = $companyId;
$_SESSION['branch_id'] = $branchId;
$_SESSION['user_role'] = 'admin';

// Only the public and webhook values go on the paystack side: a secret key of
// its own would make the gateway call the real API, which this host must not do.
$publicRaw = 'pk_test_' . bin2hex(random_bytes(12));
$webhookRaw = 'whsec_' . bin2hex(random_bytes(12));
$anythingButAKey = 'sk_test_' . bin2hex(random_bytes(12));
$fwSecret = 'FLWSECK-' . bin2hex(random_bytes(12));
$fwPublic = 'FLWPUBK-' . bin2hex(random_bytes(12));

$snapshot = [
    'lines' => [['product_id' => $productId, 'description' => 'Gateway Test Item', 'quantity' => 2, 'discount_percent' => 0]],
    'customer_id' => $customerId,
    'method' => 'paystack',
    'tax_rate' => 0.0,
    'notes' => 'gateway test sale',
];

/** Open a session for the fixture basket at the price the catalogue says. */
$openSession = static function (float $amount = 2000.00, ?array $cart = null) use ($pdo, $companyId, $branchId, $adminId, $snapshot): array {
    return gatewayInitialize($pdo, $companyId, [
        'gateway' => 'paystack', 'amount' => $amount, 'currency' => 'NGN',
        'customer_email' => 'gateway.test@example.test', 'branch_id' => $branchId, 'shift_id' => 0,
        'user_id' => $adminId, 'snapshot' => $cart ?? $snapshot,
    ]);
};

// ===========================================================================
// 1. Credentials: stored encrypted, never shown, runtime chosen by the host
// ===========================================================================

$explicitSandbox = in_array(strtolower(trim((string) app_env('GATEWAY_SANDBOX', ''))), ['1', 'true', 'yes', 'on'], true);
check('simulation is permitted by the environment, never assumed from the gateway mode',
    gatewaySimulationPermitted() === (!appIsProduction() || $explicitSandbox),
    json_encode(['app_env' => app_env('APP_ENV', ''), 'gateway_sandbox' => app_env('GATEWAY_SANDBOX', ''),
                 'production' => appIsProduction(), 'permitted' => gatewaySimulationPermitted()]));
check('a host that claims production while still simulating admits it out loud',
    !appIsProduction() || !gatewaySimulationPermitted() || gatewaySandboxWarning() !== '',
    json_encode(gatewaySandboxWarning()));

$roundTrip = secretDecrypt(secretEncrypt($anythingButAKey));
check('a stored secret comes back as it went in', $roundTrip === $anythingButAKey);
check('the stored form is not the secret',
    secretIsEncrypted(secretEncrypt($anythingButAKey)) && !str_contains(secretEncrypt($anythingButAKey), $anythingButAKey));

$noKeys = gatewaySaveConfig($pdo, $companyId, 'paystack', ['mode' => 'test', 'is_enabled' => 1, 'sandbox_allowed' => 0], $adminId);
check('a gateway with nothing behind it cannot be switched on',
    !($noKeys['ok'] ?? false) && gatewayResolvedConfig($pdo, $companyId, 'paystack')['runtime_mode'] === 'unavailable',
    json_encode(['ok' => $noKeys['ok'] ?? null, 'message' => $noKeys['message'] ?? null]));

$wrongShape = gatewaySaveConfig($pdo, $companyId, 'paystack', ['mode' => 'live', 'live_secret_key' => $anythingButAKey], $adminId);
check('a test key is not accepted as the live key', !($wrongShape['ok'] ?? false), json_encode($wrongShape['message'] ?? null));

$save = gatewaySaveConfig($pdo, $companyId, 'paystack', [
    'mode' => 'test', 'is_enabled' => 1, 'sandbox_allowed' => 1,
    'test_public_key' => $publicRaw, 'test_webhook_secret' => $webhookRaw,
], $adminId);
$stored = $pdo->prepare('SELECT * FROM payment_gateways WHERE company_id = ? AND gateway = ?');
$stored->execute([$companyId, 'paystack']);
$storedRow = $stored->fetch(PDO::FETCH_ASSOC) ?: [];
check('a sandbox configuration is accepted and remembered',
    ($save['ok'] ?? false) === true && (int) ($storedRow['is_enabled'] ?? 0) === 1, json_encode($save['message'] ?? $save['messages'] ?? null));
check('no credential is stored in the clear',
    !str_contains((string) ($storedRow['test_public_key'] ?? ''), $publicRaw)
    && !str_contains((string) ($storedRow['test_webhook_secret'] ?? ''), $webhookRaw)
    && secretIsEncrypted((string) ($storedRow['test_webhook_secret'] ?? '')),
    json_encode(['stored' => substr((string) $storedRow['test_webhook_secret'], 0, 16)]));

$resolved = gatewayResolvedConfig($pdo, $companyId, 'paystack');
check('a configuration written for the first time is audited against the row it created',
    (int) sqlValue($pdo, "SELECT entity_id FROM audit_log WHERE company_id = $companyId
                          AND entity_type = 'payment_gateways' AND new_value LIKE '%\"gateway\":\"paystack\"%'
                          ORDER BY audit_id DESC LIMIT 1") === (int) ($storedRow['gateway_config_id'] ?? 0)
    && (int) ($storedRow['gateway_config_id'] ?? 0) > 0,
    json_encode(['audited' => sqlValue($pdo, "SELECT entity_id FROM audit_log WHERE company_id = $companyId
                                              AND entity_type = 'payment_gateways' AND new_value LIKE '%\"gateway\":\"paystack\"%'
                                              ORDER BY audit_id DESC LIMIT 1"),
                 'row' => $storedRow['gateway_config_id'] ?? null]));
check('a test-mode gateway with no secret key of its own runs as the simulator',
    $resolved['runtime_mode'] === 'sandbox' && gatewayRuntimeMode($resolved) === 'sandbox', json_encode($resolved['runtime_mode']));
check('the service layer can still read what it needs to check a signature',
    (string) $resolved['webhook_secret'] === $webhookRaw && trim((string) $resolved['secret_key']) === '');

$views = gatewayConfigViews($pdo, $companyId)['paystack'];
check('what a screen is handed is a hint, never the credential',
    !str_contains(json_encode($views), $webhookRaw) && !str_contains(json_encode($views), $publicRaw)
    && $views['fields']['test_webhook_secret']['set'] === true,
    json_encode(['set' => $views['fields']['test_webhook_secret']['set'] ?? null]));

$online = gatewayOnlineMethods($pdo, $companyId);
$tenderCode = (string) (array_key_first($online) ?? '');
check('switching the provider on gave the till exactly one card tender',
    count($online) === 1 && $tenderCode !== '' && $online[$tenderCode]['gateway'] === 'paystack',
    json_encode(array_keys($online)));

// The other provider is configured with a secret key, which puts it in real
// test mode rather than the simulator - nothing below ever opens a session
// against it, so no call leaves this machine.
$fwSave = gatewaySaveConfig($pdo, $companyId, 'flutterwave', [
    'mode' => 'test', 'is_enabled' => 1, 'sandbox_allowed' => 1,
    'test_secret_key' => $fwSecret, 'test_public_key' => $fwPublic,
], $adminId);
$fwResolved = gatewayResolvedConfig($pdo, $companyId, 'flutterwave');
$fwStored = $pdo->prepare('SELECT * FROM payment_gateways WHERE company_id = ? AND gateway = ?');
$fwStored->execute([$companyId, 'flutterwave']);
$fwStored = $fwStored->fetch(PDO::FETCH_ASSOC) ?: [];
check('a gateway that holds a secret key runs in that key\'s own mode, not the simulator',
    ($fwSave['ok'] ?? false) === true && $fwResolved['runtime_mode'] === 'test', json_encode($fwResolved['runtime_mode']));
check('that secret is decrypted for the service layer and never for storage',
    (string) $fwResolved['secret_key'] === $fwSecret && !str_contains((string) ($fwStored['test_secret_key'] ?? ''), $fwSecret)
    && !str_contains(json_encode(gatewayConfigViews($pdo, $companyId)['flutterwave']), $fwSecret),
    json_encode(['hint' => $fwStored['test_secret_key'] ?? null]));
check('both providers now offer the till a card tender each',
    count(gatewayOnlineMethods($pdo, $companyId)) === 2, json_encode(array_keys(gatewayOnlineMethods($pdo, $companyId))));

// ===========================================================================
// 2. A session is not a sale until the provider has been asked
// ===========================================================================

$session = $openSession();
$row = gatewayTransactionByReference($pdo, $companyId, (string) $session['reference']);
check('opening a session records a pending payment with no invoice',
    ($session['ok'] ?? false) === true && (string) $row['status'] === 'pending' && (int) ($row['invoice_id'] ?? 0) === 0,
    json_encode(['ok' => $session['ok'] ?? null, 'status' => $row['status'] ?? null]));
check('the simulator page is what the customer is sent to',
    str_contains((string) $row['checkout_url'], 'action=gateway_sandbox')
    && str_contains((string) $row['checkout_url'], rawurlencode((string) $row['reference'])),
    json_encode($row['checkout_url']));
check('the cart travels with the session, without prices',
    str_contains((string) $row['payload'], '"product_id":' . $productId) && !str_contains((string) $row['payload'], 'unit_price'),
    json_encode($row['payload']));

$beforeVerify = gatewayVerify($pdo, $companyId, (string) $row['reference']);
check('verifying an unanswered session posts nothing',
    ($beforeVerify['paid'] ?? true) === false && (int) ($beforeVerify['invoice_id'] ?? 0) === 0,
    json_encode(['message' => $beforeVerify['message'] ?? null]));

$answer = gatewaySandboxRespond($pdo, $companyId, (string) $row['reference'], 'approve', $adminId);
$paid = gatewayTransaction($pdo, $companyId, (int) $row['transaction_id']);
$invoiceId = (int) ($answer['invoice_id'] ?? 0);
check('the provider saying yes is what writes the sale',
    ($answer['paid'] ?? false) === true && $invoiceId > 0 && (string) $paid['status'] === 'paid',
    json_encode(['paid' => $answer['paid'] ?? null, 'invoice_id' => $invoiceId]));

$invoice = $pdo->prepare('SELECT * FROM sales_invoices WHERE invoice_id = ?');
$invoice->execute([$invoiceId]);
$invoice = $invoice->fetch(PDO::FETCH_ASSOC) ?: [];
$invoiceItems = $pdo->prepare('SELECT quantity, unit_price, line_total FROM sales_invoice_items WHERE invoice_id = ?');
$invoiceItems->execute([$invoiceId]);
$invoiceItems = $invoiceItems->fetchAll(PDO::FETCH_ASSOC);
$invoicePayments = $pdo->prepare('SELECT amount, payment_method, reference_number FROM customer_payments WHERE invoice_id = ?');
$invoicePayments->execute([$invoiceId]);
$invoicePayments = $invoicePayments->fetchAll(PDO::FETCH_ASSOC);

check('the sale is the snapshot, priced from the catalogue rather than the session',
    (int) ($invoice['created_by'] ?? 0) === $adminId && abs((float) ($invoice['total_amount'] ?? 0) - 2000.00) < 0.005
    && count($invoiceItems) === 1 && abs((float) ($invoiceItems[0]['unit_price'] ?? 0) - 1000.00) < 0.005,
    json_encode(['total' => $invoice['total_amount'] ?? null, 'items' => $invoiceItems]));
check('the sale is paid in full by the card tender it was taken as',
    (string) ($invoice['status'] ?? '') === 'paid' && count($invoicePayments) === 1
    && (string) ($invoicePayments[0]['payment_method'] ?? '') === $tenderCode
    && (string) ($invoicePayments[0]['reference_number'] ?? '') === (string) $row['reference'],
    json_encode($invoicePayments));
check('the payment row is tied to the sale it created and stays marked simulated',
    (int) $paid['invoice_id'] === $invoiceId && (int) $paid['simulates_payment'] === 1 && (string) $paid['mode'] === 'sandbox',
    json_encode(['invoice_id' => $paid['invoice_id'] ?? null, 'simulates' => $paid['simulates_payment'] ?? null]));
check('the invoice key is the payment, so a retry cannot add a second sale',
    (string) ($invoice['idempotency_key'] ?? '') === 'gw:' . $row['reference'], json_encode($invoice['idempotency_key'] ?? null));
$stockLeft = (float) sqlValue($pdo, "SELECT on_hand_quantity FROM product_locations WHERE product_id = $productId AND branch_id = $branchId");
check('stock moved for the goods the snapshot carried', abs($stockLeft - 8.0) < 0.005, (string) $stockLeft);

$replay = gatewaySandboxRespond($pdo, $companyId, (string) $row['reference'], 'approve', $adminId);
check('being told the same payment again adds nothing',
    (int) ($replay['invoice_id'] ?? 0) === $invoiceId && str_contains((string) ($replay['message'] ?? ''), 'Already recorded'),
    json_encode(['message' => $replay['message'] ?? null, 'invoice_id' => $replay['invoice_id'] ?? null]));
check('still exactly one invoice in the whole company came from this run',
    (int) sqlValue($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId AND invoice_id > {$mark['invoice_id']}") === 1,
    (string) sqlValue($pdo, "SELECT COUNT(*) FROM sales_invoices WHERE company_id = $companyId AND invoice_id > {$mark['invoice_id']}"));

$tillStatus = gatewayPosStatus($pdo, $companyId, (string) $row['reference']);
check('the till reads its own record, not the browser, to know a sale landed',
    ($tillStatus['found'] ?? false) === true && ($tillStatus['posted'] ?? false) === true
    && (int) $tillStatus['invoice_id'] === $invoiceId && str_contains((string) $tillStatus['receipt_url'], (string) $invoiceId),
    json_encode($tillStatus));

// ===========================================================================
// 3. Money that does not match the sale it paid for is held, not posted
// ===========================================================================

$pdo->prepare('UPDATE products SET sales_price = 1200 WHERE product_id = ?')->execute([$productId]);
$driftSession = $openSession();
$drift = gatewaySandboxRespond($pdo, $companyId, (string) $driftSession['reference'], 'approve', $adminId);
$driftRow = gatewayTransaction($pdo, $companyId, (int) $driftSession['transaction_id']);
check('a price change while the customer was paying holds the payment',
    ($drift['ok'] ?? true) === false && (string) ($drift['status'] ?? '') === 'price_drift'
    && (int) ($driftRow['invoice_id'] ?? 0) === 0,
    json_encode(['status' => $drift['status'] ?? null, 'invoice_id' => $driftRow['invoice_id'] ?? null]));
check('the held payment explains itself for whoever reconciles',
    str_contains((string) ($driftRow['failure_reason'] ?? ''), 'catalogue now totals'), json_encode($driftRow['failure_reason'] ?? null));
$pdo->prepare('UPDATE products SET sales_price = 1000 WHERE product_id = ?')->execute([$productId]);

foreach ([
    ['the amount settled', ['amount' => 1000.00, 'currency' => 'NGN'], true, 'paid'],
    ['a short settlement', ['amount' => 999.00, 'currency' => 'NGN'], false, 'amount_mismatch'],
    ['a settlement in another currency', ['amount' => 1000.00, 'currency' => 'USD'], false, 'currency_mismatch'],
] as [$label, $settled, $posts, $status]) {
    $verdict = gatewaySettlementVerdict(['amount' => 1000.00, 'currency' => 'NGN', 'reference' => 'X'], $settled + ['status' => 'success']);
    check("holding $label is the settlement rule ($status)",
        $verdict['post'] === $posts && $verdict['status'] === $status, json_encode($verdict));
}

// ===========================================================================
// 4. A card that was never paid stays a card that was never paid
// ===========================================================================

foreach (['decline' => 'failed', 'timeout' => 'expired'] as $outcome => $expectedStatus) {
    $trial = $openSession(1000.00);
    $answered = gatewaySandboxRespond($pdo, $companyId, (string) $trial['reference'], $outcome, $adminId);
    $trialRow = gatewayTransaction($pdo, $companyId, (int) $trial['transaction_id']);
    check("a $outcome payment is recorded as " . $expectedStatus . ' and never as a sale',
        ($answered['paid'] ?? true) === false && (string) $trialRow['status'] === $expectedStatus
        && (int) ($trialRow['invoice_id'] ?? 0) === 0,
        json_encode(['status' => $trialRow['status'], 'invoice_id' => $trialRow['invoice_id'] ?? null]));
}

$unpaidRefund = gatewayIssueRefund($pdo, $companyId, (int) $trial['transaction_id'], 500.00, 'Refunding a card that never paid', $adminId);
check('nothing can be refunded on a session that was never paid',
    !($unpaidRefund['ok'] ?? false) && (int) sqlValue($pdo, "SELECT COUNT(*) FROM gateway_refunds WHERE refund_id > {$mark['refund_id']}") === 0,
    json_encode(['ok' => $unpaidRefund['ok'] ?? null, 'message' => $unpaidRefund['message'] ?? null]));

// ===========================================================================
// 5. Refunds move money back without rewriting the sale
// ===========================================================================

$overRefund = gatewayIssueRefund($pdo, $companyId, (int) $paid['transaction_id'], 2500.00, 'More than was paid', $adminId);
check('a refund larger than the payment is refused',
    !($overRefund['ok'] ?? false) && str_contains((string) ($overRefund['message'] ?? ''), 'refund'),
    json_encode($overRefund['message'] ?? null));

$partial = gatewayIssueRefund($pdo, $companyId, (int) $paid['transaction_id'], 500.00, 'One item returned', $adminId);
$afterRefund = gatewayTransaction($pdo, $companyId, (int) $paid['transaction_id']);
$invoiceAfter = $pdo->prepare('SELECT status, total_amount, amount_paid FROM sales_invoices WHERE invoice_id = ?');
$invoiceAfter->execute([$invoiceId]);
$invoiceAfter = $invoiceAfter->fetch(PDO::FETCH_ASSOC) ?: [];
check('a partial refund is recorded against the payment',
    ($partial['ok'] ?? false) === true && (string) $afterRefund['status'] === 'partial_refund',
    json_encode(['ok' => $partial['ok'] ?? null, 'status' => $afterRefund['status'] ?? null, 'message' => $partial['message'] ?? null]));
check('the posted sale is left as it happened',
    (string) ($invoiceAfter['status'] ?? '') === 'paid'
    && abs((float) ($invoiceAfter['amount_paid'] ?? 0) - 2000.00) < 0.005
    && abs((float) ($invoiceAfter['total_amount'] ?? 0) - 2000.00) < 0.005,
    json_encode($invoiceAfter));
check('the refund itself is a row that can be audited',
    (int) sqlValue($pdo, "SELECT COUNT(*) FROM gateway_refunds WHERE transaction_id = {$paid['transaction_id']}") === 1
    && abs((float) sqlValue($pdo, "SELECT amount FROM gateway_refunds WHERE transaction_id = {$paid['transaction_id']}") - 500.00) < 0.005,
    (string) sqlValue($pdo, "SELECT COUNT(*) FROM gateway_refunds"));

$recon = gatewayReconcile($pdo, $companyId, null, null);
check('reconciliation reports the money it found',
    count($recon['buckets']) >= 5 && array_key_exists('captured', $recon['totals'])
    && $recon['totals']['refunded'] >= 500.0 && $recon['totals']['simulated_captured'] > 0,
    json_encode([$recon['totals'], array_map('count', $recon['buckets'])]));
check('simulated money is counted separately from real settlement',
    (float) $recon['totals']['simulated_captured'] === (float) $recon['totals']['captured'],
    json_encode($recon['totals']));

// ===========================================================================
// 6. A callback announces, it does not prove
// ===========================================================================

$unsigned = gatewayHandleWebhook($pdo, 'paystack', '{"event":"charge.success","data":{"reference":"nope"}}', []);
check('a callback with no signature is refused', $unsigned['status'] === 401, json_encode($unsigned));

$forged = gatewayHandleWebhook($pdo, 'paystack', '{"event":"charge.success","data":{"reference":"nope"}}',
    ['x-paystack-signature' => hash_hmac('sha512', '{"event":"charge.success","data":{"reference":"nope"}}', 'not-the-secret')]);
check('a callback signed with the wrong key is refused', $forged['status'] === 401, json_encode($forged));

$unreadable = gatewayHandleWebhook($pdo, 'paystack', 'this is not json', ['x-paystack-signature' => str_repeat('0', 128)]);
check('a callback that is not JSON is refused before anything is read', $unreadable['status'] === 400, json_encode($unreadable));

$hookSession = $openSession();
$eventBody = json_encode(['event' => 'charge.success', 'data' => [
    'id' => 88001, 'status' => 'success', 'reference' => $hookSession['reference'],
    'amount' => 200000, 'currency' => 'NGN', 'channel' => 'card',
]]);
$signed = ['x-paystack-signature' => hash_hmac('sha512', $eventBody, $webhookRaw)];
$hooked = gatewayHandleWebhook($pdo, 'paystack', $eventBody, $signed);
$hookRow = gatewayTransaction($pdo, $companyId, (int) $hookSession['transaction_id']);
check('a correctly signed callback settles the session it names',
    $hooked['status'] === 200 && (string) $hookRow['status'] === 'paid' && (int) $hookRow['invoice_id'] > 0,
    json_encode(['answer' => $hooked, 'row' => [$hookRow['status'], $hookRow['invoice_id']]]));

$redelivered = gatewayHandleWebhook($pdo, 'paystack', $eventBody, $signed);
$hookEvents = (int) sqlValue($pdo, "SELECT COUNT(*) FROM gateway_events WHERE provider_event_id = '88001'
                                     AND company_id = $companyId AND log_id > {$mark['log_id']}");
check('the same event delivered twice is acknowledged once',
    $redelivered['status'] === 200 && str_contains((string) $redelivered['body'], 'Already received') && $hookEvents === 1,
    json_encode(['answer' => $redelivered, 'events' => $hookEvents]));

$tamperedBody = json_encode(['event' => 'charge.success', 'data' => [
    'id' => 88002, 'status' => 'success', 'reference' => $hookSession['reference'],
    'amount' => 1, 'currency' => 'NGN',
]]);
$tampered = gatewayHandleWebhook($pdo, 'paystack', $tamperedBody,
    ['x-paystack-signature' => hash_hmac('sha512', $tamperedBody, 'not-the-secret')]);
check('a callback that claims a different amount is refused, not believed',
    $tampered['status'] === 401 && (int) sqlValue($pdo, "SELECT COUNT(*) FROM gateway_events WHERE provider_event_id = '88002'
                                                          AND company_id = $companyId AND log_id > {$mark['log_id']}") === 0,
    json_encode($tampered));

$unknownRef = json_encode(['event' => 'charge.success', 'data' => ['id' => 88003, 'status' => 'success', 'reference' => 'GWTEST-NOBODY', 'amount' => 100, 'currency' => 'NGN']]);
$unknownAnswer = gatewayHandleWebhook($pdo, 'paystack', $unknownRef, ['x-paystack-signature' => hash_hmac('sha512', $unknownRef, $webhookRaw)]);
check('an event for a stranger is kept for reconciliation and posts nothing',
    $unknownAnswer['status'] === 200 && (int) sqlValue($pdo, "SELECT COUNT(*) FROM gateway_transactions WHERE reference = 'GWTEST-NOBODY'") === 0,
    json_encode($unknownAnswer));

// ===========================================================================
// 7. The simulator cannot be mistaken for a settlement
// ===========================================================================

$switchOff = $openSession();
$halfOff = gatewaySaveConfig($pdo, $companyId, 'paystack',
    ['mode' => 'test', 'is_enabled' => 1, 'sandbox_allowed' => 0], $adminId);
check('simulation cannot be taken from an enabled gateway that has no real key behind it',
    ($halfOff['ok'] ?? true) === false && gatewayResolvedConfig($pdo, $companyId, 'paystack')['runtime_mode'] === 'sandbox',
    json_encode($halfOff['messages'] ?? $halfOff['message'] ?? null));

$switchedOff = gatewaySaveConfig($pdo, $companyId, 'paystack',
    ['mode' => 'test', 'is_enabled' => 0, 'sandbox_allowed' => 0], $adminId);
$refused = gatewaySandboxRespond($pdo, $companyId, (string) $switchOff['reference'], 'approve', $adminId);
$switchedRow = gatewayTransaction($pdo, $companyId, (int) $switchOff['transaction_id']) ?? [];
check('a provider that has been switched off records nothing, even for a session it opened itself',
    ($switchedOff['ok'] ?? false) === true && ($refused['paid'] ?? true) === false
    && str_contains(strtolower((string) ($refused['message'] ?? '')), 'sandbox')
    && empty($switchedRow['invoice_id']),
    json_encode(['paid' => $refused['paid'] ?? null, 'message' => $refused['message'] ?? null,
                 'save' => $switchedOff, 'row' => [$switchedRow['status'] ?? null, $switchedRow['invoice_id'] ?? null]]));
check('the card tender goes with the provider that made it',
    !array_key_exists($tenderCode, gatewayOnlineMethods($pdo, $companyId)),
    json_encode(array_keys(gatewayOnlineMethods($pdo, $companyId))));

// A session that says it was opened against a live account is never answerable
// here, whatever this host believes about simulation.
$liveSession = gatewayCreateTransaction($pdo, $companyId, [
    'gateway' => 'paystack', 'runtime_mode' => 'live', 'amount' => 1000.00, 'currency' => 'NGN',
    'user_id' => $adminId, 'branch_id' => $branchId, 'customer_email' => 'gateway.test@example.test',
    'snapshot' => $snapshot,
]);
$mustNotAnswer = gatewaySandboxRespond($pdo, $companyId, (string) $liveSession['reference'], 'approve', $adminId);
check('a session opened against a real gateway is answered by that gateway only',
    ($mustNotAnswer['paid'] ?? true) === false && str_contains((string) ($mustNotAnswer['message'] ?? ''), 'real gateway'),
    json_encode($mustNotAnswer['message'] ?? null));
$restored = gatewaySaveConfig($pdo, $companyId, 'paystack', [
    'mode' => 'test', 'is_enabled' => 1, 'sandbox_allowed' => 1,
    'test_public_key' => $publicRaw, 'test_webhook_secret' => $webhookRaw,
], $adminId);
check('the simulator and its tender come back the moment the provider is switched on again',
    ($restored['ok'] ?? false) === true
    && gatewayResolvedConfig($pdo, $companyId, 'paystack')['runtime_mode'] === 'sandbox'
    && array_key_exists($tenderCode, gatewayOnlineMethods($pdo, $companyId)),
    json_encode(['runtime' => gatewayResolvedConfig($pdo, $companyId, 'paystack')['runtime_mode'] ?? null,
                 'messages' => $restored['messages'] ?? null]));

$_SESSION['pos_cart'] = [];
$noBasket = gatewayPosSession($pdo, $companyId, $tenderCode, $branchId, $adminId);
check('a till with an empty basket cannot open a payment',
    ($noBasket['ok'] ?? true) === false && str_contains((string) ($noBasket['message'] ?? ''), 'empty'),
    json_encode($noBasket['message'] ?? null));
$notOnline = gatewayPosSession($pdo, $companyId, 'cash', $branchId, $adminId);
check('cash cannot be taken through the gateway button',
    ($notOnline['ok'] ?? true) === false && str_contains((string) ($notOnline['message'] ?? ''), 'not an online payment method'),
    json_encode($notOnline['message'] ?? null));

// A card sale belongs to the drawer that was open when it was taken, so the
// till rule is tested with a shift of this run's own.
require_once APP_ROOT . '/includes/shift_functions.php';
$drawerNeeded = shiftEnforcementEnabled($pdo);
$drawer = $drawerNeeded
    ? shiftOpen($pdo, $adminId, $companyId, $branchId, 0, 'Gateway test drawer')
    : ['success' => true, 'shift_id' => 0, 'message' => ''];
check('the drawer the till needs is open', (bool) ($drawer['success'] ?? false), (string) ($drawer['message'] ?? ''));

posClearCart();
posAddItem($pdo, $productId, 1);
$tillTotal = (float) ($_SESSION['pos_total'] ?? 0);
$refusedSale = posCheckout($pdo, [
    'branch_id' => $branchId,
    'payments' => [['method' => $tenderCode, 'amount' => $tillTotal]],
    'idempotency_key' => posNewIdempotencyKey(),
]);
$stray = $pdo->query("SELECT invoice_id FROM sales_invoices WHERE company_id = $companyId
                      AND invoice_id > {$mark['invoice_id']} AND notes = 'POS sale'")->fetchAll(PDO::FETCH_COLUMN);
$runInvoices = array_merge($runInvoices, array_map('intval', $stray));
check('the till cannot post a card tender as though it were money',
    ($refusedSale['success'] ?? true) === false
    && str_contains((string) ($refusedSale['message'] ?? ''), 'online payment button')
    && $stray === [] && (int) ($refusedSale['invoice_id'] ?? -1) === 0,
    json_encode(['message' => $refusedSale['message'] ?? null, 'stray' => $stray]));

$askedAgain = gatewayPosSession($pdo, $companyId, $tenderCode, $branchId, $adminId);
$askedTwice = gatewayPosSession($pdo, $companyId, $tenderCode, $branchId, $adminId);
check('pressing the card button twice keeps one session waiting, not two',
    ($askedAgain['ok'] ?? false) === true && ($askedTwice['reused'] ?? false) === true
    && (string) $askedTwice['reference'] === (string) $askedAgain['reference']
    && (int) $askedTwice['transaction_id'] === (int) $askedAgain['transaction_id'],
    json_encode(['first' => $askedAgain['reference'] ?? null, 'second' => $askedTwice['reference'] ?? null]));
// The basket changed while the card was waiting. Three more units makes a total
// nothing else in this run asked for, so the new session cannot be a reuse.
posAddItem($pdo, $productId, 3);
$serverTotal = sprintf('%.2f', (float) ($_SESSION['pos_total'] ?? 0));
$grewCart = gatewayPosSession($pdo, $companyId, $tenderCode, $branchId, $adminId);
$grewRow = gatewayTransaction($pdo, $companyId, (int) ($grewCart['transaction_id'] ?? 0)) ?? [];
$superseded = gatewayTransaction($pdo, $companyId, (int) $askedAgain['transaction_id']) ?? [];
check('a bigger basket is priced by the server and retires the session that no longer fits',
    ($grewCart['ok'] ?? false) === true && !($grewCart['reused'] ?? true)
    && abs((float) $grewCart['amount'] - (float) $serverTotal) < 0.005 && (float) $serverTotal > $tillTotal
    && (string) ($grewRow['status'] ?? '') === 'pending' && empty($grewRow['invoice_id'])
    && (string) ($superseded['status'] ?? '') === 'expired',
    json_encode(['asked' => $grewCart['amount'] ?? null, 'server' => $serverTotal, 'till' => $tillTotal,
                 'reused' => $grewCart['reused'] ?? null, 'new' => $grewRow['status'] ?? null,
                 'old' => $superseded['status'] ?? null]));
check('the basket has exactly one card session waiting at its own total',
    (int) sqlValue($pdo, "SELECT COUNT(*) FROM gateway_transactions WHERE company_id = $companyId
                          AND branch_id = $branchId AND status = 'pending' AND ROUND(amount, 2) = $serverTotal
                          AND transaction_id > {$mark['transaction_id']}") === 1,
    (string) sqlValue($pdo, "SELECT COUNT(*) FROM gateway_transactions WHERE company_id = $companyId
                             AND branch_id = $branchId AND status = 'pending' AND ROUND(amount, 2) = $serverTotal
                             AND transaction_id > {$mark['transaction_id']}"));
posClearCart();

// ===========================================================================
// 8. A callback has no session, so the trail must be stamped by hand
// ===========================================================================

$otherCompanyId = 2;
$pdo->prepare('INSERT INTO users (company_id, branch_id, username, password_hash, full_name, email, role, is_active)
               VALUES (?, NULL, ?, ?, ?, ?, ?, 1)')
    ->execute([$otherCompanyId, 'gwtest.other', password_hash('test-only', PASSWORD_DEFAULT), 'Gateway Test Other',
               'gwtest.other@example.test', 'cashier']);
$otherUser = (int) $pdo->lastInsertId();
unset($_SESSION['company_id'], $_SESSION['user_id']);
gatewayAudit($pdo, $otherCompanyId, 'UPDATE', 'gateway_transactions', 900001, '{"gateway_test":"tenancy"}', $otherUser);
$stamped = $pdo->query('SELECT company_id, user_id, entity_id FROM audit_log ORDER BY audit_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
check('an audit entry names the tenant that owns the payment, not the default',
    (int) ($stamped['company_id'] ?? 0) === $otherCompanyId && (int) ($stamped['user_id'] ?? 0) === $otherUser,
    json_encode($stamped));
$pdo->prepare('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?')->execute(['gateway_transactions', 900001]);
$_SESSION['company_id'] = $companyId;
$_SESSION['user_id'] = $adminId;

$foreignSessions = 0;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM gateway_transactions WHERE company_id = ?');
$stmt->execute([$otherCompanyId]);
check('a second company was given nothing to find', (int) $stmt->fetchColumn() === $foreignSessions);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
