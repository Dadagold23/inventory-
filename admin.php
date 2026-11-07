<?php
/**
 * Administration controller: users, roles and permission grants.
 * Backs includes/user_functions.php with a UI.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/user_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();
$currentUserId = (int) (auth_user()['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $isRoleOp = in_array($postAction, ['create_role', 'update_role', 'delete_role', 'save_role_permissions'], true);
    if ($isRoleOp) {
    } else {
        require_permission('users', match ($postAction) {
            'create_user' => 'create',
            'reset_password' => 'reset_password',
            'delete_user' => 'delete',
            default => 'update',
        });
    }
} else {
    require_permission('users', 'view');
}

$flashType = 'info';

function admin_flash_set(string $message): void
{
    $_SESSION['auth_message'] = $message;
}

$action = $_GET['action'] ?? 'users';

// ============================================================
// POST handlers — users
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_post_csrf();
    $postAction = (string) $_POST['action'];
    $backTarget = match (true) {
        $postAction === 'create_role', $postAction === 'update_role',
        $postAction === 'delete_role', $postAction === 'save_role_permissions' => '/admin?action=roles',
        default => '/admin?action=users',
    };

    if ($postAction === 'create_user') {
        $result = adminCreateUser($pdo, $companyId, [
            'username' => $_POST['username'] ?? '',
            'email' => $_POST['email'] ?? '',
            'full_name' => $_POST['full_name'] ?? '',
            'role' => $_POST['role'] ?? 'staff',
            'password' => (string) ($_POST['password'] ?? ''),
            'branch_id' => (int) ($_POST['branch_id'] ?? 0),
            'branch_access' => (array) ($_POST['branch_access'] ?? []),
            'permissions' => (array) ($_POST['permissions'] ?? []),
        ]);
        admin_flash_set($result['message']);
        redirect($backTarget);
    }

    if ($postAction === 'update_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $existing = adminGetUser($pdo, $userId, $companyId);
        $result = adminUpdateUser($pdo, $userId, $companyId, [
            'email' => $_POST['email'] ?? '',
            'full_name' => $_POST['full_name'] ?? '',
            'role' => $_POST['role'] ?? ($existing['role'] ?? 'staff'),
            'branch_id' => (int) ($_POST['branch_id'] ?? 0),
            'is_active' => !empty($_POST['is_active']),
            'branch_access' => (array) ($_POST['branch_access'] ?? []),
            'permissions' => array_key_exists('permissions', $_POST) ? (array) $_POST['permissions'] : (array) ($_POST['existing_permissions'] ?? []),
        ]);
        admin_flash_set($result['message']);
        redirect($backTarget);
    }

    if ($postAction === 'reset_password') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $result = adminResetUserPassword($pdo, $userId, $companyId, (string) ($_POST['new_password'] ?? ''));
        admin_flash_set($result['message']);
        redirect($backTarget);
    }

    if ($postAction === 'delete_user') {
        $userId = (int) ($_POST['delete_id'] ?? 0);
        if ($userId === $currentUserId) {
            admin_flash_set('You cannot deactivate your own account.');
            redirect($backTarget);
        }
        $target = adminGetUser($pdo, $userId, $companyId);
        if (!$target) {
            admin_flash_set('User not found in your company.');
            redirect($backTarget);
        }
        if ($target['role'] === 'admin') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND role = 'admin' AND is_active = 1 AND user_id <> ?");
            $stmt->execute([$companyId, $userId]);
            if ((int) $stmt->fetchColumn() === 0) {
                admin_flash_set('This is the last active administrator for the company.');
                redirect($backTarget);
            }
        }
        $pdo->prepare('UPDATE users SET is_active = 0, updated_at = NOW() WHERE user_id = ? AND company_id = ?')
            ->execute([$userId, $companyId]);
        users_audit($pdo, 'DELETE', $userId, "Deactivated user '{$target['username']}'");
        admin_flash_set('User deactivated. Their records are preserved for audit.');
        redirect($backTarget);
    }

    // ============================================================
    // POST handlers — roles
    // ============================================================

    if ($postAction === 'create_role') {
        $result = adminCreateRole($pdo, [
            'role_key' => $_POST['role_key'] ?? '',
            'name' => $_POST['name'] ?? '',
            'description' => $_POST['description'] ?? '',
        ]);
        admin_flash_set($result['message']);
        redirect('/admin?action=roles');
    }

    if ($postAction === 'update_role') {
        $roleKey = (string) ($_POST['role_key'] ?? '');
        $result = adminUpdateRole($pdo, $roleKey, [
            'name' => $_POST['name'] ?? '',
            'description' => $_POST['description'] ?? '',
            'is_active' => !empty($_POST['is_active']),
        ]);
        admin_flash_set($result['message']);
        redirect('/admin?action=roles');
    }

    if ($postAction === 'delete_role') {
        $result = adminDeleteRole($pdo, (string) ($_POST['role_key'] ?? ''));
        admin_flash_set($result['message']);
        redirect('/admin?action=roles');
    }

    if ($postAction === 'save_role_permissions') {
        $roleKey = (string) ($_POST['role_key'] ?? '');
        $result = adminSaveRolePermissions($pdo, $roleKey, (array) ($_POST['permissions'] ?? []));
        admin_flash_set($result['message']);
        redirect('/admin?action=roles#matrix-' . rawurlencode($roleKey));
    }
}

// ============================================================
// Data for views
// ============================================================

$users = adminListUsers($pdo, $companyId);
$roles = getAllRoles($pdo, false);
$allPermissions = adminListPermissions($pdo);
$branchOptions = adminListBranches($pdo, $companyId);

$editUserId = (int) ($_GET['edit'] ?? 0);
$editUser = $editUserId > 0 ? adminGetUser($pdo, $editUserId, $companyId) : null;
$editUserBranchIds = $editUser ? adminGetUserBranchIds($pdo, (int) $editUser['user_id']) : [];
$editUserPerms = [];
if ($editUser) {
    $stmt = $pdo->prepare(
        'SELECT p.name FROM user_permissions up
         JOIN permissions p ON p.permission_id = up.permission_id
         WHERE up.user_id = ?'
    );
    $stmt->execute([(int) $editUser['user_id']]);
    $editUserPerms = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$rolePermMap = [];
foreach ($roles as $roleRow) {
    $rolePermMap[(string) $roleRow['role_key']] = array_flip(adminGetRolePermissionNames($pdo, (string) $roleRow['role_key']));
}

$pageTitle = 'User & Role Administration';
$viewFile = 'views/admin.html';

require_once APP_ROOT . '/views/' . basename($viewFile);
