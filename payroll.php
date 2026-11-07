<?php
/**
 * Module 16: Payroll Controller
 * Employee management plus payroll runs and line items.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('PAYROLL_MODULE') || define('PAYROLL_MODULE', 'payroll');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/payroll_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $payrollWriteAction = match ($postAction) {
        'save_employee' => (int) ($_POST['employee_id'] ?? 0) > 0 ? 'update' : 'create',
        'toggle_employee' => 'update',
        'create_run', 'add_line' => 'create',
        'delete_line' => 'delete',
        'process_run', 'reverse_run' => 'approve',
        default => 'update',
    };
    require_permission('payroll', $payrollWriteAction);
} else {
    require_permission('payroll', 'view');
}

$view = (string) ($_GET['action'] ?? 'employees');
$viewMap = [
    'list' => 'employees',
    'manage_employees' => 'employees',
    'payroll' => 'runs',
    'manage_payroll' => 'runs',
    'entry' => 'runs',
];
if (isset($viewMap[$view])) {
    $view = $viewMap[$view];
}
if (!in_array($view, ['employees', 'runs'], true)) {
    $view = 'employees';
}

$editEmployeeId = (int) ($_GET['employee_id'] ?? 0);
$runId = (int) ($_GET['run_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'save_employee') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $payload = [
            'company_id' => $companyId,
            'employee_code' => trim((string) ($_POST['employee_code'] ?? '')),
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'last_name' => trim((string) ($_POST['last_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'hire_date' => (string) ($_POST['hire_date'] ?? date('Y-m-d')),
            'termination_date' => trim((string) ($_POST['termination_date'] ?? '')),
            'employment_type' => (string) ($_POST['employment_type'] ?? 'full_time'),
            'hourly_rate' => (float) ($_POST['hourly_rate'] ?? 0),
            'salary' => (float) ($_POST['salary'] ?? 0),
            'tax_id' => trim((string) ($_POST['tax_id'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ];

        if ($employeeId > 0) {
            $existing = getEmployeeById($pdo, $employeeId);
            if (!$existing || (int) $existing['company_id'] !== $companyId) {
                $_SESSION['auth_message'] = 'Employee not found.';
            } else {
                if ($payload['employee_code'] === '') {
                    $payload['employee_code'] = $existing['employee_code'];
                }
                $_SESSION['auth_message'] = updateEmployee($pdo, $employeeId, $payload)
                    ? 'Employee updated successfully.'
                    : 'Unable to update the employee. Check the required fields and employee code.';
            }
        } else {
            $_SESSION['auth_message'] = createEmployee($pdo, $payload) > 0
                ? 'Employee created successfully.'
                : 'Unable to create the employee. Check the required fields and employee code.';
        }

        redirect('/payroll?action=employees');
    }

    if ($postAction === 'toggle_employee') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $employee = $employeeId > 0 ? getEmployeeById($pdo, $employeeId) : null;

        if ($employee && (int) $employee['company_id'] === $companyId) {
            toggleEmployeeStatus($pdo, $employeeId);
            $_SESSION['auth_message'] = 'Employee status updated.';
        } else {
            $_SESSION['auth_message'] = 'Employee not found.';
        }

        redirect('/payroll?action=employees');
    }

    if ($postAction === 'create_run') {
        $newRunId = createPayrollRun($pdo, [
            'company_id' => $companyId,
            'run_date' => (string) ($_POST['run_date'] ?? date('Y-m-d')),
            'period_start' => (string) ($_POST['period_start'] ?? date('Y-m-01')),
            'period_end' => (string) ($_POST['period_end'] ?? date('Y-m-t')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
            'created_by' => $_SESSION['user_id'] ?? null,
        ]);

        $_SESSION['auth_message'] = $newRunId > 0
            ? 'Payroll run created. Add earning, deduction and tax lines, then process it.'
            : 'Unable to create the payroll run. Check the period dates.';

        redirect($newRunId > 0 ? '/payroll?action=runs&run_id=' . $newRunId : '/payroll?action=runs');
    }

    if ($postAction === 'add_line') {
        $targetRunId = (int) ($_POST['run_id'] ?? 0);
        $lineId = addPayrollLine($pdo, [
            'run_id' => $targetRunId,
            'employee_id' => (int) ($_POST['employee_id'] ?? 0),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'type' => (string) ($_POST['type'] ?? 'earning'),
            'amount' => (float) ($_POST['amount'] ?? 0),
        ], $companyId);

        $_SESSION['auth_message'] = $lineId > 0
            ? 'Payroll line added.'
            : 'Unable to add the line. Draft runs only and the amount must be greater than zero.';

        redirect('/payroll?action=runs&run_id=' . $targetRunId);
    }

    if ($postAction === 'delete_line') {
        $targetRunId = (int) ($_POST['run_id'] ?? 0);
        $lineId = (int) ($_POST['line_id'] ?? 0);

        $_SESSION['auth_message'] = deletePayrollLine($pdo, $lineId, $companyId)
            ? 'Payroll line removed.'
            : 'Unable to remove the line. Only draft runs can be edited.';

        redirect('/payroll?action=runs&run_id=' . $targetRunId);
    }

    if ($postAction === 'process_run') {
        $targetRunId = (int) ($_POST['run_id'] ?? 0);

        $_SESSION['auth_message'] = processPayrollRun($pdo, $targetRunId, $companyId)
            ? 'Payroll run processed.'
            : 'Unable to process the run. It must be a draft with earnings and a non-negative net.';

        redirect('/payroll?action=runs&run_id=' . $targetRunId);
    }

    if ($postAction === 'reverse_run') {
        $targetRunId = (int) ($_POST['run_id'] ?? 0);

        $_SESSION['auth_message'] = reversePayrollRun($pdo, $targetRunId, $companyId)
            ? 'Payroll run reversed.'
            : 'Unable to reverse the run. Only processed runs can be reversed.';

        redirect('/payroll?action=runs&run_id=' . $targetRunId);
    }
}

$employees = getEmployeesByCompany($pdo, $companyId);
$activeEmployees = getEmployeesByCompany($pdo, $companyId, true);
$payrollRuns = getPayrollRunsByCompany($pdo, $companyId);

$employee = [];
if ($editEmployeeId > 0) {
    $candidate = getEmployeeById($pdo, $editEmployeeId);
    if ($candidate && (int) $candidate['company_id'] === $companyId) {
        $employee = $candidate;
    }
}

$selectedRun = [];
$runLines = [];
$runTotals = [];
if ($runId > 0) {
    $candidate = getPayrollRunById($pdo, $runId);
    if ($candidate && (int) $candidate['company_id'] === $companyId) {
        $selectedRun = $candidate;
        $runLines = getPayrollRunLines($pdo, $runId);
        $runTotals = calculatePayrollTotals($pdo, $runId);
    }
}

$activeEmployeeCount = count($activeEmployees);
$draftRunCount = count(array_filter($payrollRuns, static fn(array $run): bool => $run['status'] === 'draft'));
$processedRunCount = count(array_filter($payrollRuns, static fn(array $run): bool => $run['status'] === 'processed'));

$pageTitle = $view === 'runs' ? 'Payroll Runs' : 'Employees';

require APP_ROOT . '/views/payroll.html';
