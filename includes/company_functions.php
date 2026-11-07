<?php
/**
 * Module 3: Company and Branch Functions
 * Handles company setup, branch management, and department assignments.
 * Extends Module 2 authentication foundation.
 */

// Prevent direct access
defined('COMPANY_MODULE') || define('COMPANY_MODULE', 'company');

// Require foundation and auth
defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/auth_helpers.php';

// ============================================================
// Company Functions
// ============================================================

/**
 * Resolve the company the signed-in user belongs to.
 */
function currentCompanyId(): int {
    if (function_exists('auth_company_id')) {
        $companyId = auth_company_id();
        if ($companyId > 0) {
            return $companyId;
        }
    }

    return 1;
}

/**
 * Get the current user's company.
 */
function getCurrentCompany(PDO $pdo): array {
    $companyId = currentCompanyId();

    $stmt = $pdo->prepare("
        SELECT c.*,
               (SELECT b.name FROM branches b
                WHERE b.company_id = c.company_id AND b.is_active = 1
                ORDER BY b.sort_order, b.branch_id LIMIT 1) AS branch_name,
               (SELECT b.code FROM branches b
                WHERE b.company_id = c.company_id AND b.is_active = 1
                ORDER BY b.sort_order, b.branch_id LIMIT 1) AS branch_code
        FROM companies c
        WHERE c.company_id = ?
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Get all companies (admin only).
 */
function getAllCompanies(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT company_id, name, tax_id, address, phone, email, currency,
                fiscal_year_start, is_active, created_at, updated_at
         FROM companies ORDER BY name"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get company by ID.
 */
function getCompanyById(PDO $pdo, int $companyId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE company_id = ?");
    $stmt->execute([$companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Update company settings (key/value store).
 */
function updateCompanySettings(PDO $pdo, int $companyId, array $settings): bool {
    foreach ($settings as $key => $value) {
        $stmt = $pdo->prepare("SELECT setting_id FROM settings WHERE `key` = ? AND company_id = ?");
        $stmt->execute([$key, $companyId]);
        $settingId = $stmt->fetchColumn();

        if ($settingId) {
            $pdo->prepare(
                "UPDATE settings SET value = ?, updated_at = NOW() WHERE setting_id = ?"
            )->execute([(string) $value, $settingId]);
        } else {
            $pdo->prepare(
                "INSERT INTO settings (company_id, `key`, value, category, description)
                 VALUES (?, ?, ?, 'general', ?)"
            )->execute([$companyId, $key, (string) $value, ucfirst($key) . ' setting']);
        }
    }
    return true;
}

/**
 * Get company setting value. Settings are stored per company; the read
 * defaults to the authenticated company.
 */
function getCompanySetting(PDO $pdo, string $key, string $default = '', ?int $companyId = null): string {
    $companyId = $companyId ?? (function_exists('auth_company_id') ? auth_company_id() : 1);
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$key, $companyId]);
    $result = $stmt->fetchColumn();

    if ($result === false && $companyId !== 1) {
        // Legacy rows were written without company scoping; fall back to company 1.
        $fallback = $pdo->prepare("SELECT value FROM settings WHERE `key` = ? AND company_id = 1 LIMIT 1");
        $fallback->execute([$key]);
        $result = $fallback->fetchColumn();
    }

    return $result !== false ? (string) $result : $default;
}

if (!function_exists('receiptPaperWidths')) {
    /**
     * The paper sizes a receipt may be laid out for. Declared beside the
     * setting that chooses one, because they are the same rule: a width nobody
     * can render is not an option, and the PDF and the slip read this list.
     *
     * @return array<string,string> setting value => label
     */
    function receiptPaperWidths(): array
    {
        return [
            '58' => '58 mm (compact thermal)',
            '80' => '80 mm (standard thermal)',
        ];
    }
}

/**
 * Settings managed through the Application Settings editor (system_settings screen).
 * Definitions double as validation rules. 'options' rows are stored as ids.
 */
function appSettingDefinitions(PDO $pdo): array {
    $incomeSources = [];
    try {
        $stmt = $pdo->query("SELECT income_source_id, name FROM income_sources WHERE is_active = 1 ORDER BY name");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $incomeSources[(string) $row['income_source_id']] = (string) $row['name'];
        }
    } catch (Throwable $e) {
        $incomeSources = [];
    }

    return [
        'decimal_places' => [
            'label' => 'Decimal places', 'type' => 'int', 'min' => 0, 'max' => 6, 'default' => '2',
            'description' => 'Amount precision used on sales, income and reporting screens.',
        ],
        'default_tax_rate' => [
            'label' => 'Default tax rate (decimal)', 'type' => 'float', 'min' => 0, 'max' => 1, 'default' => '0',
            'description' => 'Applied to POS sales and pre-filled on new invoices and supplier orders. Enter it as a decimal, not a percentage: 0.075 charges 7.5%.',
        ],
        'default_ap_terms' => [
            'label' => 'Default payables terms', 'type' => 'string', 'max_len' => 80, 'default' => 'Net 30',
            'description' => 'Payment terms suggested when creating a vendor.',
        ],
        'default_income_source' => [
            'label' => 'Default income source', 'type' => 'select', 'default' => '0', 'options' => $incomeSources + ['0' => '— none —'],
            'description' => 'Pre-selected source on the income entry screen.',
        ],
        'allow_self_approval' => [
            'label' => 'Allow self-approval', 'type' => 'bool', 'default' => '0',
            'description' => 'When enabled, a requester may approve their own approval requests. Off by default.',
        ],
        'pos_requires_shift' => [
            'label' => 'Require an open shift for POS sales', 'type' => 'bool', 'default' => '1',
            'description' => 'Blocks register checkout until the cashier opens a shift at their branch, so cash takings are always counted into a known drawer. Turn off only for companies that do not keep a cash drawer.',
        ],
        'receipt_paper_width' => [
            'label' => 'Receipt paper width', 'type' => 'select', 'default' => '80',
            'options' => receiptPaperWidths(),
            'description' => 'The slip on the screen, the thermal print and the PDF are all laid out for this width. Choose the roll actually loaded in the printer.',
        ],
        'receipt_footer_text' => [
            'label' => 'Receipt footer message', 'type' => 'string', 'max_len' => 200,
            'default' => 'Please keep this receipt for your records.',
            'description' => 'The line under "Thank you". Returns policy, a complaint number, anything the customer should read - no link is clickable on paper.',
        ],
        'receipt_show_logo' => [
            'label' => 'Print the receipt logo', 'type' => 'bool', 'default' => '1',
            'description' => 'Shows the uploaded logo at the head of every slip and PDF. Leave on and upload nothing to print without a logo.',
        ],
    ];
}

/**
 * Read current settings-table values for the editor, merged over defaults.
 * Settings are per company: since the legacy global unique key on `key` was
 * dropped, reading every row would show one company the editor's own values.
 */
function listAppSettings(PDO $pdo, ?int $companyId = null): array {
    $companyId = $companyId ?? (function_exists('auth_company_id') ? auth_company_id() : 1);
    $defs = appSettingDefinitions($pdo);
    $rows = [];
    try {
        $stmt = $pdo->prepare('SELECT `key`, value FROM settings WHERE company_id = ?');
        $stmt->execute([$companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['key']] = (string) $row['value'];
        }
    } catch (Throwable $e) {
        $rows = [];
    }
    $out = [];
    foreach ($defs as $key => $def) {
        $out[$key] = $rows[$key] ?? $def['default'];
    }
    return $out;
}

/**
 * Validate + save editor input into the settings table.
 * @return array{ok:bool,message:string,errors:string[]}
 */
function saveAppSettings(PDO $pdo, int $companyId, array $input): array {
    $defs = appSettingDefinitions($pdo);
    $errors = [];
    $clean = [];

    foreach ($defs as $key => $def) {
        $raw = $input[$key] ?? null;
        if ($raw === null) {
            continue;
        }
        $value = is_array($raw) ? (string) reset($raw) : trim((string) $raw);

        switch ($def['type']) {
            case 'bool':
                $value = ($value === '1' || $value === 'true') ? '1' : '0';
                break;
            case 'int':
                if (!preg_match('/^\d+$/', $value) || (int) $value < ($def['min'] ?? 0) || (int) $value > ($def['max'] ?? PHP_INT_MAX)) {
                    $errors[$key] = $def['label'] . ': enter a whole number between ' . ($def['min'] ?? 0) . ' and ' . ($def['max'] ?? 0) . '.';
                    continue 2;
                }
                break;
            case 'select':
                // A select is only ever one of the ids it was offered. Without
                // this, the editor would happily store anything a crafted POST
                // named and the reader would fall back silently.
                if (!isset($def['options'][$value])) {
                    $errors[$key] = $def['label'] . ': invalid selection.';
                    continue 2;
                }
                break;
            case 'float':
                if (!is_numeric($value) || (float) $value < ($def['min'] ?? 0) || (float) $value > ($def['max'] ?? 0)) {
                    $errors[$key] = $def['label'] . ': enter a number between ' . ($def['min'] ?? 0) . ' and ' . ($def['max'] ?? 0) . '.';
                    continue 2;
                }
                break;
            default:
                if (mb_strlen($value) > ($def['max_len'] ?? 255)) {
                    $errors[$key] = $def['label'] . ': too long.';
                    continue 2;
                }
        }
        $clean[$key] = $value;
    }

    if ($errors !== []) {
        return ['ok' => false, 'message' => 'Application settings were not saved: ' . implode(' ', $errors), 'errors' => $errors];
    }
    if ($clean === []) {
        return ['ok' => false, 'message' => 'Nothing to save.', 'errors' => []];
    }

    updateCompanySettings($pdo, $companyId, $clean);
    return ['ok' => true, 'message' => 'Application settings saved.', 'errors' => []];
}

/**
 * Check if user can access a specific company.
 */
function userCanAccessCompany(PDO $pdo, int $userId, int $companyId): bool {
    if ($companyId === currentCompanyId()) {
        return true;
    }

    $stmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    return (string) $stmt->fetchColumn() === 'admin';
}

// ============================================================
// Branch Functions
// ============================================================

/**
 * Get all branches for a company.
 */
function getBranchesByCompany(PDO $pdo, int $companyId): array {
    $stmt = $pdo->prepare("
        SELECT b.*, c.name AS company_name, COUNT(d.department_id) AS department_count
        FROM branches b
        LEFT JOIN companies c ON c.company_id = b.company_id
        LEFT JOIN departments d ON d.branch_id = b.branch_id AND d.is_active = 1
        WHERE b.company_id = ?
        GROUP BY b.branch_id
        ORDER BY b.sort_order, b.name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a specific branch.
 */
function getBranchById(PDO $pdo, int $branchId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM branches WHERE branch_id = ?");
    $stmt->execute([$branchId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Create or update a branch.
 */
function saveBranch(PDO $pdo, array $branchData): bool {
    $branchId = (int) ($branchData['branch_id'] ?? 0);
    $companyId = (int) ($branchData['company_id'] ?? currentCompanyId());

    if ($branchId > 0) {
        $stmt = $pdo->prepare("
            UPDATE branches SET name = ?, company_id = ?, code = ?, address = ?, phone = ?,
                email = ?, is_active = ?, sort_order = ?, updated_at = NOW()
            WHERE branch_id = ? AND company_id = ?
        ");
        return $stmt->execute([
            $branchData['name'] ?? '',
            $companyId,
            $branchData['code'] ?? '',
            $branchData['address'] ?? '',
            $branchData['phone'] ?? '',
            $branchData['email'] ?? '',
            !empty($branchData['is_active']) ? 1 : 0,
            (int) ($branchData['sort_order'] ?? 0),
            $branchId,
            $companyId,
        ]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO branches (name, company_id, code, address, phone, email, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    return $stmt->execute([
        $branchData['name'] ?? '',
        $companyId,
        $branchData['code'] ?? '',
        $branchData['address'] ?? '',
        $branchData['phone'] ?? '',
        $branchData['email'] ?? '',
        !empty($branchData['is_active']) ? 1 : 0,
        (int) ($branchData['sort_order'] ?? 0),
    ]);
}

/**
 * Soft-delete a branch.
 */
function deleteBranch(PDO $pdo, int $branchId): bool {
    $stmt = $pdo->prepare(
        "UPDATE branches SET is_active = 0, updated_at = NOW() WHERE branch_id = ? AND company_id = ?"
    );
    return $stmt->execute([$branchId, currentCompanyId()]);
}

/**
 * Get departments for a branch.
 */
function getDepartmentsByBranch(PDO $pdo, int $branchId): array {
    $stmt = $pdo->prepare("
        SELECT d.*, u.full_name AS manager_name
        FROM departments d
        LEFT JOIN users u ON d.manager_id = u.user_id
        WHERE d.branch_id = ? AND d.is_active = 1
        ORDER BY d.sort_order, d.name
    ");
    $stmt->execute([$branchId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create or update a department.
 */
function saveDepartment(PDO $pdo, array $deptData): bool {
    $deptId = (int) ($deptData['department_id'] ?? 0);
    $companyId = (int) ($deptData['company_id'] ?? currentCompanyId());
    $managerId = $deptData['manager_id'] ?? null;
    $managerId = $managerId !== null && $managerId !== '' ? (int) $managerId : null;

    if ($deptId > 0) {
        $stmt = $pdo->prepare("
            UPDATE departments SET name = ?, code = ?, description = ?, branch_id = ?,
                manager_id = ?, is_active = ?, sort_order = ?, updated_at = NOW()
            WHERE department_id = ? AND company_id = ?
        ");
        return $stmt->execute([
            $deptData['name'] ?? '',
            $deptData['code'] ?? '',
            $deptData['description'] ?? '',
            !empty($deptData['branch_id']) ? (int) $deptData['branch_id'] : null,
            $managerId,
            !empty($deptData['is_active']) ? 1 : 0,
            (int) ($deptData['sort_order'] ?? 0),
            $deptId,
            $companyId,
        ]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO departments (company_id, name, code, description, branch_id, manager_id, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    return $stmt->execute([
        $companyId,
        $deptData['name'] ?? '',
        $deptData['code'] ?? '',
        $deptData['description'] ?? '',
        !empty($deptData['branch_id']) ? (int) $deptData['branch_id'] : null,
        $managerId,
        !empty($deptData['is_active']) ? 1 : 0,
        (int) ($deptData['sort_order'] ?? 0),
    ]);
}

// ============================================================
// Department Functions
// ============================================================

/**
 * Get all departments for a company.
 */
function getAllDepartments(PDO $pdo, int $companyId): array {
    $stmt = $pdo->prepare("
        SELECT d.*, b.name AS branch_name, u.full_name AS manager_name
        FROM departments d
        LEFT JOIN branches b ON d.branch_id = b.branch_id
        LEFT JOIN users u ON d.manager_id = u.user_id
        WHERE d.company_id = ?
        ORDER BY d.sort_order, d.name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get department by ID.
 */
function getDepartmentById(PDO $pdo, int $deptId): ?array {
    $stmt = $pdo->prepare("
        SELECT d.*, b.name AS branch_name, u.full_name AS manager_name
        FROM departments d
        LEFT JOIN branches b ON d.branch_id = b.branch_id
        LEFT JOIN users u ON d.manager_id = u.user_id
        WHERE d.department_id = ?
    ");
    $stmt->execute([$deptId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Check if a user is a department manager.
 */
function userIsDepartmentManager(PDO $pdo, int $userId, int $deptId): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_id = ? AND manager_id = ?");
    $stmt->execute([$deptId, $userId]);
    return (int) $stmt->fetchColumn() > 0;
}

// ============================================================
// Utility Functions
// ============================================================

/**
 * Get branch name from ID.
 */
function getBranchName(PDO $pdo, int $branchId): string {
    $branch = getBranchById($pdo, $branchId);
    return $branch ? $branch['name'] : 'Unknown Branch';
}

/**
 * Get the configured currency code.
 */
function getCompanyCurrency(PDO $pdo): string {
    $company = getCurrentCompany($pdo);
    return $company['currency'] ?? 'USD';
}

/**
 * Format a currency amount.
 */
function formatCurrency(PDO $pdo, float $amount, ?string $currency = null): string {
    $currency = $currency ?? getCompanyCurrency($pdo);
    $decimals = (int) getCompanySetting($pdo, 'decimal_places', '2');

    return number_format($amount, $decimals, '.', '') . ' ' . $currency;
}

/**
 * Get the department a user manages.
 */
function getUserDepartment(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("
        SELECT d.*, b.name AS branch_name
        FROM departments d
        LEFT JOIN branches b ON d.branch_id = b.branch_id
        WHERE d.manager_id = ? AND d.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
