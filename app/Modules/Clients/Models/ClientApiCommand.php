<?php

namespace App\Modules\Clients\Models;

use Illuminate\Database\Eloquent\Model;

/** Contains only protected command references/results, never provider credentials. */
class ClientApiCommand extends Model
{
    protected $table = 'client_api_commands';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];
}
