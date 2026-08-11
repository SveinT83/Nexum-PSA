<?php

namespace App\Modules\Notification\Models;

use App\Models\Core\User;
use Illuminate\Support\Str;
use Minishlink\WebPush\ContentEncoding;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Package-compatible subscription with Nexum-owned safe device metadata.
 *
 * Endpoint and encryption material are transport secrets and must never be
 * serialized through an inventory or audit response.
 */
class WebPushSubscription extends PushSubscription
{
    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'device_label',
        'browser_family',
        'platform_family',
        'last_seen_at',
    ];

    protected $hidden = [
        'endpoint',
        'public_key',
        'auth_token',
        'subscribable_id',
        'subscribable_type',
    ];

    protected $casts = [
        'content_encoding' => ContentEncoding::class,
        'last_seen_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (WebPushSubscription $subscription): void {
            $subscription->public_id ??= (string) Str::uuid();

            // The package creates the transport record before the controller
            // enriches it with coarse browser metadata.
            $subscription->device_label ??= 'Other browser on Other platform';
            $subscription->browser_family ??= 'Other browser';
            $subscription->platform_family ??= 'Other platform';
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function belongsToUser(User $user): bool
    {
        return (string) $this->subscribable_id === (string) $user->getKey()
            && $this->subscribable_type === $user->getMorphClass();
    }

    /**
     * Return only fields approved for user and administrator inventories.
     *
     * @return array<string, mixed>
     */
    public function safeSummary(): array
    {
        return [
            'id' => $this->public_id,
            'label' => $this->device_label,
            'browser' => $this->browser_family,
            'platform' => $this->platform_family,
            'registered_at' => $this->created_at?->toISOString(),
            'last_seen_at' => $this->last_seen_at?->toISOString(),
        ];
    }
}
