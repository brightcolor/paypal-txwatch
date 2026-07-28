<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A FinTS-capable bank (BLZ + PIN/TAN URL) for the setup bank picker.
 * Reference data, filled via `fints:import-banks`.
 */
class FintsBank extends Model
{
    protected $table = 'fints_banks';

    protected $primaryKey = 'blz';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['blz', 'name', 'ort', 'bic', 'url'];
}
