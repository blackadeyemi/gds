<?php

namespace Modules\Bpl\Support;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Modules\Bpl\Models\BplProforma;

/**
 * Everything the proforma invoice sheet needs — the port of the legacy
 * `app/views/bpl_proforma_invoice.php`, which built its own SQL inside the
 * template.
 *
 * THE MATHS, unchanged: a line's price is per tonne and its weight is in
 * kilograms, so `amount = price * weight / 1000`. Freight is quoted per
 * container: `freight charge = containers * freight price`. The grand total is
 * the goods plus the freight.
 *
 * Fixed on every sheet, as before: packing "P.E. Wrapping", insurance "To be
 * covered by buyer", tolerance "+/- 10% on value", port of loading "Tincan".
 */
class ProformaInvoice
{
    /** Words for the "Say … Only" line. GBP is included; the legacy had only three. */
    private const CURRENCY_WORDS = [
        'usd' => ['dollars', 'cents'],
        'eur' => ['euros', 'cents'],
        'ngn' => ['naira', 'kobo'],
        'gbp' => ['pounds', 'pence'],
    ];

    /**
     * The currency's symbol as a character.
     *
     * `currencies.symbol` holds HTML entities — `&#8358;`, `&#36;`, `&#x20AC;`,
     * `&#163;` — because the legacy templates dropped them straight into the
     * markup unescaped. Anything that escapes its output (Blade, a grid cell)
     * would print the entity itself, so it is decoded here, once.
     */
    public static function symbol(?string $raw): string
    {
        return html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The invoice number: the order's number, plus the customer's own reference. */
    public static function numberFor(?string $orderno, ?string $customerRef): string
    {
        $number = (string) ($orderno ?: '');

        return trim($customerRef ?? '') !== '' ? $number . ' - ' . trim($customerRef) : $number;
    }

    /**
     * The priced lines of an order.
     *
     * Every line of the ORDER is returned, priced or not, so the form can put a
     * price on a line added after the proforma was raised. The printed sheet
     * drops the unpriced ones.
     *
     * @return array<int, array>
     */
    public static function lines(int $orderId): array
    {
        $rows = DB::connection('bpl')->table('bpl_sales_items as i')
            ->leftJoin('bpl_proforma_items as pi', 'pi.order_item_id', '=', 'i.id')
            ->leftJoin('bpl_products_hardroll as p', 'p.id', '=', 'i.productid')
            ->where('i.order_id', $orderId)
            ->orderBy('i.id')
            ->get(['i.id as order_item_id', 'pi.id as item_id', 'pi.price', 'i.weight',
                'p.productname', 'p.gradetype', 'p.gsm', 'p.ply', 'p.width', 'p.diameter', 'p.slice']);

        return $rows->map(fn ($r) => [
            'order_item_id' => (int) $r->order_item_id,
            'item_id' => $r->item_id !== null ? (int) $r->item_id : null,
            'productname' => (string) ($r->productname ?? '—'),
            // The description the legacy printed, built from the product's own
            // specification — the packing list matches goods on this string.
            'fullname' => self::fullName($r),
            'weight' => (float) $r->weight,
            'tonnes' => round((float) $r->weight / 1000, 4),
            'price' => $r->price !== null ? (float) $r->price : null,
            'amount' => $r->price !== null ? round((float) $r->price * (float) $r->weight / 1000, 2) : null,
        ])->all();
    }

    /** "SKT 19gsm, 2ply, 279cm width, 121cm diameter, 1 slice" — the legacy CONCAT. */
    public static function fullName($p): string
    {
        if (($p->productname ?? null) === null) {
            return '—';
        }

        $slice = (int) ($p->slice ?: 1);

        return trim((string) $p->gradetype) . ' ' . (float) $p->gsm . 'gsm, ' . (int) $p->ply . 'ply, '
            . (float) $p->width . 'cm width, ' . (float) $p->diameter . 'cm diameter, '
            . $slice . ($slice === 1 ? ' slice' : ' slices');
    }

    /** "one thousand two hundred and thirty-four dollars and fifty cents" */
    public static function inWords(float $amount, string $currencyCode): string
    {
        $words = self::CURRENCY_WORDS[strtolower($currencyCode)] ?? ['', ''];

        [$whole, $fraction] = explode('.', number_format($amount, 2, '.', ''));

        $fmt = new \NumberFormatter('en', \NumberFormatter::SPELLOUT);
        $fmt->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, '%spellout-numbering-verbose');

        $said = $fmt->format((int) $whole) . ' ' . $words[0];

        if ((int) $fraction > 0) {
            $said .= ' and ' . $fmt->format((int) $fraction) . ' ' . $words[1];
        }

        return ucwords($said);
    }

    /** The whole sheet for one order, or a 404. */
    public static function render(int $orderId): View
    {
        $row = DB::connection('bpl')->table('bpl_proforma as pf')
            ->join('bpl_sales as s', 's.id', '=', 'pf.order_id')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->leftJoin('countries as co', 'co.name', '=', 'c.customercountry')
            ->leftJoin('bpl_payment_terms as pt', 'pt.id', '=', 'pf.payment_term_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'pf.currency_id')
            ->where('pf.order_id', $orderId)
            ->whereNull('pf.deleted_at')
            ->whereNull('s.deleted_at')
            ->first([
                'pf.*', 's.ref', 's.orderno', 's.company', 's.date as order_date',
                'c.customername', 'c.customerlabel', 'c.customeraddress', 'c.customertelephone',
                'c.email', 'c.port', 'co.iso', 'pt.payment_terms', 'cur.code as currency', 'cur.symbol',
            ]);

        abort_if(! $row, 404);

        $lines = array_values(array_filter(self::lines($orderId), fn ($l) => $l['price'] !== null));

        $goods = round(array_sum(array_column($lines, 'amount')), 2);
        $freight = round((int) $row->container * (float) $row->freight_price, 2);
        $grand = round($goods + $freight, 2);

        return view('bpl::print.proforma-invoice', [
            'number' => self::numberFor($row->orderno, $row->customer_ref),
            'company' => (string) $row->company,
            'date' => (string) ($row->date ?: $row->order_date),
            'customer' => [
                'name' => (string) $row->customername,
                'label' => (string) $row->customerlabel,
                'address' => (string) $row->customeraddress,
                'phone' => (string) $row->customertelephone,
                'email' => (string) $row->email,
                'port' => (string) $row->port,
            ],
            'lines' => $lines,
            'goods' => $goods,
            'freightCharge' => $freight,
            'grand' => $grand,
            'inWords' => self::inWords($grand, (string) $row->currency),
            'currency' => (string) $row->currency,
            'symbol' => self::symbol($row->symbol),
            'freight' => (string) $row->freight,
            'containers' => (int) $row->container,
            'freightPrice' => (float) $row->freight_price,
            // CFR quotes the port it is carried to; the legacy appended it here.
            'terms' => $row->terms . ($row->terms === 'CFR' && $row->port ? ' ' . $row->port : ''),
            'shipment' => (string) $row->shipment,
            'paymentTerms' => (string) ($row->payment_terms ?? ''),
            'nxp' => (string) ($row->nxp ?? ''),
            'bank' => self::bankBlock((int) $row->account_id),
            'packing' => 'P.E. Wrapping',
            'insurance' => 'To be covered by buyer',
            'tolerance' => '+/- 10% on value',
            'portOfLoading' => 'Tincan',
        ]);
    }

    /**
     * The receiving account as the sheet prints it.
     *
     * A routed account (intermediary and/or correspondent bank) prints the
     * chain and the further-credit account; a plain one prints its own
     * details. The legacy also carried a hard-coded block for the customer
     * labelled "Delta Paper Mill-" that ignored the account on the proforma
     * entirely — that is NOT reproduced: the account says where the money goes.
     */
    public static function bankBlock(int $accountId): ?array
    {
        $a = DB::connection('bpl')->table('bpl_accounts as a')
            ->leftJoin('bpl_banks as b', 'b.id', '=', 'a.beneficiary')
            ->leftJoin('bpl_banks as i', 'i.id', '=', 'a.intermediary')
            ->leftJoin('bpl_banks as c', 'c.id', '=', 'a.correspondent')
            ->where('a.id', $accountId)
            ->first(['a.account', 'a.further_acc',
                'b.name as bank', 'b.number', 'b.sortcode', 'b.swiftcode', 'b.address',
                'i.name as intermediary_bank', 'i.number as intermediary_number', 'i.swiftcode as intermediary_swiftcode',
                'c.name as correspondent_bank', 'c.number as correspondent_number', 'c.swiftcode as correspondent_swiftcode']);

        if (! $a) {
            return null;
        }

        return [
            'routed' => (bool) ($a->intermediary_bank || $a->correspondent_bank),
            'account' => (string) $a->account,
            'further_acc' => (string) $a->further_acc,
            'bank' => (string) $a->bank,
            'number' => (string) $a->number,
            'sortcode' => (string) $a->sortcode,
            'swiftcode' => (string) $a->swiftcode,
            'address' => (string) $a->address,
            'intermediary_bank' => (string) $a->intermediary_bank,
            'intermediary_number' => (string) $a->intermediary_number,
            'intermediary_swiftcode' => (string) $a->intermediary_swiftcode,
            'correspondent_bank' => (string) $a->correspondent_bank,
            'correspondent_number' => (string) $a->correspondent_number,
            'correspondent_swiftcode' => (string) $a->correspondent_swiftcode,
        ];
    }

    /** Orders that could take a proforma: none raised yet (or one deleted). */
    public static function openOrders()
    {
        return DB::connection('bpl')->table('bpl_sales as s')
            ->leftJoin('bpl_customers as c', 'c.id', '=', 's.customerid')
            ->whereNull('s.deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bpl_proforma as pf')
                ->whereColumn('pf.order_id', 's.id')->whereNull('pf.deleted_at'))
            ->orderByDesc('s.id')
            ->limit(300)
            ->get(['s.id', 's.orderno', 's.ref', 's.date', 'c.customername']);
    }

    /** Whether an order already carries a proforma, deleted ones included. */
    public static function existingFor(int $orderId): ?BplProforma
    {
        return BplProforma::withTrashed()->find($orderId);
    }
}
