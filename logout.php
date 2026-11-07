<?php
/**
 * Legacy sign-out entry point. Ends the session through the auth service and
 * returns to the sign-in page.
 */

require_once __DIR__ . '/bootstrap.php';

auth_logout(get_db_connection());
redirect('/login');
