<?php

namespace Modules\Core\Concerns;

use Livewire\Attributes\Computed;
use Modules\Core\Support\ShiftService;

/**
 * Drop onto any Livewire page that should only be usable during its shift
 * window. Declare the area with shiftKey(); include the guard modal in the
 * view (@include('core::partials.shift-guard')); and gate write actions with
 * ensureShiftOpen(). Holders of `bypass-shift-window` are never blocked, and an
 * ungated/unconfigured context is always open.
 */
trait EnforcesShift
{
    /** The shift context key for this page, or null to opt out (default). */
    public function shiftKey(): ?string
    {
        return null;
    }

    /**
     * Resolved shift status (+ can_bypass/blocked), or null when not gated.
     * Named shiftStatus (not shift) so it never clashes with a component's own
     * `$shift` property (e.g. a Day/Night selector).
     */
    #[Computed]
    public function shiftStatus(): ?array
    {
        $key = $this->shiftKey();
        if (! $key) {
            return null;
        }

        $status = (new ShiftService())->status($key);
        $status['can_bypass'] = (bool) (auth()->user()?->canDo($key, 'bypass-shift'));
        $status['blocked'] = ! $status['open'] && ! $status['can_bypass'];

        return $status;
    }

    /** May the current user act on this page right now? */
    public function shiftOpen(): bool
    {
        $status = $this->shiftStatus();

        return $status === null || ! $status['blocked'];
    }

    /** Server-side guard for write actions: flash + refuse when closed. */
    protected function ensureShiftOpen(): bool
    {
        if ($this->shiftOpen()) {
            return true;
        }

        session()->flash('err', ($this->shiftStatus()['label'] ?? 'This area') . ' is closed for the current shift.');

        return false;
    }
}
