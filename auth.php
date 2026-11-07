<?php
/**
 * Authentication controller.
 *
 * Routes:
 *   GET  /auth                         Redirects to /login
 *   GET  /login                        Canonical login form (login.php)
 *   POST /auth?action=logout           Process logout
 *   GET  /auth?action=change_password  Change password form
 *   POST /auth?action=change_password  Change password
 */

require_once __DIR__ . '/bootstrap.php';

$action = $_POST['action'] ?? $_GET['action'] ?? 'login';
$pdo = get_db_connection();

if ($action === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_post_csrf();
    }
    auth_logout($pdo);
    redirect('/login');
}

if ($action === 'login') {
    if (auth_check()) {
        redirect('/dashboard');
    }
    redirect('/login');
}

if ($action === 'forgot_password') {
    flash('auth_message', 'Password resets are handled by an administrator. Please contact your administrator.');
    redirect('/login');
}

if ($action === 'reset_password') {
    flash('auth_error', 'Password reset links are not enabled. Please contact your administrator.');
    redirect('/login');
}

if ($action === 'change_password') {
    auth_require();

    if (!$pdo instanceof PDO) {
        http_response_code(503);
        exit('The database is unavailable. Please contact your administrator.');
    }

    $message = null;
    $error = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $error = 'Your session expired. Please try again.';
        } else {
            $error = auth_change_password(
                $pdo,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['confirm_password'] ?? '')
            );

            if ($error === null) {
                flash('auth_message', 'Your password has been updated.');
                redirect('/dashboard');
            }
        }
    }

    require APP_ROOT . '/views/change_password.html';
    exit;
}

redirect('/login');
