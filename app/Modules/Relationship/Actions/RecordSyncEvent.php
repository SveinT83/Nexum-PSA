<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncEvent;

class RecordSyncEvent
{
    public function handle(NexumRelationship $relationship, array $attributes): NexumSyncEvent
    {
        return NexumSyncEvent::query()->create(array_merge([
            'relationship_id' => $relationship->id,
            'direction' => $attributes['direction'] ?? 'internal',
            'event_type' => $attributes['event_type'] ?? 'relationship_event',
            'outcome' => $attributes['outcome'] ?? 'recorded',
            'occurred_at' => now(),
        ], $attributes, [
            'relationship_id' => $relationship->id,
        ]));
    }
}
