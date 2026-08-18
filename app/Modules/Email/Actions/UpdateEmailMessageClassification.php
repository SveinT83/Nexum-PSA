<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessageClassification;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateEmailMessageClassification
{
    /**
     * @param  array<int, string>  $tagNames
     */
    public function handle(EmailMailboxPlacement $placement, User $actor, ?int $categoryId, array $tagNames): EmailMessageClassification
    {
        $placement->loadMissing('account', 'message');

        if (! $placement->account || ! $placement->message) {
            throw ValidationException::withMessages([
                'classification' => 'The selected mailbox placement cannot be classified.',
            ]);
        }

        $mailboxAccess = app(MailboxAccess::class);

        if (
            ! $mailboxAccess->canAccessAccount($actor, $placement->account, MailboxAccess::VIEW)
            || ! $mailboxAccess->canAccessAccount($actor, $placement->account, MailboxAccess::ORGANIZE)
        ) {
            throw ValidationException::withMessages([
                'classification' => 'You need mailbox Organize access before changing email category or tags.',
            ]);
        }

        $category = $this->resolveCategory($categoryId);
        $tags = $this->resolveTags($tagNames, $actor);

        return DB::transaction(function () use ($placement, $actor, $category, $tags): EmailMessageClassification {
            $classification = EmailMessageClassification::query()->firstOrNew([
                'account_id' => $placement->account_id,
                'email_message_id' => $placement->email_message_id,
            ]);

            $before = $classification->exists
                ? $this->snapshot($classification->loadMissing('category', 'tags'))
                : ['category' => null, 'tags' => []];

            $classification->fill([
                'category_id' => $category?->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);
            $classification->save();

            $classification->tags()->syncWithPivotValues(
                $tags->pluck('id')->all(),
                ['module' => 'email'],
            );

            $classification->load('category', 'tags');

            DB::table('email_message_classification_events')->insert([
                'email_message_classification_id' => $classification->id,
                'account_id' => $placement->account_id,
                'email_message_id' => $placement->email_message_id,
                'actor_id' => $actor->id,
                'event_type' => 'updated',
                'before_json' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_json' => json_encode($this->snapshot($classification), JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $classification;
        });
    }

    private function resolveCategory(?int $categoryId): ?Category
    {
        if (! $categoryId) {
            return null;
        }

        $category = Category::query()
            ->whereKey($categoryId)
            ->where('type', Category::TYPE_EMAIL)
            ->where('is_active', true)
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'classificationCategoryId' => 'Select an active Email category.',
            ]);
        }

        return $category;
    }

    /**
     * @param  array<int, string>  $tagNames
     * @return \Illuminate\Support\Collection<int, Tag>
     */
    private function resolveTags(array $tagNames, User $actor)
    {
        $names = collect($tagNames)
            ->map(fn (string $name): string => trim((string) preg_replace('/\s+/', ' ', $name)))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values();

        if ($names->count() > 15) {
            throw ValidationException::withMessages([
                'classificationTagsInput' => 'Use at most 15 tags on one email.',
            ]);
        }

        return $names->map(function (string $name) use ($actor): Tag {
            if (mb_strlen($name) > 255) {
                throw ValidationException::withMessages([
                    'classificationTagsInput' => 'Each tag name must be 255 characters or fewer.',
                ]);
            }

            $slug = Str::slug($name);
            $tag = Tag::query()
                ->where(function ($query) use ($name, $slug): void {
                    $query->where('name', $name)
                        ->orWhere('slug', $slug);
                })
                ->where('active', true)
                ->first();

            if ($tag) {
                return $tag;
            }

            if (! $actor->can('taxonomy.manage_tags')) {
                throw ValidationException::withMessages([
                    'classificationTagsInput' => 'Unknown tags must be created by a user with Taxonomy tag management access.',
                ]);
            }

            return Tag::query()->create([
                'name' => $name,
                'slug' => $this->uniqueTagSlug($name),
                'active' => true,
            ]);
        });
    }

    /**
     * @return array{category: ?string, tags: array<int, string>}
     */
    private function snapshot(EmailMessageClassification $classification): array
    {
        return [
            'category' => $classification->category?->name,
            'tags' => $classification->tags
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
        ];
    }

    private function uniqueTagSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tag';
        $slug = $base;
        $counter = 2;

        while (Tag::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
