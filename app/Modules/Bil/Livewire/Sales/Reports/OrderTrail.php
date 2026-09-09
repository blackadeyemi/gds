<?php

namespace Modules\Bil\Livewire\Sales\Reports;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Bil\Support\SalesOrderTrail;

/**
 * BIL → Sales → Reports → Order Trail. Everything that ever happened to ONE
 * order, from the order itself through to the waybill.
 *
 * The other six sales reports answer "what happened in this period"; this one
 * answers "what happened to this order", which is the question a customer asks
 * on the phone. Before it you had to open Orders, then Loading, then Delivery,
 * then Waybill, and carry the barcodes between them in your head.
 *
 * It is NOT built on RawMaterialReport, deliberately. That framework lists rows
 * matching a date range and pages through them; this has no date range (an
 * order is an order), its subject is chosen rather than filtered, and its unit
 * of paging is a whole timeline rather than a row. Forcing it into that shape
 * would have meant fighting the base on all three. It borrows the chrome — the
 * card, the export menu, the print route — and nothing else.
 *
 * ONE ORDER LINE PER PAGE. The same product is routinely ordered twice on one
 * order, once sold and once free of charge, so the line is the honest unit;
 * each page says which it is. See SalesOrderTrail for the rest.
 */
#[Layout('core::layouts.admin')]
#[Title('Order Trail')]
class OrderTrail extends Component
{
    /** The order being traced. In the URL, so a trail can be linked to. */
    #[Url(as: 'order')]
    public string $orderid = '';

    /** Optional: narrow the pages to one product. */
    #[Url(as: 'product')]
    public string $productid = '';

    /** Which line is on screen, 1-based. */
    #[Url(as: 'line')]
    public int $lineNo = 1;

    /** What is typed in the search box — an order number or a customer name. */
    public string $search = '';

    public const PAGE_KEY = 'bil.sales.reports.order_trail';

    public function title(): string
    {
        return 'Order Trail';
    }

    public function subtitle(): string
    {
        return 'Every transaction on one order — ordered, loaded, delivered, waybilled, returned.';
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

    #[Computed]
    public function suggestions(): array
    {
        return $this->search === '' ? [] : SalesOrderTrail::suggest($this->search);
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
        $this->productid = '';
        $this->lineNo = 1;
        unset($this->order, $this->lines, $this->products, $this->suggestions);
    }

    /**
     * Typing an order number in full and pressing enter opens it, so the
     * keyboard alone gets you there — the suggestion list is for browsing, not
     * a toll gate.
     */
    public function submitSearch(): void
    {
        $term = trim($this->search);

        if ($term !== '' && SalesOrderTrail::find($term)) {
            $this->openOrder($term);

            return;
        }

        // Exactly one match is unambiguous; open it rather than making them
        // click the only row on offer.
        $hits = $this->suggestions;

        if (count($hits) === 1) {
            $this->openOrder((string) $hits[0]->orderid);
        }
    }

    public function clearOrder(): void
    {
        $this->orderid = '';
        $this->productid = '';
        $this->lineNo = 1;
        $this->search = '';
        unset($this->order, $this->lines, $this->products, $this->suggestions);
    }

    /* ---------------- The lines, one per page ---------------- */

    #[Computed]
    public function lines(): array
    {
        return $this->order
            ? SalesOrderTrail::lines($this->orderid, $this->productid === '' ? null : (int) $this->productid)
            : [];
    }

    #[Computed]
    public function products(): array
    {
        return $this->order ? SalesOrderTrail::products($this->orderid) : [];
    }

    public function pageCount(): int
    {
        return count($this->lines);
    }

    /** The line on screen, clamped — a product filter can shrink the set. */
    #[Computed]
    public function line(): ?object
    {
        $lines = $this->lines;

        if ($lines === []) {
            return null;
        }

        $index = min(max(1, $this->lineNo), count($lines)) - 1;

        return $lines[$index];
    }

    #[Computed]
    public function trail(): array
    {
        $line = $this->line;

        return $line && $this->order ? SalesOrderTrail::trail($this->order, $line) : [];
    }

    public function updatedProductid(): void
    {
        $this->lineNo = 1;
        unset($this->lines, $this->line, $this->trail);
    }

    public function goTo(int $line): void
    {
        $this->lineNo = min(max(1, $line), max(1, $this->pageCount()));
        unset($this->line, $this->trail);
    }

    public function nextLine(): void
    {
        $this->goTo($this->lineNo + 1);
    }

    public function previousLine(): void
    {
        $this->goTo($this->lineNo - 1);
    }

    /* ---------------- What the whole order totals ---------------- */

    public function totals(): array
    {
        $lines = $this->lines;

        return [
            'lines' => count($lines),
            'ordered' => array_sum(array_map(fn ($l) => (int) $l->quantityordered, $lines)),
            'loaded' => array_sum(array_map(fn ($l) => (int) $l->loaded, $lines)),
            'delivered' => array_sum(array_map(fn ($l) => (int) $l->delivered, $lines)),
            'returned' => array_sum(array_map(fn ($l) => (int) $l->returned, $lines)),
        ];
    }

    /* ---------------- Print and export ---------------- */

    /**
     * Both cover the WHOLE order, not the page on screen. A printed trail is
     * handed to someone to answer a question about the order, and one product
     * out of nine would not answer it. The product filter still applies — that
     * is a deliberate narrowing, unlike which page you happen to be on.
     */
    protected function exportParams(): array
    {
        return array_filter([
            'order' => $this->orderid,
            'product' => $this->productid,
        ], fn ($v) => $v !== '');
    }

    public function printUrl(): string
    {
        return route('bil.sales.reports.order-trail.print', $this->exportParams());
    }

    public function downloadUrl(string $format): string
    {
        return route('bil.sales.reports.order-trail.download',
            ['format' => $format] + $this->exportParams());
    }

    public function render()
    {
        return view('bil::livewire.sales.reports.order-trail');
    }
}
