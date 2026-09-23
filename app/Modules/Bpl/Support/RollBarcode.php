<?php

namespace Modules\Bpl\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Barcodes and roll numbers for BPL production.
 *
 * ## Why the softroll prefix is S
 *
 * The legacy app numbers hardrolls and softrolls from two INDEPENDENT counters
 * but formats both as `YY-MM-DD-M{machine}-NNN`, so the two streams share one
 * barcode namespace — and collide. On 2026-09-09, 1,699 of 1,708 softroll
 * barcodes were byte-identical to a hardroll barcode; 1,632 of those pairs were
 * on the factory floor at the same time and 297 were scanned out of the factory
 * on the same day. `26-08-25-M3-008` is both a 3,328 kg softroll and a 332 kg
 * hardroll.
 *
 * Nothing in the barcode distinguishes them, so the legacy Factory Exit,
 * Store Entrance and Store Exit screens exist twice — one page per stream — and
 * the ONLY thing selecting a stream is which menu item the operator clicked. A
 * scan on the wrong page succeeds silently against the twin roll. It has
 * already happened at least once downstream: softroll `24-07-01-M3-005` was
 * scanned into BIL's Gambini gate on 2024/07/25 (factory_entrance_reel id
 * 57864) and later flagged deleted.
 *
 * So a softroll printed from here carries `S{machine}` and a hardroll carries
 * `M{machine}`. The barcode names its own stream, one Factory Exit page can
 * route a scan without asking, and BIL's Factory Entrance can refuse a softroll
 * label outright instead of resolving it to somebody else's hardroll.
 *
 * The change is forward-only: rolls made before the cut-over keep their `M`
 * barcodes and stay stream-resolved by which table holds them. The legacy app's
 * own generator was changed to match (Bil\Bpl\production::MachinePrefix), so
 * both apps print the same format on the same day.
 *
 * ROLL NUMBERS are deliberately NOT changed. `hardrollnumber` /
 * `softrollnumber` (e.g. `M3260826-37`) are human references typed on
 * paperwork, not scanned, and they are unique within their own table — the
 * ambiguity only ever mattered on the thing a scanner reads.
 */
class RollBarcode
{
    /** Machine-segment prefix per stream. */
    public const HARDROLL = 'M';
    public const SOFTROLL = 'S';

    /**
     * Which stream a barcode belongs to: 'hardroll', 'softroll', or null when
     * the machine segment says neither (every barcode printed before the
     * cut-over reads as 'hardroll' — see the class note).
     */
    public static function stream(string $barcode): ?string
    {
        $segments = explode('-', trim($barcode));
        if (count($segments) < 5) {
            return null;
        }

        return match (strtoupper(substr($segments[3], 0, 1))) {
            self::HARDROLL => 'hardroll',
            self::SOFTROLL => 'softroll',
            default => null,
        };
    }

    /**
     * Next hardroll barcode for a date and machine.
     *
     * @param  string  $date         legacy 'Y/m/d'
     * @param  string  $papermachine legacy machine name, 'PM2' / 'PM3'
     */
    public static function hardroll(string $date, string $papermachine): string
    {
        return self::compose(
            $date,
            self::HARDROLL . self::machineNumber($papermachine),
            self::nextSequence('bpl_production', $date, 'papermachine', $papermachine)
        );
    }

    /**
     * Next softroll barcode for a date and machine.
     *
     * @param  string      $date         legacy 'Y/m/d'
     * @param  int|string  $papermachine machine NUMBER (2/3) — the softroll
     *                                   table stores the number, not the name
     */
    public static function softroll(string $date, int|string $papermachine): string
    {
        return self::compose(
            $date,
            self::SOFTROLL . self::machineNumber($papermachine),
            self::nextSequence('bpl_softroll_production', $date, 'papermachine', $papermachine)
        );
    }

    /**
     * Next `hardrollnumber` — `{M}{machine}{ymd}-{serial}{cart}`, where the
     * serial runs per MONTH per machine (not per day, unlike the barcode).
     */
    public static function hardrollNumber(string $date, string $papermachine, string $cart): string
    {
        $serial = self::nextMonthlySerial('bpl_production', 'hardrollnumber', $date, $papermachine);

        return strtoupper(sprintf(
            'M%s%s-%d%s',
            self::machineNumber($papermachine),
            Carbon::parse($date)->format('ymd'),
            $serial,
            $cart
        ));
    }

    /**
     * Next `softrollnumber` — `{M}{machine}{ymd}-{serial}`, serial per month
     * per machine. Keeps the legacy `M` prefix on purpose (see the class note).
     */
    public static function softrollNumber(string $date, int|string $papermachine): string
    {
        $serial = self::nextMonthlySerial('bpl_softroll_production', 'softrollnumber', $date, $papermachine);

        return strtoupper(sprintf(
            'M%s%s-%d',
            self::machineNumber($papermachine),
            Carbon::parse($date)->format('ymd'),
            $serial
        ));
    }

    /** `YY-MM-DD-{machineSegment}-NNN`. */
    private static function compose(string $date, string $machineSegment, int $sequence): string
    {
        return strtoupper(sprintf(
            '%s-%s-%03d',
            Carbon::parse($date)->format('y-m-d'),
            $machineSegment,
            $sequence
        ));
    }

    /** 'PM3' / '3' / 3 all mean machine 3. */
    private static function machineNumber(int|string $papermachine): string
    {
        return preg_replace('/\D/', '', (string) $papermachine) ?: '0';
    }

    /**
     * Highest barcode sequence already used on this date + machine, plus one.
     *
     * Reads MAX of the trailing segment rather than the legacy
     * `ORDER BY id DESC LIMIT 1`: a roll entered out of order (a backdated
     * correction, a row restored) would otherwise hand back a number already
     * taken, and `barcode` is UNIQUE.
     *
     * Soft-deleted rows are counted — their barcodes are still occupying the
     * unique index, and a deleted roll's number should not be reissued.
     */
    private static function nextSequence(string $table, string $date, string $machineColumn, int|string $machine): int
    {
        $max = DB::connection('bpl')->table($table)
            ->where('dateofmanufacture', $date)
            ->where($machineColumn, $machine)
            ->selectRaw('MAX(CAST(SUBSTRING_INDEX(`barcode`, "-", -1) AS UNSIGNED)) as n')
            ->value('n');

        return ((int) $max) + 1;
    }

    /** Highest monthly roll-number serial on this machine, plus one. */
    private static function nextMonthlySerial(string $table, string $column, string $date, int|string $machine): int
    {
        $month = Carbon::parse($date)->format('Y/m');

        $max = DB::connection('bpl')->table($table)
            ->where('dateofmanufacture', 'like', $month . '%')
            ->where('papermachine', $machine)
            // The serial sits after the dash and may carry a cart letter
            // ('37A'); CAST stops at the first non-digit.
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(`{$column}`, '-', -1) AS UNSIGNED)) as n")
            ->value('n');

        return ((int) $max) + 1;
    }
}
