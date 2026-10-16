<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `core.actions` — "this happened, and who says so".
 *
 * A very thin audit table the legacy wrote through a small HTTP service: one
 * row per milestone, keyed by a free-text `request` string. Every one of its
 * 927 rows belongs to a BPL proforma:
 *
 *   `pfi_customer{order id}`   — the customer approved the proforma (358)
 *   `pfi_production{order id}` — the order has been produced (288)
 *   `pfi_loading{order id}`    — the containers have been loaded (281)
 *
 * `bil.actions` and `bpl.actions` are views onto this table, which is how the
 * legacy proforma screens join it. See Modules\Bpl\Support\ProformaMilestones.
 */
class Action extends Model
{
    protected $connection = 'core';
    protected $table = 'actions';
    public $timestamps = false;

    protected $fillable = ['user', 'request', 'type', 'date', 'comment'];

    protected $casts = ['date' => 'datetime'];
}
