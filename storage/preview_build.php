<?php
/**
 * Scratch tool (not part of the app): make one throwaway sale, fetch its slip
 * from Apache exactly as a browser would, and drop the returned markup + PDF in
 * storage/preview/ so headless Chrome can be pointed at them. The sale, its
 * rows and its print trail are removed on the way out.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/receipt_functions.php';
restore_error_handler();
restore_exception_handler();

const BASE_URL = 'http://localhost/inventory';
$pdo = db();
$companyId = 1;
$out = APP_ROOT . '/storage/preview';
@mkdir($out, 0775, true);

$pdo->exec("DELETE FROM branches WHERE code = 'RCP-VIEW'");
$pdo->exec("DELETE FROM products WHERE sku LIKE 'RCP-VIEW-%'");
$pdo->exec("DELETE FROM customers WHERE name = 'Receipt View Customer'");
$pdo->prepare('INSERT INTO branches (company_id, name, code, is_active, sort_order) VALUES (?, ?, ?, 1, 97)')
    ->execute([$companyId, 'Main Store', 'RCP-VIEW']);
$branchId = (int) $pdo->lastInsertId();
$marks = [
    'invoice_id' => (int) $pdo->query('SELECT COALESCE(MAX(invoice_id), 0) FROM sales_invoices')->fetchColumn(),
];

foreach ([['RCP-VIEW-1', 'Widget (small) \\ 10mm', 100.00], ['RCP-VIEW-2', 'Assorted stationery bundle for offices and schools', 25.50]] as $row) {
    [$sku, $name, $price] = $row;
    $pdo->prepare('INSERT INTO products (company_id, category_id, sku, name, sales_price, cost_price, track_inventory, manage_stock, is_active)
                   VALUES (?, 1, ?, ?, ?, 10, 1, 1, 1)')->execute([$companyId, $sku, $name, $price]);
    $productId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO product_locations (company_id, product_id, branch_id, on_hand_quantity, reserved_quantity, available_quantity, on_hand_value)
                   VALUES (?, ?, ?, 40, 0, 40, 400)')->execute([$companyId, $productId, $branchId]);
    ${'p' . substr($sku, -1)} = $productId;
}
$pdo->prepare("INSERT INTO customers (company_id, name, email, credit_limit, credit_used, payment_terms, payment_status, is_active)
               VALUES (?, 'Receipt View Customer', 'receipt.view@example.test', 0, 0, 'Net 0', 'paid', 1)")->execute([$companyId]);
$customerId = (int) $pdo->lastInsertId();
$adminId = (int) $pdo->query("SELECT user_id FROM users WHERE role = 'admin' AND company_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$_SESSION['user_id'] = $adminId;
$_SESSION['company_id'] = $companyId;
$_SESSION['branch_id'] = $branchId;
$_SESSION['user_role'] = 'admin';

$sale = createSalesInvoice($pdo, [
    'company_id' => $companyId, 'customer_id' => $customerId, 'branch_id' => $branchId, 'created_by' => $adminId,
    'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d'), 'tax_rate' => 0.075,
    'lines' => [['product_id' => $p1, 'quantity' => 2], ['product_id' => $p2, 'quantity' => 1]],
    'payments' => [['method' => 'cash', 'amount' => 300.00]],
]);
$invoiceId = (int) $sale['invoice_id'];
echo "preview sale $invoiceId total {$sale['total']}\n";

$sid = bin2hex(random_bytes(16));
$blob = '';
foreach (['user_id' => $adminId, 'username' => 'admin', 'full_name' => 'Studio Admin', 'user_role' => 'admin',
          'company_id' => $companyId, 'branch_id' => $branchId, 'login_time' => time(), 'last_activity' => time(),
          'csrf_token' => bin2hex(random_bytes(32))] as $key => $value) {
    $blob .= $key . '|' . serialize($value);
}
$sessPath = rtrim((string) ini_get('session.save_path'), '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid;
file_put_contents($sessPath, $blob);

$fetch = static function (string $path) use ($sid): array {
    $ch = curl_init(BASE_URL . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'inventory_session=' . $sid]);
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return [$body, strlen($body)];
};

[$html80] = $fetch('/sales.php?action=receipt&sale_id=' . $invoiceId);
[$html58] = $fetch('/sales.php?action=receipt&sale_id=' . $invoiceId . '&width=58');
[$pdf] = $fetch('/sales.php?action=receipt&sale_id=' . $invoiceId . '&format=pdf');
// Second view of the same sale: the page should say it is a reprint.
[$reprint] = $fetch('/sales.php?action=receipt&sale_id=' . $invoiceId);

file_put_contents("$out/receipt-80.html", $html80);
file_put_contents("$out/receipt-58.html", $html58);
file_put_contents("$out/receipt.pdf", $pdf);
file_put_contents("$out/receipt-reprint.html", $reprint);
echo 'wrote ' . strlen($html80) . ' + ' . strlen($html58) . ' + ' . strlen($pdf) . ' + ' . strlen($reprint) . " bytes\n";
echo 'reprint flag in markup: ' . (str_contains($reprint, '** reprint **') ? 'yes' : 'NO') . "\n";

$pdo->beginTransaction();
$pdo->exec("DELETE FROM customer_payments WHERE invoice_id = $invoiceId");
$pdo->exec("DELETE FROM sales_invoice_items WHERE invoice_id = $invoiceId");
$pdo->exec("DELETE FROM inventory_transactions WHERE reference_type = 'sales_invoice' AND reference_id = $invoiceId");
$pdo->exec("DELETE FROM audit_log WHERE action = 'PRINT' AND entity_type = 'sales_invoices' AND entity_id = $invoiceId");
$pdo->exec("DELETE FROM sales_invoices WHERE invoice_id = $invoiceId");
$pdo->exec("DELETE FROM product_locations WHERE product_id IN (SELECT product_id FROM products WHERE sku LIKE 'RCP-VIEW-%')");
$pdo->exec("DELETE FROM products WHERE sku LIKE 'RCP-VIEW-%'");
$pdo->exec("DELETE FROM customers WHERE name = 'Receipt View Customer'");
$pdo->exec("DELETE FROM branches WHERE code = 'RCP-VIEW'");
$pdo->commit();
@unlink($sessPath);
echo 'audit now ' . $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() . "\n";
echo 'invoices now ' . $pdo->query('SELECT COUNT(*) FROM sales_invoices')->fetchColumn() . "\n";
