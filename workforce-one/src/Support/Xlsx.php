<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/**
 * A plain Excel workbook, written and read (3.31.98, the Org chart's managers file). Writing: per sheet
 * a name, headers, rows and column widths; numbers stay numbers, text that a spreadsheet would run as
 * a formula is kept as text. Reading: the first sheet's cells as text, row by row (shared strings,
 * inline strings, numbers; empty cells in between kept). A .csv file (comma or semicolon) is read too.
 * Needs the PHP ZipArchive extension for .xlsx. No WordPress calls.
 */
final class Xlsx
{
    public static function available(): bool
    {
        return class_exists('ZipArchive');
    }

    /** @param list<array{0:string,1:list<string>,2:list<list<mixed>>,3?:list<int>}> $sheets */
    public static function write(array $sheets): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'wfo');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Could not create the workbook.');
        $entries = ''; $rels = ''; $overrides = '';
        foreach (array_values($sheets) as $i => $sheet) {
            [$name, $headers, $rows] = $sheet;
            $widths = $sheet[3] ?? [];
            $n = $i + 1;
            $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
            foreach ($headers as $c => $h) $xml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (int) ($widths[$c] ?? 16) . '" customWidth="1"/>';
            $xml .= '</cols><sheetData>';
            foreach (array_merge([$headers], $rows) as $r => $row) {
                $xml .= '<row r="' . ($r + 1) . '">';
                foreach (array_values($row) as $c => $v) {
                    $ref = self::col($c + 1) . ($r + 1);
                    if ($r > 0 && (is_int($v) || is_float($v))) $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                    else $xml .= '<c r="' . $ref . '" s="' . ($r === 0 ? 1 : 0) . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc($v) . '</t></is></c>';
                }
                $xml .= '</row>';
            }
            $xml .= '</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . $xml);
            $sname = function_exists('mb_substr') ? mb_substr($name, 0, 31) : substr($name, 0, 31);
            $entries .= '<sheet name="' . self::esc($sname) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $rels .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $overrides . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $entries . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEEF2FF"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    /**
     * The first sheet of an .xlsx file, or a .csv file, as rows of text. Null when the file cannot be read.
     * @return list<list<string>>|null
     */
    public static function read(string $path, string $name = ''): ?array
    {
        $head = (string) @file_get_contents($path, false, null, 0, 4);
        if (substr($head, 0, 2) !== 'PK') return self::readCsv($path);
        if (!self::available()) return null;
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return null;
        // A small file that unzips to something huge is refused (50 MB per part is far more than 5,000 rows).
        foreach (['xl/sharedStrings.xml', self::firstSheet($zip)] as $part) {
            $st = $part !== '' ? $zip->statName($part) : false;
            if ($st && (int) $st['size'] > 50 * 1024 * 1024) { $zip->close(); return null; }
        }
        $strings = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = self::xml($ss);
            if ($x) foreach ($x->si as $si) $strings[] = self::text($si);
        }
        $sheet = self::firstSheet($zip);
        $raw = $sheet !== '' ? $zip->getFromName($sheet) : false;
        $zip->close();
        if ($raw === false) return null;
        $x = self::xml($raw);
        if (!$x || !isset($x->sheetData)) return null;
        $rows = [];
        foreach ($x->sheetData->row as $row) {
            $r = isset($row['r']) ? (int) $row['r'] : count($rows) + 1;
            $cells = [];
            $next = 0;
            foreach ($row->c as $c) {
                $idx = isset($c['r']) ? self::colIndex((string) $c['r']) : $next;
                $t = (string) ($c['t'] ?? '');
                if ($t === 's') $v = $strings[(int) $c->v] ?? '';
                elseif ($t === 'inlineStr') $v = self::text($c->is);
                elseif ($t === 'b') $v = ((string) $c->v) === '1' ? 'TRUE' : 'FALSE';
                else $v = (string) $c->v;
                if ($t === '' && is_numeric($v) && (float) $v == (int) $v && abs((float) $v) < 1e15) $v = (string) (int) $v;
                for ($k = count($cells); $k < $idx; $k++) $cells[$k] = '';
                $cells[$idx] = $v;
                $next = $idx + 1;
            }
            for ($k = count($rows); $k < $r - 1; $k++) $rows[] = [];
            $rows[] = array_values($cells);
            if (count($rows) > 20000) break;
        }
        return $rows;
    }

    /** @return list<list<string>>|null */
    private static function readCsv(string $path): ?array
    {
        $fh = @fopen($path, 'r');
        if (!$fh) return null;
        $first = (string) fgets($fh);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
            if (!$rows && isset($r[0])) $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $r[0]);
            $rows[] = array_map(static function ($v) { $v = (string) $v; return strpos($v, "'") === 0 ? substr($v, 1) : $v; }, $r);
            if (count($rows) > 20000) break;
        }
        fclose($fh);
        // A file saved by Excel in Arabic Windows is not UTF-8.
        if (function_exists('mb_check_encoding')) {
            foreach ($rows as $i => $r) if (!mb_check_encoding(implode('', $r), 'UTF-8')) $rows[$i] = array_map(static function ($v) { return (string) mb_convert_encoding($v, 'UTF-8', 'Windows-1256'); }, $r);
        }
        return $rows;
    }

    private static function firstSheet(\ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $w = self::xml($wb);
            $r = self::xml($rels);
            if ($w && $r && isset($w->sheets->sheet[0])) {
                $rid = (string) $w->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($r->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $t = ltrim((string) $rel['Target'], '/');
                        return strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
                    }
                }
            }
        }
        return $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : '';
    }

    private static function xml(string $s): ?\SimpleXMLElement
    {
        if (stripos($s, '<!DOCTYPE') !== false) return null;   // no entities
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        return $x ?: null;
    }

    private static function text(?\SimpleXMLElement $node): string
    {
        if (!$node) return '';
        if (isset($node->t)) {
            $out = (string) $node->t;
            if (!isset($node->r)) return $out;
        }
        $out = '';
        foreach ($node->r as $run) $out .= (string) $run->t;
        return $out !== '' ? $out : (string) ($node->t ?? '');
    }

    private static function colIndex(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $n = 0;
        foreach (str_split((string) $letters) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return max(0, $n - 1);
    }

    public static function col(int $n): string
    {
        $s = '';
        while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
        return $s;
    }

    /** Text for a cell: XML-escaped, and a leading = + - @ kept as text by Excel (inline strings are never formulas). */
    private static function esc($v): string
    {
        return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
