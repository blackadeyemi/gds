<?php

namespace Modules\Core\Livewire\Settings;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Support\Activity\Retention;
use Modules\Core\Support\Settings;

/**
 * Settings → Activity Log. How many days the activity log is kept before
 * `gds:prune-activity` (nightly) deletes it.
 *
 * ADMIN ONLY, and not delegable: the route is behind the `admin` middleware and
 * the page is deliberately absent from config/pages.php, so the Role matrix has
 * nothing to grant. The log is the audit trail; who may shorten it is not a
 * per-role decision. Every action here re-checks, since a Livewire update does
 * not pass back through the route middleware.
 *
 * The change itself is recorded in the activity log (app_settings is not an
 * ignored table) and, being a Settings change, reaches the other Admins' bells.
 */
#[Layout('core::layouts.admin')]
#[Title('Activity Log Settings')]
class ActivitySettings extends Component
{
    public int|string $days = '';

    public function mount(): void
    {
        $this->guard();
        $this->days = Retention::days();
    }

    /**
     * What saving `$this->days` would delete on the next nightly prune —
     * shown before saving, because a shorter period cannot be undone.
     */
    #[Computed]
    public function impact(): ?array
    {
        $days = filter_var($this->days, FILTER_VALIDATE_INT);
        if ($days === false || $days < Retention::min() || $days > Retention::max()) {
            return null;
        }

        return [
            'days' => $days,
            'cutoff' => Retention::cutoff($days),
            'due' => Retention::dueCount($days),
            'shorter' => $days < Retention::days(),
        ];
    }

    #[Computed]
    public function meta(): ?object
    {
        return Settings::meta(Retention::KEY);
    }

    public function updatedDays(): void
    {
        unset($this->impact);
    }

    public function save(): void
    {
        $this->guard();

        $this->validate([
            'days' => ['required', 'integer', 'min:' . Retention::min(), 'max:' . Retention::max()],
        ], [], ['days' => 'retention period']);

        $days = (int) $this->days;
        if ($days === Retention::days() && Settings::isOverridden(Retention::KEY)) {
            session()->flash('ok', 'No change — the log is already kept for ' . $days . ' days.');

            return;
        }

        Settings::set(Retention::KEY, $days);
        $this->days = Retention::days();
        unset($this->impact, $this->meta);

        session()->flash('ok', 'The activity log is now kept for ' . number_format($days) . ' days.'
            . ' The nightly clean-up applies it from tonight.');
    }

    /** Drop the override: back to ACTIVITY_RETENTION_DAYS / the config default. */
    public function useDefault(): void
    {
        $this->guard();

        Settings::forget(Retention::KEY);
        $this->days = Retention::days();
        unset($this->impact, $this->meta);

        session()->flash('ok', 'Back to the default: ' . number_format(Retention::defaultDays()) . ' days.');
    }

    private function guard(): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403);
    }

    public function render()
    {
        return view('core::livewire.settings.activity-settings', [
            'overridden' => Settings::isOverridden(Retention::KEY),
            'defaultDays' => Retention::defaultDays(),
            'min' => Retention::min(),
            'max' => Retention::max(),
        ]);
    }
}
