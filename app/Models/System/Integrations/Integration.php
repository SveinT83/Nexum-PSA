<?php

namespace App\Models\System\Integrations;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class Integration extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'type',
        'server',
        'status',
        'config',
        'secrets',
        'last_sync_at',
        'last_error',
        'is_healthy',
    ];

    protected $casts = [
        'config' => 'array',
        'secrets' => 'array',
        'last_sync_at' => 'datetime',
        'is_healthy' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Get decrypted secret by key.
     */
    public function getSecret($key)
    {
        if (! isset($this->secrets[$key])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->secrets[$key]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Set encrypted secret.
     */
    public function setSecret($key, $value)
    {
        $secrets = $this->secrets ?? [];
        $secrets[$key] = Crypt::encryptString($value);
        $this->secrets = $secrets;
    }

    public function cloudFactoryClientLinks()
    {
        return $this->hasMany(\App\Modules\Integration\Models\CloudFactory\ClientLink::class);
    }

    public function cloudFactoryOffers()
    {
        return $this->hasMany(\App\Modules\Integration\Models\CloudFactory\Offer::class);
    }
}
