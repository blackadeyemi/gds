<div class="form-group">
    <label class="form-label">Username</label>
    <input type="text" class="form-control" wire:model="username" placeholder="Username" autofocus>
    @error('username') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Full name</label>
    <input type="text" class="form-control" wire:model="fullname" placeholder="Full name">
    @error('fullname') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Email</label>
    <input type="email" class="form-control" wire:model="email" placeholder="name@company.ng">
    @error('email') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Role</label>
    @include('core::partials.searchable-select', [
        'field' => 'role_id',
        'options' => $this->roles,
        'placeholder' => '— Select role —',
        'live' => true,
    ])
    @error('role_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
@if (! $this->isAdminRole())
    <div class="form-group">
        <label class="form-label">Company</label>
        @include('core::partials.searchable-select', [
            'field' => 'company_id',
            'options' => $this->companies,
            'placeholder' => '— Select company —',
            'live' => true,
        ])
        @error('company_id') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Department</label>
        @include('core::partials.searchable-select', [
            'field' => 'department_id',
            'options' => $this->departmentsForCompany,
            'placeholder' => $this->company_id ? '— Select department —' : 'Select a company first',
            'disabled' => ! $this->company_id,
            'key' => 'dept-' . ($this->company_id ?? 'none'),
        ])
        @error('department_id') <div class="form-error">{{ $message }}</div> @enderror
    </div>
@endif
<div class="form-group">
    <label class="form-label">Password @if ($editingId)<span class="text-muted">(leave blank to keep)</span>@endif</label>
    <input type="password" class="form-control" wire:model="password" placeholder="{{ $editingId ? '••••••••' : 'Set a password' }}" autocomplete="new-password">
    @error('password') <div class="form-error">{{ $message }}</div> @enderror
</div>
