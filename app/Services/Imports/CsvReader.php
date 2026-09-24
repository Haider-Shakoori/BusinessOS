<?php

namespace App\Services\Imports;

use Generator;
use RuntimeException;

/**
 * Streaming CSV reader (Batch 20).
 *
 * Reads the staging file line by line with fgetcsv so a large upload is never
 * materialized as one giant in-memory array. It handles the three real-world
 * annoyances a hand-built parser would trip over:
 *
 *  - a UTF-8 BOM in the first cell of the header row (Excel habit);
 *  - CRLF and LF line endings alike (fgetcsv tolerates both);
 *  - quoted commas and embedded newlines inside fields (fgetcsv-native).
 *
 * Row numbering is 1-based and counts the header as row 1, so a "Row 4" error
 * in the UI matches the row a user sees in their spreadsheet.
 */
class CsvReader
{
    public const UTF8_BOM = "\xEF\xBB\xBF";

    public const DELIMITER = ',';

    public const ENCLOSURE = '"';

    public const ESCAPE = '\\';

    public function __construct(private readonly int $maxRows)
    {
        //
    }

    /**
     * Parse the header (the first non-blank line). A leading UTF-8 BOM is
     * stripped from the first cell; cells are returned raw (untrimmed) so the
     * mapper can apply its own normalization.
     *
     * @return list<string>
     */
    public function headerCells(string $path): array
    {
        [$handle, $header] = $this->firstNonBlank($path);

        fclose($handle);

        return $header;
    }

    /**
     * Stream every data row (header skipped), keyed by its physical 1-based
     * line number.
     *
     * @return Generator<int, list<string>>
     *
     * @throws TooManyRowsException
     */
    public function stream(string $path): Generator
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the CSV file.');
        }

        try {
            $lineNo = 0;
            $sawHeader = false;
            $dataCount = 0;

            while (($cells = fgetcsv($handle, 0, self::DELIMITER, self::ENCLOSURE, self::ESCAPE)) !== false) {
                $lineNo++;

                if ($cells === [null]) {
                    continue;
                }

                if (! $sawHeader) {
                    $sawHeader = true;

                    continue;
                }

                if ($dataCount >= $this->maxRows) {
                    throw new TooManyRowsException($this->maxRows);
                }

                $dataCount++;

                yield $lineNo => array_map(static fn ($cell) => (string) $cell, $cells);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{0: resource, 1: list<string>}
     */
    private function firstNonBlank(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the CSV file.');
        }

        try {
            while (($cells = fgetcsv($handle, 0, self::DELIMITER, self::ENCLOSURE, self::ESCAPE)) !== false) {
                if ($cells === [null]) {
                    continue;
                }

                $cells = array_map(static fn ($cell) => (string) $cell, $cells);

                if ($cells === []) {
                    continue;
                }

                $cells[0] = str_starts_with($cells[0], self::UTF8_BOM)
                    ? substr($cells[0], strlen(self::UTF8_BOM))
                    : $cells[0];

                return [$handle, $cells];
            }

            return [$handle, []];
        } catch (Throwable $e) {
            fclose($handle);

            throw $e;
        }
    }
}
