<?php

namespace Modules\Bpl\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The scanned bill of lading documents.
 *
 * They are loose files in a folder shared with the legacy app (configured by
 * `bpl.bl_docs_path`), and `bpl_bill_of_lading.doc` stores only the filename,
 * so both apps open the same document. The folder lives outside the web root;
 * gds streams a file through a gated route rather than publishing the folder,
 * and never builds a path from anything but `basename()` of the stored name.
 *
 * The legacy named each file after its packing list — `BPL-SN-SAFT-116.pdf`,
 * the number with its slashes turned into dashes — and gds keeps doing that,
 * adding a counter when a name is taken so replacing a scan cannot silently
 * overwrite another shipment's.
 */
class BillOfLadingDocs
{
    /** What may be uploaded: the legacy folder holds PDFs and photos of them. */
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const MAX_KB = 12288;

    public static function directory(): string
    {
        return rtrim((string) config('bpl.bl_docs_path'), '/\\');
    }

    public static function available(): bool
    {
        return is_dir(self::directory());
    }

    /** The full path of a stored document, or null when it is not there. */
    public static function path(?string $filename): ?string
    {
        $name = basename(trim((string) $filename));

        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        $path = self::directory() . DIRECTORY_SEPARATOR . $name;

        return is_file($path) && is_readable($path) ? $path : null;
    }

    public static function exists(?string $filename): bool
    {
        return self::path($filename) !== null;
    }

    /** Stream the document inline, so a PDF opens in the browser's viewer. */
    public static function stream(int $billId): BinaryFileResponse
    {
        $row = DB::connection('bpl')->table('bpl_bill_of_lading')->where('id', $billId)->first(['doc', 'number']);

        abort_if(! $row, 404);

        $path = self::path($row->doc);

        abort_if(! $path, 404, 'The scanned document is not in ' . self::directory() . '.');

        return response()->file($path, ['Content-Disposition' => 'inline; filename="' . basename($path) . '"']);
    }

    /**
     * Save an upload for a packing list, named the legacy way.
     *
     * @return string the stored filename
     */
    public static function store(UploadedFile $file, string $packingNumber): string
    {
        $dir = self::directory();

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('The bill of lading folder does not exist and could not be created: ' . $dir);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'pdf');
        $base = str_replace(['/', '\\', ' '], ['-', '-', ''], trim($packingNumber)) ?: 'BPL-BL';
        $name = $base . '.' . $extension;

        // A replaced scan must not land on another shipment's file.
        $n = 1;
        while (is_file($dir . DIRECTORY_SEPARATOR . $name)) {
            $name = $base . '-' . (++$n) . '.' . $extension;
        }

        // Copied rather than moved, and not put through a filesystem disk: the
        // folder is shared with the legacy app and lives wherever the
        // environment says — on Windows that is often another volume from the
        // upload's temp directory, and rename() across volumes fails.
        $source = $file->getRealPath() ?: $file->getPathname();
        $target = $dir . DIRECTORY_SEPARATOR . $name;

        if (! @copy($source, $target)) {
            throw new \RuntimeException('The document could not be written to ' . $dir . '.');
        }

        @unlink($source);

        return $name;
    }

    /** Remove a stored document; missing files are not an error. */
    public static function delete(?string $filename): void
    {
        $path = self::path($filename);

        if ($path) {
            @unlink($path);
        }
    }
}
