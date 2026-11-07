<?php
/**
 * Phase C: Cashier shift (register session) management.
 *
 * A shift bounds the money that passes through one cashier's drawer at one
 * branch. Everything a shift needs to be provable lives in rows: the opening
 * float, the payments taken against sales on that shift, and explicit drawer
 * movements. Reconciliation is therefore recomputed from data on every read:
 *
 *   Expected = Float + Cash Receipts + Paid In - Cash Refunds - Paid Out
 *
 * Never trust a stored "expected" figure: cashierShiftTotals() rebuilds it.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/auth_helpers.php';

if (!function_exists('shiftTableReady')) {
    /** True once migration 011 has created the shift tables. */
    function shiftTableReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name IN ('cashier_shifts','shift_movements')"
            );
            $ready = (int) $stmt->fetchColumn() === 2;
        } catch (Throwable $e) {
            app_log('warning', 'Shift schema check failed: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('shiftCashMethodCodes')) {
    /**
     * Payment method codes that put physical notes in the drawer. Driven by
     * payment_methods.is_cash so configuration, not code, decides what is cash.
     */
    function shiftCashMethodCodes(PDO $pdo, int $companyId): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT code FROM payment_methods WHERE company_id = ? AND is_cash = 1 AND is_active = 1"
            );
            $stmt->execute([$companyId]);
            $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $codes = array_values(array_filter(array_map('strval', (array) $codes), static fn ($c) => $c !== ''));
            return $codes === [] ? ['cash'] : $codes;
        } catch (Throwable $e) {
            return ['cash'];
        }
    }
}

if (!function_exists('shiftIsCashMethod')) {
    function shiftIsCashMethod(PDO $pdo, string $code, int $companyId): bool
    {
        return in_array($code, shiftCashMethodCodes($pdo, $companyId), true);
    }
}

if (!function_exists('shiftResolveBranchId')) {
    /**
     * Branch a shift belongs to. A posted branch is honoured only when it
     * belongs to the caller's company and the user is scoped to it, so a
     * cashier cannot open a drawer against another branch.
     */
    function shiftResolveBranchId(PDO $pdo, int $companyId, int $requested = 0): int
    {
        $sessionBranch = function_exists('auth_branch_id') ? (int) auth_branch_id() : 0;

        if ($requested > 0) {
            if ($sessionBranch > 0 && $requested !== $sessionBranch) {
                if (!auth_branch_allowed($pdo, $requested)) {
                    throw new RuntimeException('You are not assigned to that branch.');
                }
            }
            $stmt = $pdo->prepare("SELECT branch_id FROM branches WHERE branch_id = ? AND company_id = ? AND is_active = 1");
            $stmt->execute([$requested, $companyId]);
            $id = $stmt->fetchColumn();
            if (!$id) {
                throw new RuntimeException('Branch not found for this company.');
            }
            return (int) $id;
        }

        if ($sessionBranch > 0) {
            return $sessionBranch;
        }

        $stmt = $pdo->prepare(
            "SELECT branch_id FROM branches WHERE company_id = ? AND is_active = 1 ORDER BY sort_order, branch_id LIMIT 1"
        );
        $stmt->execute([$companyId]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException('No active branch exists to open a shift against.');
        }
        return (int) $id;
    }
}

if (!function_exists('shiftOpen')) {
    /**
     * Open a register session for the given cashier. One open shift per
     * cashier+branch: the row is locked while checking so two devices cannot
     * both succeed.
     *
     * @return array{success:bool, shift_id:int, message:string}
     */
    function shiftOpen(PDO $pdo, int $userId, int $companyId, int $branchId, float $openingFloat, string $note = ''): array
    {
        if (!shiftTableReady($pdo)) {
            return ['success' => false, 'shift_id' => 0, 'message' => 'Shift tables are not installed. Run migrations.'];
        }
        if ($openingFloat < 0) {
            return ['success' => false, 'shift_id' => 0, 'message' => 'Opening float cannot be negative.'];
        }
        if ($userId <= 0) {
            return ['success' => false, 'shift_id' => 0, 'message' => 'A shift needs a signed-in cashier.'];
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT shift_id FROM cashier_shifts
                 WHERE company_id = ? AND branch_id = ? AND user_id = ? AND status = 'open' FOR UPDATE"
            );
            $stmt->execute([$companyId, $branchId, $userId]);
            if ($existing = (int) $stmt->fetchColumn()) {
                $pdo->rollBack();
                return ['success' => false, 'shift_id' => $existing, 'message' => 'A shift is already open for this cashier at this branch.'];
            }

            $pdo->prepare(
                "INSERT INTO cashier_shifts (company_id, branch_id, user_id, status, opening_float, opening_note, opened_at)
                 VALUES (?, ?, ?, 'open', ?, ?, NOW())"
            )->execute([$companyId, $branchId, $userId, round($openingFloat, 4), $note !== '' ? $note : null]);

            $shiftId = (int) $pdo->lastInsertId();
            // The float lives in cashier_shifts.opening_float, not in
            // shift_movements: duplicating it as a movement row would count
            // the same notes twice in Expected.
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('error', 'Shift open failed: ' . $e->getMessage());
            return ['success' => false, 'shift_id' => 0, 'message' => 'Unable to open the shift: ' . $e->getMessage()];
        }

        auth_audit($pdo, 'CREATE', 'cashier_shifts', $shiftId,
            'Opened shift with float ' . number_format($openingFloat, 2), $userId);

        return ['success' => true, 'shift_id' => $shiftId, 'message' => 'Shift opened.'];
    }
}

if (!function_exists('shiftGet')) {
    /** One shift row, company-isolated. */
    function shiftGet(PDO $pdo, int $shiftId, int $companyId): ?array
    {
        if (!shiftTableReady($pdo) || $shiftId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT s.*, u.full_name AS cashier_name, u.username AS cashier_username, b.name AS branch_name
             FROM cashier_shifts s
             LEFT JOIN users u ON u.user_id = s.user_id
             LEFT JOIN branches b ON b.branch_id = s.branch_id
             WHERE s.shift_id = ? AND s.company_id = ?"
        );
        $stmt->execute([$shiftId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}

if (!function_exists('shiftCurrentFor')) {
    /** The open shift for a cashier at a branch, or null when none is open. */
    function shiftCurrentFor(PDO $pdo, int $userId, int $companyId, int $branchId): ?array
    {
        if (!shiftTableReady($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM cashier_shifts
             WHERE company_id = ? AND branch_id = ? AND user_id = ? AND status = 'open'
             ORDER BY opened_at DESC LIMIT 1"
        );
        $stmt->execute([$companyId, $branchId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}

if (!function_exists('shiftCurrent')) {
    /** Open shift for the signed-in user in the branch the POS will sell from. */
    function shiftCurrent(PDO $pdo, int $companyId, int $branchId): ?array
    {
        return shiftCurrentFor($pdo, (int) ($_SESSION['user_id'] ?? 0), $companyId, $branchId);
    }
}

if (!function_exists('shiftRequireOpen')) {
    /**
     * Gate for POS checkout: returns the open shift or throws with the message
     * the cashier should see. No shift means no sale - the drawer must be able
     * to account for every note.
     */
    function shiftRequireOpen(PDO $pdo, int $userId, int $companyId, int $branchId): array
    {
        $shift = shiftCurrentFor($pdo, $userId, $companyId, $branchId);
        if (!$shift) {
            throw new RuntimeException('Open a cashier shift before taking payment. Use Shifts to open the register.');
        }
        return $shift;
    }
}

if (!function_exists('shiftTotals')) {
    /**
     * Rebuild a shift from rows: takings by payment method, the four terms of
     * the reconciliation formula, expected cash, and variance when counted.
     */
    function shiftTotals(PDO $pdo, array $shift): array
    {
        $shiftId = (int) $shift['shift_id'];
        $companyId = (int) $shift['company_id'];
        $cashCodes = shiftCashMethodCodes($pdo, $companyId);
        $placeholders = implode(',', array_fill(0, count($cashCodes), '?'));

        // Payments are attributed through customer_payments.shift_id, which is
        // written by createSalesInvoice()/recordPayment() while the sale happens.
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN cp.payment_method IN ($placeholders) THEN cp.amount ELSE 0 END), 0) AS cash_receipts,
                    COALESCE(SUM(cp.amount), 0) AS total_receipts,
                    COUNT(DISTINCT cp.invoice_id) AS payment_count
             FROM customer_payments cp WHERE cp.shift_id = ?"
        );
        $stmt->execute(array_merge($cashCodes, [$shiftId]));
        $receipts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare(
            "SELECT movement_type, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS entries
             FROM shift_movements WHERE shift_id = ? GROUP BY movement_type"
        );
        $stmt->execute([$shiftId]);
        $movements = ['float_adjust' => 0.0, 'paid_in' => 0.0, 'paid_out' => 0.0, 'cash_refund' => 0.0];
        $movementRows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $movements[$row['movement_type']] = (float) $row['total'];
            $movementRows[] = $row;
        }

        $float = round((float) $shift['opening_float'] + $movements['float_adjust'], 4);
        $cashReceipts = round((float) ($receipts['cash_receipts'] ?? 0), 4);
        $paidIn = round($movements['paid_in'], 4);
        $cashRefunds = round($movements['cash_refund'], 4);
        $paidOut = round($movements['paid_out'], 4);

        // Expected = Float + Cash Receipts + Paid In - Cash Refunds - Paid Out
        $expected = round($float + $cashReceipts + $paidIn - $cashRefunds - $paidOut, 4);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS sales, COALESCE(SUM(total_amount), 0) AS sales_value,
                    COALESCE(SUM(amount_paid), 0) AS collected, COALESCE(SUM(amount_owed), 0) AS outstanding
             FROM sales_invoices WHERE shift_id = ? AND status <> 'cancelled'"
        );
        $stmt->execute([$shiftId]);
        $sales = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare(
            "SELECT cp.payment_method, COALESCE(SUM(cp.amount), 0) AS total, COUNT(*) AS entries
             FROM customer_payments cp WHERE cp.shift_id = ?
             GROUP BY cp.payment_method ORDER BY total DESC"
        );
        $stmt->execute([$shiftId]);
        $byMethod = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $closing = $shift['closing_count'] === null ? null : round((float) $shift['closing_count'], 4);
        $variance = $closing === null ? null : round($closing - $expected, 4);

        return [
            'opening_float' => round((float) $shift['opening_float'], 4),
            'float_adjustments' => round($movements['float_adjust'], 4),
            'float' => $float,
            'cash_receipts' => $cashReceipts,
            'paid_in' => $paidIn,
            'cash_refunds' => $cashRefunds,
            'paid_out' => $paidOut,
            'expected_cash' => $expected,
            'closing_count' => $closing,
            'variance' => $variance,
            'total_receipts' => round((float) ($receipts['total_receipts'] ?? 0), 4),
            'non_cash_receipts' => round((float) ($receipts['total_receipts'] ?? 0) - $cashReceipts, 4),
            'payment_count' => (int) ($receipts['payment_count'] ?? 0),
            'sales_count' => (int) ($sales['sales'] ?? 0),
            'sales_value' => round((float) ($sales['sales_value'] ?? 0), 4),
            'collected' => round((float) ($sales['collected'] ?? 0), 4),
            'outstanding' => round((float) ($sales['outstanding'] ?? 0), 4),
            'by_method' => $byMethod,
            'movements' => $movementRows,
            'cash_codes' => $cashCodes,
        ];
    }
}

if (!function_exists('shiftPersistExpected')) {
    /** Store the computed figures so lists and reports do not recompute per row. */
    function shiftPersistExpected(PDO $pdo, int $shiftId, array $totals): void
    {
        $pdo->prepare("UPDATE cashier_shifts SET expected_cash = ? WHERE shift_id = ?")
            ->execute([$totals['expected_cash'], $shiftId]);
    }
}

if (!function_exists('shiftClose')) {
    /**
     * Close a shift against a counted drawer. A null count stores the figures
     * without asserting a physical count (used by a manager-forced close).
     *
     * @return array{success:bool, message:string, totals:array}
     */
    function shiftClose(PDO $pdo, int $shiftId, int $companyId, ?float $closingCount, string $note = '', bool $forceClose = false): array
    {
        $shift = shiftGet($pdo, $shiftId, $companyId);
        if (!$shift) {
            return ['success' => false, 'message' => 'Shift not found.', 'totals' => []];
        }
        if ($shift['status'] !== 'open') {
            return ['success' => false, 'message' => 'That shift is already closed.', 'totals' => []];
        }

        $actorId = (int) ($_SESSION['user_id'] ?? 0);
        if (!$forceClose && (int) $shift['user_id'] !== $actorId && !userHasPermission($pdo, $actorId, 'shifts', 'delete')) {
            return ['success' => false, 'message' => 'Only the cashier who opened this shift (or a manager) can close it.', 'totals' => []];
        }
        if ($closingCount !== null && $closingCount < 0) {
            return ['success' => false, 'message' => 'A drawer count cannot be negative.', 'totals' => []];
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT status FROM cashier_shifts WHERE shift_id = ? FOR UPDATE");
            $stmt->execute([$shiftId]);
            if ((string) $stmt->fetchColumn() !== 'open') {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'That shift is already closed.', 'totals' => []];
            }

            $totals = shiftTotals($pdo, $shift);
            $expected = $totals['expected_cash'];
            $variance = $closingCount === null ? null : round($closingCount - $expected, 4);

            $pdo->prepare(
                "UPDATE cashier_shifts
                 SET status = 'closed', closed_at = NOW(), expected_cash = ?, closing_count = ?, variance = ?,
                     closing_note = ?, force_closed = ?
                 WHERE shift_id = ?"
            )->execute([
                $expected,
                $closingCount,
                $variance,
                $note !== '' ? $note : null,
                $forceClose ? 1 : 0,
                $shiftId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('error', 'Shift close failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to close the shift: ' . $e->getMessage(), 'totals' => []];
        }

        auth_audit($pdo, 'UPDATE', 'cashier_shifts', $shiftId,
            ($forceClose ? 'Force-closed shift' : 'Closed shift')
            . ', expected ' . number_format($expected, 2)
            . ($closingCount === null ? ', not counted' : ', counted ' . number_format((float) $closingCount, 2)
                . ', variance ' . number_format((float) $variance, 2)),
            $actorId);

        return [
            'success' => true,
            'message' => 'Shift closed. Expected cash ' . number_format($expected, 2)
                . ($closingCount === null ? '.' : ', counted ' . number_format((float) $closingCount, 2)
                    . ', variance ' . number_format((float) $variance, 2) . '.'),
            'totals' => shiftTotals($pdo, shiftGet($pdo, $shiftId, $companyId) ?: $shift),
        ];
    }
}

if (!function_exists('shiftAddMovement')) {
    /**
     * Record cash that enters or leaves the drawer without being a sale
     * (paid in / paid out) or a refund given back at the counter.
     */
    function shiftAddMovement(PDO $pdo, int $shiftId, string $type, float $amount, int $companyId, string $reason = '', ?int $invoiceId = null): array
    {
        $allowed = ['paid_in', 'paid_out', 'cash_refund', 'float_adjust'];
        if (!in_array($type, $allowed, true)) {
            return ['success' => false, 'message' => 'Unknown movement type.'];
        }
        if ($type === 'float_adjust') {
            if (abs($amount) < 0.0001) {
                return ['success' => false, 'message' => 'A float adjustment must be non-zero.'];
            }
        } elseif ($amount <= 0) {
            return ['success' => false, 'message' => 'Enter an amount greater than zero.'];
        }

        $shift = shiftGet($pdo, $shiftId, $companyId);
        if (!$shift) {
            return ['success' => false, 'message' => 'Shift not found for this company.'];
        }
        if ($shift['status'] !== 'open') {
            return ['success' => false, 'message' => 'Movements can only be recorded on an open shift.'];
        }

        if ($invoiceId !== null) {
            $stmt = $pdo->prepare("SELECT company_id FROM sales_invoices WHERE invoice_id = ?");
            $stmt->execute([$invoiceId]);
            $ownerCompany = $stmt->fetchColumn();
            if ($ownerCompany === false || (int) $ownerCompany !== $companyId) {
                return ['success' => false, 'message' => 'The linked sale does not belong to this company.'];
            }
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        try {
            $pdo->prepare(
                "INSERT INTO shift_movements (shift_id, company_id, movement_type, amount, reason, reference_invoice_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$shiftId, $companyId, $type, round($amount, 4), $reason !== '' ? $reason : null, $invoiceId, $userId ?: null]);
        } catch (Throwable $e) {
            app_log('error', 'Shift movement failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to record the movement.'];
        }

        auth_audit($pdo, 'CREATE', 'shift_movements', $shiftId,
            ucfirst(str_replace('_', ' ', $type)) . ' ' . number_format($amount, 2)
            . ($reason !== '' ? ' (' . $reason . ')' : ''), $userId);

        return ['success' => true, 'message' => 'Movement recorded.', 'shift_id' => $shiftId];
    }
}

if (!function_exists('shiftVoidIfEmpty')) {
    /**
     * Delete a shift only when nothing touched the drawer. Sales, payments and
     * movements make a shift a financial record: those are closed, never voided.
     */
    function shiftVoidIfEmpty(PDO $pdo, int $shiftId, int $companyId): array
    {
        $shift = shiftGet($pdo, $shiftId, $companyId);
        if (!$shift) {
            return ['success' => false, 'message' => 'Shift not found.'];
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_invoices WHERE shift_id = ?");
        $stmt->execute([$shiftId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'This shift already has sales recorded - close it instead of voiding it.'];
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM shift_movements WHERE shift_id = ? AND movement_type <> 'float_adjust'");
        $stmt->execute([$shiftId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'This shift has drawer movements - close it instead of voiding it.'];
        }

        $pdo->prepare("DELETE FROM cashier_shifts WHERE shift_id = ? AND company_id = ?")->execute([$shiftId, $companyId]);
        auth_audit($pdo, 'DELETE', 'cashier_shifts', $shiftId, 'Voided empty shift', (int) ($_SESSION['user_id'] ?? 0));

        return ['success' => true, 'message' => 'Empty shift voided.'];
    }
}

if (!function_exists('shiftList')) {
    /**
     * Shift register with totals computed per row for the visible window.
     * Filters: status, user_id, branch_id, from, to (dates, inclusive).
     */
    function shiftList(PDO $pdo, int $companyId, array $filters = [], int $limit = 200): array
    {
        if (!shiftTableReady($pdo)) {
            return [];
        }

        $where = ['s.company_id = ?'];
        $args = [$companyId];

        if (($filters['status'] ?? '') === 'open' || ($filters['status'] ?? '') === 'closed') {
            $where[] = 's.status = ?';
            $args[] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 's.user_id = ?';
            $args[] = (int) $filters['user_id'];
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 's.branch_id = ?';
            $args[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 's.opened_at >= ?';
            $args[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 's.opened_at <= ?';
            $args[] = $filters['to'] . ' 23:59:59';
        }
        // Branch scope from Phase A: a user pinned to a branch sees only that drawer.
        if (empty($filters['branch_id']) && function_exists('auth_branch_id') && (int) auth_branch_id() > 0
            && !userHasPermission($pdo, (int) ($_SESSION['user_id'] ?? 0), 'shifts', 'delete')) {
            $where[] = 's.branch_id = ?';
            $args[] = (int) auth_branch_id();
        }

        $stmt = $pdo->prepare(
            'SELECT s.*, u.full_name AS cashier_name, u.username AS cashier_username, b.name AS branch_name
             FROM cashier_shifts s
             LEFT JOIN users u ON u.user_id = s.user_id
             LEFT JOIN branches b ON b.branch_id = s.branch_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY s.opened_at DESC LIMIT ' . max(1, min(1000, $limit))
        );
        $stmt->execute($args);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['totals'] = shiftTotals($pdo, $row);
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('shiftSalesForUser')) {
    /** Sales billed on a shift - used by the X/Z report and the POS receipt list. */
    function shiftSalesForUser(PDO $pdo, int $shiftId): array
    {
        $stmt = $pdo->prepare(
            "SELECT invoice_id, invoice_number, issue_date, total_amount, amount_paid, amount_owed,
                    payment_method, status, notes
             FROM sales_invoices WHERE shift_id = ? ORDER BY invoice_id DESC"
        );
        $stmt->execute([$shiftId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('shiftSetting')) {
    /** Company toggle: must a cashier hold an open shift to sell? */
    function shiftSetting(PDO $pdo, string $key, string $default = ''): string
    {
        if (!function_exists('salesSetting')) {
            require_once APP_ROOT . '/includes/sales_functions.php';
        }
        return salesSetting($pdo, $key, $default);
    }
}

if (!function_exists('shiftEnforcementEnabled')) {
    function shiftEnforcementEnabled(PDO $pdo): bool
    {
        return shiftSetting($pdo, 'pos_requires_shift', '1') !== '0';
    }
}

if (!function_exists('shiftSummaryForUser')) {
    /**
     * Compact shift state for the POS screen and its JSON status call.
     * Includes whether checkout is allowed right now and why not.
     */
    function shiftSummaryForUser(PDO $pdo, int $companyId, int $branchId): array
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $shift = shiftCurrentFor($pdo, $userId, $companyId, $branchId);
        $required = shiftEnforcementEnabled($pdo);

        if (!$shift) {
            return [
                'required' => $required,
                'open' => false,
                'allowed' => !$required,
                'reason' => $required
                    ? 'No shift is open for this cashier at this branch. Open one from Shifts before taking payment.'
                    : '',
                'shift_id' => 0,
                'branch_id' => $branchId,
                'opening_float' => 0.0,
                'opened_at' => null,
                'expected_cash' => 0.0,
                'sales_count' => 0,
                'cash_receipts' => 0.0,
            ];
        }

        $totals = shiftTotals($pdo, $shift);
        return [
            'required' => $required,
            'open' => true,
            'allowed' => true,
            'reason' => '',
            'shift_id' => (int) $shift['shift_id'],
            'branch_id' => (int) $shift['branch_id'],
            'opened_at' => $shift['opened_at'],
            'opening_float' => $totals['float'],
            'expected_cash' => $totals['expected_cash'],
            'sales_count' => $totals['sales_count'],
            'cash_receipts' => $totals['cash_receipts'],
            'cash_refunds' => $totals['cash_refunds'],
            'paid_in' => $totals['paid_in'],
            'paid_out' => $totals['paid_out'],
            'totals' => $totals,
        ];
    }
}
