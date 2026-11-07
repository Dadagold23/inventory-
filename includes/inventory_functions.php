<?php
/**
 * Module 4: Inventory Functions
 * Handles product catalogue, stock tracking, transactions, and FIFO costing.
 * Extends Modules 1-3 foundation.
 */

// Prevent direct access
defined('INVENTORY_MODULE') || define('INVENTORY_MODULE', 'inventory');

// Require foundation
defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';

// ============================================================
// Product Functions
// ============================================================

/**
 * Get all products for a company
 */
function getProductsByCompany(PDO $pdo, int $companyId = 1): array {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE p.company_id = ?
        ORDER BY p.sort_order, p.name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a specific product
 */
function getProductById(PDO $pdo, int $productId): ?array {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE p.product_id = ?
    ");
    $stmt->execute([$productId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Create product
 */
function createProduct(PDO $pdo, array $productData): int {
    $name = trim((string) ($productData['name'] ?? ''));
    if ($name === '') {
        return 0;
    }

    $sku = trim((string) ($productData['sku'] ?? ''));
    if ($sku === '') {
        $sku = 'SKU-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO products (company_id, category_id, name, sku, description,
                               track_inventory, manage_stock, sales_price, cost_price,
                               reorder_level, maximum_level, target_level, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int) ($productData['company_id'] ?? 1),
            (int) ($productData['category_id'] ?? 1),
            $name,
            $sku,
            $productData['description'] ?? '',
            !empty($productData['track_inventory']) ? 1 : 0,
            !empty($productData['manage_stock']) ? 1 : 0,
            $productData['sales_price'] ?? 0.0000,
            $productData['cost_price'] ?? 0.0000,
            $productData['reorder_level'] ?? 0,
            $productData['maximum_level'] ?? 999999,
            $productData['target_level'] ?? 100,
            array_key_exists('is_active', $productData) ? (int) (bool) $productData['is_active'] : 1,
            (int) ($productData['sort_order'] ?? 0),
        ]);
    } catch (PDOException $e) {
        return 0;
    }

    return (int) $pdo->lastInsertId();
}

/**
 * Update product
 */
function updateProduct(PDO $pdo, int $productId, array $productData): bool {
    $allowed = [
        'category_id', 'name', 'sku', 'description', 'track_inventory', 'manage_stock',
        'sales_price', 'cost_price', 'reorder_level', 'maximum_level', 'target_level',
        'is_active', 'sort_order',
    ];
    $sets = [];
    $params = [];

    foreach ($allowed as $column) {
        if (!array_key_exists($column, $productData)) {
            continue;
        }
        $value = $productData[$column];
        if (in_array($column, ['track_inventory', 'manage_stock', 'is_active'], true)) {
            $value = (int) (bool) $value;
        }
        if ($column === 'name') {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
        }
        $sets[] = $column . ' = ?';
        $params[] = $value;
    }

    if ($sets === []) {
        return true;
    }

    $params[] = $productId;
    $stmt = $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE product_id = ?');
    return $stmt->execute($params);
}

/**
 * Delete product (soft delete - set inactive)
 */
function deleteProduct(PDO $pdo, int $productId): bool {
    $pdo->prepare("UPDATE products SET is_active = 0 WHERE product_id = ?")
        ->execute([$productId]);
    return true;
}

// ============================================================
// Product Location Functions
// ============================================================

/**
 * Get stock levels for a product across branches
 */
function getStockLevels(PDO $pdo, int $productId, int $branchId = null): array {
    $branchCond = $branchId ? "AND pl.branch_id = ?" : "";
    $params = $branchId ? [$branchId] : [];
    
    $stmt = $pdo->prepare("
        SELECT pl.*, b.name as branch_name
        FROM product_locations pl
        LEFT JOIN branches b ON pl.branch_id = b.branch_id
        WHERE pl.product_id = ? $branchCond
        ORDER BY pl.branch_id
    ");
    array_unshift($params, $productId);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get low stock alerts for a company
 */
function getLowStockAlerts(PDO $pdo, int $companyId = 1): array {
    $stmt = $pdo->prepare("
        SELECT p.product_id, p.name, p.sku, pl.on_hand_quantity, pl.available_quantity,
               p.reorder_level, p.maximum_level
        FROM products p
        JOIN product_locations pl ON p.product_id = pl.product_id
        WHERE p.company_id = ? AND pl.on_hand_quantity <= p.reorder_level
        ORDER BY p.name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Update stock level (called after transactions)
 */
function updateStockLevel(PDO $pdo, int $productId, int $branchId, float $onHand, float $onHandValue, float $reserved = 0.0): bool {
    $stmt = $pdo->prepare("
        UPDATE product_locations SET on_hand_quantity = ?, on_hand_value = ?, 
            reserved_quantity = ?, available_quantity = on_hand_quantity - reserved_quantity,
            last_counted = NOW()
        WHERE product_id = ? AND branch_id = ?
    ");
    
    return $stmt->execute([$onHand, $onHandValue, $reserved, $productId, $branchId]);
}

// ============================================================
// Inventory Transaction Functions
// ============================================================

/**
 * Record a stock receiving transaction
 * Updates stock levels and creates audit trail
 */
function recordReceiving(PDO $pdo, int $productId, int $branchId, float $quantity, float $unitCost, int $userId, string $notes = ''): bool {
    if ($productId <= 0 || $branchId <= 0 || $quantity <= 0 || $unitCost < 0) {
        return false;
    }

    $companyStmt = $pdo->prepare('SELECT company_id FROM products WHERE product_id = ?');
    $companyStmt->execute([$productId]);
    $companyId = $companyStmt->fetchColumn();
    if ($companyId === false) {
        return false;
    }
    $companyId = (int) $companyId;

    $pdo->beginTransaction();
    try {
        $locStmt = $pdo->prepare(
            'SELECT location_id, on_hand_quantity, reserved_quantity, on_hand_value
             FROM product_locations
             WHERE product_id = ? AND branch_id = ?
             FOR UPDATE'
        );
        $locStmt->execute([$productId, $branchId]);
        $location = $locStmt->fetch(PDO::FETCH_ASSOC);

        $oldQty = $location ? (float) $location['on_hand_quantity'] : 0.0;
        $reserved = $location ? (float) $location['reserved_quantity'] : 0.0;
        $oldValue = $location ? (float) $location['on_hand_value'] : 0.0;
        $newQty = $oldQty + $quantity;
        $newValue = $oldValue + ($quantity * $unitCost);

        $pdo->prepare(
            "INSERT INTO inventory_transactions
                (company_id, product_id, branch_id, transaction_type, quantity, unit_cost, total_value, reference_type, user_id, notes)
             VALUES (?, ?, ?, 'receiving', ?, ?, ?, NULL, ?, ?)"
        )->execute([
            $companyId, $productId, $branchId, $quantity, $unitCost, $quantity * $unitCost, $userId, $notes,
        ]);

        if ($location) {
            $pdo->prepare(
                'UPDATE product_locations
                 SET on_hand_quantity = ?, on_hand_value = ?, available_quantity = ?,
                     last_counted = NOW(), updated_at = NOW()
                 WHERE location_id = ?'
            )->execute([$newQty, $newValue, $newQty - $reserved, $location['location_id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO product_locations
                    (company_id, product_id, branch_id, on_hand_quantity, reserved_quantity,
                     available_quantity, on_hand_value, last_counted)
                 VALUES (?, ?, ?, ?, 0, ?, ?, NOW())'
            )->execute([$companyId, $productId, $branchId, $newQty, $newQty, $newValue]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_log('error', 'Stock receiving failed: ' . $e->getMessage(), ['product_id' => $productId]);
        return false;
    }
}

/**
 * Record a sale transaction (issue stock) at weighted average cost.
 */
function recordSale(PDO $pdo, int $productId, int $branchId, float $quantity, float $salesPrice, int $userId, string $notes = ''): bool {
    if ($productId <= 0 || $branchId <= 0 || $quantity <= 0) {
        return false;
    }

    $companyStmt = $pdo->prepare('SELECT company_id FROM products WHERE product_id = ?');
    $companyStmt->execute([$productId]);
    $companyId = $companyStmt->fetchColumn();
    if ($companyId === false) {
        return false;
    }
    $companyId = (int) $companyId;

    $pdo->beginTransaction();
    try {
        $locStmt = $pdo->prepare(
            'SELECT location_id, on_hand_quantity, reserved_quantity, on_hand_value
             FROM product_locations
             WHERE product_id = ? AND branch_id = ?
             FOR UPDATE'
        );
        $locStmt->execute([$productId, $branchId]);
        $location = $locStmt->fetch(PDO::FETCH_ASSOC);

        $onHand = $location ? (float) $location['on_hand_quantity'] : 0.0;
        if (!$location || $onHand < $quantity) {
            $pdo->rollBack();
            return false;
        }

        $reserved = (float) $location['reserved_quantity'];
        $unitCost = $onHand > 0 ? (float) $location['on_hand_value'] / $onHand : 0.0;
        $totalValue = $quantity * $unitCost;
        $newOnHand = $onHand - $quantity;
        $newOnHandValue = max(0.0, (float) $location['on_hand_value'] - $totalValue);

        $pdo->prepare(
            "INSERT INTO inventory_transactions
                (company_id, product_id, branch_id, transaction_type, quantity, unit_cost, total_value, reference_type, user_id, notes)
             VALUES (?, ?, ?, 'sale', ?, ?, ?, NULL, ?, ?)"
        )->execute([
            $companyId, $productId, $branchId, $quantity, $unitCost, $totalValue, $userId, $notes,
        ]);

        $pdo->prepare(
            'UPDATE product_locations
             SET on_hand_quantity = ?, on_hand_value = ?, available_quantity = ?,
                 last_counted = NOW(), updated_at = NOW()
             WHERE location_id = ?'
        )->execute([$newOnHand, $newOnHandValue, $newOnHand - $reserved, $location['location_id']]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_log('error', 'Stock sale failed: ' . $e->getMessage(), ['product_id' => $productId]);
        return false;
    }
}

/**
 * Record a stock adjustment
 */
function recordAdjustment(PDO $pdo, int $productId, int $branchId, float $quantityChange, float $unitCost, int $userId, string $reason = ''): bool {
    if ($productId <= 0 || $branchId <= 0 || $quantityChange == 0.0) {
        return false;
    }

    $companyStmt = $pdo->prepare('SELECT company_id FROM products WHERE product_id = ?');
    $companyStmt->execute([$productId]);
    $companyId = $companyStmt->fetchColumn();
    if ($companyId === false) {
        return false;
    }
    $companyId = (int) $companyId;

    $pdo->beginTransaction();
    try {
        $locStmt = $pdo->prepare(
            'SELECT location_id, on_hand_quantity, reserved_quantity, on_hand_value
             FROM product_locations
             WHERE product_id = ? AND branch_id = ?
             FOR UPDATE'
        );
        $locStmt->execute([$productId, $branchId]);
        $location = $locStmt->fetch(PDO::FETCH_ASSOC);

        if (!$location) {
            $pdo->rollBack();
            return false;
        }

        $currentOnHand = (float) $location['on_hand_quantity'];
        $reserved = (float) $location['reserved_quantity'];
        $currentValue = (float) $location['on_hand_value'];
        $newOnHand = $currentOnHand + $quantityChange;

        if ($newOnHand < 0) {
            $pdo->rollBack();
            return false;
        }

        $effectiveCost = $unitCost;
        if ($effectiveCost <= 0 && $currentOnHand > 0) {
            $effectiveCost = $currentValue / $currentOnHand;
        }
        $totalValue = $quantityChange * $effectiveCost;
        $newOnHandValue = max(0.0, $currentValue + $totalValue);

        $pdo->prepare(
            "INSERT INTO inventory_transactions
                (company_id, product_id, branch_id, transaction_type, quantity, unit_cost, total_value, reference_type, user_id, notes)
             VALUES (?, ?, ?, 'adjustment', ?, ?, ?, NULL, ?, ?)"
        )->execute([
            $companyId, $productId, $branchId, $quantityChange, $effectiveCost, $totalValue, $userId, $reason,
        ]);

        $pdo->prepare(
            'UPDATE product_locations
             SET on_hand_quantity = ?, on_hand_value = ?, available_quantity = ?,
                 last_counted = NOW(), updated_at = NOW()
             WHERE location_id = ?'
        )->execute([$newOnHand, $newOnHandValue, $newOnHand - $reserved, $location['location_id']]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_log('error', 'Stock adjustment failed: ' . $e->getMessage(), ['product_id' => $productId]);
        return false;
    }
}

// ============================================================
// Product Search and Display Functions
// ============================================================

/**
 * Search products by name or SKU
 */
function searchProducts(PDO $pdo, string $term, int $companyId = 1): array {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE p.company_id = ? AND (p.name LIKE ? OR p.sku LIKE ?)
        ORDER BY p.name
    ");
    $searchTerm = "%$term%";
    $stmt->execute([$companyId, $searchTerm, $searchTerm]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get products with low stock across all branches
 */
function getProductsLowStock(PDO $pdo, int $companyId = 1): array {
    $stmt = $pdo->prepare("
        SELECT p.product_id, p.name, p.sku, p.reorder_level,
               COALESCE(SUM(pl.on_hand_quantity), 0) as total_on_hand
        FROM products p
        LEFT JOIN product_locations pl ON p.product_id = pl.product_id
        WHERE p.company_id = ? AND p.is_active = 1
        GROUP BY p.product_id
        HAVING total_on_hand <= p.reorder_level
        ORDER BY p.name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// Utility Functions
// ============================================================

/**
 * Check if product is low on stock
 */
function isProductLowStock(PDO $pdo, int $productId, int $branchId = null): bool {
    $stock = getStockLevels($pdo, $productId, $branchId);
    $product = getProductById($pdo, $productId);
    $reorder = (float) ($product['reorder_level'] ?? 0);

    if (count($stock) === 0) {
        return $reorder > 0;
    }

    $onHand = (float) ($stock[0]['on_hand_quantity'] ?? 0);
    return $onHand <= $reorder;
}

/**
 * Get product valuation by costing method
 */
function getProductValuation(PDO $pdo, int $productId, int $branchId = null, string $costingMethod = 'fifo'): array {
    $locations = getStockLevels($pdo, $productId, $branchId);
    
    $onHand = 0;
    $value = 0.0;
    
    if (count($locations) > 0) {
        $onHand = $locations[0]['on_hand_quantity'] ?? 0;
        $value = $locations[0]['on_hand_value'] ?? 0.0;
    }
    
    return [
        'product_id' => $productId,
        'on_hand_quantity' => $onHand,
        'on_hand_value' => number_format($value, 2, '.', ''),
        'costing_method' => $costingMethod,
        'location_id' => $locations[0]['location_id'] ?? 0,
    ];
}