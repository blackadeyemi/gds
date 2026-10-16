{{-- One activity: who, when, where, and every record it changed. --}}
<div class="modal-backdrop" x-data x-show="$wire.openId !== null" x-cloak
     @keydown.escape.window="$wire.closeActivity()" style="display:none;">
    <div class="modal-card" style="max-width:820px;" @click.outside="$wire.closeActivity()">
        @if ($opened = $this->opened)
            @php($a = $opened['activity'])
            <div class="modal-head">
                <div>
                    <h3 class="modal-title">{{ $a->verb }} · {{ $opened['page']['label'] ?? ($a->action === 'auth' ? 'Sign-in' : 'Change') }}</h3>
                    <p class="text-muted text-sm mb-0" style="margin-top:0.15rem;">
                        {{ $a->username ?? 'Unknown user' }}
                        · {{ $a->created_at?->format(\Modules\Core\Support\Prefs::dateFormat() . ' H:i:s') }}
                        ({{ $a->created_at?->diffForHumans() }})
                        @if ($opened['page']['module'] ?? null) · {{ $opened['page']['module'] }} @endif
                    </p>
                </div>
                <button class="modal-close" wire:click="closeActivity">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-top:0;">{{ $a->summary }}</p>

                <dl class="activity-facts">
                    @if ($a->ip)<dt>From</dt><dd>{{ $a->ip }} <span class="text-muted text-sm">{{ \Illuminate\Support\Str::limit($a->user_agent ?? '', 80) }}</span></dd>@endif
                    @if ($a->method)<dt>Action</dt><dd><code>{{ class_basename($a->component ?? '') }}::{{ $a->method }}</code></dd>@endif
                    @if ($a->item_count > $opened['shown'])
                        <dt>Records</dt><dd>{{ number_format($a->item_count) }} changed — the first {{ $opened['shown'] }} are listed</dd>
                    @endif
                </dl>

                @foreach ($a->items as $item)
                    <div class="activity-item" wire:key="ai-{{ $item->id }}">
                        <div class="activity-item-head">
                            <span class="badge {{ ['created' => 'badge-success', 'deleted' => 'badge-danger'][$item->op] ?? 'badge-warning' }}">{{ $item->op }}</span>
                            <strong>{{ ucfirst($item->subjectLabel()) }}</strong>
                            @if ($item->ref)<span>{{ $item->ref }}</span>@endif
                            @if ($item->subject_id && $item->ref !== '#' . $item->subject_id)
                                <span class="text-muted text-sm">#{{ $item->subject_id }}</span>
                            @endif
                            <span class="text-muted text-sm ml-auto">{{ $item->subject_type }}</span>
                        </div>
                        @if ($where = $item->whereText())
                            <div class="text-muted text-sm" style="margin:0.2rem 0 0.35rem;">Where {{ $where }}</div>
                        @endif
                        @php($rows = $item->rows())
                        @if ($rows)
                            <table class="data activity-diff">
                                <thead>
                                    <tr>
                                        <th>Field</th>
                                        @if ($item->op !== 'created')<th>Before</th>@endif
                                        @if ($item->op !== 'deleted')<th>After</th>@endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as [$field, $before, $after])
                                        <tr>
                                            <td>{{ $field }}</td>
                                            @if ($item->op !== 'created')<td class="diff-before">{{ $before ?? ($item->source === 'query' ? '' : '—') }}</td>@endif
                                            @if ($item->op !== 'deleted')<td class="diff-after">{{ $after ?? '—' }}</td>@endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            @if ($item->op === 'updated' && $item->source === 'query')
                                <p class="text-muted text-sm" style="margin:0.25rem 0 0;">Written directly by the screen, so the old values were not captured.</p>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="modal-foot">
                @if ($opened['url'])
                    <a class="btn btn-ghost" href="{{ $opened['url'] }}">Open {{ $opened['page']['label'] ?? 'page' }}</a>
                @endif
                <button type="button" class="btn btn-primary" wire:click="closeActivity">Close</button>
            </div>
        @endif
    </div>
</div>

{{-- Notification settings: modules this user does not want to hear about. --}}
<div class="modal-backdrop" x-data x-show="$wire.showMutes" x-cloak
     @keydown.escape.window="$wire.showMutes = false" style="display:none;">
    <div class="modal-card" style="max-width:520px;" @click.outside="$wire.showMutes = false">
        <div class="modal-head">
            <h3 class="modal-title">Notification settings</h3>
            <button class="modal-close" wire:click="$set('showMutes', false)">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted text-sm" style="margin-top:0;">
                Turn off a module to stop its notifications reaching your bell. Its activity still appears on this page.
            </p>
            @if ($showMutes)
                @forelse ($this->muteOptions as $opt)
                    <label class="activity-mute" wire:key="mute-{{ md5($opt['module']) }}">
                        <input type="checkbox" @checked(! $opt['muted']) wire:click="toggleMute(@js($opt['module']))">
                        <span>{{ $opt['module'] }}</span>
                        @if ($opt['muted'])<span class="badge badge-muted ml-auto">Muted</span>@endif
                    </label>
                @empty
                    <p class="text-muted text-sm mb-0">You have no pages yet, so there is nothing to be notified about.</p>
                @endforelse
            @endif
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-primary" wire:click="$set('showMutes', false)">Done</button>
        </div>
    </div>
</div>
