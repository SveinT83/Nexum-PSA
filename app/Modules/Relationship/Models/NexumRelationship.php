<?php

namespace App\Modules\Relationship\Models;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\RelationshipDirection;
use App\Modules\Relationship\Support\RelationshipHealthStatus;
use App\Modules\Relationship\Support\RelationshipStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class NexumRelationship extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'direction',
        'relationship_type',
        'client_id',
        'vendor_id',
        'remote_base_url',
        'remote_instance_id',
        'remote_organization_name',
        'remote_organization_identifier',
        'status',
        'health_status',
        'capabilities',
        'ticket_policy',
        'documentation_policy',
        'attachment_policy',
        'status_mapping',
        'service_areas',
        'outbound_token_encrypted',
        'webhook_secret_encrypted',
        'inbound_token_hash',
        'token_rotated_at',
        'last_successful_sync_at',
        'last_failure_at',
        'health_checked_at',
        'failure_summary',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'ticket_policy' => 'array',
        'documentation_policy' => 'array',
        'attachment_policy' => 'array',
        'status_mapping' => 'array',
        'service_areas' => 'array',
        'outbound_token_encrypted' => 'encrypted',
        'webhook_secret_encrypted' => 'encrypted',
        'token_rotated_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'health_checked_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function syncLinks(): HasMany
    {
        return $this->hasMany(NexumSyncLink::class, 'relationship_id');
    }

    public function syncEvents(): HasMany
    {
        return $this->hasMany(NexumSyncEvent::class, 'relationship_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RelationshipStatus::ACTIVE);
    }

    public function scopeTicketCapable(Builder $query): Builder
    {
        return $query->where('capabilities->'.RelationshipCapability::TICKET_SYNC, true);
    }

    public function isActive(): bool
    {
        return $this->status === RelationshipStatus::ACTIVE;
    }

    public function isProviderForClient(): bool
    {
        return $this->direction === RelationshipDirection::WE_ARE_PROVIDER;
    }

    public function usesUpstreamProvider(): bool
    {
        return $this->direction === RelationshipDirection::WE_USE_PROVIDER;
    }

    public function supports(string $capability): bool
    {
        return (bool) (($this->capabilities ?? RelationshipCapability::defaults())[$capability] ?? false);
    }

    public function syncSource(): string
    {
        return 'nexum_relationship:'.$this->id;
    }

    public function rotateInboundToken(?string $plainToken = null): string
    {
        $plainToken = $plainToken ?: Str::random(64);
        $this->inbound_token_hash = Hash::make($plainToken);
        $this->token_rotated_at = now();

        return $plainToken;
    }

    public function hasOutboundCredentials(): bool
    {
        return filled($this->remote_base_url)
            && filled($this->outbound_token_encrypted)
            && filled($this->webhook_secret_encrypted);
    }

    public function markSyncSuccess(): void
    {
        $this->forceFill([
            'health_status' => RelationshipHealthStatus::HEALTHY,
            'last_successful_sync_at' => now(),
            'failure_summary' => null,
        ])->save();
    }

    public function markSyncFailure(string $summary): void
    {
        $this->forceFill([
            'health_status' => RelationshipHealthStatus::FAILING,
            'last_failure_at' => now(),
            'failure_summary' => Str::limit($summary, 1000, ''),
        ])->save();
    }
}
