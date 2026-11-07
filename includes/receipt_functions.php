<?php
/**
 * Phase E: Receipt presentation.
 *
 * A receipt is the same fact told to three audiences: the screen, a thermal
 * printer (58 or 80 mm) and a PDF download. They must never disagree, so the
 * data is assembled once by receiptPayload() and every layout reads that.
 *
 * What the slip says about money is deliberately the payment rows, not the
 * invoice header: customer_payments.amount is what was APPLIED to the bill and
 * tendered_amount is what the customer handed over, which is the only place the
 * change that went back across the counter is recorded. A header can only ever
 * say "split", so a slip built from the header cannot show a tender.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/sales_functions.php';

if (!function_exists('receiptLogoDirectory')) {
    /**
     * Absolute path of the logo store, created on demand. It lives under
     * storage/ on purpose: that tree is denied by storage/.htaccess, so a logo
     * can only reach a browser inside the receipt page it belongs to.
     */
    function receiptLogoDirectory(): string
    {
        $dir = APP_ROOT . '/storage/uploads/logos';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }
}

if (!function_exists('receiptSettings')) {
    /**
     * @return array{width:int, footer:string, show_logo:bool, logo_path:string}
     */
    function receiptSettings(PDO $pdo, int $companyId): array
    {
        $width = (int) getCompanySetting($pdo, 'receipt_paper_width', '80', $companyId);
        if (!isset(receiptPaperWidths()[(string) $width])) {
            $width = 80;
        }
        $footer = trim(getCompanySetting($pdo, 'receipt_footer_text', 'Please keep this receipt for your records.', $companyId));
        $showLogo = getCompanySetting($pdo, 'receipt_show_logo', '1', $companyId) === '1';
        $logoPath = trim(getCompanySetting($pdo, 'receipt_logo_path', '', $companyId));

        return ['width' => $width, 'footer' => $footer, 'show_logo' => $showLogo, 'logo_path' => $logoPath];
    }
}

if (!function_exists('receiptLogoFile')) {
    /** The stored logo as an absolute path, or null when there is nothing to show. */
    function receiptLogoFile(array $settings): ?string
    {
        if (!$settings['show_logo'] || $settings['logo_path'] === '') {
            return null;
        }
        $real = realpath(APP_ROOT . '/' . ltrim($settings['logo_path'], '/'));
        $store = realpath(receiptLogoDirectory());

        // A setting hand-edited to point outside the store is ignored.
        if ($real === false || $store === false || !str_starts_with($real, $store . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($real) ? $real : null;
    }
}

if (!function_exists('receiptLogoDataUri')) {
    /** Inline the logo so the receipt page needs no second request for it. */
    function receiptLogoDataUri(?string $file): string
    {
        if ($file === null) {
            return '';
        }
        $bytes = @file_get_contents($file);
        if ($bytes === false || $bytes === '') {
            return '';
        }

        return 'data:image/jpeg;base64,' . base64_encode($bytes);
    }
}

if (!function_exists('receiptSaveLogo')) {
    /**
     * Accept one uploaded picture and keep it as a JPEG flattened to tones of
     * gray inside the logo store. Re-encoding through GD is the point: whatever
     * a PNG or SVG carried is not copied, only pixels are, and a receipt
     * printer can render nothing but tone anyway.
     *
     * @return array{ok:bool,message:string}
     */
    function receiptSaveLogo(PDO $pdo, int $companyId, array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'No logo was uploaded.'];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            return ['ok' => false, 'message' => 'The logo must be a picture between 1 byte and 2 MB.'];
        }
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagefilter') || !function_exists('imagejpeg')) {
            return ['ok' => false, 'message' => 'This server has no GD extension, so a logo cannot be prepared safely.'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $info = $tmp !== '' ? @getimagesize($tmp) : false;
        if ($info === false) {
            return ['ok' => false, 'message' => 'That file is not a readable PNG, JPEG, GIF, WebP or BMP picture.'];
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < 32 || $height < 32 || $width > 3000 || $height > 3000) {
            return ['ok' => false, 'message' => 'A logo must measure between 32x32 and 3000x3000 pixels.'];
        }

        $source = @imagecreatefromstring((string) @file_get_contents($tmp));
        if (!$source) {
            return ['ok' => false, 'message' => 'The picture could not be decoded.'];
        }

        // Slips are narrow; 480 px is more than any layout can show, and
        // shrinking here keeps the receipt page small.
        $targetWidth = min(480, $width);
        $targetHeight = max(1, (int) round($height * $targetWidth / $width));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // transparency becomes paper
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagefilter($canvas, IMG_FILTER_GRAYSCALE);
        imagedestroy($source);

        $relative = 'storage/uploads/logos/company_' . $companyId . '.jpg';
        $absolute = APP_ROOT . '/' . $relative;
        $written = @imagejpeg($canvas, $absolute, 82);
        imagedestroy($canvas);
        if (!$written || !is_file($absolute)) {
            return ['ok' => false, 'message' => 'The logo could not be written. Check that storage/uploads/logos is writable.'];
        }

        updateCompanySettings($pdo, $companyId, ['receipt_logo_path' => $relative]);
        auth_audit($pdo, 'UPDATE', 'companies', $companyId, json_encode([
            'receipt_logo' => $relative,
            'replaced_pixels' => $width . 'x' . $height,
            'kept_pixels' => $targetWidth . 'x' . $targetHeight,
            'bytes' => (int) filesize($absolute),
        ], JSON_UNESCAPED_SLASHES));

        return ['ok' => true, 'message' => 'Receipt logo saved.'];
    }
}

if (!function_exists('receiptDeleteLogo')) {
    /** @return array{ok:bool,message:string} */
    function receiptDeleteLogo(PDO $pdo, int $companyId): array
    {
        $settings = receiptSettings($pdo, $companyId);
        if ($settings['logo_path'] !== '') {
            $real = realpath(APP_ROOT . '/' . ltrim($settings['logo_path'], '/'));
            if ($real !== false && is_file($real)) {
                @unlink($real);
            }
        }
        updateCompanySettings($pdo, $companyId, ['receipt_logo_path' => '']);
        auth_audit($pdo, 'DELETE', 'companies', $companyId, json_encode(['receipt_logo' => 'removed'], JSON_UNESCAPED_SLASHES));

        return ['ok' => true, 'message' => 'Receipt logo removed.'];
    }
}

if (!function_exists('receiptTenderRows')) {
    /**
     * The payment rows of one invoice, with the change each row handed back.
     * Change is a cash-counter event: a row whose method is not cash shows the
     * money it took and nothing returned. A row with no tendered_amount is a
     * payment recorded away from a counter (a receivables receipt, a sale from
     * before this column existed) and is shown as applied only.
     *
     * @return array<int,array<string,mixed>>
     */
    function receiptTenderRows(PDO $pdo, int $invoiceId): array
    {
        $rows = [];
        try {
            $stmt = $pdo->prepare("
                SELECT cp.payment_id, cp.amount, cp.tendered_amount, cp.payment_method,
                       cp.reference_number, cp.created_at, pm.name AS method_name,
                       pm.is_cash, pm.is_online
                FROM customer_payments cp
                LEFT JOIN payment_methods pm ON pm.company_id = cp.company_id AND pm.code = cp.payment_method
                WHERE cp.invoice_id = ?
                ORDER BY cp.payment_id
            ");
            $stmt->execute([$invoiceId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $applied = round((float) $row['amount'], 2);
                $tendered = $row['tendered_amount'] === null ? null : round((float) $row['tendered_amount'], 2);
                $isCash = (int) ($row['is_cash'] ?? 0) === 1;
                $rows[] = [
                    'payment_id' => (int) $row['payment_id'],
                    'method' => (string) $row['payment_method'],
                    'label' => trim((string) ($row['method_name'] ?? '')) !== ''
                        ? (string) $row['method_name']
                        : ucfirst(str_replace('_', ' ', (string) $row['payment_method'])),
                    'is_cash' => $isCash,
                    'is_online' => (int) ($row['is_online'] ?? 0) === 1,
                    'applied' => $applied,
                    'handed' => $tendered,
                    // Only notes coming across the counter are counted back. A
                    // transfer or gateway row that settled for less than it was
                    // sent for returns nothing here, and saying otherwise would
                    // be the slip inventing a payout.
                    'change' => ($tendered === null || !$isCash) ? 0.0 : round(max(0.0, $tendered - $applied), 2),
                    'reference' => (string) ($row['reference_number'] ?? ''),
                    'recorded_at' => (string) ($row['created_at'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            app_log('warning', 'Receipt tender rows unreadable: ' . $e->getMessage());
        }

        return $rows;
    }
}

if (!function_exists('receiptPayload')) {
    /**
     * Everything one slip needs. Returns null when the invoice is not in this
     * company, so a caller can redirect instead of printing a stranger's sale.
     *
     * @return array<string,mixed>|null
     */
    function receiptPayload(PDO $pdo, int $companyId, int $invoiceId, string $format = 'html'): ?array
    {
        $sale = $invoiceId > 0 ? getSaleById($pdo, $invoiceId) : null;
        if (!$sale || (int) $sale['company_id'] !== $companyId) {
            return null;
        }

        $company = getCompanyById($pdo, $companyId) ?: getCurrentCompany($pdo);
        $branch = !empty($sale['branch_id']) ? getBranchById($pdo, (int) $sale['branch_id']) : null;
        $user = function_exists('auth_user') ? (array) auth_user() : [];
        $cashier = trim((string) ($sale['created_by_name'] ?? ''));
        if ($cashier === '') {
            $cashier = trim((string) ($user['full_name'] ?? $user['username'] ?? ''));
        }

        $settings = receiptSettings($pdo, $companyId);
        $tenders = receiptTenderRows($pdo, $invoiceId);
        $handed = 0.0;
        $changeTotal = 0.0;
        $tenderedKnown = false;
        foreach ($tenders as $tender) {
            if ($tender['handed'] !== null) {
                $tenderedKnown = true;
                $handed = round($handed + (float) $tender['handed'], 2);
            }
            $changeTotal = round($changeTotal + (float) $tender['change'], 2);
        }
        $allCash = $tenders === [] || count(array_filter($tenders, static fn ($t) => !$t['is_cash'])) === 0;
        if (!$tenderedKnown) {
            // Nothing recorded what came across the counter. A single cash
            // payment over the bill is the one case where the overpay can be
            // inferred, and it is inferred rather than invented for a transfer.
            $handed = round((float) $sale['amount_paid'], 2);
            $changeTotal = $allCash ? round(max(0.0, $handed - (float) $sale['total_amount']), 2) : 0.0;
        }

        $logoFile = receiptLogoFile($settings);

        // The invoice's stored currency is authoritative: it was fixed when the
        // sale was made. Only fall back to the customer/country rule for old
        // rows created before currency was stored on the invoice.
        $companyCurrencyCode = strtoupper(trim((string) ($company['currency'] ?? getCompanyCurrency($pdo))));
        $saleCurrencyCode = strtoupper(trim((string) ($sale['currency'] ?? '')));
        if ($saleCurrencyCode === '') {
            $saleCurrencyCode = function_exists('customerCurrencyCode')
                ? customerCurrencyCode(
                    ['currency' => $sale['customer_currency'] ?? null, 'country' => $sale['customer_country'] ?? null],
                    $companyCurrencyCode
                  )
                : $companyCurrencyCode;
        }
        $exchangeRate = (float) ($sale['exchange_rate'] ?? 1);
        if ($exchangeRate <= 0) {
            $exchangeRate = 1.0;
        }

        return [
            'company' => [
                'name' => trim((string) ($company['name'] ?? APP_NAME)),
                'address' => trim((string) ($company['address'] ?? '')),
                'phone' => trim((string) ($company['phone'] ?? '')),
                'email' => trim((string) ($company['email'] ?? '')),
                'tax_id' => trim((string) ($company['tax_id'] ?? '')),
                'currency' => $saleCurrencyCode,
                'base_currency' => $companyCurrencyCode,
                'exchange_rate' => $exchangeRate,
            ],
            'branch' => trim((string) ($branch['name'] ?? '')),
            'cashier' => $cashier,
            'printed_by' => trim((string) ($user['full_name'] ?? $user['username'] ?? '')),
            'customer' => trim((string) ($sale['customer_name'] ?? '')) ?: 'Walk-in Customer',
            'number' => (string) ($sale['invoice_number'] ?? ''),
            'sale_id' => (int) $sale['invoice_id'],
            'status' => (string) ($sale['status'] ?? ''),
            'issued_at' => (string) (($sale['created_at'] ?? '') !== '' ? $sale['created_at'] : ($sale['issue_date'] ?? 'now')),
            'reference' => (string) ($sale['reference_number'] ?? ''),
            'notes' => trim((string) ($sale['notes'] ?? '')),
            'lines' => array_map(static fn (array $item): array => [
                'description' => trim((string) ($item['description'] ?? $item['product_name'] ?? 'Item')),
                'sku' => trim((string) ($item['sku'] ?? '')),
                'quantity' => round((float) ($item['quantity'] ?? 0), 2),
                'unit_price' => round((float) ($item['unit_price'] ?? 0), 2),
                'discount' => round((float) ($item['discount_amount'] ?? 0), 2),
                'line_total' => round((float) ($item['line_total'] ?? 0), 2),
            ], is_array($sale['items'] ?? null) ? $sale['items'] : []),
            'summary' => [
                'subtotal' => round((float) $sale['subtotal'], 2),
                'discount' => round((float) $sale['discount_amount'], 2),
                'tax' => round((float) $sale['tax_amount'], 2),
                'total' => round((float) $sale['total_amount'], 2),
                'paid' => round((float) $sale['amount_paid'], 2),
                'balance' => round((float) $sale['amount_owed'], 2),
            ],
            'tenders' => $tenders,
            'handed' => $handed,
            'change' => $changeTotal,
            'cash_only' => $allCash,
            'tendered_recorded' => $tenderedKnown,
            'print' => [
                'width' => $settings['width'],
                'footer' => $settings['footer'],
                'logo_uri' => receiptLogoDataUri($logoFile),
                'logo_file' => $logoFile,
                'reprint' => receiptIsReprint($pdo, $invoiceId),
                'printed_at' => date('Y-m-d H:i:s'),
                'format' => $format,
            ],
        ];
    }
}

if (!function_exists('receiptIsReprint')) {
    /** True once this slip has been printed or downloaded before. */
    function receiptIsReprint(PDO $pdo, int $invoiceId): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM audit_log
                WHERE action = 'PRINT' AND entity_type = 'sales_invoices' AND entity_id = ?
            ");
            $stmt->execute([$invoiceId]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('receiptAuditPrint')) {
    /**
     * The evidence trail: who produced this slip, in what form, on what paper,
     * and whether an earlier print of the same sale already exists.
     */
    function receiptAuditPrint(PDO $pdo, array $payload, bool $reprint): void
    {
        auth_audit($pdo, 'PRINT', 'sales_invoices', (int) $payload['sale_id'], json_encode([
            'invoice_number' => $payload['number'],
            'format' => $payload['print']['format'],
            'paper_mm' => $payload['print']['width'],
            'reprint' => $reprint,
            'total' => $payload['summary']['total'],
            'paid' => $payload['summary']['paid'],
            'change' => $payload['change'],
            'tenders' => count($payload['tenders']),
        ], JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('receiptStatusLabel')) {
    function receiptStatusLabel(string $status): string
    {
        $labels = [
            'paid' => 'Paid',
            'partial' => 'Partially paid',
            'pending' => 'Payment pending',
            'draft' => 'Draft',
            'cancelled' => 'Cancelled',
        ];

        return $labels[$status] ?? ($status === '' ? '' : ucfirst($status));
    }
}

if (!function_exists('receiptMoney')) {
    function receiptMoney(float $value): string
    {
        return number_format($value, 2);
    }
}

if (!function_exists('receiptWrap')) {
    /**
     * Break text into lines no wider than the paper. Words are kept whole where
     * they fit and cut only when a single one is longer than the roll, which is
     * what a thermal printer does and what makes an address readable.
     */
    function receiptWrap(string $text, int $cols): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $paragraph) {
            $paragraph = trim(preg_replace('/\s+/u', ' ', $paragraph));
            if ($paragraph === '') {
                continue;
            }
            $line = '';
            foreach (explode(' ', $paragraph) as $word) {
                while (mb_strlen($word) > $cols) {          // a word longer than the paper
                    $out[] = $line !== '' ? $line : mb_substr($word, 0, $cols);
                    if ($line !== '') {
                        $line = '';
                    }
                    $word = mb_substr($word, $cols);
                }
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if (mb_strlen($candidate) <= $cols) {
                    $line = $candidate;
                } else {
                    $out[] = $line;
                    $line = $word;
                }
            }
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }
}

if (!function_exists('receiptColumns')) {
    /** Characters that fit one line at this paper width and point size (Courier is 0.6em). */
    function receiptColumns(int $widthMm, float $size): int
    {
        $usable = ($widthMm - 8.0) * 72 / 25.4;

        return max(20, (int) floor($usable / ($size * 0.6)));
    }
}

if (!function_exists('receiptTextLines')) {
    /**
     * The slip as a plain-text page: what a thermal print looks like and the
     * body of the PDF. 58 mm and 80 mm get their own column count, so a long
     * item name wraps instead of running off the paper.
     *
     * @return array{cols:int, size:float, lines:array<int,array{text:string,align:string,bold:bool}>}
     */
    function receiptTextLines(array $payload, int $widthMm = 80): array
    {
        $size = $widthMm >= 80 ? 9.0 : 7.5;
        $cols = receiptColumns($widthMm, $size);
        $lines = [];

        $add = static function (string $text, string $align = 'left', bool $bold = false) use (&$lines): void {
            $lines[] = ['text' => $text, 'align' => $align, 'bold' => $bold];
        };
        $pair = static function (string $left, string $right, bool $bold = false) use (&$add, $cols): void {
            $left = mb_substr($left, 0, max(1, $cols - 2));
            $right = mb_substr($right, 0, max(1, $cols - 2));
            $room = $cols - mb_strlen($left) - mb_strlen($right);
            if ($room >= 1) {
                $add($left . str_repeat(' ', $room) . $right, 'left', $bold);
            } else {
                $add($left, 'left', $bold);
                $add($right, 'right', $bold);
            }
        };
        $para = static function (string $text, string $align = 'left') use (&$add, $cols): void {
            foreach (receiptWrap($text, $cols) as $chunk) {
                $add($chunk, $align);
            }
        };

        $company = $payload['company'];
        foreach (receiptWrap($company['name'], $cols) as $chunk) {
            $add(mb_strtoupper($chunk), 'center', true);
        }
        if ($company['address'] !== '') {
            $para($company['address'], 'center');
        }
        foreach (['Tel: ' => $company['phone'], '' => $company['email'], 'RC / Tax ID: ' => $company['tax_id'], 'Currency: ' => $company['currency']] as $prefix => $value) {
            if (trim((string) $value) !== '') {
                $para($prefix . trim((string) $value), 'center');
            }
        }

        $add('');
        $add(str_repeat('=', $cols));
        $add(mb_strtoupper($payload['print']['reprint'] ? 'REPRINT - SALES RECEIPT' : 'SALES RECEIPT'), 'center', true);
        $add(str_repeat('=', $cols));

        $pair('Receipt no.', $payload['number']);
        $pair('Date', date('d M Y H:i', strtotime($payload['issued_at'])));
        if ($payload['cashier'] !== '') {
            $pair('Cashier', $payload['cashier']);
        }
        if ($payload['branch'] !== '') {
            $pair('Branch', $payload['branch']);
        }
        $pair('Customer', $payload['customer']);
        if ($payload['reference'] !== '') {
            $pair('Reference', $payload['reference']);
        }

        $add('');
        $add(str_repeat('-', $cols));
        $pair('Item', 'Amount', true);
        foreach ($payload['lines'] as $line) {
            $name = (string) $line['description'];
            if ($line['sku'] !== '') {
                $name .= ' [' . $line['sku'] . ']';
            }
            $para($name);
            $detail = sprintf('%s x %s',
                rtrim(rtrim(number_format((float) $line['quantity'], 2), '0'), '.'),
                receiptMoney((float) $line['unit_price']));
            $pair('  ' . $detail, receiptMoney((float) $line['line_total']));
            if ((float) $line['discount'] > 0) {
                $pair('  Discount', '-' . receiptMoney((float) $line['discount']));
            }
        }
        if ($payload['lines'] === []) {
            $add('No line items recorded.');
        }
        $add(str_repeat('-', $cols));

        $summary = $payload['summary'];
        $pair('Subtotal', receiptMoney((float) $summary['subtotal']));
        if ((float) $summary['discount'] > 0) {
            $pair('Discount', '-' . receiptMoney((float) $summary['discount']));
        }
        if ((float) $summary['tax'] > 0) {
            $pair('Tax', receiptMoney((float) $summary['tax']));
        }
        $add(str_repeat('=', $cols));
        $pair('TOTAL', receiptMoney((float) $summary['total']), true);
        $add(str_repeat('=', $cols));

        $add('');
        foreach ($payload['tenders'] as $tender) {
            $label = $tender['label'];
            if ($tender['reference'] !== '') {
                $label .= ' #' . $tender['reference'];
            }
            $pair($label, receiptMoney((float) $tender['applied']));
            if ($tender['handed'] !== null && abs((float) $tender['handed'] - (float) $tender['applied']) > 0.004) {
                $pair('  Handed over', receiptMoney((float) $tender['handed']));
                if ((float) $tender['change'] > 0) {
                    $pair('  Change returned', receiptMoney((float) $tender['change']));
                }
            }
        }
        if ($payload['tenders'] === []) {
            $pair('Amount paid', receiptMoney((float) $summary['paid']));
        }
        if ((float) $payload['change'] > 0) {
            $pair('CHANGE DUE', receiptMoney((float) $payload['change']), true);
        }
        if ((float) $summary['balance'] > 0) {
            $pair('Balance due', receiptMoney((float) $summary['balance']), true);
        }

        $status = receiptStatusLabel((string) $payload['status']);
        if ($status !== '') {
            $add('');
            $add(mb_strtoupper($status), 'center', true);
        }
        if ($payload['notes'] !== '') {
            $add('');
            $para($payload['notes']);
        }

        $add('');
        $add('Thank you for your purchase!', 'center', true);
        $para($payload['print']['footer'], 'center');
        $add('');
        $footerBits = $payload['number'] . ' | ' . $widthMm . ' mm';
        if ($payload['print']['reprint']) {
            $footerBits .= ' | reprinted' . ($payload['printed_by'] !== '' ? ' by ' . $payload['printed_by'] : '');
        }
        $para($footerBits, 'center');

        return ['cols' => $cols, 'size' => $size, 'lines' => $lines];
    }
}

if (!function_exists('receiptPdfBytes')) {
    /**
     * A dependency-free PDF of the slip. No PDF library is installed on this
     * host and a receipt is text, so the writer stays small: Courier pages at
     * the exact thermal width, one object per piece, and a cross-reference
     * table whose offsets are measured while the file is written. The logo, when
     * there is one, is the stored grayscale JPEG passed through unchanged.
     */
    function receiptPdfBytes(array $payload): string
    {
        $widthMm = max(40, min(210, (int) $payload['print']['width']));
        $layout = receiptTextLines($payload, $widthMm);
        $size = $layout['size'];
        $leading = round($size * 1.45, 3);
        $margin = round(4.0 * 72 / 25.4, 3);
        $pageWidth = round($widthMm * 72 / 25.4, 3);
        $maxStrip = round(297.0 * 72 / 25.4, 3);

        $logo = null;
        $logoFile = $payload['print']['logo_file'] ?? null;
        if (is_string($logoFile) && $logoFile !== '' && is_file($logoFile)) {
            $raw = @file_get_contents($logoFile);
            $info = $raw === false ? false : @getimagesizefromstring($raw);
            if ($raw !== false && $raw !== '' && is_array($info) && (int) ($info[2] ?? 0) === IMAGETYPE_JPEG) {
                $drawWidth = round(min($pageWidth - 2 * $margin, 30.0 * 72 / 25.4), 3);
                $logo = [
                    'bytes' => $raw,
                    'width' => (int) $info[0],
                    'height' => (int) $info[1],
                    // A JPEG carries its own colour information, so the object
                    // that names it must agree with the picture inside. Claiming
                    // DeviceGray for a three-component file is the kind of
                    // mismatch a viewer renders as noise or refuses outright.
                    'colorspace' => ((int) ($info['channels'] ?? 3) === 1) ? '/DeviceGray' : '/DeviceRGB',
                    'draw_width' => $drawWidth,
                    'draw_height' => round($drawWidth * (int) $info[1] / max(1, (int) $info[0]), 3),
                ];
            }
        }

        // Split the text into strips that fit, then give each its own page.
        $pages = [];
        $current = [];
        $used = $margin + ($logo !== null ? $logo['draw_height'] + $leading : 0);
        foreach ($layout['lines'] as $line) {
            if ($used + $leading + $margin > $maxStrip) {
                $pages[] = $current;
                $current = [];
                $used = 0;
            }
            $used += $leading;
            $current[] = $line;
        }
        if ($current !== []) {
            $pages[] = $current;
        }
        if ($pages === []) {
            $pages[] = [['text' => 'Receipt has no content.', 'align' => 'left', 'bold' => false]];
        }

        // Object ids are issued in one run so the cross-reference table has no
        // holes: 1 catalogue, 2 page tree, 3/4 the two Courier faces, 5 the logo
        // when there is one, then each page and its content stream.
        $objects = [];
        $pageIds = [];
        $imageId = $logo !== null ? 5 : 0;
        $nextId = $logo !== null ? 6 : 5;
        foreach ($pages as $index => $pageLines) {
            $pageHeight = round($margin * 2 + $leading * count($pageLines) + ($index === 0 && $logo !== null ? $logo['draw_height'] : 0), 3);
            $stream = '';
            if ($index === 0 && $logo !== null) {
                $stream .= sprintf(
                    "q %F 0 0 %F %F %F cm /Im0 Do Q\n",
                    $logo['draw_width'],
                    $logo['draw_height'],
                    $margin,
                    $pageHeight - $margin - $logo['draw_height']
                );
            }
            $baseline = $pageHeight - $margin;
            if ($index === 0 && $logo !== null) {
                $baseline -= $logo['draw_height'] + $leading;
            }
            foreach ($pageLines as $line) {
                $text = (string) $line['text'];
                $glyphs = mb_strlen($text);
                $advance = 0.6 * $size;
                $x = $margin;
                if ($line['align'] === 'center') {
                    $x = $margin + max(0.0, ($pageWidth - 2 * $margin - $glyphs * $advance) / 2);
                } elseif ($line['align'] === 'right') {
                    $x = max($margin, $pageWidth - $margin - $glyphs * $advance);
                }
                $stream .= sprintf(
                    "BT %s %F Tf 1 0 0 1 %F %F Tm (%s) Tj ET\n",
                    $line['bold'] ? '/F2' : '/F1',
                    $size,
                    round(max(0.0, $x), 3),
                    round($baseline, 3),
                    pdfEscapeString($text)
                );
                $baseline -= $leading;
            }

            $pageId = $nextId;
            $nextId += 2;
            $pageIds[] = $pageId;
            $xObjects = $index === 0 && $logo !== null ? sprintf(' /XObject << /Im0 %d 0 R >>', $imageId) : '';
            $objects[$pageId] = [
                'dict' => sprintf(
                    "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %F %F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>",
                    $pageWidth,
                    $pageHeight,
                    $xObjects,
                    $pageId + 1
                ),
            ];
            $objects[$pageId + 1] = ['dict' => sprintf('<< /Length %d >>', strlen($stream)), 'stream' => $stream];
        }

        $objects[1] = ['dict' => '<< /Type /Catalog /Pages 2 0 R >>'];
        $objects[2] = [
            'dict' => sprintf('<< /Type /Pages /Kids [%s] /Count %d >>',
                implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds)),
                count($pageIds)),
        ];
        $objects[3] = ['dict' => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>'];
        $objects[4] = ['dict' => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>'];
        if ($logo !== null) {
            $objects[5] = [
                'dict' => sprintf(
                    '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>',
                    $logo['width'],
                    $logo['height'],
                    $logo['colorspace'],
                    strlen($logo['bytes'])
                ),
                'stream' => $logo['bytes'],
            ];
        }
        ksort($objects);

        return pdfWriteDocument($objects);
    }
}

if (!function_exists('pdfWriteDocument')) {
    /**
     * Emit objects in id order and record each offset while writing, so the
     * cross-reference table cannot disagree with the file it describes.
     *
     * @param array<int,array{dict:string,stream?:string}> $objects
     */
    function pdfWriteDocument(array $objects): string
    {
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $object) {
            $offsets[(int) $id] = strlen($out);
            $out .= sprintf("%d 0 obj\n%s\n", (int) $id, $object['dict']);
            if (array_key_exists('stream', $object)) {
                $out .= "stream\n" . $object['stream'] . "endstream\n";
            }
            $out .= "endobj\n";
        }

        $maxId = max(array_keys($offsets));
        $xrefStart = strlen($out);
        $out .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $out .= sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", $maxId + 1, $xrefStart);

        return $out;
    }
}

if (!function_exists('pdfEscapeString')) {
    /** PDF literal strings are Latin-1: escape the three specials, drop what cannot be drawn. */
    function pdfEscapeString(string $text): string
    {
        $converted = (string) @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $converted);
    }
}

if (!function_exists('receiptPdfPages')) {
    /**
     * Parse what receiptPdfBytes() produced the way a reader would: walk the
     * xref offsets, confirm each points at its object, and lift the text back
     * out of the content streams. Verification, not layout.
     *
     * @return array{pages:int, offsets_ok:bool, text:string}
     */
    function receiptPdfPages(string $pdf): array
    {
        $ok = str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, "%%EOF\n");
        $offsets = [];
        if (preg_match('/xref\n0 (\d+)\n(.*?)\ntrailer/s', $pdf, $match)) {
            foreach (array_slice(preg_split('/\n/', trim($match[2])), 1) as $row) {
                $offsets[] = (int) trim((string) preg_replace('/\s.*$/', '', $row));
            }
        }
        $verified = $offsets !== [];
        foreach ($offsets as $index => $offset) {
            $id = $index + 1;
            if ($id === 0 || !str_starts_with(substr($pdf, $offset, 24), $id . ' 0 obj')) {
                $verified = false;
                break;
            }
        }

        $text = '';
        if (preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $matches)) {
            foreach ($matches[1] as $stream) {
                if (!str_contains($stream, ' Tj')) {
                    continue;   // the logo is a raw JPEG, not text
                }
                if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\) Tj/', $stream, $strings)) {
                    foreach ($strings[1] as $literal) {
                        $text .= str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $literal) . "\n";
                    }
                }
            }
        }

        return [
            'pages' => substr_count($pdf, '/Type /Page '),
            'offsets_ok' => $ok && $verified,
            'text' => $text,
        ];
    }
}
