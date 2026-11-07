<?php
/**
 * Barcode allocation, mapping and lookup (Phase B).
 *
 * Internal in-store codes use GS1-reserved prefix 2xx and are marked
 * source='system'; externally allocated GS1 codes (EAN/UPC printed on
 * supplier packaging) are marked source='gs1' so the two pools can never
 * be confused. The renderer lives in includes/barcode_lib.php.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!function_exists('bcEan13CheckDigit')) {
    function bcEan13CheckDigit(string $digits12): int
    {
        if (!preg_match('/^\d{12}$/', $digits12)) {
            throw new InvalidArgumentException('EAN-13 check digit requires exactly 12 digits.');
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $digits12[$i]) * ($i % 2 === 0 ? 1 : 3);
        }
        return (10 - ($sum % 10)) % 10;
    }
}

if (!function_exists('bcIsEan13')) {
    function bcIsEan13(string $code): bool
    {
        return preg_match('/^\d{13}$/', $code) === 1
            && bcEan13CheckDigit(substr($code, 0, 12)) === (int) $code[12];
    }
}

if (!function_exists('bcIsEan8')) {
    function bcIsEan8(string $code): bool
    {
        if (preg_match('/^\d{8}$/', $code) !== 1) {
            return false;
        }
        $sum = 0;
        $weights = [3, 1, 3, 1, 3, 1, 3];
        for ($i = 0; $i < 7; $i++) {
            $sum += ((int) $code[$i]) * $weights[$i];
        }
        return (10 - ($sum % 10)) % 10 === (int) $code[7];
    }
}

if (!function_exists('bcNormaliseGtin')) {
    /**
     * Validate + normalise an externally supplied product identifier.
     * @return array{ok:bool,code:string,symbology:string,message:string}
     */
    function bcNormaliseGtin(string $raw): array
    {
        $code = preg_replace('/[\s-]+/', '', trim($raw)) ?? '';
        $out = ['ok' => false, 'code' => $code, 'symbology' => 'other', 'message' => 'Barcode must be numeric (EAN-13/EAN-8/UPC) or 1-60 printable characters for Code 128.'];
        if ($code === '' || strlen($code) > 60) {
            $out['message'] = 'Barcode is empty or too long (max 60 characters).';
            return $out;
        }
        if (ctype_digit($code)) {
            if (strlen($code) === 13) {
                if (!bcIsEan13($code)) {
                    $out['message'] = 'That EAN-13 fails its check digit. Verify the printed number.';
                    return $out;
                }
                $out = ['ok' => true, 'code' => $code, 'symbology' => 'ean13', 'message' => ''];
                return $out;
            }
            if (strlen($code) === 8) {
                if (!bcIsEan8($code)) {
                    $out['message'] = 'That EAN-8 fails its check digit. Verify the printed number.';
                    return $out;
                }
                $out = ['ok' => true, 'code' => $code, 'symbology' => 'ean8', 'message' => ''];
                return $out;
            }
            if (strlen($code) === 12) {
                $out = ['ok' => true, 'code' => $code, 'symbology' => 'upc_a', 'message' => ''];
                return $out;
            }
            $out = ['ok' => true, 'code' => $code, 'symbology' => 'code128', 'message' => ''];
            return $out;
        }
        if (preg_match('/^[\x20-\x7E]+$/', $code) === 1) {
            $out = ['ok' => true, 'code' => $code, 'symbology' => 'code128', 'message' => ''];
            return $out;
        }
        return $out;
    }
}

if (!function_exists('bcGenerateInternalCode')) {
    /**
     * Deterministic in-store EAN-13: '2' + zero-padded product id (11 digits
     * incl. check digit). GS1 reserves the 200-299 range for internal use.
     */
    function bcGenerateInternalCode(int $productId): string
    {
        $base = '2' . str_pad((string) ($productId % 100000000000), 11, '0', STR_PAD_LEFT);
        return $base . bcEan13CheckDigit($base);
    }
}

if (!function_exists('bcEnsureProductBarcode')) {
    /**
     * Guarantee the product has a primary barcode + system alias row.
     * Returns the primary code.
     */
    function bcEnsureProductBarcode(PDO $pdo, int $productId, int $companyId): string
    {
        $stmt = $pdo->prepare('SELECT barcode FROM products WHERE product_id = ? AND company_id = ?');
        $stmt->execute([$productId, $companyId]);
        $code = $stmt->fetchColumn();
        if (is_string($code) && $code !== '') {
            bcSyncAliasRow($pdo, $productId, $companyId, $code, 'ean13', 'system');
            return $code;
        }

        $code = bcGenerateInternalCode($productId);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE barcode = ? AND product_id <> ?');
        $stmt->execute([$code, $productId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException("Generated barcode {$code} collides with another product.");
        }
        $pdo->prepare('UPDATE products SET barcode = ?, updated_at = NOW() WHERE product_id = ? AND company_id = ?')
            ->execute([$code, $productId, $companyId]);
        bcSyncAliasRow($pdo, $productId, $companyId, $code, 'ean13', 'system');
        return $code;
    }
}

if (!function_exists('bcSyncAliasRow')) {
    function bcSyncAliasRow(PDO $pdo, int $productId, int $companyId, string $code, string $symbology, string $source): void
    {
        $stmt = $pdo->prepare('SELECT barcode_id FROM product_barcodes WHERE code = ? AND company_id = ?');
        $stmt->execute([$code, $companyId]);
        $existing = (int) $stmt->fetchColumn();
        if ($existing > 0) {
            $pdo->prepare('UPDATE product_barcodes SET product_id = ?, symbology = ?, source = ?, is_active = 1, updated_at = NOW() WHERE barcode_id = ?')
                ->execute([$productId, $symbology, $source, $existing]);
            return;
        }
        $pdo->prepare('INSERT INTO product_barcodes (product_id, company_id, code, symbology, source) VALUES (?, ?, ?, ?, ?)')
            ->execute([$productId, $companyId, $code, $symbology, $source]);
    }
}

if (!function_exists('bcListForProduct')) {
    function bcListForProduct(PDO $pdo, int $productId, int $companyId): array
    {
        $stmt = $pdo->prepare(
            'SELECT pb.*, p.barcode AS primary_code FROM product_barcodes pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.product_id = ? AND pb.company_id = ?
             ORDER BY pb.is_active DESC, pb.barcode_id'
        );
        $stmt->execute([$productId, $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('bcAddCode')) {
    /**
     * Attach an additional scannable code to a product.
     * @return array{ok:bool,message:string,symbology?:string}
     */
    function bcAddCode(PDO $pdo, int $productId, int $companyId, string $rawCode, string $source = 'manual'): array
    {
        $norm = bcNormaliseGtin($rawCode);
        if (!$norm['ok']) {
            return ['ok' => false, 'message' => $norm['message']];
        }
        if (!in_array($source, ['gs1', 'manual', 'import'], true)) {
            $source = 'manual';
        }
        $stmt = $pdo->prepare(
            'SELECT pb.product_id FROM product_barcodes pb WHERE pb.code = ? AND pb.company_id = ?'
        );
        $stmt->execute([$norm['code'], $companyId]);
        $owner = $stmt->fetchColumn();
        if ($owner !== false && (int) $owner !== $productId) {
            return ['ok' => false, 'message' => 'That code is already assigned to another product.'];
        }

        $product = $pdo->prepare('SELECT product_id, barcode FROM products WHERE product_id = ? AND company_id = ?');
        $product->execute([$productId, $companyId]);
        $row = $product->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'Product not found in your company.'];
        }

        bcSyncAliasRow($pdo, $productId, $companyId, $norm['code'], $norm['symbology'], $norm['code'] === $row['barcode'] ? 'system' : $source);

        // First externally-allocated EAN-13 becomes the primary printed code.
        if ($norm['symbology'] === 'ean13' && (!is_string($row['barcode']) || str_starts_with((string) $row['barcode'], '2'))) {
            $pdo->prepare('UPDATE products SET barcode = ?, updated_at = NOW() WHERE product_id = ? AND company_id = ?')
                ->execute([$norm['code'], $productId, $companyId]);
        }
        barcodesAudit($pdo, 'CREATE', $productId, "Added code {$norm['code']} ({$norm['symbology']}, {$source})");
        return ['ok' => true, 'message' => 'Barcode ' . $norm['code'] . ' assigned.', 'symbology' => $norm['symbology']];
    }
}

if (!function_exists('bcRemoveCode')) {
    function bcRemoveCode(PDO $pdo, int $barcodeId, int $companyId): array
    {
        $stmt = $pdo->prepare(
            'SELECT pb.*, p.barcode AS primary_code FROM product_barcodes pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.barcode_id = ? AND pb.company_id = ?'
        );
        $stmt->execute([$barcodeId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'Barcode not found.'];
        }
        if ($row['code'] === $row['primary_code']) {
            return ['ok' => false, 'message' => 'This is the product\'s primary code. Assign a replacement first (set another code as primary).'];
        }
        $pdo->prepare('DELETE FROM product_barcodes WHERE barcode_id = ? AND company_id = ?')->execute([$barcodeId, $companyId]);
        barcodesAudit($pdo, 'DELETE', (int) $row['product_id'], "Removed code {$row['code']}");
        return ['ok' => true, 'message' => 'Barcode removed.'];
    }
}

if (!function_exists('bcSetPrimary')) {
    function bcSetPrimary(PDO $pdo, int $barcodeId, int $companyId): array
    {
        $stmt = $pdo->prepare(
            'SELECT pb.*, p.barcode AS primary_code FROM product_barcodes pb
             JOIN products p ON p.product_id = pb.product_id
             WHERE pb.barcode_id = ? AND pb.company_id = ?'
        );
        $stmt->execute([$barcodeId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'Barcode not found.'];
        }
        if ($row['code'] === $row['primary_code']) {
            return ['ok' => true, 'message' => 'That code is already primary.'];
        }
        $pdo->prepare('UPDATE products SET barcode = ?, updated_at = NOW() WHERE product_id = ? AND company_id = ?')
            ->execute([$row['code'], (int) $row['product_id'], $companyId]);
        // Keep alias table consistent: old primary row stays as an alternate code.
        $alt = $pdo->prepare('SELECT barcode_id FROM product_barcodes WHERE code = ? AND company_id = ?');
        $alt->execute([$row['primary_code'], $companyId]);
        if (!$alt->fetchColumn()) {
            bcSyncAliasRow($pdo, (int) $row['product_id'], $companyId, (string) $row['primary_code'], 'ean13', 'system');
        }
        barcodesAudit($pdo, 'UPDATE', (int) $row['product_id'], "Primary barcode set to {$row['code']}");
        return ['ok' => true, 'message' => 'Primary barcode updated.'];
    }
}

if (!function_exists('bcLookup')) {
    /**
     * Resolve a scanned/typed code to a product. Search order: primary
     * barcode, SKU, product id (internal identifiers), then the alias table.
     * $branchId restricts the reported stock figure to one branch.
     */
    function bcLookup(PDO $pdo, string $rawCode, int $companyId, ?int $branchId = null): ?array
    {
        $raw = trim($rawCode);
        $normalised = preg_replace('/[\s-]+/', '', $raw) ?? '';
        $candidates = array_values(array_unique(array_filter([$raw, $normalised], static fn ($v) => $v !== '')));
        if (!$candidates) {
            return null;
        }
        $numeric = ctype_digit($normalised);
        // Bind order mirrors textual order: join filter, company, code sets, id.
        $codes = implode(',', array_fill(0, count($candidates), '?'));
        $head = [$branchId, $branchId];
        $select = 'SELECT p.product_id, p.company_id, p.name, p.sku, p.barcode, p.sales_price, p.cost_price,
                          p.reorder_level, p.is_active, p.track_inventory,
                          COALESCE(SUM(pl.on_hand_quantity), 0) AS on_hand
                   FROM products p
                   LEFT JOIN product_locations pl ON pl.product_id = p.product_id
                          AND (? IS NULL OR pl.branch_id = ?)
                   WHERE p.company_id = ? AND (p.barcode IN (' . $codes . ') OR p.sku IN (' . $codes . ')';
        $params = array_merge($head, [$companyId], $candidates, $candidates);
        if ($numeric) {
            $select .= ' OR p.product_id = ?';
            $params[] = (int) $normalised;
        }
        $select .= ') GROUP BY p.product_id LIMIT 1';

        $stmt = $pdo->prepare($select);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['matched_by'] = in_array($row['barcode'], $candidates, true) ? 'barcode'
                : (in_array($row['sku'], $candidates, true) ? 'sku' : 'product_id');
            return $row;
        }

        $stmt = $pdo->prepare(
            'SELECT p.product_id, p.company_id, p.name, p.sku, p.barcode, p.sales_price, p.cost_price,
                    p.reorder_level, p.is_active, p.track_inventory,
                    pb.symbology, pb.source,
                    COALESCE(SUM(pl.on_hand_quantity), 0) AS on_hand
             FROM product_barcodes pb
             JOIN products p ON p.product_id = pb.product_id
             LEFT JOIN product_locations pl ON pl.product_id = p.product_id
                    AND (? IS NULL OR pl.branch_id = ?)
             WHERE pb.company_id = ? AND pb.is_active = 1 AND pb.code IN (' . $codes . ')
             GROUP BY p.product_id LIMIT 1'
        );
        $stmt->execute(array_merge($head, [$companyId], $candidates));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['matched_by'] = 'alias:' . $row['source'];
            return $row;
        }
        return null;
    }
}

if (!function_exists('bcRenderForProduct')) {
    /**
     * Render one code for a product. $symbology: auto|ean13|ean8|code128|code128c|qr.
     * @return array{ok:bool,svg?:string,code?:string,symbology?:string,message?:string}
     */
    function bcRenderForProduct(PDO $pdo, int $productId, int $companyId, string $code, string $symbology = 'auto'): array
    {
        $stmt = $pdo->prepare('SELECT name, barcode FROM products WHERE product_id = ? AND company_id = ?');
        $stmt->execute([$productId, $companyId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            return ['ok' => false, 'message' => 'Product not found.'];
        }
        if ($code === '') {
            $code = (string) ($product['barcode'] ?? '');
        }
        if ($code === '') {
            return ['ok' => false, 'message' => 'Product has no barcode yet.'];
        }
        if ($symbology === 'auto') {
            $symbology = bcIsEan13($code) ? 'ean13' : (bcIsEan8($code) ? 'ean8' : (ctype_digit($code) && strlen($code) % 2 === 0 && strlen($code) <= 48 ? 'code128c' : 'code128'));
        }
        require_once APP_ROOT . '/includes/barcode_lib.php';
        try {
            $svg = match ($symbology) {
                'ean13' => barcode_render_ean13_svg(str_pad($code, 13, '0', STR_PAD_LEFT)),
                'ean8' => barcode_render_ean8_svg($code),
                'code128c' => barcode_render_code128c_svg($code),
                'qr' => barcode_render_qr_svg($code),
                default => barcode_render_code128_svg($code),
            };
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Cannot render ' . $symbology . ': ' . $e->getMessage()];
        }
        return ['ok' => true, 'svg' => $svg, 'code' => $code, 'symbology' => $symbology];
    }
}

if (!function_exists('barcodesAudit')) {
    function barcodesAudit(PDO $pdo, string $action, int $productId, string $details): void
    {
        $allowed = ['CREATE', 'READ', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'EXPORT', 'IMPORT', 'PRINT'];
        if (!in_array($action, $allowed, true)) {
            $action = 'UPDATE';
        }
        // audit_log.user_id is NOT NULL with an FK, so an unattributed write
        // can never land. Say so instead of failing silently.
        $userId = (int) (($_SESSION['user_id'] ?? 0));
        if ($userId <= 0) {
            if (function_exists('app_log')) {
                app_log('warning', 'barcode change not audited (no authenticated user): product ' . $productId . ' - ' . $details);
            }
            return;
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_log (company_id, user_id, action, entity_type, entity_id, new_value, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                function_exists('currentCompanyId') ? currentCompanyId() : (int) ($_SESSION['company_id'] ?? 1),
                $userId,
                $action,
                'product_barcodes',
                $productId,
                json_encode(['product_id' => $productId, 'details' => $details]),
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);
        } catch (Throwable $e) {
            if (function_exists('app_log')) {
                app_log('error', 'barcode audit failed: ' . $e->getMessage());
            }
        }
    }
}
