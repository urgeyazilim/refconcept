<?php

declare(strict_types=1);

namespace App\Domains\Imports\Services;

use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Reads a seller's spreadsheet without loading it into memory.
 *
 * Streaming rather than parsing the whole file: a supplier catalogue is routinely
 * tens of thousands of rows, and an importer that dies on a large file is an importer
 * the largest sellers cannot use.
 *
 * Two Turkish-specific problems are handled here rather than left to the caller,
 * because both silently produce plausible wrong answers:
 *
 *  - **Delimiter.** Turkish Excel writes CSV with semicolons, because the comma is
 *    the decimal separator. Assuming a comma turns every row into one long cell,
 *    which reads as "your file has one column" rather than as a delimiter problem.
 *  - **Encoding.** Files exported from older Windows tools arrive as Windows-1254,
 *    not UTF-8, and "Kanepe" becomes "Kanepe" in the catalogue. The byte-order mark
 *    is stripped for the same reason: an invisible prefix on the first header cell
 *    makes that one column fail to map while every other column works.
 */
final class SpreadsheetReader
{
    public const SUPPORTED_EXTENSIONS = ['csv', 'xlsx'];

    /** Candidate delimiters, in the order a Turkish file is likely to use them. */
    private const DELIMITERS = [';', ',', "\t", '|'];

    /**
     * The header row, normalised for matching.
     *
     * @return array<int, string>
     */
    public function headers(string $path, string $extension): array
    {
        foreach ($this->rows($path, $extension) as $cells) {
            return array_map(fn (string $cell): string => $this->cleanHeader($cell), $cells);
        }

        throw new RuntimeException('Dosyada başlık satırı bulunamadı.');
    }

    /**
     * Every data row, keyed by header, with the file's own line number.
     *
     * Rows that are entirely empty are skipped rather than reported as errors: a
     * spreadsheet with trailing blank rows is normal, and telling a seller that
     * lines 400–1048576 are invalid is not help.
     *
     * @return Generator<int, array{line: int, values: array<string, string>}>
     */
    public function records(string $path, string $extension): Generator
    {
        $headers = null;
        $line = 0;

        foreach ($this->rows($path, $extension) as $cells) {
            $line++;

            if ($headers === null) {
                $headers = array_map(fn (string $cell): string => $this->cleanHeader($cell), $cells);

                continue;
            }

            if ($this->isBlank($cells)) {
                continue;
            }

            $values = [];

            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $values[$header] = trim($cells[$index] ?? '');
            }

            yield ['line' => $line, 'values' => $values];
        }
    }

    /**
     * Raw cell values, row by row.
     *
     * @return Generator<int, array<int, string>>
     */
    private function rows(string $path, string $extension): Generator
    {
        $reader = $this->readerFor($path, $extension);
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $this->cellsOf($row);
                }

                // Only the first sheet. A workbook with a "Notlar" tab should not have
                // its notes imported as products.
                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function cellsOf(Row $row): array
    {
        return array_map(
            function (mixed $value): string {
                if ($value instanceof DateTimeInterface) {
                    return $value->format('Y-m-d');
                }

                if (is_bool($value)) {
                    return $value ? '1' : '0';
                }

                return $this->toUtf8((string) $value);
            },
            $row->toArray(),
        );
    }

    /**
     * The reader for this file type.
     *
     * Returns the two concrete classes rather than the interface: OpenSpout's
     * `ReaderInterface` is generic over its sheet iterator, and naming the union says
     * more with less ceremony than pinning that type parameter would.
     */
    private function readerFor(string $path, string $extension): CsvReader|XlsxReader
    {
        return match (strtolower($extension)) {
            'xlsx' => new XlsxReader,
            'csv' => $this->csvReader($path),
            default => throw new RuntimeException(
                'Yalnızca CSV ve XLSX dosyaları içe aktarılabilir.',
            ),
        };
    }

    private function csvReader(string $path): CsvReader
    {
        $options = new CsvOptions;
        $options->FIELD_DELIMITER = $this->detectDelimiter($path);
        $options->FIELD_ENCLOSURE = '"';

        return new CsvReader($options);
    }

    /**
     * Picks the delimiter that yields the most columns on the header line.
     *
     * Counting rather than guessing: a Turkish file separated by semicolons contains
     * plenty of commas inside its decimal numbers, so "does the line contain a comma"
     * answers the wrong question.
     */
    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ';';
        }

        $header = fgets($handle, 65536);
        fclose($handle);

        if ($header === false) {
            return ';';
        }

        $header = $this->toUtf8($header);
        $best = ';';
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = substr_count($header, $delimiter);

            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * Converts to UTF-8 if it is not already.
     *
     * Windows-1254 is the fallback rather than ISO-8859-1: it is what Turkish Windows
     * exports, and the two differ in exactly the characters that matter here.
     */
    private function toUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1254');

        return $converted === false ? $value : $converted;
    }

    /** Strips the BOM and normalises case and spacing for header matching. */
    private function cleanHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = trim($header);

        // Turkish lowercasing, because strtolower turns "İ" into a two-byte mess and
        // leaves "I" as "I" — so "İSİM" and "isim" would not match.
        return mb_strtolower($header, 'UTF-8');
    }

    /**
     * @param  array<int, string>  $cells
     */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
