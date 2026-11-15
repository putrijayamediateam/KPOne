<?php

namespace App\Domain\Shared\Services;

use Illuminate\Validation\ValidationException;

/** Bounded in-memory CSV; no generated files, expressions or retained export records. */
final class ReportCsv
{
    public const MAX_ROWS = 5000;

    /** @param list<string> $headings
     * @param  list<list<string>>  $rows
     * @param  list<list<string>>  $metadata
     */
    public static function encode(array $headings, array $rows, array $metadata = []): string
    {
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['export' => 'More than 5,000 rows match. Narrow the filters before downloading.']);
        }
        $out = fopen('php://memory', 'w+');
        if ($out === false) {
            throw new \RuntimeException('Unable to prepare the report.');
        }
        try {
            fwrite($out, "\xEF\xBB\xBF");
            foreach ([...$metadata, ...($metadata === [] ? [] : [[]]), $headings, ...$rows] as $row) {
                fputcsv($out, array_map(self::safeCell(...), $row), ',', '"', '', "\r\n");
            }
            rewind($out);
            $csv = stream_get_contents($out);
            if ($csv === false) {
                throw new \RuntimeException('Unable to prepare the report.');
            }

            return $csv;
        } finally {
            fclose($out);
        }
    }

    public static function money(int $sen): string
    {
        $whole = intdiv($sen, 100);

        return ($sen < 0 && $whole === 0 ? '-' : '').$whole.'.'.str_pad((string) abs($sen % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function safeCell(string $value): string
    {
        // Typed, strictly formatted numeric values remain numeric, including genuine negatives.
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value)) {
            return $value;
        }

        return preg_match('/^(?:[\s\p{Cf}]*[=+\-@]|[\t\r\n])/u', $value) ? "'".$value : $value;
    }
}
