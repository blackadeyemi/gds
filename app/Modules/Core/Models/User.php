<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Legacy `core.user` table. Passwords are already bcrypt (the legacy app
 * used password_hash(..., PASSWORD_BCRYPT)), so Laravel's Hash::check
 * validates them natively with no rehash. Non-standard column names:
 * primary key `userid`, login field `username`, no email/remember_token.
 */
class User extends Authenticatable
{
    use HasRoles;

    protected $connection = 'core';
    protected $table = 'user';
    protected $primaryKey = 'userid';
    public $timestamps = false;

    protected $hidden = ['password'];

    /** Company this user is scoped to (null for Admins, who span all). */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** Department this user belongs to, within their company (null for Admins). */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /** Admin role (legacy_level 1) grants every ability on every page. */
    public function isAdmin(): bool
    {
        return $this->roles->contains(fn ($r) => (int) $r->legacy_level === 1);
    }

    /** @var array<int,string>|null memoized permission names for this request */
    protected ?array $permNameCache = null;

    protected function permissionNames(): array
    {
        return $this->permNameCache ??= $this->getAllPermissions()->pluck('name')->all();
    }

    /** Can the user perform an ability on a page? "{key}:{ability}" (Admin = all). */
    public function canDo(string $pageKey, string $ability): bool
    {
        return $this->isAdmin() || in_array("$pageKey:$ability", $this->permissionNames(), true);
    }

    /** Access to a page = its `view` ability. */
    public function canAccessPage(string $key): bool
    {
        return $this->canDo($key, 'view');
    }

    /** Keys of pages this user may open (Admin = all, else those with :view). */
    public function accessiblePageKeys(): array
    {
        if ($this->isAdmin()) {
            return Page::pluck('key')->all();
        }

        return array_map(
            fn ($n) => substr($n, 0, -strlen(':view')),
            array_filter($this->permissionNames(), fn ($n) => str_ends_with($n, ':view'))
        );
    }

    /**
     * Legacy default landing page for this user, resolved through
     * redirections (user.redirection_id -> redirections.page). `redirections`
     * is a bil-schema table while `user` lives in core, so this join runs on
     * the bil connection, where both the real redirections table and the
     * compatibility `user` view are visible.
     */
    public function defaultPage(): ?string
    {
        return optional(
            \DB::connection('bil')
                ->table('user')
                ->leftJoin('redirections', 'user.redirection_id', '=', 'redirections.id')
                ->where('user.userid', $this->userid)
                ->first(['redirections.page'])
        )->page;
    }
}
