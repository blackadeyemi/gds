<?php

namespace Modules\Bil\Livewire\Sales;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Modules\Bil\Models\SalesCustomer;
use Modules\Bil\Support\SalesTerritory;
use Modules\Core\Livewire\DataGrid;
use Modules\Core\Support\Geography;

/**
 * BIL → Sales → Customers. Rebuild of legacy `sales_customers.php` +
 * `js/customers.js` and `Bil\Sales\Customer`, superseding the older
 * `sales_customers_details.php` (the same fields in a hand-rolled table form).
 *
 * ── HOW THIS PAGE IS ORGANISED ─────────────────────────────────────────────
 * A customer record is three separate things, and the code is laid out that way
 * rather than as ten fields in a row:
 *
 *   IDENTITY        code + name. Must not collide with another customer.
 *   CLASSIFICATION  region + designation + channel. What the sales reports
 *                   GROUP BY. Region/designation are NIGERIAN sales territories
 *                   and are null for a customer anywhere else.
 *   LOCATION        country → state → city, plus address and phone. A cascade:
 *                   choosing a parent narrows, and clears, what is under it.
 *
 * The rules for each live where they belong, not in this class:
 *   Bil\Support\SalesTerritory  — what a territory is and who has one
 *   Core\Support\Geography      — countries, states, cities, dial codes
 *   Bil\Models\SalesCustomer    — what "missing classification" means, and the
 *                                 states/cities this list already uses
 *
 * What is left here is the form: hold the values, offer the right options,
 * cascade on change, and refuse a save that would make the data worse. Each
 * refusal is one `reject*()` method that returns true when it has objected.
 *
 * ── WHAT CHANGED FROM LEGACY, DELIBERATELY ─────────────────────────────────
 *  - Territory is Nigeria-only. Legacy offered LAGOS/NORTH/… to any customer;
 *    one Cameroonian is filed under "WEST" as a result, which no by-territory
 *    report can do anything sensible with.
 *  - Country is a real list (250) and drives state → city and the dial code,
 *    instead of a readonly "Nigeria" that could not describe the Ghana and
 *    Cameroon rows.
 *  - Duplicate codes/names are refused going forward only. 14 codes are already
 *    shared by 28 live customers; enforcing outright would lock them out of
 *    editing entirely.
 *  - An "Unclassified" view: the report-facing fields are optional (they always
 *    were), so the gaps need naming rather than blocking.
 */
#[Title('Sales Customers')]
class Customers extends DataGrid
{
    /* ---------------- Form state ---------------- */

    // Identity
    public string $customername = '';
    public string $customercode = '';

    // Classification
    public ?string $customerregion = null;
    public ?string $customerdesignation = null;
    public ?string $channel = null;

    // Location
    public string $customercountry = SalesCustomer::DEFAULT_COUNTRY;
    public string $customerstate = '';
    public string $customercity = '';
    public string $customeraddress = '';
    public string $customerphonenumber = '';

    /** Was the record's country blank in the DB? Drives a note on the form. */
    public bool $countryWasBlank = false;

    /* ---------------- Grid ---------------- */

    public function pageKey(): string { return 'bil.sales.customers'; }
    public function pageLabel(): string { return 'Sales Customers'; }
    public function pageSubtitle(): string { return 'The customer master behind every sales order, loading and delivery.'; }
    public function editable(): bool { return true; }
    public function formView(): ?string { return 'bil::livewire.forms.sales-customer'; }
    public function defaultSort(): array { return ['customername', 'asc']; }
    public function modalSize(): string { return '680px'; }

    public function views(): array
    {
        $or = fn ($v) => ($v === null || $v === '') ? '—' : e($v);

        return [
            'default' => [
                'label' => 'Default',
                'type' => 'table',
                'columns' => [
                    ['Code', 'customercode'],
                    ['Customer', 'customername'],
                    ['Channel', 'channel', fn ($r) => $or($r->channel)],
                    ['Region', 'customerregion', fn ($r) => $or($r->customerregion)],
                    ['Designation', 'customerdesignation', fn ($r) => $or($r->customerdesignation)],
                    ['City', 'customercity', fn ($r) => $or($r->customercity)],
                    ['State', 'customerstate', fn ($r) => $or($r->customerstate)],
                ],
                'query' => fn () => SalesCustomer::query()->withCount('orders'),
                'searchable' => ['customercode', 'customername', 'customercity', 'customerstate', 'customerdesignation'],
                'sortable' => ['customercode', 'customername', 'channel', 'customerregion', 'customerdesignation', 'customercity', 'customerstate'],
            ],
            'contact' => [
                'label' => 'Contact details',
                'type' => 'table',
                'columns' => [
                    ['Code', 'customercode'],
                    ['Customer', 'customername'],
                    ['Phone', 'customerphonenumber', fn ($r) => $or($r->customerphonenumber)],
                    // varchar(1000): shortened on screen, full in the export
                    // (exports read the field, not this closure).
                    ['Address', 'customeraddress', fn ($r) => $r->customeraddress
                        ? '<span title="' . e($r->customeraddress) . '">' . e(Str::limit($r->customeraddress, 60)) . '</span>'
                        : '—'],
                    ['City', 'customercity', fn ($r) => $or($r->customercity)],
                    ['State', 'customerstate', fn ($r) => $or($r->customerstate)],
                    ['Country', 'customercountry', fn ($r) => $or($r->customercountry)],
                ],
                'query' => fn () => SalesCustomer::query()->withCount('orders'),
                'searchable' => ['customercode', 'customername', 'customerphonenumber', 'customeraddress', 'customercity'],
                'sortable' => ['customercode', 'customername', 'customercity', 'customerstate', 'customercountry'],
            ],
            'unclassified' => [
                'label' => 'Unclassified',
                'type' => 'table',
                'columns' => [
                    ['Code', 'customercode'],
                    ['Customer', 'customername'],
                    ['Country', 'customercountry', fn ($r) => $or($r->customercountry)],
                    ['Missing', null, fn ($r) => collect($r->missingClassification())
                        ->map(fn ($f) => '<span class="badge badge-danger">' . e($f) . '</span>')
                        ->implode(' ') ?: '—'],
                    ['Orders', 'orders_count'],
                ],
                'query' => fn () => SalesCustomer::query()->needsClassification()->withCount('orders'),
                'searchable' => ['customercode', 'customername'],
                'sortable' => ['customercode', 'customername', 'customercountry', 'orders_count'],
            ],
            'by_region' => [
                'label' => 'Summary (by territory)',
                'type' => 'summary',
                'columns' => [
                    ['Region', 'region'],
                    ['Designation', 'designation'],
                    ['Customers', 'total'],
                ],
                'query' => fn () => SalesCustomer::query()
                    ->selectRaw("COALESCE(NULLIF(customerregion, ''), '—') as region,
                                 COALESCE(NULLIF(customerdesignation, ''), '—') as designation,
                                 COUNT(*) as total")
                    ->groupBy('region', 'designation')
                    ->orderBy('region')->orderBy('designation'),
            ],
            'by_channel' => [
                'label' => 'Summary (by channel)',
                'type' => 'summary',
                'columns' => [
                    ['Channel', 'channel_name'],
                    ['Customers', 'total'],
                ],
                'query' => fn () => SalesCustomer::query()
                    ->selectRaw("COALESCE(NULLIF(channel, ''), '—') as channel_name, COUNT(*) as total")
                    ->groupBy('channel_name')
                    ->orderByDesc('total'),
            ],
        ];
    }

    /* ================= CLASSIFICATION ================= */

    /** Does a Nigerian sales territory apply to the country on the form? */
    #[Computed]
    public function territoryApplies(): bool
    {
        return SalesTerritory::appliesTo($this->customercountry);
    }

    #[Computed]
    public function regionOptions(): array
    {
        return $this->territoryApplies ? SalesTerritory::regions() : [];
    }

    /**
     * Designations for the chosen region, plus the record's own value when the
     * territory list has never heard of it — see SalesTerritory::isHistoric().
     */
    #[Computed]
    public function designationOptions(): array
    {
        if (! $this->territoryApplies) {
            return [];
        }

        return $this->keepCurrent(
            SalesTerritory::designationsIn($this->customerregion),
            $this->customerdesignation
        );
    }

    /** The four legacy channels, plus any other value the record already has. */
    #[Computed]
    public function channelOptions(): array
    {
        return $this->keepCurrent(SalesCustomer::CHANNELS, $this->channel);
    }

    /* ================= LOCATION ================= */

    #[Computed]
    public function countryOptions(): array
    {
        return Geography::countries();
    }

    /** "State", "Province", "Region" — whatever this country calls them. */
    #[Computed]
    public function stateNoun(): string
    {
        return Geography::stateNoun($this->customercountry) ?: 'State';
    }

    /**
     * States to offer: the reference list for the country, merged with the
     * spellings this customer list already uses.
     *
     * Merged, not either/or. The reference data has "Lagos"; 207 customers say
     * "LAGOS STATE" and 918 say "LAGOS". Offering only the canonical names
     * would make every one of those rows look wrong; offering only ours would
     * mean no country outside Nigeria ever gets a list.
     */
    #[Computed]
    public function stateOptions(): array
    {
        return $this->keepCurrent(
            $this->merge(
                Geography::states($this->customercountry),
                SalesCustomer::statesUsedIn($this->customercountry)
            ),
            $this->customerstate
        );
    }

    /** Same idea for cities: reference data first, then what we actually use. */
    #[Computed]
    public function cityOptions(): array
    {
        return $this->keepCurrent(
            $this->merge(
                Geography::cities($this->customercountry, $this->customerstate),
                SalesCustomer::citiesUsedIn($this->customercountry, $this->customerstate)
            ),
            $this->customercity
        );
    }

    /**
     * Dial code for the chosen country, shown beside the phone box.
     *
     * Displayed, NOT stored: the 1,898 existing rows hold national numbers that
     * the legacy screens and printed waybills already use, and prefixing them
     * would change what those records say.
     */
    #[Computed]
    public function dialCode(): ?string
    {
        return Geography::dialCode($this->customercountry);
    }

    /* ================= CASCADE =================
     | Changing a parent clears the children that no longer belong to it. The
     | three hooks are the same rule at three levels; nothing else clears state.
     */

    /** Country decides the territory, the state list and the dial code. */
    public function updatedCustomercountry(): void
    {
        $this->customerstate = '';
        $this->customercity = '';

        if (! SalesTerritory::appliesTo($this->customercountry)) {
            $this->customerregion = null;
            $this->customerdesignation = null;
        }

        $this->forgetOptions();
    }

    public function updatedCustomerregion(): void
    {
        if (! SalesTerritory::belongsTo($this->customerdesignation, $this->customerregion)) {
            $this->customerdesignation = null;
        }

        $this->forgetOptions();
    }

    public function updatedCustomerstate(): void
    {
        $this->customercity = '';
        $this->forgetOptions();
    }

    protected function forgetOptions(): void
    {
        unset($this->territoryApplies, $this->regionOptions, $this->designationOptions,
              $this->channelOptions, $this->stateOptions, $this->cityOptions,
              $this->stateNoun, $this->dialCode);
    }

    /* ================= LOAD / RESET ================= */

    protected function resetForm(): void
    {
        $this->customername = '';
        $this->customercode = '';
        $this->customerregion = null;
        $this->customerdesignation = null;
        $this->channel = null;
        $this->customercountry = SalesCustomer::DEFAULT_COUNTRY;
        $this->customerstate = '';
        $this->customercity = '';
        $this->customeraddress = '';
        $this->customerphonenumber = '';
        $this->countryWasBlank = false;
        $this->forgetOptions();
    }

    protected function fillForm(int $id): void
    {
        $c = SalesCustomer::findOrFail($id);

        $this->customername = (string) $c->customername;
        $this->customercode = (string) $c->customercode;
        $this->customerregion = $c->customerregion ?: null;
        $this->customerdesignation = $c->customerdesignation ?: null;
        $this->channel = $c->channel ?: null;

        // 624 customers have no country. It is required (NOT NULL, and legacy's
        // select carried `required`), so a blank one is defaulted rather than
        // blocking every future edit of those rows on a field nobody meant to
        // leave empty. The form says so, in red.
        $this->countryWasBlank = ! $c->customercountry;
        $this->customercountry = $c->customercountry ?: SalesCustomer::DEFAULT_COUNTRY;

        $this->customerstate = (string) $c->customerstate;
        $this->customercity = (string) $c->customercity;
        $this->customeraddress = (string) $c->customeraddress;
        $this->customerphonenumber = (string) $c->customerphonenumber;

        $this->forgetOptions();
    }

    /* ================= SAVE ================= */

    protected function rules(): array
    {
        return [
            // Identity
            'customername' => ['required', 'string', 'max:255'],
            'customercode' => ['required', 'string', 'max:255'],

            // Classification — all optional; the Unclassified view is the
            // worklist for the gaps rather than a block on saving.
            'customerregion' => ['nullable', 'string', 'max:50'],
            'customerdesignation' => ['nullable', 'string', 'max:50'],
            'channel' => ['nullable', 'string', 'max:25'],

            // Location. Country is closed — every non-blank value in the table
            // already matches the reference list, so nothing is grandfathered.
            // State and city are suggestions over free text: 64 state spellings
            // are in use against 37 real states, and narrowing them would
            // either reject those rows or rewrite what they say.
            'customercountry' => ['required', 'string', Rule::in($this->countryOptions)],
            'customerstate' => ['nullable', 'string', 'max:255'],
            'customercity' => ['nullable', 'string', 'max:50'],
            'customeraddress' => ['nullable', 'string', 'max:1000'],
            'customerphonenumber' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\-. ]+$/'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'customername' => 'customer',
            'customercode' => 'code',
            'customerregion' => 'region',
            'customerdesignation' => 'designation',
            'customercountry' => 'country',
            'customerstate' => 'state',
            'customercity' => 'city',
            'customeraddress' => 'address',
            'customerphonenumber' => 'phone number',
        ];
    }

    public function save(): void
    {
        $this->normalise();

        $data = $this->scopeTerritory($this->validate());

        $original = $this->editingId ? SalesCustomer::find($this->editingId) : null;

        if ($this->rejectDuplicate($data, $original) || $this->rejectStrayDesignation($data, $original)) {
            return;
        }

        SalesCustomer::updateOrCreate(['id' => $this->editingId], $this->forColumns($data));

        $this->showModal = false;
        session()->flash('ok', $this->editingId ? 'Customer updated.' : 'Customer added.');
    }

    /**
     * Tidy what was typed.
     *
     * Legacy upper-cased city and state as you left the field and the data
     * follows that (34 of 1,898 aside). Trailing spaces are rife —
     * "LAGOS STATE " — so everything is trimmed. Name and address keep their
     * case; they are read by people, not grouped by.
     */
    protected function normalise(): void
    {
        $this->customername = trim($this->customername);
        $this->customercode = trim($this->customercode);
        $this->customercountry = trim($this->customercountry);
        $this->customerstate = Str::upper(trim($this->customerstate));
        $this->customercity = Str::upper(trim($this->customercity));
        $this->customeraddress = trim($this->customeraddress);
        $this->customerphonenumber = trim($this->customerphonenumber);
    }

    /**
     * A territory only means something in Nigeria — clear it for anyone else.
     *
     * Enforced here and not only in the cascade, because the form is not the
     * only way these properties can be set and a stale region must never
     * survive onto a foreign customer. One Cameroonian customer is filed under
     * "WEST" today; this clears it the next time that record is saved.
     */
    protected function scopeTerritory(array $data): array
    {
        if (! SalesTerritory::appliesTo($data['customercountry'])) {
            $data['customerregion'] = null;
            $data['customerdesignation'] = null;
        }

        return $data;
    }

    /**
     * Match the columns' own idea of "empty".
     *
     * `customercity` is NOT NULL with no default, so an omitted city is the
     * empty string the other 650 blanks use — null would be a strict-mode
     * error. `customerstate` IS nullable and 403 rows are already NULL.
     */
    protected function forColumns(array $data): array
    {
        $data['customercity'] = $data['customercity'] ?? '';
        $data['customerstate'] = ($data['customerstate'] ?? '') !== '' ? $data['customerstate'] : null;

        return $data;
    }

    /**
     * Refuse a code or name that another customer already uses.
     *
     * Only a value being NEWLY SET OR CHANGED is checked. The table already
     * holds 14 shared codes and 4 shared names; a blanket rule would make those
     * rows uneditable, which helps nobody.
     */
    protected function rejectDuplicate(array $data, ?SalesCustomer $original): bool
    {
        foreach (['customercode' => 'code', 'customername' => 'name'] as $field => $label) {
            if ($original && (string) $original->{$field} === $data[$field]) {
                continue;   // unchanged — not this save's problem
            }

            $taken = SalesCustomer::where($field, $data[$field])
                ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
                ->exists();

            if ($taken) {
                $this->addError($field, 'Another customer already uses that ' . $label . '.');

                return true;
            }
        }

        return false;
    }

    /**
     * Refuse a designation that does not sit under the chosen region.
     *
     * Exempt: a value the territory list has never heard of, on a record that
     * already had it — history, kept so an unrelated edit does not blank it.
     * A designation the list DOES know must match its region: "NORTH 2" under
     * LAGOS is a mistake, not history.
     */
    protected function rejectStrayDesignation(array $data, ?SalesCustomer $original): bool
    {
        $designation = $data['customerdesignation'];

        if (! $designation || SalesTerritory::belongsTo($designation, $data['customerregion'])) {
            return false;
        }

        $kept = SalesTerritory::isHistoric($designation)
            && $original && $original->customerdesignation === $designation;

        if ($kept) {
            return false;
        }

        $this->addError('customerdesignation', 'That designation does not belong to the chosen region.');

        return true;
    }

    /* ================= DELETE ================= */

    /** A customer named on an order stays: deleting would orphan its history. */
    public function deleteGuard($row): ?string
    {
        $n = (int) ($row->orders_count ?? 0);

        return $n > 0
            ? 'Named on ' . number_format($n) . ' sales ' . Str::plural('order', $n) . ' — cannot delete.'
            : null;
    }

    protected function findRow(int $id)
    {
        return SalesCustomer::withCount('orders')->find($id);
    }

    protected function performDelete(int $id): void
    {
        SalesCustomer::whereKey($id)->delete();
    }

    /* ================= Small helpers ================= */

    /** Merge suggestion sources, case-insensitively deduped, alphabetical. */
    protected function merge(array ...$lists): array
    {
        $seen = [];

        foreach (array_merge(...$lists) as $value) {
            $seen[Str::upper($value)] ??= $value;
        }

        ksort($seen);

        return array_values($seen);
    }

    /** Whatever the record already holds goes first, however odd it looks. */
    protected function keepCurrent(array $list, ?string $current): array
    {
        $current = trim((string) $current);

        if ($current !== '' && ! in_array($current, $list, true)) {
            array_unshift($list, $current);
        }

        return $list;
    }
}
