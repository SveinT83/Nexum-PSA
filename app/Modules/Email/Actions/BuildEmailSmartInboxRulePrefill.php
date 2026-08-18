<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Services\EmailConversationFingerprint;
use App\Modules\Email\Services\EmailSmartInboxSuggestionIdentity;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BuildEmailSmartInboxRulePrefill
{
    public const ADMIN_PREFILL_TOKEN_QUERY = 'smart_inbox_prefill';

    public const ADMIN_ACTION_PROVIDER_ARCHIVE = 'provider_archive';

    public const ADMIN_ACTION_PROVIDER_MOVE = 'provider_move';

    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly EmailConversationFingerprint $conversationFingerprint,
        private readonly EmailSmartInboxSuggestionIdentity $identity,
    ) {}

    /**
     * Return an existing rule-editor payload. This action intentionally never
     * creates, versions, publishes, or activates an Email rule.
     *
     * @return array{
     *   mode: 'personal'|'admin',
     *   personal_payload: array<string, mixed>|null,
     *   admin_route: string|null,
     *   admin_query: array<string, mixed>|null
     * }
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(EmailSmartInboxSuggestion $suggestion, User $actor): array
    {
        return $this->build($suggestion, $actor, true);
    }

    /**
     * Consume a short-lived, one-use browser token and rebuild its prefill
     * under current suggestion, mailbox, account, and actor authority.
     *
     * @return array<string, mixed>|null
     */
    public function consumeAdminPrefill(mixed $token, User $actor): ?array
    {
        if (! is_string($token) || strlen($token) !== 64 || ! ctype_alnum($token)) {
            return null;
        }

        $payload = session()->pull($this->adminPrefillSessionKey($token));

        if (! is_array($payload)
            || ! is_numeric($payload['user_id'] ?? null)
            || (int) $payload['user_id'] !== (int) $actor->id
            || ! is_numeric($payload['suggestion_id'] ?? null)
            || ! is_numeric($payload['expires_at'] ?? null)
            || (int) $payload['expires_at'] < now()->timestamp) {
            return null;
        }

        $suggestion = EmailSmartInboxSuggestion::query()->find((int) $payload['suggestion_id']);

        if (! $suggestion) {
            return null;
        }

        try {
            $prefill = $this->build($suggestion, $actor, false);
        } catch (AuthorizationException|ValidationException) {
            return null;
        }

        return $prefill['mode'] === 'admin' && is_array($prefill['admin_query'])
            ? $prefill['admin_query']
            : null;
    }

    /**
     * @return array{
     *   mode: 'personal'|'admin',
     *   personal_payload: array<string, mixed>|null,
     *   admin_route: string|null,
     *   admin_query: array<string, mixed>|null
     * }
     */
    private function build(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        bool $issueAdminToken,
    ): array {
        return DB::transaction(function () use ($suggestion, $actor, $issueAdminToken): array {
            $locked = EmailSmartInboxSuggestion::query()
                ->lockForUpdate()
                ->findOrFail($suggestion->id);

            if ((int) $locked->user_id !== (int) $actor->id
                || ! $actor->isActive()
                || ! in_array($locked->status, [
                    EmailSmartInboxSuggestion::STATUS_PENDING,
                    EmailSmartInboxSuggestion::STATUS_APPLIED,
                ], true)) {
                throw new AuthorizationException('Smart Inbox suggestion not found.');
            }

            if (! in_array($locked->effect_type, [
                EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
                EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            ], true)
                || ! $locked->ai_agent_id
                || $locked->schema_version !== EmailSmartInboxSuggestion::SCHEMA_VERSION
                || ! hash_equals(
                    (string) $locked->proposal_fingerprint,
                    $this->identity->checksum((array) $locked->proposal_json),
                )) {
                throw ValidationException::withMessages([
                    'suggestion' => 'This Smart Inbox suggestion cannot prefill a cleanup rule.',
                ]);
            }

            $account = EmailAccount::query()
                ->whereKey($locked->account_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            $conversation = EmailConversation::query()
                ->whereKey($locked->email_conversation_id)
                ->where('account_id', $locked->account_id)
                ->where('status', EmailConversation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if (! $account
                || ! $conversation
                || ! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::ORGANIZE)) {
                throw new AuthorizationException('Smart Inbox suggestion not found.');
            }

            try {
                $current = $this->conversationFingerprint->forConversation(
                    $conversation,
                    $locked->source_fingerprint_schema
                        ?: EmailConversationFingerprint::LEGACY_SCHEMA_VERSION,
                );
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'suggestion' => 'This Smart Inbox suggestion cannot be validated. Analyze the conversation again.',
                ]);
            }
            if ($current['source_message_ids'] === []
                || ! hash_equals((string) $locked->source_fingerprint, $current['fingerprint'])) {
                throw ValidationException::withMessages([
                    'suggestion' => 'This Smart Inbox suggestion is stale. Analyze the conversation again.',
                ]);
            }

            $target = $this->targetFolder($locked, $account);
            $message = $this->sourceMessage($locked, $conversation);
            $condition = $this->condition($message);
            $actionLabel = $locked->effect_type === EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL
                ? 'Archive'
                : 'Move to '.$target->name;
            $name = Str::limit($actionLabel.' mail from '.$condition['value'], 255, '');

            if ($account->isPersonal()) {
                if ((int) $account->owner_id !== (int) $actor->id) {
                    throw new AuthorizationException('Smart Inbox suggestion not found.');
                }

                return [
                    'mode' => 'personal',
                    'personal_payload' => [
                        'name' => $name,
                        'condition_field' => $condition['field'],
                        'condition_value' => $condition['value'],
                        'action_type' => $locked->effect_type === EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL
                            ? CreatePersonalEmailRule::ACTION_ARCHIVE
                            : CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER,
                        'target_folder_id' => (int) $target->id,
                    ],
                    'admin_route' => null,
                    'admin_query' => null,
                ];
            }

            if (! $actor->can('email.rule_manage') || ! $account->ticket_ingress_enabled) {
                throw new AuthorizationException('You cannot create an Admin cleanup rule for this mailbox.');
            }

            $query = [
                'account_id' => (int) $account->id,
                'condition_field' => $condition['field'],
                'condition_value' => $condition['value'],
                'name' => $name,
                'action_type' => $locked->effect_type === EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL
                    ? self::ADMIN_ACTION_PROVIDER_ARCHIVE
                    : self::ADMIN_ACTION_PROVIDER_MOVE,
                'target_folder_id' => (int) $target->id,
                // Admin cleanup prefills are deliberately inactive. The
                // normal form submission and publisher remain mandatory.
                'is_active' => 0,
                // Cleanup must not fall through into default Ticket routing.
                'stop_processing' => 1,
            ];

            $token = $issueAdminToken
                ? $this->storeAdminPrefillToken($locked, $actor)
                : null;

            return [
                'mode' => 'admin',
                'personal_payload' => null,
                'admin_route' => $token
                    ? route('tech.admin.settings.email.rules.create', [
                        self::ADMIN_PREFILL_TOKEN_QUERY => $token,
                    ])
                    : null,
                'admin_query' => $query,
            ];
        });
    }

    private function storeAdminPrefillToken(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
    ): string {
        $token = Str::random(64);
        session()->put($this->adminPrefillSessionKey($token), [
            'user_id' => (int) $actor->id,
            'suggestion_id' => (int) $suggestion->id,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return $token;
    }

    private function adminPrefillSessionKey(string $token): string
    {
        return 'email.smart_inbox.admin_rule_prefill.'.$token;
    }

    private function targetFolder(
        EmailSmartInboxSuggestion $suggestion,
        EmailAccount $account,
    ): EmailFolder {
        $targetId = $suggestion->proposal_json['target_folder_id'] ?? null;
        if (! is_int($targetId) && (! is_string($targetId) || ! ctype_digit($targetId))) {
            throw ValidationException::withMessages(['suggestion' => 'The cleanup rule target is invalid.']);
        }

        $folder = EmailFolder::query()
            ->whereKey((int) $targetId)
            ->where('account_id', $account->id)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->when(
                $suggestion->effect_type === EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
                fn ($folders) => $folders->where('role', EmailFolder::ROLE_ARCHIVE),
            )
            ->lockForUpdate()
            ->first();

        $sourcePlacement = EmailMailboxPlacement::query()
            ->whereKey($suggestion->selected_email_mailbox_placement_id)
            ->where('account_id', $account->id)
            ->lockForUpdate()
            ->first(['id', 'email_folder_id', 'email_message_id']);

        if (! $folder
            || ! $sourcePlacement
            || (int) $folder->id === (int) $sourcePlacement->email_folder_id
            || (string) ($suggestion->proposal_json['target_folder_path'] ?? '') !== (string) $folder->path
            || (string) ($suggestion->proposal_json['target_folder_name'] ?? '') !== (string) $folder->name) {
            throw ValidationException::withMessages([
                'suggestion' => 'The cleanup rule target is stale or no longer selectable.',
            ]);
        }

        return $folder;
    }

    private function sourceMessage(
        EmailSmartInboxSuggestion $suggestion,
        EmailConversation $conversation,
    ): EmailMessage {
        $allowedIds = collect($suggestion->source_message_ids_json ?? [])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $messageId = $suggestion->proposal_json['source_message_id'] ?? null;

        if ($messageId === null) {
            $messageId = EmailMailboxPlacement::query()
                ->whereKey($suggestion->selected_email_mailbox_placement_id)
                ->where('account_id', $conversation->account_id)
                ->value('email_message_id');
        }

        if (! is_numeric($messageId) || ! in_array((int) $messageId, $allowedIds, true)) {
            throw ValidationException::withMessages(['suggestion' => 'The cleanup rule source is stale.']);
        }

        $message = EmailMessage::query()
            ->whereKey((int) $messageId)
            ->where('account_id', $conversation->account_id)
            ->first();

        if (! $message) {
            throw ValidationException::withMessages(['suggestion' => 'The cleanup rule source is stale.']);
        }

        return $message;
    }

    /** @return array{field: string, value: string} */
    private function condition(EmailMessage $message): array
    {
        $from = trim((string) $message->from_email);

        return $from !== ''
            ? ['field' => 'from', 'value' => Str::limit($from, 1000, '')]
            : [
                'field' => 'subject',
                'value' => Str::limit(trim((string) ($message->subject ?: '(no subject)')), 1000, ''),
            ];
    }
}
