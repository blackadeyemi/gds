<?php

namespace Modules\Bpl\Support;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * The two sheets a delivery prints — ports of the legacy
 * `app/views/bpl_delivery_printout.php` and
 * `app/views/bpl_delivery_processor_printout.php`.
 *
 *   - **The waybill** (`render`) is the customer's copy: rolls grouped by
 *     product, because that is what they sign for — "8 rolls of PBTS 19.0gsm,
 *     12,430 kg", not twenty barcodes.
 *   - **The processor slip** (`processor`) is the receiving mill's copy: every
 *     roll on its own line with its hardroll number, barcode and weight, and
 *     the weighbridge figures across the top. It is what the rolls are checked
 *     in against.
 *
 * Both take each roll's weight from `bpl_production`, so a reprint shows the
 * figures the first print did.
 */
class Waybill
{
    /**
     * The processors BPL delivers hardrolls to, and their id in the receiving
     * system's `importdata` table — the mapping the legacy hard-coded inside
     * its SQL generator. A delivery to anyone else has nothing to import.
     */
    public const PROCESSOR_COMPANIES = ['BIL ABUJA' => 1, 'BHN' => 2];

    /**
     * One delivery: its waybill row, its rolls and the form behind it.
     *
     * @return array{waybill:object,lines:\Illuminate\Support\Collection,form:?object}
     */
    protected static function load(string $number): array
    {
        $number = trim($number);

        $waybill = DB::connection('bpl')->table('bpl_delivery_barcode')
            ->where('delivery_barcode', $number)->orderByDesc('id')->first();

        abort_if(! $waybill, 404);

        $lines = DB::connection('bpl')->table('bpl_delivery_details as d')
            ->leftJoin('bpl_production as r', 'r.barcode', '=', 'd.barcode')
            ->leftJoin('bpl_products_hardroll as p', 'p.id', '=', 'r.product_id')
            ->where('d.delivery_barcode', $number)
            ->orderBy('d.id')
            // The import file's date is the DELIVERY line's, as the legacy
            // wrote it — bpl_production carries no created_at of its own.
            ->get(['d.barcode', 'd.created_at as made_at', 'r.hardrollnumber', 'r.weight',
                'p.productname', 'p.gradetype']);

        $form = DB::connection('bpl')->table('bpl_delivery')
            ->where('id', DB::connection('bpl')->table('bpl_delivery_details')
                ->where('delivery_barcode', $number)->value('bpl_delivery_id'))
            ->first();

        return ['waybill' => $waybill, 'lines' => $lines, 'form' => $form];
    }

    public static function render(string $number): View
    {
        $number = trim($number);
        ['waybill' => $waybill, 'lines' => $lines, 'form' => $form] = self::load($number);

        $summary = [];
        foreach ($lines as $line) {
            $key = (string) ($line->productname ?: '—');
            $summary[$key] ??= ['product' => $key, 'grade' => (string) ($line->gradetype ?? ''), 'rolls' => 0, 'weight' => 0.0];
            $summary[$key]['rolls']++;
            $summary[$key]['weight'] += (float) $line->weight;
        }

        $truckWeight = (float) ($form->truck_weight ?? 0);
        $fullWeight = (float) ($waybill->full_weight ?? 0);

        return view('bpl::print.waybill', [
            'number' => $number,
            'status' => (string) $waybill->status,
            'date' => (string) ($form->date ?? $waybill->created_at),
            'customer' => (string) ($form->customer_name ?? ''),
            'internal' => trim((string) ($form->customer_name ?? '')) === \Modules\Bpl\Models\BplDelivery::INTERNAL_CUSTOMER,
            'driver' => (string) ($form->driver ?? ''),
            'driverPhone' => (string) ($form->driver_phone ?? ''),
            'vehicle' => (string) ($form->vechile_number ?? ''),
            'container' => (string) ($form->container_number ?? ''),
            'loader' => (string) ($form->loader ?? ''),
            'truckWeight' => $truckWeight,
            'fullWeight' => $fullWeight,
            // Only once the loaded truck has been weighed does the net mean
            // anything; until then the sheet leaves it off.
            'netWeight' => $fullWeight > 0 ? round($fullWeight - $truckWeight, 2) : null,
            'summary' => array_values($summary),
            'rolls' => $lines,
        ]);
    }

    /**
     * The processor slip: every roll on its own line, with the weighbridge
     * figures. What the receiving mill checks the load in against.
     */
    public static function processor(string $number): View
    {
        $number = trim($number);
        ['waybill' => $waybill, 'lines' => $lines, 'form' => $form] = self::load($number);

        $truckWeight = (float) ($form->truck_weight ?? 0);
        $fullWeight = (float) ($waybill->full_weight ?? 0);
        $customer = (string) ($form->customer_name ?? '');

        return view('bpl::print.delivery-processor-slip', [
            'number' => $number,
            'company' => 'Belpapyrus',
            'date' => (string) ($form->date ?? $waybill->created_at),
            'customer' => $customer,
            'internal' => trim($customer) === \Modules\Bpl\Models\BplDelivery::INTERNAL_CUSTOMER,
            'driver' => (string) ($form->driver ?? ''),
            'driverPhone' => (string) ($form->driver_phone ?? ''),
            'loader' => (string) ($form->loader ?? ''),
            'vehicle' => (string) ($form->vechile_number ?? ''),
            'container' => (string) ($form->container_number ?? ''),
            'truckWeight' => $truckWeight,
            'fullWeight' => $fullWeight,
            'netWeight' => $fullWeight > 0 ? round($fullWeight - $truckWeight, 2) : null,
            'rolls' => $lines,
            'totalWeight' => round((float) $lines->sum('weight'), 2),
            // The import file only means something for a processor we know.
            'importable' => isset(self::PROCESSOR_COMPANIES[strtoupper(trim($customer))]),
        ]);
    }

    /**
     * The load as INSERT statements for the processor's own `importdata`
     * table — the legacy's "Download SQL" button, which wrote `data.sql` into
     * the web root and handed the statements back to the browser. gds streams
     * the file straight to the clerk instead, and refuses for a customer with
     * no id in the receiving system rather than writing `company_id = ''`.
     */
    public static function importSql(string $number): \Symfony\Component\HttpFoundation\Response
    {
        $number = trim($number);
        ['lines' => $lines, 'form' => $form] = self::load($number);

        $customer = strtoupper(trim((string) ($form->customer_name ?? '')));
        $companyId = self::PROCESSOR_COMPANIES[$customer] ?? null;

        abort_if($companyId === null, 422, 'This delivery went to ' . ($form->customer_name ?: 'nobody')
            . ', which has no id in the processor system — nothing to import.');

        // Values are escaped for the file, not for this database.
        $quote = fn ($value) => "'" . addslashes((string) $value) . "'";

        $statements = $lines->map(fn ($r) => 'INSERT INTO importdata (hardrollnumber, barcode, weight, grade,'
            . ' product, company_id, date, delivery_barcode) VALUES ('
            . implode(', ', [
                $quote($r->hardrollnumber),
                $quote($r->barcode),
                $quote($r->weight),
                $quote($r->gradetype),
                $quote($r->productname),
                $quote($companyId),
                $quote($r->made_at),
                $quote($number),
            ]) . ');')->implode("
");

        $filename = str_replace(['/', ' '], ['-', ''], $number) . '.sql';

        return response($statements . "
", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
