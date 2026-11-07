<?php
/**
 * Product Category Controller
 * CRUD for the `categories` table (product/service taxonomy).
 * Categories are soft-deactivated only, never deleted, and removal is refused
 * while products still reference them.
 *
 * Permission gates reuse the inventory.* resources.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('CATEGORIES_MODULE') || define('CATEGORIES_MODULE', 'categories');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

// Require foundation, auth, and category functions
require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/categories_functions.php';

// Require authentication
auth_require();

// Initialize PDO
$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $categoryWriteAction = match ($postAction) {
        'save_category' => (int) ($_POST['category_id'] ?? 0) > 0 ? 'update' : 'create',
        'activate_category' => 'update',
        'deactivate_category' => 'delete',
        default => 'update',
    };
    require_permission('inventory', $categoryWriteAction);
} else {
    require_permission('inventory', 'view');
}

// Handle routing based on action
$action = (string) ($_GET['action'] ?? 'list');
$viewMap = ['manage' => 'list', 'create' => 'list', 'edit' => 'list'];
if (isset($viewMap[$action])) {
    $action = $viewMap[$action];
}

$category = [];
$editingId = (int) ($_GET['category_id'] ?? 0);

// ============================================================
// Category Actions
// ============================================================

$allCategories = getCategories($pdo, $companyId);
$activeCount = 0;
foreach ($allCategories as $row) {
    if ((int) $row['is_active'] === 1) {
        $activeCount++;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_post_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'save_category') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $existing = $categoryId > 0 ? getCategoryById($pdo, $categoryId) : null;

        if ($categoryId > 0 && (!$existing || (int) $existing['company_id'] !== $companyId)) {
            $_SESSION['auth_message'] = 'Category not found.';
            redirect('/categories?action=list');
        }

        $payload = [
            'name' => trim((string) ($_POST['category_name'] ?? '')),
            'parent_id' => (int) ($_POST['category_parent'] ?? 0),
            'sort_order' => (int) ($_POST['category_sort_order'] ?? 0),
            'is_active' => !empty($_POST['category_active']) ? 1 : 0,
        ];

        // Reuse the existing list as the hierarchy source for validation.
        $validated = validateCategory($payload, $companyId, $allCategories, $categoryId);

        if (!$validated['ok']) {
            $_SESSION['auth_message'] = $validated['error'];
            redirect('/categories' . ($categoryId > 0 ? '?action=edit&category_id=' . $categoryId : '?action=create'));
        }

        // Turning an active category off through the form is a deactivation and
        // is subject to the same product-reference guard as the explicit action.
        $isDeactivation = $categoryId > 0
            && (int) $existing['is_active'] === 1
            && (int) $validated['values']['is_active'] === 0;

        if ($isDeactivation) {
            $productCount = countCategoryProducts($pdo, $categoryId);
            if ($productCount > 0) {
                $_SESSION['auth_message'] = 'Cannot deactivate "' . $validated['values']['name']
                    . '": ' . $productCount . ' product' . ($productCount === 1 ? '' : 's')
                    . ' still ' . ($productCount === 1 ? 'uses' : 'use') . ' this category. '
                    . 'Reassign those products first.';
                redirect('/categories?action=edit&category_id=' . $categoryId);
            }
        }

        // Duplicate guard applies to new records only, so legacy categories that
        // already share a name can still be edited.
        if ($categoryId === 0 && categoryNameExists($pdo, $companyId, (string) $validated['values']['name'], $validated['values']['parent_id'])) {
            $_SESSION['auth_message'] = 'A category named "' . $validated['values']['name']
                . '" already exists at this level. Choose a different name.';
            redirect('/categories?action=create');
        }

        if ($categoryId > 0) {
            $_SESSION['auth_message'] = updateCategory($pdo, $categoryId, $validated['values'])
                ? 'Category updated successfully.'
                : 'Unable to update the category.';
        } else {
            $newId = createCategory($pdo, $validated['values']);
            $_SESSION['auth_message'] = $newId > 0
                ? 'Category created successfully.'
                : 'Unable to create the category.';
        }

        redirect('/categories?action=list');
    }

    if ($postAction === 'deactivate_category') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $existing = $categoryId > 0 ? getCategoryById($pdo, $categoryId) : null;

        if (!$existing || (int) $existing['company_id'] !== $companyId) {
            $_SESSION['auth_message'] = 'Category not found.';
        } elseif ((int) $existing['is_active'] === 0) {
            $_SESSION['auth_message'] = 'Category is already inactive.';
        } else {
            $productCount = countCategoryProducts($pdo, $categoryId);
            $childCount = 0;
            foreach ($allCategories as $row) {
                if ((int) ($row['parent_id'] ?? 0) === $categoryId) {
                    $childCount++;
                }
            }

            if ($productCount > 0) {
                $_SESSION['auth_message'] = 'Cannot deactivate "' . $existing['name'] . '": '
                    . $productCount . ' product' . ($productCount === 1 ? '' : 's') . ' still '
                    . ($productCount === 1 ? 'uses' : 'use') . ' this category. '
                    . 'Edit those products and move them to another category first. '
                    . 'Categories are never deleted while records reference them.';
            } elseif ($childCount > 0) {
                $_SESSION['auth_message'] = 'Cannot deactivate "' . $existing['name'] . '": '
                    . $childCount . ' subcategor' . ($childCount === 1 ? 'y is' : 'ies are')
                    . ' still nested under it. Deactivate or reparent them first.';
            } else {
                deactivateCategory($pdo, $categoryId);
                $_SESSION['auth_message'] = 'Category "' . $existing['name'] . '" deactivated successfully.';
            }
        }

        redirect('/categories?action=list');
    }

    if ($postAction === 'activate_category') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $existing = $categoryId > 0 ? getCategoryById($pdo, $categoryId) : null;

        if (!$existing || (int) $existing['company_id'] !== $companyId) {
            $_SESSION['auth_message'] = 'Category not found.';
        } else {
            activateCategory($pdo, $categoryId);
            $_SESSION['auth_message'] = 'Category "' . $existing['name'] . '" activated successfully.';
        }

        redirect('/categories?action=list');
    }
}

if ($editingId > 0) {
    $candidate = getCategoryById($pdo, $editingId);
    if ($candidate && (int) $candidate['company_id'] === $companyId) {
        $category = $candidate;
    }
}

// Every POST branch redirects, so $allCategories here is always a fresh read.
$parentOptions = categoryParentOptions($allCategories, $editingId);

$pageTitle = 'Product Categories';
$formAction = '/categories?action=list';

$viewFile = 'views/categories.html';

require_once APP_ROOT . '/views/' . basename($viewFile);
