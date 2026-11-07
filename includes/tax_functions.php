<?php
/**
 * Module 17: Tax Functions
 * Tax rates and tax transactions backed by tax_rates / tax_transactions.
 */

defined('TAX_MODULE') || define('TAX_MODULE', 'tax');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/database.php';

const TAX_TYPES = ['gst', 'vat', 'sales_tax', 'use_tax'];
const TAX_TRANSACTION_TYPES = ['sale', 'purchase', 'adjustment'];

function taxValidType(string $type): bool
{
    return in_array($type, TAX_TYPES, true);
}

// ============================================================
// Tax rates
// ============================================================

function getTaxRatesByCompany(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM tax_rates WHERE company_id = ? ORDER BY sort_order, tax_type, name'
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getActiveTaxRates(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM tax_rates WHERE company_id = ? AND is_active = 1 ORDER BY sort_order, name'
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTaxRateById(PDO $pdo, int $taxRateId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM tax_rates WHERE tax_rate_id = ?');
    $stmt->execute([$taxRateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createTaxRate(PDO $pdo, array $rateData): int
{
    $name = trim((string) ($rateData['name'] ?? ''));
    $type = (string) ($rateData['tax_type'] ?? '');
    $rate = (float) ($rateData['rate'] ?? 0);
    $jurisdiction = trim((string) ($rateData['jurisdiction'] ?? ''));
    $effectiveDate = (string) ($rateData['effective_date'] ?? date('Y-m-d'));
    $endDate = trim((string) ($rateData['end_date'] ?? ''));
    $companyId = (int) ($rateData['company_id'] ?? 1);

    if ($name === '' || !taxValidType($type) || $rate <= 0 || $rate > 1) {
        return 0;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
        $effectiveDate = date('Y-m-d');
    }
    if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $endDate = '';
    }

    try {
        $dupe = $pdo->prepare(
            'SELECT 1 FROM tax_rates
              WHERE company_id = ? AND tax_type = ? AND name = ? AND COALESCE(jurisdiction, \'\') = ?
              LIMIT 1'
        );
        $dupe->execute([$companyId, $type, $name, $jurisdiction]);
        if ($dupe->fetchColumn()) {
            return 0;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO tax_rates
                (company_id, name, rate, tax_type, jurisdiction, is_active,
                 effective_date, end_date, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $companyId,
            $name,
            $rate,
            $type,
            $jurisdiction !== '' ? $jurisdiction : null,
            array_key_exists('is_active', $rateData) ? (int) (bool) $rateData['is_active'] : 1,
            $effectiveDate,
            $endDate !== '' ? $endDate : null,
            (int) ($rateData['sort_order'] ?? 0),
        ]);
    } catch (PDOException $e) {
        return 0;
    }

    return (int) $pdo->lastInsertId();
}

function updateTaxRate(PDO $pdo, int $taxRateId, array $rateData): bool
{
    $name = trim((string) ($rateData['name'] ?? ''));
    $type = (string) ($rateData['tax_type'] ?? '');
    $rate = (float) ($rateData['rate'] ?? 0);
    $jurisdiction = trim((string) ($rateData['jurisdiction'] ?? ''));
    $effectiveDate = (string) ($rateData['effective_date'] ?? date('Y-m-d'));
    $endDate = trim((string) ($rateData['end_date'] ?? ''));

    if ($name === '' || !taxValidType($type) || $rate <= 0 || $rate > 1) {
        return false;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
        $effectiveDate = date('Y-m-d');
    }
    if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $endDate = '';
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE tax_rates
                SET name = ?, rate = ?, tax_type = ?, jurisdiction = ?, is_active = ?,
                    effective_date = ?, end_date = ?, sort_order = ?, updated_at = NOW()
              WHERE tax_rate_id = ?'
        );
        return $stmt->execute([
            $name,
            $rate,
            $type,
            $jurisdiction !== '' ? $jurisdiction : null,
            array_key_exists('is_active', $rateData) ? (int) (bool) $rateData['is_active'] : 1,
            $effectiveDate,
            $endDate !== '' ? $endDate : null,
            (int) ($rateData['sort_order'] ?? 0),
            $taxRateId,
        ]);
    } catch (PDOException $e) {
        return false;
    }
}

function toggleTaxRateStatus(PDO $pdo, int $taxRateId): bool
{
    $stmt = $pdo->prepare(
        'UPDATE tax_rates
            SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END, updated_at = NOW()
          WHERE tax_rate_id = ?'
    );
    return $stmt->execute([$taxRateId]);
}

// ============================================================
// Tax transactions
// ============================================================

function getTaxTransactions(PDO $pdo, int $companyId = 1, ?int $taxRateId = null): array
{
    $cond = $taxRateId !== null && $taxRateId > 0 ? 'AND tt.tax_rate_id = ?' : '';
    $params = $taxRateId !== null && $taxRateId > 0 ? [$taxRateId] : [];

    $stmt = $pdo->prepare("
        SELECT tt.*, tr.name AS rate_name, tr.tax_type, tr.rate AS tax_rate
        FROM tax_transactions tt
        INNER JOIN tax_rates tr ON tt.tax_rate_id = tr.tax_rate_id
        WHERE tt.company_id = ? $cond
        ORDER BY tt.transaction_date DESC, tt.transaction_id DESC
    ");
    array_unshift($params, $companyId);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTaxTransactionById(PDO $pdo, int $transactionId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM tax_transactions WHERE transaction_id = ?');
    $stmt->execute([$transactionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createTaxTransaction(PDO $pdo, array $data): int
{
    $companyId = (int) ($data['company_id'] ?? 1);
    $taxRateId = (int) ($data['tax_rate_id'] ?? 0);
    $taxable = round((float) ($data['taxable_amount'] ?? 0), 4);
    $taxAmount = round((float) ($data['tax_amount'] ?? 0), 4);
    $type = (string) ($data['transaction_type'] ?? 'sale');
    $date = (string) ($data['transaction_date'] ?? date('Y-m-d'));

    if ($taxRateId <= 0 || $taxable < 0 || $taxAmount < 0 || ($taxable <= 0 && $taxAmount <= 0)) {
        return 0;
    }
    if (!in_array($type, TAX_TRANSACTION_TYPES, true)) {
        return 0;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $rate = getTaxRateById($pdo, $taxRateId);
    if (!$rate || (int) $rate['company_id'] !== $companyId) {
        return 0;
    }

    $referenceId = (int) ($data['reference_id'] ?? 0);
    $referenceType = trim((string) ($data['reference_type'] ?? ''));

    $stmt = $pdo->prepare(
        'INSERT INTO tax_transactions
            (company_id, tax_rate_id, taxable_amount, tax_amount, transaction_type,
             reference_id, reference_type, transaction_date, description)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $companyId,
        $taxRateId,
        $taxable,
        $taxAmount,
        $type,
        $referenceId > 0 ? $referenceId : null,
        $referenceType !== '' ? substr($referenceType, 0, 50) : null,
        $date,
        trim((string) ($data['description'] ?? '')) !== '' ? trim((string) $data['description']) : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function setTaxTransactionReconciled(PDO $pdo, int $transactionId, bool $reconciled): bool
{
    $stmt = $pdo->prepare(
        'UPDATE tax_transactions SET is_reconciled = ?, updated_at = NOW() WHERE transaction_id = ?'
    );
    return $stmt->execute([$reconciled ? 1 : 0, $transactionId]);
}

// ============================================================
// Summaries
// ============================================================

function getTaxSummaryByType(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT tr.tax_type, tr.name AS rate_name,
               COALESCE(SUM(tt.taxable_amount), 0) AS total_taxable,
               COALESCE(SUM(tt.tax_amount), 0) AS total_amount,
               COUNT(tt.transaction_id) AS transaction_count,
               COALESCE(SUM(CASE WHEN tt.is_reconciled = 1 THEN 1 ELSE 0 END), 0) AS reconciled_count
        FROM tax_transactions tt
        INNER JOIN tax_rates tr ON tt.tax_rate_id = tr.tax_rate_id
        WHERE tt.company_id = ?
        GROUP BY tr.tax_type, tr.name
        ORDER BY tr.tax_type, tr.name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTaxTotals(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS transaction_count,
               COALESCE(SUM(taxable_amount), 0) AS total_taxable,
               COALESCE(SUM(tax_amount), 0) AS total_tax,
               COALESCE(SUM(CASE WHEN is_reconciled = 1 THEN 1 ELSE 0 END), 0) AS reconciled_count
        FROM tax_transactions
        WHERE company_id = ?
    ");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return $row + [
        'transaction_count' => 0,
        'total_taxable' => 0,
        'total_tax' => 0,
        'reconciled_count' => 0,
    ];
}
