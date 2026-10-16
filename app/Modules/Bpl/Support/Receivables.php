<?php

namespace Modules\Bpl\Support;

/**
 * When a shipment's invoice falls due, and whether it is late.
 *
 * ## The rule, ported from the legacy
 *
 * `Bil\Bpl\InvoiceTrait::paymentStatus()` decided this, and three separate
 * legacy screens called it. Written out:
 *
 *   - The clock starts when the goods are DOCUMENTED, not when they are
 *     packed. For a vessel shipment that is the bill of lading's issue date;
 *     for an inland truck, which never gets one, it is the packing list's own
 *     date.
 *   - The credit period is `bpl_payment_terms`.`days` **plus one**. The extra
 *     day is the legacy's, kept so gds and the old screens never disagree by a
 *     day about whether an invoice is late.
 *   - A vessel shipment with NO bill of lading yet has no due date at all —
 *     the clock has not started. Those invoices are real but not yet owed, and
 *     showing them as overdue would be wrong; they are their own state here
 *     ("Awaiting B/L") rather than being hidden.
 *
 * ## What the legacy got wrong, and what is kept
 *
 * The legacy reported a part-paid invoice as "REMAIN x" whatever its age, so a
 * shipment part-paid three years ago read the same as one part-paid
 * yesterday — it could never be chased. Here, paid-in-full closes an invoice
 * and EVERYTHING ELSE is aged, part paid or not. The due date itself is
 * unchanged, so the two apps still agree on when the money was due.
 */
class Receivables
{
    /** Rounding slack, in currency units, before a balance counts as owed. */
    public const TOLERANCE = 1.0;

    /** The states an invoice can be in, in the order they escalate. */
    public const STATES = [
        'paid' => 'Paid',
        'awaiting_bl' => 'Awaiting B/L',
        'not_due' => 'Within terms',
        'due_30' => 'Overdue 1–30 days',
        'due_60' => 'Overdue 31–60 days',
        'due_90' => 'Overdue 61–90 days',
        'due_90_plus' => 'Overdue over 90 days',
    ];

    /** The ageing buckets, in order, as [state, lower bound, upper bound|null]. */
    public const BUCKETS = [
        ['due_30', 1, 30],
        ['due_60', 31, 60],
        ['due_90', 61, 90],
        ['due_90_plus', 91, null],
    ];

    /**
     * SQL for the date the credit period runs from: the bill of lading for a
     * vessel, the packing list for a truck, and nothing at all for a vessel
     * that has not been billed yet.
     *
     * Expects `pl` (packing list), `pf` (proforma) and `bl` (bill of lading)
     * to be in scope, which every Finance report's base query provides.
     */
    public const CLOCK_START = "CASE"
        . " WHEN `pf`.`shipment` = 'Truck' THEN `pl`.`date`"
        . ' WHEN `bl`.`issued_date` IS NOT NULL AND `bl`.`issued_date` <> \'\' THEN `bl`.`issued_date`'
        . ' ELSE NULL END';

    /**
     * SQL for the due date as a real DATE, or NULL while the clock has not
     * started.
     *
     * The stored dates are legacy 'Y/m/d' text, so they go through
     * STR_TO_DATE before any arithmetic — a string cannot have days added to
     * it, and comparing '2021/12/12' to CURDATE() would compare a date to a
     * number.
     */
    public const DUE_DATE = "DATE_ADD(STR_TO_DATE(" . self::CLOCK_START . ", '%Y/%m/%d'),"
        . ' INTERVAL COALESCE(`pt`.`days`, 0) + 1 DAY)';

    /** SQL for how many days past due it is — negative while still in credit. */
    public const DAYS_OVERDUE = 'DATEDIFF(CURDATE(), ' . self::DUE_DATE . ')';

    /**
     * SQL for the state, given an expression for the balance still owed.
     *
     * The balance is passed in rather than assumed because each report builds
     * it from its own aliases — see SalesFigures for the invoice arithmetic.
     */
    public static function state(string $balanceExpr): string
    {
        $overdue = self::DAYS_OVERDUE;

        return 'CASE'
            . " WHEN {$balanceExpr} < " . self::TOLERANCE . " THEN 'paid'"
            . ' WHEN ' . self::DUE_DATE . " IS NULL THEN 'awaiting_bl'"
            . " WHEN {$overdue} <= 0 THEN 'not_due'"
            . " WHEN {$overdue} <= 30 THEN 'due_30'"
            . " WHEN {$overdue} <= 60 THEN 'due_60'"
            . " WHEN {$overdue} <= 90 THEN 'due_90'"
            . " ELSE 'due_90_plus' END";
    }

    /** How a state reads. */
    public static function label(?string $state): string
    {
        return self::STATES[(string) $state] ?? (string) $state;
    }

    /** The badge class a state is drawn with — the colour IS the urgency. */
    public static function tone(?string $state): string
    {
        return match ((string) $state) {
            'paid' => 'badge-success',
            'not_due' => 'badge-muted',
            'awaiting_bl' => 'badge',
            'due_30' => 'badge-warning',
            default => 'badge-danger',
        };
    }

    /** Whether a state means money is late. */
    public static function isOverdue(?string $state): bool
    {
        return str_starts_with((string) $state, 'due_');
    }
}
