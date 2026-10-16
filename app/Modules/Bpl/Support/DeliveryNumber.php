<?php

namespace Modules\Bpl\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The waybill number a delivery goes out with: `DD-MM-YY-BPD-NNN`, e.g.
 * `02-09-26-BPD-001`, where NNN counts that day's deliveries.
 *
 * WHAT THE LEGACY DID
 *
 * It read the id of the last waybill row for today and added one:
 *
 *     SELECT id FROM bpl_delivery_barcode WHERE date = '26-08-15' ORDER BY id DESC LIMIT 1
 *
 * With no delivery yet that day there is no row, so it fell through to '001' —
 * correct. On the SECOND delivery of a day it found the first one's id, a
 * running table id in the thousands, and produced `15-08-26-BPD-1248`. Two of
 * those exist in the data (15-08-26 and 07-08-26).
 *
 * gds counts the day's waybills instead, and steps past any number already
 * taken, so the second delivery of a day is `-002`. Existing numbers are left
 * exactly as they are.
 */
class DeliveryNumber
{
    public const SUFFIX = 'BPD';

    /** Up to this many numbers are tried for one day before giving up. */
    private const MAX_PER_DAY = 999;

    /** The legacy `date` column value for a day: 'y-m-d' (e.g. 26-09-02). */
    public static function dateKey(string $dateIso): string
    {
        return Carbon::parse($dateIso)->format('y-m-d');
    }

    /** The prefix every waybill of that day shares: 'DD-MM-YY-BPD'. */
    public static function prefix(string $dateIso): string
    {
        return Carbon::parse($dateIso)->format('d-m-y') . '-' . self::SUFFIX;
    }

    /**
     * The next free waybill number for a day.
     *
     * Call inside the delivery's transaction: two clerks saving at the same
     * moment would otherwise be handed the same number. The number is not
     * unique in the database (the legacy repeats rule that out), so the check
     * is done here.
     */
    public static function next(string $dateIso): string
    {
        $prefix = self::prefix($dateIso);

        $taken = DB::connection('bpl')->table('bpl_delivery_barcode')
            ->where('delivery_barcode', 'like', $prefix . '-%')
            ->lockForUpdate()
            ->pluck('delivery_barcode')
            ->all();

        $used = array_flip($taken);

        for ($n = count($taken) + 1; $n <= self::MAX_PER_DAY; $n++) {
            $candidate = $prefix . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);

            if (! isset($used[$candidate])) {
                return $candidate;
            }
        }

        throw new \RuntimeException('No waybill number left for ' . $dateIso . '.');
    }
}
