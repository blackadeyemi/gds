<?php

namespace Modules\Bpl\Support;

use Modules\Bpl\Models\BplWeightAllowance;

/**
 * The one place that answers "how much comes off this reel's scale weight?".
 *
 * Reads bpl_weight_allowances, which BPL → Jumbo Rolls → Weight Allowances
 * maintains. Before this the rule was five hard-coded objects in
 * Bil\Bpl\production::merge().
 *
 * ## Case
 *
 * The legacy comparison was PHP `==`, so it matched the grade type
 * case-sensitively — and the product catalog holds both `PBTS` and `PBTs`,
 * `PBTB` and `PBTb`. A reel whose product happened to carry the lowercase
 * spelling therefore got no allowance at all, silently.
 *
 * The lookup here is case-INSENSITIVE. That cannot change any historical
 * figure: only 3 of the 4,388 catalog products carry a lowercase variant, and
 * none of them has been made for the allowance customer since the rule started
 * on 2026/05/14. What it does remove is a trap where a typo in the product
 * master quietly changes a reel's weight.
 */
class WeightAllowance
{
    /** @var array<string, float>|null request-lifetime cache of the rule set */
    private static ?array $rules = null;

    /** Kilograms to deduct, or 0.0 when no rule covers this reel. */
    public static function for(int $customerId, ?string $gradetype, int $ply): float
    {
        return self::rules()[self::key($customerId, $gradetype, $ply)] ?? 0.0;
    }

    /** Is there a rule for this combination at all? (0.00 is a real answer.) */
    public static function has(int $customerId, ?string $gradetype, int $ply): bool
    {
        return array_key_exists(self::key($customerId, $gradetype, $ply), self::rules());
    }

    /**
     * Drop the cache. Called by the screen after a save so the next reel
     * entered in the same request sees the new rule.
     */
    public static function flush(): void
    {
        self::$rules = null;
    }

    /**
     * The whole rule set in one query. There are five rules today and no
     * plausible growth to thousands, so loading the lot beats a lookup per
     * reel — and a batch of reels entered together then costs one query.
     */
    private static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        return self::$rules = BplWeightAllowance::query()
            ->get(['customer_id', 'gradetype', 'ply', 'allowance'])
            ->mapWithKeys(fn ($r) => [
                self::key($r->customer_id, $r->gradetype, $r->ply) => (float) $r->allowance,
            ])
            ->all();
    }

    private static function key(int $customerId, ?string $gradetype, int $ply): string
    {
        return $customerId . '|' . strtoupper(trim((string) $gradetype)) . '|' . $ply;
    }
}
