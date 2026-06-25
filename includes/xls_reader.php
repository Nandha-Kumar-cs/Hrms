<?php
/**
 * Minimal legacy .xls reader (OLE2 compound file + BIFF8).
 *
 * Biometric attendance devices frequently export Excel 97-2003 (.xls) files —
 * sometimes even saved with an .xlsx extension. Those are NOT zip-based, so the
 * ZipArchive/SimpleXML XLSX parser cannot read them. This reader extracts the
 * "Workbook" stream from the OLE2 container and parses the BIFF8 records needed
 * for a plain data export: the shared-string table (SST), and the cell records
 * LABELSST / LABEL / RK / MULRK / NUMBER.
 *
 * It returns a 0-indexed grid (array of rows; each row a 0-indexed array of cell
 * string values) — the same shape the attendance importers walk for the
 * biometric block layouts. Returns [] when the file isn't a readable OLE2/BIFF.
 *
 * Scope: enough for tabular data exports. Charts, formulas (beyond cached string
 * results), and rich-text formatting are ignored.
 */

/** True when the file begins with the OLE2 compound-document magic number. */
function xls_is_ole2(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if (!$fh) return false;
    $magic = fread($fh, 8);
    fclose($fh);
    return $magic === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
}

/** Read a legacy .xls into a 0-indexed grid of cell strings. */
function xls_read_grid(string $path): array
{
    $data = @file_get_contents($path);
    if ($data === false || strlen($data) < 512) return [];
    if (substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") return [];

    $workbook = _xls_extract_workbook($data);
    if ($workbook === '') return [];

    return _xls_parse_biff($workbook);
}

/* ─── OLE2 compound-document extraction ──────────────────────────────────── */

function _xls_extract_workbook(string $data): string
{
    $u16 = fn($off) => unpack('v', substr($data, $off, 2))[1];
    $u32 = fn($off) => unpack('V', substr($data, $off, 4))[1];

    $secShift     = $u16(30);
    $miniShift    = $u16(32);
    $secSize      = 1 << $secShift;          // usually 512
    $miniSize     = 1 << $miniShift;         // usually 64
    $numFatSec    = $u32(44);
    $dirStart     = $u32(48);
    $miniCutoff   = $u32(56);
    $miniFatStart = $u32(60);
    $numMiniFat   = $u32(64);
    $difatStart   = $u32(68);
    $numDifat     = $u32(72);

    $sectorOffset = fn($sec) => ($sec + 1) * $secSize;
    $ENDOFCHAIN   = 0xFFFFFFFE;
    $FREESECT     = 0xFFFFFFFF;

    // 1) Build the DIFAT (list of FAT-sector numbers): 109 in the header + chain.
    $difat = [];
    for ($i = 0; $i < 109; $i++) {
        $s = $u32(76 + $i * 4);
        if ($s === $FREESECT || $s === $ENDOFCHAIN) continue;
        $difat[] = $s;
    }
    $sec = $difatStart;
    $guard = 0;
    while ($numDifat > 0 && $sec !== $ENDOFCHAIN && $sec !== $FREESECT && $guard++ < 100000) {
        $base = $sectorOffset($sec);
        if ($base + $secSize > strlen($data)) break;
        $entries = $secSize / 4;
        for ($i = 0; $i < $entries - 1; $i++) {
            $s = $u32($base + $i * 4);
            if ($s !== $FREESECT && $s !== $ENDOFCHAIN) $difat[] = $s;
        }
        $sec = $u32($base + ($entries - 1) * 4); // last slot chains to next DIFAT sector
    }

    // 2) Read the FAT array from the DIFAT sectors.
    $fat = [];
    foreach ($difat as $fs) {
        $base = $sectorOffset($fs);
        if ($base + $secSize > strlen($data)) continue;
        for ($i = 0; $i < $secSize / 4; $i++) $fat[] = $u32($base + $i * 4);
    }
    if (!$fat) return '';

    // Follow a FAT chain, returning the concatenated sector bytes.
    $readChain = function (int $start) use ($data, $fat, $sectorOffset, $secSize, $ENDOFCHAIN, $FREESECT): string {
        $out = ''; $sec = $start; $guard = 0;
        while ($sec !== $ENDOFCHAIN && $sec !== $FREESECT && isset($fat[$sec]) && $guard++ < 1000000) {
            $base = $sectorOffset($sec);
            if ($base + $secSize > strlen($data)) break;
            $out .= substr($data, $base, $secSize);
            $sec = $fat[$sec];
        }
        return $out;
    };

    // 3) Directory stream → entries (128 bytes each).
    $dir = $readChain($dirStart);
    if ($dir === '') return '';

    $entries = [];
    for ($off = 0; $off + 128 <= strlen($dir); $off += 128) {
        $nameLen = unpack('v', substr($dir, $off + 64, 2))[1];
        if ($nameLen <= 0) continue;
        $nameRaw = substr($dir, $off, max(0, $nameLen - 2));
        $name    = @iconv('UTF-16LE', 'UTF-8//IGNORE', $nameRaw) ?: '';
        $type    = ord($dir[$off + 66]);              // 1=storage 2=stream 5=root
        $startSc = unpack('V', substr($dir, $off + 116, 4))[1];
        $size    = unpack('V', substr($dir, $off + 120, 4))[1];
        $entries[] = ['name' => $name, 'type' => $type, 'start' => $startSc, 'size' => $size];
    }

    // 4) Root entry holds the mini-stream; find Workbook/Book entry.
    $root = null; $wb = null;
    foreach ($entries as $e) {
        if ($e['type'] === 5) $root = $e;
        if ($e['type'] === 2 && in_array(strtolower($e['name']), ['workbook', 'book'], true)) $wb = $e;
    }
    if (!$wb) return '';

    // 5a) Large stream → straight from the FAT chain.
    if ($wb['size'] >= $miniCutoff) {
        return substr($readChain($wb['start']), 0, $wb['size']);
    }

    // 5b) Small stream → from the mini-stream, chained via the mini-FAT.
    if (!$root) return '';
    $miniStream = $readChain($root['start']);

    $miniFatRaw = $readChain($miniFatStart);
    $miniFat = [];
    for ($i = 0; $i + 4 <= strlen($miniFatRaw); $i += 4) {
        $miniFat[] = unpack('V', substr($miniFatRaw, $i, 4))[1];
    }

    $out = ''; $sec = $wb['start']; $guard = 0;
    while ($sec !== $ENDOFCHAIN && $sec !== $FREESECT && isset($miniFat[$sec]) && $guard++ < 1000000) {
        $start = $sec * $miniSize;
        $out  .= substr($miniStream, $start, $miniSize);
        $sec   = $miniFat[$sec];
    }
    return substr($out, 0, $wb['size']);
}

/* ─── BIFF8 record parsing ───────────────────────────────────────────────── */

function _xls_parse_biff(string $wb): array
{
    $len = strlen($wb);

    // Pass 1: gather record offsets and locate the SST (+ its CONTINUE records).
    $records = [];
    $off = 0;
    while ($off + 4 <= $len) {
        $type = unpack('v', substr($wb, $off, 2))[1];
        $size = unpack('v', substr($wb, $off + 2, 2))[1];
        $records[] = ['type' => $type, 'pos' => $off + 4, 'size' => $size];
        $off += 4 + $size;
    }

    $sst = _xls_parse_sst($wb, $records);

    // Pass 2: collect cell values into a grid.
    $grid = [];
    $put = function (int $row, int $col, string $val) use (&$grid) {
        if ($val === '') return;
        $grid[$row][$col] = $val;
    };

    foreach ($records as $rec) {
        $p = $rec['pos']; $sz = $rec['size'];
        if ($p + $sz > $len) continue;
        switch ($rec['type']) {
            case 0x00FD: // LABELSST: row, col, xf, isst
                if ($sz < 10) break;
                $row = unpack('v', substr($wb, $p, 2))[1];
                $col = unpack('v', substr($wb, $p + 2, 2))[1];
                $isst = unpack('V', substr($wb, $p + 6, 4))[1];
                $put($row, $col, $sst[$isst] ?? '');
                break;

            case 0x0204: // LABEL: row, col, xf, unicode string
                if ($sz < 8) break;
                $row = unpack('v', substr($wb, $p, 2))[1];
                $col = unpack('v', substr($wb, $p + 2, 2))[1];
                [$str] = _xls_read_unicode($wb, $p + 6, $sz - 6);
                $put($row, $col, $str);
                break;

            case 0x027E: // RK number
                if ($sz < 10) break;
                $row = unpack('v', substr($wb, $p, 2))[1];
                $col = unpack('v', substr($wb, $p + 2, 2))[1];
                $rk  = unpack('V', substr($wb, $p + 6, 4))[1];
                $put($row, $col, _xls_num_str(_xls_rk_value($rk)));
                break;

            case 0x0203: // NUMBER (IEEE double)
                if ($sz < 14) break;
                $row = unpack('v', substr($wb, $p, 2))[1];
                $col = unpack('v', substr($wb, $p + 2, 2))[1];
                $dbl = unpack('d', substr($wb, $p + 6, 8))[1];
                $put($row, $col, _xls_num_str($dbl));
                break;

            case 0x00BD: // MULRK: row, firstCol, [xf, rk]..., lastCol
                if ($sz < 6) break;
                $row = unpack('v', substr($wb, $p, 2))[1];
                $colF = unpack('v', substr($wb, $p + 2, 2))[1];
                $count = (int)(($sz - 6) / 6);
                for ($i = 0; $i < $count; $i++) {
                    $rk = unpack('V', substr($wb, $p + 4 + $i * 6 + 2, 4))[1];
                    $put($row, $colF + $i, _xls_num_str(_xls_rk_value($rk)));
                }
                break;
        }
    }

    if (!$grid) return [];

    // Densify into a rectangular 0-indexed grid.
    ksort($grid);
    $out = [];
    foreach ($grid as $cells) {
        ksort($cells);
        $max = max(array_keys($cells));
        $row = [];
        for ($c = 0; $c <= $max; $c++) $row[] = $cells[$c] ?? '';
        $out[] = $row;
    }
    return $out;
}

/**
 * Parse the SST (shared string table), stitching across CONTINUE records.
 * Returns an ordered array of decoded strings.
 */
function _xls_parse_sst(string $wb, array $records): array
{
    // Build one contiguous byte buffer of the SST payload + CONTINUE payloads,
    // remembering where each CONTINUE began (a split string's grbit byte resets
    // the compression flag at a CONTINUE boundary).
    $sstIdx = null;
    foreach ($records as $i => $rec) {
        if ($rec['type'] === 0x00FC) { $sstIdx = $i; break; }
    }
    if ($sstIdx === null) return [];

    $buf = '';
    $breaks = [];                 // byte offsets in $buf where a CONTINUE starts
    $rec = $records[$sstIdx];
    $buf .= substr($wb, $rec['pos'], $rec['size']);
    for ($i = $sstIdx + 1; $i < count($records); $i++) {
        if ($records[$i]['type'] !== 0x003C) break; // CONTINUE
        $breaks[strlen($buf)] = true;
        $buf .= substr($wb, $records[$i]['pos'], $records[$i]['size']);
    }

    $total = unpack('V', substr($buf, 4, 4))[1]; // cstUnique
    $pos = 8;
    $blen = strlen($buf);
    $strings = [];

    for ($n = 0; $n < $total && $pos + 3 <= $blen; $n++) {
        $cch  = unpack('v', substr($buf, $pos, 2))[1]; // char count
        $grbit = ord($buf[$pos + 2]);
        $pos += 3;
        $is16   = ($grbit & 0x01) !== 0;
        $hasRT  = ($grbit & 0x08) !== 0;
        $hasExt = ($grbit & 0x04) !== 0;
        $rtRuns = 0; $extSize = 0;
        if ($hasRT)  { $rtRuns  = unpack('v', substr($buf, $pos, 2))[1]; $pos += 2; }
        if ($hasExt) { $extSize = unpack('V', substr($buf, $pos, 4))[1]; $pos += 4; }

        // Read $cch characters, honoring CONTINUE boundaries which reset grbit.
        $str = '';
        $remaining = $cch;
        while ($remaining > 0 && $pos <= $blen) {
            // How many chars fit before the next CONTINUE break?
            $nextBreak = $blen;
            foreach ($breaks as $bpos => $_) { if ($bpos > $pos) { $nextBreak = min($nextBreak, $bpos); } }
            $bytesPerChar = $is16 ? 2 : 1;
            $avail = intdiv($nextBreak - $pos, $bytesPerChar);
            $take  = min($remaining, max(0, $avail));
            if ($take > 0) {
                $chunk = substr($buf, $pos, $take * $bytesPerChar);
                $str  .= $is16 ? (@iconv('UTF-16LE', 'UTF-8//IGNORE', $chunk) ?: '') : _xls_latin1($chunk);
                $pos  += $take * $bytesPerChar;
                $remaining -= $take;
            }
            if ($remaining > 0) {
                // We hit a CONTINUE boundary; the next byte is a fresh grbit.
                if ($pos < $blen) { $is16 = (ord($buf[$pos]) & 0x01) !== 0; $pos += 1; }
                else break;
            }
        }

        // Skip rich-text runs / phonetic ext data trailing the characters.
        if ($hasRT)  $pos += 4 * $rtRuns;
        if ($hasExt) $pos += $extSize;

        $strings[] = $str;
    }
    return $strings;
}

/** Read a BIFF8 unicode string at $off (2-byte cch, 1-byte grbit, data). */
function _xls_read_unicode(string $buf, int $off, int $max): array
{
    if ($max < 3) return ['', $off];
    $cch  = unpack('v', substr($buf, $off, 2))[1];
    $grbit = ord($buf[$off + 2]);
    $p = $off + 3;
    $is16 = ($grbit & 0x01) !== 0;
    $bytes = $cch * ($is16 ? 2 : 1);
    $chunk = substr($buf, $p, $bytes);
    $str = $is16 ? (@iconv('UTF-16LE', 'UTF-8//IGNORE', $chunk) ?: '') : _xls_latin1($chunk);
    return [$str, $p + $bytes];
}

/** Decode an RK-encoded number. */
function _xls_rk_value(int $rk): float
{
    $isMul100 = $rk & 0x01;
    $isInt    = $rk & 0x02;
    if ($isInt) {
        $val = $rk >> 2;
        if ($val & 0x20000000) $val -= 0x40000000; // sign-extend 30-bit
        $num = (float)$val;
    } else {
        // Top 30 bits are the high bits of an IEEE double mantissa/exponent.
        $packed = pack('V', $rk & 0xFFFFFFFC);
        $num = unpack('d', "\x00\x00\x00\x00" . $packed)[1];
    }
    return $isMul100 ? $num / 100.0 : $num;
}

/** Format a numeric cell value without trailing-zero noise. */
function _xls_num_str(float $n): string
{
    if ($n == (int)$n) return (string)(int)$n;
    return rtrim(rtrim(sprintf('%.10f', $n), '0'), '.');
}

/** Convert a Latin-1/compressed byte run to UTF-8. */
function _xls_latin1(string $s): string
{
    return @iconv('Windows-1252', 'UTF-8//IGNORE', $s) ?: $s;
}
