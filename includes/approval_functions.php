<?php
/**
 * Module 10: Approval Workflow Functions
 * Workflows, workflow steps, requests and actions backed by the canonical
 * approval_workflows / approval_workflow_steps / approval_requests /
 * approval_actions tables.
 */

defined('APPROVAL_MODULE') || define('APPROVAL_MODULE', 'approval');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/database.php';

const APPROVAL_ACTIONS = ['approve', 'reject', 'review'];
const APPROVAL_STATUSES = ['pending', 'approved', 'rejected', 'expired'];

// ============================================================
// Workflows
// ============================================================

function getApprovalWorkflowsByCompany(PDO $pdo, int $companyId = 1, bool $activeOnly = false): array
{
    $cond = $activeOnly ? 'AND w.is_active = 1' : '';
    $stmt = $pdo->prepare("
        SELECT w.*,
               (SELECT COUNT(DISTINCT s.step_number)
                  FROM approval_workflow_steps s WHERE s.workflow_id = w.workflow_id) AS step_count,
               (SELECT COUNT(*) FROM approval_requests r
                 WHERE r.workflow_id = w.workflow_id AND r.status = 'pending') AS request_count
        FROM approval_workflows w
        WHERE w.company_id = ? $cond
        ORDER BY w.sort_order, w.name, w.workflow_id
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getWorkflowById(PDO $pdo, int $workflowId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM approval_workflows WHERE workflow_id = ?');
    $stmt->execute([$workflowId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getWorkflowSteps(PDO $pdo, int $workflowId): array
{
    $stmt = $pdo->prepare("
        SELECT MIN(s.step_id) AS step_id,
               s.workflow_id,
               s.step_number,
               GROUP_CONCAT(DISTINCT s.role_required ORDER BY s.role_required SEPARATOR ', ') AS role_required,
               MIN(s.action) AS action,
               MAX(s.notes) AS notes,
               MIN(s.sort_order) AS sort_order
        FROM approval_workflow_steps s
        WHERE s.workflow_id = ?
        GROUP BY s.workflow_id, s.step_number
        ORDER BY s.step_number
    ");
    $stmt->execute([$workflowId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function createApprovalWorkflow(PDO $pdo, array $data): int
{
    $companyId = (int) ($data['company_id'] ?? 1);
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        return 0;
    }

    $stmt = $pdo->prepare('
        INSERT INTO approval_workflows (company_id, name, description, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $companyId,
        substr($name, 0, 80),
        trim((string) ($data['description'] ?? '')) ?: null,
        array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : 1,
        (int) ($data['sort_order'] ?? 0),
    ]);

    return (int) $pdo->lastInsertId();
}

function updateApprovalWorkflow(PDO $pdo, int $workflowId, array $data): bool
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        return false;
    }

    $stmt = $pdo->prepare('
        UPDATE approval_workflows
           SET name = ?, description = ?, is_active = ?, sort_order = ?, updated_at = NOW()
         WHERE workflow_id = ?
    ');
    return $stmt->execute([
        substr($name, 0, 80),
        trim((string) ($data['description'] ?? '')) ?: null,
        array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : 1,
        (int) ($data['sort_order'] ?? 0),
        $workflowId,
    ]);
}

function toggleWorkflowStatus(PDO $pdo, int $workflowId): bool
{
    $stmt = $pdo->prepare(
        'UPDATE approval_workflows
            SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END, updated_at = NOW()
          WHERE workflow_id = ?'
    );
    return $stmt->execute([$workflowId]);
}

function getWorkflowRequiredApprovals(PDO $pdo, int $workflowId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT step_number)
        FROM approval_workflow_steps
        WHERE workflow_id = ? AND action = 'approve'
    ");
    $stmt->execute([$workflowId]);
    return max(1, (int) $stmt->fetchColumn());
}

// ============================================================
// Workflow steps (per-row CRUD on approval_workflow_steps)
// ============================================================

/**
 * Raw step rows for one workflow, in order.
 * getWorkflowSteps() collapses steps that share a step_number for display;
 * the editor needs one row per record, so this keeps them ungrouped.
 */
function getWorkflowStepRows(PDO $pdo, int $workflowId): array
{
    $stmt = $pdo->prepare("
        SELECT s.step_id, s.workflow_id, s.step_number, s.role_required, s.action,
               s.notes, s.sort_order, s.created_at, s.updated_at
        FROM approval_workflow_steps s
        WHERE s.workflow_id = ?
        ORDER BY s.step_number, s.sort_order, s.step_id
    ");
    $stmt->execute([$workflowId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every step row of every workflow owned by a company, keyed by workflow_id.
 * Lets the workflow list render steps under each workflow without an N+1 loop.
 */
function getWorkflowStepRowsByCompany(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare("
        SELECT s.step_id, s.workflow_id, s.step_number, s.role_required, s.action,
               s.notes, s.sort_order
        FROM approval_workflow_steps s
        INNER JOIN approval_workflows w ON w.workflow_id = s.workflow_id
        WHERE w.company_id = ?
        ORDER BY s.workflow_id, s.step_number, s.sort_order, s.step_id
    ");
    $stmt->execute([$companyId]);

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $grouped[(int) $row['workflow_id']][] = $row;
    }
    return $grouped;
}

function getWorkflowStepById(PDO $pdo, int $stepId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM approval_workflow_steps WHERE step_id = ?');
    $stmt->execute([$stepId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Pending requests outstanding on a workflow. Steps may only be removed while
 * this is zero, so an in-flight approval chain is never mutated.
 */
function countPendingWorkflowRequests(PDO $pdo, int $workflowId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM approval_requests
        WHERE workflow_id = ? AND status = 'pending'
    ");
    $stmt->execute([$workflowId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Validate a step payload. Returns ['ok' => bool, 'error' => string, 'values' => array].
 */
function validateWorkflowStep(array $data, array $allowedRoles): array
{
    $stepNumber = (int) ($data['step_number'] ?? 0);
    $role = substr(trim((string) ($data['role_required'] ?? '')), 0, 50);
    $action = trim((string) ($data['action'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));

    if ($stepNumber < 1 || $stepNumber > 65535) {
        return ['ok' => false, 'error' => 'Step number must be between 1 and 65535.', 'values' => []];
    }
    if ($role === '' || !in_array($role, $allowedRoles, true)) {
        return ['ok' => false, 'error' => 'Choose a valid role for this step.', 'values' => []];
    }
    if (!in_array($action, APPROVAL_ACTIONS, true)) {
        return ['ok' => false, 'error' => 'Choose a valid step action (approve, reject or review).', 'values' => []];
    }

    return ['ok' => true, 'error' => '', 'values' => [
        'step_number' => $stepNumber,
        'role_required' => $role,
        'action' => $action,
        'notes' => $notes !== '' ? $notes : null,
    ]];
}

/**
 * sort_order is kept in sync with step_number so both ordering columns agree.
 */
function createWorkflowStep(PDO $pdo, int $workflowId, array $values): int
{
    if ($workflowId <= 0 || !isset($values['step_number'], $values['role_required'], $values['action'])) {
        return 0;
    }

    $stmt = $pdo->prepare("
        INSERT INTO approval_workflow_steps
            (workflow_id, step_number, role_required, action, notes, sort_order, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $workflowId,
        (int) $values['step_number'],
        (string) $values['role_required'],
        (string) $values['action'],
        $values['notes'] ?? null,
        (int) $values['step_number'],
    ]);

    return (int) $pdo->lastInsertId();
}

function updateWorkflowStep(PDO $pdo, int $stepId, array $values): bool
{
    if ($stepId <= 0 || !isset($values['step_number'], $values['role_required'], $values['action'])) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE approval_workflow_steps
           SET step_number = ?, role_required = ?, action = ?, notes = ?, sort_order = ?, updated_at = NOW()
         WHERE step_id = ?
    ");
    return $stmt->execute([
        (int) $values['step_number'],
        (string) $values['role_required'],
        (string) $values['action'],
        $values['notes'] ?? null,
        (int) $values['step_number'],
        $stepId,
    ]);
}

function deleteWorkflowStep(PDO $pdo, int $stepId): bool
{
    if ($stepId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM approval_workflow_steps WHERE step_id = ?');
    return $stmt->execute([$stepId]);
}

// ============================================================
// Requests
// ============================================================

function createApprovalRequest(PDO $pdo, array $data): int
{
    $companyId = (int) ($data['company_id'] ?? 1);
    $workflowId = (int) ($data['workflow_id'] ?? 0);
    $requesterId = (int) ($data['requester_id'] ?? ($_SESSION['user_id'] ?? 0));
    $entityType = trim((string) ($data['entity_type'] ?? ''));
    $entityId = (int) ($data['entity_id'] ?? 0);

    if ($workflowId <= 0 || $requesterId <= 0 || $entityType === '' || $entityId <= 0) {
        return 0;
    }

    $workflow = getWorkflowById($pdo, $workflowId);
    if (!$workflow || (int) $workflow['company_id'] !== $companyId || (int) $workflow['is_active'] !== 1) {
        return 0;
    }

    $required = getWorkflowRequiredApprovals($pdo, $workflowId);

    $stmt = $pdo->prepare("
        INSERT INTO approval_requests
            (workflow_id, company_id, requester_id, entity_type, entity_id,
             status, submitted_date, approval_count, required_count, notes)
        VALUES (?, ?, ?, ?, ?, 'pending', NOW(), 0, ?, ?)
    ");
    $stmt->execute([
        $workflowId,
        $companyId,
        $requesterId,
        substr($entityType, 0, 50),
        $entityId,
        $required,
        trim((string) ($data['notes'] ?? '')) ?: null,
    ]);

    return (int) $pdo->lastInsertId();
}

function getApprovalRequestsByStatus(PDO $pdo, int $companyId = 1, ?string $status = 'pending'): array
{
    $cond = $status !== null && $status !== '' ? 'AND r.status = ?' : '';
    $params = $status !== null && $status !== '' ? [$status] : [];

    $stmt = $pdo->prepare("
        SELECT r.*, w.name AS workflow_name, rq.full_name AS requester_name,
               (SELECT COUNT(*) FROM approval_actions a WHERE a.request_id = r.request_id) AS action_count
        FROM approval_requests r
        LEFT JOIN approval_workflows w ON r.workflow_id = w.workflow_id
        LEFT JOIN users rq ON r.requester_id = rq.user_id
        WHERE r.company_id = ? $cond
        ORDER BY r.submitted_date DESC, r.request_id DESC
    ");
    array_unshift($params, $companyId);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getApprovalRequestById(PDO $pdo, int $requestId): ?array
{
    $stmt = $pdo->prepare("
        SELECT r.*, w.name AS workflow_name, rq.full_name AS requester_name
        FROM approval_requests r
        LEFT JOIN approval_workflows w ON r.workflow_id = w.workflow_id
        LEFT JOIN users rq ON r.requester_id = rq.user_id
        WHERE r.request_id = ?
    ");
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getApprovalActions(PDO $pdo, int $requestId): array
{
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name AS user_name
        FROM approval_actions a
        LEFT JOIN users u ON a.user_id = u.user_id
        WHERE a.request_id = ?
        ORDER BY a.action_date, a.action_id
    ");
    $stmt->execute([$requestId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getApprovalRequestCounts(PDO $pdo, int $companyId = 1): array
{
    $counts = array_fill_keys(APPROVAL_STATUSES, 0);
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS total FROM approval_requests WHERE company_id = ? GROUP BY status');
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int) $row['total'];
        }
    }
    return $counts;
}

// ============================================================
// Actions
// ============================================================

/**
 * Record an approval action and advance the request status.
 */
function addApprovalAction(PDO $pdo, int $requestId, int $userId, string $action, string $comments = ''): bool
{
    if (!in_array($action, APPROVAL_ACTIONS, true) || $requestId <= 0 || $userId <= 0) {
        return false;
    }

    $request = getApprovalRequestById($pdo, $requestId);
    if (!$request || $request['status'] !== 'pending') {
        return false;
    }

    // Prevent self-approval by default; settings 'allow_self_approval' = '1' opts in.
    if ((int) ($request['requester_id'] ?? 0) === $userId) {
        $allowSelf = getCompanySetting($pdo, 'allow_self_approval', '0') === '1';
        if (!$allowSelf) {
            return false;
        }
    }

    if ($action === 'approve') {
        $check = $pdo->prepare(
            "SELECT 1 FROM approval_actions WHERE request_id = ? AND user_id = ? AND action = 'approve' LIMIT 1"
        );
        $check->execute([$requestId, $userId]);
        if ($check->fetchColumn()) {
            return false;
        }
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
        $ipAddress = null;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO approval_actions (request_id, user_id, action, comments, action_date, ip_address)
             VALUES (?, ?, ?, ?, NOW(), ?)'
        );
        $stmt->execute([
            $requestId,
            $userId,
            $action,
            trim($comments) ?: null,
            $ipAddress,
        ]);

        if ($action === 'reject') {
            $pdo->prepare(
                "UPDATE approval_requests
                    SET status = 'rejected', completed_date = NOW(), updated_at = NOW()
                  WHERE request_id = ? AND status = 'pending'"
            )->execute([$requestId]);
        } elseif ($action === 'approve') {
            $countStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM approval_actions WHERE request_id = ? AND action = 'approve'"
            );
            $countStmt->execute([$requestId]);
            $approvalCount = (int) $countStmt->fetchColumn();
            $required = max(1, (int) $request['required_count']);

            if ($approvalCount >= $required) {
                $pdo->prepare(
                    "UPDATE approval_requests
                        SET approval_count = ?, status = 'approved', completed_date = NOW(), updated_at = NOW()
                      WHERE request_id = ? AND status = 'pending'"
                )->execute([$approvalCount, $requestId]);
            } else {
                $pdo->prepare(
                    'UPDATE approval_requests SET approval_count = ?, updated_at = NOW() WHERE request_id = ?'
                )->execute([$approvalCount, $requestId]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function userCanApprove(PDO $pdo, int $requestId, int $userId): bool
{
    $request = getApprovalRequestById($pdo, $requestId);
    if (!$request || $request['status'] !== 'pending') {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM approval_actions WHERE request_id = ? AND user_id = ? AND action = 'approve' LIMIT 1"
    );
    $stmt->execute([$requestId, $userId]);
    return !$stmt->fetchColumn();
}
