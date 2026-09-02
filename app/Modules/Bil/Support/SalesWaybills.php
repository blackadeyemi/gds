<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Facades\DB;
use Modules\Bil\Models\SalesWaybill;

/**
 * The waybill: what the haulier is paid for taking a delivery out.
 *
 * The last step of the sales chain, and the thinnest. A delivery already says
 * what went and to whom; the waybill adds a RECEIPT NUMBER and a TRANSPORT
 * COST and nothing else. That is the whole screen.
 *
 * WHY THE QUEUE IS SCOPED TO A DATE, unlike Loading and Delivery. Those queues
 * are "everything still open", which is 73 loads. The equivalent here would be
 * every delivery without a waybill — 74,692 of them — because most deliveries
 * never get one: 54,894 waybills against 129,583 deliveries, since a customer
 * collecting in their own truck has no haulier to pay. An unwaybilled delivery
 * is a normal end state, not a backlog, so listing them all as work outstanding
 * would be a lie told 74,692 times. The legacy scoped by date and so does this.
 *
 * IT MOVES NO STOCK. The goods left at loading and the delivery confirmed they
 * arrived; this is money, not movement.
 */
class SalesWaybills
{
    /** How many rows the queue shows before "Show more". */
    public const QUEUE_LIMIT = 15;

    /** The legacy barcode's fixed "waybill" character. */
    private const WAYBILL_CHAR = 'W';

    /* ---------------- The queue ---------------- */

    /**
     * A date's deliveries, each flagged with whether it has a waybill.
     *
     * ONE call per render, deliberately. The screen wants the list, the counts
     * above it and the selected row, and an earlier cut fetched the day three
     * times over for those. It is a small read — a busy date is 58 deliveries —
     * so the caller filters and slices this rather than asking again.
     *
     * One row per DELIVERY rather than per waybill, because the screen's job is
     * to raise the missing ones: a delivery without a waybill has to be listed
     * to be actionable, and one with a waybill has to be listed to be reprinted
     * or removed.
     */
    public static function dayView(string $dateSlash): array
    {
        $rows = SalesDeliveries::deliveriesOn($dateSlash);

        // SalesDeliveries::decorate() already resolved the waybill barcode onto
        // each delivery, so nothing more is read to know which have one.
        foreach ($rows as $r) {
            $r->has_waybill = $r->waybill !== null;
        }

        return $rows;
    }

    /** Filter a day's deliveries to what the queue should show. */
    public static function filter(array $rows, ?string $search = null, bool $awaitingOnly = false): array
    {
        if ($awaitingOnly) {
            $rows = array_values(array_filter($rows, fn ($r) => ! $r->has_waybill));
        }

        if ($search) {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower(
                $r->barcode . ' ' . $r->deliverynumber . ' ' . ($r->customername ?? '')
                . ' ' . ($r->trucknumber ?? '') . ' ' . ($r->transportername ?? '')
                . ' ' . ($r->loadbarcode ?? '')
            ), $needle)));
        }

        return array_values($rows);
    }

    /** How many deliveries a day's rows hold, and how many still want a waybill. */
    public static function countsFrom(array $rows): array
    {
        $awaiting = count(array_filter($rows, fn ($r) => ! $r->has_waybill));

        return ['deliveries' => count($rows), 'awaiting' => $awaiting,
            'waybilled' => count($rows) - $awaiting];
    }

    /**
     * The most recent date that has a delivery still wanting a waybill.
     *
     * Opening on today would show an empty screen on any day the office is
     * catching up, and the operator would have to guess which date to try.
     *
     * ⚠️ NOT a join. `sales_delivery.deliverynumber` is an int and
     * `sales_waybill.deliverynumber` a varchar, so joining them needs a CAST —
     * which no index can serve. An earlier cut did exactly that and cost
     * 1,880ms of a 1,935ms page. Instead it walks back over the distinct
     * delivery dates, both sides indexed, and stops at the first date where the
     * two counts differ. A waybill is unique per (number, date) and can only
     * exist for a delivery, so fewer waybills than deliveries IS one missing.
     * In practice it stops on the first date.
     */
    public static function latestDateAwaiting(int $lookBackDates = 90): ?string
    {
        $bil = DB::connection('bil');

        $dates = $bil->table('sales_delivery')
            ->distinct()->orderByDesc('dateofdelivery')
            ->limit($lookBackDates)->pluck('dateofdelivery');

        foreach ($dates as $date) {
            $deliveries = $bil->table('sales_delivery')->where('dateofdelivery', $date)->count();
            $waybills = $bil->table('sales_waybill')->where('dateofwaybill', $date)->count();

            if ($waybills < $deliveries) {
                return (string) $date;
            }
        }

        return $dates->first() !== null ? (string) $dates->first() : null;
    }

    /* ---------------- Barcodes ---------------- */

    /**
     * The waybill's barcode, from the delivery's: `…-L1D-379` -> `…-L1W-379`.
     *
     * Reproduces sales_waybill_request.php, which split the delivery barcode on
     * '-', trimmed the D off the fourth part and appended a W. Same serial,
     * same number — only the character changes, so a waybill is recognisably
     * the delivery's.
     */
    public static function barcodeFor(string $deliveryBarcode): string
    {
        $parts = explode('-', $deliveryBarcode);

        if (count($parts) !== 5) {
            return $deliveryBarcode;
        }

        $parts[3] = rtrim($parts[3], 'D') . self::WAYBILL_CHAR;

        return implode('-', $parts);
    }

    /* ---------------- Reading ---------------- */

    /** Every waybill on a date, newest delivery number first — the print list. */
    public static function waybillsOn(string $dateSlash): array
    {
        $waybills = DB::connection('bil')->table('sales_waybill')
            ->where('dateofwaybill', $dateSlash)
            ->orderByDesc(DB::raw('CAST(deliverynumber AS UNSIGNED)'))
            ->get()->all();

        return $waybills === [] ? [] : self::decorate($waybills, $dateSlash);
    }

    public static function find(int $id): ?object
    {
        $row = DB::connection('bil')->table('sales_waybill')->where('id', $id)->first();

        return $row ? (self::decorate([$row], (string) $row->dateofwaybill)[0] ?? null) : null;
    }

    public static function byBarcode(string $barcode): ?object
    {
        $row = DB::connection('bil')->table('sales_waybill')->where('barcode', $barcode)->first();

        return $row ? (self::decorate([$row], (string) $row->dateofwaybill)[0] ?? null) : null;
    }

    /** The waybill raised against one delivery, or null. */
    public static function forDelivery(int $deliveryNumber, string $dateSlash): ?object
    {
        $row = DB::connection('bil')->table('sales_waybill')
            ->where('deliverynumber', (string) $deliveryNumber)
            ->where('dateofwaybill', $dateSlash)->first();

        return $row ? (self::decorate([$row], $dateSlash)[0] ?? null) : null;
    }

    /**
     * Hang the delivery — and through it the load, customer and truck — onto
     * each waybill.
     *
     * Everything a waybill shows other than its two figures belongs to the
     * delivery, so it is fetched once for the date and matched on the number.
     */
    private static function decorate(array $waybills, string $dateSlash): array
    {
        $deliveries = collect(SalesDeliveries::deliveriesOn($dateSlash))
            ->keyBy(fn ($d) => (string) $d->deliverynumber);

        foreach ($waybills as $w) {
            $d = $deliveries[(string) $w->deliverynumber] ?? null;

            $w->delivery = $d;
            $w->deliverybarcode = $d->barcode ?? null;
            $w->loadbarcode = $d->loadbarcode ?? null;
            $w->loadnumber = $d->loadnumber ?? null;
            $w->customername = $d->customername ?? null;
            $w->customeraddress = $d->customeraddress ?? null;
            $w->transportername = $d->transportername ?? null;
            $w->trucknumber = $d->trucknumber ?? null;
            $w->truckdriver = $d->truckdriver ?? null;
            $w->transportcost = (float) $w->transportcost;
        }

        return $waybills;
    }

    /* ---------------- Raising one ---------------- */

    /**
     * Raise the waybill for a delivery. Returns [ok, message, barcode].
     *
     * The legacy checked for an existing waybill when it DREW the form and
     * never again, so two tabs on one delivery wrote two rows. Here the check
     * is at the write, which is the only place it can be true.
     */
    public static function create(object $delivery, ?int $receiptNumber, float $transportCost): array
    {
        $dateSlash = (string) $delivery->dateofdelivery;
        $number = (int) $delivery->deliverynumber;

        if ($transportCost <= 0) {
            return ['ok' => false, 'message' => 'Enter what the haulier is being paid.'];
        }

        if (self::forDelivery($number, $dateSlash)) {
            return ['ok' => false, 'message' =>
                'Delivery ' . $delivery->barcode . ' already has a waybill.'];
        }

        $user = auth()->user();
        $barcode = self::barcodeFor((string) $delivery->barcode);

        SalesWaybill::create([
            'barcode' => $barcode,
            'username' => (string) ($user?->username ?? $user?->name ?? 'gds'),
            'deliverynumber' => (string) $number,
            'receiptnumber' => $receiptNumber,
            'transportcost' => $transportCost,
            'dateofwaybill' => $dateSlash,
            'timestamp' => time(),
        ]);

        return ['ok' => true, 'barcode' => $barcode, 'message' => 'Waybill ' . $barcode . ' raised.'];
    }

    /** Correct the two figures on an existing waybill. */
    public static function update(int $id, ?int $receiptNumber, float $transportCost): array
    {
        $waybill = SalesWaybill::find($id);

        if (! $waybill) {
            return ['ok' => false, 'message' => 'That waybill could not be found.'];
        }

        if ($transportCost <= 0) {
            return ['ok' => false, 'message' => 'Enter what the haulier is being paid.'];
        }

        $waybill->update(['receiptnumber' => $receiptNumber, 'transportcost' => $transportCost]);

        return ['ok' => true, 'message' => 'Waybill updated.'];
    }

    /**
     * Remove a waybill, putting its delivery back on the list.
     *
     * ⚠️ This is the guard the DELIVERY screen leans on: SalesDeliveries::undo()
     * refuses while a waybill exists, so removing one here re-opens that door.
     * Deliberate — it is the only way to correct a delivery that was waybilled
     * by mistake — but it is why the ability is separate from raising one.
     */
    public static function remove(int $id): array
    {
        $waybill = SalesWaybill::find($id);

        if (! $waybill) {
            return ['ok' => false, 'message' => 'That waybill could not be found.'];
        }

        $barcode = $waybill->barcode;
        $waybill->delete();

        return ['ok' => true, 'message' => 'Waybill ' . $barcode . ' removed.'];
    }

    /* ---------------- Print-outs ---------------- */

    /**
     * Everything needed to print one or more waybills.
     *
     * The product rows come from the LOAD, summed per product — the same figure
     * the delivery note prints, because the waybill covers the same goods.
     */
    public static function printouts(array $barcodes): array
    {
        $barcodes = array_values(array_filter(array_unique($barcodes)));

        if ($barcodes === []) {
            return [];
        }

        $rows = DB::connection('bil')->table('sales_waybill')
            ->whereIn('barcode', $barcodes)->get()->all();

        if ($rows === []) {
            return [];
        }

        $out = [];

        foreach (collect($rows)->groupBy('dateofwaybill') as $dateSlash => $group) {
            foreach (self::decorate($group->values()->all(), (string) $dateSlash) as $w) {
                $out[] = $w;
            }
        }

        $warehouses = DB::connection('bil')->table('sales_warehouse')
            ->pluck('warehouselocation', 'warehousecode');

        foreach ($out as $w) {
            $delivery = $w->delivery;

            if (! $delivery) {
                $w->orders = [];
                $w->warehouse = null;
                $w->lines = [];
                $w->total = 0;

                continue;
            }

            // Reuse the delivery's own print build: same load, same rows, and
            // the same attribution when a load number carries two deliveries.
            $doc = SalesDeliveries::printouts([$delivery->barcode])[0] ?? null;

            $w->orders = $doc->orders ?? [];
            $w->warehouse = $doc->warehouse ?? ($warehouses[''] ?? null);
            $w->lines = $doc->lines ?? [];
            $w->total = $doc->total ?? 0;
        }

        usort($out, fn ($a, $b) => array_search($a->barcode, $barcodes, true)
            <=> array_search($b->barcode, $barcodes, true));

        return $out;
    }
}
