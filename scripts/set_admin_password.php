<?php
/**
 * Set a user's password from the command line.
 *
 * Usage:
 *   php scripts/set_admin_password.php <username> <new-password>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php scripts/set_admin_password.php <username> <new-password>\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$pdo = get_db_connection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Unable to connect to the database.\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1');
$stmt->execute([$username, $username]);
$userId = $stmt->fetchColumn();

if (!$userId) {
    fwrite(STDERR, "User not found: $username\n");
    exit(1);
}

$stmt = $pdo->prepare(
    'UPDATE users SET password_hash = ?, failed_login_attempts = 0, lock_until = NULL, password_changed_at = NOW() WHERE user_id = ?'
);
$stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);

echo "Password updated for user #$userId.\n";
