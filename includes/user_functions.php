<?php
/**
 * User, role and permission administration.
 * All mutations are company-scoped and audited through audit_log.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

function users_audit(PDO $pdo, string $action, int $targetUserId, string $details): void
{
    $actor = auth_user();
    $allowed = ['CREATE', 'READ', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'EXPORT', 'IMPORT', 'PRINT'];
    if (!in_array($action, $allowed, true)) {
        $action = 'UPDATE';
    }
    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, new_value, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int) ($actor['company_id'] ?? 0),
        (int) ($actor['user_id'] ?? 0),
        $action,
        'users',
        $targetUserId,
        json_encode(['target_user_id' => $targetUserId, 'details' => $details]),
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);
}

function adminListUsers(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.username, u.email, u.full_name, u.role, u.company_id, u.branch_id,
                u.department_id, u.is_active, u.last_login, u.lock_until, r.name AS role_name,
                b.name AS branch_name, d.name AS department_name
         FROM users u
         LEFT JOIN roles r ON r.role_key = u.role
         LEFT JOIN branches b ON b.branch_id = u.branch_id
         LEFT JOIN departments d ON d.department_id = u.department_id
         WHERE u.company_id = ?
         ORDER BY u.is_active DESC, u.username'
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function adminGetUser(PDO $pdo, int $userId, int $companyId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ? AND company_id = ?');
    $stmt->execute([$userId, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function adminRoleExists(PDO $pdo, string $roleKey): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE role_key = ? AND is_active = 1');
        $stmt->execute([$roleKey]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return $roleKey !== '';
    }
}

/**
 * @return array{ok:bool,message:string,user_id?:int}
 */
function adminCreateUser(PDO $pdo, int $companyId, array $data): array
{
    $username = trim((string) ($data['username'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $fullName = trim((string) ($data['full_name'] ?? ''));
    $role = trim((string) ($data['role'] ?? 'staff'));
    $password = (string) ($data['password'] ?? '');
    $branchId = (int) ($data['branch_id'] ?? 0);
    $departmentId = (int) ($data['department_id'] ?? 0);

    if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        return ['ok' => false, 'message' => 'Username must be 3-50 characters (letters, numbers, dot, dash, underscore).'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'A valid email address is required.'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'message' => 'Password must be at least 8 characters.'];
    }
    if (!adminRoleExists($pdo, $role)) {
        return ['ok' => false, 'message' => 'The selected role does not exist.'];
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $email]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'A user with that username or email already exists.'];
    }

    if ($branchId > 0) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE branch_id = ? AND company_id = ? AND is_active = 1');
        $stmt->execute([$branchId, $companyId]);
        if ((int) $stmt->fetchColumn() === 0) {
            return ['ok' => false, 'message' => 'The selected branch does not belong to this company.'];
        }
    }
    if ($departmentId > 0) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM departments WHERE department_id = ? AND company_id = ? AND is_active = 1');
        $stmt->execute([$departmentId, $companyId]);
        if ((int) $stmt->fetchColumn() === 0) {
            return ['ok' => false, 'message' => 'The selected department does not belong to this company or is inactive.'];
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (username, email, full_name, password_hash, role, company_id, branch_id, department_id, is_active, password_changed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())'
    );
    $stmt->execute([
        $username, $email, $fullName !== '' ? $fullName : $username,
        password_hash($password, PASSWORD_DEFAULT), $role, $companyId,
        $branchId > 0 ? $branchId : null, $departmentId > 0 ? $departmentId : null,
    ]);
    $userId = (int) $pdo->lastInsertId();

    adminApplyUserBranches($pdo, $userId, $companyId, (array) ($data['branch_access'] ?? []));
    adminSaveUserPermissions($pdo, $userId, $companyId, (array) ($data['permissions'] ?? []));
    users_audit($pdo, 'CREATE', $userId, "Created user '{$username}' with role '{$role}'");

    return ['ok' => true, 'message' => 'User created successfully.', 'user_id' => $userId];
}

/**
 * @return array{ok:bool,message:string}
 */
function adminUpdateUser(PDO $pdo, int $userId, int $companyId, array $data): array
{
    $target = adminGetUser($pdo, $userId, $companyId);
    if (!$target) {
        return ['ok' => false, 'message' => 'User not found in your company.'];
    }

    $email = trim((string) ($data['email'] ?? $target['email']));
    $fullName = trim((string) ($data['full_name'] ?? $target['full_name']));
    $role = trim((string) ($data['role'] ?? $target['role']));
    $branchId = (int) ($data['branch_id'] ?? 0);
    $departmentId = (int) ($data['department_id'] ?? ($target['department_id'] ?? 0));
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'A valid email address is required.'];
    }
    if (!adminRoleExists($pdo, $role)) {
        return ['ok' => false, 'message' => 'The selected role does not exist.'];
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND user_id <> ?');
    $stmt->execute([$email, $userId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'Another user already uses that email.'];
    }
    if ($branchId > 0) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE branch_id = ? AND company_id = ?');
        $stmt->execute([$branchId, $companyId]);
        if ((int) $stmt->fetchColumn() === 0) {
            return ['ok' => false, 'message' => 'The selected branch does not belong to this company.'];
        }
    }
    if ($departmentId > 0) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM departments WHERE department_id = ? AND company_id = ? AND is_active = 1');
        $stmt->execute([$departmentId, $companyId]);
        if ((int) $stmt->fetchColumn() === 0) {
            return ['ok' => false, 'message' => 'The selected department does not belong to this company or is inactive.'];
        }
    }

    // Never allow an administrator to lock themselves out or deactivate the last admin.
    $actor = auth_user();
    if ($isActive === 0) {
        if ((int) ($actor['user_id'] ?? 0) === $userId) {
            return ['ok' => false, 'message' => 'You cannot deactivate your own account.'];
        }
        if ($target['role'] === 'admin') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND role = 'admin' AND is_active = 1 AND user_id <> ?");
            $stmt->execute([$companyId, $userId]);
            if ((int) $stmt->fetchColumn() === 0) {
                return ['ok' => false, 'message' => 'This is the last active administrator for the company.'];
            }
        }
    }
    if ($target['role'] === 'admin' && $role !== 'admin') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND role = 'admin' AND is_active = 1");
        $stmt->execute([$companyId]);
        if ((int) $stmt->fetchColumn() <= 1) {
            return ['ok' => false, 'message' => 'The last administrator cannot have their role changed.'];
        }
    }

    $stmt = $pdo->prepare(
        'UPDATE users SET email = ?, full_name = ?, role = ?, branch_id = ?, department_id = ?, is_active = ?, updated_at = NOW()
         WHERE user_id = ? AND company_id = ?'
    );
    $stmt->execute([
        $email, $fullName !== '' ? $fullName : $target['username'], $role,
        $branchId > 0 ? $branchId : null, $departmentId > 0 ? $departmentId : null, $isActive, $userId, $companyId,
    ]);

    adminApplyUserBranches($pdo, $userId, $companyId, (array) ($data['branch_access'] ?? []));
    adminSaveUserPermissions($pdo, $userId, $companyId, (array) ($data['permissions'] ?? []));
    users_audit($pdo, 'UPDATE', $userId, "Updated user '{$target['username']}' (role '{$role}', active " . $isActive . ')');

    return ['ok' => true, 'message' => 'User updated successfully.'];
}

function adminResetUserPassword(PDO $pdo, int $userId, int $companyId, string $newPassword): array
{
    if (strlen($newPassword) < 8) {
        return ['ok' => false, 'message' => 'Password must be at least 8 characters.'];
    }
    $target = adminGetUser($pdo, $userId, $companyId);
    if (!$target) {
        return ['ok' => false, 'message' => 'User not found in your company.'];
    }

    $stmt = $pdo->prepare(
        'UPDATE users SET password_hash = ?, failed_login_attempts = 0, lock_until = NULL, password_changed_at = NOW(), updated_at = NOW()
         WHERE user_id = ? AND company_id = ?'
    );
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId, $companyId]);
    users_audit($pdo, 'UPDATE', $userId, "Password reset for '{$target['username']}'");

    return ['ok' => true, 'message' => 'Password reset successfully.'];
}

function adminApplyUserBranches(PDO $pdo, int $userId, int $companyId, array $branchIds): void
{
    if (!tables_exist($pdo, ['user_branch_access'])) {
        return;
    }
    $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));
    $pdo->prepare('DELETE FROM user_branch_access WHERE user_id = ?')->execute([$userId]);
    foreach ($branchIds as $branchId) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE branch_id = ? AND company_id = ?');
        $stmt->execute([$branchId, $companyId]);
        if ((int) $stmt->fetchColumn() > 0) {
            $pdo->prepare('INSERT INTO user_branch_access (user_id, branch_id) VALUES (?, ?)')->execute([$userId, $branchId]);
        }
    }
}

function adminGetUserBranchIds(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare('SELECT branch_id FROM user_branch_access WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

function adminSaveUserPermissions(PDO $pdo, int $userId, int $companyId, array $permissionNames): void
{
    $permissionNames = array_values(array_unique(array_filter(array_map(
        static fn($n) => trim((string) $n),
        $permissionNames
    ))));

    $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$userId]);
    if ($permissionNames === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($permissionNames), '?'));
    $stmt = $pdo->prepare("SELECT name, permission_id FROM permissions WHERE name IN ($placeholders)");
    $stmt->execute($permissionNames);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $perm) {
        $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)')
            ->execute([$userId, (int) $perm['permission_id']]);
    }
}

function adminListPermissions(PDO $pdo): array
{
    return $pdo->query('SELECT permission_id, name, description FROM permissions ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}

function adminListBranches(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare('SELECT branch_id, name FROM branches WHERE company_id = ? AND is_active = 1 ORDER BY name');
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------- roles

function adminCreateRole(PDO $pdo, array $data): array
{
    $key = strtolower(trim((string) ($data['role_key'] ?? '')));
    $name = trim((string) ($data['name'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));

    if (!preg_match('/^[a-z][a-z0-9_]{2,49}$/', $key)) {
        return ['ok' => false, 'message' => 'Role key must be 3-50 characters: lowercase letters, numbers or underscores.'];
    }
    if ($name === '') {
        return ['ok' => false, 'message' => 'Role name is required.'];
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE role_key = ?');
    $stmt->execute([$key]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'That role key already exists.'];
    }

    $pdo->prepare('INSERT INTO roles (role_key, name, description, is_system, is_active) VALUES (?, ?, ?, 0, 1)')
        ->execute([$key, $name, $description]);
    users_audit($pdo, 'CREATE', 0, "Created role '{$key}' ({$name})");

    return ['ok' => true, 'message' => 'Role created. Assign its permissions in the matrix below.'];
}

function adminUpdateRole(PDO $pdo, string $roleKey, array $data): array
{
    $stmt = $pdo->prepare('SELECT * FROM roles WHERE role_key = ?');
    $stmt->execute([$roleKey]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$role) {
        return ['ok' => false, 'message' => 'Role not found.'];
    }

    $name = trim((string) ($data['name'] ?? $role['name']));
    $description = trim((string) ($data['description'] ?? $role['description']));
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if ($name === '') {
        return ['ok' => false, 'message' => 'Role name is required.'];
    }
    if ((int) $role['is_system'] === 1 && $isActive === 0) {
        return ['ok' => false, 'message' => 'System roles cannot be deactivated.'];
    }

    $pdo->prepare('UPDATE roles SET name = ?, description = ?, is_active = ?, updated_at = NOW() WHERE role_key = ?')
        ->execute([$name, $description, $isActive, $roleKey]);
    users_audit($pdo, 'UPDATE', 0, "Updated role '{$roleKey}'");

    return ['ok' => true, 'message' => 'Role updated.'];
}

function adminDeleteRole(PDO $pdo, string $roleKey): array
{
    $stmt = $pdo->prepare('SELECT is_system FROM roles WHERE role_key = ?');
    $stmt->execute([$roleKey]);
    $isSystem = $stmt->fetchColumn();
    if ($isSystem === false) {
        return ['ok' => false, 'message' => 'Role not found.'];
    }
    if ((int) $isSystem === 1) {
        return ['ok' => false, 'message' => 'System roles cannot be deleted. Deactivate them instead.'];
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
    $stmt->execute([$roleKey]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'Users are still assigned to this role. Reassign them first.'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM role_permissions WHERE role = ?')->execute([$roleKey]);
        $pdo->prepare('DELETE FROM roles WHERE role_key = ?')->execute([$roleKey]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Role could not be deleted.'];
    }

    users_audit($pdo, 'DELETE', 0, "Deleted role '{$roleKey}'");
    return ['ok' => true, 'message' => 'Role deleted.'];
}

function adminGetRolePermissionNames(PDO $pdo, string $roleKey): array
{
    $stmt = $pdo->prepare('SELECT permission FROM role_permissions WHERE role = ?');
    $stmt->execute([$roleKey]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function adminSaveRolePermissions(PDO $pdo, string $roleKey, array $permissionNames): array
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE role_key = ?');
    $stmt->execute([$roleKey]);
    if ((int) $stmt->fetchColumn() === 0) {
        return ['ok' => false, 'message' => 'Role not found.'];
    }
    if ($roleKey === 'admin') {
        return ['ok' => false, 'message' => 'Administrators bypass permission checks; their grants cannot be edited.'];
    }

    $permissionNames = array_values(array_unique(array_filter(array_map(
        static fn($n) => trim((string) $n),
        $permissionNames
    ))));

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM role_permissions WHERE role = ?')->execute([$roleKey]);
        foreach ($permissionNames as $name) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM permissions WHERE name = ?');
            $stmt->execute([$name]);
            if ((int) $stmt->fetchColumn() > 0) {
                $pdo->prepare('INSERT INTO role_permissions (role, permission) VALUES (?, ?)')->execute([$roleKey, $name]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Role permissions could not be saved.'];
    }

    users_audit($pdo, 'UPDATE', 0, "Set " . count($permissionNames) . " permissions for role '{$roleKey}'");
    return ['ok' => true, 'message' => 'Role permissions saved.'];
}
