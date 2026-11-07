<?php
/**
 * Module 21: Payment Methods Controller
 * Configurable payment methods administration (payment_methods table).
 * Methods are never hard-deleted; historical records reference their codes.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('PAYMENT_METHODS_MODULE') || define('PAYMENT_METHODS_MODULE', 'payment_methods');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

// Require foundation, auth, and payment methods functions
require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/payment_methods_functions.php';
require_once APP_ROOT . '/includes/gateway_functions.php';

// Require authentication
auth_require();

// Initialize PDO
$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $paymentMethodsWriteAction = match ($postAction) {
        'save_payment_method', 'toggle_payment_method' => 'update',
        default => 'update',
    };
    require_permission('payment_methods', $paymentMethodsWriteAction);
} else {
    require_permission('payment_methods', 'view');
}

// Handle routing based on action
$action = $_GET['action'] ?? 'list';

// Initialize variables for views
$paymentMethods = [];
$paymentMethod = [];
$pageTitle = 'Payment Methods';
$formAction = '/payment_methods';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_post_csrf();

    if ($_POST['action'] === 'save_payment_method') {
        $methodData = [
            'payment_method_id' => (int) ($_POST['payment_method_id'] ?? 0),
            'code' => (string) ($_POST['payment_method_code'] ?? ''),
            'name' => (string) ($_POST['payment_method_name'] ?? ''),
            'description' => (string) ($_POST['payment_method_description'] ?? ''),
            'is_cash' => isset($_POST['payment_method_is_cash']) ? 1 : 0,
            'is_online' => isset($_POST['payment_method_is_online']) ? 1 : 0,
            'is_active' => isset($_POST['payment_method_is_active']) ? 1 : 0,
            'sort_order' => (int) ($_POST['payment_method_sort_order'] ?? 0),
        ];
        if (array_key_exists('payment_method_gateway', $_POST)) {
            $methodData['gateway'] = (string) $_POST['payment_method_gateway'];
        }
        $result = savePaymentMethod($pdo, $companyId, $methodData);

        $_SESSION['auth_message'] = $result['message'];
        redirect('/payment_methods?action=list');
    }

    if ($_POST['action'] === 'toggle_payment_method') {
        $methodId = (int) ($_POST['payment_method_id'] ?? 0);
        $targetActive = isset($_POST['target_active']) && (int) $_POST['target_active'] === 1 ? 1 : 0;
        $updated = $methodId > 0 ? setPaymentMethodActive($pdo, $methodId, $companyId, $targetActive) : null;

        $_SESSION['auth_message'] = $updated !== null
            ? 'Payment method "' . $updated['name'] . '" ' . ($targetActive === 1 ? 'activated' : 'deactivated') . ' successfully. Historical records are preserved.'
            : 'Unable to change this payment method. It may not belong to your company.';
        redirect('/payment_methods?action=list');
    }
}

if ($action === 'list' || $action === 'manage_payment_methods') {
    $pageTitle = 'Payment Methods';
    $paymentMethods = getPaymentMethods($pdo, $companyId);

    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $paymentMethod = getPaymentMethodById($pdo, (int) $_GET['edit'], $companyId) ?? [];
    }
}

// ============================================================
// Render Views
// ============================================================

$paymentMethods = getPaymentMethods($pdo, $companyId);

$viewFile = 'views/payment_methods.html';

require_once APP_ROOT . '/views/' . basename($viewFile);
