<?php
/**
 * barcode_lib.php -- self-contained barcode rendering library (SVG strings only).
 *
 * Conventions: plain functions, every symbol behind function_exists guards,
 * require_once-safe, no classes, no output buffering, nothing echoed, no deps.
 * Pure PHP; no GD required (SVG + HTML output only).
 *
 * Spec simplifications are documented inline and in scripts/test_barcode_lib.php.
 */

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

if (!function_exists('barcode_svg_escape_text')) {
    /**
     * Make arbitrary user text safe to embed inside an SVG/HTML node:
     * strip C0/C1 control chars (incl. NUL), then htmlspecialchars-escape.
     */
    function barcode_svg_escape_text(string $text): string
    {
        if (!function_exists('mb_check_encoding') || !mb_check_encoding($text, 'UTF-8')) {
            $text = (string) preg_replace('/[^\x20-\x7E]/', ' ', $text); // drop invalid bytes
        }
        $text = (string) preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}]/u', '', $text);
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('barcode_bits_rects')) {
    /**
     * Convert a module bitstring ('1'=black) into merged <rect> markup.
     * Returns [rectsXml, consumedWidthPx].
     */
    function barcode_bits_rects(string $bits, float $mw, float $x0, float $y, float $h, array $skip = []): array
    {
        $skipMap = array_fill_keys($skip, true);
        $out = '';
        $n = strlen($bits);
        $i = 0;
        while ($i < $n) {
            if ($bits[$i] === '1' && !isset($skipMap[$i])) {
                $start = $i;
                while ($i + 1 < $n && $bits[$i + 1] === '1' && !isset($skipMap[$i + 1])) {
                    $i++;
                }
                $out .= sprintf('<rect x="%.4F" y="%.4F" width="%.4F" height="%.4F" fill="#000"/>',
                    $x0 + $start * $mw, $y, ($i - $start + 1) * $mw, $h);
            }
            $i++;
        }
        return [$out, $n * $mw];
    }
}

// ---------------------------------------------------------------------------
// EAN-13 / EAN-8
// ---------------------------------------------------------------------------

if (!function_exists('barcode_ean13_lcodes')) {
    /** Left-hand (odd parity) encodings; G = reverse of L, C = complement of L (GS1 spec). */
    function barcode_ean13_lcodes(): array
    {
        return [
            '0' => '0001101', '1' => '0011001', '2' => '0010011', '3' => '0111101', '4' => '0100011',
            '5' => '0110001', '6' => '0101111', '7' => '0111011', '8' => '0110111', '9' => '0001011',
        ];
    }
}

if (!function_exists('barcode_ean_tables')) {
    /** ['L'=>[..], 'G'=>[..], 'C'=>[..]] digit=>7-bit patterns derived from the L table. */
    function barcode_ean_tables(): array
    {
        $l = barcode_ean13_lcodes();
        $g = [];
        $c = [];
        foreach ($l as $d => $bits) {
            $comp = '';
            foreach (str_split($bits) as $b) {
                $comp .= $b === '0' ? '1' : '0';
            }
            $c[$d] = $comp;                       // even parity (right side)
            $g[$d] = strrev($comp);               // reverse of C
        }
        return ['L' => $l, 'G' => $g, 'C' => $c];
    }
}

if (!function_exists('barcode_ean13_parity_pattern')) {
    /** Per-first-digit parity pattern for the 6 left-hand digits (L/G), GS1 Table. */
    function barcode_ean13_parity_pattern(string $firstDigit): string
    {
        $patterns = [
            '0' => 'LLLLLL', '1' => 'LLGLGG', '2' => 'LLGGLG', '3' => 'LLGGGL', '4' => 'LGLLGG',
            '5' => 'LGGLLG', '6' => 'LGGGLL', '7' => 'LGLGLG', '8' => 'LGLGGL', '9' => 'LGGLGL',
        ];
        if (!isset($patterns[$firstDigit])) {
            throw new InvalidArgumentException('Invalid EAN-13 first digit.');
        }
        return $patterns[$firstDigit];
    }
}

if (!function_exists('barcode_ean13_check_digit')) {
    /** Standard EAN modulo-10: weights 1,3,1,3,... over the 12 payload digits. */
    function barcode_ean13_check_digit(string $digits12): int
    {
        $digits12 = (string) $digits12;
        if (strlen($digits12) !== 12 || !ctype_digit($digits12)) {
            throw new InvalidArgumentException('EAN-13 check digit needs exactly 12 digits.');
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $digits12[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return (10 - $sum % 10) % 10;
    }
}

if (!function_exists('barcode_ean8_check_digit')) {
    /** EAN-8 modulo-10: weights 3,1,3,1,3,1,3 over the 7 payload digits. */
    function barcode_ean8_check_digit(string $digits7): int
    {
        $digits7 = (string) $digits7;
        if (strlen($digits7) !== 7 || !ctype_digit($digits7)) {
            throw new InvalidArgumentException('EAN-8 check digit needs exactly 7 digits.');
        }
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $digits7[$i] * ($i % 2 === 0 ? 3 : 1);
        }
        return (10 - $sum % 10) % 10;
    }
}

if (!function_exists('barcode_is_valid_ean13')) {
    function barcode_is_valid_ean13(string $code): bool
    {
        $code = (string) $code;
        return strlen($code) === 13 && ctype_digit($code)
            && barcode_ean13_check_digit(substr($code, 0, 12)) === (int) $code[12];
    }
}

if (!function_exists('barcode_is_valid_ean8')) {
    function barcode_is_valid_ean8(string $code): bool
    {
        $code = (string) $code;
        return strlen($code) === 8 && ctype_digit($code)
            && barcode_ean8_check_digit(substr($code, 0, 7)) === (int) $code[7];
    }
}

if (!function_exists('barcode_ean13_bitstring')) {
    /**
     * Full EAN-13 module bitstring (95 modules, no quiet zones), testable.
     * Layout: 101 + 6 digits (L/G per parity table) + 01010 + 6 digits (C) + 10101.
     */
    function barcode_ean13_bitstring(string $ean13): string
    {
        if (!barcode_is_valid_ean13($ean13)) {
            throw new InvalidArgumentException("Not a valid EAN-13 code: " . barcode_svg_escape_text($ean13));
        }
        $t = barcode_ean_tables();
        $parity = barcode_ean13_parity_pattern($ean13[0]);
        $bits = '101';
        for ($i = 1; $i <= 6; $i++) {
            $bits .= $t[$parity[$i - 1]][$ean13[$i]];
        }
        $bits .= '01010';
        for ($i = 7; $i <= 12; $i++) {
            $bits .= $t['C'][$ean13[$i]];
        }
        $bits .= '101'; // right guard is 3 modules: 3+42+5+42+3 = 95 total
        return $bits;
    }
}

if (!function_exists('barcode_ean8_bitstring')) {
    /** EAN-8 module bitstring (67 modules): 101 + 4xL + 01010 + 4xC + 10101. */
    function barcode_ean8_bitstring(string $ean8): string
    {
        if (!barcode_is_valid_ean8($ean8)) {
            throw new InvalidArgumentException("Not a valid EAN-8 code: " . barcode_svg_escape_text($ean8));
        }
        $t = barcode_ean_tables();
        $bits = '101';
        for ($i = 0; $i < 4; $i++) {
            $bits .= $t['L'][$ean8[$i]];
        }
        $bits .= '01010';
        for ($i = 4; $i < 8; $i++) {
            $bits .= $t['C'][$ean8[$i]];
        }
        $bits .= '101'; // 3+28+5+28+3 = 67 modules total
        return $bits;
    }
}

if (!function_exists('barcode_render_ean_svg')) {
    /**
     * Shared EAN-13/EAN-8 SVG painter. $groups = [[firstDigitModulePos, count, digitsIdx], ...].
     * Quiet zones: 11 modules left / 10 right (>= spec minimum 10; extra 1 carries the
     * leading digit printed in the left quiet zone). Guards extend below the bars.
     */
    function barcode_render_ean_svg(string $bits, string $digits, array $groups, int $centerGuard, array $opts): string
    {
        $mw = max(0.5, (float) ($opts['moduleWidth'] ?? 2));
        $height = max(30, (float) ($opts['height'] ?? 60));
        $hideText = !empty($opts['hideText']);

        $modules = strlen($bits);
        $guardIdx = array_merge(range(0, 2), range($centerGuard, $centerGuard + 4),
            range($modules - 3, $modules - 1));
        $fontSize = $hideText ? 0 : max(8.0, round($height * 0.16, 2));
        $descent = $fontSize * 0.2;
        $barH = $hideText ? $height : $height - $fontSize - $descent - 1;
        $guardBottom = $hideText ? $height : $height - 1;
        $w = ($modules + 21) * $mw;
        $x0 = 11 * $mw;

        [$bars] = barcode_bits_rects($bits, $mw, $x0, 0, $barH, $guardIdx);
        $guardXml = '';
        foreach ($guardIdx as $gi) {
            if ($bits[$gi] === '1') {
                $guardXml .= sprintf('<rect x="%.4F" y="0" width="%.4F" height="%.4F" fill="#000"/>',
                    $x0 + $gi * $mw, $mw, $guardBottom);
            }
        }
        $textXml = '';
        if (!$hideText) {
            $base = $guardBottom - $descent;               // baseline for in-barcode digits
            $firstBase = $height - 0.5 - $descent;         // baseline for leading digit
            $font = 'font-family="monospace" font-size="' . sprintf('%.2F', $fontSize) . '" fill="#000" text-anchor="middle"';
            $textXml .= sprintf('<text x="%.4F" y="%.4F" %s>%s</text>',
                4 * $mw, $firstBase, $font, barcode_svg_escape_text($digits[0]));
            foreach ($groups as [$pos, $count, $idx]) {
                for ($k = 0; $k < $count; $k++) {
                    $cx = $x0 + ($pos + 7 * $k + 3.5) * $mw;
                    $textXml .= sprintf('<text x="%.4F" y="%.4F" %s>%s</text>',
                        $cx, $base, $font, barcode_svg_escape_text($digits[$idx + $k]));
                }
            }
        }
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%.4F" height="%.4F" viewBox="0 0 %.4F %.4F" role="img">'
            . '<rect width="100%%" height="100%%" fill="#fff"/><g>%s%s</g>%s</svg>',
            $w, $height, $w, $height, $bars, $guardXml, $textXml
        );
    }
}

if (!function_exists('barcode_render_ean13_svg')) {
    function barcode_render_ean13_svg(string $ean13, array $opts = []): string
    {
        $bits = barcode_ean13_bitstring($ean13);
        // left half digits start at module 3 (digits 2-7), right half at module 50 (digits 8-13)
        return barcode_render_ean_svg($bits, $ean13, [[3, 6, 1], [50, 6, 7]], 45, $opts);
    }
}

if (!function_exists('barcode_render_ean8_svg')) {
    function barcode_render_ean8_svg(string $ean8, array $opts = []): string
    {
        $bits = barcode_ean8_bitstring($ean8);
        return barcode_render_ean_svg($bits, $ean8, [[3, 4, 0], [36, 4, 4]], 31, $opts);
    }
}

// ---------------------------------------------------------------------------
// Code 128
// ---------------------------------------------------------------------------

if (!function_exists('barcode_code128_patterns')) {
    /** Symbol value 0..106 -> module bitstring (11 modules; stop = 11+2). ISO/IEC 15417 table. */
    function barcode_code128_patterns(): array
    {
        $p = [
            '11011001100','11001101100','11001100110','10010011000','10010001100','10001001100',
            '10011001000','10011000100','10001100100','11001001000','11001000100','11000100100',
            '10110011100','10011011100','10011001110','10111001100','10011101100','10011100110',
            '11001110010','11001011100','11001001110','11011100100','11001110100','11101101110',
            '11101001100','11100101100','11100100110','11101100100','11100110100','11100110010',
            '11011011000','11011000110','11000110110','10100011000','10001011000','10001000110',
            '10110001000','10001101000','10001100010','11010001000','11000101000','11000100010',
            '10110111000','10110001110','10001101110','10111011000','10111000110','10001110110',
            '11101110110','11010001110','11000101110','11011101000','11011100010','11011101110',
            '11101011000','11101000110','11100010110','11101101000','11101100010','11100011010',
            '11101111010','11001000010','11110001010','10100110000','10100001100','10010110000',
            '10010000110','10000101100','10000100110','10110010000','10110000100','10011010000',
            '10011000010','10000110100','10000110010','11000010010','11001010000','11110111010',
            '11000010100','10001111010','10100111100','10010111100','10010011110','10111100100',
            '10011110100','10011110010','11110100100','11110010100','11110010010','11011011110',
            '11011110110','11110110110','10101111000','10100011110','10001011110','10111101000',
            '10111100010','11110101000','11110100010','10111011110','10111101110','11101011110',
            '11110101110','11010000100','11010010000','11010011100',
        ];
        $p[106] = '1100011101011'; // stop (stop symbol + final 2-module bar)
        return $p;
    }
}

if (!function_exists('barcode_code128_encode')) {
    /**
     * Auto subset B/C encoder. Returns the full symbol-value list:
     * [start, ...data (with 99=Code C / 100=Code B switches), checksum, 106(stop)].
     * Simplifications: subset A unsupported (control chars rejected); compression rule is a
     * greedy heuristic (digit runs >=4 at string start/end or >=6 in the middle use C).
     */
    function barcode_code128_encode(string $text): array
    {
        $n = strlen($text);
        if ($n === 0 || $n > 80) {
            throw new InvalidArgumentException('Code 128 text must be 1-80 bytes.');
        }
        for ($i = 0; $i < $n; $i++) {
            $o = ord($text[$i]);
            if ($o < 32 || $o > 126) {
                throw new InvalidArgumentException('Code 128 (B/C only) needs printable ASCII 32-126.');
            }
        }
        $vals = [];
        $i = 0;
        $mode = null;
        // digit run length starting at $p
        $runLen = function (string $t, int $p): int {
            $l = 0;
            while ($p + $l < strlen($t) && $t[$p + $l] >= '0' && $t[$p + $l] <= '9') {
                $l++;
            }
            return $l;
        };
        // choose start
        $lead = $runLen($text, 0);
        if ($lead === $n && $n % 2 === 0) {
            $vals[] = 105; $mode = 'C';
        } elseif ($lead >= 4) {
            $vals[] = 105; $mode = 'C';
            if ($lead % 2 === 1) { // odd leading run: keep first digit in B
                $vals[0] = 104; $mode = 'B'; $vals[] = ord($text[0]) - 32; $i = 1;
            }
        } else {
            $vals[] = 104; $mode = 'B';
        }
        while ($i < $n) {
            if ($mode === 'C') {
                if ($i + 1 < $n && ctype_digit($text[$i]) && ctype_digit($text[$i + 1])) {
                    $vals[] = (int) substr($text, $i, 2);
                    $i += 2;
                } else {
                    $vals[] = 100; // Code B
                    $mode = 'B';
                }
            } else {
                $run = $runLen($text, $i);
                $atEnd = ($i + $run) === $n;
                $threshold = ($i === 0 || $atEnd) ? 4 : 6;
                if ($run >= $threshold) {
                    if ($run % 2 === 1) {          // keep parity even for C pairs
                        $vals[] = ord($text[$i]) - 32;
                        $i++;
                        $run--;
                    }
                    $vals[] = 99;                  // Code C
                    $mode = 'C';
                } else {
                    $vals[] = ord($text[$i]) - 32;
                    $i++;
                }
            }
        }
        // checksum: start value + sum(weight k * value at position k), mod 103
        $sum = $vals[0];
        for ($k = 1, $c = count($vals); $k < $c; $k++) {
            $sum += $vals[$k] * $k;
        }
        $vals[] = $sum % 103;
        $vals[] = 106;
        return $vals;
    }
}

if (!function_exists('barcode_code128c_encode')) {
    /** Subset C only: even-length numeric string, 2..48 chars, leading zeros kept. */
    function barcode_code128c_encode(string $digits): array
    {
        $len = strlen($digits);
        if ($len < 2 || $len > 48 || $len % 2 !== 0 || !ctype_digit($digits)) {
            throw new InvalidArgumentException('Code 128C needs an even-length (2-48) numeric string.');
        }
        $vals = [105];
        for ($i = 0; $i < $len; $i += 2) {
            $vals[] = (int) substr($digits, $i, 2);
        }
        $sum = $vals[0];
        for ($k = 1; $k < count($vals); $k++) {
            $sum += $vals[$k] * $k;
        }
        $vals[] = $sum % 103;
        $vals[] = 106;
        return $vals;
    }
}

if (!function_exists('barcode_code128_bitstring')) {
    function barcode_code128_bitstring(string $text): string
    {
        return barcode_code128_values_bitstring(barcode_code128_encode($text));
    }
}

if (!function_exists('barcode_code128c_bitstring')) {
    function barcode_code128c_bitstring(string $digits): string
    {
        return barcode_code128_values_bitstring(barcode_code128c_encode($digits));
    }
}

if (!function_exists('barcode_code128_values_bitstring')) {
    function barcode_code128_values_bitstring(array $values): string
    {
        $p = barcode_code128_patterns();
        $bits = '';
        foreach ($values as $v) {
            if (!isset($p[$v])) {
                throw new InvalidArgumentException("Bad Code 128 symbol value: $v");
            }
            $bits .= $p[$v];
        }
        return $bits;
    }
}

if (!function_exists('barcode_render_code128_svg')) {
    function barcode_render_code128_svg(string $text, array $opts = []): string
    {
        $bits = barcode_code128_bitstring($text);
        return barcode_render_code128_svg_from($bits, $text, $opts);
    }
}

if (!function_exists('barcode_render_code128c_svg')) {
    function barcode_render_code128c_svg(string $digits, array $opts = []): string
    {
        $bits = barcode_code128c_bitstring($digits);
        return barcode_render_code128_svg_from($bits, $digits, $opts);
    }
}

if (!function_exists('barcode_render_code128_svg_from')) {
    function barcode_render_code128_svg_from(string $bits, string $human, array $opts): string
    {
        $mw = max(0.5, (float) ($opts['moduleWidth'] ?? 2));
        $height = max(30, (float) ($opts['height'] ?? 50));
        $hideText = !empty($opts['hideText']);
        $quiet = max(10, (int) ($opts['quietModules'] ?? 12)); // Code 128 minimum: 10 modules
        $modules = strlen($bits);
        $fontSize = $hideText ? 0 : max(8.0, round($height * 0.15, 2));
        $barH = $hideText ? $height : $height - $fontSize - 2;
        $w = ($modules + 2 * $quiet) * $mw;
        [$bars] = barcode_bits_rects($bits, $mw, $quiet * $mw, 0, $barH);
        $textXml = '';
        if (!$hideText) {
            $textXml = sprintf('<text x="%.4F" y="%.4F" font-family="monospace" font-size="%.2F" fill="#000" text-anchor="middle">%s</text>',
                $w / 2, $height - 1, $fontSize, barcode_svg_escape_text($human));
        }
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%.4F" height="%.4F" viewBox="0 0 %.4F %.4F" role="img">'
            . '<rect width="100%%" height="100%%" fill="#fff"/><g>%s</g>%s</svg>',
            $w, $height, $w, $height, $bars, $textXml);
    }
}

// ---------------------------------------------------------------------------
// GF(256) Reed-Solomon (QR polynomial 0x11d)
// ---------------------------------------------------------------------------

if (!function_exists('barcode_gf_tables')) {
    /** [exp[], log[]] tables for GF(256) with primitive polynomial x^8+x^4+x^3+x^2+1 (0x11d). */
    function barcode_gf_tables(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $exp = [];
        $log = [];
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d;
            }
        }
        for ($i = 255; $i < 510; $i++) { // wrap-around copy for cheap modular lookup
            $exp[$i] = $exp[$i - 255];
        }
        $cache = [$exp, $log];
        return $cache;
    }
}

if (!function_exists('barcode_gf_mul')) {
    function barcode_gf_mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        [$exp, $log] = barcode_gf_tables();
        return $exp[($log[$a] + $log[$b]) % 255];
    }
}

if (!function_exists('barcode_rs_generator_poly')) {
    /**
     * RS generator polynomial g(x) = prod_{i=0}^{n-1} (x - alpha^i) over GF(256).
     * Returns the n+1 coefficients as ALPHA EXPONENTS, highest degree first
     * (leading coefficient is alpha^0 = 1).
     */
    function barcode_rs_generator_poly(int $n): array
    {
        if ($n < 1 || $n > 64) {
            throw new InvalidArgumentException('RS generator degree must be 1..64.');
        }
        [$exp, $log] = barcode_gf_tables();
        $poly = [1]; // real coefficients
        for ($i = 0; $i < $n; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            $ai = $exp[$i];
            foreach ($poly as $j => $c) {
                $next[$j] ^= $c;
                $next[$j + 1] ^= barcode_gf_mul($c, $ai);
            }
            $poly = $next;
        }
        $out = [];
        foreach ($poly as $c) {
            $out[] = $c === 0 ? 0 : $log[$c];
        }
        return $out;
    }
}

if (!function_exists('barcode_rs_ec_codewords')) {
    /** EC codewords (real byte values) for $data under generator degree $n. */
    function barcode_rs_ec_codewords(array $data, int $n): array
    {
        $gen = array_slice(barcode_rs_generator_poly($n), 1); // drop leading 1, keep exponents
        [$exp] = barcode_gf_tables();
        $genReal = array_map(fn ($e) => $exp[$e], $gen);
        $msg = array_values($data);
        for ($i = 0; $i < $n; $i++) {
            $msg[] = 0;
        }
        for ($i = 0, $len = count($data); $i < $len; $i++) {
            $coef = $msg[$i];
            if ($coef !== 0) {
                foreach ($genReal as $j => $g) {
                    $msg[$i + 1 + $j] ^= barcode_gf_mul($g, $coef);
                }
            }
        }
        return array_slice($msg, $len);
    }
}

// ---------------------------------------------------------------------------
// QR code (ISO/IEC 18004), byte mode, versions 1-10
// ---------------------------------------------------------------------------

if (!function_exists('barcode_qr_tables')) {
    /**
     * ECC block table for versions 1-10, levels L/M/Q/H.
     * Structure per (version, level): [ecCodewordsPerBlock, [[blockCount, dataCodewords], ...]].
     * Transcribed from the published ISO/IEC 18004 Table 13-22 (verified against thonky.com).
     */
    function barcode_qr_tables(): array
    {
        static $t = null;
        if ($t !== null) {
            return $t;
        }
        $t = [
            1  => ['L' => [7, [[1, 19]]],  'M' => [10, [[1, 16]]], 'Q' => [13, [[1, 13]]], 'H' => [17, [[1, 9]]]],
            2  => ['L' => [10, [[1, 34]]], 'M' => [16, [[1, 28]]], 'Q' => [22, [[1, 22]]], 'H' => [28, [[1, 16]]]],
            3  => ['L' => [15, [[1, 55]]], 'M' => [26, [[1, 44]]], 'Q' => [18, [[2, 17]]], 'H' => [22, [[2, 13]]]],
            4  => ['L' => [20, [[1, 80]]], 'M' => [18, [[2, 32]]], 'Q' => [26, [[2, 24]]], 'H' => [16, [[4, 9]]]],
            5  => ['L' => [26, [[1, 108]]], 'M' => [24, [[2, 43]]], 'Q' => [18, [[2, 15], [2, 16]]], 'H' => [22, [[2, 11], [2, 12]]]],
            6  => ['L' => [18, [[2, 68]]], 'M' => [16, [[4, 27]]], 'Q' => [24, [[4, 19]]], 'H' => [28, [[4, 15]]]],
            7  => ['L' => [20, [[2, 78]]], 'M' => [18, [[4, 31]]], 'Q' => [18, [[2, 14], [4, 15]]], 'H' => [26, [[4, 13], [1, 14]]]],
            8  => ['L' => [24, [[2, 97]]], 'M' => [22, [[2, 38], [2, 39]]], 'Q' => [22, [[4, 18], [2, 19]]], 'H' => [26, [[4, 14], [2, 15]]]],
            9  => ['L' => [30, [[2, 116]]], 'M' => [22, [[3, 36], [2, 37]]], 'Q' => [20, [[4, 16], [4, 17]]], 'H' => [24, [[4, 12], [4, 13]]]],
            10 => ['L' => [18, [[2, 68], [2, 69]]], 'M' => [26, [[4, 43], [1, 44]]], 'Q' => [24, [[6, 19], [2, 20]]], 'H' => [28, [[6, 15], [2, 16]]]],
        ];
        return $t;
    }
}

if (!function_exists('barcode_qr_alignment_coords')) {
    function barcode_qr_alignment_coords(): array
    {
        return [
            2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
            7 => [6, 22, 38], 8 => [6, 22, 42], 9 => [6, 22, 46], 10 => [6, 24, 52],
        ];
    }
}

if (!function_exists('barcode_qr_payload')) {
    /** Build common QR payload strings from a semantic kind + raw data. */
    function barcode_qr_payload(string $kind, string $data): string
    {
        switch (strtolower($kind)) {
            case 'url':
                return preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $data) ? $data : 'https://' . $data;
            case 'email':
                return 'mailto:' . $data;
            case 'phone':
            case 'tel':
                return 'tel:' . $data;
            case 'sms':
                return 'SMSTO:' . $data;
            case 'text':
            default:
                return $data;
        }
    }
}

if (!function_exists('barcode_qr_data_bits')) {
    /** Bit array (0/1 list) of the byte-mode message padded to $dataCodewords bytes. */
    function barcode_qr_data_bits(string $text, int $version, int $dataCodewords): array
    {
        $ccBits = $version < 10 ? 8 : 16;
        $need = 4 + $ccBits + 8 * strlen($text);
        if ($need > $dataCodewords * 8) {
            throw new InvalidArgumentException('QR payload does not fit selected version.');
        }
        $bits = [];
        $push = function (int $value, int $count) use (&$bits) {
            for ($i = $count - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $push(4, 4); // byte mode
        $push(strlen($text), $ccBits);
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $push(ord($text[$i]), 8);
        }
        for ($i = 0; $i < 4 && count($bits) < $dataCodewords * 8; $i++) {
            $bits[] = 0; // terminator
        }
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        $pad = [0xEC, 0x11];
        $k = 0;
        while (count($bits) < $dataCodewords * 8) {
            $push($pad[$k++ % 2], 8);
        }
        return $bits;
    }
}

if (!function_exists('barcode_qr_interleaved')) {
    /** Split data codewords into RS blocks, compute EC, interleave per ISO Annex. */
    function barcode_qr_interleaved(array $bits, int $version, string $ecc): array
    {
        [$ecPerBlock, $groups] = barcode_qr_tables()[$version][$ecc];
        $blocks = [];
        $pos = 0;
        foreach ($groups as [$count, $dataLen]) {
            for ($b = 0; $b < $count; $b++) {
                $cw = [];
                for ($i = 0; $i < $dataLen; $i++) {
                    $byte = 0;
                    for ($j = 0; $j < 8; $j++) {
                        $byte = ($byte << 1) | $bits[$pos++];
                    }
                    $cw[] = $byte;
                }
                $blocks[] = [$cw, barcode_rs_ec_codewords($cw, $ecPerBlock)];
            }
        }
        $out = [];
        $maxData = max(array_map(fn ($g) => $g[1], $groups));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $blk) {
                if ($i < count($blk[0])) {
                    $out[] = $blk[0][$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($blocks as $blk) {
                $out[] = $blk[1][$i];
            }
        }
        $bitsOut = [];
        foreach ($out as $byte) {
            for ($j = 7; $j >= 0; $j--) {
                $bitsOut[] = ($byte >> $j) & 1;
            }
        }
        return [$bitsOut, $ecPerBlock];
    }
}

if (!function_exists('barcode_qr_format_bits')) {
    /**
     * 15-bit BCH(15,5) format information for [eccBits, mask], generator 0x537,
     * XOR mask 0x5412. Returns array indexed by bit number i (LSB first): $f[$i].
     */
    function barcode_qr_format_bits(string $ecc, int $mask): array
    {
        $eccBits = ['M' => 0b00, 'L' => 0b01, 'H' => 0b10, 'Q' => 0b11][$ecc];
        $data = ($eccBits << 3) | ($mask & 7);
        $rem = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ((($rem >> $i) & 1) === 1) {
                $rem ^= 0x537 << ($i - 10);
            }
        }
        $value = (($data << 10) | $rem) ^ 0x5412;
        $bits = [];
        for ($i = 0; $i < 15; $i++) {
            $bits[$i] = ($value >> $i) & 1;
        }
        return $bits;
    }
}

if (!function_exists('barcode_qr_version_bits')) {
    /** 18-bit BCH(18,6) version information (generator 0x1F25), LSB-first index. Version 7+. */
    function barcode_qr_version_bits(int $version): array
    {
        $rem = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if ((($rem >> $i) & 1) === 1) {
                $rem ^= 0x1F25 << ($i - 12);
            }
        }
        $value = ($version << 12) | $rem;
        $bits = [];
        for ($i = 0; $i < 18; $i++) {
            $bits[$i] = ($value >> $i) & 1;
        }
        return $bits;
    }
}

if (!function_exists('barcode_qr_mask_fn')) {
    /** Standard ISO mask conditions 0..7: returns true when the module must be flipped. */
    function barcode_qr_mask_fn(int $mask, int $row, int $col): bool
    {
        switch ($mask) {
            case 0: return ($row + $col) % 2 === 0;
            case 1: return $row % 2 === 0;
            case 2: return $col % 3 === 0;
            case 3: return ($row + $col) % 3 === 0;
            case 4: return (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0;
            case 5: return (($row * $col) % 2) + (($row * $col) % 3) === 0;
            case 6: return ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0;
            case 7: return (((($row + $col) % 2) + (($row * $col) % 3)) % 2) === 0;
            default: throw new InvalidArgumentException("Bad QR mask: $mask");
        }
    }
}

if (!function_exists('barcode_qr_penalty')) {
    /** Standard four mask penalty rules (ISO/IEC 18004 8.8.2). Simplification: rule 3 uses
     *  the widely adopted 'finder-like pattern + 4 light modules on one side' interpretation. */
    function barcode_qr_penalty(array $m): int
    {
        $size = count($m);
        $p1 = 0;
        $p2 = 0;
        $p3 = 0;
        $dark = 0;
        $padA = '10111010000';
        $padB = '00001011101';
        for ($i = 0; $i < $size; $i++) {
            $rowBits = '';
            $colBits = '';
            for ($j = 0; $j < $size; $j++) {
                $dark += $m[$i][$j];
                $rowBits .= (string) $m[$i][$j];
                $colBits .= (string) $m[$j][$i];
            }
            foreach ([$rowBits, $colBits] as $line) {
                preg_match_all('/0{5,}|1{5,}/', $line, $mm);
                foreach ($mm[0] as $run) {
                    $p1 += 3 + (strlen($run) - 5);
                }
                $p3 += substr_count($line, $padA) + substr_count($line, $padB);
            }
        }
        for ($i = 0; $i < $size - 1; $i++) {
            for ($j = 0; $j < $size - 1; $j++) {
                $v = $m[$i][$j];
                if ($v === $m[$i][$j + 1] && $v === $m[$i + 1][$j] && $v === $m[$i + 1][$j + 1]) {
                    $p2++;
                }
            }
        }
        $total = $size * $size;
        $percent = $dark * 100 / $total;
        $p4 = (int) floor(abs($percent - 50) / 5) * 10;
        return $p1 + $p2 * 3 + $p3 * 40 + $p4;
    }
}

if (!function_exists('barcode_qr_function_map')) {
    /**
     * Blank matrix + reserved/function map for a version. Places finder patterns,
     * separators, timing patterns, alignment patterns, dark module, and reserves
     * format/version info areas. Returns [modules, reserved, size].
     */
    function barcode_qr_function_map(int $version): array
    {
        $size = 17 + 4 * $version;
        $modules = array_fill(0, $size, array_fill(0, $size, 0));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));
        $set = function (int $r, int $c, int $v) use (&$modules, &$reserved) {
            $modules[$r][$c] = $v;
            $reserved[$r][$c] = true;
        };
        // finder patterns + separators (three corners)
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r0, $c0]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $r0 + $r;
                    $cc = $c0 + $c;
                    if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) {
                        continue;
                    }
                    $inFinder = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;
                    // ISO 18004 finder eye: solid 7x7 dark ring (corners included)
                    // around a light ring and a 3x3 dark core.
                    $edge = $r === 0 || $r === 6 || $c === 0 || $c === 6;
                    $core = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                    $set($rr, $cc, $inFinder && ($edge || $core) ? 1 : 0);
                }
            }
        }
        // timing patterns (row 6 and column 6)
        for ($i = 8; $i < $size - 8; $i++) {
            $v = $i % 2 === 0 ? 1 : 0;
            $set(6, $i, $v);
            $set($i, 6, $v);
        }
        // alignment patterns
        $coords = barcode_qr_alignment_coords()[$version] ?? [];
        foreach ($coords as $r0) {
            foreach ($coords as $c0) {
                $nearFinder = ($r0 <= 8 && $c0 <= 8) || ($r0 <= 8 && $c0 >= $size - 9)
                    || ($r0 >= $size - 9 && $c0 <= 8);
                if ($nearFinder) {
                    continue; // overlaps a finder block
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $set($r0 + $r, $c0 + $c, (max(abs($r), abs($c)) !== 1) ? 1 : 0);
                    }
                }
            }
        }
        // format info areas (both copies) + dark module
        for ($i = 0; $i <= 8; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }
        $set($size - 8, 8, 1); // dark module
        // version info areas (versions 7+)
        if ($version >= 7) {
            for ($i = 0; $i < 18; $i++) {
                $reserved[intdiv($i, 3)][$size - 11 + $i % 3] = true;
                $reserved[$size - 11 + $i % 3][intdiv($i, 3)] = true;
            }
        }
        return [$modules, $reserved, $size];
    }
}

if (!function_exists('barcode_qr_place_data')) {
    /** ISO zigzag two-module column placement (skipping the vertical timing column). */
    function barcode_qr_place_data(array $modules, array $reserved, array $bits): array
    {
        $size = count($modules);
        $col = $size - 1;
        $upward = true;
        $idx = 0;
        $n = count($bits);
        while ($col >= 0) {
            if ($col === 6) {
                $col--; // skip timing column; continue with the (5,4) pair
            }
            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? ($size - 1 - $i) : $i;
                for ($c = $col; $c >= $col - 1; $c--) {
                    if ($c < 0 || $reserved[$row][$c]) {
                        continue;
                    }
                    $modules[$row][$c] = $idx < $n ? $bits[$idx++] : 0;
                }
            }
            $upward = !$upward;
            $col -= 2;
        }
        return $modules;
    }
}

if (!function_exists('barcode_qr_read_data')) {
    /** Inverse of barcode_qr_place_data: read data-module bits in zigzag order, unmasking. */
    function barcode_qr_read_data(array $modules, array $reserved, int $mask): array
    {
        $size = count($modules);
        $bits = [];
        $col = $size - 1;
        $upward = true;
        while ($col >= 0) {
            if ($col === 6) {
                $col--;
            }
            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? ($size - 1 - $i) : $i;
                for ($c = $col; $c >= $col - 1; $c--) {
                    if ($c < 0 || $reserved[$row][$c]) {
                        continue;
                    }
                    $v = $modules[$row][$c];
                    if (barcode_qr_mask_fn($mask, $row, $c)) {
                        $v ^= 1;
                    }
                    $bits[] = $v;
                }
            }
            $upward = !$upward;
            $col -= 2;
        }
        return $bits;
    }
}

if (!function_exists('barcode_qr_build')) {
    /**
     * Build a QR matrix. Returns ['modules'=>2D 0/1, 'reserved'=>2D bool, 'size'=>int,
     * 'version'=>int, 'ecc'=>string, 'mask'=>int]. Byte mode only; versions 1-10 auto-selected.
     */
    function barcode_qr_build(string $text, string $ecc = 'M'): array
    {
        $ecc = strtoupper($ecc);
        if ($text === '' || !isset(barcode_qr_tables()[1][$ecc])) {
            throw new InvalidArgumentException('QR needs non-empty text and ECC level L, M, Q or H.');
        }
        // pick smallest version that fits
        $version = 0;
        $dataCodewords = 0;
        for ($v = 1; $v <= 10; $v++) {
            [$ecPer, $groups] = barcode_qr_tables()[$v][$ecc];
            $dc = 0;
            foreach ($groups as [$cnt, $len]) {
                $dc += $cnt * $len;
            }
            $ccBits = $v < 10 ? 8 : 16;
            if (4 + $ccBits + 8 * strlen($text) <= $dc * 8) {
                $version = $v;
                $dataCodewords = $dc;
                break;
            }
        }
        if ($version === 0) {
            throw new InvalidArgumentException('QR payload too large for versions 1-10 (simplification: max ~213 bytes at ECC M).');
        }
        $bits = barcode_qr_data_bits($text, $version, $dataCodewords);
        [$codewordBits] = barcode_qr_interleaved($bits, $version, $ecc);
        [$modules, $reserved, $size] = barcode_qr_function_map($version);
        $modules = barcode_qr_place_data($modules, $reserved, $codewordBits);
        if ($version >= 7) {
            $vb = barcode_qr_version_bits($version);
            for ($i = 0; $i < 18; $i++) {
                $modules[intdiv($i, 3)][$size - 11 + $i % 3] = $vb[$i];
                $modules[$size - 11 + $i % 3][intdiv($i, 3)] = $vb[$i];
            }
        }
        // evaluate all 8 masks, keep the lowest penalty
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $try = $modules;
            for ($r = 0; $r < $size; $r++) {
                for ($c = 0; $c < $size; $c++) {
                    if (!$reserved[$r][$c] && barcode_qr_mask_fn($mask, $r, $c)) {
                        $try[$r][$c] ^= 1;
                    }
                }
            }
            $f = barcode_qr_format_bits($ecc, $mask);
            for ($i = 0; $i < 15; $i++) {
                if ($i < 6) {
                    $try[$i][8] = $f[$i];
                } elseif ($i < 8) {
                    $try[$i + 1][8] = $f[$i];
                } else {
                    $try[$size - 15 + $i][8] = $f[$i];
                }
                if ($i < 8) {
                    $try[8][$size - 1 - $i] = $f[$i];
                } elseif ($i === 8) {
                    $try[8][7] = $f[$i];
                } else {
                    $try[8][14 - $i] = $f[$i];
                }
            }
            $try[$size - 8][8] = 1; // dark module stays dark
            $score = barcode_qr_penalty($try);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = [$mask, $try];
            }
        }
        return [
            'modules' => $best[1],
            'reserved' => $reserved,
            'size' => $size,
            'version' => $version,
            'ecc' => $ecc,
            'mask' => $best[0],
            'dataCodewords' => $dataCodewords,
        ];
    }
}

if (!function_exists('barcode_qr_extract_text')) {
    /**
     * Reverse operation used for self-verification: recover the byte-mode payload from a
     * built matrix (un-mask, zigzag-read, de-interleave, parse header). Not a general
     * QR decoder (byte mode only).
     */
    function barcode_qr_extract_text(array $meta): string
    {
        [$ecPerBlock, $groups] = barcode_qr_tables()[$meta['version']][$meta['ecc']];
        $bits = barcode_qr_read_data($meta['modules'], $meta['reserved'], $meta['mask']);
        $blocks = [];
        $total = 0;
        foreach ($groups as [$cnt, $len]) {
            for ($b = 0; $b < $cnt; $b++) {
                $blocks[] = $len;
                $total += $len;
            }
        }
        $cw = [];
        foreach ($bits as $i => $bit) {
            if ($i % 8 === 7) {
                $byte = 0;
                for ($j = 0; $j < 8; $j++) {
                    $byte = ($byte << 1) | $bits[$i - 7 + $j];
                }
                $cw[] = $byte;
            }
        }
        $cw = array_slice($cw, 0, $total); // drop remainder bits
        // de-interleave data codewords
        $data = array_fill(0, count($blocks), []);
        $pos = 0;
        $maxLen = max($blocks);
        for ($i = 0; $i < $maxLen; $i++) {
            foreach ($data as $b => $len) {
                if ($i < $blocks[$b]) {
                    $data[$b][] = $cw[$pos++];
                }
            }
        }
        $flat = [];
        foreach ($data as $d) {
            foreach ($d as $c) {
                $flat[] = $c;
            }
        }
        // parse header
        $bitArr = [];
        foreach ($flat as $byte) {
            for ($j = 7; $j >= 0; $j--) {
                $bitArr[] = ($byte >> $j) & 1;
            }
        }
        $take = function (int $n, int &$p) use ($bitArr): int {
            $v = 0;
            for ($i = 0; $i < $n; $i++) {
                $v = ($v << 1) | $bitArr[$p++];
            }
            return $v;
        };
        $p = 0;
        $mode = $take(4, $p);
        if ($mode !== 4) {
            throw new RuntimeException('QR extraction failed: not byte mode.');
        }
        $len = $take($meta['version'] < 10 ? 8 : 16, $p);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= chr($take(8, $p));
        }
        return $out;
    }
}

if (!function_exists('barcode_render_qr_svg')) {
    /**
     * Render a scannable QR code (byte mode, ECC default M) as an SVG string.
     * $opts: moduleWidth (px per module, default 4), margin (quiet-zone modules,
     * default 4 = ISO minimum).
     */
    function barcode_render_qr_svg(string $text, array $opts = []): string
    {
        $meta = barcode_qr_build($text, (string) ($opts['ecc'] ?? 'M'));
        return barcode_render_qr_svg_from($meta, $opts);
    }
}

if (!function_exists('barcode_render_qr_svg_from')) {
    function barcode_render_qr_svg_from(array $meta, array $opts = []): string
    {
        $mw = max(0.5, (float) ($opts['moduleWidth'] ?? 4));
        $margin = max(4, (int) ($opts['margin'] ?? 4)); // ISO quiet zone = 4 modules
        $size = $meta['size'];
        $px = ($size + 2 * $margin) * $mw;
        $mods = $meta['modules'];
        $rects = '';
        for ($r = 0; $r < $size; $r++) {
            $rowBits = implode('', array_map(fn ($v) => (string) $v, $mods[$r]));
            [$xml] = barcode_bits_rects($rowBits, $mw, $margin * $mw, ($r + $margin) * $mw, $mw);
            $rects .= $xml;
        }
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%.4F" height="%.4F" viewBox="0 0 %.4F %.4F" role="img">'
            . '<rect width="100%%" height="100%%" fill="#fff"/><g fill="none">%s</g></svg>',
            $px, $px, $px, $px, $rects);
    }
}

// ---------------------------------------------------------------------------
// HTML wrapper
// ---------------------------------------------------------------------------

if (!function_exists('barcode_render_html')) {
    /** Wrap a rendered SVG in the app's .barcode-wrap markup with an escaped caption. */
    function barcode_render_html(string $svg, string $caption, array $opts = []): string
    {
        $class = isset($opts['class']) ? (string) $opts['class'] : 'barcode-wrap';
        // strip control chars first, then escape via the app's e() when available
        $raw = (string) preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}]/u', '', $caption);
        $safeCaption = function_exists('e')
            ? e($raw)
            : htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<div class="' . barcode_svg_escape_text($class) . '">' . $svg
            . '<div class="barcode-caption">' . $safeCaption . '</div></div>';
    }
}
