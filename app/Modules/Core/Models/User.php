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
