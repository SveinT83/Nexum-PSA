<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\TicketQueue;
use Illuminate\Support\Str;

class EnsureRelationshipTicketQueue
{
    public function handle(NexumRelationship $relationship): TicketQueue
    {
        $policy = $relationship->ticket_policy ?? [];

        if (! empty($policy['queue_id'])) {
            $queue = TicketQueue::query()
                ->where('is_active', true)
                ->find($policy['queue_id']);

            if ($queue) {
                return $queue;
            }
        }

        if (($policy['auto_create_queue'] ?? false) === true) {
            $queue = TicketQueue::query()->firstOrCreate(
                ['slug' => 'nexum-relationship-'.$relationship->id],
                [
                    'name' => 'Nexum: '.$relationship->name,
                    'description' => 'Tickets received through the Nexum relationship '.$relationship->name.'.',
                    'is_default' => false,
                    'is_active' => true,
                    'sort_order' => 1000,
                    'settings' => ['source' => 'nexum_relationship', 'relationship_id' => $relationship->id],
                ]
            );

            $relationship->forceFill([
                'ticket_policy' => array_merge($policy, [
                    'queue_id' => $queue->id,
                    'queue_slug' => $queue->slug ?: Str::slug($queue->name),
                    'auto_create_queue' => true,
                ]),
            ])->save();

            return $queue;
        }

        return app(EnsureTicketDefaults::class)->handle()['queue'];
    }
}
