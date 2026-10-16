<div>
    @assets
        <script src="{{ asset('js/chart.umd.min.js') }}"></script>
        <script src="{{ asset('js/statistics.js') }}"></script>
    @endassets

    @php
        $since = null;
        try { $since = $user->created_at ? \Carbon\Carbon::parse($user->created_at) : null; } catch (\Throwable) {}
    @endphp

    {{-- Who is signed in --}}
    <section class="card dash-profile">
        <div class="dash-avatar" aria-hidden="true">{{ $initials }}</div>

        <div class="dash-id">
            <p class="dash-greet">Welcome back</p>
            <h1>{{ $displayName }}</h1>
            <p class="dash-handle">{{ '@' . $user->username }}</p>
            <div class="dash-roles">
                @forelse ($user->roles as $role)
                    <span class="dash-role {{ (int) $role->legacy_level === 1 ? 'is-admin' : '' }}"
                          @if ($role->description) title="{{ $role->description }}" @endif>{{ $role->name }}</span>
                @empty
                    <span class="dash-role is-none">No role assigned</span>
                @endforelse
            </div>
        </div>

        <dl class="dash-facts">
            <div>
                <dt>Company</dt>
                <dd>{{ $user->company?->name ?? ($isAdmin ? 'All companies' : '—') }}</dd>
            </div>
            <div>
                <dt>Department</dt>
                <dd>
                    {{ $user->department?->name ?? '—' }}
                    @if ($user->division)
                        <span class="dash-muted">/ {{ $user->division->name }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt>Email</dt>
                <dd>{{ $user->email ?: 'Not on file' }}</dd>
            </div>
            <div>
                <dt>Member since</dt>
                <dd>{{ $since ? $since->format(\Modules\Core\Support\Prefs::dateFormat()) : '—' }}</dd>
            </div>
        </dl>

        <div class="dash-access">
            <div><strong>{{ $counts['screens'] }}</strong><span>{{ Str::plural('screen', $counts['screens']) }}</span></div>
            <div><strong>{{ $counts['statistics'] }}</strong><span>statistics</span></div>
            <div><strong>{{ $counts['reports'] }}</strong><span>{{ Str::plural('report', $counts['reports']) }}</span></div>
        </div>
    </section>

    {{-- Things waiting on someone this role can act on. --}}
    @if ($hasAttention)
        <section class="dash-section">
            <div class="dash-section-head"><h2>Needs attention</h2></div>
            @livewire(\Modules\Core\Livewire\DashboardAttention::class, [], key('attention'))
        </section>
    @endif

    {{-- Statistics + reports, by company. One range for every card; a module
         that lacks it shows its closest. --}}
    @if ($catalog)
        <section class="dash-section">
            <div class="dash-section-head">
                <h2>Statistics &amp; reports</h2>
                @if ($counts['statistics'] > 0)
                    <div class="dash-toolbar">
                        <label for="dash-range">Figures for</label>
                        <select id="dash-range" class="form-control" wire:model.live="range">
                            @foreach ($ranges as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            @foreach ($catalog as $code => $cards)
                <div class="dash-company">
                    <h3 class="dash-company-name">
                        {{ $companyNames[$code] ?? $code }}
                        <span>{{ $code }}</span>
                    </h3>

                    <div class="dash-grid">
                        @foreach ($cards as $card)
                            <article class="card dash-module" wire:key="dash-{{ $card['module'] }}">
                                <header>
                                    <h3>{{ $card['name'] }}</h3>
                                    @if ($card['statsUrl'])
                                        <a href="{{ $card['statsUrl'] }}" wire:navigate class="dash-open">
                                            Statistics
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                        </a>
                                    @endif
                                </header>

                                @if ($card['stats'])
                                    @livewire(\Modules\Core\Livewire\DashboardStats::class, ['pageKey' => $card['stats'], 'range' => $want], key('stats-' . $card['stats'] . '-' . ($want ?? 'default')))
                                @endif

                                @if ($card['reports'])
                                    <div class="dash-reports">
                                        <h4>Reports</h4>
                                        <ul>
                                            @foreach ($card['reports'] as $r)
                                                <li><a href="{{ $r['url'] }}" wire:navigate>{{ $r['label'] }}</a></li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    {{-- The screens this role works in. --}}
    @if ($work)
        <section class="dash-section">
            <div class="dash-section-head"><h2>Your work</h2></div>
            <div class="dash-work">
                @foreach ($work as $g)
                    <nav class="card dash-work-card" aria-label="{{ $g['company'] }} {{ $g['name'] }}">
                        <header>
                            <h3>{{ $g['name'] }}</h3>
                            <span>{{ $g['company'] }}</span>
                        </header>
                        <ul>
                            @foreach ($g['pages'] as $p)
                                <li><a href="{{ $p['url'] }}" wire:navigate>{{ $p['label'] }}</a></li>
                            @endforeach
                        </ul>
                    </nav>
                @endforeach
            </div>
        </section>
    @endif

    @if (! $catalog && ! $work && ! $hasAttention)
        <div class="card card-pad dash-empty">
            <h3>Nothing to show yet</h3>
            <p>Your role has not been given access to any screens. Ask an administrator to grant access in
                <strong>Admin → Roles</strong>; it will appear here automatically.</p>
        </div>
    @endif
</div>
