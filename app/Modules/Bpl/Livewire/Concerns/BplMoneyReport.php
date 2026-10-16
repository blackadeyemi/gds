<?php

namespace Modules\Bpl\Livewire\Concerns;

use Illuminate\Support\Facades\DB;
use Modules\Bpl\Support\SalesFigures;

/**
 * The conventions every BPL report that shows money has to follow.
 *
 * Used by the Sales reports and the Finance reports, which are the same
 * business seen from two ends — one asks what was sold, the other what was
 * collected — and which therefore have to agree about currency, formatting and
 * how an order is named.
 *
 * ## Money is never totalled across currencies
 *
 * BPL sells in naira, dollars, euros and pounds, and the amounts live in ONE
 * column with the currency beside them. Adding a naira invoice to a dollar one
 * produces a number that means nothing, so a value column is only totalled
 * once the Currency filter narrows the page to a single one — moneyTotals()
 * below. Each row still prints its own symbol, so a mixed page is readable,
 * just not summable, and the subtitle says so.
 */
trait BplMoneyReport
{
    protected function connection(): string
    {
        return 'bpl';
    }

    protected function db()
    {
        return DB::connection('bpl');
    }

    /* ---------------- Currency ---------------- */

    /** The currency filter's value, or null when the page shows all of them. */
    protected function currencyId(): ?int
    {
        $value = $this->filters['currency'] ?? '';

        return $value === '' || $value === 'all' ? null : (int) $value;
    }

    /**
     * Fields to total, but only when one currency is on the page.
     *
     * @param  array<int,string>  $fields  the money columns
     * @param  array<int,string>  $always  columns safe to total whatever the currency (weights, counts)
     */
    protected function moneyTotals(array $fields, array $always = []): array
    {
        return $this->currencyId() === null ? $always : array_merge($always, $fields);
    }

    /** The line under the title, with a word about the totals when they are off. */
    public function subtitle(): string
    {
        $base = $this->headline();

        if ($this->currencyId() !== null || ! $this->hasMoneyColumns()) {
            return $base;
        }

        return $base . ' Amounts are shown per row in their own currency; pick a '
            . 'currency to total them.';
    }

    /** The report's own one-line description. */
    abstract protected function headline(): string;

    /** Whether this report shows amounts at all. */
    protected function hasMoneyColumns(): bool
    {
        return true;
    }

    /* ---------------- Cells ---------------- */

    /**
     * An amount with the row's own currency symbol.
     *
     * The symbol is stored as an HTML entity (`&#36;`), so it is decoded or
     * the cell reads "&#36;900.00". `$symbolField` is the selected column
     * holding it.
     */
    protected function moneyCell(string $field, string $symbolField = 'symbol'): callable
    {
        return function ($r) use ($field, $symbolField) {
            $value = data_get($r, $field);

            if ($value === null || $value === '') {
                return '';
            }

            return e(SalesFigures::symbol((string) data_get($r, $symbolField)))
                . number_format((float) $value, 2);
        };
    }

    /** Kilograms, two decimals. */
    protected function kgCell(string $field): callable
    {
        return fn ($r) => ($v = data_get($r, $field)) === null || $v === ''
            ? '' : number_format((float) $v, 2);
    }

    /** Tonnes, from a kilogram column. */
    protected function tonnesCell(string $field): callable
    {
        return fn ($r) => ($v = data_get($r, $field)) === null || $v === ''
            ? '' : number_format((float) $v / 1000, 3);
    }

    /** A yes/no badge. */
    protected function flagCell(string $field, string $yes, string $no, string $yesClass = 'badge-success'): callable
    {
        return fn ($r) => data_get($r, $field)
            ? '<span class="badge ' . $yesClass . '">' . e($yes) . '</span>'
            : '<span class="badge badge-muted">' . e($no) . '</span>';
    }

    /* ---------------- Shared filter options ---------------- */

    /** @var array<string,array>|null */
    private ?array $sharedCache = null;

    protected function shared(string $key): array
    {
        $this->sharedCache ??= [
            'currencies' => SalesFigures::currencies(),
            'customers' => $this->db()->table('bpl_customers')
                ->whereNull('deleted_at')
                ->whereNotNull('customername')->where('customername', '<>', '')
                ->orderBy('customername')->pluck('customername', 'id')->all(),
            'countries' => $this->db()->table('bpl_customers')
                ->whereNull('deleted_at')
                ->whereNotNull('customercountry')->where('customercountry', '<>', '')
                ->select('customercountry')->distinct()->orderBy('customercountry')
                ->pluck('customercountry', 'customercountry')->all(),
        ];

        return $this->sharedCache[$key] ?? [];
    }

    /**
     * The order's number as the documents show it: the stored `orderno` when
     * there is one, else the number built from country, customer and ref the
     * way the legacy composed it.
     *
     * The third fallback matters. CONCAT returns NULL if ANY part is NULL, so
     * an order whose customer has since been deleted — or whose country is not
     * in the country list — produced a blank cell in the one column a reader
     * identifies the row by. The ref at least says which order it is.
     */
    protected function orderNumberExpr(): string
    {
        return 'COALESCE(`s`.`orderno`,'
            . " NULLIF(REPLACE(CONCAT('BPL/', `co`.`iso`, '/', `c`.`customerlabel`, '/', `s`.`ref`), ' ', ''), ''),"
            . " CONCAT('#', `s`.`ref`))";
    }
}
