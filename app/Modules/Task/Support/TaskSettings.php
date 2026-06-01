<?php

namespace App\Modules\Task\Support;

use App\Models\Settings\CommonSetting;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Ticket\Models\TicketPriority;
use Illuminate\Support\Facades\DB;

class TaskSettings
{
    private const TYPE = 'task';

    private const NAME = 'defaults';

    private const DEFAULTS = [
        'default_priority_id' => null,
        'default_estimated_minutes' => null,
    ];

    public function get(): array
    {
        $setting = CommonSetting::query()
            ->where('type', self::TYPE)
            ->where('name', self::NAME)
            ->first();

        return $this->normalize(json_decode($setting?->json ?: '[]', true) ?: []);
    }

    public function update(array $payload): array
    {
        $settings = $this->normalize($payload);

        CommonSetting::query()->updateOrCreate(
            ['type' => self::TYPE, 'name' => self::NAME],
            [
                'description' => 'Task module defaults for manual task creation.',
                'value' => $settings['default_priority_id'] ? (string) $settings['default_priority_id'] : null,
                'json' => json_encode($settings),
            ],
        );

        return $settings;
    }

    public function updateDefaultStatus(int $statusId): void
    {
        app(EnsureTaskDefaults::class)->handle();

        DB::transaction(function () use ($statusId): void {
            $status = TaskStatus::query()
                ->active()
                ->findOrFail($statusId);

            TaskStatus::query()->update(['is_default' => false]);
            $status->forceFill(['is_default' => true])->save();
        });
    }

    public function taskCreateDefaults(array $input = []): array
    {
        $settings = $this->get();
        $defaults = [
            'status_id' => TaskStatus::query()->default()->value('id'),
            'priority_id' => $settings['default_priority_id'],
            'estimated_minutes' => $settings['default_estimated_minutes'],
        ];

        return array_merge(
            array_filter($defaults, fn ($value): bool => $value !== null && $value !== ''),
            array_filter($input, fn ($value): bool => $value !== null && $value !== ''),
        );
    }

    private function normalize(array $payload): array
    {
        $payload = array_merge(self::DEFAULTS, array_intersect_key($payload, self::DEFAULTS));

        $priorityId = filled($payload['default_priority_id'] ?? null)
            ? (int) $payload['default_priority_id']
            : null;

        if ($priorityId && ! TicketPriority::query()->where('id', $priorityId)->where('is_active', true)->exists()) {
            $priorityId = null;
        }

        $estimatedMinutes = filled($payload['default_estimated_minutes'] ?? null)
            ? max(1, (int) $payload['default_estimated_minutes'])
            : null;

        return [
            'default_priority_id' => $priorityId,
            'default_estimated_minutes' => $estimatedMinutes,
        ];
    }
}
