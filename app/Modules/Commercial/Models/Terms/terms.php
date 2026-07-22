<?php

namespace App\Modules\Commercial\Models\Terms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Packages\Package;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class terms extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'content',
        'origin',
        'source_integration_id',
        'external_document_id',
        'issuer',
        'source_url',
        'managed_externally',
        'sync_status',
        'last_checked_at',
        'metadata',
        'current_version_id',
    ];

    protected function casts(): array
    {
        return [
            'managed_externally' => 'boolean',
            'last_checked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function services()
    {
        return $this->belongsToMany(
            Services::class,
            'service_term_pivot',
            'term_id',
            'service_id'
        )->withTimestamps();
    }

    public function packages()
    {
        return $this->belongsToMany(
            Package::class,
            'package_term_pivot',
            'term_id',
            'package_id'
        )->withTimestamps();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TermVersion::class, 'term_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TermVersion::class, 'current_version_id');
    }

    public function cloudFactoryOffers()
    {
        return $this->belongsToMany(
            \App\Modules\Integration\Models\CloudFactory\Offer::class,
            'cloudfactory_offer_term',
            'term_id',
            'offer_id'
        )->withPivot(['is_active', 'last_seen_at'])->withTimestamps();
    }

    public function isProviderManaged(): bool
    {
        return $this->origin === 'provider' || $this->managed_externally;
    }

    public function isInUse(): bool
    {
        return $this->services()->exists()
            || $this->packages()->exists();
    }
}
