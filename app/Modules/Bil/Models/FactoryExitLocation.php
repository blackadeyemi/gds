<?php

namespace Modules\Bil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A gate a pallet can leave the factory through (bil.factoryexit_details).
 *
 * Three rows today: Gambini Gate 1, BIL1-Gate 1 and BIL1-Elevator. The table is
 * just a factory name paired with a location name; `factory_exit` stores the
 * location NAME, so the pair is the only way back to the factory — which is why
 * both the entry screen and the report join on `exitlocation` rather than an id.
 */
class FactoryExitLocation extends Model
{
    protected $connection = 'bil';
    protected $table = 'factoryexit_details';
    public $timestamps = false;

    protected $guarded = [];
}
