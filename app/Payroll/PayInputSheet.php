<?php

namespace App\Payroll;

/**
 * Reads the pay-input Excel layout: one row per employee, one column per component (the column title is the component code).
 * Plain arrays in, plain arrays out: no database, no framework.
 */
final class PayInputSheet
{
    /** Titles of the employee-number column and of columns that are only there to help the person filling the sheet. */
    private const EMPLOYEE_NO = ['EMPLOYEENO', 'EMPLOYEENUMBER'];
    private const IGNORED = ['NAME', 'EMPLOYEENAME', 'FULLNAME'];

    /**
     * @param  array<int, array<int, string>>  $rows  row number => [column number => text] (XlsxReader::read)
     * @param  string[]  $codes  component codes that may be entered
     * @return array{employee_col: ?int, columns: array<int, string>, unknown: string[], entries: array<int, array{row: int, employee_no: string, code: string, raw: string}>}
     */
    public static function parse(array $rows, array $codes): array
    {
        $valid = array_flip(array_map('strtoupper', $codes));
        $out = ['employee_col' => null, 'columns' => [], 'unknown' => [], 'entries' => []];
        if (! $rows) {
            return $out;
        }

        $headerRow = array_key_first($rows);
        foreach ($rows[$headerRow] as $col => $title) {
            $t = strtoupper(trim($title));
            $letters = preg_replace('/[^A-Z]/', '', $t);
            if (in_array($letters, self::EMPLOYEE_NO, true)) {
                $out['employee_col'] ??= $col;
            } elseif (in_array($letters, self::IGNORED, true)) {
                continue;
            } elseif (isset($valid[$t])) {
                $out['columns'][$col] = $t;
            } else {
                $out['unknown'][] = trim($title);
            }
        }
        if ($out['employee_col'] === null) {
            return $out;
        }

        foreach (array_slice($rows, 1, null, true) as $rowNo => $cells) {
            $no = trim($cells[$out['employee_col']] ?? '');
            foreach ($out['columns'] as $col => $code) {
                $raw = trim($cells[$col] ?? '');
                if ($raw !== '') {
                    $out['entries'][] = ['row' => (int) $rowNo, 'employee_no' => $no, 'code' => $code, 'raw' => $raw];
                }
            }
        }

        return $out;
    }

    /** "1,500.5" => "1500.50". Null when it is not a number with at most 2 decimals (zero is allowed, negatives are not). */
    public static function amount(string $raw): ?string
    {
        $s = str_replace([',', ' '], '', trim($raw));
        if (! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $s)) {
            return null;
        }

        return Decimal::round(Decimal::make($s), 2);
    }
}
