<?php
/**
 * Application bootstrap. Every web entry point must require this file first.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

defined('APP_NAME') || define('APP_NAME', 'Inventory Management System');
defined('APP_VERSION') || define('APP_VERSION', '2.0.2');

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/logger.php';
require_once APP_ROOT . '/includes/auth_service.php';
require_once APP_ROOT . '/includes/auth_helpers.php';

app_install_error_handlers();

defined('SESSION_LIFETIME_SECONDS') || define('SESSION_LIFETIME_SECONDS', (int) app_env('SESSION_LIFETIME', 120) * 60);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $lifetime = (int) app_env('SESSION_LIFETIME', 120) * 60;
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $base = app_base_path();

    // Browsers send the longest-path cookie first, but PHP keeps the last
    // duplicate. A legacy path=/ cookie would otherwise shadow this session.
    $rawCookie = (string) ($_SERVER['HTTP_COOKIE'] ?? '');
    if ($base !== '' && substr_count($rawCookie, 'inventory_session=') > 1) {
        if (preg_match('/(?:^|;\s*)inventory_session=([^;]+)/', $rawCookie, $match)) {
            $_COOKIE['inventory_session'] = $match[1];
        }
        foreach (['/', $base] as $legacyPath) {
            setcookie('inventory_session', '', [
                'expires' => time() - 3600,
                'path' => $legacyPath,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    session_name('inventory_session');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => $base === '' ? '/' : $base . '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    session_start();
}

csrf_token();
