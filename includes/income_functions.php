<?php
/**
 * Module 8: Income Management Functions
 * Income sources and income records using the canonical tables
 * income_sources and income_records.
 */

// Prevent direct access
defined('INCOME_MODULE') || define('INCOME_MODULE', 'income');

// Require foundation
defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';

if (!function_exists('incomeCompanyId')) {
    function incomeCompanyId(): int
    {
        return function_exists('currentCompanyId') ? currentCompanyId() : 1;
    }
}

if (!function_exists('incomePartialUpdate')) {
    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    function incomePartialUpdate(array $data, array $allowed): array
    {
        $sets = [];
        $params = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $data)) {
                $sets[] = $column . ' = ?';
                $params[] = $data[$column];
            }
        }
        return [implode(', ', $sets), $params];
    }
}

// ============================================================
// Income source functions
// ============================================================

function getIncomeSourcesByCompany(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT s.*,
               (SELECT COUNT(*) FROM income_records ir
                 WHERE ir.source_id = s.income_source_id) AS transaction_count,
               (SELECT COALESCE(SUM(ir.total_amount), 0) FROM income_records ir
                 WHERE ir.source_id = s.income_source_id AND ir.payment_status = 'paid') AS total_received
        FROM income_sources s
        WHERE s.company_id = ?
        ORDER BY s.is_active DESC, s.sort_order, s.name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getIncomeSourceById(PDO $pdo, int $sourceId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM income_sources WHERE income_source_id = ?");
    $stmt->execute([$sourceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createIncomeSource(PDO $pdo, array $sourceData): int
{
    $name = trim((string) ($sourceData['name'] ?? ''));
    if ($name === '') {
        return 0;
    }

    $stmt = $pdo->prepare("
        INSERT INTO income_sources (company_id, name, code, description, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $sourceData['company_id'] ?? incomeCompanyId(),
        $name,
        $sourceData['code'] ?? null,
        $sourceData['description'] ?? null,
        array_key_exists('is_active', $sourceData) ? (int) (bool) $sourceData['is_active'] : 1,
        (int) ($sourceData['sort_order'] ?? 0),
    ]);

    return (int) $pdo->lastInsertId();
}

function updateIncomeSource(PDO $pdo, int $sourceId, array $sourceData): bool
{
    $allowed = ['name', 'code', 'description', 'is_active', 'sort_order'];
    if (array_key_exists('is_active', $sourceData)) {
        $sourceData['is_active'] = (int) (bool) $sourceData['is_active'];
    }
    if (array_key_exists('name', $sourceData)) {
        $sourceData['name'] = trim((string) $sourceData['name']);
        if ($sourceData['name'] === '') {
            unset($sourceData['name']);
        }
    }

    [$set, $params] = incomePartialUpdate($sourceData, $allowed);
    if ($set === '') {
        return true;
    }

    $params[] = $sourceId;
    $stmt = $pdo->prepare("UPDATE income_sources SET $set, updated_at = NOW() WHERE income_source_id = ?");
    return $stmt->execute($params);
}

function deleteIncomeSource(PDO $pdo, int $sourceId): bool
{
    $stmt = $pdo->prepare("UPDATE income_sources SET is_active = 0, updated_at = NOW() WHERE income_source_id = ?");
    return $stmt->execute([$sourceId]);
}

// ============================================================
// Income record functions
// ============================================================

function getIncomeTransactionsByCompany(PDO $pdo, int $companyId = 1, ?string $status = null): array
{
    $cond = $status ? 'AND ir.payment_status = ?' : '';
    $params = $status ? [$status] : [];

    $stmt = $pdo->prepare("
        SELECT ir.*, s.name AS source_name, c.name AS customer_name,
               si.invoice_number, b.name AS branch_name
        FROM income_records ir
        LEFT JOIN income_sources s ON ir.source_id = s.income_source_id
        LEFT JOIN customers c ON ir.customer_id = c.customer_id
        LEFT JOIN sales_invoices si ON ir.sales_invoice_id = si.invoice_id
        LEFT JOIN branches b ON ir.branch_id = b.branch_id
        WHERE ir.company_id = ? $cond
        ORDER BY ir.income_date DESC, ir.income_id DESC
    ");
    array_unshift($params, $companyId);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getIncomeTransactionById(PDO $pdo, int $transactionId): ?array
{
    $stmt = $pdo->prepare("
        SELECT ir.*, s.name AS source_name, c.name AS customer_name,
               si.invoice_number, b.name AS branch_name
        FROM income_records ir
        LEFT JOIN income_sources s ON ir.source_id = s.income_source_id
        LEFT JOIN customers c ON ir.customer_id = c.customer_id
        LEFT JOIN sales_invoices si ON ir.sales_invoice_id = si.invoice_id
        LEFT JOIN branches b ON ir.branch_id = b.branch_id
        WHERE ir.income_id = ?
    ");
    $stmt->execute([$transactionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Create an income record. The legacy `source` and `amount` columns are kept
 * in sync so dashboard and reporting queries continue to work.
 */
function createIncomeTransaction(PDO $pdo, array $transactionData): int
{
    $companyId = (int) ($transactionData['company_id'] ?? incomeCompanyId());
    $sourceId = (int) ($transactionData['income_source_id'] ?? $transactionData['source_id'] ?? 0);

    $source = $sourceId > 0 ? getIncomeSourceById($pdo, $sourceId) : null;
    if (!$source || (int) $source['company_id'] !== $companyId) {
        return 0;
    }

    $gross = (float) ($transactionData['gross_amount'] ?? 0);
    $discount = (float) ($transactionData['discount_amount'] ?? 0);
    $tax = (float) ($transactionData['tax_amount'] ?? 0);
    $net = array_key_exists('net_amount', $transactionData) ? (float) $transactionData['net_amount'] : $gross - $discount;
    $total = array_key_exists('total_amount', $transactionData) ? (float) $transactionData['total_amount'] : $net + $tax;

    $recognized = !empty($transactionData['recognized']) ? 1 : 0;
    $recognitionDate = $transactionData['recognition_date'] ?? ($recognized ? date('Y-m-d') : null);
    $transactionNumber = trim((string) ($transactionData['transaction_number'] ?? ''));
    if ($transactionNumber === '') {
        $transactionNumber = 'INC-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    $stmt = $pdo->prepare("
        INSERT INTO income_records
            (transaction_number, company_id, source, amount, payment_status, gross_amount,
             discount_amount, net_amount, tax_amount, total_amount, source_id, branch_id,
             customer_id, sales_invoice_id, income_date, description, recognized,
             recognition_date, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $transactionNumber,
        $companyId,
        $source['name'],
        $total,
        $transactionData['payment_status'] ?? 'pending',
        $gross,
        $discount,
        $net,
        $tax,
        $total,
        $sourceId,
        !empty($transactionData['branch_id']) ? (int) $transactionData['branch_id'] : null,
        !empty($transactionData['customer_id']) ? (int) $transactionData['customer_id'] : null,
        !empty($transactionData['sales_invoice_id']) ? (int) $transactionData['sales_invoice_id'] : null,
        $transactionData['income_date'] ?? $transactionData['transaction_date'] ?? date('Y-m-d'),
        $transactionData['description'] ?? null,
        $recognized,
        $recognized ? $recognitionDate : null,
        $transactionData['created_by'] ?? ($_SESSION['user_id'] ?? null),
    ]);

    return (int) $pdo->lastInsertId();
}

function updateIncomeTransaction(PDO $pdo, int $transactionId, array $transactionData): bool
{
    $existing = getIncomeTransactionById($pdo, $transactionId);
    if (!$existing) {
        return false;
    }

    $merged = array_merge($existing, $transactionData);
    $sourceId = (int) ($merged['source_id'] ?? 0);
    $transactionData['source_id'] = $sourceId > 0 ? $sourceId : null;

    if ($sourceId > 0) {
        $source = getIncomeSourceById($pdo, $sourceId);
        if ($source) {
            $transactionData['source'] = $source['name'];
        }
    }

    $gross = (float) ($merged['gross_amount'] ?? 0);
    $discount = (float) ($merged['discount_amount'] ?? 0);
    $tax = (float) ($merged['tax_amount'] ?? 0);
    $net = array_key_exists('net_amount', $transactionData) ? (float) $transactionData['net_amount'] : $gross - $discount;
    $total = array_key_exists('total_amount', $transactionData) ? (float) $transactionData['total_amount'] : $net + $tax;

    $transactionData['net_amount'] = $net;
    $transactionData['total_amount'] = $total;
    $transactionData['amount'] = $total;

    $recognized = !empty($merged['recognized']) ? 1 : 0;
    $transactionData['recognized'] = $recognized;
    $transactionData['recognition_date'] = $recognized
        ? ($merged['recognition_date'] ?: date('Y-m-d'))
        : null;

    if (array_key_exists('transaction_date', $transactionData) && !array_key_exists('income_date', $transactionData)) {
        $transactionData['income_date'] = $transactionData['transaction_date'];
    }

    $allowed = [
        'transaction_number', 'source', 'amount', 'payment_status', 'gross_amount',
        'discount_amount', 'net_amount', 'tax_amount', 'total_amount', 'source_id',
        'branch_id', 'customer_id', 'sales_invoice_id', 'income_date', 'description',
        'recognized', 'recognition_date',
    ];

    [$set, $params] = incomePartialUpdate($transactionData, $allowed);
    if ($set === '') {
        return true;
    }

    $params[] = $transactionId;
    $stmt = $pdo->prepare("UPDATE income_records SET $set, updated_at = NOW() WHERE income_id = ?");
    return $stmt->execute($params);
}

function recognizeIncome(PDO $pdo, int $transactionId): bool
{
    $stmt = $pdo->prepare("
        UPDATE income_records
        SET recognized = 1, recognition_date = CURDATE(), updated_at = NOW()
        WHERE income_id = ?
    ");
    return $stmt->execute([$transactionId]);
}

// ============================================================
// Reporting functions
// ============================================================

function getPaymentStatusSummary(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT payment_status, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total_amount
        FROM income_records
        WHERE company_id = ?
        GROUP BY payment_status
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getIncomeRecognitionBySource(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT s.name AS source_name, ir.payment_status,
               COUNT(ir.income_id) AS transaction_count,
               COALESCE(SUM(ir.net_amount), 0) AS total_net,
               COALESCE(SUM(ir.gross_amount), 0) AS total_gross
        FROM income_sources s
        LEFT JOIN income_records ir
               ON ir.source_id = s.income_source_id AND ir.company_id = ?
        WHERE s.company_id = ?
        GROUP BY s.income_source_id, ir.payment_status
        ORDER BY s.name
    ");
    $stmt->execute([$companyId, $companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getIncomeRecognitionByPeriod(PDO $pdo, int $companyId = 1, ?string $startDate = null, ?string $endDate = null): array
{
    $conditions = ['ir.company_id = ?'];
    $params = [$companyId];

    if ($startDate) {
        $conditions[] = 'ir.income_date >= ?';
        $params[] = $startDate;
    }
    if ($endDate) {
        $conditions[] = 'ir.income_date <= ?';
        $params[] = $endDate;
    }

    $where = implode(' AND ', $conditions);
    $stmt = $pdo->prepare("
        SELECT ir.income_date,
               s.name AS source_name,
               COUNT(ir.income_id) AS transaction_count,
               COALESCE(SUM(ir.net_amount), 0) AS total_net,
               COALESCE(SUM(ir.gross_amount), 0) AS total_gross
        FROM income_records ir
        LEFT JOIN income_sources s ON ir.source_id = s.income_source_id
        WHERE $where
        GROUP BY ir.income_date, s.income_source_id
        ORDER BY ir.income_date DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// Utility functions
// ============================================================

function formatIncomeAmount(PDO $pdo, float $amount): string
{
    $decimals = (int) getCompanySetting($pdo, 'decimal_places', 2);
    return number_format($amount, $decimals, '.', '');
}

function getDefaultIncomeSource(PDO $pdo): int
{
    $configured = (int) getCompanySetting($pdo, 'default_income_source', 0);
    if ($configured > 0 && getIncomeSourceById($pdo, $configured)) {
        return $configured;
    }

    $stmt = $pdo->prepare("
        SELECT income_source_id FROM income_sources
        WHERE company_id = ? AND is_active = 1
        ORDER BY sort_order, name LIMIT 1
    ");
    $stmt->execute([incomeCompanyId()]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function canDeleteIncomeSource(PDO $pdo, int $sourceId): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM income_records
        WHERE source_id = ? AND payment_status = 'paid'
    ");
    $stmt->execute([$sourceId]);
    return (int) $stmt->fetchColumn() === 0;
}
