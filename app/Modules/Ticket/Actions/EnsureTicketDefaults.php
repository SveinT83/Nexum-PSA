<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use Illuminate\Support\Str;

class EnsureTicketDefaults
{
    /**
     * @return array{queue: TicketQueue, status: TicketStatus, priority: TicketPriority, type: TicketType}
     */
    public function handle(): array
    {
        $queue = TicketQueue::query()->where('is_default', true)->first()
            ?? TicketQueue::query()->first()
            ?? TicketQueue::create([
                'name' => 'Support',
                'slug' => 'support',
                'description' => 'Default support queue.',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 10,
            ]);

        $type = TicketType::query()->where('slug', 'support')->first()
            ?? TicketType::query()->first()
            ?? TicketType::create([
                'name' => 'Support',
                'slug' => 'support',
                'description' => 'Default support ticket type.',
                'is_system' => true,
                'is_deletable' => false,
                'is_active' => true,
                'sort_order' => 10,
            ]);

        TicketType::firstOrCreate(
            ['slug' => 'lead'],
            [
                'name' => 'Lead',
                'description' => 'Default lead or sales inquiry ticket type.',
                'is_system' => true,
                'is_deletable' => true,
                'is_active' => true,
                'sort_order' => 20,
            ]
        );

        foreach ([
            ['name' => 'New', 'slug' => 'new', 'state' => 'open', 'is_default' => true, 'is_closed' => false, 'sort_order' => 10],
            ['name' => 'In Progress', 'slug' => 'in-progress', 'state' => 'open', 'is_default' => false, 'is_closed' => false, 'sort_order' => 20],
            ['name' => 'Waiting Customer', 'slug' => 'waiting-customer', 'state' => 'waiting', 'is_default' => false, 'is_closed' => false, 'sort_order' => 30],
            ['name' => 'Resolved', 'slug' => 'resolved', 'state' => 'resolved', 'is_default' => false, 'is_closed' => false, 'sort_order' => 40],
            ['name' => 'Closed', 'slug' => 'closed', 'state' => 'closed', 'is_default' => false, 'is_closed' => true, 'sort_order' => 50],
        ] as $statusData) {
            TicketStatus::firstOrCreate(
                ['slug' => $statusData['slug']],
                [
                    'name' => $statusData['name'],
                    'state' => $statusData['state'],
                    'is_default' => $statusData['is_default'],
                    'is_closed' => $statusData['is_closed'],
                    'is_active' => true,
                    'sort_order' => $statusData['sort_order'],
                ]
            );
        }

        $status = TicketStatus::query()->where('is_default', true)->first()
            ?? TicketStatus::query()->orderBy('sort_order')->first();

        foreach ([1 => 'Critical', 2 => 'High', 3 => 'Normal', 4 => 'Low'] as $level => $name) {
            TicketPriority::firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'level' => $level,
                    'is_default' => $level === 3,
                    'is_active' => true,
                    'sort_order' => $level * 10,
                ]
            );
        }

        $priority = TicketPriority::query()->where('is_default', true)->first()
            ?? TicketPriority::query()->orderBy('level')->first();

        return [
            'queue' => $queue,
            'status' => $status,
            'priority' => $priority,
            'type' => $type,
        ];
    }
}
