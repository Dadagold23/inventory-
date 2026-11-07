<?php
/**
 * Module 16: Payroll Functions
 * Employees, payroll runs and payroll run lines backed by the canonical
 * employees / payroll_runs / payroll_run_lines tables.
 */

defined('PAYROLL_MODULE') || define('PAYROLL_MODULE', 'payroll');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/database.php';

const PAYROLL_EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract'];
const PAYROLL_LINE_TYPES = ['earning', 'deduction', 'tax'];

function payrollValidDate(string $date): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

// ============================================================
// Employees
// ============================================================

function getEmployeesByCompany(PDO $pdo, int $companyId = 1, bool $activeOnly = false): array
{
    $cond = $activeOnly ? 'AND e.is_active = 1' : '';
    $stmt = $pdo->prepare("
        SELECT e.*, u.full_name AS user_name
        FROM employees e
        LEFT JOIN users u ON e.user_id = u.user_id
        WHERE e.company_id = ? $cond
        ORDER BY e.last_name, e.first_name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getEmployeeById(PDO $pdo, int $employeeId): ?array
{
    $stmt = $pdo->prepare("
        SELECT e.*, u.full_name AS user_name
        FROM employees e
        LEFT JOIN users u ON e.user_id = u.user_id
        WHERE e.employee_id = ?
    ");
    $stmt->execute([$employeeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createEmployee(PDO $pdo, array $data): int
{
    $firstName = trim((string) ($data['first_name'] ?? ''));
    $lastName = trim((string) ($data['last_name'] ?? ''));
    $employmentType = (string) ($data['employment_type'] ?? 'full_time');
    $code = trim((string) ($data['employee_code'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $hireDate = (string) ($data['hire_date'] ?? date('Y-m-d'));

    if ($firstName === '' || $lastName === '') {
        return 0;
    }
    if (!in_array($employmentType, PAYROLL_EMPLOYMENT_TYPES, true)) {
        return 0;
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return 0;
    }
    if (!payrollValidDate($hireDate)) {
        $hireDate = date('Y-m-d');
    }

    $companyId = (int) ($data['company_id'] ?? 1);
    $base = [
        'company_id' => $companyId,
        'user_id' => !empty($data['user_id']) ? (int) $data['user_id'] : null,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email !== '' ? $email : null,
        'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
        'hire_date' => $hireDate,
        'termination_date' => trim((string) ($data['termination_date'] ?? '')) ?: null,
        'is_active' => array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : 1,
        'employment_type' => $employmentType,
        'hourly_rate' => max(0, (float) ($data['hourly_rate'] ?? 0)),
        'salary' => max(0, (float) ($data['salary'] ?? 0)),
        'tax_id' => trim((string) ($data['tax_id'] ?? '')) ?: null,
        'address' => trim((string) ($data['address'] ?? '')) ?: null,
    ];

    try {
        $stmt = $pdo->prepare('
            INSERT INTO employees
                (company_id, user_id, employee_code, first_name, last_name, email, phone,
                 hire_date, termination_date, is_active, employment_type, hourly_rate,
                 salary, tax_id, address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $attempts = $code === '' ? 5 : 1;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $employeeCode = $code !== ''
                ? $code
                : 'EMP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            try {
                $stmt->execute([
                    $base['company_id'],
                    $base['user_id'],
                    $employeeCode,
                    $base['first_name'],
                    $base['last_name'],
                    $base['email'],
                    $base['phone'],
                    $base['hire_date'],
                    $base['termination_date'],
                    $base['is_active'],
                    $base['employment_type'],
                    $base['hourly_rate'],
                    $base['salary'],
                    $base['tax_id'],
                    $base['address'],
                ]);
                return (int) $pdo->lastInsertId();
            } catch (PDOException $e) {
                if ($code !== '' || (int) $e->getCode() !== 23000) {
                    return 0;
                }
            }
        }
    } catch (PDOException $e) {
        return 0;
    }

    return 0;
}

function updateEmployee(PDO $pdo, int $employeeId, array $data): bool
{
    $firstName = trim((string) ($data['first_name'] ?? ''));
    $lastName = trim((string) ($data['last_name'] ?? ''));
    $employmentType = (string) ($data['employment_type'] ?? 'full_time');
    $email = trim((string) ($data['email'] ?? ''));
    $hireDate = (string) ($data['hire_date'] ?? date('Y-m-d'));

    if ($firstName === '' || $lastName === '' || !in_array($employmentType, PAYROLL_EMPLOYMENT_TYPES, true)) {
        return false;
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    if (!payrollValidDate($hireDate)) {
        $hireDate = date('Y-m-d');
    }

    try {
        $stmt = $pdo->prepare('
            UPDATE employees
               SET user_id = ?, employee_code = ?, first_name = ?, last_name = ?, email = ?,
                   phone = ?, hire_date = ?, termination_date = ?, is_active = ?,
                   employment_type = ?, hourly_rate = ?, salary = ?, tax_id = ?, address = ?,
                   updated_at = NOW()
             WHERE employee_id = ?
        ');
        return $stmt->execute([
            !empty($data['user_id']) ? (int) $data['user_id'] : null,
            trim((string) ($data['employee_code'] ?? '')),
            $firstName,
            $lastName,
            $email !== '' ? $email : null,
            trim((string) ($data['phone'] ?? '')) ?: null,
            $hireDate,
            trim((string) ($data['termination_date'] ?? '')) ?: null,
            array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : 1,
            $employmentType,
            max(0, (float) ($data['hourly_rate'] ?? 0)),
            max(0, (float) ($data['salary'] ?? 0)),
            trim((string) ($data['tax_id'] ?? '')) ?: null,
            trim((string) ($data['address'] ?? '')) ?: null,
            $employeeId,
        ]);
    } catch (PDOException $e) {
        return false;
    }
}

function toggleEmployeeStatus(PDO $pdo, int $employeeId): bool
{
    $stmt = $pdo->prepare(
        'UPDATE employees
            SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END, updated_at = NOW()
          WHERE employee_id = ?'
    );
    return $stmt->execute([$employeeId]);
}

// ============================================================
// Payroll runs
// ============================================================

function getPayrollRunsByCompany(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT pr.*, u.full_name AS created_by_name,
               (SELECT COUNT(*) FROM payroll_run_lines l WHERE l.run_id = pr.run_id) AS line_count,
               (SELECT COALESCE(SUM(l.amount), 0) FROM payroll_run_lines l
                 WHERE l.run_id = pr.run_id AND l.type = 'earning') AS earning_total,
               (SELECT COALESCE(SUM(l.amount), 0) FROM payroll_run_lines l
                 WHERE l.run_id = pr.run_id AND l.type = 'deduction') AS deduction_total,
               (SELECT COALESCE(SUM(l.amount), 0) FROM payroll_run_lines l
                 WHERE l.run_id = pr.run_id AND l.type = 'tax') AS line_tax_total
        FROM payroll_runs pr
        LEFT JOIN users u ON pr.created_by = u.user_id
        WHERE pr.company_id = ?
        ORDER BY pr.run_date DESC, pr.run_id DESC
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPayrollRunById(PDO $pdo, int $runId): ?array
{
    $stmt = $pdo->prepare("
        SELECT pr.*, u.full_name AS created_by_name
        FROM payroll_runs pr
        LEFT JOIN users u ON pr.created_by = u.user_id
        WHERE pr.run_id = ?
    ");
    $stmt->execute([$runId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createPayrollRun(PDO $pdo, array $data): int
{
    $companyId = (int) ($data['company_id'] ?? 1);
    $runDate = (string) ($data['run_date'] ?? date('Y-m-d'));
    $periodStart = (string) ($data['period_start'] ?? date('Y-m-01'));
    $periodEnd = (string) ($data['period_end'] ?? date('Y-m-t'));

    if (!payrollValidDate($runDate) || !payrollValidDate($periodStart) || !payrollValidDate($periodEnd)) {
        return 0;
    }
    if ($periodStart > $periodEnd) {
        return 0;
    }

    $createdBy = (int) ($data['created_by'] ?? ($_SESSION['user_id'] ?? 0));
    if ($createdBy <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare('
        INSERT INTO payroll_runs (company_id, run_date, period_start, period_end, status, notes, created_by)
        VALUES (?, ?, ?, ?, \'draft\', ?, ?)
    ');
    $stmt->execute([
        $companyId,
        $runDate,
        $periodStart,
        $periodEnd,
        trim((string) ($data['notes'] ?? '')) ?: null,
        $createdBy,
    ]);

    return (int) $pdo->lastInsertId();
}

// ============================================================
// Payroll run lines
// ============================================================

function getPayrollRunLines(PDO $pdo, int $runId): array
{
    $stmt = $pdo->prepare("
        SELECT l.*, e.first_name, e.last_name, e.employee_code
        FROM payroll_run_lines l
        LEFT JOIN employees e ON l.employee_id = e.employee_id
        WHERE l.run_id = ?
        ORDER BY e.last_name, e.first_name, l.line_id
    ");
    $stmt->execute([$runId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function addPayrollLine(PDO $pdo, array $data, int $companyId = 1): int
{
    $runId = (int) ($data['run_id'] ?? 0);
    $employeeId = (int) ($data['employee_id'] ?? 0);
    $type = (string) ($data['type'] ?? 'earning');
    $description = trim((string) ($data['description'] ?? ''));
    $amount = round((float) ($data['amount'] ?? 0), 4);

    if ($runId <= 0 || $employeeId <= 0 || $description === '' || $amount <= 0) {
        return 0;
    }
    if (!in_array($type, PAYROLL_LINE_TYPES, true)) {
        return 0;
    }

    $run = getPayrollRunById($pdo, $runId);
    if (!$run || (int) $run['company_id'] !== $companyId || $run['status'] !== 'draft') {
        return 0;
    }

    $employee = getEmployeeById($pdo, $employeeId);
    if (!$employee || (int) $employee['company_id'] !== $companyId) {
        return 0;
    }

    $stmt = $pdo->prepare('
        INSERT INTO payroll_run_lines (run_id, employee_id, description, type, amount)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$runId, $employeeId, $description, $type, $amount]);

    return (int) $pdo->lastInsertId();
}

function deletePayrollLine(PDO $pdo, int $lineId, int $companyId = 1): bool
{
    $stmt = $pdo->prepare("
        SELECT l.line_id, pr.status, pr.company_id
        FROM payroll_run_lines l
        INNER JOIN payroll_runs pr ON l.run_id = pr.run_id
        WHERE l.line_id = ?
    ");
    $stmt->execute([$lineId]);
    $line = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$line || (int) $line['company_id'] !== $companyId || $line['status'] !== 'draft') {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM payroll_run_lines WHERE line_id = ?');
    return $stmt->execute([$lineId]);
}

function calculatePayrollTotals(PDO $pdo, int $runId): array
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type = 'earning' THEN amount ELSE 0 END), 0) AS gross_total,
            COALESCE(SUM(CASE WHEN type = 'deduction' THEN amount ELSE 0 END), 0) AS deduction_total,
            COALESCE(SUM(CASE WHEN type = 'tax' THEN amount ELSE 0 END), 0) AS tax_total
        FROM payroll_run_lines
        WHERE run_id = ?
    ");
    $stmt->execute([$runId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $gross = (float) ($row['gross_total'] ?? 0);
    $deductions = (float) ($row['deduction_total'] ?? 0);
    $tax = (float) ($row['tax_total'] ?? 0);

    return [
        'gross_total' => $gross,
        'deduction_total' => $deductions,
        'tax_total' => $tax,
        'net_total' => $gross - $deductions - $tax,
    ];
}

function processPayrollRun(PDO $pdo, int $runId, int $companyId = 1): bool
{
    $run = getPayrollRunById($pdo, $runId);
    if (!$run || (int) $run['company_id'] !== $companyId || $run['status'] !== 'draft') {
        return false;
    }

    $totals = calculatePayrollTotals($pdo, $runId);
    if ($totals['gross_total'] <= 0 || $totals['net_total'] < 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE payroll_runs
           SET gross_total = ?, tax_total = ?, net_total = ?, status = 'processed', updated_at = NOW()
         WHERE run_id = ? AND status = 'draft'
    ");
    return $stmt->execute([
        $totals['gross_total'],
        $totals['tax_total'],
        $totals['net_total'],
        $runId,
    ]);
}

function reversePayrollRun(PDO $pdo, int $runId, int $companyId = 1): bool
{
    $run = getPayrollRunById($pdo, $runId);
    if (!$run || (int) $run['company_id'] !== $companyId || $run['status'] !== 'processed') {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE payroll_runs SET status = 'reversed', updated_at = NOW()
         WHERE run_id = ? AND status = 'processed'
    ");
    return $stmt->execute([$runId]);
}
