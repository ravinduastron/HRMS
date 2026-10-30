<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

class Csv
{
    /** Streams a CSV download. Cells that start like a formula are neutralised (spreadsheet formula injection). */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;

        return response()->streamDownload(function () use ($header, $rows, $safe) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8
            fputcsv($out, $header);
            foreach ($rows as $r) {
                fputcsv($out, array_map($safe, $r));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
