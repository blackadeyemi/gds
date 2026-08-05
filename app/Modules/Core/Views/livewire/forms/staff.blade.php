<div class="form-group">
    <label class="form-label">Staff number</label>
    <input type="number" class="form-control" wire:model="staff_no" placeholder="e.g. 1242">
    @error('staff_no') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Name</label>
    <input type="text" class="form-control" wire:model="name" placeholder="e.g. SALAU SEMIU" autofocus>
    <div class="form-hint">Service history is matched on this name within the division, so change it with care.</div>
    @error('name') <div class="form-error">{{ $message }}</div> @enderror
</div>
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
        'options' => $this->divisionsForDepartment,
        'placeholder' => $department_id ? '— No division —' : '— Select a department first —',
        'disabled' => ! $department_id,
        // Re-initialise with the new option set when the department changes.
        'key' => 'ss-division-' . ($department_id ?? 'none'),
    ])
    @error('division_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Login account <span style="font-weight:400">(optional)</span></label>
    @include('core::partials.searchable-select', [
        'field' => 'user_id',
        'options' => $this->users,
        'placeholder' => '— Not linked —',
        'valueKey' => 'userid',
        'labelKey' => 'username',
    ])
    <div class="form-hint">Link this person to their GDS login, if they have one.</div>
    @error('user_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Status</label>
    <select class="form-control" wire:model="is_active">
        <option value="1">Active</option>
        <option value="0">Inactive</option>
    </select>
    @error('is_active') <div class="form-error">{{ $message }}</div> @enderror
</div>
