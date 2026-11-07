<?php
/**
 * QR Code Scan-to-Pay: company-scoped pay points and their QR rendering.
 *
 * A pay point is not a payment itself. It is the sign a customer scans: a
 * destination (usually an https payment link from the merchant's provider)
 * plus the branch, tender and amount rules that belong with it. The QR is
 * rendered from the stored destination on demand, so the code on the wall can
 * never point somewhere the database does not say.
 *
 * Rules:
 *  - Points are company-scoped and never shared across companies.
 *  - Deactivation is the normal "removal"; a point may be deleted only when it
 *    has no payment history, so a printed code can still be explained later.
 *  - A fixed amount is appended to the destination as `amount`/`reference`
 *    query parameters. An open-amount code uses the destination unchanged.
 */

defined('QR_PAYMENTS_MODULE') || define('QR_PAYMENTS_MODULE', 'qr_payments');

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/barcode_lib.php';
require_once APP_ROOT . '/includes/audit_functions.php';

if (!function_exists('qrPaymentCodeIsValid')) {
    /**
     * Point code: lowercase letter first, then lowercase letters, digits,
     * underscores or hyphens, 2-40 characters. It is the stable handle used in
     * printed material, so it is deliberately narrower than a free-text name.
     */
    function qrPaymentCodeIsValid(string $code): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $code) === 1;
    }
}

if (!function_exists('qrPaymentUrlIsValid')) {
    /**
     * A scan destination must carry a URI scheme. http(s) links are checked by
     * the URL filter; other schemes (upi:, tel:, etc.) are accepted as long as
     * they are well-formed and contain no raw whitespace or control bytes.
     */
    function qrPaymentUrlIsValid(string $url): bool
    {
        if ($url === '' || strlen($url) > 500) {
            return false;
        }
        if (preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) !== 1) {
            return false;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        }
        return true;
    }
}

if (!function_exists('qrPaymentPointPayload')) {
    /**
     * The exact text encoded in the QR. A fixed amount is added to an http(s)
     * destination as query parameters, preserving an existing query string and
     * fragment; other URI schemes are left untouched because they do not share
     * the URL query convention.
     */
    function qrPaymentPointPayload(array $point): string
    {
        $url = trim((string) ($point['scan_url'] ?? ''));
        if ($url === '') {
            return '';
        }
        $isFixed = (string) ($point['amount_type'] ?? 'open') === 'fixed';
        $amount = (float) ($point['amount'] ?? 0);
        if (!$isFixed || $amount <= 0 || preg_match('#^https?://#i', $url) !== 1) {
            return $url;
        }

        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $separator = str_contains($url, '?') ? '&' : '?';
        $payload = $url . $separator . 'amount=' . number_format($amount, 2, '.', '');
        $reference = trim((string) ($point['merchant_reference'] ?? ''));
        if ($reference === '') {
            $reference = (string) ($point['code'] ?? '');
        }
        if ($reference !== '') {
            $payload .= '&reference=' . rawurlencode($reference);
        }
        return $payload . $fragment;
    }
}

if (!function_exists('qrPaymentPointSvg')) {
    /**
     * Render one point as an SVG QR code.
     *
     * @return array{ok:bool,svg:string,payload:string,message:string}
     */
    function qrPaymentPointSvg(array $point, array $opts = []): array
    {
        $payload = qrPaymentPointPayload($point);
        if ($payload === '') {
            return ['ok' => false, 'svg' => '', 'payload' => '', 'message' => 'This QR point has no scan destination.'];
        }

        $ecc = strtoupper((string) ($point['ecc'] ?? 'M'));
        if (!in_array($ecc, ['L', 'M', 'Q', 'H'], true)) {
            $ecc = 'M';
        }
        $opts['ecc'] = (string) ($opts['ecc'] ?? $ecc);

        try {
            $svg = barcode_render_qr_svg($payload, $opts);
            return ['ok' => true, 'svg' => $svg, 'payload' => $payload, 'message' => ''];
        } catch (Throwable $e) {
            app_log('warning', 'QR pay point render failed: ' . $e->getMessage());
            return [
                'ok' => false,
                'svg' => '',
                'payload' => $payload,
                'message' => 'The scan destination is too long, or cannot be encoded as a QR code at this error-correction level.',
            ];
        }
    }
}

if (!function_exists('qrPaymentPointAmountLabel')) {
    /** Human label for the amount rule: a formatted fixed amount or "Open amount". */
    function qrPaymentPointAmountLabel(array $point): string
    {
        if ((string) ($point['amount_type'] ?? 'open') === 'fixed' && (float) ($point['amount'] ?? 0) > 0) {
            return number_format((float) $point['amount'], 2);
        }
        return 'Open amount';
    }
}

if (!function_exists('getQrPaymentPoints')) {
    /**
     * All pay points for a company, newest sort order last. Joined names make
     * the list readable without a second query per row.
     */
    function getQrPaymentPoints(PDO $pdo, int $companyId, bool $activeOnly = false): array
    {
        $sql = 'SELECT q.*,
                       b.name AS branch_name,
                       b.code AS branch_code,
                       pm.name AS payment_method_name,
                       pm.code AS payment_method_code
                  FROM qr_payment_points q
                  LEFT JOIN branches b ON b.branch_id = q.branch_id
                  LEFT JOIN payment_methods pm ON pm.payment_method_id = q.payment_method_id
                 WHERE q.company_id = ?';
        if ($activeOnly) {
            $sql .= ' AND q.is_active = 1';
        }
        $sql .= ' ORDER BY q.sort_order, q.name, q.qr_point_id';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$companyId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            app_log('warning', 'QR pay point listing failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getQrPaymentPointById')) {
    /**
     * One point, scoped to the company so another company's code is invisible.
     */
    function getQrPaymentPointById(PDO $pdo, int $pointId, int $companyId): ?array
    {
        if ($pointId <= 0) {
            return null;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT q.*, b.name AS branch_name, pm.name AS payment_method_name
                   FROM qr_payment_points q
                   LEFT JOIN branches b ON b.branch_id = q.branch_id
                   LEFT JOIN payment_methods pm ON pm.payment_method_id = q.payment_method_id
                  WHERE q.qr_point_id = ? AND q.company_id = ?'
            );
            $stmt->execute([$pointId, $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            app_log('warning', 'QR pay point lookup failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('qrPaymentPointSnapshot')) {
    /** The fields worth keeping in the audit trail, as JSON. */
    function qrPaymentPointSnapshot(array $point): string
    {
        $fields = [
            'name', 'code', 'branch_id', 'payment_method_id', 'merchant_reference',
            'scan_url', 'amount_type', 'amount', 'ecc', 'description', 'is_active', 'sort_order',
        ];
        $snapshot = [];
        foreach ($fields as $field) {
            $snapshot[$field] = $point[$field] ?? null;
        }
        return (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('qrPaymentAudit')) {
    /** Audit helper that never lets a logging failure break the save. */
    function qrPaymentAudit(PDO $pdo, string $action, int $pointId, string $oldValue, string $newValue, ?int $userId, int $companyId): void
    {
        if (!function_exists('auditLog')) {
            return;
        }
        try {
            auditLog($pdo, $action, 'qr_payment_points', $pointId, $oldValue, $newValue, $userId, $companyId);
        } catch (Throwable $e) {
            app_log('warning', 'QR pay point audit failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('saveQrPaymentPoint')) {
    /**
     * Create or update a pay point. Returns:
     *   ['success' => bool, 'message' => string, 'point_id' => int]
     */
    function saveQrPaymentPoint(PDO $pdo, int $companyId, array $data, ?int $userId = null): array
    {
        $pointId = (int) ($data['qr_point_id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        $code = strtolower(trim((string) ($data['code'] ?? '')));
        $branchId = max(0, (int) ($data['branch_id'] ?? 0));
        $paymentMethodId = max(0, (int) ($data['payment_method_id'] ?? 0));
        $merchantReference = trim((string) ($data['merchant_reference'] ?? ''));
        $scanUrl = trim((string) ($data['scan_url'] ?? ''));
        $amountType = strtolower(trim((string) ($data['amount_type'] ?? 'open')));
        $amount = $amountType === 'fixed' ? round((float) ($data['amount'] ?? 0), 4) : null;
        $ecc = strtoupper(trim((string) ($data['ecc'] ?? 'M')));
        $description = trim((string) ($data['description'] ?? ''));
        $isActive = array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1;
        $sortOrder = max(0, min(9999, (int) ($data['sort_order'] ?? 0)));

        if ($name === '') {
            return ['success' => false, 'message' => 'A name is required so staff can identify the code.', 'point_id' => $pointId];
        }
        if (mb_strlen($name) > 100) {
            return ['success' => false, 'message' => 'The name must be 100 characters or fewer.', 'point_id' => $pointId];
        }
        if (!qrPaymentCodeIsValid($code)) {
            return [
                'success' => false,
                'message' => 'The code must start with a lowercase letter and use only lowercase letters, numbers, underscores or hyphens (2-40 characters).',
                'point_id' => $pointId,
            ];
        }
        if (!in_array($amountType, ['open', 'fixed'], true)) {
            return ['success' => false, 'message' => 'The amount type must be open or fixed.', 'point_id' => $pointId];
        }
        if ($amountType === 'fixed' && ($amount === null || $amount <= 0)) {
            return ['success' => false, 'message' => 'A fixed-amount QR point needs an amount greater than zero.', 'point_id' => $pointId];
        }
        if (!qrPaymentUrlIsValid($scanUrl)) {
            return [
                'success' => false,
                'message' => 'The scan destination must be a valid link, for example https://pay.example.com/checkout, up to 500 characters.',
                'point_id' => $pointId,
            ];
        }
        if (mb_strlen($merchantReference) > 120) {
            return ['success' => false, 'message' => 'The merchant reference must be 120 characters or fewer.', 'point_id' => $pointId];
        }
        if (mb_strlen($description) > 2000) {
            return ['success' => false, 'message' => 'The description must be 2,000 characters or fewer.', 'point_id' => $pointId];
        }
        if (!in_array($ecc, ['L', 'M', 'Q', 'H'], true)) {
            $ecc = 'M';
        }

        $existing = null;
        if ($pointId > 0) {
            $existing = getQrPaymentPointById($pdo, $pointId, $companyId);
            if ($existing === null) {
                return ['success' => false, 'message' => 'QR point not found in your company.', 'point_id' => 0];
            }
        }

        if ($branchId > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE branch_id = ? AND company_id = ?');
            $stmt->execute([$branchId, $companyId]);
            if ((int) $stmt->fetchColumn() === 0) {
                return ['success' => false, 'message' => 'The selected branch does not belong to your company.', 'point_id' => $pointId];
            }
        }
        if ($paymentMethodId > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment_methods WHERE payment_method_id = ? AND company_id = ?');
            $stmt->execute([$paymentMethodId, $companyId]);
            if ((int) $stmt->fetchColumn() === 0) {
                return ['success' => false, 'message' => 'The selected payment method does not belong to your company.', 'point_id' => $pointId];
            }
        }

        // The code is the printed handle: keep it unique per company, with a
        // friendly message rather than letting the unique index raise.
        $stmt = $pdo->prepare('SELECT qr_point_id FROM qr_payment_points WHERE company_id = ? AND code = ?');
        $args = [$companyId, $code];
        if ($pointId > 0) {
            $stmt = $pdo->prepare('SELECT qr_point_id FROM qr_payment_points WHERE company_id = ? AND code = ? AND qr_point_id <> ?');
            $args[] = $pointId;
        }
        $stmt->execute($args);
        if ($stmt->fetchColumn() !== false) {
            return ['success' => false, 'message' => 'A QR point with code "' . $code . '" already exists.', 'point_id' => $pointId];
        }

        // Prove the destination fits in a QR before storing it. The same
        // renderer is used by the preview, so a saved point cannot be one the
        // screen then refuses to draw.
        $probe = [
            'scan_url' => $scanUrl,
            'amount_type' => $amountType,
            'amount' => $amount,
            'merchant_reference' => $merchantReference,
            'code' => $code,
        ];
        try {
            barcode_qr_build(qrPaymentPointPayload($probe), $ecc);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'The scan destination is too long to fit in a QR code. Shorten the link or lower the error-correction level.',
                'point_id' => $pointId,
            ];
        }

        $actor = $userId !== null && $userId > 0 ? $userId : null;

        try {
            if ($pointId > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE qr_payment_points
                        SET name = ?, code = ?, branch_id = ?, payment_method_id = ?,
                            merchant_reference = ?, scan_url = ?, amount_type = ?, amount = ?,
                            ecc = ?, description = ?, is_active = ?, sort_order = ?, updated_by = ?
                      WHERE qr_point_id = ? AND company_id = ?'
                );
                $stmt->execute([
                    $name,
                    $code,
                    $branchId > 0 ? $branchId : null,
                    $paymentMethodId > 0 ? $paymentMethodId : null,
                    $merchantReference !== '' ? $merchantReference : null,
                    $scanUrl,
                    $amountType,
                    $amount,
                    $ecc,
                    $description !== '' ? $description : null,
                    $isActive,
                    $sortOrder,
                    $actor,
                    $pointId,
                    $companyId,
                ]);
                $saved = getQrPaymentPointById($pdo, $pointId, $companyId) ?? [];
                qrPaymentAudit($pdo, 'UPDATE', $pointId, qrPaymentPointSnapshot($existing), qrPaymentPointSnapshot($saved), $actor, $companyId);
                return ['success' => true, 'message' => 'QR point "' . $name . '" updated successfully.', 'point_id' => $pointId];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO qr_payment_points
                    (company_id, branch_id, payment_method_id, name, code, merchant_reference,
                     scan_url, amount_type, amount, ecc, description, is_active, sort_order, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $companyId,
                $branchId > 0 ? $branchId : null,
                $paymentMethodId > 0 ? $paymentMethodId : null,
                $name,
                $code,
                $merchantReference !== '' ? $merchantReference : null,
                $scanUrl,
                $amountType,
                $amount,
                $ecc,
                $description !== '' ? $description : null,
                $isActive,
                $sortOrder,
                $actor,
                $actor,
            ]);
            $pointId = (int) $pdo->lastInsertId();
            $saved = getQrPaymentPointById($pdo, $pointId, $companyId) ?? [];
            qrPaymentAudit($pdo, 'CREATE', $pointId, '', qrPaymentPointSnapshot($saved), $actor, $companyId);
            return ['success' => true, 'message' => 'QR point "' . $name . '" created successfully.', 'point_id' => $pointId];
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['success' => false, 'message' => 'A QR point with code "' . $code . '" already exists.', 'point_id' => $pointId];
            }
            app_log('warning', 'QR pay point save failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to save the QR point. Please try again.', 'point_id' => $pointId];
        }
    }
}

if (!function_exists('setQrPaymentPointActive')) {
    /**
     * Soft toggle. Returns the updated row, or null when the point is not in
     * this company.
     */
    function setQrPaymentPointActive(PDO $pdo, int $pointId, int $companyId, int $isActive, ?int $userId = null): ?array
    {
        $point = getQrPaymentPointById($pdo, $pointId, $companyId);
        if ($point === null) {
            return null;
        }

        $actor = $userId !== null && $userId > 0 ? $userId : null;
        try {
            $stmt = $pdo->prepare(
                'UPDATE qr_payment_points SET is_active = ?, updated_by = ?, updated_at = NOW()
                  WHERE qr_point_id = ? AND company_id = ?'
            );
            $stmt->execute([$isActive ? 1 : 0, $actor, $pointId, $companyId]);
            $updated = getQrPaymentPointById($pdo, $pointId, $companyId);
            if ($updated !== null) {
                qrPaymentAudit($pdo, 'UPDATE', $pointId, qrPaymentPointSnapshot($point), qrPaymentPointSnapshot($updated), $actor, $companyId);
            }
            return $updated;
        } catch (PDOException $e) {
            app_log('warning', 'QR pay point toggle failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('deleteQrPaymentPoint')) {
    /**
     * Hard delete. A point that has been used by a payment row should be
     * deactivated instead; the FK protects that case and this returns a
     * friendly message rather than a database error.
     *
     * @return array{success:bool,message:string}
     */
    function deleteQrPaymentPoint(PDO $pdo, int $pointId, int $companyId, ?int $userId = null): array
    {
        $point = getQrPaymentPointById($pdo, $pointId, $companyId);
        if ($point === null) {
            return ['success' => false, 'message' => 'QR point not found in your company.'];
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM qr_payment_points WHERE qr_point_id = ? AND company_id = ?');
            $stmt->execute([$pointId, $companyId]);
            qrPaymentAudit($pdo, 'DELETE', $pointId, qrPaymentPointSnapshot($point), '', $userId, $companyId);
            return ['success' => true, 'message' => 'QR point "' . (string) $point['name'] . '" deleted.'];
        } catch (PDOException $e) {
            app_log('warning', 'QR pay point delete failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'This QR point is in use and cannot be deleted. Deactivate it instead.'];
        }
    }
}
