<?php
/**
 * Barcode workspace: code management, label printing, downloads and scanning.
 *
 * Product identity codes live on products.barcode (primary) plus the
 * product_barcodes alias table (alternates). Rendering lives in
 * includes/barcode_lib.php; allocation/validation in barcode_functions.php.
 * Permission resource: inventory (no separate barcode privilege exists).
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('BARCODE_MODULE') || define('BARCODE_MODULE', 'barcodes');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/inventory_functions.php';
require_once APP_ROOT . '/includes/barcode_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();
$action = $_GET['action'] ?? ($_POST['action'] ?? 'manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Attaching, re-primarying or allocating a code is a product edit;
    // only detaching an alternate code counts as a delete.
    require_permission('inventory', $action === 'delete_code' ? 'delete' : 'update');
} else {
    require_permission('inventory', 'view');
}

// ============================================================
// Read-only helpers
// ============================================================

$labelPresets = [
    '58' => ['width' => 58.0, 'height' => 35.0, 'gap' => 3.0, 'cols' => 1, 'desc' => '58 mm paper'],
    '80' => ['width' => 80.0, 'height' => 40.0, 'gap' => 3.0, 'cols' => 1, 'desc' => '80 mm paper'],
    'sheet' => ['width' => 63.5, 'height' => 38.1, 'gap' => 2.0, 'cols' => 3, 'desc' => 'Avery L7162 sheet'],
];
$symbologyChoices = [
    'auto' => 'Auto (match the code)',
    'ean13' => 'EAN-13',
    'ean8' => 'EAN-8',
    'code128' => 'Code 128 (B)',
    'code128c' => 'Code 128 (C, compact numeric)',
    'qr' => 'QR code',
];

$presetKey = (string) ($_GET['paper'] ?? $_POST['paper'] ?? '58');
if (!isset($labelPresets[$presetKey])) {
    $presetKey = '58';
}
$symbology = (string) ($_GET['type'] ?? $_POST['type'] ?? 'auto');
if (!isset($symbologyChoices[$symbology])) {
    $symbology = 'auto';
}

/** Product ids from ?ids[] / ?ids=1,2 / POST product_ids. */
function bcRequestedProductIds(PDO $pdo, int $companyId): array
{
    $raw = $_GET['ids'] ?? $_POST['product_ids'] ?? '';
    $ids = is_array($raw) ? $raw : (preg_split('/[,\s]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
    if (!$ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT product_id FROM products WHERE company_id = ? AND product_id IN ($ph) ORDER BY name");
    $stmt->execute(array_merge([$companyId], $ids));
    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'product_id'));
}

// ============================================================
// JSON scan lookup (POS integration)
// ============================================================

if ($action === 'lookup') {
    $code = trim((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        json_response(['ok' => false, 'message' => 'No code supplied.'], 422);
    }
    $branchId = auth_branch_id() ?: null;
    $product = bcLookup($pdo, $code, $companyId, $branchId);
    if (!$product || (int) $product['is_active'] !== 1) {
        json_response(['ok' => false, 'message' => 'No active product matches that code.'], 404);
    }
    json_response([
        'ok' => true,
        'product_id' => (int) $product['product_id'],
        'name' => (string) $product['name'],
        'sku' => (string) ($product['sku'] ?? ''),
        'barcode' => (string) ($product['barcode'] ?? ''),
        'matched_by' => (string) ($product['matched_by'] ?? ''),
        'symbology' => (string) ($product['symbology'] ?? ''),
        'source' => (string) ($product['source'] ?? 'primary'),
        'price' => round((float) ($product['sales_price'] ?? 0), 4),
        'on_hand' => round((float) ($product['on_hand'] ?? 0), 4),
        'track_inventory' => (int) ($product['track_inventory'] ?? 0),
        'company_id' => (int) $product['company_id'],
    ]);
}

// ============================================================
// SVG render / download
// ============================================================

if ($action === 'svg') {
    $productId = (int) ($_GET['product_id'] ?? 0);
    $code = trim((string) ($_GET['code'] ?? ''));
    $result = bcRenderForProduct($pdo, $productId, $companyId, $code, $symbology);
    if (empty($result['ok'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo $result['message'] ?? 'Cannot render barcode.';
        exit;
    }
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $result['code']) ?: 'barcode';
    header('Content-Type: image/svg+xml');
    header('Cache-Control: private, max-age=300');
    if (($_GET['download'] ?? '') === '1') {
        header('Content-Disposition: attachment; filename="barcode-' . $safe . '-' . $result['symbology'] . '.svg"');
    }
    echo $result['svg'];
    exit;
}

// ============================================================
// Bulk SVG download (ZIP)
// ============================================================

if ($action === 'pack') {
    $ids = bcRequestedProductIds($pdo, $companyId);
    if (!$ids) {
        $_SESSION['auth_message'] = 'Select at least one product to download.';
        redirect('/barcodes?action=manage');
    }
    if (!class_exists('ZipArchive')) {
        $_SESSION['auth_message'] = 'The PHP zip extension is not available on this server.';
        redirect('/barcodes?action=manage');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'bclabels') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $_SESSION['auth_message'] = 'Could not build the barcode archive.';
        redirect('/barcodes?action=manage');
    }
    $added = 0;
    foreach ($ids as $pid) {
        $result = bcRenderForProduct($pdo, $pid, $companyId, '', $symbology);
        if (empty($result['ok'])) {
            continue;
        }
        $name = sprintf('%s-%s-%s.svg', str_pad((string) $pid, 6, '0', STR_PAD_LEFT), preg_replace('/[^A-Za-z0-9._-]/', '', $result['code']), $result['symbology']);
        $zip->addFromString($name, $result['svg']);
        $added++;
    }
    $zip->close();
    barcodesAudit($pdo, 'EXPORT', (int) ($ids[0] ?? 0), 'Downloaded ' . $added . ' barcode SVG file(s)');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="barcodes-' . date('Ymd-Hi') . '.zip"');
    header('Content-Length: ' . (string) filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

// ============================================================
// Printable label sheet
// ============================================================

if ($action === 'labels') {
    $ids = bcRequestedProductIds($pdo, $companyId);
    if (!$ids) {
        $_SESSION['auth_message'] = 'Select at least one product to print labels for.';
        redirect('/barcodes?action=manage');
    }
    $copies = max(1, min(999, (int) ($_GET['copies'] ?? 1)));
    $showPrice = ($_GET['price'] ?? '1') === '1';
    $labels = [];
    foreach ($ids as $pid) {
        $product = getProductById($pdo, $pid);
        if (!$product || (int) $product['company_id'] !== $companyId) {
            continue;
        }
        $result = bcRenderForProduct($pdo, $pid, $companyId, '', $symbology);
        if (empty($result['ok'])) {
            $labels[] = ['product' => $product, 'error' => $result['message'] ?? 'Cannot render.'];
            continue;
        }
        for ($c = 0; $c < $copies; $c++) {
            $labels[] = [
                'product' => $product,
                'svg' => $result['svg'],
                'code' => $result['code'],
                'symbology' => $result['symbology'],
            ];
        }
    }
    if (!$labels) {
        $_SESSION['auth_message'] = 'No printable label could be generated for that selection.';
        redirect('/barcodes?action=manage');
    }
    $company = getCurrentCompany($pdo);
    $pageTitle = 'Barcode Labels';
    $pageHeading = 'Barcode Labels';
    $pageEyebrow = 'Barcode workspace';
    $activeNav = 'barcodes';
    require_once APP_ROOT . '/views/barcode_labels.html';
    exit;
}

// ============================================================
// POST: code management
// ============================================================

$flash = static function (string $message): void {
    $_SESSION['auth_message'] = $message;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $productId = (int) ($_POST['product_id'] ?? 0);
    $back = '/barcodes?action=manage&product_id=' . $productId;

    $product = $productId > 0 ? getProductById($pdo, $productId) : null;
    if (!$product || (int) $product['company_id'] !== $companyId) {
        $flash('Product not found in your company.');
        redirect('/barcodes?action=manage');
    }

    switch ($action) {
        case 'save_code':
            $source = (string) ($_POST['source'] ?? 'manual');
            if (!in_array($source, ['gs1', 'manual', 'import'], true)) {
                $source = 'manual';
            }
            $result = bcAddCode($pdo, $productId, $companyId, (string) ($_POST['code'] ?? ''), $source);
            $flash($result['ok'] ? $result['message'] : 'Barcode not added: ' . $result['message']);
            redirect($result['ok'] ? $back : '/barcodes?action=manage&product_id=' . $productId . '#add-code');
            // no fallthrough

        case 'set_primary':
            $result = bcSetPrimary($pdo, (int) ($_POST['barcode_id'] ?? 0), $companyId);
            $flash($result['message']);
            redirect($back);

        case 'delete_code':
            $result = bcRemoveCode($pdo, (int) ($_POST['barcode_id'] ?? 0), $companyId);
            $flash($result['message']);
            redirect($back);

        case 'ensure_code':
            try {
                $code = bcEnsureProductBarcode($pdo, $productId, $companyId);
                $flash('Internal barcode allocated: ' . $code);
            } catch (Throwable $e) {
                app_log('error', 'manual barcode allocation failed: ' . $e->getMessage());
                $flash('Could not allocate a barcode - see the application log.');
            }
            redirect($back);

        default:
            $flash('Unknown barcode action.');
            redirect('/barcodes?action=manage');
    }
}

// ============================================================
// Manage screen
// ============================================================

$focusId = (int) ($_GET['product_id'] ?? 0);
$focus = null;
$focusCodes = [];
if ($focusId > 0) {
    $candidate = getProductById($pdo, $focusId);
    if ($candidate && (int) $candidate['company_id'] === $companyId) {
        $focus = $candidate;
        $focusCodes = bcListForProduct($pdo, $focusId, $companyId);
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT p.product_id, p.name, p.sku, p.barcode, p.sales_price, p.is_active,
               (SELECT COUNT(*) FROM product_barcodes pb WHERE pb.product_id = p.product_id AND pb.company_id = p.company_id AND pb.is_active = 1) AS code_count
        FROM products p WHERE p.company_id = ?';
$params = [$companyId];
if ($search !== '') {
    $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY p.name LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$missingCodes = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE company_id = ' . (int) $companyId . ' AND (barcode IS NULL OR barcode = "")')->fetchColumn();

$pageTitle = 'Barcodes';
$pageHeading = 'Barcodes';
$pageEyebrow = 'Product identity codes, labels and scanning';
$activeNav = 'barcodes';
$viewFile = 'views/barcodes.html';
require_once APP_ROOT . '/views/' . basename($viewFile);
