<?php
/**
 * Legacy authentication helper library.
 * Authentication logic now lives in includes/auth_service.php.
 * This file keeps logAuthEvent() for older modules and must not execute on include.
 */

defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/auth_service.php';

if (!function_exists('logAuthEvent')) {
    /**
     * Map legacy auth event names onto the audit_log action enum.
     */
    function logAuthEvent(PDO $pdo, string $event, ?int $userId = null, string $details = ''): void
    {
        $map = [
            'login_success' => 'LOGIN',
            'login_failed' => 'LOGIN',
            'logout' => 'LOGOUT',
            'password_change' => 'UPDATE',
        ];

        auth_audit($pdo, $map[$event] ?? 'UPDATE', 'users', $userId, $details, $userId);
    }
}
