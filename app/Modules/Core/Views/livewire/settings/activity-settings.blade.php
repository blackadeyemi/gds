<div>
    <div class="page-head">
        <h1>Activity Log</h1>
        <p>How long the <a href="{{ route('activity') }}">activity log</a> is kept. Entries older than this are deleted by the nightly clean-up. Only Admins can open this page, and it cannot be given to a role.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif

    <form wire:submit="save">
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Retention</h2>
                    <div class="text-sm text-muted">Between {{ number_format($min) }} and {{ number_format($max) }} days.</div>
                </div>
            </div>

            <div class="card-pad">
                <div style="display:grid;grid-template-columns:minmax(180px,220px) 1fr;gap:1rem;align-items:start;">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" for="retention-days">Keep entries for (days)</label>
                        <input id="retention-days" type="number" class="form-control" min="{{ $min }}" max="{{ $max }}" step="1"
                               wire:model.live.debounce.400ms="days">
                        @error('days') <div class="form-error">{{ $message }}</div> @enderror
                    </div>

                    <div class="text-sm text-muted">
                        @if ($overridden)
                            <p style="margin:0 0 .5rem;">
                                Set here, overriding the default of <strong>{{ number_format($defaultDays) }} days</strong>
                                (<code>ACTIVITY_RETENTION_DAYS</code>).
                                @if ($this->meta?->updated_by_name)
                                    Last changed by <strong>{{ $this->meta->updated_by_name }}</strong>
                                    on {{ \Illuminate\Support\Carbon::parse($this->meta->updated_at)->format(\Modules\Core\Support\Prefs::dateFormat() . ' H:i') }}.
                                @endif
                            </p>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="useDefault"
                                    wire:confirm="Go back to the default of {{ number_format($defaultDays) }} days?">
                                Use default ({{ number_format($defaultDays) }} days)
                            </button>
                        @else
                            <p style="margin:0;">
                                Using the default, <strong>{{ number_format($defaultDays) }} days</strong>
                                (<code>ACTIVITY_RETENTION_DAYS</code>). Saving a value here overrides it.
                            </p>
                        @endif
                    </div>
                </div>

                {{-- A shorter period deletes history for good, so the cost is shown before it is paid. --}}
                @if ($impact = $this->impact)
                    <div class="card" style="margin-top:0.9rem;padding:0.8rem 1.1rem;border-color:{{ $impact['due'] > 0 ? 'var(--danger)' : 'var(--line)' }};">
                        <div style="font-weight:600;">
                            @if ($impact['due'] > 0)
                                Tonight's clean-up would delete {{ number_format($impact['due']) }} {{ \Illuminate\Support\Str::plural('entry', $impact['due']) }}
                            @else
                                Nothing is old enough to be deleted at this setting
                            @endif
                        </div>
                        <div class="text-sm" style="margin-top:0.3rem;">
                            Everything before <strong>{{ $impact['cutoff']->format(\Modules\Core\Support\Prefs::dateFormat()) }}</strong> goes.
                            @if ($impact['due'] > 0)
                                <strong>Deleted entries cannot be recovered.</strong> This change is recorded against your name, and the other Admins are notified.
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</div>
