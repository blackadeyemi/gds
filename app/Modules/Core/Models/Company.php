<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $connection = 'core';
    protected $fillable = ['name'];

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
