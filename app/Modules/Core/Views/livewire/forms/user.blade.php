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
    <select class="form-control" wire:model="role_id">
        <option value="">— Select role —</option>
        @foreach ($this->roles as $r)
            <option value="{{ $r->id }}">{{ $r->name }}</option>
        @endforeach
    </select>
    @error('role_id') <div class="form-error">{{ $message }}</div> @enderror
</div>
<div class="form-group">
    <label class="form-label">Password @if ($editingId)<span class="text-muted">(leave blank to keep)</span>@endif</label>
    <input type="password" class="form-control" wire:model="password" placeholder="{{ $editingId ? '••••••••' : 'Set a password' }}" autocomplete="new-password">
    @error('password') <div class="form-error">{{ $message }}</div> @enderror
</div>
