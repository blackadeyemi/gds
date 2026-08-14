<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Bil\Support\SalesTerritory;
use Modules\Core\Support\Geography;

/**
 * Legacy `bil.sales_customers` — who BIL sells finished goods to.
 *
 * The master behind every sales screen: `sales_order.customerid` points here,
 * and the loading, delivery, waybill and invoice reports all read the name,
 * code and territory off these rows. No soft delete — a customer is either here
 * or gone, which is why deleting one is guarded on its orders.
 *
 * A row carries three groups of fields, and it is worth keeping them apart:
 *
 *   IDENTITY       customercode, customername
 *   CLASSIFICATION customerregion, customerdesignation, channel
 *                  — what the sales reports GROUP BY. Region/designation are
 *                    Nigerian sales territories (see Support\SalesTerritory);
 *                    channel applies anywhere.
 *   LOCATION       customercountry, customerstate, customercity,
 *                  customeraddress, customerphonenumber
 *                  — a postal address, from Core\Support\Geography.
 *
 * `created_at` is a real column with a CURRENT_TIMESTAMP default and there is
 * no `updated_at`, so Eloquent timestamps stay off and MySQL stamps the insert.
 */
class SalesCustomer extends Model
{
    protected $connection = 'bil';
    protected $table = 'sales_customers';
    public $timestamps = false;

    protected $fillable = [
        'customercode', 'customername', 'customeraddress', 'customerphonenumber',
        'customercity', 'customerstate', 'customerdesignation', 'customerregion',
        'customercountry', 'channel',
    ];

    /**
     * Trade channels, as the legacy form offered them (hard-coded in
     * `js/customers.js`). Not a table: four values, no screen to manage them in,
     * and adding a fifth is a decision about how sales are reported rather than
     * a row someone types. 1,060 customers have none — the field is optional and
     * always has been, so a blank stays valid.
     */
    public const CHANNELS = ['Cash Van', 'Distributor', 'Horeca', 'Supermarket'];

    /** Where the business is; what a blank country falls back to. */
    public const DEFAULT_COUNTRY = Geography::DEFAULT_COUNTRY;

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'customerid');
    }

    /* ---------------- Reading a row ---------------- */

    /** Name as shown in pickers and lists, with the code when there is one. */
    public function pickerLabel(): string
    {
        return $this->customercode
            ? $this->customername . ' — ' . $this->customercode
            : (string) $this->customername;
    }

    /** Does a sales territory apply to this customer? (Nigerian customers do.) */
    public function hasTerritory(): bool
    {
        return SalesTerritory::appliesTo($this->customercountry);
    }

    /**
     * Which classification fields this customer is missing.
     *
     * The fields the sales reports group by. Territory is only counted against
     * customers a territory applies to — a Ghanaian customer is not "missing" a
     * Nigerian region, and counting it as such would put a permanent, unfixable
     * row on the clean-up worklist.
     *
     * @return string[] e.g. ['region', 'channel']
     */
    public function missingClassification(): array
    {
        $missing = [];

        if ($this->hasTerritory()) {
            if (! $this->customerregion) {
                $missing[] = 'region';
            }
            if (! $this->customerdesignation) {
                $missing[] = 'designation';
            }
        }

        if (! $this->channel) {
            $missing[] = 'channel';
        }

        return $missing;
    }

    /* ---------------- Scopes ---------------- */

    public function scopeOrdered($query)
    {
        return $query->orderBy('customername');
    }

    /** Customers a Nigerian sales territory applies to. */
    public function scopeInTerritory($query)
    {
        return $query->where(fn ($q) => $q
            ->where('customercountry', SalesTerritory::COUNTRY)
            // 624 rows predate the country field; they are Nigerian in
            // everything but the blank, and the form defaults them on edit.
            ->orWhereNull('customercountry')->orWhere('customercountry', ''));
    }

    /**
     * Customers whose classification has a hole — the clean-up worklist.
     *
     * Mirrors missingClassification(): a missing territory only counts where a
     * territory applies, a missing channel counts everywhere.
     */
    public function scopeNeedsClassification($query)
    {
        $blank = fn ($q, $col) => $q->whereNull($col)->orWhere($col, '');

        return $query->where(fn ($outer) => $outer
            ->where(fn ($q) => $q->inTerritory()->where(fn ($w) => $w
                ->where(fn ($i) => $blank($i, 'customerregion'))
                ->orWhere(fn ($i) => $blank($i, 'customerdesignation'))))
            ->orWhere(fn ($q) => $blank($q, 'channel')));
    }

    /* ---------------- Local knowledge ---------------- */

    /**
     * States this customer list already uses in a country.
     *
     * The fallback for countries the geo dataset has no subdivisions for, and
     * the way a spelling already in use ("LAGOS STATE") stays offerable.
     */
    public static function statesUsedIn(?string $country): array
    {
        return self::distinctValues('customerstate', ['customercountry' => trim((string) $country)]);
    }

    /** Cities this customer list already uses in a country + state. */
    public static function citiesUsedIn(?string $country, ?string $state): array
    {
        return self::distinctValues('customercity', array_filter([
            'customercountry' => trim((string) $country),
            'customerstate' => trim((string) $state),
        ], fn ($v) => $v !== ''));
    }

    protected static function distinctValues(string $column, array $where): array
    {
        if ($where === []) {
            return [];
        }

        return static::query()->where($where)
            ->whereNotNull($column)->where($column, '<>', '')
            ->distinct()->orderBy($column)
            ->pluck($column)->all();
    }
}
