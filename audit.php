<?php
/**
 * Module 18: Audit Controller
 * Read-only system audit trail with action, entity and user filters.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('AUDIT_MODULE') || define('AUDIT_MODULE', 'audit');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/audit_functions.php';

auth_require();
require_permission('audit', 'view');

$pdo = db();
$companyId = currentCompanyId();

$actionFilter = trim((string) ($_GET['action_filter'] ?? ''));
$entityTypeFilter = trim((string) ($_GET['entity_type_filter'] ?? ''));
$userFilter = (int) ($_GET['user_filter'] ?? 0);
$logId = (int) ($_GET['log_id'] ?? 0);

$auditLogs = getAuditLogsByCompany(
    $pdo,
    $companyId,
    200,
    $actionFilter !== '' ? $actionFilter : null,
    $entityTypeFilter !== '' ? $entityTypeFilter : null,
    $userFilter > 0 ? $userFilter : null
);

$entityTypes = getAuditEntityTypes($pdo, $companyId);
$auditUsers = getAuditUsers($pdo, $companyId);

$selectedLog = [];
if ($logId > 0) {
    $candidate = getAuditLogById($pdo, $logId);
    if ($candidate && (int) $candidate['company_id'] === $companyId) {
        $selectedLog = $candidate;
    }
}

$pageTitle = 'Audit Log';

require APP_ROOT . '/views/audit.html';
