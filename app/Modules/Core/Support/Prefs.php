<?php

namespace Modules\Core\Support;

/**
 * Per-browser display preferences that must be readable BOTH client-side
 * (flatpickr in date forms) and server-side (report/export date rendering).
 *
 * Theme and text-size live in localStorage (client-only), but the date format
 * also drives server-rendered reports and exports, so it rides in a plain
 * cookie (`gds_date_format`) — set by settings.js, read here. The cookie is
 * excluded from Laravel's cookie encryption (see bootstrap/app.php) so the
 * JS-set plaintext value is readable.
 *
 * This is a DISPLAY preference only: dates are always stored/submitted as ISO
 * `Y-m-d`; nothing here touches what goes into the database.
 */
class Prefs
{
    public const COOKIE = 'gds_date_format';

    public const DEFAULT = 'd/M/Y';

    /**
     * Allowed date formats. Every token here is valid AND identical in meaning
     * for both PHP date() and flatpickr (d, m, Y, M, j, F), so the one stored
     * string drives on-screen forms, reports, and exports alike.
     */
    public const FORMATS = [
        'd/M/Y',  // 03/Jul/2026
        'd/m/Y',  // 03/07/2026
        'Y-m-d',  // 2026-07-03
        'm/d/Y',  // 07/03/2026
        'd M Y',  // 03 Jul 2026
        'M j, Y', // Jul 3, 2026
    ];

    /** The active date format for this request, validated against the allowlist. */
    public static function dateFormat(): string
    {
        $fmt = (string) request()?->cookie(self::COOKIE, self::DEFAULT);

        return in_array($fmt, self::FORMATS, true) ? $fmt : self::DEFAULT;
    }
}
