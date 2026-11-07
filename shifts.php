<?php
/**
 * Cashier shift workspace: open a register, record drawer movements,
 * reconcile and close.
 *
 * A shift is the boundary that makes cash provable: sales and payments taken
 * while it is open are attributed to it, and Expected = Float + Cash Receipts
 * + Paid In - Cash Refunds - Paid Out is rebuilt from those rows on every
 * read. Permission resource: shifts.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('SHIFT_MODULE') || define('SHIFT_MODULE', 'shifts');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/inventory_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/shift_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

$writeVerbs = [
    'open' => 'create',
    'movement' => 'update',
    'close' => 'update',
    'void' => 'delete',
    'force_close' => 'delete',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('shifts', $writeVerbs[$action] ?? 'update');
} else {
    require_permission('shifts', 'view');
}

$shiftId = (int) ($_GET['shift_id'] ?? $_POST['shift_id'] ?? 0);
$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'user_id' => (int) ($_GET['cashier_id'] ?? 0),
    'branch_id' => (int) ($_GET['branch_id'] ?? 0),
    'from' => trim((string) ($_GET['from'] ?? '')),
    'to' => trim((string) ($_GET['to'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();

    if ($action === 'open') {
        try {
            $branchId = shiftResolveBranchId($pdo, $companyId, (int) ($_POST['branch_id'] ?? 0));
        } catch (RuntimeException $e) {
            $_SESSION['auth_message'] = $e->getMessage();
            redirect('/shifts?action=new');
        }
        $result = shiftOpen(
            $pdo,
            $userId,
            $companyId,
            $branchId,
            round((float) ($_POST['opening_float'] ?? 0), 4),
            trim((string) ($_POST['opening_note'] ?? ''))
        );
        $_SESSION['auth_message'] = $result['message']
            . ($result['success'] ? ' The register is ready for sales.' : '');
        redirect('/shifts?action=' . ($result['success'] ? 'detail&shift_id=' . (int) $result['shift_id'] : 'new'));
    }

    if ($action === 'movement') {
        $result = shiftAddMovement(
            $pdo,
            $shiftId,
            (string) ($_POST['movement_type'] ?? ''),
            round((float) ($_POST['amount'] ?? 0), 4),
            $companyId,
            trim((string) ($_POST['reason'] ?? '')),
            (int) ($_POST['reference_invoice_id'] ?? 0) ?: null
        );
        $_SESSION['auth_message'] = $result['message'];
        redirect('/shifts?action=detail&shift_id=' . $shiftId);
    }

    if ($action === 'close') {
        $countRaw = trim((string) ($_POST['closing_count'] ?? ''));
        $result = shiftClose(
            $pdo,
            $shiftId,
            $companyId,
            $countRaw === '' ? null : round((float) $countRaw, 4),
            trim((string) ($_POST['closing_note'] ?? ''))
        );
        $_SESSION['auth_message'] = $result['message'];
        redirect('/shifts?action=detail&shift_id=' . $shiftId);
    }

    if ($action === 'force_close') {
        // A manager ends an abandoned register. No counted drawer is asserted,
        // so the report states expected cash without a variance.
        $result = shiftClose($pdo, $shiftId, $companyId, null,
            trim((string) ($_POST['closing_note'] ?? '')) ?: 'Closed by manager', true);
        $_SESSION['auth_message'] = $result['message'];
        redirect('/shifts?action=detail&shift_id=' . $shiftId);
    }

    if ($action === 'void') {
        $result = shiftVoidIfEmpty($pdo, $shiftId, $companyId);
        $_SESSION['auth_message'] = $result['message'];
        redirect($result['success'] ? '/shifts?action=list' : '/shifts?action=detail&shift_id=' . $shiftId);
    }
}

// ============================================================
// Data for the views
// ============================================================

/**
 * Branch for "my shift" lookups. Falls back to 0 rather than throwing, because
 * the workspace still has to render when no branch is configured.
 */
function shiftResolveBranchIdSafe(PDO $pdo, int $companyId): int
{
    try {
        return shiftResolveBranchId($pdo, $companyId);
    } catch (RuntimeException $e) {
        return 0;
    }
}

$shift = $shiftId > 0 ? shiftGet($pdo, $shiftId, $companyId) : null;
if ($shiftId > 0 && !$shift) {
    $_SESSION['auth_message'] = 'Shift not found for this company.';
    redirect('/shifts?action=list');
}

$shiftTotals = $shift ? shiftTotals($pdo, $shift) : [];
$shiftMovements = [];
$shiftSales = [];
if ($shift) {
    $stmt = $pdo->prepare(
        "SELECT m.*, u.full_name AS actor_name
         FROM shift_movements m
         LEFT JOIN users u ON u.user_id = m.created_by
         WHERE m.shift_id = ?
         ORDER BY m.created_at DESC, m.movement_id DESC"
    );
    $stmt->execute([$shiftId]);
    $shiftMovements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $shiftSales = shiftSalesForUser($pdo, $shiftId);
}

if ($action === 'report') {
    // Printable X/Z report: standalone page, no navigation chrome.
    if (!$shift) {
        $_SESSION['auth_message'] = 'Choose a shift to report on.';
        redirect('/shifts?action=list');
    }
    $reportShift = $shift;
    $reportTotals = $shiftTotals;
    $reportMovements = $shiftMovements;
    $reportSales = $shiftSales;
    $reportCompany = getCurrentCompany($pdo);
    require_once APP_ROOT . '/views/shift_report.html';
    exit;
}

$shifts = shiftList($pdo, $companyId, $filters);

// Cashiers for the filter and the open form's own row.
$stmt = $pdo->prepare(
    "SELECT u.user_id, u.full_name, u.username, u.role, b.name AS branch_name
     FROM users u LEFT JOIN branches b ON b.branch_id = u.branch_id
     WHERE u.company_id = ? AND u.is_active = 1 ORDER BY u.full_name"
);
$stmt->execute([$companyId]);
$cashiers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$branches = getBranchesByCompany($pdo, $companyId);
$myOpenShift = shiftCurrentFor($pdo, $userId, $companyId, shiftResolveBranchIdSafe($pdo, $companyId));
$paymentMethods = salesPaymentMethodOptions($pdo, $companyId);
$cashCodes = shiftCashMethodCodes($pdo, $companyId);

require_once APP_ROOT . '/views/shifts.html';

