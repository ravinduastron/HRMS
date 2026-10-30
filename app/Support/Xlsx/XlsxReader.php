<?php

namespace App\Support\Xlsx;

use DateTimeImmutable;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Minimal .xlsx reader (needs PHP's zip and xml extensions). Reads ONE sheet into text: shared and inline strings, numbers,
 * booleans, and date-formatted cells (converted to Y-m-d). Formulas are read as their last calculated value. Macros, images
 * and everything else are ignored. Guards against oversized files.
 */
final class XlsxReader
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const MAX_PART_BYTES = 30 * 1024 * 1024;

    /** @return string[] sheet names in workbook order */
    public static function sheetNames(string $path): array
    {
        $zip = self::open($path);

        return array_keys(self::sheetMap($zip));
    }

    /**
     * @return array<int, array<int, string>> row number => [column number => text]; empty cells are left out
     */
    public static function read(string $path, ?string $sheet = null): array
    {
        $zip = self::open($path);
        $map = self::sheetMap($zip);
        if (! $map) {
            throw new RuntimeException('The workbook has no sheets.');
        }
        $target = $sheet !== null && isset($map[$sheet]) ? $map[$sheet] : reset($map);

        $shared = self::sharedStrings($zip);
        $dateStyles = self::dateStyles($zip);
        $xml = self::xml(self::part($zip, $target));
        $xml->registerXPathNamespace('m', self::NS);

        $rows = [];
        foreach ($xml->xpath('//m:sheetData/m:row') ?: [] as $row) {
            $rn = (int) $row['r'];
            foreach ($row->c as $c) {
                $col = self::colIndex((string) $c['r']);
                $rn = $rn ?: (int) preg_replace('/\D/', '', (string) $c['r']);
                $t = (string) $c['t'];
                $v = isset($c->v) ? (string) $c->v : null;

                if ($t === 's') {
                    $text = $shared[(int) $v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $text = self::runs($c->is);
                } elseif ($t === 'b') {
                    $text = $v === '1' ? 'TRUE' : 'FALSE';
                } elseif ($t === 'str' || $t === 'e' || $t === 'd') {
                    $text = (string) $v;
                } else {
                    $text = $v === null ? '' : trim($v);
                    if ($text !== '' && is_numeric($text) && ($dateStyles[(int) $c['s']] ?? false)) {
                        $text = self::serialToDate((float) $text);
                    }
                }
                if (trim($text) !== '') {
                    $rows[$rn][$col] = $text;
                }
            }
        }
        ksort($rows);

        return $rows;
    }

    public static function colIndex(string $ref): int
    {
        preg_match('/^[A-Z]+/i', $ref, $m);
        $n = 0;
        foreach (str_split(strtoupper($m[0] ?? 'A')) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n;
    }

    /* ------------------------------ internals ------------------------------ */

    private static function open(string $path): ZipArchive
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to read Excel files. Enable extension=zip in php.ini.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This is not a valid .xlsx file.');
        }
        if ($zip->numFiles > 500) {
            throw new RuntimeException('This Excel file has too many parts.');
        }

        return $zip;
    }

    private static function part(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            throw new RuntimeException("The Excel file is missing {$name}.");
        }
        if ($stat['size'] > self::MAX_PART_BYTES) {
            throw new RuntimeException('This Excel file is too large to read.');
        }

        return (string) $zip->getFromName($name);
    }

    private static function xml(string $s): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($s, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        if ($x === false) {
            throw new RuntimeException('The Excel file could not be read (damaged or not an .xlsx).');
        }

        return $x;
    }

    /** @return array<string, string> sheet name => part path */
    private static function sheetMap(ZipArchive $zip): array
    {
        $wb = self::xml(self::part($zip, 'xl/workbook.xml'));
        $rels = self::xml(self::part($zip, 'xl/_rels/workbook.xml.rels'));
        $targets = [];
        foreach ($rels->Relationship as $r) {
            $t = (string) $r['Target'];
            $targets[(string) $r['Id']] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/'.$t;
        }

        $out = [];
        $wb->registerXPathNamespace('m', self::NS);
        foreach ($wb->xpath('//m:sheets/m:sheet') ?: [] as $s) {
            $id = (string) $s->attributes(self::REL)['id'];
            if (isset($targets[$id])) {
                $out[(string) $s['name']] = $targets[$id];
            }
        }

        return $out;
    }

    private static function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->statName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $x = self::xml(self::part($zip, 'xl/sharedStrings.xml'));
        $out = [];
        foreach ($x->si as $si) {
            $out[] = self::runs($si);
        }

        return $out;
    }

    /** Text of a <si> / <is> element: a plain <t>, or several rich-text runs. */
    private static function runs(?SimpleXMLElement $el): string
    {
        if ($el === null) {
            return '';
        }
        if (isset($el->t)) {
            return (string) $el->t;
        }
        $s = '';
        foreach ($el->r as $r) {
            $s .= (string) $r->t;
        }

        return $s;
    }

    /** @return array<int, bool> cell style index => is it a date format */
    private static function dateStyles(ZipArchive $zip): array
    {
        if ($zip->statName('xl/styles.xml') === false) {
            return [];
        }
        $x = self::xml(self::part($zip, 'xl/styles.xml'));
        $custom = [];
        foreach ($x->numFmts->numFmt ?? [] as $f) {
            $custom[(int) $f['numFmtId']] = (string) $f['formatCode'];
        }

        $out = [];
        foreach ($x->cellXfs->xf ?? [] as $xf) { // the style index of a cell is the POSITION of its <xf>
            $id = (int) $xf['numFmtId'];
            $out[] = in_array($id, [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58], true)
                || (isset($custom[$id]) && self::looksLikeDate($custom[$id]));
        }

        return array_values($out);
    }

    private static function looksLikeDate(string $code): bool
    {
        $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code);

        return (bool) preg_match('/[dy]/i', $code);
    }

    private static function serialToDate(float $serial): string
    {
        return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int) floor($serial).' days')->format('Y-m-d');
    }
}
