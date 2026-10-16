<?php

namespace Modules\Bpl\Livewire\Logistics;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Bpl\Models\BplCustomer;
use Modules\Bpl\Models\BplDelivery as DeliveryForm;
use Modules\Bpl\Models\BplDeliveryLine;
use Modules\Bpl\Models\BplWaybill;
use Modules\Bpl\Support\DeliveryNumber;
use Modules\Bpl\Support\RollResolver;

/**
 * BPL → Logistics → Delivery. Rebuild of the legacy `bpl_delivery_form.php`
 * (handler `Bil\Bpl\Delivery::save_delivery_form`): the truck load that takes
 * jumbo rolls off the loading bay, and the waybill it leaves with.
 *
 * WHAT IT WRITES, all in one transaction (the three tables were MyISAM until
 * migration 2026_09_23_100000, so the legacy could not):
 *
 *   1. `bpl_delivery` — the form: customer, truck, driver, loader.
 *   2. `bpl_delivery_barcode` — the waybill number and its status.
 *   3. `bpl_delivery_details` — one row per roll.
 *   4. `bpl_warehouse_exit.status = 'yes'` for every roll, which is what stops
 *      it being loaded onto a second truck.
 *
 * Changes from legacy:
 *  - **The waybill number is counted per day**, so the second delivery of a day
 *    is `-002` and not the table's row id — see Support\DeliveryNumber.
 *  - **Each roll is re-checked inside the transaction.** The legacy read the
 *    status when the barcode was scanned and never again, so two clerks
 *    scanning the same roll both got it.
 *  - **A softroll is refused in words.** The legacy looked it up in the
 *    hardroll tables, found nothing and said "Barcode Does'nt Exit".
 *  - **Nothing is half-written**: a failure rolls the whole load back.
 *
 * A load going to Belimpex Limited is an inter-company move: no truck details,
 * and its waybill is 'completed' at once because nothing weighs it. Everything
 * else is 'pending' until the weighbridge figure is entered.
 */
#[Layout('core::layouts.admin')]
#[Title('BPL Delivery')]
class Delivery extends Component
{
    public const PAGE_KEY = 'bpl.logistics.delivery';

    /** One truck holds about 20 rolls; the cap is a typo guard, not a rule. */
    public const MAX_ROLLS = 60;

    /** 'form' = loading a truck (the default), 'list' = deliveries already made. */
    public string $mode = 'form';

    /* ---------------- The form ---------------- */

    public string $dateIso = '';
    public string $customerName = '';
    public string $vehicleNumber = '';
    public string $loader = '';
    public string $driver = '';
    public string $driverPhone = '';
    public string $truckWeight = '';
    public string $containerNumber = '';

    /** Scanned rolls, as RollResolver candidates. */
    public array $items = [];
    public string $scan = '';
    public string $scanError = '';

    /** The waybill just created, for the print link (and nothing else). */
    public string $lastWaybill = '';

    /* ---------------- The list ---------------- */

    public string $listDateIso = '';
    public string $listSearch = '';

    public function mount(): void
    {
        $this->startNew();
    }

    public function canBackdate(): bool
    {
        return (bool) auth()->user()?->canDo(self::PAGE_KEY, 'backdate');
    }

    public function startNew(): void
    {
        $this->lastWaybill = '';
        $this->dateIso = now()->format('Y-m-d');
        $this->customerName = '';
        $this->vehicleNumber = $this->loader = $this->driver = '';
        $this->driverPhone = $this->truckWeight = $this->containerNumber = '';
        $this->items = [];
        $this->scan = '';
        $this->scanError = '';
        $this->mode = 'form';
        $this->resetErrorBag();
    }

    /* ---------------- Options ---------------- */

    #[Computed]
    public function customers()
    {
        return BplCustomer::query()->orderBy('customername')->pluck('customername')->filter()->values();
    }

    /**
     * What has been typed into these boxes before, as datalists — the legacy
     * offered the same, and it is how "AAA 895 YM" stays spelled one way.
     *
     * @return array{vehicles:array,loaders:array,drivers:array}
     */
    #[Computed]
    public function suggestions(): array
    {
        $distinct = fn (string $column) => DB::connection('bpl')->table('bpl_delivery')
            ->whereNotNull($column)->where($column, '<>', '')
            ->distinct()->orderBy($column)->pluck($column)->all();

        return [
            'vehicles' => $distinct('vechile_number'),
            'loaders' => $distinct('loader'),
            'drivers' => $distinct('driver'),
        ];
    }

    /** A load to BIL carries no truck paperwork, and is not weighed. */
    public function isInternal(): bool
    {
        return trim($this->customerName) === DeliveryForm::INTERNAL_CUSTOMER;
    }

    /* ---------------- Scanning ---------------- */

    public function addScan(): void
    {
        $this->scanError = '';

        $barcode = strtoupper(trim($this->scan));
        $this->scan = '';

        if ($barcode === '') {
            return;
        }

        if (collect($this->items)->contains('barcode', $barcode)) {
            $this->scanError = 'Barcode already on this truck.';

            return;
        }

        if (count($this->items) >= self::MAX_ROLLS) {
            $this->scanError = 'That is ' . self::MAX_ROLLS . ' rolls — save this load before scanning more.';

            return;
        }

        $candidates = RollResolver::awaitingDelivery($barcode);

        if ($candidates === []) {
            $this->scanError = $this->missMessage($barcode);

            return;
        }

        $this->items[] = $candidates[0];
    }

    protected function missMessage(string $barcode): string
    {
        $miss = RollResolver::explainDeliveryMiss($barcode);

        return match ($miss['reason']) {
            'delivered' => 'That roll has already gone out' . ($miss['date'] ? ' on ' . $miss['date'] : '') . '.',
            'in_store' => 'That roll is still in the warehouse — release it at Warehouse Exit first.',
            'not_exited' => 'That roll has not left the factory yet.',
            'softroll' => 'That is a softroll. Softrolls do not go out on a delivery form.',
            default => 'Barcode not found in production.',
        };
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function totalWeight(): float
    {
        return round((float) array_sum(array_column($this->items, 'weight')), 2);
    }

    /** The load grouped the way the waybill prints it: one line per product. */
    public function summary(): array
    {
        $rows = [];

        foreach ($this->items as $item) {
            $key = $item['productname'];
            $rows[$key] ??= ['product' => $key, 'grade' => $item['gradetype'], 'rolls' => 0, 'weight' => 0.0];
            $rows[$key]['rolls']++;
            $rows[$key]['weight'] += (float) $item['weight'];
        }

        return array_values($rows);
    }

    /* ---------------- Save ---------------- */

    public function save(): void
    {
        if (! $this->canBackdate()) {
            $this->dateIso = now()->format('Y-m-d');
        }

        $rules = [
            'customerName' => ['required', 'string', 'max:255'],
            'dateIso' => ['required', 'date', 'before_or_equal:today'],
        ];

        if (! $this->isInternal()) {
            $rules += [
                'vehicleNumber' => ['required', 'string', 'max:255'],
                'driver' => ['required', 'string', 'max:255'],
                'loader' => ['required', 'string', 'max:255'],
                'driverPhone' => ['nullable', 'string', 'max:20'],
                'truckWeight' => ['nullable', 'numeric', 'min:0'],
                'containerNumber' => ['nullable', 'string', 'max:255'],
            ];
        }

        $this->validate($rules, [], [
            'customerName' => 'customer',
            'dateIso' => 'date',
            'vehicleNumber' => 'truck number',
            'driverPhone' => 'driver phone',
            'truckWeight' => 'empty truck weight',
            'containerNumber' => 'container number',
        ]);

        if ($this->items === []) {
            $this->scanError = 'Scan at least one roll before saving.';

            return;
        }

        $barcodes = array_column($this->items, 'barcode');
        $date = str_replace('-', '/', $this->dateIso);
        $internal = $this->isInternal();

        try {
            $waybill = DB::connection('bpl')->transaction(function () use ($barcodes, $date, $internal) {
                // Re-check here, not at scan time: a roll loaded onto another
                // truck since it was scanned must not go out twice.
                $available = DB::connection('bpl')->table('bpl_warehouse_exit')
                    ->whereIn('barcode', $barcodes)->whereNull('deleted_at')->whereNull('status')
                    ->lockForUpdate()->pluck('barcode')->all();

                $gone = array_diff($barcodes, $available);
                if ($gone !== []) {
                    throw new \RuntimeException('Already gone: ' . implode(', ', $gone));
                }

                $number = DeliveryNumber::next($this->dateIso);
                $now = DeliveryNumber::dateKey($this->dateIso);

                $form = DeliveryForm::create([
                    'customer_name' => trim($this->customerName),
                    'date' => $date,
                    'vechile_number' => $internal ? null : trim($this->vehicleNumber),
                    'loader' => $internal ? null : trim($this->loader),
                    'driver' => $internal ? null : trim($this->driver),
                    'driver_phone' => $internal ? null : trim($this->driverPhone),
                    'truck_weight' => $internal ? null : ($this->truckWeight === '' ? null : $this->truckWeight),
                    'container_number' => $internal ? null : trim($this->containerNumber),
                    'created_at' => now()->format('Y-m-d H:i:s'),
                    'updated_at' => now()->format('Y-m-d H:i:s'),
                ]);

                BplWaybill::create([
                    'delivery_barcode' => $number,
                    // Nothing weighs an inter-company load, so it is done; every
                    // other load waits for the weighbridge figure.
                    'status' => $internal ? BplWaybill::COMPLETED : BplWaybill::PENDING,
                    'deleted_at' => '',
                    'created_at' => str_replace('-', '/', $this->dateIso),
                    'updated_at' => $now,
                    'date' => $now,
                    'full_weight' => 0,
                ]);

                foreach ($this->items as $item) {
                    BplDeliveryLine::create([
                        'bpl_delivery_id' => (string) $form->id,
                        'barcode' => $item['barcode'],
                        'product_id' => (string) ($item['product_id'] ?? ''),
                        'delivery_barcode' => $number,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::connection('bpl')->table('bpl_warehouse_exit')
                    ->whereIn('barcode', $barcodes)->whereNull('deleted_at')
                    ->update(['status' => 'yes', 'updated_at' => now()]);

                return $number;
            });
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'Already gone: ')) {
                $this->scanError = 'Nothing was saved — ' . substr($e->getMessage(), 14)
                    . ' has gone out on another truck since it was scanned. Remove it and save again.';

                return;
            }

            report($e);
            $this->scanError = 'Nothing was saved — ' . $e->getMessage();

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->scanError = 'Nothing was saved — the delivery could not be recorded. Please try again.';

            return;
        }

        $count = count($this->items);
        $this->startNew();
        $this->lastWaybill = $waybill;

        session()->flash('ok', 'Waybill ' . $waybill . ' created — ' . $count . ' roll' . ($count === 1 ? '' : 's') . '.');
    }

    /* ---------------- Deliveries already made ---------------- */

    public function showList(): void
    {
        $this->mode = 'list';
    }

    public function showForm(): void
    {
        $this->mode = 'form';
    }

    #[Computed]
    public function deliveries()
    {
        $term = trim($this->listSearch);

        return DB::connection('bpl')->table('bpl_delivery_barcode as w')
            ->leftJoin('bpl_delivery_details as d', 'd.delivery_barcode', '=', 'w.delivery_barcode')
            ->leftJoin('bpl_delivery as f', 'f.id', '=', 'd.bpl_delivery_id')
            ->leftJoin('bpl_production as p', 'p.barcode', '=', 'd.barcode')
            ->when($this->listDateIso !== '', fn ($q) => $q->where('w.date', DeliveryNumber::dateKey($this->listDateIso)))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('w.delivery_barcode', 'like', '%' . $term . '%')
                ->orWhere('f.customer_name', 'like', '%' . $term . '%')
                ->orWhere('f.vechile_number', 'like', '%' . $term . '%')))
            ->groupBy('w.id', 'w.delivery_barcode', 'w.status', 'w.date', 'w.full_weight',
                'f.customer_name', 'f.vechile_number', 'f.driver', 'f.truck_weight')
            ->orderByDesc('w.id')
            ->limit(200)
            ->selectRaw('w.id, w.delivery_barcode, w.status, w.date, w.full_weight,'
                . ' f.customer_name, f.vechile_number, f.driver, f.truck_weight,'
                . ' COUNT(d.id) as roll_count, ROUND(COALESCE(SUM(p.weight), 0), 2) as total_weight')
            ->get();
    }

    public function render()
    {
        return view('bpl::livewire.logistics.delivery');
    }
}
