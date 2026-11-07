<?php
/**
 * Module 3: Company and Branch Controller
 * Handles company, branch, and department management requests.
 * Extends Module 2 authentication.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('COMPANY_MODULE') || define('COMPANY_MODULE', 'company');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

// Require foundation, auth, and company functions
require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';

// Require authentication
auth_require();

// Initialize PDO
$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $companyWriteAction = match ($postAction) {
        'save_company' => 'update',
        'save_branch', 'save_department' => (int) ($_POST['branch_id'] ?? $_POST['department_id'] ?? 0) > 0 ? 'update' : 'create',
        'delete_branch', 'delete_department' => 'delete',
        default => 'update',
    };
    require_permission('company', $companyWriteAction);
} else {
    require_permission('company', 'view');
}

// Handle routing based on action
$action = $_GET['action'] ?? 'list';
$subaction = $_GET['subaction'] ?? '';

// Initialize variables for views
$companies = [];
$branches = [];
$departments = [];
$managers = [];
$company = [];
$branch = [];
$department = [];
$pageTitle = 'Company Management';
$formAction = '/company';

// ============================================================
// Company Actions
// ============================================================

if ($action === 'list' || $action === 'manage') {
    $pageTitle = 'Company Management';
    $companies = getAllCompanies($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        require_post_csrf();

        if ($_POST['action'] === 'save_company') {
            $editId = (int) ($_POST['company_id'] ?? 0);
            $companyName = trim((string) ($_POST['company_name'] ?? ''));
            $companyCurrency = strtoupper(substr(trim((string) ($_POST['company_currency'] ?? 'NGN')), 0, 3));
            $fiscalYear = max(1, min(12, (int) ($_POST['fiscal_year'] ?? 1)));
            $taxId = trim((string) ($_POST['company_tax_id'] ?? ''));
            $address = trim((string) ($_POST['company_address'] ?? ''));
            $phone = trim((string) ($_POST['company_phone'] ?? ''));
            $email = trim((string) ($_POST['company_email'] ?? ''));
            $website = trim((string) ($_POST['company_website'] ?? ''));

            if ($companyName === '') {
                $_SESSION['auth_message'] = 'Company name is required.';
                redirect('/company');
            }

            if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false) {
                $_SESSION['auth_message'] = 'Enter a valid company website URL.';
                redirect('/company');
            }

            $isUpdate = $editId > 0;

            if ($isUpdate) {
                $pdo->prepare("
                    UPDATE companies SET name = ?, currency = ?, fiscal_year_start = ?,
                        tax_id = ?, address = ?, phone = ?, email = ?, updated_at = NOW()
                    WHERE company_id = ?
                ")->execute([$companyName, $companyCurrency, $fiscalYear, $taxId, $address, $phone, $email, $companyId]);
                updateCompanySettings($pdo, $companyId, ['company_website' => $website]);
            } else {
                $pdo->prepare("
                    INSERT INTO companies (name, currency, fiscal_year_start, tax_id, address, phone, email, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ")->execute([$companyName, $companyCurrency, $fiscalYear, $taxId, $address, $phone, $email]);

                $newCompanyId = (int) $pdo->lastInsertId();
                updateCompanySettings($pdo, $newCompanyId, [
                    'timezone' => 'UTC',
                    'date_format' => 'm/d/Y',
                    'decimal_places' => '2',
                    'weighted_average_costing' => '1',
                    'company_website' => $website,
                ]);
            }

            $_SESSION['auth_message'] = 'Company ' . ($isUpdate ? 'updated' : 'saved') . ' successfully.';
            redirect('/company');
        }
    }

    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $company = getCompanyById($pdo, (int) $_GET['edit']);
    }
}

// ============================================================
// Branch Actions
// ============================================================

if ($action === 'branches' || $action === 'manage_branches') {
    $pageTitle = 'Branch Management';
    $branches = getBranchesByCompany($pdo, $companyId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        require_post_csrf();

        if ($_POST['action'] === 'save_branch') {
            $branchId = (int) ($_POST['branch_id'] ?? 0);
            $branchName = trim((string) ($_POST['branch_name'] ?? ''));

            if ($branchName === '') {
                $_SESSION['auth_message'] = 'Branch name is required.';
                redirect('/company?action=branches');
            }

            saveBranch($pdo, [
                'branch_id' => $branchId,
                'company_id' => $companyId,
                'name' => $branchName,
                'code' => trim((string) ($_POST['branch_code'] ?? '')),
                'address' => trim((string) ($_POST['branch_address'] ?? '')),
                'phone' => trim((string) ($_POST['branch_phone'] ?? '')),
                'email' => trim((string) ($_POST['branch_email'] ?? '')),
                'is_active' => isset($_POST['branch_active']) ? 1 : 0,
                'sort_order' => (int) ($_POST['branch_sort_order'] ?? 0),
            ]);

            $_SESSION['auth_message'] = 'Branch ' . ($branchId > 0 ? 'updated' : 'saved') . ' successfully.';
            redirect('/company?action=branches');
        }

        if ($_POST['action'] === 'delete_branch') {
            $delId = (int) ($_POST['delete_id'] ?? 0);
            if ($delId > 0) {
                deleteBranch($pdo, $delId);
                $_SESSION['auth_message'] = 'Branch deactivated successfully.';
            }
            redirect('/company?action=branches');
        }
    }

    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $branch = getBranchById($pdo, (int) $_GET['edit']);
    }
}

// ============================================================
// Department Actions
// ============================================================

if ($action === 'departments' || $action === 'manage_departments') {
    $pageTitle = 'Department Management';
    $departments = getAllDepartments($pdo, $companyId);
    $branches = getBranchesByCompany($pdo, $companyId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        require_post_csrf();

        if ($_POST['action'] === 'save_department') {
            $deptId = (int) ($_POST['department_id'] ?? 0);
            $deptName = trim((string) ($_POST['department_name'] ?? ''));

            if ($deptName === '') {
                $_SESSION['auth_message'] = 'Department name is required.';
                redirect('/company?action=departments');
            }

            saveDepartment($pdo, [
                'department_id' => $deptId,
                'company_id' => $companyId,
                'name' => $deptName,
                'code' => trim((string) ($_POST['department_code'] ?? '')),
                'description' => trim((string) ($_POST['department_description'] ?? '')),
                'branch_id' => (int) ($_POST['department_branch'] ?? 0) ?: null,
                'manager_id' => (int) ($_POST['department_manager'] ?? 0) ?: null,
                'is_active' => isset($_POST['department_active']) ? 1 : 0,
                'sort_order' => (int) ($_POST['department_sort_order'] ?? 0),
            ]);

            $_SESSION['auth_message'] = 'Department ' . ($deptId > 0 ? 'updated' : 'saved') . ' successfully.';
            redirect('/company?action=departments');
        }

        if ($_POST['action'] === 'delete_department') {
            $delId = (int) ($_POST['delete_id'] ?? 0);
            if ($delId > 0) {
                $pdo->prepare(
                    "UPDATE departments SET is_active = 0, updated_at = NOW()
                     WHERE department_id = ? AND company_id = ?"
                )->execute([$delId, $companyId]);
                $_SESSION['auth_message'] = 'Department deactivated successfully.';
            }
            redirect('/company?action=departments');
        }
    }

    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $department = getDepartmentById($pdo, (int) $_GET['edit']);
    }
}

// ============================================================
// Render Views
// ============================================================

$branches = getBranchesByCompany($pdo, $companyId);
$departments = getAllDepartments($pdo, $companyId);
$managersStmt = $pdo->prepare(
    "SELECT user_id, username, full_name FROM users
     WHERE company_id = ? AND is_active = 1
     ORDER BY full_name, username"
);
$managersStmt->execute([$companyId]);
$managers = $managersStmt->fetchAll(PDO::FETCH_ASSOC);

$companyData = getCurrentCompany($pdo);
$companyData['website'] = getCompanySetting($pdo, 'company_website', '', $companyId);
$companyCurrency = getCompanyCurrency($pdo);

$viewFile = 'views/company.html';

require_once APP_ROOT . '/views/' . basename($viewFile);
