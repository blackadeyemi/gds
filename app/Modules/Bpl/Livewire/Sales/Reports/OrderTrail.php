<?php

namespace Modules\Bpl\Livewire\Sales\Reports;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Bpl\Support\SalesOrderTrail;

/**
 * BPL → Sales → Reports → Order Trail. Everything that ever happened to ONE
 * export order, from the order itself through to the money coming back.
 *
 * The other sales reports answer "what happened in this period"; this answers
 * "what happened to this order" — the question a customer asks on the phone,
 * and one that previously meant opening Orders, then Proforma Invoices, then
 * Packing Lists, then Bills of Lading, then Invoice Payments, carrying the
 * numbers between them.
 *
 * It is NOT built on the report framework, for the same reasons the BIL trail
 * is not: no date range (an order is an order), a subject that is chosen
 * rather than filtered, and a timeline rather than a list of rows. It borrows
 * the chrome — the card, the export menu, the print route — and nothing else.
 *
 * ## Nine ways to find the order
 *
 * Unlike a BIL order, a BPL export order is called something different by
 * everyone who touches it: the bank quotes the NXP, the shipping line quotes
 * the bill of lading, the customer quotes their own purchase-order reference,
 * the yard quotes a container. All of them are searched at once and each hit
 * says which one matched — see SalesOrderTrail::suggest().
 *
 * ONE PAGE PER ORDER, not per line. A BPL order carries a handful of product
 * lines that all travel on the same proforma and the same containers, so the
 * order is the honest unit; the lines are a table on the page rather than a
 * pager.
 */
#[Layout('core::layouts.admin')]
#[Title('BPL Order Trail')]
class OrderTrail extends Component
{
    /** The order being traced, by its internal id. In the URL, so it can be linked to. */
    #[Url(as: 'order')]
    public string $orderid = '';

    /** What is typed in the search box — any of the nine identifiers. */
    public string $search = '';

    public const PAGE_KEY = 'bpl.sales.reports.order_trail';

    /** How many matches the list shows before it says there are more. */
    public const SUGGEST_LIMIT = 15;

    public function title(): string
    {
        return 'Order Trail';
    }

    public function subtitle(): string
    {
        return 'Every step of one export order — quoted, approved, produced, shipped, '
            . 'billed and paid.';
    }

    public function date(?string $stored): string
    {
        return SalesOrderTrail::humanDate($stored);
    }

    /* ---------------- Permissions ---------------- */

    public function mayDo(string $ability): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, $ability);
    }

    public function canExport(): bool
    {
        return $this->mayDo('export');
    }

    /* ---------------- Finding an order ---------------- */

    /**
     * One more than we show, so the page can say the list was cut short.
     * Without that, fifteen hits and five hundred look identical.
     */
    #[Computed]
    public function matches(): array
    {
        $rows = $this->search === ''
            ? []
            : SalesOrderTrail::suggest($this->search, self::SUGGEST_LIMIT + 1);

        return [
            'rows' => array_slice($rows, 0, self::SUGGEST_LIMIT),
            'more' => count($rows) > self::SUGGEST_LIMIT,
        ];
    }

    #[Computed]
    public function suggestions(): array
    {
        return $this->matches['rows'];
    }

    public function hasMoreMatches(): bool
    {
        return $this->matches['more'];
    }

    /** The ways a search can match, for the hint under the box. */
    public function searchWays(): array
    {
        return SalesOrderTrail::WAYS;
    }

    #[Computed]
    public function order(): ?object
    {
        return $this->orderid === '' ? null : SalesOrderTrail::find($this->orderid);
    }

    public function openOrder(string $orderid): void
    {
        $this->orderid = $orderid;
        $this->search = '';
        $this->forget();
    }

    /**
     * Pressing enter opens the only match, so the keyboard alone gets you
     * there — the suggestion list is for browsing, not a toll gate.
     */
    public function submitSearch(): void
    {
        $hits = $this->suggestions;

        if (count($hits) === 1) {
            $this->openOrder((string) $hits[0]->id);
        }
    }

    public function clearOrder(): void
    {
        $this->orderid = '';
        $this->search = '';
        $this->forget();
    }

    private function forget(): void
    {
        unset($this->order, $this->lines, $this->trail, $this->progress,
            $this->totals, $this->matches, $this->suggestions);
    }

    /* ---------------- What is on the page ---------------- */

    #[Computed]
    public function lines(): array
    {
        return $this->order ? SalesOrderTrail::lines((int) $this->order->id) : [];
    }

    #[Computed]
    public function trail(): array
    {
        return $this->order ? SalesOrderTrail::trail($this->order) : [];
    }

    #[Computed]
    public function progress(): array
    {
        return $this->order ? SalesOrderTrail::progress($this->order) : [];
    }

    #[Computed]
    public function totals(): array
    {
        return $this->order ? SalesOrderTrail::totals($this->order) : [];
    }

    /** An amount in the order's own currency. */
    public function money(?float $value): string
    {
        if ($value === null) {
            return '';
        }

        return \Modules\Bpl\Support\ProformaInvoice::symbol((string) ($this->order->symbol ?? ''))
            . number_format($value, 2);
    }

    /** Kilograms as tonnes, the unit a BPL order is discussed in. */
    public function tonnes(?float $kg): string
    {
        return $kg === null ? '' : number_format($kg / 1000, 3);
    }

    /* ---------------- Print and export ---------------- */

    protected function exportParams(): array
    {
        return ['order' => $this->orderid];
    }

    public function printUrl(): string
    {
        return route('bpl.sales.reports.order-trail.print', $this->exportParams());
    }

    public function downloadUrl(string $format): string
    {
        return route('bpl.sales.reports.order-trail.download',
            ['format' => $format] + $this->exportParams());
    }

    public function render()
    {
        return view('bpl::livewire.sales.reports.order-trail');
    }
}
