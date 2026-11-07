<?php
/**
 * Payment gateway workspace: configure Paystack and Flutterwave, watch the
 * payment sessions they took, verify anything the provider has not confirmed,
 * refund, and reconcile the ledger against the gateway.
 *
 * Two rules shape every screen here. Credentials are write-only - the page
 * shows a mask, never a key. And nothing is recorded as a sale until this
 * server has verified the payment with the provider, so a row that is still
 * "pending" has no invoice and cannot be talked into having one.
 *
 * Permission resource: gateways (view, update, verify, refund).
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/payment_methods_functions.php';
require_once APP_ROOT . '/includes/gateway_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');

$verbs = [
    'save_config' => 'update',
    'test' => 'update',
    'verify' => 'verify',
    'reconcile' => 'verify',
    'expire' => 'verify',
    'refund' => 'refund',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('gateways', $verbs[$action] ?? 'update');
} else {
    require_permission('gateways', 'view');
}

$transactionId = (int) ($_GET['transaction_id'] ?? $_POST['transaction_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();

    if ($action === 'save_config') {
        $gateway = (string) ($_POST['gateway'] ?? '');
        $input = [
            'mode' => (string) ($_POST['mode'] ?? 'test'),
            'is_enabled' => !empty($_POST['is_enabled']),
            'sandbox_allowed' => !empty($_POST['sandbox_allowed']),
        ];
        // Blank means "keep what is stored"; only an explicit forget flag clears.
        foreach (['test_public_key', 'test_secret_key', 'test_webhook_secret',
                  'live_public_key', 'live_secret_key', 'live_webhook_secret'] as $field) {
            $input[$field] = (string) ($_POST[$field] ?? '');
            $input['clear_' . $field] = !empty($_POST['clear_' . $field]);
        }
        $result = gatewaySaveConfig($pdo, $companyId, $gateway, $input, $userId);
        $_SESSION['auth_message'] = implode(' ', $result['messages']);
        redirect('/payment_gateways?action=list' . ($result['ok'] ? '' : '&gateway=' . rawurlencode($gateway)));
    }

    if ($action === 'test') {
        $gateway = (string) ($_POST['gateway'] ?? '');
        $result = gatewayTestConnection($pdo, $companyId, $gateway, $userId);
        $_SESSION['auth_message'] = $result['message'];
        redirect('/payment_gateways?action=list');
    }

    if ($action === 'verify') {
        $transaction = gatewayTransaction($pdo, $companyId, $transactionId);
        if ($transaction === null) {
            $_SESSION['auth_message'] = 'That payment session is not in this company.';
            redirect('/payment_gateways?action=list');
        }
        $result = gatewayVerify($pdo, $companyId, (string) $transaction['reference'], (string) $transaction['gateway']);
        $_SESSION['auth_message'] = $result['message'];
        redirect('/payment_gateways?action=detail&transaction_id=' . $transactionId);
    }

    if ($action === 'expire') {
        $minutes = max(1, (int) ($_POST['minutes'] ?? 60));
        $expired = gatewayExpireStale($pdo, $companyId, $minutes);
        $_SESSION['auth_message'] = $expired === 0
            ? 'No payment session was older than ' . $minutes . ' minutes.'
            : $expired . ' unpaid payment session' . ($expired === 1 ? '' : 's') . ' expired.';
        redirect('/payment_gateways?action=list#reconciliation');
    }

    if ($action === 'refund') {
        $transaction = gatewayTransaction($pdo, $companyId, $transactionId);
        if ($transaction === null) {
            $_SESSION['auth_message'] = 'That payment session is not in this company.';
            redirect('/payment_gateways?action=list');
        }
        $result = gatewayIssueRefund(
            $pdo,
            $companyId,
            $transactionId,
            round((float) ($_POST['amount'] ?? 0), 2),
            mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255),
            $userId
        );
        $_SESSION['auth_message'] = $result['message'];
        redirect('/payment_gateways?action=detail&transaction_id=' . $transactionId);
    }
}

$filters = [
    'gateway' => (string) ($_GET['gateway'] ?? ''),
    'status' => (string) ($_GET['status'] ?? ''),
    'reference' => trim((string) ($_GET['reference'] ?? '')),
    'from' => trim((string) ($_GET['from'] ?? '')),
    'to' => trim((string) ($_GET['to'] ?? '')),
];
if (!gatewayIsKnownProvider($filters['gateway'])) {
    $filters['gateway'] = '';
}
if (!in_array($filters['status'], ['pending', 'paid', 'failed', 'expired', 'refunded', 'partial_refund'], true)) {
    $filters['status'] = '';
}

$transactions = gatewayListTransactions($pdo, $companyId, $filters, 100);
$summary = gatewaySummaryForUser($pdo, $companyId);
$reconciliation = gatewayReconcile($pdo, $companyId, $filters['from'], $filters['to']);
$events = gatewayListEvents($pdo, $companyId, 25);
$configs = gatewayConfigViews($pdo, $companyId);
$providers = gatewayProviders();
$onlineMethods = gatewayOnlineMethods($pdo, $companyId);
$sandboxWarning = gatewaySandboxWarning();
$simulationPermitted = gatewaySimulationPermitted();

$transaction = null;
$refunds = [];
$linkedSaleNumber = null;
if ($action === 'detail') {
    $transaction = gatewayTransaction($pdo, $companyId, $transactionId);
    if ($transaction === null) {
        $_SESSION['auth_message'] = 'Payment session not found for this company.';
        redirect('/payment_gateways?action=list');
    }
    $refunds = gatewayListRefunds($pdo, $companyId, $transactionId);
    if ((int) $transaction['invoice_id'] > 0) {
        $stmt = $pdo->prepare('SELECT invoice_number FROM sales_invoices WHERE invoice_id = ? AND company_id = ?');
        $stmt->execute([(int) $transaction['invoice_id'], $companyId]);
        $number = $stmt->fetchColumn();
        $linkedSaleNumber = $number === false ? null : (string) $number;
    }
}

require APP_ROOT . '/views/payment_gateways.html';
