<?php

namespace App\Support\Xlsx;

use RuntimeException;
use ZipArchive;

/**
 * Minimal .xlsx writer (needs PHP's zip extension, nothing else). Text, numbers, a bold header row, text-formatted columns
 * (so Excel keeps employee numbers like 00123 as typed), drop-down lists and a frozen header. Not a general spreadsheet library.
 */
final class XlsxWriter
{
    public const STYLE_HEADER = 2;
    public const STYLE_WRAP = 3;
    public const STYLE_BOLD = 4;

    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @var array<int, array{name: string, rows: array, opts: array}> */
    private array $sheets = [];

    /**
     * @param  array<int, array<int, scalar|array{0: scalar, 1: int}|null>>  $rows  a cell is a value, or [value, style]; ints/floats are written as numbers
     * @param  array{widths?: array<int, int>, textColumns?: int[], freezeHeader?: bool, validations?: array<int, array{sqref: string, formula?: string, list?: string, message?: string}>}  $opts
     */
    public function addSheet(string $name, array $rows, array $opts = []): static
    {
        $this->sheets[] = ['name' => mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name), 0, 31), 'rows' => $rows, 'opts' => $opts];

        return $this;
    }

    public static function colLetter(int $i): string
    {
        $s = '';
        while ($i > 0) {
            $i--;
            $s = chr(65 + $i % 26).$s;
            $i = intdiv($i, 26);
        }

        return $s;
    }

    public function toBinary(): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required for Excel files. Enable extension=zip in php.ini.');
        }
        if (! $this->sheets) {
            throw new RuntimeException('A workbook needs at least one sheet.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $n = count($this->sheets);
        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wbSheets = $wbRels = '';
        foreach ($this->sheets as $i => $s) {
            $k = $i + 1;
            $types .= "<Override PartName=\"/xl/worksheets/sheet{$k}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $wbSheets .= '<sheet name="'.$this->esc($s['name'])."\" sheetId=\"{$k}\" r:id=\"rId{$k}\"/>";
            $wbRels .= "<Relationship Id=\"rId{$k}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$k}.xml\"/>";
            $zip->addFromString("xl/worksheets/sheet{$k}.xml", $this->sheetXml($s['rows'], $s['opts']));
        }
        $types .= '</Types>';
        $wbRels .= '<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml', $types);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$wbSheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$wbRels.'</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->close();

        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }

    private function sheetXml(array $rows, array $o): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="'.self::NS.'"><sheetViews><sheetView workbookViewId="0">';
        if ($o['freezeHeader'] ?? false) {
            $x .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
        }
        $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';

        $widths = $o['widths'] ?? [];
        $text = array_flip($o['textColumns'] ?? []);
        $cols = '';
        $max = max(array_merge([0], array_keys($widths), array_keys($text)));
        for ($c = 1; $c <= $max; $c++) {
            if (isset($widths[$c]) || isset($text[$c])) {
                $cols .= '<col min="'.$c.'" max="'.$c.'" width="'.($widths[$c] ?? 14).'" customWidth="1"'.(isset($text[$c]) ? ' style="1"' : '').'/>';
            }
        }
        if ($cols !== '') {
            $x .= "<cols>{$cols}</cols>";
        }

        $x .= '<sheetData>';
        foreach (array_values($rows) as $r => $row) {
            $rn = $r + 1;
            $x .= "<row r=\"{$rn}\">";
            foreach (array_values($row) as $c => $cell) {
                [$v, $style] = is_array($cell) ? [$cell[0], $cell[1] ?? 0] : [$cell, 0];
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = self::colLetter($c + 1).$rn;
                $s = $style ? " s=\"{$style}\"" : '';
                $x .= is_int($v) || is_float($v)
                    ? "<c r=\"{$ref}\"{$s}><v>{$v}</v></c>"
                    : "<c r=\"{$ref}\"{$s} t=\"inlineStr\"><is><t xml:space=\"preserve\">".$this->esc((string) $v).'</t></is></c>';
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';

        $val = $o['validations'] ?? [];
        if ($val) {
            $x .= '<dataValidations count="'.count($val).'">';
            foreach ($val as $v) {
                $f = isset($v['list']) ? '"'.$this->esc($v['list']).'"' : $this->esc($v['formula']);
                $x .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Not allowed" error="'.$this->esc($v['message'] ?? 'Choose a value from the list.').'" sqref="'.$this->esc($v['sqref'])."\"><formula1>{$f}</formula1></dataValidation>";
            }
            $x .= '</dataValidations>';
        }

        return $x.'</worksheet>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="'.self::NS.'">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD5ECE9"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="5">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
