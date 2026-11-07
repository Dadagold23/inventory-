<?php
/**
 * Module 18: Audit Functions
 * Read/write helpers for the canonical audit_log table.
 */

defined('AUDIT_MODULE') || define('AUDIT_MODULE', 'audit');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/database.php';

const AUDIT_ACTIONS = ['CREATE', 'READ', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'EXPORT', 'IMPORT', 'PRINT'];

function auditNormalizeAction(string $action): string
{
    $normalized = strtoupper(trim($action));
    return in_array($normalized, AUDIT_ACTIONS, true) ? $normalized : 'UPDATE';
}

/**
 * Write an audit log entry.
 */
function auditLog(
    PDO $pdo,
    string $action,
    string $entityType,
    int $entityId = 0,
    string $oldValues = '',
    string $newValues = '',
    ?int $userId = null,
    int $companyId = 1
): int {
    $userId = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO audit_log
            (company_id, user_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $companyId,
        $userId,
        auditNormalizeAction($action),
        substr(trim($entityType), 0, 50),
        $entityId > 0 ? $entityId : null,
        $oldValues !== '' ? $oldValues : null,
        $newValues !== '' ? $newValues : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function getAuditLogsByCompany(
    PDO $pdo,
    int $companyId = 1,
    int $limit = 200,
    ?string $action = null,
    ?string $entityType = null,
    ?int $userId = null
): array {
    $conditions = ['al.company_id = ?'];
    $params = [$companyId];

    if ($action !== null && $action !== '') {
        $conditions[] = 'al.action = ?';
        $params[] = auditNormalizeAction($action);
    }
    if ($entityType !== null && $entityType !== '') {
        $conditions[] = 'al.entity_type = ?';
        $params[] = $entityType;
    }
    if ($userId !== null && $userId > 0) {
        $conditions[] = 'al.user_id = ?';
        $params[] = $userId;
    }

    $limit = max(1, min($limit, 1000));
    $sql = 'SELECT al.*, u.full_name AS user_name
              FROM audit_log al
              LEFT JOIN users u ON al.user_id = u.user_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY al.created_at DESC, al.audit_id DESC
             LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAuditLogById(PDO $pdo, int $auditId): ?array
{
    $stmt = $pdo->prepare("
        SELECT al.*, u.full_name AS user_name
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE al.audit_id = ?
    ");
    $stmt->execute([$auditId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getAuditLogsByEntity(PDO $pdo, string $entityType, int $entityId, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT al.*, u.full_name AS user_name
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE al.company_id = ? AND al.entity_type = ? AND al.entity_id = ?
        ORDER BY al.created_at DESC, al.audit_id DESC
    ");
    $stmt->execute([$companyId, $entityType, $entityId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAuditLogsByAction(PDO $pdo, string $action, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT al.*, u.full_name AS user_name
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE al.company_id = ? AND al.action = ?
        ORDER BY al.created_at DESC, al.audit_id DESC
    ");
    $stmt->execute([$companyId, auditNormalizeAction($action)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAuditEntityTypes(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT entity_type FROM audit_log WHERE company_id = ? ORDER BY entity_type'
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getAuditUsers(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.user_id, u.full_name
        FROM audit_log al
        INNER JOIN users u ON al.user_id = u.user_id
        WHERE al.company_id = ?
        ORDER BY u.full_name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
