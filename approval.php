<?php
/**
 * Module 10: Approval Workflow Controller
 * Workflow definitions, approval requests and approve/reject actions.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('APPROVAL_MODULE') || define('APPROVAL_MODULE', 'approval');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/approval_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $approvalWriteAction = match ($postAction) {
        'save_workflow' => (int) ($_POST['workflow_id'] ?? 0) > 0 ? 'update' : 'create',
        'toggle_workflow' => 'update',
        // Workflow steps use the same gate as workflow header updates.
        'save_step', 'delete_step' => 'update',
        'submit_request' => 'create',
        'approve_request', 'reject_request' => 'approve',
        default => 'update',
    };
    require_permission('approvals', $approvalWriteAction);
} else {
    require_permission('approvals', 'view');
}

$view = (string) ($_GET['action'] ?? 'workflows');
$viewMap = [
    'list' => 'workflows',
    'manage_workflows' => 'workflows',
    'requests' => 'requests',
    'manage_requests' => 'requests',
    'create_request' => 'requests',
];
if (isset($viewMap[$view])) {
    $view = $viewMap[$view];
}
if (!in_array($view, ['workflows', 'requests'], true)) {
    $view = 'workflows';
}

$workflowId = (int) ($_GET['workflow_id'] ?? 0);
$stepId = (int) ($_GET['step_id'] ?? 0);
$requestId = (int) ($_GET['request_id'] ?? 0);
$statusFilter = (string) ($_GET['status'] ?? 'pending');
if ($statusFilter !== 'all' && !in_array($statusFilter, APPROVAL_STATUSES, true)) {
    $statusFilter = 'pending';
}

// Roles selectable in the workflow step editor (also used to validate POSTs).
$allRoles = getAllRoles($pdo);
$allowedRoleKeys = array_column($allRoles, 'role_key');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'save_workflow') {
        $targetId = (int) ($_POST['workflow_id'] ?? 0);
        $payload = [
            'company_id' => $companyId,
            'name' => trim((string) ($_POST['workflow_name'] ?? '')),
            'description' => trim((string) ($_POST['workflow_description'] ?? '')),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];

        if ($payload['name'] === '') {
            $_SESSION['auth_message'] = 'Workflow name is required.';
        } elseif ($targetId > 0) {
            $existing = getWorkflowById($pdo, $targetId);
            if (!$existing || (int) $existing['company_id'] !== $companyId) {
                $_SESSION['auth_message'] = 'Workflow not found.';
            } else {
                $_SESSION['auth_message'] = updateApprovalWorkflow($pdo, $targetId, $payload)
                    ? 'Workflow updated successfully.'
                    : 'Unable to update the workflow.';
            }
        } else {
            $_SESSION['auth_message'] = createApprovalWorkflow($pdo, $payload) > 0
                ? 'Workflow created successfully.'
                : 'Unable to create the workflow.';
        }

        redirect('/approval?action=workflows');
    }

    if ($postAction === 'toggle_workflow') {
        $targetId = (int) ($_POST['workflow_id'] ?? 0);
        $workflow = $targetId > 0 ? getWorkflowById($pdo, $targetId) : null;

        if ($workflow && (int) $workflow['company_id'] === $companyId) {
            toggleWorkflowStatus($pdo, $targetId);
            $_SESSION['auth_message'] = 'Workflow status updated.';
        } else {
            $_SESSION['auth_message'] = 'Workflow not found.';
        }

        redirect('/approval?action=workflows');
    }

    // ---- Workflow steps (approval_workflow_steps) ----
    if ($postAction === 'save_step' || $postAction === 'delete_step') {
        $targetWorkflowId = (int) ($_POST['workflow_id'] ?? 0);
        $stepWorkflow = $targetWorkflowId > 0 ? getWorkflowById($pdo, $targetWorkflowId) : null;

        if (!$stepWorkflow || (int) $stepWorkflow['company_id'] !== $companyId) {
            $_SESSION['auth_message'] = 'Workflow not found.';
            redirect('/approval?action=workflows');
        }

        $stepBack = '/approval?action=workflows&workflow_id=' . $targetWorkflowId;

        if ($postAction === 'save_step') {
            $targetStepId = (int) ($_POST['step_id'] ?? 0);
            $stepPayload = [
                'step_number' => (int) ($_POST['step_number'] ?? 0),
                'role_required' => (string) ($_POST['role_required'] ?? ''),
                'action' => (string) ($_POST['step_action'] ?? ''),
                'notes' => (string) ($_POST['step_notes'] ?? ''),
            ];

            $validated = validateWorkflowStep($stepPayload, $allowedRoleKeys);

            if (!$validated['ok']) {
                $_SESSION['auth_message'] = $validated['error'];
            } elseif ($targetStepId > 0) {
                $existingStep = getWorkflowStepById($pdo, $targetStepId);
                if (!$existingStep || (int) $existingStep['workflow_id'] !== $targetWorkflowId) {
                    $_SESSION['auth_message'] = 'Workflow step not found.';
                } else {
                    $_SESSION['auth_message'] = updateWorkflowStep($pdo, $targetStepId, $validated['values'])
                        ? 'Workflow step updated successfully.'
                        : 'Unable to update the workflow step.';
                }
            } else {
                $newStepId = createWorkflowStep($pdo, $targetWorkflowId, $validated['values']);
                $_SESSION['auth_message'] = $newStepId > 0
                    ? 'Workflow step added successfully.'
                    : 'Unable to add the workflow step.';
            }

            redirect($stepBack);
        }

        // delete_step: refuse while the workflow still has pending requests.
        $targetStepId = (int) ($_POST['step_id'] ?? 0);
        $existingStep = $targetStepId > 0 ? getWorkflowStepById($pdo, $targetStepId) : null;

        if (!$existingStep || (int) $existingStep['workflow_id'] !== $targetWorkflowId) {
            $_SESSION['auth_message'] = 'Workflow step not found.';
        } else {
            $pendingCount = countPendingWorkflowRequests($pdo, $targetWorkflowId);
            if ($pendingCount > 0) {
                $_SESSION['auth_message'] = 'Cannot delete this step: workflow "'
                    . $stepWorkflow['name'] . '" has ' . $pendingCount . ' pending request'
                    . ($pendingCount === 1 ? '' : 's') . '. Resolve them first.';
                app_log('warning', 'Workflow step delete blocked by pending requests', [
                    'workflow_id' => $targetWorkflowId,
                    'step_id' => $targetStepId,
                    'pending' => $pendingCount,
                ]);
            } else {
                $_SESSION['auth_message'] = deleteWorkflowStep($pdo, $targetStepId)
                    ? 'Workflow step deleted successfully.'
                    : 'Unable to delete the workflow step.';
            }
        }

        redirect($stepBack);
    }

    if ($postAction === 'submit_request') {
        $newRequestId = createApprovalRequest($pdo, [
            'company_id' => $companyId,
            'workflow_id' => (int) ($_POST['workflow_id'] ?? 0),
            'requester_id' => (int) ($_SESSION['user_id'] ?? 0),
            'entity_type' => trim((string) ($_POST['entity_type'] ?? '')),
            'entity_id' => (int) ($_POST['entity_id'] ?? 0),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ]);

        $_SESSION['auth_message'] = $newRequestId > 0
            ? 'Approval request submitted successfully.'
            : 'Unable to submit the request. Choose an active workflow and a positive entity ID.';

        redirect('/approval?action=requests');
    }

    if ($postAction === 'approve_request' || $postAction === 'reject_request') {
        $targetId = (int) ($_POST['request_id'] ?? 0);
        $request = $targetId > 0 ? getApprovalRequestById($pdo, $targetId) : null;
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $actionName = $postAction === 'approve_request' ? 'approve' : 'reject';

        if (!$request || (int) $request['company_id'] !== $companyId) {
            $_SESSION['auth_message'] = 'Approval request not found.';
        } elseif ($request['status'] !== 'pending') {
            $_SESSION['auth_message'] = 'Only pending requests can be actioned.';
        } else {
            $_SESSION['auth_message'] = addApprovalAction(
                $pdo,
                $targetId,
                $userId,
                $actionName,
                trim((string) ($_POST['comments'] ?? ''))
            )
                ? ($actionName === 'approve' ? 'Approval recorded.' : 'Request rejected.')
                : 'Unable to record the action. You may have already approved this request.';
        }

        redirect('/approval?action=requests&request_id=' . $targetId);
    }
}

$workflows = getApprovalWorkflowsByCompany($pdo, $companyId);
$activeWorkflows = getApprovalWorkflowsByCompany($pdo, $companyId, true);
$requests = getApprovalRequestsByStatus($pdo, $companyId, $statusFilter === 'all' ? null : $statusFilter);
$requestCounts = getApprovalRequestCounts($pdo, $companyId);

$workflow = [];
$workflowSteps = [];
$workflowStepRows = [];
$stepPendingCount = 0;
if ($workflowId > 0) {
    $candidate = getWorkflowById($pdo, $workflowId);
    if ($candidate && (int) $candidate['company_id'] === $companyId) {
        $workflow = $candidate;
        $workflowSteps = getWorkflowSteps($pdo, $workflowId);
        $workflowStepRows = getWorkflowStepRows($pdo, $workflowId);
        $stepPendingCount = countPendingWorkflowRequests($pdo, $workflowId);
    }
}

// Steps of every workflow, keyed by workflow_id, for the workflow list.
$stepsByWorkflow = getWorkflowStepRowsByCompany($pdo, $companyId);

// Step being edited (must belong to the selected workflow).
$editingStep = [];
if ($stepId > 0 && $workflow !== []) {
    foreach ($workflowStepRows as $stepRow) {
        if ((int) $stepRow['step_id'] === $stepId) {
            $editingStep = $stepRow;
            break;
        }
    }
}

$roleLabels = [];
foreach ($allRoles as $roleRow) {
    $roleLabels[(string) $roleRow['role_key']] = (string) $roleRow['name'];
}

$request = [];
$requestActions = [];
$requestSteps = [];
if ($requestId > 0) {
    $candidate = getApprovalRequestById($pdo, $requestId);
    if ($candidate && (int) $candidate['company_id'] === $companyId) {
        $request = $candidate;
        $requestActions = getApprovalActions($pdo, $requestId);
        $requestSteps = getWorkflowSteps($pdo, (int) $request['workflow_id']);
    }
}

$entityTypes = [
    'expense' => 'Expense',
    'purchase_order' => 'Purchase order',
    'invoice' => 'Invoice',
    'sales_discount' => 'Sales discount',
    'journal_entry' => 'Journal entry',
    'payment_made' => 'Payment made',
    'payment_received' => 'Payment received',
    'payroll_run' => 'Payroll run',
];

$pageTitle = $view === 'requests' ? 'Approval Requests' : 'Approval Workflows';

require APP_ROOT . '/views/approval.html';
