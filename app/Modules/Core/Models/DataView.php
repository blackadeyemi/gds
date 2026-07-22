<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataView extends Model
{
    protected $connection = 'core';
    protected $fillable = ['data_page_id', 'key', 'label', 'is_enabled', 'is_default', 'sort_order'];
    protected $casts = ['is_enabled' => 'boolean', 'is_default' => 'boolean'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(DataPage::class, 'data_page_id');
    }
}
