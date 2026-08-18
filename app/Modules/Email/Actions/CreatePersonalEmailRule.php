<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Services\EmailRulePublisher;
use App\Modules\Email\Services\MailboxAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePersonalEmailRule
{
    public const ACTION_MOVE_TO_FOLDER = 'move_to_folder';

    public const ACTION_ARCHIVE = 'archive';

    /**
     * @var array<int, string>
     */
    private const CONDITION_FIELDS = ['from', 'from_domain', 'subject', 'to', 'cc'];

    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly EmailRulePublisher $publisher,
    ) {}

    /**
     * @param  array{name?: string|null, condition_field?: string|null, condition_value?: string|null, action_type?: string|null, target_folder_id?: mixed}  $payload
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(EmailMailboxPlacement $placement, User $actor, array $payload): EmailRule
    {
        if ($placement->exists) {
            $placement->refresh();
        }

        $placement->loadMissing(['account', 'folder', 'message']);

        if (! Schema::hasColumn('email_rules', 'rule_kind') || ! Schema::hasColumn('email_rules', 'owner_id')) {
            throw ValidationException::withMessages([
                'rule' => 'Personal mail rules are not available until the latest Email migrations have run.',
            ]);
        }

        $account = $placement->account;
        if (! $account instanceof EmailAccount || ! $account->isPersonal() || (int) $account->owner_id !== (int) $actor->id) {
            throw new AuthorizationException('Personal rules can only be created for your own personal mailbox.');
        }

        if (! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::ORGANIZE)) {
            throw new AuthorizationException('You need mailbox Organize access before creating this rule.');
        }

        if ($placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE || ! $placement->message) {
            throw ValidationException::withMessages([
                'placement' => 'This mailbox placement is not available for a new rule.',
            ]);
        }

        $condition = $this->conditionFromPayload($payload);
        $action = $this->actionFromPayload($placement, $payload);
        $name = $this->ruleName($payload, $condition, $action);

        return DB::transaction(function () use ($account, $actor, $condition, $action, $name): EmailRule {
            $rule = EmailRule::query()->create([
                'name' => $name,
                'description' => 'Personal Mail rule created from the Mail workspace.',
                'trigger' => EmailRule::TRIGGER_INBOUND,
                'routing_phase' => EmailRule::ROUTING_PHASE_PERSONAL,
                'rule_kind' => EmailRule::KIND_PERSONAL_SIMPLE,
                'owner_id' => $actor->id,
                'weight' => 100,
                'is_active' => true,
                'lifecycle_status' => EmailRule::LIFECYCLE_PUBLISHED,
                'stop_processing' => false,
                'conditions_json' => [$condition],
                'actions_json' => [$action],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $rule->accounts()->sync([$account->id]);
            $this->publisher->publish($rule, $actor);

            return $rule->fresh(['accounts', 'publishedVersion']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{field: string, operator: string, value: string}
     *
     * @throws ValidationException
     */
    private function conditionFromPayload(array $payload): array
    {
        $field = trim((string) ($payload['condition_field'] ?? 'from'));
        if (! in_array($field, self::CONDITION_FIELDS, true)) {
            throw ValidationException::withMessages([
                'personalRuleConditionField' => 'Choose a supported condition for the personal rule.',
            ]);
        }

        $value = trim((string) ($payload['condition_value'] ?? ''));
        if ($field === 'from_domain') {
            $value = ltrim(Str::lower($value), '@');
        }

        if ($value === '') {
            throw ValidationException::withMessages([
                'personalRuleConditionValue' => 'Enter a value before creating the personal rule.',
            ]);
        }

        return [
            'field' => $field,
            'operator' => in_array($field, ['from', 'from_domain'], true) ? 'equals' : 'contains',
            'value' => Str::limit($value, 1000, ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function actionFromPayload(EmailMailboxPlacement $placement, array $payload): array
    {
        $actionType = trim((string) ($payload['action_type'] ?? self::ACTION_MOVE_TO_FOLDER));

        if ($actionType === self::ACTION_ARCHIVE) {
            $archiveFolder = EmailFolder::query()
                ->when(
                    is_numeric($payload['target_folder_id'] ?? null),
                    fn ($folders) => $folders->whereKey((int) $payload['target_folder_id']),
                )
                ->where('account_id', $placement->account_id)
                ->where('role', EmailFolder::ROLE_ARCHIVE)
                ->where('is_selectable', true)
                ->where('sync_enabled', true)
                ->first();

            if (! $archiveFolder) {
                throw ValidationException::withMessages([
                    'personalRuleActionType' => 'This mailbox does not have a selectable Archive folder.',
                ]);
            }

            return [
                'type' => self::ACTION_ARCHIVE,
                'value' => $archiveFolder->path,
                'target_folder_id' => $archiveFolder->id,
            ];
        }

        if ($actionType !== self::ACTION_MOVE_TO_FOLDER) {
            throw ValidationException::withMessages([
                'personalRuleActionType' => 'Choose a supported personal rule action.',
            ]);
        }

        $targetFolder = EmailFolder::query()
            ->whereKey((int) ($payload['target_folder_id'] ?? 0))
            ->where('account_id', $placement->account_id)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->first();

        if (! $targetFolder) {
            throw ValidationException::withMessages([
                'personalRuleTargetFolderId' => 'Choose a target folder from this mailbox.',
            ]);
        }

        if ((int) $targetFolder->id === (int) $placement->email_folder_id) {
            throw ValidationException::withMessages([
                'personalRuleTargetFolderId' => 'Choose a different folder for the personal rule.',
            ]);
        }

        return [
            'type' => self::ACTION_MOVE_TO_FOLDER,
            'value' => $targetFolder->path,
            'target_folder_id' => $targetFolder->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $condition
     * @param  array<string, mixed>  $action
     */
    private function ruleName(array $payload, array $condition, array $action): string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name !== '') {
            return Str::limit($name, 255, '');
        }

        $actionLabel = ($action['type'] ?? '') === self::ACTION_ARCHIVE
            ? 'Archive'
            : 'Move to '.($action['value'] ?? 'folder');

        return Str::limit($actionLabel.' when '.$condition['field'].' matches '.$condition['value'], 255, '');
    }
}
