<?php
/**
 * QR Code Scan-to-Pay administration.
 *
 * A pay point is the sign a customer scans, not a payment record. This screen
 * creates, edits, previews and retires those signs. Every write is company-
 * scoped, CSRF-checked and audited; the QR itself is rendered on demand from
 * the stored destination by includes/barcode_lib.php.
 *
 * Permission resource: qr_payments (view, create, update, delete).
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('QR_PAYMENTS_MODULE') || define('QR_PAYMENTS_MODULE', 'qr_payments');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/payment_methods_functions.php';
require_once APP_ROOT . '/includes/qr_payment_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();
$user = auth_user();
$userId = (int) ($user['user_id'] ?? 0);
$route = (string) ($_GET['action'] ?? 'list');
$postAction = (string) ($_POST['action'] ?? '');

// ------------------------------------------------------------
// Permission gate: a POST is judged by the action it actually performs,
// not by the page it was submitted from.
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $verb = match ($postAction) {
        'save_qr_point' => ((int) ($_POST['qr_point_id'] ?? 0) > 0) ? 'update' : 'create',
        'toggle_qr_point' => 'update',
        'delete_qr_point' => 'delete',
        default => 'update',
    };
    require_permission('qr_payments', $verb);
} else {
    require_permission('qr_payments', 'view');
}

// ------------------------------------------------------------
// SVG preview / download
// ------------------------------------------------------------

if ($route === 'preview' || $route === 'download') {
    $pointId = (int) ($_GET['id'] ?? 0);
    $point = getQrPaymentPointById($pdo, $pointId, $companyId);
    if ($point === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'QR point not found in your company.';
        exit;
    }

    $rendered = qrPaymentPointSvg($point, ['moduleWidth' => 8, 'margin' => 4]);
    if (empty($rendered['ok'])) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo $rendered['message'] !== '' ? $rendered['message'] : 'This QR point cannot be rendered.';
        exit;
    }

    $safeCode = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $point['code']) ?: 'qr-pay';
    header('Content-Type: image/svg+xml');
    header('Cache-Control: private, max-age=300');
    if ($route === 'download') {
        header('Content-Disposition: attachment; filename="qr-pay-' . $safeCode . '.svg"');
        qrPaymentAudit($pdo, 'EXPORT', $pointId, '', 'Downloaded QR SVG', $userId, $companyId);
    }
    echo $rendered['svg'];
    exit;
}

// ------------------------------------------------------------
// POST actions
// ------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();

    if ($postAction === 'save_qr_point') {
        $pointId = (int) ($_POST['qr_point_id'] ?? 0);
        $result = saveQrPaymentPoint($pdo, $companyId, [
            'qr_point_id' => $pointId,
            'name' => (string) ($_POST['name'] ?? ''),
            'code' => (string) ($_POST['code'] ?? ''),
            'branch_id' => (int) ($_POST['branch_id'] ?? 0),
            'payment_method_id' => (int) ($_POST['payment_method_id'] ?? 0),
            'merchant_reference' => (string) ($_POST['merchant_reference'] ?? ''),
            'scan_url' => (string) ($_POST['scan_url'] ?? ''),
            'amount_type' => (string) ($_POST['amount_type'] ?? 'open'),
            'amount' => (string) ($_POST['amount'] ?? ''),
            'ecc' => (string) ($_POST['ecc'] ?? 'M'),
            'description' => (string) ($_POST['description'] ?? ''),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ], $userId);

        $_SESSION['auth_message'] = $result['message'];
        if (!empty($result['success'])) {
            redirect('/qr_payments?action=list&edit=' . (int) $result['point_id']);
        }

        // Keep what the administrator typed so a validation error does not
        // erase a long payment link. The checkbox needs an explicit sentinel:
        // an unchecked box is absent from POST, which is not the same as "on".
        $_SESSION['qr_payment_old'] = $_POST;
        $_SESSION['qr_payment_old']['is_active'] = !empty($_POST['is_active']) ? 1 : 0;
        redirect($pointId > 0
            ? '/qr_payments?action=list&edit=' . $pointId
            : '/qr_payments?action=list&new=1');
    }

    if ($postAction === 'toggle_qr_point') {
        $pointId = (int) ($_POST['qr_point_id'] ?? 0);
        $targetActive = (int) ($_POST['target_active'] ?? 0) === 1 ? 1 : 0;
        $updated = $pointId > 0
            ? setQrPaymentPointActive($pdo, $pointId, $companyId, $targetActive, $userId)
            : null;

        $_SESSION['auth_message'] = $updated !== null
            ? 'QR point "' . (string) $updated['name'] . '" ' . ($targetActive === 1 ? 'activated' : 'deactivated') . '. A deactivated code stays in the list so printed copies can still be identified.'
            : 'Unable to change this QR point. It may not belong to your company.';
        redirect('/qr_payments?action=list');
    }

    if ($postAction === 'delete_qr_point') {
        $result = deleteQrPaymentPoint($pdo, (int) ($_POST['qr_point_id'] ?? 0), $companyId, $userId);
        $_SESSION['auth_message'] = $result['message'];
        redirect('/qr_payments?action=list');
    }

    $_SESSION['auth_message'] = 'Unknown QR pay point action.';
    redirect('/qr_payments?action=list');
}

// ------------------------------------------------------------
// List / edit screen
// ------------------------------------------------------------

$points = getQrPaymentPoints($pdo, $companyId);
$branches = getBranchesByCompany($pdo, $companyId);
$paymentMethods = getPaymentMethods($pdo, $companyId, true);

$editingPoint = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $candidate = getQrPaymentPointById($pdo, (int) $_GET['edit'], $companyId);
    if ($candidate !== null) {
        $editingPoint = $candidate;
    }
}

$oldPoint = [];
if (!empty($_SESSION['qr_payment_old']) && is_array($_SESSION['qr_payment_old'])) {
    $oldPoint = $_SESSION['qr_payment_old'];
    unset($_SESSION['qr_payment_old']);
}

$pageTitle = 'QR Code Scan-to-Pay';
$pageHeading = 'QR Code Scan-to-Pay';
$pageEyebrow = 'Administration';
$activeNav = 'qr_payments';

require APP_ROOT . '/views/qr_payments.html';
