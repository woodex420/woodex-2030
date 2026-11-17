<?php

declare(strict_types=1);

namespace App\Support\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One CSV shape for every report (RPT-009): UTF-8, comma-separated, quoted when needed, money as plain
 * rupees so accountants can paste it anywhere. Opens cleanly in Excel.
 */
final class Csv
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, int|float|string|null>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 with the BOM.
            fputcsv($out, $headers, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_values($row), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
