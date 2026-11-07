<?php
/**
 * Role and permission helpers.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/auth_service.php';

if (!function_exists('getAllRoles')) {
    /**
     * Roles from the roles table with a static fallback for legacy enum values.
     */
    function getAllRoles(PDO $pdo, bool $activeOnly = true): array
    {
        static $cache = [];
        $cacheKey = $activeOnly ? 'active' : 'all';
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $fallback = [
            ['role_key' => 'admin', 'name' => 'Super Administrator', 'description' => 'Full access to all modules and administration', 'is_system' => 1, 'is_active' => 1],
            ['role_key' => 'manager', 'name' => 'General Manager', 'description' => 'Inventory, sales, expenses, income and approvals', 'is_system' => 1, 'is_active' => 1],
            ['role_key' => 'staff', 'name' => 'Staff', 'description' => 'Day-to-day inventory and sales entry', 'is_system' => 1, 'is_active' => 1],
        ];

        try {
            if (!tables_exist($pdo, ['roles'])) {
                return $cache[$cacheKey] = $fallback;
            }
            $sql = 'SELECT role_key, name, description, is_system, is_active FROM roles';
            if ($activeOnly) {
                $sql .= ' WHERE is_active = 1';
            }
            $sql .= ' ORDER BY is_system DESC, name ASC';
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            return $cache[$cacheKey] = ($rows ?: $fallback);
        } catch (PDOException $e) {
            app_log('warning', 'Role listing failed: ' . $e->getMessage());
            return $cache[$cacheKey] = $fallback;
        }
    }
}

if (!function_exists('getRoleName')) {
    function getRoleName(string $role): string
    {
        static $names = null;
        if ($names === null) {
            $names = [];
            try {
                $pdo = get_db_connection();
                if ($pdo instanceof PDO && tables_exist($pdo, ['roles'])) {
                    foreach ($pdo->query('SELECT role_key, name FROM roles') as $row) {
                        $names[$row['role_key']] = $row['name'];
                    }
                }
            } catch (Throwable $e) {
                // fall through to legacy map
            }
        }
        if (isset($names[$role])) {
            return $names[$role];
        }
        return [
            'admin' => 'Administrator',
            'manager' => 'Manager',
            'staff' => 'Staff',
            'operator' => 'Operator',
            'viewer' => 'Viewer',
        ][$role] ?? ucfirst($role);
    }
}

if (!function_exists('getRolePermissions')) {
    function getRolePermissions(string $role): string
    {
        try {
            $pdo = get_db_connection();
            if ($pdo instanceof PDO && tables_exist($pdo, ['roles'])) {
                $stmt = $pdo->prepare('SELECT description FROM roles WHERE role_key = ?');
                $stmt->execute([$role]);
                $desc = $stmt->fetchColumn();
                if (is_string($desc) && trim($desc) !== '') {
                    return $desc;
                }
            }
        } catch (Throwable $e) {
            // fall through
        }
        return [
            'admin' => 'Full access to all modules',
            'manager' => 'Inventory, sales, expenses, income and approvals',
            'staff' => 'Day-to-day inventory and sales entry',
            'operator' => 'Inventory and sales entry',
            'viewer' => 'Reports and dashboards only',
        ][$role] ?? 'Limited access';
    }
}

if (!function_exists('auth_accessible_branch_ids')) {
    /**
     * Branch IDs a user may operate in. An empty user_branch_access grant list
     * means every active branch of the company (legacy behaviour preserved).
     */
    function auth_accessible_branch_ids(PDO $pdo, int $userId, int $companyId): array
    {
        static $cache = [];
        $cacheKey = $userId . '|' . $companyId;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        try {
            if (tables_exist($pdo, ['user_branch_access'])) {
                $stmt = $pdo->prepare('SELECT branch_id FROM user_branch_access WHERE user_id = ?');
                $stmt->execute([$userId]);
                $granted = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                if ($granted !== []) {
                    $stmt = $pdo->prepare('SELECT branch_id FROM branches WHERE branch_id IN (' . implode(',', array_fill(0, count($granted), '?')) . ') AND company_id = ? AND is_active = 1');
                    $stmt->execute(array_merge($granted, [$companyId]));
                    $valid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                    if ($valid !== []) {
                        return $cache[$cacheKey] = $valid;
                    }
                }
            }
            $stmt = $pdo->prepare('SELECT branch_id FROM branches WHERE company_id = ? AND is_active = 1 ORDER BY branch_id');
            $stmt->execute([$companyId]);
            return $cache[$cacheKey] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            app_log('warning', 'Branch access resolution failed: ' . $e->getMessage());
            return $cache[$cacheKey] = [];
        }
    }
}

if (!function_exists('auth_branch_allowed')) {
    function auth_branch_allowed(PDO $pdo, int $branchId): bool
    {
        $user = auth_user();
        if (!$user) {
            return false;
        }
        return in_array($branchId, auth_accessible_branch_ids($pdo, (int) $user['user_id'], (int) $user['company_id']), true);
    }
}

if (!function_exists('auth_guard_branch')) {
    /**
     * Abort with 403 unless the current user may operate on $branchId.
     * 0 means "no branch selected" and always passes.
     */
    function auth_guard_branch(PDO $pdo, int $branchId): void
    {
        if ($branchId <= 0) {
            return;
        }
        if (auth_branch_allowed($pdo, $branchId)) {
            return;
        }

        $user = auth_user();
        app_log('warning', 'Branch scope violation', [
            'user_id' => (int) ($user['user_id'] ?? 0),
            'branch_id' => $branchId,
            'path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        ]);
        http_response_code(403);
        require APP_ROOT . '/views/error_403.html';
        exit;
    }
}

if (!function_exists('userHasPermission')) {
    /**
     * Check a resource.action permission using direct grants and role grants.
     * Administrators always pass. Results are cached for the current request.
     */
    function userHasPermission(PDO $pdo, int $userId, string $resource, string $action): bool
    {
        static $cache = [];
        static $schemaAvailable = null;

        $permission = $resource . '.' . $action;
        $cacheKey = $userId . '|' . $permission;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        try {
            $stmt = $pdo->prepare('SELECT role FROM users WHERE user_id = ?');
            $stmt->execute([$userId]);
            $role = (string) $stmt->fetchColumn();

            if ($role === 'admin') {
                return $cache[$cacheKey] = true;
            }

            if ($schemaAvailable === null) {
                $schemaAvailable = tables_exist($pdo, ['permissions', 'role_permissions', 'user_permissions']);
            }
            if (!$schemaAvailable) {
                return $cache[$cacheKey] = false;
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM role_permissions rp
                 INNER JOIN permissions p ON p.name = rp.permission
                 WHERE rp.role = ? AND p.name = ?'
            );
            $stmt->execute([$role, $permission]);
            if ((int) $stmt->fetchColumn() > 0) {
                return $cache[$cacheKey] = true;
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM user_permissions up
                 INNER JOIN permissions p ON p.permission_id = up.permission_id
                 WHERE up.user_id = ? AND p.name = ?'
            );
            $stmt->execute([$userId, $permission]);
            return $cache[$cacheKey] = (int) $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            app_log('warning', 'Permission check failed: ' . $e->getMessage());
            return $cache[$cacheKey] = false;
        }
    }
}

if (!function_exists('can')) {
    /**
     * Permission check for views. Returns false for guests.
     */
    function can(string $resource, string $action): bool
    {
        $user = auth_user();
        if (!$user) {
            return false;
        }

        $pdo = get_db_connection();
        if (!$pdo instanceof PDO) {
            return false;
        }

        return userHasPermission($pdo, (int) $user['user_id'], $resource, $action);
    }
}

if (!function_exists('require_permission')) {
    /**
     * Abort with a 403 page unless the current user holds resource.action.
     */
    function require_permission(string $resource, string $action): void
    {
        $user = auth_user();
        if (!$user) {
            auth_require();
            return;
        }

        $pdo = get_db_connection();
        if (!$pdo instanceof PDO) {
            http_response_code(503);
            exit('The database is unavailable. Please contact your administrator.');
        }

        $permission = $resource . '.' . $action;
        if (userHasPermission($pdo, (int) $user['user_id'], $resource, $action)) {
            return;
        }

        app_log('warning', 'Permission denied', [
            'user_id' => (int) $user['user_id'],
            'role' => (string) $user['role'],
            'permission' => $permission,
            'path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        ]);

        http_response_code(403);
        require APP_ROOT . '/views/error_403.html';
        exit;
    }
}

if (!function_exists('getUserPermissions')) {
    function getUserPermissions(PDO $pdo, int $userId): array
    {
        try {
            $stmt = $pdo->prepare('SELECT role FROM users WHERE user_id = ?');
            $stmt->execute([$userId]);
            $role = (string) $stmt->fetchColumn();

            if (!tables_exist($pdo, ['permissions', 'role_permissions', 'user_permissions'])) {
                return [];
            }

            $stmt = $pdo->prepare(
                'SELECT p.name FROM role_permissions rp
                 INNER JOIN permissions p ON p.name = rp.permission
                 WHERE rp.role = ?
                 UNION
                 SELECT p.name FROM user_permissions up
                 INNER JOIN permissions p ON p.permission_id = up.permission_id
                 WHERE up.user_id = ?'
            );
            $stmt->execute([$role, $userId]);

            return array_column($stmt->fetchAll(), 'name');
        } catch (PDOException $e) {
            app_log('warning', 'Permission listing failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(string $token): bool
    {
        return function_exists('csrf_verify')
            ? csrf_verify($token)
            : (!empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token));
    }
}

if (!function_exists('auth_can_view_cross_user_records')) {
    function auth_can_view_cross_user_records(): bool
    {
        $user = auth_user();
        return $user !== null
            && in_array(strtolower((string) ($user['role'] ?? '')), ['admin', 'manager', 'auditor'], true);
    }
}

if (!function_exists('auth_sales_scope_user_id')) {
    function auth_sales_scope_user_id(): ?int
    {
        $user = auth_user();
        if ($user === null || auth_can_view_cross_user_records()) {
            return null;
        }

        $userId = (int) ($user['user_id'] ?? 0);
        return $userId > 0 ? $userId : null;
    }
}

if (!function_exists('auth_can_view_company_sales_dashboard')) {
    function auth_can_view_company_sales_dashboard(): bool
    {
        $user = auth_user();
        return $user !== null
            && in_array(strtolower((string) ($user['role'] ?? '')), ['admin', 'auditor'], true);
    }
}

if (!function_exists('getCurrentUser')) {
    function getCurrentUser(): array
    {
        $user = auth_user();
        return [
            'id' => $user['user_id'] ?? 0,
            'username' => $user['username'] ?? '',
            'full_name' => $user['full_name'] ?? '',
            'role' => $user['role'] ?? 'guest',
            'logged_in' => $user !== null,
        ];
    }
}

if (!function_exists('requireAuth')) {
    function requireAuth(): void
    {
        auth_require();
    }
}

if (!function_exists('requireGuest')) {
    function requireGuest(): void
    {
        auth_guest();
    }
}
