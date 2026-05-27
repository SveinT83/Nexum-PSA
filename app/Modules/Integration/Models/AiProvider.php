<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class AiProvider extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'name',
        'provider_key',
        'base_url',
        'default_model',
        'embedding_model',
        'status',
        'config',
        'secrets',
        'last_error',
        'is_healthy',
    ];

    protected $casts = [
        'config' => 'array',
        'secrets' => 'array',
        'is_healthy' => 'boolean',
    ];

    public function agents(): HasMany
    {
        return $this->hasMany(AiAgent::class);
    }

    /**
     * Decrypt a provider secret while hiding legacy or invalid values.
     */
    public function getSecret(string $key): ?string
    {
        if (! isset($this->secrets[$key])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->secrets[$key]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Store provider credentials encrypted at rest.
     */
    public function setSecret(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $secrets = $this->secrets ?? [];
        $secrets[$key] = Crypt::encryptString($value);
        $this->secrets = $secrets;
    }
}
