<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarkEmailAsSpam
{
    public function handle(EmailMessage $message, ?User $actor = null): EmailRule
    {
        return DB::transaction(function () use ($message, $actor) {
            $tag = Tag::firstOrCreate(
                ['name' => 'spam'],
                [
                    'slug' => 'spam',
                    'color' => '#dc3545',
                    'active' => true,
                ]
            );

            if (! $message->tags()->where('tags.id', $tag->id)->exists()) {
                $message->tags()->attach($tag->id, ['module' => 'email']);
            }

            $message->forceFill(['state' => 'archived'])->save();

            return $this->upsertRule($message, $actor);
        });
    }

    private function upsertRule(EmailMessage $message, ?User $actor): EmailRule
    {
        $conditions = $message->from_email
            ? [['field' => 'from', 'operator' => 'equals', 'value' => (string) $message->from_email]]
            : [['field' => 'subject', 'operator' => 'equals', 'value' => (string) $message->subject]];
        $actions = [
            ['type' => 'tag', 'value' => 'spam'],
            ['type' => 'archive', 'value' => ''],
        ];

        $rule = EmailRule::query()
            ->where('trigger', EmailRule::TRIGGER_INBOUND)
            ->get()
            ->first(fn (EmailRule $rule) => ($rule->conditions_json ?? []) === $conditions);

        $payload = [
            'name' => 'Spam: '.Str::limit((string) ($message->from_email ?: $message->subject ?: 'Inbound email'), 80, ''),
            'description' => 'Created from Inbox when a technician marked an email as spam.',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'weight' => 0,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => $conditions,
            'actions_json' => $actions,
            'updated_by' => $actor?->id,
        ];

        if ($rule) {
            $rule->forceFill($payload)->save();

            return $rule->fresh();
        }

        return EmailRule::create($payload + [
            'created_by' => $actor?->id,
            'hit_count' => 0,
        ]);
    }
}
