<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Jobs\ProcessEmailRuleReprocessRun;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleReprocessRun;
use App\Modules\Email\Models\EmailRuleVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailRuleReprocessService
{
    public const DEFAULT_CAP = 100;

    public const HARD_CAP = 500;

    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly InboundEmailRuleEngine $engine,
    ) {}

    /** @param array<string, mixed> $selection */
    public function preview(EmailRule $rule, User $actor, array $selection): EmailRuleReprocessRun
    {
        if (! $actor->isActive()
            || ! $actor->can('email.rule_manage')
            || ! $actor->can('email.rule_reprocess')) {
            abort(403);
        }
        $rule->loadMissing('publishedVersion');
        $version = $rule->publishedVersion;
        if (! $version) {
            throw ValidationException::withMessages(['rule' => 'Publish the Email rule before previewing reprocessing.']);
        }

        $selection = $this->normalizeSelection($version, $selection);
        $account = EmailAccount::query()->findOrFail($selection['account_id']);
        if (! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::VIEW)) {
            abort(404);
        }

        [$messages, $overflow] = $this->selectedMessages($selection);
        if (is_array($selection['message_ids'])
            && count($selection['message_ids']) !== $messages->count()) {
            // Explicit selections must never silently disclose or omit a
            // message that is outside the actor's active mailbox boundary.
            abort(404);
        }
        $selectionHash = $this->selectionHash($selection, $messages->pluck('id')->all());

        return DB::transaction(function () use ($rule, $version, $actor, $selection, $messages, $overflow, $selectionHash): EmailRuleReprocessRun {
            $run = EmailRuleReprocessRun::query()->create([
                'public_id' => (string) Str::uuid(),
                'email_rule_id' => $rule->id,
                'email_rule_version_id' => $version->id,
                'actor_id' => $actor->id,
                'operation' => 'preview',
                'status' => EmailRuleReprocessRun::STATUS_PREVIEW,
                'selection_json' => $selection,
                'selection_hash' => $selectionHash,
                'requested_count' => $messages->count(),
                'overflow' => $overflow,
                'expires_at' => now()->addMinutes(15),
            ]);

            foreach ($messages as $message) {
                $placement = $this->activePlacement($message, $selection);
                if (! $placement) {
                    continue;
                }
                $preview = $this->engine->previewPublishedVersion($rule, $version, $message);
                $run->items()->create([
                    'email_message_id' => $message->id,
                    'email_mailbox_placement_id' => $placement->id,
                    'email_account_id' => $message->account_id,
                    'source_fingerprint' => $this->sourceFingerprint($message, $placement),
                    'status' => 'previewed',
                    'reason_code' => $preview['matched'] ? null : 'rule_not_matched',
                    'matched' => $preview['matched'],
                    'action_summary_json' => collect($preview['actions'])->map(fn (array $action): array => [
                        'position' => $action['position'],
                        'type' => $action['type'],
                        'status' => $action['status'],
                    ])->values()->all(),
                ]);
            }

            $run->forceFill([
                'requested_count' => $run->items()->count(),
                'matched_count' => $run->items()->where('matched', true)->count(),
            ])->save();

            return $run->fresh('items');
        });
    }

    public function apply(EmailRuleReprocessRun $run, User $actor): EmailRuleReprocessRun
    {
        return DB::transaction(function () use ($run, $actor): EmailRuleReprocessRun {
            $run = EmailRuleReprocessRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->status !== EmailRuleReprocessRun::STATUS_PREVIEW || ! $run->expires_at?->isFuture()) {
                throw ValidationException::withMessages(['run' => 'This reprocess preview expired or was already applied.']);
            }
            if ($run->overflow) {
                throw ValidationException::withMessages(['run' => 'The selection exceeds the preview cap. Narrow it before applying.']);
            }

            $this->authorizeRun($run, $actor);
            [$current] = $this->selectedMessages($run->selection_json);
            if (! hash_equals($run->selection_hash, $this->selectionHash($run->selection_json, $current->pluck('id')->all()))) {
                throw ValidationException::withMessages(['run' => 'The selected mailbox messages changed after preview. Create a new preview.']);
            }
            foreach ($run->items()->with(['message', 'placement'])->get() as $item) {
                if (! $item->message || ! $item->placement
                    || ! hash_equals($item->source_fingerprint, $this->sourceFingerprint($item->message, $item->placement))) {
                    throw ValidationException::withMessages(['run' => 'A message placement changed after preview. Create a new preview.']);
                }
            }

            $run->forceFill([
                'operation' => 'apply',
                'status' => EmailRuleReprocessRun::STATUS_QUEUED,
                'expires_at' => null,
            ])->save();
            DB::afterCommit(fn () => ProcessEmailRuleReprocessRun::dispatch($run->id));

            return $run->refresh();
        }, 3);
    }

    public function repeat(EmailRuleReprocessRun $source, User $actor, bool $fullRerun): EmailRuleReprocessRun
    {
        $this->authorizeRun($source, $actor);
        if (! $source->finished_at && $source->status !== EmailRuleReprocessRun::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['run' => 'Only a finished or cancelled run can be retried.']);
        }

        $run = DB::transaction(function () use ($source, $actor, $fullRerun): EmailRuleReprocessRun {
            $run = EmailRuleReprocessRun::query()->create([
                'public_id' => (string) Str::uuid(),
                'email_rule_id' => $source->email_rule_id,
                'email_rule_version_id' => $source->email_rule_version_id,
                'parent_run_id' => $source->id,
                'actor_id' => $actor->id,
                'operation' => $fullRerun ? 'full_rerun' : 'retry',
                'status' => EmailRuleReprocessRun::STATUS_QUEUED,
                'selection_json' => $source->selection_json,
                'selection_hash' => $source->selection_hash,
                'requested_count' => $source->requested_count,
                'matched_count' => $source->matched_count,
            ]);

            foreach ($source->items as $item) {
                $run->items()->create([
                    'email_message_id' => $item->email_message_id,
                    'email_mailbox_placement_id' => $item->email_mailbox_placement_id,
                    'email_account_id' => $item->email_account_id,
                    'source_fingerprint' => $item->source_fingerprint,
                    'status' => 'previewed',
                    'reason_code' => null,
                    'matched' => $item->matched,
                    'action_summary_json' => $item->action_summary_json,
                ]);
            }

            return $run;
        });
        ProcessEmailRuleReprocessRun::dispatch($run->id);

        return $run;
    }

    public function cancel(EmailRuleReprocessRun $run, User $actor): EmailRuleReprocessRun
    {
        $this->authorizeRun($run, $actor);
        if (in_array($run->status, [EmailRuleReprocessRun::STATUS_SUCCEEDED, EmailRuleReprocessRun::STATUS_PARTIAL, EmailRuleReprocessRun::STATUS_FAILED], true)) {
            throw ValidationException::withMessages(['run' => 'A finished reprocess run cannot be cancelled.']);
        }
        $run->forceFill(['status' => EmailRuleReprocessRun::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

        return $run->refresh();
    }

    public function authorizeRun(EmailRuleReprocessRun $run, User $actor): void
    {
        $account = EmailAccount::query()->findOrFail((int) data_get($run->selection_json, 'account_id'));
        if (! $actor->isActive() || ! $actor->can('email.rule_reprocess')
            || ! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::VIEW)) {
            abort(404);
        }
    }

    /** @param array<string, mixed> $selection */
    private function normalizeSelection(EmailRuleVersion $version, array $selection): array
    {
        $accountId = (int) ($selection['account_id'] ?? 0);
        if (! in_array($accountId, array_map('intval', $version->account_ids_json ?? []), true)) {
            throw ValidationException::withMessages(['account_id' => 'Choose an account from the published rule scope.']);
        }
        $selectors = collect(['message_ids', 'folder_id', 'search', 'utc_date'])
            ->filter(fn (string $key): bool => filled($selection[$key] ?? null));
        if ($selectors->count() !== 1) {
            throw ValidationException::withMessages(['selection' => 'Choose exactly one bounded message selection.']);
        }
        $cap = max(1, min(self::HARD_CAP, (int) ($selection['cap'] ?? self::DEFAULT_CAP)));

        return [
            'account_id' => $accountId,
            'message_ids' => $selectors->first() === 'message_ids'
                ? collect($selection['message_ids'])->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all()
                : null,
            'folder_id' => $selectors->first() === 'folder_id' ? (int) $selection['folder_id'] : null,
            // The durable operational ledger stores only encrypted search
            // input; mailbox content fragments must not be readable at rest.
            'search_ciphertext' => $selectors->first() === 'search'
                ? Crypt::encryptString(Str::limit(trim((string) $selection['search']), 120, ''))
                : null,
            'utc_date' => $selectors->first() === 'utc_date' ? (string) $selection['utc_date'] : null,
            'cap' => $cap,
        ];
    }

    /** @param array<string, mixed> $selection */
    private function selectedMessages(array $selection): array
    {
        $query = EmailMessage::query()
            ->where('account_id', $selection['account_id'])
            ->withActiveProviderPlacement()
            ->orderBy('id');
        if (is_array($selection['message_ids'])) {
            $query->whereIn('id', $selection['message_ids']);
        } elseif ($selection['folder_id']) {
            $query->whereHas('placements', fn (Builder $placements) => $placements
                ->where('email_folder_id', $selection['folder_id'])
                ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                ->whereNull('provider_missing_at'));
        } elseif (($selection['search_ciphertext'] ?? null) !== null) {
            $term = Crypt::decryptString($selection['search_ciphertext']);
            $query->where(fn (Builder $messages) => $messages
                ->where('subject', 'like', '%'.$term.'%')
                ->orWhere('from_email', 'like', '%'.$term.'%'));
        } else {
            $query->whereDate('received_at', $selection['utc_date']);
        }

        $messages = $query->limit($selection['cap'] + 1)->get();
        $overflow = $messages->count() > $selection['cap'];

        return [$messages->take($selection['cap'])->values(), $overflow];
    }

    /** @param array<string, mixed> $selection */
    private function activePlacement(EmailMessage $message, array $selection): ?EmailMailboxPlacement
    {
        return $message->placements()
            ->where('account_id', $selection['account_id'])
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('provider_missing_at')
            ->when($selection['folder_id'], fn ($query) => $query->where('email_folder_id', $selection['folder_id']))
            ->orderBy('id')
            ->first();
    }

    private function sourceFingerprint(EmailMessage $message, EmailMailboxPlacement $placement): string
    {
        return hash('sha256', implode('|', [
            $message->id, $message->account_id, $placement->id, $placement->email_folder_id,
            $placement->uid_namespace_id, $placement->imap_uid_validity, $placement->imap_uid,
            $placement->sync_version, $placement->local_state, $placement->provider_missing_at?->toIso8601String(),
        ]));
    }

    /** @param array<string, mixed> $selection @param array<int, int> $ids */
    private function selectionHash(array $selection, array $ids): string
    {
        sort($ids);

        return hash('sha256', json_encode([$selection, $ids], JSON_THROW_ON_ERROR));
    }
}
