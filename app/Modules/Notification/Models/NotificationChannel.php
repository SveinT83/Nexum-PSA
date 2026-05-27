<?php

namespace App\Modules\Notification\Models;

use Database\Factories\Notification\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * System-wide notification channel configuration.
 *
 * Stores credentials and settings for external notification drivers
 * like Nextcloud Talk, Slack, etc.
 */
class NotificationChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'label',
        'driver',
        'is_enabled',
        'config',
        'secrets',
        'last_tested_at',
        'last_test_result',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config' => 'array',
        'secrets' => 'array', // Will be encrypted at rest
        'last_tested_at' => 'datetime',
    ];

    protected static function newFactory(): NotificationChannelFactory
    {
        return NotificationChannelFactory::new();
    }

    /**
     * Get a channel configuration by driver name.
     */
    public static function getByDriver(string $driver): ?self
    {
        return static::where('driver', $driver)->first();
    }

    /**
     * Get a decrypted secret value by key.
     */
    public function getSecret(string $key): ?string
    {
        if (!isset($this->secrets[$key])) {
            return null;
        }

        try {
            return \Crypt::decryptString($this->secrets[$key]);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Set an encrypted secret value.
     */
    public function setSecret(string $key, string $value): self
    {
        $secrets = $this->secrets ?? [];
        $secrets[$key] = \Crypt::encryptString($value);
        $this->secrets = $secrets;

        return $this;
    }
}
