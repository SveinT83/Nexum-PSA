<?php

namespace App\Modules\Ticket\Support;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Integration\Support\DomainApiAudit;
use Illuminate\Support\Facades\DB;

/** Existing merge switches remain explicit settings; reading them never activates automation. */
class TicketMergeSettings
{
    public function settings(): array
    {
        $settings = CommonSetting::query()->where('type', 'ticket_merge')->pluck('value', 'name');

        return [
            'auto_merge_enabled' => $settings->get('auto_merge_enabled', '0') === '1',
            'ai_merge_enabled' => $settings->get('ai_merge_enabled', '0') === '1',
            'ai_similarity_threshold' => (int) ($settings->get('ai_similarity_threshold', '90') ?: 90),
        ];
    }

    public function update(array $data, User $actor): void
    {
        DB::transaction(function () use ($data, $actor) {
            $before = $this->settings();
            $values = ['auto_merge_enabled' => (bool) ($data['auto_merge_enabled'] ?? false), 'ai_merge_enabled' => (bool) ($data['ai_merge_enabled'] ?? false), 'ai_similarity_threshold' => (int) $data['ai_similarity_threshold']];
            foreach ($values as $name => $value) {
                CommonSetting::updateOrCreate(['type' => 'ticket_merge', 'name' => $name], [
                    'description' => 'Ticket merge automation setting.', 'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                ]);
            }
            app(DomainApiAudit::class)->record($actor, 'ticket', 'ticket.settings.merge.updated', null, $before, $this->settings());
        });
    }
}
