<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock transfers — the rebuild of the legacy fg_inter_transfer /
 * fg_inter_received pair.
 *
 * THE ONE IDEA THAT CHANGES: a transfer goes from a WAREHOUSE to a WAREHOUSE.
 *
 * The legacy screen asked for a "company from" and a "company to", and its
 * destination list conflated the two things it was really offering: of its two
 * entries, "BIL ABUJA" is a warehouse of Belimpex and "BHN" is a different
 * company altogether. 812 of the 814 transfers went to BIL ABUJA — i.e. the
 * overwhelmingly common case was warehouse-to-warehouse INSIDE one company,
 * recorded through a field called "company to".
 *
 * Since `warehouses.company_id` already says which company a warehouse belongs
 * to, there is nothing to choose: pick the destination warehouse and the KIND of
 * transfer follows from it. Same company as the source, and it is internal;
 * different, and it is inter-company. `kind` is stored — derived once at write
 * time, never asked for — so reports can group on it without recomputing.
 *
 * STOCK MOVES IN TWO STEPS, because that is what physically happens. Dispatch
 * takes the bundles off the source warehouse (they are on a truck); receipt puts
 * them onto the destination. What has left but not arrived is in transit, and is
 * visible as exactly that rather than being lost between two figures. Both steps
 * go through FinishedGoodsStock::adjust(), so a transfer is just another
 * movement in the ledger the stock page already reconciles.
 *
 * HISTORIC ROWS carry null warehouses. The legacy table records only a company
 * on each side, so which warehouse a 2023 transfer left from is not knowable —
 * and inventing one would put fiction in the ledger. They are imported for the
 * report, flagged `is_historic`, and never touch stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        $core = Schema::connection('core');

        $core->create('stock_transfers', function (Blueprint $table) {
            $table->id();

            // Which product master the lines refer to, exactly as `warehouses`
            // uses it — bil.products for finished goods, a different master for
            // raw materials. The legacy app has a separate transfer table per
            // module; this is one table that knows which it is.
            $table->string('module', 32)->default('finished-goods')->index();

            $table->string('transfer_number', 64)->nullable()->index();
            // The legacy `barcode`: one code per truckload, shared by its lines.
            $table->string('reference', 64)->nullable()->index();

            // Nullable only for historic rows, where the legacy data records a
            // company but not a warehouse.
            $table->unsignedBigInteger('from_warehouse_id')->nullable()->index();
            $table->unsignedBigInteger('from_company_id')->nullable()->index();
            $table->unsignedBigInteger('to_warehouse_id')->nullable()->index();
            $table->unsignedBigInteger('to_company_id')->nullable()->index();

            // DERIVED from the two companies, never chosen. See the class note.
            $table->string('kind', 16)->default('internal')->index();

            $table->string('truck_number', 64)->nullable();
            $table->date('date_of_transfer')->index();

            // dispatched -> received, or cancelled. The timestamps below are the
            // real state; this is the column reports and lists filter on.
            $table->string('status', 16)->default('dispatched')->index();

            $table->unsignedBigInteger('dispatched_by')->nullable();
            $table->string('dispatched_by_name')->nullable();
            $table->timestamp('dispatched_at')->nullable();

            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('received_by_name')->nullable();
            $table->timestamp('received_at')->nullable();

            // The legacy received table carried an `approved` status on 33 of
            // its 96 rows, so receipt and approval are distinct steps here too.
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->text('note')->nullable();

            // Imported from fg_inter_transfer: shown in reports, never in stock.
            $table->boolean('is_historic')->default(false)->index();

            $table->timestamps();

            $table->index(['module', 'date_of_transfer'], 'st_module_date_idx');
            $table->index(['status', 'date_of_transfer'], 'st_status_date_idx');
        });

        $core->create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();

            // Cross-database (bil.products), so a plain indexed column.
            $table->unsignedInteger('productid')->index();
            // Denormalised: the product master is on another connection and
            // cannot be joined, and a historic line must still read correctly
            // if a product is later renamed.
            $table->string('product_code')->nullable();
            $table->string('product_name')->nullable();

            $table->unsignedInteger('bundles');
            // Null until received. Allowed to differ from `bundles` — a short
            // delivery is a fact worth recording, not an error to suppress.
            $table->unsignedInteger('received_bundles')->nullable();

            $table->timestamps();

            $table->index(['transfer_id', 'productid'], 'stl_transfer_product_idx');
        });

        $this->importLegacy();
    }

    /**
     * Bring the 814 legacy transfer rows across as history.
     *
     * Grouped into truckloads by the columns the legacy screen filled in
     * together — transfer number, barcode, date and truck — because one legacy
     * row is a PRODUCT LINE, not a transfer.
     */
    private function importLegacy(): void
    {
        $core = DB::connection('core');

        if (! Schema::connection('core')->hasTable('fg_inter_transfer')) {
            return;
        }

        // The legacy destination list, resolved to what it actually meant.
        // "BIL ABUJA" is Belimpex's Abuja Depot; "BHN" is the Belhin company,
        // whose warehouse is unknown and therefore left null.
        $abuja = $core->table('warehouses')->where('code', 'ABJ')->value('id');
        $belhin = $core->table('companies')->where('code', 'BHN')->value('id');
        $belimpex = $core->table('companies')->where('code', 'BIL')->value('id');

        $destinations = [
            '1' => ['warehouse' => $abuja, 'company' => $belimpex],
            '2' => ['warehouse' => null, 'company' => $belhin],
        ];

        $rows = $core->table('fg_inter_transfer')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            return;
        }

        $groups = $rows->groupBy(fn ($r) => implode('|', [
            $r->transfer_number ?? '', $r->barcode ?? '',
            $r->dateoftransfer ?? '', $r->trucknumber ?? '', $r->to_company ?? '',
        ]));

        $now = now();

        foreach ($groups as $lines) {
            $first = $lines->first();
            $dest = $destinations[(string) $first->to_company] ?? ['warehouse' => null, 'company' => null];

            $transferId = $core->table('stock_transfers')->insertGetId([
                'module' => 'finished-goods',
                'transfer_number' => $first->transfer_number,
                'reference' => $first->barcode,
                // Not knowable from the legacy data — see the class note.
                'from_warehouse_id' => null,
                'from_company_id' => $belimpex,
                'to_warehouse_id' => $dest['warehouse'],
                'to_company_id' => $dest['company'],
                'kind' => ($dest['company'] && $dest['company'] !== $belimpex) ? 'inter_company' : 'internal',
                'truck_number' => $first->trucknumber,
                'date_of_transfer' => $first->dateoftransfer,
                // Imported after the fact; treated as complete, since the goods
                // demonstrably went years ago.
                'status' => 'received',
                'dispatched_by_name' => $first->username,
                'dispatched_at' => $first->timestamp,
                'is_historic' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($lines as $line) {
                $bundles = (int) $line->bundle;

                $core->table('stock_transfer_lines')->insert([
                    'transfer_id' => $transferId,
                    'productid' => (int) $line->productid,
                    'product_code' => $line->productcode,
                    'product_name' => $line->productname,
                    'bundles' => max(0, $bundles),
                    'received_bundles' => max(0, $bundles),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $core = Schema::connection('core');
        $core->dropIfExists('stock_transfer_lines');
        $core->dropIfExists('stock_transfers');
    }
};
