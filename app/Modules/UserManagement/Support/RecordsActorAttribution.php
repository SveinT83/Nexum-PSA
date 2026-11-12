<?php

namespace App\Modules\UserManagement\Support;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit actors only: never infer an editor from an assignee or ambient login.
 * Technical touches and read-state changes retain the last domain attribution.
 */
trait RecordsActorAttribution
{
    private bool $attributionActorSpecified = false;

    private bool $attributionRelatedChange = false;

    public static function bootRecordsActorAttribution(): void
    {
        static::creating(function ($record): void {
            if (! array_key_exists('updated_by', $record->getAttributes())) {
                $record->updated_by = $record->created_by;
            }
            $record->actor_updated_at = now();
        });

        static::updating(function ($record): void {
            // Creator identity cannot be changed through an ordinary record edit.
            $record->attributes['created_by'] = $record->getRawOriginal('created_by');
            $changes = array_diff_key($record->getDirty(), array_flip([
                'created_by', 'updated_by', 'actor_updated_at', 'updated_at', 'is_unread',
            ]));

            if ($changes === [] && ! $record->attributionRelatedChange) {
                $record->attributes['updated_by'] = $record->getRawOriginal('updated_by');
                $record->attributes['actor_updated_at'] = $record->getRawOriginal('actor_updated_at');

                return;
            }

            // Uninstrumented/system writes are unknown rather than the previous editor.
            if (! $record->attributionActorSpecified) {
                $record->attributes['updated_by'] = null;
            }
            $record->actor_updated_at = now();
        });

        static::saved(function ($record): void {
            $record->attributionActorSpecified = false;
            $record->attributionRelatedChange = false;
            $record->unsetRelation('updater');
        });
    }

    /** Capture explicit assignment even when the ID equals the previous updater. */
    public function setUpdatedByAttribute(mixed $value): void
    {
        $this->attributionActorSpecified = true;
        $this->attributes['updated_by'] = $value === null ? null : (int) $value;
    }

    /** Call only after a verified change to record-owned tags/custom fields/checklist. */
    public function recordRelatedMutation(?User $actor): void
    {
        $this->attributionRelatedChange = true;
        $this->updated_by = $actor?->id;
        $this->touch();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->select(['id', 'name']);
    }
}
