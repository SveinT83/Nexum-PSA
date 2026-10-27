<?php

namespace App\Modules\CustomField\Models;

use Illuminate\Database\Eloquent\Model;

/** Encrypted exact results; request bodies and plaintext API keys are never persisted. */
class CustomFieldApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];
}
