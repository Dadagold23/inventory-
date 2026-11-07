<?php
/**
 * Module 4: Inventory Controller
 * Handles product catalogue, stock management, transactions, and reporting.
 * Extends Modules 1-3 authentication and company foundation.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('INVENTORY_MODULE') || define('INVENTORY_MODULE', 'inventory');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

// Require foundation, auth, and inventory functions
require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/inventory_functions.php';
require_once APP_ROOT . '/includes/barcode_functions.php';

// Require authentication
auth_require();

// Initialize PDO
$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $inventoryWriteAction = match ($postAction) {
        'save_product' => (int) ($_POST['product_id'] ?? 0) > 0 ? 'update' : 'create',
        'delete_product' => 'delete',
        'record_receiving', 'record_sale', 'record_adjustment', 'update_location' => 'update',
        default => 'update',
    };
    require_permission('inventory', $inventoryWriteAction);
} else {
    require_permission('inventory', 'view');
}

// Handle routing based on action
$action = $_GET['action'] ?? 'list';
$productId = (int) ($_GET['product_id'] ?? 0);

// Initialize variables
$products = [];
$product = [];
$stockLevels = [];
$lowStockItems = [];
$productStock = [];
$categories = [];
$totalStockValue = 0.0;
$averageCost = 0.0;
$defaultBranchId = 0;
$stockBranchId = 0;
$currency = getCompanyCurrency($pdo);
$pageTitle = 'Inventory Management';
$formAction = '/inventory';

// ============================================================
// Product Listings
// ============================================================

if ($action === 'list' || $action === 'manage') {
    $pageTitle = 'Inventory - Product Catalogue';
    $products = getProductsByCompany($pdo, $companyId);
    $lowStockItems = getLowStockAlerts($pdo, $companyId);
    $categories = $pdo->prepare('SELECT category_id, name FROM categories WHERE company_id = ? ORDER BY name');
    $categories->execute([$companyId]);
    $categories = $categories->fetchAll(PDO::FETCH_ASSOC);

    $branchStmt = $pdo->prepare('SELECT branch_id FROM branches WHERE company_id = ? AND is_active = 1 ORDER BY sort_order, branch_id LIMIT 1');
    $branchStmt->execute([$companyId]);
    $defaultBranchId = (int) ($branchStmt->fetchColumn() ?: 0);
    $stockBranchId = auth_branch_id() ?: $defaultBranchId;

    $totalCost = 0.0;
    foreach ($products as $p) {
        $qty = 0.0;
        $value = 0.0;
        foreach (getStockLevels($pdo, (int) $p['product_id'], null) as $loc) {
            $qty += (float) ($loc['on_hand_quantity'] ?? 0);
            $value += (float) ($loc['on_hand_value'] ?? 0);
        }
        $productStock[(int) $p['product_id']] = ['quantity' => $qty, 'value' => $value];
        $totalStockValue += $value;
        $totalCost += (float) ($p['cost_price'] ?? 0);
    }
    $averageCost = count($products) > 0 ? $totalCost / count($products) : 0.0;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        require_post_csrf();
        $saveAction = $_POST['action'];
        
        if ($saveAction === 'save_product') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $isUpdate = $productId > 0;
            $name = $_POST['product_name'] ?? '';
            $sku = $_POST['product_sku'] ?? '';
            $categoryId = $_POST['product_category'] ?? 1;
            $description = $_POST['product_description'] ?? '';
            $salesPrice = floatval($_POST['sales_price'] ?? '0.0000');
            $costPrice = floatval($_POST['cost_price'] ?? '0.0000');
            $reorderLevel = intval($_POST['reorder_level'] ?? '0');
            $maximumLevel = intval($_POST['maximum_level'] ?? '999999');
            $targetLevel = intval($_POST['target_level'] ?? '100');
            $trackInventory = !empty($_POST['track_inventory']) ? 1 : 0;
            $manageStock = !empty($_POST['manage_stock']) ? 1 : 0;
            
            if (!empty($name)) {
                if ($productId > 0) {
                    $existingProduct = getProductById($pdo, $productId);
                    if (!$existingProduct || (int) $existingProduct['company_id'] !== $companyId) {
                        $_SESSION['auth_message'] = 'Product not found.';
                        redirect('/inventory');
                    }

                    // Update existing
                    updateProduct($pdo, $productId, [
                        'name' => $name,
                        'sku' => $sku,
                        'category_id' => $categoryId,
                        'description' => $description,
                        'sales_price' => $salesPrice,
                        'cost_price' => $costPrice,
                        'reorder_level' => $reorderLevel,
                        'maximum_level' => $maximumLevel,
                        'target_level' => $targetLevel,
                        'track_inventory' => $trackInventory,
                        'manage_stock' => $manageStock,
                    ]);
                } else {
                    // Insert new
                    $productId = createProduct($pdo, [
                        'company_id' => $companyId,
                        'category_id' => $categoryId,
                        'name' => $name,
                        'sku' => $sku,
                        'description' => $description,
                        'sales_price' => $salesPrice,
                        'cost_price' => $costPrice,
                        'reorder_level' => $reorderLevel,
                        'maximum_level' => $maximumLevel,
                        'target_level' => $targetLevel,
                        'track_inventory' => $trackInventory,
                        'manage_stock' => $manageStock,
                    ]);
                }
                
                // Every product carries a scannable identifier: allocate the
                // GS1 internal-use (2xx) code when none is set yet.
                $barcodeNote = '';
                if ($productId > 0) {
                    try {
                        $assigned = bcEnsureProductBarcode($pdo, $productId, $companyId);
                        if (!$isUpdate) {
                            $barcodeNote = ' Barcode ' . $assigned . '.';
                        }
                    } catch (Throwable $e) {
                        app_log('error', 'barcode allocation failed for product ' . $productId . ': ' . $e->getMessage());
                        $barcodeNote = ' WARNING: barcode could not be allocated - open Barcodes to fix.';
                    }
                }

                // Initialize product location if tracking inventory
                if ($productId > 0 && $trackInventory && $defaultBranchId > 0) {
                    // Check if location already exists
                    $stmt = $pdo->prepare("SELECT location_id FROM product_locations WHERE product_id = ? AND branch_id = ?");
                    $stmt->execute([$productId, $defaultBranchId]);
                    if ($stmt->fetchColumn() === false) {
                        // Create initial location with 0 stock
                        $pdo->prepare("
                            INSERT INTO product_locations
                                (company_id, product_id, branch_id, on_hand_quantity, reserved_quantity, available_quantity, on_hand_value, last_counted)
                            VALUES (?, ?, ?, 0.0000, 0.0000, 0.0000, 0.0000, NOW())
                        ")->execute([$companyId, $productId, $defaultBranchId]);
                    }
                }
                
                $_SESSION['auth_message'] = ($productId > 0
                    ? 'Product ' . ($isUpdate ? 'updated' : 'saved') . ' successfully.'
                    : 'Unable to save product. Check the SKU and try again.') . $barcodeNote;
                redirect('/inventory');
            }
        }
        
        if ($saveAction === 'delete_product') {
            $delId = (int) ($_POST['delete_id'] ?? 0);
            $existingProduct = $delId > 0 ? getProductById($pdo, $delId) : null;
            if ($existingProduct && (int) $existingProduct['company_id'] === $companyId) {
                deleteProduct($pdo, $delId);
                $_SESSION['auth_message'] = 'Product deactivated successfully.';
            } else {
                $_SESSION['auth_message'] = 'Product not found.';
            }
            redirect('/inventory');
        }
        
        if ($saveAction === 'record_receiving') {
            $pid = (int) ($_POST['product_id'] ?? 0);
            $qty = floatval($_POST['receiving_quantity'] ?? '0');
            $unitCost = floatval($_POST['unit_cost'] ?? '0.0000');
            $notes = $_POST['receiving_notes'] ?? '';
            $target = $pid > 0 ? getProductById($pdo, $pid) : null;

            if ($target && (int) $target['company_id'] === $companyId && $stockBranchId > 0 && $qty > 0) {
                $userId = (int) (auth_user()['user_id'] ?? 0);
                $result = recordReceiving($pdo, $pid, $stockBranchId, $qty, $unitCost, $userId, $notes);
                
                if ($result) {
                    $_SESSION['auth_message'] = 'Stock received and inventory updated successfully.';
                } else {
                    $_SESSION['auth_message'] = 'Error recording stock receipt.';
                }
            } else {
                $_SESSION['auth_message'] = 'Unable to record stock receipt for this product.';
            }
            redirect('/inventory?product_id=' . $pid);
        }
        
        if ($saveAction === 'record_sale') {
            $pid = (int) ($_POST['product_id'] ?? 0);
            $qty = floatval($_POST['sale_quantity'] ?? '0');
            $salesPrice = floatval($_POST['sales_price'] ?? '0.0000');
            $notes = $_POST['sale_notes'] ?? '';
            $target = $pid > 0 ? getProductById($pdo, $pid) : null;

            if ($target && (int) $target['company_id'] === $companyId && $stockBranchId > 0 && $qty > 0) {
                $userId = (int) (auth_user()['user_id'] ?? 0);
                $result = recordSale($pdo, $pid, $stockBranchId, $qty, $salesPrice, $userId, $notes);
                
                if ($result) {
                    $_SESSION['auth_message'] = 'Sale recorded and inventory updated successfully.';
                } else {
                    $_SESSION['auth_message'] = 'Error recording sale. Check that sufficient stock is available.';
                }
            } else {
                $_SESSION['auth_message'] = 'Unable to record sale for this product.';
            }
            redirect('/inventory?product_id=' . $pid);
        }
        
        if ($saveAction === 'record_adjustment') {
            $pid = (int) ($_POST['product_id'] ?? 0);
            $qtyChange = floatval($_POST['adjustment_quantity'] ?? '0');
            $unitCost = floatval($_POST['adjustment_cost'] ?? '0.0000');
            $reason = $_POST['adjustment_reason'] ?? '';
            $target = $pid > 0 ? getProductById($pdo, $pid) : null;

            if ($target && (int) $target['company_id'] === $companyId && $stockBranchId > 0 && $qtyChange != 0.0) {
                $userId = (int) (auth_user()['user_id'] ?? 0);
                $result = recordAdjustment($pdo, $pid, $stockBranchId, $qtyChange, $unitCost, $userId, $reason);
                
                if ($result) {
                    $_SESSION['auth_message'] = 'Stock adjustment recorded successfully.';
                } else {
                    $_SESSION['auth_message'] = 'Error recording adjustment. Stock cannot fall below zero.';
                }
            } else {
                $_SESSION['auth_message'] = 'Unable to record adjustment for this product.';
            }
            redirect('/inventory?product_id=' . $pid);
        }
    }
    
    // If viewing a specific product, fetch details
    if ($productId > 0) {
        $candidate = getProductById($pdo, $productId);
        if ($candidate && (int) $candidate['company_id'] === $companyId) {
            $product = $candidate;
            $stockLevels = getStockLevels($pdo, $productId);
        } else {
            $productId = 0;
        }
    }
}

// ============================================================
// Branch/Location Actions (simplified for Module 4)
// ============================================================

if ($action === 'locations') {
    $pageTitle = 'Stock Locations';
    $productId = (int) ($_GET['product_id'] ?? 0);
    
    if ($productId > 0 && isset($_POST['action'])) {
        require_post_csrf();
        $saveAction = $_POST['action'];
        
        if ($saveAction === 'update_location') {
            $locId = $_POST['location_id'] ?? 0;
            $onHand = floatval($_POST['on_hand_quantity'] ?? '0');
            $onHandValue = floatval($_POST['on_hand_value'] ?? '0.0000');
            $reserved = floatval($_POST['reserved_quantity'] ?? '0.0000');
            
            if ($locId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE product_locations SET on_hand_quantity = ?, on_hand_value = ?,
                        reserved_quantity = ?, available_quantity = on_hand_quantity - reserved_quantity,
                        last_counted = NOW()
                    WHERE location_id = ? AND company_id = ?
                ");
                $stmt->execute([$onHand, $onHandValue, $reserved, $locId, $companyId]);
            }

            $_SESSION['auth_message'] = 'Stock location updated successfully.';
            redirect('/inventory?product_id=' . $productId . '&action=locations');
        }
    }
    
    if ($productId > 0) {
        $candidate = getProductById($pdo, $productId);
        if ($candidate && (int) $candidate['company_id'] === $companyId) {
            $product = $candidate;
            $stockLevels = getStockLevels($pdo, $productId);
        } else {
            $productId = 0;
        }
    }
}

// ============================================================
// Render Views
// ============================================================

// Get company settings for display
$companyCurrency = getCompanyCurrency($pdo);
$decimalPlaces = (float)getCompanySetting($pdo, 'decimal_places', 2);

// Get valuation for display
$productValuation = [];
if ($productId > 0) {
    $productValuation = getProductValuation($pdo, $productId);
}

// Include the view
$viewFile = 'views/inventory.html';

require_once APP_ROOT . '/views/' . basename($viewFile);