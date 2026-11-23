<?php
/**
 * FIS Real Estate — read an uploaded CSV or .xlsx into rows (part 40).
 *
 * No third-party library: .xlsx is a zip of XML, read with ZipArchive and
 * SimpleXML, both bundled with PHP. Only the FIRST worksheet is read, and only
 * cell values — formulas come back as their last calculated value, which is
 * what the person saw in Excel.
 *
 * Dates are NOT converted here. Excel stores a date as a day count, and only
 * the column definition knows a column holds dates, so the engine converts
 * them with importParseDate(), using the 1904 flag this returns.
 */

const IMPORT_MAX_ROWS = 5000;

/**
 * @return array ['ok'=>bool, 'error'=>string|null, 'headers'=>string[], 'rows'=>array[], 'date1904'=>bool]
 *               rows are 0-indexed lists of strings; 'line' gives each row's line in the file
 */
function importReadFile($path, $originalName) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') return importReadCsv($path);
    if ($ext === 'xlsx') return importReadXlsx($path);
    if ($ext === 'xls') {
        return importFail(__('imp_err_xls', 'Old .xls files cannot be read. In Excel use File > Save As and choose "Excel Workbook (.xlsx)" or "CSV".'));
    }
    return importFail(__('imp_err_type', 'Upload a .csv or .xlsx file.'));
}

function importFail($message) {
    return ['ok' => false, 'error' => $message, 'headers' => [], 'rows' => [], 'date1904' => false];
}

function importReadCsv($path) {
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return importFail(__('imp_err_empty', 'The file is empty.'));

    // Excel on Windows saves CSV in the ANSI code page unless told otherwise,
    // which turns names like "Zuhura" fine but "Müller" into garbage.
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');

    // Regional Excel uses ; where the decimal mark is a comma. Pick whichever
    // separator splits the header line into the most columns.
    $firstLine = strtok($raw, "\r\n");
    $delim = ',';
    $best = 0;
    foreach ([',', ';', "\t"] as $d) {
        $n = count(str_getcsv($firstLine, $d));
        if ($n > $best) { $best = $n; $delim = $d; }
    }

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);

    $headers = null;
    $rows = [];
    $line = 0;
    while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
        $line++;
        $cells = array_map(function ($c) { return trim((string) $c); }, $cells);
        if (!array_filter($cells, 'strlen')) continue;
        if ($headers === null) { $headers = $cells; continue; }
        if (count($rows) >= IMPORT_MAX_ROWS) {
            fclose($fh);
            return importFail(sprintf(__('imp_err_too_many', 'The file has more than %d rows. Split it into smaller files.'), IMPORT_MAX_ROWS));
        }
        $rows[] = ['line' => $line, 'cells' => $cells];
    }
    fclose($fh);

    if ($headers === null) return importFail(__('imp_err_empty', 'The file is empty.'));
    return ['ok' => true, 'error' => null, 'headers' => $headers, 'rows' => $rows, 'date1904' => false];
}

function importReadXlsx($path) {
    if (!class_exists('ZipArchive')) {
        return importFail(__('imp_err_nozip', 'This server cannot read Excel files. Save the sheet as CSV and upload that instead.'));
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return importFail(__('imp_err_xlsx', 'This does not look like a valid Excel file. Save it again as .xlsx or CSV.'));
    }

    $workbook = $zip->getFromName('xl/workbook.xml');
    if ($workbook === false) {
        $zip->close();
        return importFail(__('imp_err_xlsx', 'This does not look like a valid Excel file. Save it again as .xlsx or CSV.'));
    }
    $wb = simplexml_load_string($workbook);
    $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $date1904 = false;
    $pr = $wb->xpath('//m:workbookPr');
    if ($pr && in_array(strtolower((string) ($pr[0]['date1904'] ?? '')), ['1', 'true'], true)) $date1904 = true;

    // First sheet in tab order, resolved through the workbook relationships
    // rather than assuming sheet1.xml — a reordered workbook breaks that.
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $sheets = $wb->xpath('//m:sheets/m:sheet');
    if ($sheets) {
        $rid = (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($rid !== '' && $rels !== false) {
            $relXml = simplexml_load_string($rels);
            foreach ($relXml->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');
                    $sheetPath = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                    break;
                }
            }
        }
    }

    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $ssXml = simplexml_load_string($ss);
        foreach ($ssXml->si as $si) {
            // Rich text arrives as several <r><t> runs; plain text as one <t>.
            if (isset($si->t)) {
                $shared[] = (string) $si->t;
            } else {
                $text = '';
                foreach ($si->r as $run) $text .= (string) $run->t;
                $shared[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) return importFail(__('imp_err_empty', 'The file is empty.'));

    $sheet = simplexml_load_string($sheetXml);
    $headers = null;
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $col = importColumnIndex((string) $c['r']);
            $type = (string) $c['t'];
            if ($type === 's') {
                $value = $shared[(int) $c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = isset($c->is->t) ? (string) $c->is->t : '';
            } elseif ($type === 'b') {
                $value = ((string) $c->v) === '1' ? 'TRUE' : 'FALSE';
            } elseif ($type === 'e') {
                $value = '';
            } else {
                $value = (string) $c->v;
            }
            $cells[$col] = trim($value);
        }
        if (!$cells || !array_filter($cells, 'strlen')) continue;

        $max = max(array_keys($cells));
        $list = [];
        for ($i = 0; $i <= $max; $i++) $list[] = $cells[$i] ?? '';

        if ($headers === null) { $headers = $list; continue; }
        if (count($rows) >= IMPORT_MAX_ROWS) {
            return importFail(sprintf(__('imp_err_too_many', 'The file has more than %d rows. Split it into smaller files.'), IMPORT_MAX_ROWS));
        }
        $rows[] = ['line' => (int) $row['r'], 'cells' => $list];
    }

    if ($headers === null) return importFail(__('imp_err_empty', 'The file is empty.'));
    return ['ok' => true, 'error' => null, 'headers' => $headers, 'rows' => $rows, 'date1904' => $date1904];
}

/** "AB12" -> 27 (0-based column). */
function importColumnIndex($ref) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $n - 1);
}
