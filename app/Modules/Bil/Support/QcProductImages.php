<?php

namespace Modules\Bil\Support;

use Illuminate\Http\UploadedFile;

/**
 * Quality-control product photos.
 *
 * They are loose files in a folder shared with the legacy app (configured by
 * `bil.qc_pics_path`); products.imagepath stores only the bare filename, so
 * both apps resolve the same picture. Images are served inline as data: URIs
 * rather than from a public path — the folder lives outside the web root.
 */
class QcProductImages
{
    public static function directory(): string
    {
        return rtrim((string) config('bil.qc_pics_path'), '/\\');
    }

    /** Whether the configured folder is currently reachable. */
    public static function available(): bool
    {
        return is_dir(self::directory());
    }

    /** The picture as a data: URI, or null when absent or unreadable. */
    public static function dataUri(?string $filename): ?string
    {
        // basename() so a stored value can never walk out of the folder.
        $name = basename(trim((string) $filename));
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        $path = self::directory() . DIRECTORY_SEPARATOR . $name;
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'jpeg';

        return 'data:image/' . ($ext === 'jpg' ? 'jpeg' : $ext) . ';base64,' . base64_encode($bytes);
    }

    /** Save an upload alongside the legacy ones; returns the stored filename. */
    public static function store(UploadedFile $file): string
    {
        $dir = self::directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("QC picture folder does not exist and could not be created: {$dir}");
        }

        // Same "QC-<uid>.<ext>" shape the legacy uploader used, so the shared
        // folder stays consistent whichever app wrote the file.
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
        $name = 'QC-' . uniqid() . '.' . $ext;
        $file->move($dir, $name);

        return $name;
    }
}
