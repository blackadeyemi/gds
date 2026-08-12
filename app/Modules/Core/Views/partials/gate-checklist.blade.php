{{--
    A grouped checkbox list of gates (warehouse entrances, factory exit
    locations) for the user editor.

    Expects:
      $label     section heading
      $hint      one-line explanation under the heading
      $groups    Collection keyed by group name => Collection of models with
                 ->id, ->name and ->is_active
      $field     the Livewire array property to bind, e.g. 'entrance_ids'
      $selected  the current value of that property
      $errorKey  validation key to report against

    A gate that is inactive, or (for entrances) not attached to a warehouse, is
    still listed and still tickable — it simply cannot be used until that is
    fixed, and saying so here is more useful than hiding it and leaving someone
    wondering why a gate never appears.
--}}
<div class="form-group">
    <label class="form-label">{{ $label }}</label>
    <p class="text-sm text-muted" style="margin:-0.2rem 0 0.6rem;">{{ $hint }}</p>

    @if ($groups->isEmpty())
        <div class="text-muted text-sm" style="padding:.6rem .75rem;border:1px dashed var(--line);border-radius:8px;">
            None configured yet.
        </div>
    @else
        <div style="max-height:30vh;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:.35rem 0;">
            @foreach ($groups as $groupName => $gates)
                <div style="padding:.3rem .75rem;background:var(--hover,#f6f7f9);font-weight:700;font-size:0.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);">
                    {{ $groupName }}
                </div>
                @foreach ($gates as $gate)
                    <label wire:key="{{ $field }}-{{ $gate->id }}"
                           style="display:flex;align-items:center;gap:.55rem;padding:.35rem .75rem;cursor:pointer;">
                        <input type="checkbox" value="{{ $gate->id }}" wire:model="{{ $field }}">
                        <span>{{ $gate->name }}</span>
                        @unless ($gate->is_active)
                            <span class="badge badge-muted">inactive</span>
                        @endunless
                        @if ($groupName === 'Unassigned')
                            <span class="badge badge-danger">no parent</span>
                        @endif
                    </label>
                @endforeach
            @endforeach
        </div>
        <div class="text-muted text-sm" style="margin-top:.35rem;">
            {{ count($selected) }} selected.
        </div>
    @endif

    @error($errorKey) <div class="form-error">{{ $message }}</div> @enderror
</div>
