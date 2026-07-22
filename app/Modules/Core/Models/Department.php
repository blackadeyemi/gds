<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Department extends Model
{
    protected $connection = 'core';
    protected $fillable = ['name', 'company_id', 'status'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
