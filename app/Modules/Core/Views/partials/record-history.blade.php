{{-- History of one record, from the activity log. Params: $history (ActivityItem collection). --}}
<details class="record-history">
    <summary>History <span class="badge badge-muted">{{ $history->count() }}</span></summary>
    @forelse ($history as $item)
        @php($a = $item->activity)
        <div class="record-history-row" wire:key="rh-{{ $item->id }}">
            <div class="record-history-head">
                <span class="badge {{ ['created' => 'badge-success', 'deleted' => 'badge-danger'][$item->op] ?? 'badge-warning' }}">{{ $item->op }}</span>
                <strong>{{ $a?->username ?? 'Unknown' }}</strong>
                <span class="text-muted text-sm">{{ $a?->created_at?->format(\Modules\Core\Support\Prefs::dateFormat() . ' H:i') }}</span>
            </div>
            @if ($item->op === 'updated')
                <ul class="record-history-changes">
                    @foreach (array_slice($item->rows(), 0, 8) as [$field, $before, $after])
                        <li>{{ $field }}: @if ($before !== null)<span class="diff-before">{{ $before }}</span> → @endif<span class="diff-after">{{ $after }}</span></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        <p class="text-muted text-sm mb-0">No changes recorded yet. Changes are recorded from 7 October 2026.</p>
    @endforelse
</details>
