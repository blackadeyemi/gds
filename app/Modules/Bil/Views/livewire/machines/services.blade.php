<div>
    <div class="page-head">
        <h1>Services</h1>
        <p>Log a service job against a machine — who worked on it, where, and for how long.</p>
    </div>

    @if (session('ok'))
        <div class="card" style="border-color:var(--success);color:var(--success);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="card" style="border-color:var(--danger);color:var(--danger);margin-bottom:1rem;padding:0.7rem 1.25rem;">{{ session('err') }}</div>
    @endif

    <form wire:submit="save">
        <div class="card card-pad" style="margin-bottom:1rem;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Job title</label>
                    <input type="text" class="form-control" wire:model="jobtitle" placeholder="e.g. LOG SAW 9" autofocus>
                    @error('jobtitle') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Service type</label>
                    <select class="form-control" wire:model="service_type_id">
                        <option value="">— Select type —</option>
                        @foreach ($this->serviceTypes as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                    @error('service_type_id') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">User</label>
                    <input type="text" class="form-control" value="{{ auth()->user()?->username }}" disabled>
                </div>
            </div>
        </div>

        <div class="card card-pad" style="margin-bottom:1rem;">
            <h3 class="card-title" style="margin-bottom:0.75rem;">Who</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Department</label>
                    @include('core::partials.searchable-select', [
                        'field' => 'department_id',
                        'options' => $this->departments,
                        'placeholder' => '— Select department —',
                        'live' => true,
                    ])
                    @error('department_id') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Division <span style="font-weight:400">(optional)</span></label>
                    @include('core::partials.searchable-select', [
                        'field' => 'division_id',
                        'options' => $this->divisions,
                        'placeholder' => $department_id ? '— All divisions —' : 'Select a department first',
                        'disabled' => ! $department_id,
                        'live' => true,
                        'key' => 'svc-div-' . ($department_id ?? 'none'),
                    ])
                    @error('division_id') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Staff</label>
                    @include('core::partials.searchable-select', [
                        'field' => 'staff_id',
                        'options' => $this->staffOptions,
                        'placeholder' => $department_id ? '— Select staff —' : 'Select a department first',
                        'disabled' => ! $department_id,
                        'key' => 'svc-staff-' . ($department_id ?? 'none') . '-' . ($division_id ?? 'all'),
                    ])
                    @error('staff_id') <div class="form-error">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        <div class="card card-pad" style="margin-bottom:1rem;">
            <h3 class="card-title" style="margin-bottom:0.75rem;">Where</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Line</label>
                    @include('core::partials.searchable-select', [
                        'field' => 'line_id',
                        'options' => $this->lineOptions,
                        'placeholder' => '— Select line —',
                        'live' => true,
                    ])
                    <div class="form-hint">Indented entries are sub-lines.</div>
                    @error('line_id') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Project</label>
                    @include('core::partials.searchable-select', [
                        'field' => 'project_id',
                        'options' => $this->projects,
                        'placeholder' => $line_id ? '— Select project —' : 'Select a line first',
                        'disabled' => ! $line_id,
                        'live' => true,
                        'key' => 'svc-proj-' . ($line_id ?? 'none'),
                    ])
                    @error('project_id') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Sub-project <span style="font-weight:400">(optional)</span></label>
                    @include('core::partials.searchable-select', [
                        'field' => 'subproject_id',
                        'options' => $this->subprojects,
                        'placeholder' => $project_id ? '— None —' : 'Select a project first',
                        'disabled' => ! $project_id,
                        'key' => 'svc-sub-' . ($project_id ?? 'none'),
                    ])
                    @error('subproject_id') <div class="form-error">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        <div class="card card-pad" style="margin-bottom:1rem;">
            <h3 class="card-title" style="margin-bottom:0.75rem;">When</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Start date</label>
                    @include('bil::partials.date-field', ['model' => 'startDate'])
                    @error('startDate') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Start time</label>
                    <input type="time" class="form-control" wire:model="startTime">
                    @error('startTime') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">End date</label>
                    @include('bil::partials.date-field', ['model' => 'endDate'])
                    @error('endDate') <div class="form-error">{{ $message }}</div> @enderror
                </div>
                <div class="form-group">
                    <label class="form-label">End time</label>
                    <input type="time" class="form-control" wire:model="endTime">
                    @error('endTime') <div class="form-error">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        <div class="card card-pad" style="margin-bottom:1rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Note</label>
                <textarea rows="5" class="form-control" wire:model="note" placeholder="What was done"></textarea>
                @error('note') <div class="form-error">{{ $message }}</div> @enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="save">Log service job</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </form>
</div>
