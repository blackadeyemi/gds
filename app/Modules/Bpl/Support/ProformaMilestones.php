<?php

namespace Modules\Bpl\Support;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Action;

/**
 * Where a proforma has got to: approved by the customer, produced, loaded.
 *
 * The legacy recorded these by POSTing to a small HTTP service, which wrote a
 * row into `core.actions` keyed `pfi_customer{order}`, `pfi_production{order}`
 * or `pfi_loading{order}` — and the proforma screens then LEFT JOINed the
 * table to find out whether a step had happened. gds writes the same rows
 * directly, so both agree; it does NOT send the legacy's notification emails,
 * which went to a fixed list with the PDF attached.
 *
 * Nothing about the row says which order it belongs to except the string, so
 * the keys live here and nowhere else.
 */
class ProformaMilestones
{
    public const APPROVED = 'approved';
    public const PRODUCED = 'produced';
    public const LOADED = 'loaded';

    /** stage => [request prefix, the `type` the legacy stored, label] */
    private const STAGES = [
        self::APPROVED => ['pfi_customer', 'approve', 'Customer approved'],
        self::PRODUCED => ['pfi_production', '', 'Produced'],
        self::LOADED => ['pfi_loading', '', 'Loaded'],
    ];

    public static function stages(): array
    {
        return array_map(fn ($s) => $s[2], self::STAGES);
    }

    public static function isStage(string $stage): bool
    {
        return isset(self::STAGES[$stage]);
    }

    public static function label(string $stage): string
    {
        return self::STAGES[$stage][2] ?? $stage;
    }

    public static function requestFor(string $stage, int $orderId): string
    {
        return self::STAGES[$stage][0] . $orderId;
    }

    /**
     * What has happened to each of these orders.
     *
     * @return array<int, array<string, array{id:int,user:string,date:string}>>
     *         order id => stage => the action
     */
    public static function forOrders(array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds)));

        if ($orderIds === []) {
            return [];
        }

        $out = [];

        foreach (self::STAGES as $stage => [$prefix]) {
            $requests = array_map(fn ($id) => $prefix . $id, $orderIds);

            foreach (Action::whereIn('request', $requests)->get(['id', 'user', 'request', 'date']) as $row) {
                $orderId = (int) substr($row->request, strlen($prefix));

                // Keep the FIRST one: the legacy never removed a duplicate, so
                // an order can carry two rows for the same step.
                $out[$orderId][$stage] ??= [
                    'id' => (int) $row->id,
                    'user' => (string) $row->user,
                    'date' => $row->date?->format('d/m/Y') ?? '',
                ];
            }
        }

        return $out;
    }

    /** The stages one order has reached. */
    public static function forOrder(int $orderId): array
    {
        return self::forOrders([$orderId])[$orderId] ?? [];
    }

    /** Record a stage, unless it is already recorded. */
    public static function mark(string $stage, int $orderId, string $user): bool
    {
        if (! self::isStage($stage)) {
            return false;
        }

        [$prefix, $type] = self::STAGES[$stage];
        $request = $prefix . $orderId;

        if (Action::where('request', $request)->exists()) {
            return false;
        }

        Action::create([
            'user' => $user,
            'request' => $request,
            'type' => $type,
            'date' => now(),
        ]);

        return true;
    }

    /**
     * Take a stage back.
     *
     * The legacy offered this for the customer approval alone, and only while
     * the NXP number was still empty — an approval is what lets the export
     * form be raised, so undoing it afterwards would contradict a document
     * that already exists. gds keeps that rule and applies it to the
     * production and loading steps too, which the legacy could not undo at all.
     */
    public static function unmark(string $stage, int $orderId): bool
    {
        if (! self::isStage($stage)) {
            return false;
        }

        [$prefix] = self::STAGES[$stage];

        return Action::where('request', $prefix . $orderId)->delete() > 0;
    }

    /** Has the proforma got an NXP number? Approvals cannot be undone once it has. */
    public static function hasNxp(int $orderId): bool
    {
        $nxp = DB::connection('bpl')->table('bpl_proforma')->where('order_id', $orderId)->value('nxp');

        return trim((string) $nxp) !== '';
    }
}
