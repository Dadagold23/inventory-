<?php
/**
 * Authentication and session service.
 * Canonical users schema: password_hash, role, is_active, company_id, branch_id.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/database.php';

if (!function_exists('auth_attempt')) {
    /**
     * Attempt a login with username or email.
     *
     * @return array{ok: bool, message: string, user: ?array}
     */
    function auth_attempt(PDO $pdo, string $login, string $password): array
    {
        $login = trim($login);
        if ($login === '' || $password === '') {
            return ['ok' => false, 'message' => 'Please enter your username and password.', 'user' => null];
        }

        $stmt = $pdo->prepare(
            'SELECT user_id, username, email, password_hash, full_name, role, is_active,
                    company_id, branch_id, failed_login_attempts, lock_until
             FROM users
             WHERE username = ? OR email = ?
             LIMIT 1'
        );
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();

        if (!$user) {
            app_log('warning', 'Login failed: unknown account', ['login' => $login]);
            return ['ok' => false, 'message' => 'Invalid username or password.', 'user' => null];
        }

        if (!empty($user['lock_until']) && strtotime((string) $user['lock_until']) > time()) {
            return ['ok' => false, 'message' => 'Account temporarily locked. Try again later.', 'user' => null];
        }

        if ((int) $user['is_active'] !== 1) {
            return ['ok' => false, 'message' => 'This account is inactive. Contact an administrator.', 'user' => null];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $attempts = (int) $user['failed_login_attempts'] + 1;
            if ($attempts >= 5) {
                $pdo->prepare(
                    'UPDATE users SET failed_login_attempts = 0, lock_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE user_id = ?'
                )->execute([$user['user_id']]);
                auth_audit($pdo, 'LOGIN', 'users', (int) $user['user_id'], 'Account locked after repeated failures', (int) $user['user_id']);
                return ['ok' => false, 'message' => 'Too many failed attempts. Account locked for 30 minutes.', 'user' => null];
            }

            $pdo->prepare('UPDATE users SET failed_login_attempts = ? WHERE user_id = ?')
                ->execute([$attempts, $user['user_id']]);
            auth_audit($pdo, 'LOGIN', 'users', (int) $user['user_id'], 'Failed login attempt ' . $attempts, (int) $user['user_id']);
            return ['ok' => false, 'message' => 'Invalid username or password.', 'user' => null];
        }

        $pdo->prepare(
            'UPDATE users SET failed_login_attempts = 0, lock_until = NULL, last_login = NOW(), last_ip_address = ? WHERE user_id = ?'
        )->execute([auth_client_ip(), $user['user_id']]);

        return ['ok' => true, 'message' => 'Signed in successfully.', 'user' => $user];
    }
}

if (!function_exists('auth_start_session')) {
    /**
     * Store an authenticated user in the session.
     */
    function auth_start_session(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id'] = (int) $user['user_id'];
        $_SESSION['username'] = (string) $user['username'];
        $_SESSION['full_name'] = (string) ($user['full_name'] ?? $user['username']);
        $_SESSION['user_role'] = (string) $user['role'];
        $_SESSION['company_id'] = (int) ($user['company_id'] ?? 1);
        $_SESSION['branch_id'] = isset($user['branch_id']) ? (int) $user['branch_id'] : null;
        $_SESSION['login_time'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

if (!function_exists('auth_user')) {
    /**
     * Current authenticated user from the session, or null.
     */
    function auth_user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        return [
            'user_id' => (int) $_SESSION['user_id'],
            'username' => (string) ($_SESSION['username'] ?? ''),
            'full_name' => (string) ($_SESSION['full_name'] ?? ''),
            'role' => (string) ($_SESSION['user_role'] ?? 'staff'),
            'company_id' => (int) ($_SESSION['company_id'] ?? 1),
            'branch_id' => isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null,
        ];
    }
}

if (!function_exists('auth_check')) {
    function auth_check(): bool
    {
        return !empty($_SESSION['user_id']);
    }
}

if (!function_exists('auth_require')) {
    function auth_require(): void
    {
        if (!auth_check()) {
            $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '';
            redirect('/login');
        }

        // Enforce idle timeout: PHP's probabilistic GC alone does not expire sessions.
        $idleLimit = defined('SESSION_LIFETIME_SECONDS') ? SESSION_LIFETIME_SECONDS : 7200;
        $reference = (int) ($_SESSION['last_activity'] ?? ($_SESSION['login_time'] ?? 0));
        if ($reference > 0 && (time() - $reference) > $idleLimit) {
            app_log('info', 'Session expired after inactivity', ['user_id' => (int) ($_SESSION['user_id'] ?? 0)]);
            auth_logout();
            session_start();
            $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '';
            $_SESSION['auth_message'] = 'Your session expired after a period of inactivity. Please sign in again.';
            redirect('/login');
        }

        $_SESSION['last_activity'] = time();
    }
}

if (!function_exists('auth_guest')) {
    function auth_guest(): void
    {
        if (auth_check()) {
            redirect('/dashboard');
        }
    }
}

if (!function_exists('auth_require_role')) {
    /**
     * Require one of the supplied roles; sends 403 otherwise.
     */
    function auth_require_role(array $roles): void
    {
        auth_require();
        $user = auth_user();
        if (!$user || !in_array($user['role'], $roles, true)) {
            http_response_code(403);
            exit('You do not have permission to access this page.');
        }
    }
}

if (!function_exists('auth_company_id')) {
    function auth_company_id(): int
    {
        return (int) ($_SESSION['company_id'] ?? 1);
    }
}

if (!function_exists('auth_branch_id')) {
    function auth_branch_id(): ?int
    {
        return isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;
    }
}

if (!function_exists('auth_logout')) {
    function auth_logout(?PDO $pdo = null): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($pdo instanceof PDO && $userId > 0) {
            auth_audit($pdo, 'LOGOUT', 'users', $userId, 'User signed out', $userId);
        }

        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
        }
    }
}

if (!function_exists('auth_change_password')) {
    /**
     * Change the current user's password. Returns an error message or null on success.
     */
    function auth_change_password(PDO $pdo, string $current, string $new, string $confirm): ?string
    {
        $user = auth_user();
        if (!$user) {
            return 'You must be signed in.';
        }
        if ($current === '' || $new === '' || $confirm === '') {
            return 'All password fields are required.';
        }
        if (strlen($new) < 8) {
            return 'The new password must be at least 8 characters.';
        }
        if ($new !== $confirm) {
            return 'The new password and confirmation do not match.';
        }

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $stmt->execute([$user['user_id']]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($current, (string) $hash)) {
            return 'Your current password is incorrect.';
        }

        $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE user_id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['user_id']]);

        auth_audit($pdo, 'UPDATE', 'users', $user['user_id'], 'Password changed');

        return null;
    }
}

if (!function_exists('auth_audit')) {
    function auth_audit(PDO $pdo, string $action, string $entityType, ?int $entityId, string $details = '', ?int $userId = null): void
    {
        static $available = null;
        if ($available === null) {
            $available = tables_exist($pdo, ['audit_log']);
        }
        if (!$available) {
            return;
        }

        $userId = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $allowed = ['CREATE', 'READ', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'EXPORT', 'IMPORT', 'PRINT'];
        if (!in_array($action, $allowed, true)) {
            $action = 'UPDATE';
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, new_value, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                auth_company_id(),
                $userId,
                $action,
                $entityType,
                $entityId,
                $details !== '' ? $details : null,
                auth_client_ip(),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (PDOException $e) {
            app_log('warning', 'Audit log write failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('auth_client_ip')) {
    function auth_client_ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }
}
