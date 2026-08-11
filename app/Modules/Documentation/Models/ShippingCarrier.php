<?php

namespace App\Modules\Documentation\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documentation-owned master data for a transport brand or carrier division.
 *
 * Operational shipments snapshot this configuration so later carrier edits do
 * not rewrite tracking history owned by Storage.
 */
class ShippingCarrier extends Model
{
    public const LIFECYCLE_ACTIVE = 'active';

    public const LIFECYCLE_LEGACY = 'legacy';

    public const LIFECYCLE_INACTIVE = 'inactive';

    public const TRACKING_TEMPLATE = 'template';

    public const TRACKING_GENERIC_PAGE = 'generic_page';

    public const TRACKING_PROVIDER_GENERATED = 'provider_generated';

    public const TRACKING_API = 'api';

    public const TRACKING_MANUAL = 'manual';

    public const VISIBILITY_NORMAL = 'normal';

    public const VISIBILITY_RECIPIENT_ONLY = 'recipient_only';

    public const VISIBILITY_AUTHENTICATED = 'authenticated';

    public const VERIFICATION_VERIFIED = 'verified';

    public const VERIFICATION_UNVERIFIED = 'unverified';

    public const VERIFICATION_NEEDS_REVIEW = 'needs_review';

    protected $table = 'shipping_carriers';

    protected $fillable = [
        'code',
        'name',
        'vendor_id',
        'legal_name',
        'lifecycle_state',
        'sort_order',
        'service_tags',
        'website_url',
        'support_url',
        'tracking_page_url',
        'tracking_method',
        'tracking_url_template',
        'allowed_tracking_hosts',
        'link_visibility',
        'connector_type',
        'source_url',
        'verification_state',
        'verified_at',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'service_tags' => 'array',
        'allowed_tracking_hosts' => 'array',
        'verified_at' => 'date',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->where('lifecycle_state', self::LIFECYCLE_ACTIVE);
    }

    /** @return array<string, string> */
    public static function lifecycleOptions(): array
    {
        return [
            self::LIFECYCLE_ACTIVE => 'Active',
            self::LIFECYCLE_LEGACY => 'Legacy / transition',
            self::LIFECYCLE_INACTIVE => 'Inactive',
        ];
    }

    /** @return array<string, string> */
    public static function trackingMethodOptions(): array
    {
        return [
            self::TRACKING_TEMPLATE => 'Verified URL template',
            self::TRACKING_GENERIC_PAGE => 'Generic tracking page',
            self::TRACKING_PROVIDER_GENERATED => 'Provider-generated link',
            self::TRACKING_API => 'Future API connector',
            self::TRACKING_MANUAL => 'Manual / no link',
        ];
    }

    /** @return array<string, string> */
    public static function linkVisibilityOptions(): array
    {
        return [
            self::VISIBILITY_NORMAL => 'Normal',
            self::VISIBILITY_RECIPIENT_ONLY => 'Recipient only',
            self::VISIBILITY_AUTHENTICATED => 'Authenticated carrier session',
        ];
    }

    /** @return array<string, string> */
    public static function verificationOptions(): array
    {
        return [
            self::VERIFICATION_VERIFIED => 'Verified',
            self::VERIFICATION_UNVERIFIED => 'Unverified',
            self::VERIFICATION_NEEDS_REVIEW => 'Needs review',
        ];
    }

    public function lifecycleLabel(): string
    {
        return self::lifecycleOptions()[$this->lifecycle_state] ?? $this->lifecycle_state;
    }

    public function trackingMethodLabel(): string
    {
        return self::trackingMethodOptions()[$this->tracking_method] ?? $this->tracking_method;
    }

    public function linkVisibilityLabel(): string
    {
        return self::linkVisibilityOptions()[$this->link_visibility] ?? $this->link_visibility;
    }

    public function verificationLabel(): string
    {
        return self::verificationOptions()[$this->verification_state] ?? $this->verification_state;
    }
}
