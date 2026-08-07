<?php

namespace Modules\Core\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams a DataGrid view (header + rows) to a real .xlsx or .csv download.
 * xlsx uses OpenSpout (requires ext-zip); both write to a temp file which is
 * returned as a download and deleted after send — reliable inside Livewire
 * actions (unlike streaming, since xlsx needs a seekable target).
 */
class GridExporter
{
    /**
     * A writable, app-owned temp directory for export scratch files.
     *
     * PHP's sys_get_temp_dir() can't be used here: under the Apache/php-cgi
     * SAPI on Windows it resolves to C:\Windows\Temp, which the web-server
     * process cannot write to. tempnam() there returns false (→ dompdf writes
     * an empty file → "blank" PDF) and OpenSpout throws "not a writable folder".
     * storage/ is always writable by the web process (it writes the logs), so
     * we scope our temp files there.
     */
    private static function tempDir(): string
    {
        $dir = storage_path('app/exports-tmp');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private static function tempFile(string $prefix): string
    {
        return tempnam(self::tempDir(), $prefix);
    }

    /**
     * @param  string    $format    'xlsx' | 'csv'
     * @param  string    $basename  filename without extension
     * @param  string[]  $headings  column labels
     * @param  iterable  $rows      each item an array of scalar cell values
     * @param  array     $context   [[label, value], ...] describing what was
     *                              filtered — written above the table so the
     *                              file says what it is out of context
     */
    public static function download(string $format, string $basename, array $headings, iterable $rows, array $context = []): BinaryFileResponse
    {
        $format = strtolower($format) === 'csv' ? 'csv' : 'xlsx';
        $tmp = self::tempFile('gdsgrid');

        // XLSX buffers sheet parts through its own temp folder — point it at the
        // writable app dir too (its default is sys_get_temp_dir()).
        $writer = $format === 'csv'
            ? new CsvWriter()
            : new XlsxWriter(new XlsxOptions(tempFolder: self::tempDir()));
        $writer->openToFile($tmp);

        // A spreadsheet outlives the screen it came from, so lead with the
        // filters that produced it — otherwise "1,204 rows" is unreadable a week
        // later. Blank row between the context block and the table so the sheet
        // still sorts/filters cleanly from the heading row.
        foreach ($context as [$label, $value]) {
            $writer->addRow(Row::fromValues([$label, $value]));
        }

        if ($context !== []) {
            // One empty cell, not an empty array: a row with no cells writes
            // nothing at all in CSV, and the context would run straight into
            // the headings.
            $writer->addRow(Row::fromValues(['']));
        }

        $writer->addRow(Row::fromValues($headings));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_values($row)));
        }
        $writer->close();

        $filename = $basename . '-' . now()->format('Ymd-His') . '.' . $format;

        // Set Content-Type explicitly. The temp file has no extension, so
        // response()->download() can't guess a MIME type, and Livewire's
        // download effect then reports contentType=null — leaving the browser to
        // sniff the blob (which can fail → broken/blank render). Be explicit.
        $mime = $format === 'csv'
            ? 'text/csv'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()
            ->download($tmp, $filename, ['Content-Type' => $mime])
            ->deleteFileAfterSend(true);
    }

    /**
     * Render a view (header + rows) to a real .pdf download via dompdf. Uses the
     * shared print layout, A4 landscape (report tables are wide). Written to a
     * temp file and returned as a download, mirroring download() — reliable
     * inside Livewire actions.
     *
     * @param  string    $basename  filename without extension
     * @param  string    $label     report title shown on the page
     * @param  string[]  $headings  column labels
     * @param  iterable  $rows      each item an array of scalar cell values
     * @param  array     $context   [[label, value], ...] shown under the title
     */
    public static function pdf(string $basename, string $label, array $headings, iterable $rows, array $context = []): BinaryFileResponse
    {
        // dompdf holds the whole document in memory and is memory-hungry (a
        // ~450-row × 10-col table already nears 512M). Under php-cgi an OOM kills
        // the worker outright — an empty HTTP 500 with nothing logged. Give it
        // generous headroom + no time limit so a capped report never crashes.
        // (Bulk data still belongs in the streamed xlsx/csv exports.)
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $rows = is_array($rows) ? $rows : iterator_to_array($rows);
        // Lean, border-light template — dompdf is slow with per-cell borders.
        $html = view('core::print.grid-pdf', [
            'label' => $label, 'headings' => $headings, 'rows' => $rows, 'context' => $context,
        ])->render();

        // dompdf's own tempDir/fontCache also default to sys_get_temp_dir();
        // pin them to the writable app dir so large PDFs (which spool to temp)
        // don't fail the same way under Apache.
        $pdf = Pdf::setOption(['tempDir' => self::tempDir(), 'fontCache' => self::tempDir()])
            ->loadHTML($html)->setPaper('a4', 'landscape');

        $tmp = self::tempFile('gdspdf');
        file_put_contents($tmp, $pdf->output());

        $filename = $basename . '-' . now()->format('Ymd-His') . '.pdf';

        // Explicit MIME — the temp file has no extension, so without this the
        // Livewire download effect sends contentType=null and the browser's PDF
        // viewer can render a blank/black page instead of the document.
        return response()
            ->download($tmp, $filename, ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }
}
