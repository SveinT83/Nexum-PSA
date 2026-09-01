<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Models\Core\User;
use App\Modules\Email\Jobs\TestEmailAccountConnectionJob;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailMailboxDelegation;
use App\Modules\Email\Services\EmailLiveAuthorityCoordinator;
use App\Modules\Email\Services\EmailOrdinaryMailboxEntitlementResolver;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Services\EmailProviderEndpointPolicy;
use App\Modules\Integration\Services\EmailProviderLifecycleAccountLocks;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use App\Modules\Integration\Services\EmailProviderRuntimeFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountsController extends Controller
{
    public function __construct(
        private readonly EmailUnreadAccessEpochService $unreadEpochs,
        private readonly EmailOrdinaryMailboxEntitlementResolver $ordinaryEntitlements,
        private readonly EmailProviderManagementAuthorization $providerAuthorization,
        private readonly EmailProviderLifecycleAccountLocks $providerLifecycleLocks,
        private readonly EmailProviderRuntimeFactory $providerRuntime,
        private readonly EmailProviderEndpointPolicy $endpointPolicy,
        private readonly EmailLiveAuthorityCoordinator $liveAuthority,
    ) {}

    public function index(Request $request)
    {
        $accounts = EmailAccount::query()
            ->with(['owner', 'userGrants.user', 'folders'])
            ->orderBy('address')
            ->get();

        return view('email::Admin.Accounts.index', [
            'accounts' => $accounts,
            'canCreateAccounts' => $this->canConfigureAccounts($request->user()),
        ]);
    }

    public function create(Request $request)
    {
        $this->providerAuthorization->authorizeAccountConfiguration($request->user());

        return view('email::Admin.Accounts.create', [
            'users' => $this->grantableUsers(),
        ]);
    }

    public function store(Request $request)
    {
        $actor = $this->providerAuthorization->authorizeAccountConfiguration($request->user());
        $data = $this->validateData($request);
        $requestedActive = (bool) $data['is_active'];
        $grants = $this->grantData($data['grants'] ?? []);
        unset($data['grants']);
        $data = $this->prepareConnectionData($data, null, $actor);
        $data['is_active'] = false;
        $data['last_test_result'] = 'Testing';
        $data['last_test_at'] = null;
        $data['last_error_code'] = null;
        $data['last_error_message'] = null;

        $account = DB::transaction(function () use ($actor, $data, $grants): EmailAccount {
            $account = EmailAccount::query()->create($data);
            $account = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
            $users = $this->affectedViewUsers($account, $grants, $account->owner_id);
            $generation = $this->liveAuthority->prepareAccountMutation(
                $account,
                $users->pluck('id')->all(),
            );
            $this->syncGrants($account, $grants, $actor, $generation);
            $this->reconcileUnreadEpochs(
                $account->fresh(),
                $users,
                $users->mapWithKeys(fn (User $user): array => [$user->id => false])->all(),
                $actor,
            );

            return $account->fresh();
        }, 3);

        TestEmailAccountConnectionJob::dispatch(
            (int) $account->id,
            (int) $account->provider_binding_version,
            $requestedActive,
        )->afterCommit();

        return redirect()
            ->route('tech.admin.settings.email.accounts.edit', $account)
            ->with('status', 'Saved. Nexum is testing incoming and outgoing mail now.');
    }

    public function edit(EmailAccount $account, Request $request)
    {
        $account->load(['owner', 'userGrants.user']);
        $this->providerAuthorization->authorizeAccountConfiguration($request->user());

        return view('email::Admin.Accounts.create', [
            'account' => $account,
            'users' => $this->grantableUsers(),
        ]);
    }

    public function update(EmailAccount $account, Request $request)
    {
        $actor = $this->providerAuthorization->authorizeAccountConfiguration($request->user());
        $data = $this->validateData($request, $account->id);
        $requestedActive = (bool) $data['is_active'];
        $grants = $this->grantData($data['grants'] ?? []);
        unset($data['grants']);
        $data = $this->prepareConnectionData($data, $account, $actor);
        $locks = [];

        if ($account->usesIntegrationProvider() && $account->provider_integration_id) {
            $connection = $account->providerConnection()->first();
            if ($connection) {
                $this->providerAuthorization->authorizeConnectionTrust($actor, $connection);
                $locks = $this->providerLifecycleLocks->acquire((string) $connection->getKey());
            }
        }

        try {
            $account = DB::transaction(function () use ($account, $actor, $data, $grants): EmailAccount {
                $lockedAccount = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
                $data['provider_binding_version'] = max(
                    1,
                    (int) $lockedAccount->provider_binding_version + 1,
                );
                $data['is_active'] = false;
                $data['last_test_result'] = 'Testing';
                $data['last_test_at'] = null;
                $data['last_error_code'] = null;
                $data['last_error_message'] = null;
                $data['last_successful_fetch_at'] = null;
                $data['last_successful_send_at'] = null;

                $users = $this->affectedViewUsers(
                    $lockedAccount,
                    $grants,
                    isset($data['owner_id']) ? (int) $data['owner_id'] : null,
                );
                $before = $users->mapWithKeys(fn (User $user): array => [
                    $user->id => $this->unreadEpochs->captureEntitlement($lockedAccount, $user),
                ])->all();
                $nextOwnerId = isset($data['owner_id']) ? (int) $data['owner_id'] : null;
                $ownerChanged = (int) ($lockedAccount->owner_id ?? 0) !== (int) ($nextOwnerId ?? 0);
                $generation = $this->liveAuthority->prepareAccountMutation(
                    $lockedAccount,
                    $users->pluck('id')->all(),
                    $nextOwnerId,
                    $ownerChanged,
                );
                if ($ownerChanged) {
                    $data['email_live_owner_enable_generation'] = $lockedAccount->email_live_owner_enable_generation;
                }
                $lockedAccount->forceFill($data)->save();
                $this->syncGrants($lockedAccount->fresh(), $grants, $actor, $generation);
                $this->reconcileUnreadEpochs(
                    $lockedAccount->fresh(),
                    $users,
                    $before,
                    $actor,
                );

                return $lockedAccount->fresh();
            }, 3);
        } finally {
            $this->providerLifecycleLocks->release($locks);
        }

        TestEmailAccountConnectionJob::dispatch(
            (int) $account->id,
            (int) $account->provider_binding_version,
            $requestedActive,
        )->afterCommit();

        return redirect()
            ->route('tech.admin.settings.email.accounts.edit', $account)
            ->with('status', 'Saved. Nexum is testing incoming and outgoing mail now.');
    }

    public function toggleActive(EmailAccount $account, Request $request)
    {
        $actor = $this->providerAuthorization->authorizeAccountConfiguration($request->user());
        $connection = $account->provider_credential_source === 'integration'
            ? $account->providerConnection()->firstOrFail()
            : null;
        $locks = [];

        if ($connection) {
            $actor = $this->providerAuthorization->authorizeConnectionTrust($actor, $connection);
            $locks = $this->providerLifecycleLocks->acquire((string) $connection->getKey());
        }

        // Account enablement gates runtime access, but it is not an ordinary
        // entitlement source and therefore must not manufacture a new epoch.
        try {
            DB::transaction(function () use ($account, $actor, $connection): void {
                $lockedAccount = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
                if (! $connection
                    && ! $lockedAccount->is_active
                    && $lockedAccount->last_test_result !== 'OK') {
                    throw ValidationException::withMessages([
                        'is_active' => 'Save and pass the incoming and outgoing connection test before activation.',
                    ]);
                }

                if ($connection && ! $lockedAccount->is_active) {
                    $lockedConnection = EmailProviderConnection::query()
                        ->lockForUpdate()
                        ->findOrFail($connection->getKey());
                    $this->providerAuthorization->authorizeConnectionTrust($actor, $lockedConnection);
                    $activeCredential = $lockedConnection->active_credential_version_id
                        ? EmailProviderCredentialVersion::query()
                            ->lockForUpdate()
                            ->find($lockedConnection->active_credential_version_id)
                        : null;

                    if (! $this->providerRuntime->databaseReadySnapshot($lockedConnection, $activeCredential)) {
                        throw ValidationException::withMessages([
                            'is_active' => 'The Email provider must remain active and exactly verified.',
                        ]);
                    }
                }

                $affectedUserIds = $this->affectedViewUsers(
                    $lockedAccount,
                    $lockedAccount->userGrants->map(fn (EmailAccountUserGrant $grant): array => [
                        'user_id' => $grant->user_id,
                    ])->all(),
                    $lockedAccount->owner_id,
                )->pluck('id')->all();
                $this->liveAuthority->prepareAccountMutation($lockedAccount, $affectedUserIds);
                $lockedAccount->is_active = ! $lockedAccount->is_active;
                $lockedAccount->save();
            }, 3);
        } finally {
            $this->providerLifecycleLocks->release($locks);
        }

        return back();
    }

    protected function validateData(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'address' => 'required|email|unique:email_accounts,address,'.($id ?? 'NULL').',id',
            'description' => 'nullable|string',
            'from_name' => 'nullable|string',
            'account_kind' => ['required', 'string', Rule::in(array_keys(EmailAccount::KINDS))],
            'owner_id' => ['nullable', 'integer', 'exists:user_management,id'],
            'is_active' => 'sometimes|boolean',
            'is_global_default' => 'sometimes|boolean',
            'defaults_for' => 'nullable|array',
            'defaults_for.*' => 'string|in:'.implode(',', array_keys(EmailAccount::DEFAULT_SCOPES)),
            'ticket_ingress_enabled' => 'sometimes|boolean',
            'delete_policy' => 'required|in:local_only,sync_delete,auto_delete,legacy_default',
            'imap_host' => ['required', 'string', 'max:253'],
            'imap_port' => ['required', 'integer', Rule::in([143, 993])],
            'imap_encryption' => ['required', Rule::in(['implicit_tls', 'starttls'])],
            'imap_username' => ['required', 'string', 'max:320'],
            'imap_secret' => [$id ? 'nullable' : 'required', 'string', 'max:4096'],
            'smtp_host' => ['required', 'string', 'max:253'],
            'smtp_port' => ['required', 'integer', Rule::in([465, 587])],
            'smtp_encryption' => ['required', Rule::in(['implicit_tls', 'starttls'])],
            'smtp_username' => ['required', 'string', 'max:320'],
            'smtp_secret' => [$id ? 'nullable' : 'required', 'string', 'max:4096'],
            'grants' => 'nullable|array',
            'grants.*.user_id' => 'required|integer|exists:user_management,id',
            'grants.*.can_view' => 'nullable|boolean',
            'grants.*.can_organize' => 'nullable|boolean',
            'grants.*.can_send' => 'nullable|boolean',
        ]);

        if (($data['account_kind'] ?? EmailAccount::KIND_SHARED) === EmailAccount::KIND_PERSONAL && empty($data['owner_id'])) {
            throw ValidationException::withMessages([
                'owner_id' => 'Personal email accounts must have one owner.',
            ]);
        }

        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['is_global_default'] = (bool) ($data['is_global_default'] ?? false);
        $data['ticket_ingress_enabled'] = (bool) ($data['ticket_ingress_enabled'] ?? false);
        $data['defaults_for'] = array_values($data['defaults_for'] ?? []);
        $data['owner_id'] = $data['owner_id'] ?? null;

        if ($data['account_kind'] === EmailAccount::KIND_PERSONAL) {
            $data['ticket_ingress_enabled'] = false;
            $data['is_global_default'] = false;
            $data['defaults_for'] = [];
        }

        return $data;
    }

    public function test(EmailAccount $account, Request $request)
    {
        $this->providerAuthorization->authorizeAccountConfiguration($request->user());

        if (! in_array((string) $account->provider_credential_source, ['account', 'legacy'], true)) {
            return redirect()
                ->route('tech.admin.settings.email.accounts.edit', $account)
                ->with('error', 'Enter the IMAP and SMTP settings on this account, then save and test it.');
        }

        $account = DB::transaction(function () use ($account): EmailAccount {
            $locked = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
            $locked->forceFill([
                'provider_binding_version' => max(1, (int) $locked->provider_binding_version + 1),
                'is_active' => false,
                'last_test_result' => 'Testing',
                'last_test_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            return $locked->fresh();
        }, 3);

        TestEmailAccountConnectionJob::dispatch(
            (int) $account->id,
            (int) $account->provider_binding_version,
            true,
        )->afterCommit();

        return redirect()
            ->route('tech.admin.settings.email.accounts.edit', $account)
            ->with('status', 'Nexum is testing incoming and outgoing mail now.');
    }

    private function grantableUsers()
    {
        $query = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->orderBy('email');

        if (Schema::hasColumn((new User)->getTable(), 'is_system_actor')) {
            $query->where('is_system_actor', false);
        }

        return $query->get(['id', 'name', 'email']);
    }

    private function canConfigureAccounts(User $actor): bool
    {
        try {
            $this->providerAuthorization->authorizeAccountConfiguration($actor);
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    /**
     * Normalize and encrypt one account-owned IMAP/SMTP configuration.
     *
     * Password fields are write-only. Blank edit values preserve an existing
     * account-owned ciphertext, while a provider-bound account must be
     * re-entered completely before it can leave the old internal binding.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareConnectionData(
        array $data,
        ?EmailAccount $account,
        User $actor,
    ): array {
        try {
            $imap = $this->endpointPolicy->normalize(
                'imap',
                (string) $data['imap_host'],
                (int) $data['imap_port'],
                (string) $data['imap_encryption'],
            );
            $smtp = $this->endpointPolicy->normalize(
                'smtp',
                (string) $data['smtp_host'],
                (int) $data['smtp_port'],
                (string) $data['smtp_encryption'],
            );
        } catch (EmailProviderSecurityException) {
            throw ValidationException::withMessages([
                'imap_host' => 'Use a valid public IMAP host with port 993 TLS or port 143 STARTTLS.',
                'smtp_host' => 'Use a valid public SMTP host with port 465 TLS or port 587 STARTTLS.',
            ]);
        }

        foreach (['imap', 'smtp'] as $protocol) {
            $key = $protocol.'_secret';
            $replacement = (string) ($data[$key] ?? '');

            if ($replacement === '') {
                if (! $account
                    || ! in_array((string) ($account->provider_credential_source ?: 'legacy'), ['account', 'legacy'], true)
                    || blank($account->getAttribute($key))) {
                    throw ValidationException::withMessages([
                        $key => 'Enter the '.$protocol.' password.',
                    ]);
                }

                $data[$key] = $account->getAttribute($key);
            } else {
                $data[$key] = Crypt::encryptString($replacement);
            }
        }

        $data['imap_host'] = $imap->host();
        $data['imap_port'] = $imap->port();
        $data['imap_encryption'] = $imap->transport();
        $data['imap_auth_type'] = 'password';
        $data['smtp_host'] = $smtp->host();
        $data['smtp_port'] = $smtp->port();
        $data['smtp_encryption'] = $smtp->transport();
        $data['smtp_auth_type'] = 'password';
        $data['provider_integration_id'] = null;
        $data['provider_credential_source'] = 'account';
        $data['provider_binding_version'] = $account
            ? max(1, (int) $account->provider_binding_version + 1)
            : 1;
        $data['provider_bound_at'] = now();
        $data['provider_bound_by'] = $actor->id;
        $data['provider_runtime_paused_at'] = null;
        $data['provider_runtime_drained_at'] = null;

        return $data;
    }

    private function grantData(array $grants): array
    {
        return collect($grants)
            ->map(fn (array $grant): array => [
                'user_id' => (int) $grant['user_id'],
                'can_view' => (bool) ($grant['can_view'] ?? false),
                'can_organize' => (bool) ($grant['can_organize'] ?? false),
                'can_send' => (bool) ($grant['can_send'] ?? false),
            ])
            ->filter(fn (array $grant): bool => $grant['can_view'] || $grant['can_organize'] || $grant['can_send'])
            ->keyBy('user_id')
            ->values()
            ->all();
    }

    private function syncGrants(
        EmailAccount $account,
        array $grants,
        ?User $actor,
        int $generation,
    ): void {
        $desired = $account->isPersonal() ? collect() : collect($grants)->keyBy('user_id');

        // Authority rows are retained and disabled instead of deleted so a
        // frozen publication can prove that a prior path no longer qualifies.
        $account->userGrants()->lockForUpdate()->get()->each(
            function (EmailAccountUserGrant $existing) use ($desired, $generation): void {
                if ($desired->has((int) $existing->user_id)) {
                    return;
                }

                $existing->forceFill([
                    'can_view' => false,
                    'can_organize' => false,
                    'can_send' => false,
                    'email_live_enable_generation' => $generation,
                ])->save();
            },
        );

        foreach ($desired as $grant) {
            $existing = EmailAccountUserGrant::query()
                ->where('email_account_id', $account->id)
                ->where('user_id', $grant['user_id'])
                ->lockForUpdate()
                ->first();
            $values = [
                'can_view' => $grant['can_view'],
                'can_organize' => $grant['can_organize'],
                'can_send' => $grant['can_send'],
                'granted_by' => $actor?->id,
                'granted_at' => now(),
                'email_live_enable_generation' => $generation,
            ];

            if ($existing) {
                $existing->forceFill($values)->save();
            } else {
                EmailAccountUserGrant::query()->forceCreate([
                    'email_account_id' => $account->id,
                    'user_id' => $grant['user_id'],
                    ...$values,
                ]);
            }
        }
    }

    /**
     * Resolve the complete before/after user union, including delegations that
     * can lose authority when account kind or owner changes.
     *
     * @param  array<int, array<string, mixed>>  $grants
     * @return Collection<int, User>
     */
    private function affectedViewUsers(
        EmailAccount $account,
        array $grants,
        ?int $nextOwnerId,
    ): Collection {
        $userIds = collect([$account->owner_id, $nextOwnerId])
            ->merge($account->userGrants()->pluck('user_id'))
            ->merge(collect($grants)->pluck('user_id'));

        if (Schema::hasTable('email_mailbox_delegations')) {
            $userIds = $userIds->merge(
                EmailMailboxDelegation::query()
                    ->where('email_account_id', $account->id)
                    ->pluck('delegate_id'),
            );
        }

        return User::query()
            ->whereIn('id', $userIds->filter()->map(fn (mixed $id): int => (int) $id)->unique())
            ->orderBy('id')
            ->get();
    }

    /**
     * Apply transitions after the account/grant mutation while the caller
     * still holds the account row lock. Overlapping sources remain one epoch.
     *
     * @param  Collection<int, User>  $users
     * @param  array<int, bool>  $before
     */
    private function reconcileUnreadEpochs(
        EmailAccount $account,
        Collection $users,
        array $before,
        ?User $actor,
    ): void {
        foreach ($users as $user) {
            $hasCurrentSource = $this->unreadEpochs->captureEntitlement($account, $user);
            $description = $hasCurrentSource
                ? $this->ordinaryEntitlements->describeCurrentSources($account, $user)
                : null;

            $this->unreadEpochs->reconcileAfterMutation(
                account: $account,
                user: $user,
                wasEntitled: (bool) ($before[$user->id] ?? false),
                source: $description['source'] ?? 'account_access_update',
                sourceReference: $description['reference'] ?? null,
                actor: $actor,
            );
        }
    }
}
