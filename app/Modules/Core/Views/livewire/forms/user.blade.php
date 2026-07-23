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
    <select class="form-control" wire:model.live="role_id">
        <option value="">— Select role —</option>
        @foreach ($this->roles as $r)
            <option value="{{ $r->id }}">{{ $r->name }}</option>
        @endforeach
    </select>
    @error('role_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
@if (! $this->isAdminRole())
    <div class="form-group">
        <label class="form-label">Company</label>
        <select class="form-control" wire:model.live="company_id">
            <option value="">— Select company —</option>
            @foreach ($this->companies as $c)
                <option value="{{ $c->id }}">{{ $c->name }}</option>
            @endforeach
        </select>
        @error('company_id') <div class="form-error">{{ $message }}</div> @enderror
    </div>
    <div class="form-group">
        <label class="form-label">Department</label>
        <select class="form-control" wire:model="department_id" @disabled(! $this->company_id)>
            <option value="">{{ $this->company_id ? '— Select department —' : 'Select a company first' }}</option>
            @foreach ($this->departmentsForCompany as $d)
                <option value="{{ $d->id }}">{{ $d->name }}</option>
            @endforeach
        </select>
        @error('department_id') <div class="form-error">{{ $message }}</div> @enderror
    </div>
@endif
<div class="form-group">
    <label class="form-label">Password @if ($editingId)<span class="text-muted">(leave blank to keep)</span>@endif</label>
    <input type="password" class="form-control" wire:model="password" placeholder="{{ $editingId ? '••••••••' : 'Set a password' }}" autocomplete="new-password">
    @error('password') <div class="form-error">{{ $message }}</div> @enderror
</div>
