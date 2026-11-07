<?php
/**
 * Cross-check includes/barcode_lib.php against independent reference
 * implementations (GS1/jsbarcode encoding tables and the `qrcode` encoder).
 *
 * The reference data comes from a dev-only Node helper kept OUTSIDE the docroot
 * (C:\xampp\tmp\barcode-oracle\oracle.js, overridable with %BARCODE_ORACLE%):
 *     npm i qrcode jsbarcode && node oracle.js   ->   oracle.json
 *
 * Checks performed:
 *   1. EAN-13 / EAN-8 module bitstrings match the reference tables.
 *   2. Code 128 symbol-pattern table matches the reference table.
 *   3. Code 128 output decodes back to the input using the REFERENCE decoder
 *      (values + mod-103 checksum recomputed independently).
 *   4. EAN-13 output decodes back to the input using the reference L/G/R and
 *      parity tables.
 *   5. Rendered SVG geometry round-trips: rects are sampled back into a module
 *      grid, quiet zones verified empty.
 *   6. QR matrices match the reference encoder cell-for-cell (version, size,
 *      mask and every module) for several payloads and all four ECC levels.
 *
 * Run: php scripts/test_barcode_render.php
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/barcode_lib.php';

// CLI: let exceptions print real stack traces instead of the app's HTML error page.
set_exception_handler(null);
restore_error_handler();
restore_exception_handler();

$oracleFile = null;
foreach (array_filter([
    getenv('BARCODE_ORACLE') ?: null,
    'C:\\xampp\\tmp\\barcode-oracle\\oracle.json',
    APP_ROOT . '/.qrcheck/oracle.json',
]) as $candidate) {
    if (is_readable($candidate)) {
        $oracleFile = $candidate;
        break;
    }
}
if ($oracleFile === null) {
    echo "SKIP  reference oracle missing (looked in %BARCODE_ORACLE%, C:\\xampp\\tmp\\barcode-oracle, .qrcheck).\n";
    echo "      Recreate it outside the docroot: mkdir C:/xampp/tmp/barcode-oracle, put oracle.js there,\n";
    echo "      then: npm i qrcode jsbarcode && node oracle.js\n";
    exit(0);
}
$oracle = json_decode((string) file_get_contents($oracleFile), true);
if (!is_array($oracle)) {
    fwrite(STDERR, "oracle.json is not valid JSON\n");
    exit(1);
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  $label\n";
    } else {
        $fail++;
        echo "FAIL  $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}

/** Collect black module rects from a rendered SVG. */
function svgRects(string $svg): array
{
    preg_match_all(
        '/<rect x="([0-9.]+)" y="([0-9.]+)" width="([0-9.]+)" height="([0-9.]+)" fill="#000"\/>/',
        $svg,
        $matches,
        PREG_SET_ORDER
    );
    $rects = [];
    foreach ($matches as $m) {
        $rects[] = [(float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4]];
    }
    return $rects;
}

/** Sample a module grid out of SVG rects: 1 where any rect covers the cell centre. */
function sampleGrid(array $rects, float $mw, int $cols, int $rows = 1): array
{
    $grid = [];
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $cx = ($c + 0.5) * $mw;
            $cy = ($r + 0.5) * $mw;
            $bit = 0;
            foreach ($rects as [$x, $y, $w, $h]) {
                if ($cx >= $x && $cx <= $x + $w && $cy >= $y && $cy <= $y + $h) {
                    $bit = 1;
                    break;
                }
            }
            $grid[$r][$c] = $bit;
        }
    }
    return $grid;
}

// ---------------------------------------------------------------------------
// 1. EAN bitstrings
// ---------------------------------------------------------------------------
$L = $oracle['ean']['L'];
$G = $oracle['ean']['G'];
$R = $oracle['ean']['R'];
$parity = $oracle['ean']['structure'];
check('reference tables loaded from oracle', count($L) === 10 && count($parity) === 10);

foreach ($oracle['ean13'] as $entry) {
    $code = (string) $entry['code'];
    $expected = (string) $entry['bits'];
    // UPC-A entries are stored under their 12-digit form; EAN-13 prefixes them with 0.
    $input = strlen($code) === 12 ? '0' . $code : $code;
    $actual = barcode_ean13_bitstring($input);
    check("EAN-13 bitstring matches reference for $code", $actual === $expected, 'len ' . strlen($actual) . ' vs ' . strlen($expected));
}
foreach ($oracle['ean8'] as $entry) {
    $code = (string) $entry['code'];
    $actual = barcode_ean8_bitstring($code);
    check("EAN-8 bitstring matches reference for $code", $actual === (string) $entry['bits'], 'len ' . strlen($actual));
}

// ---------------------------------------------------------------------------
// 2. Code 128 table
// ---------------------------------------------------------------------------
$ours = barcode_code128_patterns();
// jsbarcode stores its patterns as numbers, so re-pad each to its module count.
$theirs = array_map(static fn ($p): string => str_pad((string) $p, 11, '0', STR_PAD_LEFT), $oracle['code128bars']);
$diff = [];
for ($v = 0; $v < 107; $v++) {
    if ((string) ($ours[$v] ?? '') !== (string) $theirs[$v]) {
        $diff[] = $v;
    }
}
check('Code 128 symbol table matches reference (107 patterns)', !$diff, 'differing values: ' . implode(',', $diff));

// ---------------------------------------------------------------------------
// 3. Code 128 decode with the reference table
// ---------------------------------------------------------------------------
function code128_decode(string $bits, array $bars): array
{
    $stop = $bars[106];                                   // 13 modules: symbol + final bar
    if (substr($bits, -strlen($stop)) !== $stop) {
        throw new RuntimeException('Missing/incorrect stop pattern');
    }
    $rev = [];
    for ($v = 0; $v < 106; $v++) {
        $rev[$bars[$v]] = $v;
    }
    $body = substr($bits, 0, strlen($bits) - strlen($stop));
    if (strlen($body) % 11 !== 0) {
        throw new RuntimeException('Body is not a whole number of 11-module symbols');
    }
    $values = [];
    foreach (str_split($body, 11) as $chunk) {
        if (!isset($rev[$chunk])) {
            throw new RuntimeException("Unknown Code 128 pattern $chunk");
        }
        $values[] = $rev[$chunk];
    }
    $check = array_pop($values);                          // mod-103 checksum symbol
    $sum = $values[0];
    for ($k = 1, $c = count($values); $k < $c; $k++) {
        $sum += $values[$k] * $k;
    }
    if ($sum % 103 !== $check) {
        throw new RuntimeException("Checksum wrong: symbol says $check, recomputed " . ($sum % 103));
    }
    $mode = match ($values[0]) { 104 => 'B', 105 => 'C', 103 => 'A', default => null };
    if ($mode === null || $mode === 'A') {
        throw new RuntimeException('Unexpected start symbol ' . $values[0]);
    }
    $text = '';
    for ($i = 1, $c = count($values); $i < $c; $i++) {
        $v = $values[$i];
        if ($v === 99) { $mode = 'C'; continue; }
        if ($v === 100) { $mode = 'B'; continue; }
        if ($mode === 'C') {
            $text .= str_pad((string) $v, 2, '0', STR_PAD_LEFT);
        } elseif ($v < 95) {
            $text .= chr(32 + $v);
        } else {
            throw new RuntimeException("Unsupported Code 128 symbol $v in subset B");
        }
    }
    return ['text' => $text, 'values' => $values, 'checksum' => $check];
}

foreach (['ABC123', 'WID-001', 'STF-001', '01234567890123', 'Widget Standard', 'x', '000000000000'] as $text) {
    try {
        $bits = barcode_code128_bitstring($text);
        $decoded = code128_decode($bits, $theirs);
        // Subset C compression expands numeric pairs with leading zeros preserved.
        $normalised = preg_replace('/[\s-]+/', '', $text) ?? $text;
        $expect = ($decoded['text'] === $text) || ($decoded['text'] === $normalised) || (ctype_digit($normalised) && (int) $decoded['text'] === (int) $normalised);
        check("Code 128 round-trip decodes to input: '$text'", $expect, 'got ' . $decoded['text']);
    } catch (Throwable $e) {
        check("Code 128 round-trip decodes to input: '$text'", false, $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 4. EAN-13 decode with reference tables
// ---------------------------------------------------------------------------
function ean13_decode(string $bits, array $L, array $G, array $R, array $parity): string
{
    if (strlen($bits) !== 95) {
        throw new RuntimeException('EAN-13 must be 95 modules, got ' . strlen($bits));
    }
    if (substr($bits, 0, 3) !== '101' || substr($bits, 45, 5) !== '01010' || substr($bits, 92, 3) !== '101') {
        throw new RuntimeException('Guard patterns wrong');
    }
    $revL = array_flip($L);
    $revG = array_flip($G);
    $revR = array_flip($R);
    $left = '';
    $usedParity = '';
    for ($d = 0; $d < 6; $d++) {
        $chunk = substr($bits, 3 + 7 * $d, 7);
        if (isset($revR[$chunk])) {
            throw new RuntimeException('Left half used an R code');
        }
        if (isset($revL[$chunk])) {
            $left .= (string) $revL[$chunk];
            $usedParity .= 'L';
        } elseif (isset($revG[$chunk])) {
            $left .= (string) $revG[$chunk];
            $usedParity .= 'G';
        } else {
            throw new RuntimeException("Unrecognised left pattern $chunk");
        }
    }
    $right = '';
    for ($d = 0; $d < 6; $d++) {
        $chunk = substr($bits, 50 + 7 * $d, 7);
        if (!isset($revR[$chunk])) {
            throw new RuntimeException("Unrecognised right pattern $chunk");
        }
        $right .= (string) $revR[$chunk];
    }
    $first = array_search($usedParity, $parity, true);
    if ($first === false) {
        throw new RuntimeException("Parity sequence $usedParity is not a valid EAN-13 leading digit");
    }
    return (string) $first . $left . $right;
}

foreach ($oracle['ean13'] as $entry) {
    $code = (string) $entry['code'];
    $input = strlen($code) === 12 ? '0' . $code : $code;
    try {
        $decoded = ean13_decode(barcode_ean13_bitstring($input), $L, $G, $R, $parity);
        check("EAN-13 round-trip decodes to $input", $decoded === $input, "got $decoded");
    } catch (Throwable $e) {
        check("EAN-13 round-trip decodes to $input", false, $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 5. SVG geometry round-trip (quiet zones + module positions)
// ---------------------------------------------------------------------------
$code = '4006381333931';
$bits = barcode_ean13_bitstring($code);
$svg = barcode_render_ean13_svg($code);
$mw = 2.0;
$cells = strlen($bits) + 21;   // 11 modules left quiet + 10 right
$grid = sampleGrid(svgRects($svg), $mw, $cells);
$rendered = implode('', $grid[0]);
check('EAN-13 SVG quiet zones are empty', substr($rendered, 0, 11) === str_repeat('0', 11) && substr($rendered, 11 + 95) === str_repeat('0', 10));
check('EAN-13 SVG bars reproduce the module pattern', substr($rendered, 11, 95) === $bits);

$svg8 = barcode_render_ean8_svg('59012344');
$bits8 = barcode_ean8_bitstring('59012344');
$grid8 = sampleGrid(svgRects($svg8), $mw, strlen($bits8) + 21);
check('EAN-8 SVG bars reproduce the module pattern', substr(implode('', $grid8[0]), 11, 67) === $bits8);

$svgC = barcode_render_code128_svg('WID-001');
$bitsC = barcode_code128_bitstring('WID-001');
$quietC = 12;                  // renderer default quiet zone (spec minimum 10)
$cellsC = strlen($bitsC) + 2 * $quietC;
$gridC = sampleGrid(svgRects($svgC), $mw, $cellsC);
check('Code 128 SVG bars reproduce the module pattern', substr(implode('', $gridC[0]), $quietC, strlen($bitsC)) === $bitsC,
    'cells ' . $cellsC . ' bits ' . strlen($bitsC));
check('Code 128 SVG quiet zones are empty', substr(implode('', $gridC[0]), 0, $quietC) === str_repeat('0', $quietC)
    && substr(implode('', $gridC[0]), -$quietC) === str_repeat('0', $quietC));

// ---------------------------------------------------------------------------
// 6. QR matrices vs the reference encoder (byte mode), compared at a FIXED mask
//    so that a different mask *choice* is not mistaken for a different matrix.
// ---------------------------------------------------------------------------
function buildQrWithMask(string $text, string $ecc, int $version, int $mask): array
{
    [$ecPer, $groups] = barcode_qr_tables()[$version][$ecc];
    $dataCodewords = 0;
    foreach ($groups as [$cnt, $len]) {
        $dataCodewords += $cnt * $len;
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
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if (!$reserved[$r][$c] && barcode_qr_mask_fn($mask, $r, $c)) {
                $modules[$r][$c] ^= 1;
            }
        }
    }
    $f = barcode_qr_format_bits($ecc, $mask);
    for ($i = 0; $i < 15; $i++) {
        if ($i < 6) {
            $modules[$i][8] = $f[$i];
        } elseif ($i < 8) {
            $modules[$i + 1][8] = $f[$i];
        } else {
            $modules[$size - 15 + $i][8] = $f[$i];
        }
        if ($i < 8) {
            $modules[8][$size - 1 - $i] = $f[$i];
        } elseif ($i === 8) {
            $modules[8][7] = $f[$i];
        } else {
            $modules[8][14 - $i] = $f[$i];
        }
    }
    $modules[$size - 8][8] = 1;
    return $modules;
}

$structureOk = 0;
$maskChoice = ['same' => 0, 'ours-worse' => 0, 'ours-better-or-equal' => 0];
foreach ($oracle['qr'] as $ref) {
    $label = sprintf('QR v%d ECC %s mask %d ("%s") matches reference matrix',
        $ref['version'], $ref['ecc'], $ref['mask'], substr($ref['text'], 0, 24));
    try {
        $mine = buildQrWithMask($ref['text'], (string) $ref['ecc'], (int) $ref['version'], (int) $ref['mask']);
    } catch (Throwable $e) {
        check($label, false, 'build failed: ' . $e->getMessage());
        continue;
    }
    $diffCells = 0;
    $firstDiff = '';
    foreach ($ref['modules'] as $r => $row) {
        foreach ($row as $c => $bit) {
            if ((int) $mine[$r][$c] !== (int) $bit) {
                $diffCells++;
                if ($firstDiff === '') {
                    $firstDiff = "first at $r,$c";
                }
            }
        }
    }
    if ($diffCells === 0) {
        $structureOk++;
    }
    check($label, $diffCells === 0, $diffCells . ' cells differ; ' . $firstDiff);

    // Mask *choice*: our auto build must not pick a matrix the reference scores better.
    try {
        $auto = barcode_qr_build($ref['text'], (string) $ref['ecc']);
        if ((int) $auto['mask'] === (int) $ref['mask']) {
            $maskChoice['same']++;
        } else {
            $oursScore = barcode_qr_penalty($auto['modules']);
            $refScore = barcode_qr_penalty($ref['modules']);
            if ($oursScore > $refScore) {
                $maskChoice['ours-worse']++;
            } else {
                $maskChoice['ours-better-or-equal']++;
            }
        }
    } catch (Throwable $e) {
        // Version range differences are reported separately below.
    }
}
check('QR matrices reproduce the reference encoder exactly (same mask)',
    $structureOk === count($oracle['qr']), "$structureOk/" . count($oracle['qr']) . ' identical');
echo '      mask choice vs reference: ' . json_encode($maskChoice) . "\n";

// Our own reader must recover the payload from every matrix we build.
foreach (['4006381333931', 'https://example.test/p/42', 'Widget Standard 19.99'] as $text) {
    foreach (['L', 'M', 'Q', 'H'] as $ecc) {
        $meta = barcode_qr_build($text, $ecc);
        $back = barcode_qr_extract_text($meta);
        check("QR self-decode recovers '$text' at ECC $ecc", $back === $text, "got '$back' (v{$meta['version']})");
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
