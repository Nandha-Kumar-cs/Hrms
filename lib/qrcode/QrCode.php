<?php
/**
 * MagDyn HRMS — Minimal QR Code encoder (dependency-free).
 * ─────────────────────────────────────────────────────────────────────────────
 * The project has no Composer autoloader, so this is a small self-contained
 * implementation of ISO/IEC 18004 sufficient for encoding short ASCII URLs:
 *
 *   • Byte mode only (our payload is a URL — ASCII).
 *   • Error-correction level M (~15% recovery) — the printing standard for ID
 *     cards; it survives a scuffed sticker while keeping the module count low
 *     (bigger modules = easier to scan from a phone).
 *   • Versions 1–10 (up to 213 bytes). A secure-access URL is ~80 chars, which
 *     lands on version 4–6 (33×33 to 41×41 modules).
 *
 * Output: raw module matrix, PNG (via GD) or SVG (no extension needed).
 *
 * Usage:
 *   $png = QrCode::png('https://example.com/x', 10, 4);   // binary PNG string
 *   $uri = QrCode::dataUri('https://example.com/x', 10);  // data: URI for <img>
 */

final class QrCode
{
    /** version => [ec codewords per block, [[block count, data codewords per block], ...]] — level M. */
    private const EC_M = [
        1  => [10, [[1, 16]]],
        2  => [16, [[1, 28]]],
        3  => [26, [[1, 44]]],
        4  => [18, [[2, 32]]],
        5  => [24, [[2, 43]]],
        6  => [16, [[4, 27]]],
        7  => [18, [[4, 31]]],
        8  => [22, [[2, 38], [2, 39]]],
        9  => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    /** Alignment-pattern centre coordinates per version. */
    private const ALIGN = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Remainder bits appended after the interleaved codeword stream. */
    private const REMAINDER = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7,
                              7 => 0, 8 => 0, 9 => 0, 10 => 0];

    /** Format-info EC indicator for level M. */
    private const EC_LEVEL_BITS = 0b00;

    /* ═══════════════════════════════════════════════════════════════════════
       PUBLIC API
       ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Encode $text and return the finished module matrix as a 2-D array of
     * 0/1 ints indexed [row][col]. Throws when the payload is too long.
     */
    public static function matrix(string $text): array
    {
        $version = self::pickVersion(strlen($text));
        $bits    = self::buildBitStream($text, $version);
        $stream  = self::interleave($bits, $version);

        [$m, $reserved] = self::baseMatrix($version);
        self::placeData($m, $reserved, $stream, $version);

        // Try all 8 masks, keep the lowest-penalty result (spec requirement —
        // a bad mask produces large same-colour blobs that scanners misread).
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $cand = self::applyMask($m, $reserved, $mask);
            self::placeFormat($cand, $mask, $version);
            $score = self::penalty($cand);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best      = $cand;
            }
        }
        return $best;
    }

    /**
     * Binary PNG string. $scale = pixels per module, $margin = quiet-zone
     * modules (4 is the spec minimum and is what makes phone scanning reliable).
     */
    public static function png(string $text, int $scale = 8, int $margin = 4): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD extension is required for PNG output.');
        }
        $m     = self::matrix($text);
        $count = count($m);
        $scale = max(1, $scale);
        $margin = max(0, $margin);
        $px    = ($count + $margin * 2) * $scale;

        $img   = imagecreatetruecolor($px, $px);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $px - 1, $px - 1, $white);

        for ($r = 0; $r < $count; $r++) {
            for ($c = 0; $c < $count; $c++) {
                if ($m[$r][$c]) {
                    $x = ($c + $margin) * $scale;
                    $y = ($r + $margin) * $scale;
                    imagefilledrectangle($img, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($img, null, 9);
        $bin = (string) ob_get_clean();
        imagedestroy($img);
        return $bin;
    }

    /** `data:image/png;base64,…` — safe to inline in HTML, print and html2canvas. */
    public static function dataUri(string $text, int $scale = 8, int $margin = 4): string
    {
        try {
            return 'data:image/png;base64,' . base64_encode(self::png($text, $scale, $margin));
        } catch (Throwable $e) {
            // No GD → fall back to an inline SVG data URI (renders identically).
            return 'data:image/svg+xml;base64,' . base64_encode(self::svg($text, $scale, $margin));
        }
    }

    /** Scalable SVG string — used when GD is unavailable. */
    public static function svg(string $text, int $scale = 8, int $margin = 4): string
    {
        $m     = self::matrix($text);
        $count = count($m);
        $size  = ($count + $margin * 2) * $scale;

        $path = '';
        for ($r = 0; $r < $count; $r++) {
            for ($c = 0; $c < $count; $c++) {
                if ($m[$r][$c]) {
                    $x = ($c + $margin) * $scale;
                    $y = ($r + $margin) * $scale;
                    $path .= "M{$x} {$y}h{$scale}v{$scale}h-{$scale}z";
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" '
             . 'viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges">'
             . '<rect width="100%" height="100%" fill="#fff"/>'
             . '<path d="' . $path . '" fill="#000"/></svg>';
    }

    /* ═══════════════════════════════════════════════════════════════════════
       ENCODING
       ═══════════════════════════════════════════════════════════════════════ */

    /** Total data codewords available at a version (level M). */
    private static function dataCodewords(int $version): int
    {
        $total = 0;
        foreach (self::EC_M[$version][1] as [$blocks, $cw]) $total += $blocks * $cw;
        return $total;
    }

    /** Smallest version that fits $len bytes in byte mode at level M. */
    private static function pickVersion(int $len): int
    {
        foreach (array_keys(self::EC_M) as $v) {
            $cci = ($v <= 9) ? 8 : 16;                       // character-count indicator width
            if (4 + $cci + 8 * $len <= self::dataCodewords($v) * 8) return $v;
        }
        throw new RuntimeException('QR payload too long (' . $len . ' bytes); max 213 at level M.');
    }

    /** Mode + count + data + terminator + padding → array of data codewords. */
    private static function buildBitStream(string $text, int $version): array
    {
        $len      = strlen($text);
        $capacity = self::dataCodewords($version) * 8;
        $cci      = ($version <= 9) ? 8 : 16;

        $bits = [];
        $push = function (int $value, int $width) use (&$bits): void {
            for ($i = $width - 1; $i >= 0; $i--) $bits[] = ($value >> $i) & 1;
        };

        $push(0b0100, 4);            // byte mode
        $push($len, $cci);
        for ($i = 0; $i < $len; $i++) $push(ord($text[$i]), 8);

        // Terminator (up to 4 zero bits), then pad to a byte boundary.
        for ($i = 0, $n = min(4, $capacity - count($bits)); $i < $n; $i++) $bits[] = 0;
        while (count($bits) % 8 !== 0) $bits[] = 0;

        // Pad codewords alternate 0xEC / 0x11 until the block is full.
        $pad = [0xEC, 0x11];
        $p   = 0;
        while (count($bits) < $capacity) {
            $push($pad[$p % 2], 8);
            $p++;
        }

        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($b = 0; $b < 8; $b++) $byte = ($byte << 1) | $bits[$i + $b];
            $codewords[] = $byte;
        }
        return $codewords;
    }

    /** Split into blocks, add Reed-Solomon ECC, interleave → final bit array. */
    private static function interleave(array $codewords, int $version): array
    {
        [$ecPerBlock, $groups] = self::EC_M[$version];

        $dataBlocks = [];
        $ecBlocks   = [];
        $offset     = 0;
        foreach ($groups as [$blockCount, $blockSize]) {
            for ($b = 0; $b < $blockCount; $b++) {
                $chunk        = array_slice($codewords, $offset, $blockSize);
                $offset      += $blockSize;
                $dataBlocks[] = $chunk;
                $ecBlocks[]   = self::rsEncode($chunk, $ecPerBlock);
            }
        }

        $out     = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $blk) {
                if (isset($blk[$i])) $out[] = $blk[$i];
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $blk) $out[] = $blk[$i];
        }

        $bits = [];
        foreach ($out as $byte) {
            for ($i = 7; $i >= 0; $i--) $bits[] = ($byte >> $i) & 1;
        }
        for ($i = 0; $i < self::REMAINDER[$version]; $i++) $bits[] = 0;
        return $bits;
    }

    /* ── Reed-Solomon over GF(256), primitive polynomial 0x11D ─────────────── */

    private static function gf(): array
    {
        static $exp = null, $log = null;
        if ($exp === null) {
            $exp = array_fill(0, 512, 0);
            $log = array_fill(0, 256, 0);
            $x   = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $x;
                $log[$x] = $i;
                $x <<= 1;
                if ($x & 0x100) $x ^= 0x11D;
            }
            for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
        }
        return [$exp, $log];
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        [$exp, $log] = self::gf();
        return $exp[$log[$a] + $log[$b]];
    }

    /** Generator polynomial of the given degree. */
    private static function rsGenerator(int $degree): array
    {
        [$exp,] = self::gf();
        $poly = [1];
        for ($d = 0; $d < $degree; $d++) {
            $next = array_fill(0, count($poly) + 1, 0);
            // Multiply by (x + α^d). Coefficients are stored highest-degree first,
            // so the x term keeps the index and the α^d term shifts down one.
            foreach ($poly as $i => $coeff) {
                $next[$i]     ^= $coeff;
                $next[$i + 1] ^= self::gfMul($coeff, $exp[$d]);
            }
            $poly = $next;
        }
        return $poly;
    }

    private static function rsEncode(array $data, int $ecLen): array
    {
        $gen       = self::rsGenerator($ecLen);
        $remainder = array_fill(0, $ecLen, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;
            for ($i = 0; $i < $ecLen; $i++) {
                $remainder[$i] ^= self::gfMul($gen[$i + 1], $factor);
            }
        }
        return $remainder;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       MATRIX
       ═══════════════════════════════════════════════════════════════════════ */

    /** Function patterns + reserved-area map. Returns [matrix, reserved]. */
    private static function baseMatrix(int $version): array
    {
        $size     = $version * 4 + 17;
        $m        = array_fill(0, $size, array_fill(0, $size, 0));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // Finder patterns + separators.
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$fr, $fc]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $fr + $r;
                    $cc = $fc + $c;
                    if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) continue;
                    $inRing = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                           || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6));
                    $inCore = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                    $m[$rr][$cc]        = ($inRing || $inCore) ? 1 : 0;
                    $reserved[$rr][$cc] = true;
                }
            }
        }

        // Timing patterns.
        for ($i = 8; $i < $size - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;
            $m[6][$i] = $bit;  $reserved[6][$i] = true;
            $m[$i][6] = $bit;  $reserved[$i][6] = true;
        }

        // Alignment patterns (skipped where they would overlap a finder).
        $centres = self::ALIGN[$version];
        foreach ($centres as $ar) {
            foreach ($centres as $ac) {
                if (($ar === 6 && $ac === 6)
                 || ($ar === 6 && $ac === $size - 7)
                 || ($ar === $size - 7 && $ac === 6)) continue;
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $rr = $ar + $r;
                        $cc = $ac + $c;
                        $on = (max(abs($r), abs($c)) !== 1) ? 1 : 0;   // ring at distance 2, centre dot
                        $m[$rr][$cc]        = $on;
                        $reserved[$rr][$cc] = true;
                    }
                }
            }
        }

        // Dark module — always set, never data.
        $m[$size - 8][8]        = 1;
        $reserved[$size - 8][8] = true;

        // Reserve the two format-info areas.
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) { $reserved[8][$i] = true; $reserved[$i][8] = true; }
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i]  = true;
            $reserved[$size - 1 - $i][8]  = true;
        }

        // Reserve the version-info areas (version 7+).
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $reserved[$size - 11 + $j][$i] = true;
                    $reserved[$i][$size - 11 + $j] = true;
                }
            }
        }

        return [$m, $reserved];
    }

    /** Zig-zag data placement, right to left, skipping the vertical timing column. */
    private static function placeData(array &$m, array $reserved, array $bits, int $version): void
    {
        $size = $version * 4 + 17;
        $idx  = 0;
        $up   = true;

        for ($right = $size - 1; $right > 0; $right -= 2) {
            if ($right === 6) $right = 5;                       // skip the timing column
            for ($v = 0; $v < $size; $v++) {
                $row = $up ? ($size - 1 - $v) : $v;
                for ($k = 0; $k < 2; $k++) {
                    $col = $right - $k;
                    if ($reserved[$row][$col]) continue;
                    $m[$row][$col] = $bits[$idx] ?? 0;
                    $idx++;
                }
            }
            $up = !$up;
        }
    }

    private static function maskBit(int $mask, int $r, int $c): bool
    {
        switch ($mask) {
            case 0: return ($r + $c) % 2 === 0;
            case 1: return $r % 2 === 0;
            case 2: return $c % 3 === 0;
            case 3: return ($r + $c) % 3 === 0;
            case 4: return (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0;
            case 5: return (($r * $c) % 2) + (($r * $c) % 3) === 0;
            case 6: return ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0;
            default: return ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0;
        }
    }

    private static function applyMask(array $m, array $reserved, int $mask): array
    {
        $size = count($m);
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($reserved[$r][$c]) continue;
                if (self::maskBit($mask, $r, $c)) $m[$r][$c] ^= 1;
            }
        }
        return $m;
    }

    /** BCH-protected format info (and version info for v7+) written into the matrix. */
    private static function placeFormat(array &$m, int $mask, int $version): void
    {
        $size = count($m);

        // ── Format info: 5 data bits → BCH(15,5), then XOR the spec mask ──────
        $data = (self::EC_LEVEL_BITS << 3) | $mask;
        $rem  = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $format = ((($data << 10) | $rem) ^ 0x5412) & 0x7FFF;

        // Copy 1 — down column 8, then left along row 8.
        for ($i = 0; $i <= 5; $i++)  $m[$i][8] = ($format >> $i) & 1;
        $m[7][8] = ($format >> 6) & 1;
        $m[8][8] = ($format >> 7) & 1;
        $m[8][7] = ($format >> 8) & 1;
        for ($i = 9; $i <= 14; $i++) $m[8][14 - $i] = ($format >> $i) & 1;

        // Copy 2 — right-to-left along row 8, then up column 8 from the bottom.
        for ($i = 0; $i <= 7; $i++)  $m[8][$size - 1 - $i]  = ($format >> $i) & 1;
        for ($i = 8; $i <= 14; $i++) $m[$size - 15 + $i][8] = ($format >> $i) & 1;

        $m[$size - 8][8] = 1;                                  // dark module

        // ── Version info: 6 data bits → BCH(18,6) ────────────────────────────
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
            }
            $vinfo = ($version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = ($vinfo >> $i) & 1;
                $m[$size - 11 + ($i % 3)][intdiv($i, 3)] = $bit;
                $m[intdiv($i, 3)][$size - 11 + ($i % 3)] = $bit;
            }
        }
    }

    /** The four spec penalty rules — lower is better. */
    private static function penalty(array $m): int
    {
        $size  = count($m);
        $score = 0;

        // Rule 1 — runs of 5+ identical modules in a row or column.
        for ($i = 0; $i < $size; $i++) {
            foreach ([true, false] as $isRow) {
                $run = 1;
                for ($j = 1; $j < $size; $j++) {
                    $cur  = $isRow ? $m[$i][$j]     : $m[$j][$i];
                    $prev = $isRow ? $m[$i][$j - 1] : $m[$j - 1][$i];
                    if ($cur === $prev) {
                        $run++;
                    } else {
                        if ($run >= 5) $score += 3 + ($run - 5);
                        $run = 1;
                    }
                }
                if ($run >= 5) $score += 3 + ($run - 5);
            }
        }

        // Rule 2 — 2×2 blocks of one colour.
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $m[$r][$c];
                if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                    $score += 3;
                }
            }
        }

        // Rule 3 — finder-lookalike 1011101 with 4 light modules on either side.
        $p1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $p2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j <= $size - 11; $j++) {
                $rowSeg = [];
                $colSeg = [];
                for ($k = 0; $k < 11; $k++) {
                    $rowSeg[] = $m[$i][$j + $k];
                    $colSeg[] = $m[$j + $k][$i];
                }
                if ($rowSeg === $p1 || $rowSeg === $p2) $score += 40;
                if ($colSeg === $p1 || $colSeg === $p2) $score += 40;
            }
        }

        // Rule 4 — deviation from a 50/50 dark/light balance.
        $dark = 0;
        foreach ($m as $row) $dark += array_sum($row);
        $ratio = (int) floor(abs(($dark * 100) / ($size * $size) - 50) / 5);
        $score += $ratio * 10;

        return $score;
    }
}
