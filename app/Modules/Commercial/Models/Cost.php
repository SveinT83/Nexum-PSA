<?php

namespace App\Modules\Commercial\Models;

use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Documentation\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Cost extends Model
{
    protected $table = 'costs';

    protected $fillable = [
        'source',
        'external_reference',
        'source_integration_id',
        'managed_externally',
        'name',
        'cost',
        'currency',
        'unitId', // The ID of the unit table
        'recurrence',
        'vendor_id',
        'note',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'cost' => 'decimal:4',
        'managed_externally' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function unit()
    {
        return $this->belongsTo(Units::class, 'unitId');
    }

    public function sourceIntegration()
    {
        return $this->belongsTo(Integration::class, 'source_integration_id');
    }

    public function isIntegrationManaged(): bool
    {
        return $this->managed_externally
            && $this->sourceIntegration?->status === 'active';
    }

    public function scopeIntegrationManaged(Builder $query): Builder
    {
        return $query
            ->where('managed_externally', true)
            ->whereHas('sourceIntegration', fn (Builder $query) => $query->where('status', 'active'));
    }

    public function scopeEditableInNexum(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('managed_externally', false)
                ->orWhereDoesntHave(
                    'sourceIntegration',
                    fn (Builder $query) => $query->where('status', 'active')
                );
        });
    }
}
