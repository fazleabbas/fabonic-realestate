<?php
/**
 * FIS Real Estate — download an import template (part 40).
 *
 * Default: an .xlsx with two sheets. The first holds only the header row — the
 * importer reads the first sheet, and example rows in it would get imported by
 * mistake. The second, "Read Me", explains the import and every column with an
 * example, in the user's language. ?format=csv gives the bare header row.
 *
 * Written with ZipArchive, no spreadsheet library.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/import/engine.php';

if (!importCanUse()) {
    setAlert(__('imp_admins_only', 'Only admins can import data.'), 'danger');
    redirect('index.php');
}

$type = $_GET['type'] ?? '';
$def = importType($type);
if (!$def) redirect('modules/import/');

$cols = array_values(array_filter($def['columns'], function ($c) { return $c['template'] ?? true; }));

if (($_GET['format'] ?? '') === 'csv' || !class_exists('ZipArchive')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $type . '_template.csv"');
    // The BOM makes Excel open the file as UTF-8 rather than the ANSI code page.
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array_column($cols, 'key'));
    fclose($out);
    exit;
}

// What a filled-in cell looks like, per column. Shown on the Read Me sheet only.
$examples = [
    'property_name' => 'Tandika Plaza', 'property_type' => 'commercial', 'unit_number' => 'A12', 'unit_type' => 'shop',
    'tenant_name' => 'Asha Juma', 'first_name' => 'Asha', 'last_name' => 'Juma', 'phone' => '0712345678',
    'email' => 'asha@example.com', 'tin' => '123-456-789', 'id_number' => '19850101-12345-00001-12',
    'lease_start' => '01/07/2025', 'lease_end' => '30/06/2027', 'monthly_rent' => '500000',
    'payment_frequency' => 'half_yearly', 'due_day' => '5', 'currency' => 'TZS',
    'deposit_held' => '1000000', 'deposit_paid_date' => '01/07/2025',
    'charge_type' => 'rent', 'period_from' => '01/01/2026', 'period_to' => '30/06/2026', 'due_date' => '05/01/2026',
    'amount_owed' => '1500000', 'note' => 'Part paid in March', 'amount' => '1000000', 'paid_date' => '01/07/2025',
    'reference' => 'DEP-0042', 'account_code' => '1010', 'account_name' => 'CRDB Bank', 'debit' => '80000000', 'credit' => '',
];

// ---- cells ----
function xlsxCol($i) {
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}
function xlsxCell($col, $row, $text, $style = 0) {
    $ref = xlsxCol($col) . $row;
    $s = $style ? ' s="' . $style . '"' : '';
    if ($text === '' || $text === null) return $style ? '<c r="' . $ref . '"' . $s . '/>' : '';
    return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
         . htmlspecialchars((string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
}
function xlsxSheet($rows, $widths, $freezeTop = false) {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    if ($freezeTop) {
        $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
    }
    $xml .= '<cols>';
    foreach ($widths as $i => $w) $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $xml .= '</cols><sheetData>';
    foreach ($rows as $r => $cells) {
        $xml .= '<row r="' . ($r + 1) . '">';
        foreach ($cells as $c => $cell) {
            [$text, $style] = is_array($cell) ? $cell : [$cell, 0];
            $xml .= xlsxCell($c, $r + 1, $text, $style);
        }
        $xml .= '</row>';
    }
    return $xml . '</sheetData></worksheet>';
}
/** Sheet names: at most 31 characters, none of []:*?/\ */
function xlsxSheetName($s) {
    return mb_substr(preg_replace('/[\[\]:*?\/\\\\]/u', '', $s), 0, 31) ?: 'Data';
}

// Styles: 1 = header (bold, shaded), 2 = wrapped text, 3 = title, 4 = bold
$H = 1; $WRAP = 2; $TITLE = 3; $BOLD = 4;

// ---- sheet 1: the header row to fill in ----
$sheet1 = xlsxSheet([array_map(function ($c) use ($H) { return [$c['key'], $H]; }, $cols)],
                    array_map(function ($c) { return max(14, strlen($c['key']) + 4); }, $cols), true);

// ---- sheet 2: Read Me ----
$goLive = importGoLiveDate();
$rows = [];
$rows[] = [[$def['label'] . ' — ' . __('imp_readme', 'Read Me'), $TITLE]];
$rows[] = [];
$rows[] = [[__('imp_q_what', 'What it does'), $BOLD], [$def['what'], $WRAP]];
$rows[] = [[__('imp_q_where', 'Where it shows'), $BOLD], [$def['where'], $WRAP]];
$rows[] = [[__('imp_q_books', 'What it changes in your accounts'), $BOLD], [$def['books'], $WRAP]];
$rows[] = [[__('imp_q_not', 'What it does NOT do'), $BOLD], [$def['not'], $WRAP]];
if ($goLive) {
    $rows[] = [[__('imp_golive', 'Go-live date'), $BOLD],
               [sprintf(__('imp_rm_golive', 'Your go-live date is %s. Opening figures are dated %s.'), formatDate($goLive), formatDate(importOpeningDate())), $WRAP]];
}
$rows[] = [];
$rows[] = [[__('imp_rm_how', 'How to fill it in'), $TITLE]];
foreach ([
    __('imp_rm_r1', 'Enter your data on the first sheet, one row per record, under the header row. Keep the header row.'),
    __('imp_rm_r2', 'Only the first sheet is read. This Read Me sheet is ignored, so you can leave it here.'),
    __('imp_rm_r3', 'Columns can be in any order, and columns the system does not know are ignored. Leave optional columns empty if you have nothing for them.'),
    __('imp_rm_r4', 'Dates: dd/mm/yyyy (for example 31/12/2026) or an Excel date. Amounts: plain numbers, no currency sign.'),
    __('imp_rm_r5', 'Properties, units and tenants are matched by name and unit number, so spell them the same way every time.'),
    __('imp_rm_r6', 'Nothing is saved when you upload. You see a preview of every row first, and you must confirm before anything is imported.'),
] as $i => $rule) {
    $rows[] = [[(string) ($i + 1), 0], [$rule, $WRAP]];
}
$rows[] = [];
$rows[] = [[__('imp_column', 'Column'), $H], [__('imp_required', 'Required'), $H], [__('notes', 'Notes'), $H], [__('imp_rm_example', 'Example'), $H]];
foreach ($def['columns'] as $c) {
    $rows[] = [[$c['key'], $BOLD], [$c['required'] ? __('yes', 'Yes') : '', 0], [$c['help'], $WRAP], [$examples[$c['key']] ?? '', 0]];
}
$rows[] = [];
$rows[] = [[__('imp_columns_help', 'Headers are matched by name: order, capitals and spaces do not matter. Records are matched by name and unit number, never by ID.'), $WRAP]];
$sheet2 = xlsxSheet($rows, [26, 60, 60, 26]);

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
    . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/><bgColor indexed="64"/></patternFill></fill></fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="5">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
    . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top"/></xf>'
    . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

$names = [xlsxSheetName($def['label']), xlsxSheetName(__('imp_readme', 'Read Me'))];
if ($names[0] === $names[1]) $names[1] .= ' 2';
$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="' . htmlspecialchars($names[0], ENT_XML1 | ENT_QUOTES) . '" sheetId="1" r:id="rId1"/>'
    . '<sheet name="' . htmlspecialchars($names[1], ENT_XML1 | ENT_QUOTES) . '" sheetId="2" r:id="rId2"/></sheets></workbook>';

$tmp = tempnam(sys_get_temp_dir(), 'tpl');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '</Types>');
$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>');
$zip->addFromString('xl/workbook.xml', $workbook);
$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>');
$zip->addFromString('xl/styles.xml', $styles);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet1);
$zip->addFromString('xl/worksheets/sheet2.xml', $sheet2);
$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $type . '_template.xlsx"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
exit;
