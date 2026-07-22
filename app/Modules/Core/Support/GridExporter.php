<?php

namespace Modules\Core\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
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
     * @param  string    $format    'xlsx' | 'csv'
     * @param  string    $basename  filename without extension
     * @param  string[]  $headings  column labels
     * @param  iterable  $rows      each item an array of scalar cell values
     */
    public static function download(string $format, string $basename, array $headings, iterable $rows): BinaryFileResponse
    {
        $format = strtolower($format) === 'csv' ? 'csv' : 'xlsx';
        $tmp = tempnam(sys_get_temp_dir(), 'gdsgrid');

        $writer = $format === 'csv' ? new CsvWriter() : new XlsxWriter();
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues($headings));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_values($row)));
        }
        $writer->close();

        $filename = $basename . '-' . now()->format('Ymd-His') . '.' . $format;

        return response()
            ->download($tmp, $filename)
            ->deleteFileAfterSend(true);
    }
}
