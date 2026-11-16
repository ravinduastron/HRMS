<?php

namespace App\Payroll;

/**
 * Reads the salary-adjustment Excel layout: one long sheet, one row per employee and component.
 * Columns (by title): Employee No, Name (ignored), Component, Current amount (ignored), Mode, Value.
 * Plain arrays in, plain arrays out.
 */
final class SalaryAdjustmentSheet
{
    private const COLUMNS = ['employee_no' => 'employeeno', 'component' => 'component', 'mode' => 'mode', 'value' => 'value'];

    /**
     * @param  array<int, array<int, string>>  $rows  row number => [column number => text] (XlsxReader::read)
     * @return array{missing: string[], entries: array<int, array{row: int, employee_no: string, component: string, mode_raw: string, value_raw: string}>}
     */
    public static function parse(array $rows): array
    {
        $out = ['missing' => [], 'entries' => []];
        if (! $rows) {
            $out['missing'] = array_keys(self::COLUMNS);

            return $out;
        }

        $map = [];
        foreach ($rows[array_key_first($rows)] as $col => $title) {
            $n = preg_replace('/[^a-z]/', '', mb_strtolower($title));
            foreach (self::COLUMNS as $field => $prefix) {
                if (! isset($map[$field]) && str_starts_with($n, $prefix)) {
                    $map[$field] = $col;
                }
            }
        }
        $out['missing'] = array_values(array_diff(array_keys(self::COLUMNS), array_keys($map)));
        if ($out['missing']) {
            return $out;
        }

        foreach (array_slice($rows, 1, null, true) as $rowNo => $cells) {
            $mode = trim($cells[$map['mode']] ?? '');
            $value = trim($cells[$map['value']] ?? '');
            if ($mode === '' && $value === '') {
                continue; // untouched row: nothing to adjust
            }
            $out['entries'][] = [
                'row' => (int) $rowNo, 'employee_no' => trim($cells[$map['employee_no']] ?? ''),
                'component' => strtoupper(trim($cells[$map['component']] ?? '')), 'mode_raw' => $mode, 'value_raw' => $value,
            ];
        }

        return $out;
    }
}
