<?php

namespace Modules\Bpl\Support;

use Illuminate\Support\Facades\DB;
use Modules\Bpl\Models\BplCustomer;

/**
 * BPL sales order numbers: `BPL/{country ISO}/{customer label}/{ref}`,
 * e.g. `BPL/GH/ACIPAC/063`.
 *
 * Unlike BIL, where the clerk types the order number, BPL's is GENERATED — the
 * legacy app already derived it from the customer; gds now also stores it.
 *
 * `ref` is a per-customer sequence, zero-padded to three digits. The legacy
 * code took `MAX(ref)` over a VARCHAR column, which sorts as text: at 1000 it
 * would return '999' again and hit the UNIQUE (ref, customerid) index. This
 * compares numerically. Soft-deleted orders count — their ref still occupies
 * the unique index, and a number that was printed must not be re-issued.
 */
class SalesOrderNumber
{
    /** Next ref for a customer. Call inside the saving transaction. */
    public static function nextRef(int $customerId): string
    {
        $max = DB::connection('bpl')->table('bpl_sales')
            ->where('customerid', $customerId)
            ->lockForUpdate()
            ->max(DB::connection('bpl')->raw('CAST(`ref` AS UNSIGNED)'));

        return str_pad((string) (((int) $max) + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * The order number for a customer and ref, or null when the customer
     * cannot be numbered — no label, or a country that does not resolve to an
     * ISO code. Same formula as the legacy screens, so the two agree.
     */
    public static function compose(BplCustomer $customer, string $ref): ?string
    {
        $iso = self::isoFor($customer);
        $label = str_replace(' ', '', trim((string) $customer->customerlabel));

        if ($iso === null || $label === '') {
            return null;
        }

        return 'BPL/' . $iso . '/' . $label . '/' . $ref;
    }

    /** Why a customer cannot be numbered, in words, or null when it can. */
    public static function problemWith(BplCustomer $customer): ?string
    {
        if (trim((string) $customer->customerlabel) === '') {
            return $customer->customername . ' has no label — set one under BPL → Sales → Customers.';
        }

        if (self::isoFor($customer) === null) {
            $country = trim((string) $customer->customercountry);

            return $country === ''
                ? $customer->customername . ' has no country — set one under BPL → Sales → Customers.'
                : $customer->customername . '\'s country "' . $country . '" is not a recognised country — correct it under BPL → Sales → Customers.';
        }

        return null;
    }

    public static function isoFor(BplCustomer $customer): ?string
    {
        $country = trim((string) $customer->customercountry);

        if ($country === '') {
            return null;
        }

        $iso = DB::connection('bpl')->table('countries')->where('name', $country)->value('iso');

        return $iso ? strtoupper($iso) : null;
    }
}
