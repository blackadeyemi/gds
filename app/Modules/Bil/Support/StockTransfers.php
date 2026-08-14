<?php

namespace Modules\Bil\Support;

use Illuminate\Support\Facades\DB;
use Modules\Bil\Models\StockTransfer;
use Modules\Bil\Models\StockTransferLine;
use Modules\Core\Models\Warehouse;

/**
 * Moving stock between warehouses.
 *
 * WHY THERE IS NO "TRANSFER TYPE" TO PICK. A warehouse belongs to a company, so
 * choosing the destination warehouse already says whether the goods are staying
 * inside the company or leaving it. `kind` is computed from that and stored for
 * reporting; the operator never sees the question. The legacy screen asked for a
 * "company to" and then listed a warehouse and a company side by side in it —
 * which is how 812 warehouse-to-warehouse moves came to be recorded as if they
 * were inter-company ones.
 *
 * STOCK MOVES TWICE, because the goods do. Dispatch takes bundles off the source
 * — they are on a truck and no longer countable there. Receipt puts them onto
 * the destination. The difference is IN TRANSIT and is reportable as such,
 * rather than vanishing between two warehouses' figures.
 *
 * Both legs go through FinishedGoodsStock::adjust(), so a transfer is an
 * ordinary movement in the ledger `bil:reconcile-fg-stock` already proves.
 */
class StockTransfers
{
    /** The only module built so far; the schema is ready for raw materials. */
    public const MODULE = 'finished-goods';

    /* ---------------- Where stock can go ---------------- */

    /**
     * Destinations for a transfer out of `$fromWarehouseId`, grouped by company.
     *
     * Every active warehouse in the same module except the source itself — a
     * warehouse cannot transfer to itself. Grouping by company is what makes the
     * internal / inter-company split legible without asking for it: the
     * operator's own company sits at the top, everything under another company
     * heading is by definition an inter-company move.
     *
     * @return array<string, array<int, Warehouse>>  company name => warehouses
     */
    public static function destinations(?int $fromWarehouseId, string $module = self::MODULE): array
    {
        $source = $fromWarehouseId ? Warehouse::find($fromWarehouseId) : null;

        $warehouses = Warehouse::with('company')
            ->where('module', $module)
            ->where('is_active', true)
            ->when($fromWarehouseId, fn ($q) => $q->whereKeyNot($fromWarehouseId))
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        // Own company first, then the rest alphabetically — the common case is
        // an internal move, so it should not be buried.
        return $warehouses
            ->sortBy(fn ($w) => [
                $source && $w->company_id === $source->company_id ? 0 : 1,
                (string) ($w->company?->name ?? ''),
                (int) $w->sort_order,
                (string) $w->name,
            ])
            ->groupBy(fn ($w) => $w->company?->name ?? 'Unassigned')
            ->map(fn ($group) => $group->values())
            ->all();
    }

    /** Warehouses stock can be sent FROM, for this module. */
    public static function sources(string $module = self::MODULE)
    {
        return Warehouse::with('company')
            ->where('module', $module)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Internal or inter-company — decided by the two warehouses, not the user.
     */
    public static function kindFor(?int $fromWarehouseId, ?int $toWarehouseId): string
    {
        $from = $fromWarehouseId ? Warehouse::find($fromWarehouseId) : null;
        $to = $toWarehouseId ? Warehouse::find($toWarehouseId) : null;

        if (! $from || ! $to || $from->company_id === null || $to->company_id === null) {
            return StockTransfer::INTERNAL;
        }

        return $from->company_id === $to->company_id
            ? StockTransfer::INTERNAL
            : StockTransfer::INTER_COMPANY;
    }

    /* ---------------- Dispatch ---------------- */

    /**
     * Send a truckload.
     *
     * `$lines` = [['productid' => int, 'bundles' => int], …].
     *
     * The whole thing is one transaction: a transfer that took stock off the
     * source but recorded no lines would be unrecoverable, because there would
     * be nothing left to say what had gone.
     */
    public static function dispatch(array $header, array $lines): StockTransfer
    {
        $user = auth()->user();
        $from = Warehouse::find($header['from_warehouse_id']);
        $to = Warehouse::find($header['to_warehouse_id']);

        return DB::connection('core')->transaction(function () use ($header, $lines, $user, $from, $to) {
            $transfer = StockTransfer::create([
                'module' => $header['module'] ?? self::MODULE,
                'transfer_number' => $header['transfer_number'] ?? null,
                'reference' => $header['reference'] ?? null,
                'from_warehouse_id' => $from?->id,
                'from_company_id' => $from?->company_id,
                'to_warehouse_id' => $to?->id,
                'to_company_id' => $to?->company_id,
                'kind' => self::kindFor($from?->id, $to?->id),
                'truck_number' => $header['truck_number'] ?? null,
                'date_of_transfer' => $header['date_of_transfer'],
                'status' => StockTransfer::DISPATCHED,
                'dispatched_by' => $user?->userid,
                'dispatched_by_name' => $user?->username ?? $user?->name,
                'dispatched_at' => now(),
                'note' => $header['note'] ?? null,
                'is_historic' => false,
            ]);

            foreach ($lines as $line) {
                $productid = (int) ($line['productid'] ?? 0);
                $bundles = (int) ($line['bundles'] ?? 0);

                if ($productid <= 0 || $bundles <= 0) {
                    continue;
                }

                $product = self::product($productid);

                StockTransferLine::create([
                    'transfer_id' => $transfer->id,
                    'productid' => $productid,
                    'product_code' => $product?->productcode,
                    'product_name' => $product?->productname,
                    'bundles' => $bundles,
                ]);

                // Off the source now: the bundles are on a truck, and counting
                // them in two places until receipt would overstate stock.
                FinishedGoodsStock::adjust(
                    (int) $from->id, $productid, -$bundles,
                    'Transfer ' . ($transfer->transfer_number ?: '#' . $transfer->id)
                        . ' to ' . ($to?->name ?? 'another warehouse')
                );
            }

            return $transfer;
        });
    }

    /* ---------------- Receive ---------------- */

    /**
     * Book a transfer in at the destination.
     *
     * `$received` = [lineId => bundles]. A line omitted is taken as received in
     * full — the common case is that everything arrived, and making the operator
     * retype what the truck already says invites transcription errors. Anything
     * SHORT has to be entered deliberately, which is the direction that matters.
     */
    public static function receive(StockTransfer $transfer, array $received = [], ?string $note = null): void
    {
        if ($transfer->status !== StockTransfer::DISPATCHED) {
            return;
        }

        $user = auth()->user();

        DB::connection('core')->transaction(function () use ($transfer, $received, $note, $user) {
            foreach ($transfer->lines as $line) {
                $qty = array_key_exists($line->id, $received)
                    ? max(0, (int) $received[$line->id])
                    : $line->bundles;

                // Never more than was sent — a receipt is a count of what
                // arrived, not a way to create stock.
                $qty = min($qty, $line->bundles);

                $line->update(['received_bundles' => $qty]);

                if ($transfer->to_warehouse_id) {
                    FinishedGoodsStock::adjust(
                        (int) $transfer->to_warehouse_id, (int) $line->productid, $qty,
                        'Transfer ' . ($transfer->transfer_number ?: '#' . $transfer->id)
                            . ' from ' . ($transfer->fromWarehouse?->name ?? 'another warehouse')
                    );
                }
            }

            $transfer->forceFill([
                'status' => StockTransfer::RECEIVED,
                'received_by' => $user?->userid,
                'received_by_name' => $user?->username ?? $user?->name,
                'received_at' => now(),
                'note' => $note ?: $transfer->note,
            ])->save();
        });
    }

    /** Sign off a received transfer — the legacy `approved` status. */
    public static function approve(StockTransfer $transfer): void
    {
        if (! $transfer->isReceived() || $transfer->isApproved()) {
            return;
        }

        $user = auth()->user();

        $transfer->forceFill([
            'approved_by' => $user?->userid,
            'approved_by_name' => $user?->username ?? $user?->name,
            'approved_at' => now(),
        ])->save();
    }

    /**
     * Cancel a dispatch that never left, putting the bundles back on the source.
     *
     * Only before receipt: once the destination has counted them in, the way
     * back is another transfer, not an undo.
     */
    public static function cancel(StockTransfer $transfer, ?string $reason = null): void
    {
        if ($transfer->status !== StockTransfer::DISPATCHED) {
            return;
        }

        DB::connection('core')->transaction(function () use ($transfer, $reason) {
            foreach ($transfer->lines as $line) {
                FinishedGoodsStock::adjust(
                    (int) $transfer->from_warehouse_id, (int) $line->productid, $line->bundles,
                    'Cancelled transfer ' . ($transfer->transfer_number ?: '#' . $transfer->id)
                );
            }

            $transfer->forceFill([
                'status' => StockTransfer::CANCELLED,
                'note' => $reason ?: $transfer->note,
            ])->save();
        });
    }

    /* ---------------- Reads ---------------- */

    /** Transfers dispatched to a warehouse and not yet received. */
    public static function awaitingReceipt(?int $warehouseId = null)
    {
        return StockTransfer::with(['lines', 'fromWarehouse', 'toWarehouse'])
            ->inTransit()
            ->when($warehouseId, fn ($q) => $q->where('to_warehouse_id', $warehouseId))
            ->orderBy('date_of_transfer')->orderBy('id')
            ->get();
    }

    /**
     * Bundles per product currently on a truck, for the stock picture.
     *
     * Cast to int on the way out: a SUM() comes back from PDO as a string, and
     * a caller comparing it with === would silently never match.
     */
    public static function inTransitByProduct(?int $toWarehouseId = null): array
    {
        return DB::connection('core')->table('stock_transfer_lines as l')
            ->join('stock_transfers as t', 'l.transfer_id', '=', 't.id')
            ->where('t.status', StockTransfer::DISPATCHED)
            ->where('t.is_historic', false)
            ->when($toWarehouseId, fn ($q) => $q->where('t.to_warehouse_id', $toWarehouseId))
            ->groupBy('l.productid')
            ->selectRaw('l.productid, SUM(l.bundles) as bundles')
            ->pluck('bundles', 'productid')
            ->map(fn ($b) => (int) $b)
            ->all();
    }

    /** The next transfer number, continuing the legacy sequence. */
    public static function nextTransferNumber(): string
    {
        $max = (int) DB::connection('core')->table('stock_transfers')
            ->whereRaw('transfer_number REGEXP ?', ['^[0-9]+$'])
            ->max(DB::raw('CAST(transfer_number AS UNSIGNED)'));

        return (string) ($max + 1);
    }

    /** bil.products is on the other connection, so lookups are cached here. */
    public static function product(int $productid)
    {
        static $cache = [];

        return $cache[$productid] ??= DB::connection('bil')->table('products')
            ->where('productid', $productid)
            ->first(['productid', 'productcode', 'productname']);
    }
}
