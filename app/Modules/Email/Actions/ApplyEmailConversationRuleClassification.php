<?php

namespace App\Modules\Email\Actions;

use App\Modules\Email\Models\EmailConversationClassification;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ApplyEmailConversationRuleClassification
{
    public const ACTION_TAG_CONVERSATION = 'tag_conversation';

    public const ACTION_SET_CONVERSATION_CATEGORY = 'set_conversation_category';

    public function __construct(
        private readonly EmailConversationProjector $conversations,
    ) {}

    /**
     * Apply only explicit conversation-scoped rule effects. Legacy `tag` remains message-scoped.
     */
    public function handle(
        EmailMessage $message,
        EmailRule $rule,
        string $actionType,
        string $value,
        int $actionIndex,
    ): EmailConversationClassification {
        if (! in_array($actionType, [self::ACTION_TAG_CONVERSATION, self::ACTION_SET_CONVERSATION_CATEGORY], true)) {
            throw new RuntimeException('Unsupported conversation classification rule action.');
        }

        $placement = $this->placementFor($message);

        if (! $placement) {
            throw new RuntimeException('The rule message has no account-matching mailbox placement.');
        }

        $conversation = $placement->conversation
            ?? $this->conversations->assignPlacement($placement);

        if (! $conversation || (int) $conversation->account_id !== (int) $message->account_id) {
            throw new RuntimeException('The rule message has no valid account-scoped conversation.');
        }

        $tag = $actionType === self::ACTION_TAG_CONVERSATION
            ? $this->resolveTag($value)
            : null;
        $category = $actionType === self::ACTION_SET_CONVERSATION_CATEGORY
            ? $this->resolveEmailCategory($value)
            : null;

        return DB::transaction(function () use (
            $conversation,
            $rule,
            $actionType,
            $actionIndex,
            $tag,
            $category,
        ): EmailConversationClassification {
            DB::table('email_conversations')
                ->whereKey($conversation->id)
                ->where('account_id', $conversation->account_id)
                ->lockForUpdate()
                ->first();

            $classification = EmailConversationClassification::query()
                ->where('account_id', $conversation->account_id)
                ->where('email_conversation_id', $conversation->id)
                ->lockForUpdate()
                ->first();

            if (! $classification) {
                $classification = new EmailConversationClassification([
                    'account_id' => $conversation->account_id,
                    'email_conversation_id' => $conversation->id,
                ]);
            }

            $before = $classification->exists
                ? $this->snapshot($classification->loadMissing('category', 'tags'))
                : ['category' => null, 'tags' => []];
            $provenance = [
                'source' => EmailConversationClassification::SOURCE_RULE,
                'email_rule_id' => (int) $rule->id,
                'email_rule_version_id' => $rule->published_version_id ? (int) $rule->published_version_id : null,
                'action_index' => $actionIndex,
                'action_type' => $actionType,
            ];

            $classification->fill([
                'category_id' => $category?->id ?? $classification->category_id,
                'assigned_by' => null,
                'assigned_at' => now(),
                'source' => EmailConversationClassification::SOURCE_RULE,
                'provenance' => $provenance,
            ]);
            $classification->save();

            if ($tag && ! $classification->tags()->whereKey($tag->id)->exists()) {
                $classification->tags()->attach($tag->id, ['module' => 'email']);
            }

            $classification->load('category', 'tags');
            $after = $this->snapshot($classification);

            if ($before !== $after) {
                DB::table('email_conversation_classification_events')->insert([
                    'email_conversation_classification_id' => $classification->id,
                    'account_id' => $conversation->account_id,
                    'email_conversation_id' => $conversation->id,
                    'actor_id' => null,
                    'event_type' => 'rule_applied',
                    'before_json' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after_json' => json_encode($after, JSON_THROW_ON_ERROR),
                    'metadata_json' => json_encode($provenance, JSON_THROW_ON_ERROR),
                    'provenance_json' => json_encode($provenance, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }

            return $classification;
        });
    }

    private function placementFor(EmailMessage $message): ?EmailMailboxPlacement
    {
        return $message->placements()
            ->with(['account', 'message', 'conversation'])
            ->where('account_id', $message->account_id)
            ->orderByRaw('CASE WHEN local_state = ? THEN 0 ELSE 1 END', [EmailMailboxPlacement::LOCAL_ACTIVE])
            ->latest('id')
            ->first();
    }

    private function resolveTag(string $value): Tag
    {
        $value = trim($value);
        $tag = Tag::query()
            ->where('active', true)
            ->where(function ($query) use ($value): void {
                $query->where('id', ctype_digit($value) ? (int) $value : 0)
                    ->orWhere('slug', $value)
                    ->orWhere('name', $value);
            })
            ->first();

        if (! $tag) {
            throw new RuntimeException('The conversation tag target is missing or inactive.');
        }

        return $tag;
    }

    private function resolveEmailCategory(string $value): Category
    {
        $value = trim($value);
        $category = Category::query()
            ->where('type', Category::TYPE_EMAIL)
            ->where('is_active', true)
            ->where(function ($query) use ($value): void {
                $query->where('id', ctype_digit($value) ? (int) $value : 0)
                    ->orWhere('slug', $value)
                    ->orWhere('name', $value);
            })
            ->first();

        if (! $category) {
            throw new RuntimeException('The conversation category target is not an active Email category.');
        }

        return $category;
    }

    /**
     * @return array{category: array{id: int, name: string}|null, tags: array<int, array{id: int, name: string}>}
     */
    private function snapshot(EmailConversationClassification $classification): array
    {
        return [
            'category' => $classification->category
                ? ['id' => (int) $classification->category->id, 'name' => $classification->category->name]
                : null,
            'tags' => $classification->tags
                ->sortBy('id')
                ->map(fn (Tag $tag): array => ['id' => (int) $tag->id, 'name' => $tag->name])
                ->values()
                ->all(),
        ];
    }
}
