<?php

namespace Modules\Bpl\Support;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Modules\Bpl\Models\BplPackingList;

/**
 * The packing list's numbering, its document, and the final invoice that comes
 * off it — ports of the legacy `Bil\Bpl\PackingList` and the templates
 * `bpl_packing_list.php` and `bpl_final_invoice.php`.
 */
class PackingList
{
    /**
     * The stored number: `BPL/{ISO}/:{customer id}/{ref}`.
     *
     * The customer's id is a placeholder every screen swaps for the label when
     * it prints — which is why a renamed customer's old packing lists still
     * read correctly. Kept exactly as legacy wrote it so both agree.
     */
    public static function numberFor(int $orderId): ?string
    {
        $row = DB::connection('bpl')->table('bpl_sales as s')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->where('s.id', $orderId)
            ->first(['s.ref', 'c.id as customer_id', 'co.iso']);

        if (! $row || ! $row->customer_id || ! $row->iso) {
            return null;
        }

        return str_replace(' ', '', 'BPL/' . $row->iso . '/:' . $row->customer_id . '/' . $row->ref);
    }

    /** The number as it prints: the placeholder replaced by the customer's label. */
    public static function label(?string $number, ?int $customerId, ?string $customerLabel): string
    {
        $number = (string) $number;

        if (! $customerId || $customerLabel === null) {
            return $number;
        }

        return str_replace(':' . $customerId, str_replace(' ', '', $customerLabel), $number);
    }

    /** Orders with a live proforma and no packing list yet. */
    public static function openProformas()
    {
        return DB::connection('bpl')->table('bpl_proforma as pf')
            ->join('bpl_sales as s', 's.id', '=', 'pf.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->whereNull('pf.deleted_at')
            ->whereNull('s.deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_packing_list as pl')
                ->whereColumn('pl.order_id', 'pf.order_id'))
            ->orderByDesc('s.id')
            ->limit(300)
            ->get(['s.id', 's.orderno', 's.ref', 'pf.date', 'pf.container', 'pf.freight', 'c.customername']);
    }

    /** Is a bill of lading already issued against this packing list? */
    public static function hasBillOfLading(int $packingId): bool
    {
        return DB::connection('bpl')->table('bpl_bill_of_lading')->where('packing_id', $packingId)->exists();
    }

    /**
     * Split a packing list into partial shipments.
     *
     * Each shipment becomes its own row, numbered `…(A)`, `…(B)`, carrying the
     * containers assigned to it; the original is kept and marked `split = 1`,
     * which is how the legacy screens know to leave it alone.
     *
     * @param  array<int, array>  $shipments  containers grouped per shipment
     */
    public static function split(BplPackingList $parent, array $shipments): void
    {
        DB::connection('bpl')->transaction(function () use ($parent, $shipments) {
            $letter = 'A';

            foreach ($shipments as $containers) {
                BplPackingList::create([
                    'order_id' => $parent->order_id,
                    'number' => $parent->number . '(' . $letter . ')',
                    'containers' => $containers,
                    'date' => $parent->date,
                    'split' => '0',
                ]);

                $letter++;
            }

            $parent->update(['split' => '1']);
        });
    }

    /**
     * After a delete, an order left with a single packing list is no longer
     * split — the legacy's setSplit(), kept.
     */
    public static function resetSplit(int $orderId): void
    {
        if (BplPackingList::where('order_id', $orderId)->count() === 1) {
            BplPackingList::where('order_id', $orderId)->update(['split' => '0']);
        }
    }

    /* ---------------- Documents ---------------- */

    /** Everything both sheets need about one packing list. */
    protected static function context(int $id): array
    {
        $row = DB::connection('bpl')->table('bpl_packing_list as pl')
            ->leftJoin('bpl_sales as s', 's.id', '=', 'pl.order_id')
            ->leftJoin('bpl_proforma as pf', 'pf.order_id', '=', 'pl.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->leftJoin('bpl_payment_terms as pt', 'pt.id', '=', 'pf.payment_term_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->where('pl.id', $id)
            ->first([
                'pl.id', 'pl.order_id', 'pl.number', 'pl.containers', 'pl.date',
                's.company', 's.orderno', 's.ref',
                'c.id as customer_id', 'c.customername', 'c.customerlabel', 'c.customeraddress',
                'c.customertelephone', 'c.email', 'c.port', 'co.iso',
                'pf.nxp', 'pf.freight', 'pf.terms', 'pf.shipment', 'pf.container', 'pf.freight_price',
                'pf.account_id', 'pf.customer_ref', 'pt.payment_terms', 'cur.code as currency', 'cur.symbol',
            ]);

        abort_if(! $row, 404);

        $containers = json_decode((string) $row->containers, true) ?: [];

        return ['row' => $row, 'containers' => $containers];
    }

    /** The packing list document. */
    public static function render(int $id): View
    {
        ['row' => $row, 'containers' => $containers] = self::context($id);

        $rolls = 0;
        $net = 0.0;
        $gross = 0.0;
        $anyContainerHasSeveralLots = false;

        foreach ($containers as $container) {
            $goods = $container['goods'] ?? [];
            $containerNet = array_sum(array_column($goods, 'weight'));

            $rolls += array_sum(array_column($goods, 'rolls'));
            $net += $containerNet;
            $gross += $containerNet + (float) ($container['weight'] ?? 0);

            if (count($goods) > 1) {
                $anyContainerHasSeveralLots = true;
            }
        }

        return view('bpl::print.packing-list', [
            'number' => self::label($row->number, (int) $row->customer_id, $row->customerlabel),
            'company' => (string) $row->company,
            'date' => (string) $row->date,
            'customer' => [
                'name' => (string) $row->customername,
                'address' => (string) $row->customeraddress,
                'phone' => (string) $row->customertelephone,
                'email' => (string) $row->email,
            ],
            'nxp' => (string) ($row->nxp ?? ''),
            // Inland transport goes on a truck, everything else in a container:
            // the column heading and the total both say which.
            'carrier' => $row->freight === 'Inland Transport' ? 'Truck' : 'Container',
            'containers' => $containers,
            'perContainerNet' => $anyContainerHasSeveralLots,
            'rolls' => $rolls,
            'net' => round($net, 2),
            'gross' => round($gross, 2),
        ]);
    }

    /**
     * The final invoice: the proforma, re-priced against what actually shipped.
     *
     * Each proforma line is charged on the weight of the goods that match its
     * description across the containers, and a line that shipped nothing drops
     * off the sheet. Freight is charged per container ACTUALLY shipped, which
     * is where it differs from the proforma's quoted count.
     */
    public static function finalInvoice(int $id): View
    {
        ['row' => $row, 'containers' => $containers] = self::context($id);

        abort_if(! $row->order_id || $row->currency === null, 404);

        $shipped = [];
        $rolls = 0;

        foreach ($containers as $container) {
            foreach ($container['goods'] ?? [] as $lot) {
                $key = (string) ($lot['description'] ?? '');
                $shipped[$key] = ($shipped[$key] ?? 0) + (float) ($lot['weight'] ?? 0);
                $rolls += (int) ($lot['rolls'] ?? 0);
            }
        }

        $lines = [];
        foreach (ProformaInvoice::lines((int) $row->order_id) as $line) {
            $weight = $shipped[$line['fullname']] ?? 0;

            if ($line['price'] === null || $weight <= 0) {
                continue;
            }

            $lines[] = [
                'fullname' => $line['fullname'],
                'tonnes' => round($weight / 1000, 4),
                'weight' => $weight,
                'price' => $line['price'],
                'amount' => round($line['price'] * $weight / 1000, 2),
            ];
        }

        $goods = round(array_sum(array_column($lines, 'amount')), 2);
        $freight = round(count($containers) * (float) $row->freight_price, 2);
        $grand = round($goods + $freight, 2);

        return view('bpl::print.proforma-invoice', [
            'isFinal' => true,
            'number' => ProformaInvoice::numberFor(
                self::label($row->number, (int) $row->customer_id, $row->customerlabel),
                $row->customer_ref
            ),
            'company' => (string) $row->company,
            'date' => (string) $row->date,
            'customer' => [
                'name' => (string) $row->customername,
                'label' => (string) $row->customerlabel,
                'address' => (string) $row->customeraddress,
                'phone' => (string) $row->customertelephone,
                'email' => (string) $row->email,
                'port' => (string) $row->port,
            ],
            'lines' => $lines,
            'rolls' => $rolls,
            'goods' => $goods,
            'freightCharge' => $freight,
            'grand' => $grand,
            'inWords' => ProformaInvoice::inWords($grand, (string) $row->currency),
            'currency' => (string) $row->currency,
            'symbol' => ProformaInvoice::symbol($row->symbol),
            'freight' => (string) $row->freight,
            'containers' => count($containers),
            'freightPrice' => (float) $row->freight_price,
            'terms' => $row->terms . ($row->terms === 'CFR' && $row->port ? ' ' . $row->port : ''),
            'shipment' => (string) $row->shipment,
            'paymentTerms' => (string) ($row->payment_terms ?? ''),
            'nxp' => (string) ($row->nxp ?? ''),
            'bank' => ProformaInvoice::bankBlock((int) $row->account_id),
            'packing' => 'P.E. Wrapping',
            'insurance' => 'To be covered by buyer',
            'tolerance' => '+/- 10% on value',
            'portOfLoading' => 'Tincan',
        ]);
    }
}
