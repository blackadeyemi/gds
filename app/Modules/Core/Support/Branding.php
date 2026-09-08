<?php

namespace Modules\Core\Support;

/**
 * Company branding for exports & printouts. Report grid PDFs (dompdf) and the
 * browser-print view both carry the owning company's logo. The logo is returned
 * as a base64 data: URI so it renders identically in dompdf (which can't fetch
 * an asset URL) and in a normal print page.
 *
 * Company is inferred from a page-key / export basename hint: anything under
 * `bpl*` is Belpapyrus; everything else (BIL modules, and shared admin/settings)
 * is Belimpex, the parent. The BIL sales documents embed the logo directly and
 * don't use this.
 */
class Branding
{
    private const LOGOS = [
        'bil' => 'belimpex_brands logo.png',
        'bpl' => 'belpapyrus_companies logo.png',
    ];

    /** 'bpl' or 'bil' for a page-key / basename hint (default 'bil'). */
    public static function companyFor(?string $hint): string
    {
        $h = strtolower(ltrim((string) $hint, '-'));

        return str_starts_with($h, 'bpl') ? 'bpl' : 'bil';
    }

    /** base64 data: URI of the company logo (empty string if the file is missing). */
    public static function logo(?string $hint = null): string
    {
        static $cache = [];
        $co = self::companyFor($hint);
        if (array_key_exists($co, $cache)) {
            return $cache[$co];
        }
        $path = public_path('images/'.self::LOGOS[$co]);

        return $cache[$co] = is_file($path)
            ? 'data:image/png;base64,'.base64_encode(file_get_contents($path))
            : '';
    }
}
