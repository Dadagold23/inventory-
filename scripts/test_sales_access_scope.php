<?php

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/auth_helpers.php';

$cases = [
    ['staff', 42, false, false],
    ['cashier', 42, false, false],
    ['manager', null, true, false],
    ['auditor', null, true, true],
    ['admin', null, true, true],
];

$failures = [];
foreach ($cases as [$role, $expectedScope, $expectedCrossUser, $expectedCompanyDashboard]) {
    $_SESSION['user_id'] = 42;
    $_SESSION['username'] = 'scope-test';
    $_SESSION['user_role'] = $role;

    $scope = auth_sales_scope_user_id();
    if ($scope !== $expectedScope) {
        $failures[] = "$role sales scope was " . var_export($scope, true);
    }
    if (auth_can_view_cross_user_records() !== $expectedCrossUser) {
        $failures[] = "$role cross-user access was incorrect";
    }
    if (auth_can_view_company_sales_dashboard() !== $expectedCompanyDashboard) {
        $failures[] = "$role dashboard scope was incorrect";
    }
}

$_SESSION = [];
if (auth_sales_scope_user_id() !== null) {
    $failures[] = 'unauthenticated sales scope was not empty';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "PASS sales access scopes for staff, cashier, manager, auditor and admin\n";
