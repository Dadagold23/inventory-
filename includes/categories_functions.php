<?php
/**
 * Product category functions (categories table).
 * Categories are the product/service taxonomy referenced by products.category_id.
 * Removal is never physical: records are deactivated, and only while no product
 * still points at them.
 */

defined('CATEGORIES_MODULE') || define('CATEGORIES_MODULE', 'categories');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/database.php';

/**
 * Categories of a company with the parent name, product count and child count.
 */
function getCategories(PDO $pdo, int $companyId, bool $activeOnly = false): array
{
    $cond = $activeOnly ? 'AND c.is_active = 1' : '';
    $stmt = $pdo->prepare("
        SELECT c.category_id, c.company_id, c.name, c.parent_id, c.sort_order, c.is_active,
               c.created_at, c.updated_at,
               p.name AS parent_name,
               (SELECT COUNT(*) FROM products pr WHERE pr.category_id = c.category_id) AS product_count,
               (SELECT COUNT(*) FROM categories ch WHERE ch.parent_id = c.category_id) AS child_count
        FROM categories c
        LEFT JOIN categories p ON p.category_id = c.parent_id
        WHERE c.company_id = ? $cond
        ORDER BY c.sort_order, c.name, c.category_id
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCategoryById(PDO $pdo, int $categoryId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE category_id = ?');
    $stmt->execute([$categoryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * How many products reference a category. Drives the deactivation refusal.
 */
function countCategoryProducts(PDO $pdo, int $categoryId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
    $stmt->execute([$categoryId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Validate a category payload. Returns ['ok' => bool, 'error' => string, 'values' => array].
 * The parent must be a category of the same company and must not be the record
 * itself or one of its descendants (no cycles).
 */
function validateCategory(array $data, int $companyId, array $companyCategories, int $editingId = 0): array
{
    $name = trim((string) ($data['name'] ?? ''));
    $parentId = (int) ($data['parent_id'] ?? 0);
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if ($name === '') {
        return ['ok' => false, 'error' => 'Category name is required.', 'values' => []];
    }

    $values = [
        'company_id' => $companyId,
        'name' => substr($name, 0, 80),
        'parent_id' => $parentId > 0 ? $parentId : null,
        'sort_order' => max(0, min(65535, $sortOrder)),
        'is_active' => $isActive,
    ];

    if ($parentId > 0) {
        $parent = null;
        foreach ($companyCategories as $row) {
            if ((int) $row['category_id'] === $parentId) {
                $parent = $row;
                break;
            }
        }
        if ($parent === null || (int) $parent['company_id'] !== $companyId) {
            return ['ok' => false, 'error' => 'The selected parent category does not belong to this company.', 'values' => []];
        }
        if ($editingId > 0) {
            if ($parentId === $editingId) {
                return ['ok' => false, 'error' => 'A category cannot be its own parent.', 'values' => []];
            }
            if (in_array($parentId, categoryDescendantIds($companyCategories, $editingId), true)) {
                return ['ok' => false, 'error' => 'A category cannot be moved under one of its own subcategories.', 'values' => []];
            }
        }
    }

    return ['ok' => true, 'error' => '', 'values' => $values];
}

/**
 * Category IDs nested below $rootId (direct and indirect children).
 */
function categoryDescendantIds(array $categories, int $rootId, ?array $seen = null): array
{
    if ($seen === null) {
        $seen = [$rootId => true];
    }
    $ids = [];
    foreach ($categories as $row) {
        $cid = (int) $row['category_id'];
        $pid = (int) ($row['parent_id'] ?? 0);
        if ($pid === $rootId && !isset($seen[$cid])) {
            $seen[$cid] = true;
            $ids[] = $cid;
            foreach (categoryDescendantIds($categories, $cid, $seen) as $nested) {
                $ids[] = $nested;
                $seen[$nested] = true;
            }
        }
    }
    return $ids;
}

/**
 * Parent candidates for the select: every company category except the record
 * being edited and its descendants, flattened with a depth for indentation.
 */
function categoryParentOptions(array $categories, int $excludeId = 0): array
{
    $blocked = $excludeId > 0
        ? array_merge([$excludeId], categoryDescendantIds($categories, $excludeId))
        : [];

    $byParent = [];
    foreach ($categories as $row) {
        $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
    }

    $options = [];
    $walk = static function (int $parentId, int $depth) use (&$walk, $byParent, $blocked, &$options): void {
        foreach ($byParent[$parentId] ?? [] as $row) {
            $cid = (int) $row['category_id'];
            if (in_array($cid, $blocked, true)) {
                continue;
            }
            $options[] = ['category_id' => $cid, 'name' => (string) $row['name'], 'depth' => $depth];
            $walk($cid, $depth + 1);
        }
    };
    $walk(0, 0);

    return $options;
}

/**
 * Duplicate guard: the same company cannot hold two active categories with the
 * same name under the same parent.
 */
function categoryNameExists(PDO $pdo, int $companyId, string $name, ?int $parentId, int $excludeId = 0): bool
{
    $cond = $parentId === null || $parentId === 0
        ? 'AND parent_id IS NULL'
        : 'AND parent_id = ?';
    $params = [$companyId, $name];
    if ($parentId !== null && $parentId > 0) {
        $params[] = (int) $parentId;
    }
    $sql = "SELECT COUNT(*) FROM categories WHERE company_id = ? AND name = ? $cond AND category_id <> ?";
    $params[] = $excludeId;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function createCategory(PDO $pdo, array $values): int
{
    if (trim((string) ($values['name'] ?? '')) === '') {
        return 0;
    }

    $stmt = $pdo->prepare("
        INSERT INTO categories (company_id, name, parent_id, sort_order, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        (int) $values['company_id'],
        (string) $values['name'],
        $values['parent_id'] ?? null,
        (int) ($values['sort_order'] ?? 0),
        (int) ($values['is_active'] ?? 1),
    ]);

    return (int) $pdo->lastInsertId();
}

function updateCategory(PDO $pdo, int $categoryId, array $values): bool
{
    if ($categoryId <= 0 || trim((string) ($values['name'] ?? '')) === '') {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE categories
           SET name = ?, parent_id = ?, sort_order = ?, is_active = ?, updated_at = NOW()
         WHERE category_id = ?
    ");
    return $stmt->execute([
        (string) $values['name'],
        $values['parent_id'] ?? null,
        (int) ($values['sort_order'] ?? 0),
        (int) ($values['is_active'] ?? 1),
        $categoryId,
    ]);
}

/**
 * Soft deactivate only. Callers must check countCategoryProducts() first.
 */
function deactivateCategory(PDO $pdo, int $categoryId): bool
{
    if ($categoryId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('UPDATE categories SET is_active = 0, updated_at = NOW() WHERE category_id = ?');
    return $stmt->execute([$categoryId]);
}

function activateCategory(PDO $pdo, int $categoryId): bool
{
    if ($categoryId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('UPDATE categories SET is_active = 1, updated_at = NOW() WHERE category_id = ?');
    return $stmt->execute([$categoryId]);
}
