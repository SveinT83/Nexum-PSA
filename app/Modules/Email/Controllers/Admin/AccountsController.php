<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailMailboxDelegation;
use App\Modules\Email\Services\EmailLiveAuthorityCoordinator;
use App\Modules\Email\Services\EmailOrdinaryMailboxEntitlementResolver;
use App\Modules\Email\Services\EmailTestService;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Services\EmailProviderLifecycleAccountLocks;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use App\Modules\Integration\Services\EmailProviderRuntimeFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
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
        private readonly EmailLiveAuthorityCoordinator $liveAuthority,
    ) {}

    public function index()
    {
        $accounts = EmailAccount::query()
            ->with(['owner', 'userGrants.user', 'folders', 'providerConnection.integration', 'providerConnection.activeCredentialVersion'])
            ->orderBy('address')
            ->get();

        return view('email::Admin.Accounts.index', compact('accounts'));
    }

    public function create(Request $request)
    {
        $actor = $this->providerAuthorization->authorizeBinding($request->user());

        return view('email::Admin.Accounts.create', [
            'users' => $this->grantableUsers(),
            'providers' => $this->availableProviders($actor),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $grants = $this->grantData($data['grants'] ?? []);
        unset($data['grants']);

        $actor = $this->providerAuthorization->authorizeBinding($request->user());
        $connection = EmailProviderConnection::query()->findOrFail($data['provider_integration_id']);
        $actor = $this->providerAuthorization->authorizeConnectionTrust($actor, $connection);
        $providerLocks = $this->providerLifecycleLocks->acquire((string) $connection->getKey());
        $data = array_merge($data, [
            'provider_credential_source' => 'integration',
            'provider_binding_version' => 1,
            'provider_bound_at' => now(),
            'provider_bound_by' => $actor->id,
            // New Email accounts never duplicate endpoint or credential data.
            'imap_host' => null,
            'imap_port' => null,
            'imap_encryption' => null,
            'imap_username' => null,
            'imap_secret' => null,
            'imap_auth_type' => null,
            'smtp_host' => null,
            'smtp_port' => null,
            'smtp_encryption' => null,
            'smtp_username' => null,
            'smtp_secret' => null,
            'smtp_auth_type' => null,
        ]);
        try {
            DB::transaction(function () use ($actor, $connection, $data, $grants): void {
                $lockedConnection = EmailProviderConnection::query()
                    ->lockForUpdate()
                    ->findOrFail($connection->getKey());
                $actor = $this->providerAuthorization->authorizeConnectionTrust($actor, $lockedConnection);
                $activeCredential = $lockedConnection->active_credential_version_id
                    ? EmailProviderCredentialVersion::query()
                        ->lockForUpdate()
                        ->find($lockedConnection->active_credential_version_id)
                    : null;

                if (! $this->providerRuntime->databaseReadySnapshot($lockedConnection, $activeCredential)) {
                    throw ValidationException::withMessages([
                        'provider_integration_id' => 'Select an active, exactly verified Email provider.',
                    ]);
                }

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
            }, 3);
        } finally {
            $this->providerLifecycleLocks->release($providerLocks);
        }

        return redirect()->route('tech.admin.settings.email.accounts');
    }

    public function edit(EmailAccount $account, Request $request)
    {
        $account->load(['owner', 'userGrants.user', 'providerConnection.integration', 'providerConnection.activeCredentialVersion']);
        $actor = $this->providerAuthorization->authorizeBinding($request->user());

        return view('email::Admin.Accounts.create', [
            'account' => $account,
            'users' => $this->grantableUsers(),
            'providers' => $this->availableProviders($actor),
        ]);
    }

    public function update(EmailAccount $account, Request $request)
    {
        $data = $this->validateData($request, $account->id);
        $grants = $this->grantData($data['grants'] ?? []);
        unset($data['grants']);
        $actor = $this->providerAuthorization->authorizeBinding($request->user());
        $connection = $account->provider_credential_source === 'integration'
            ? $account->providerConnection()->firstOrFail()
            : null;
        $locks = [];

        if ($connection) {
            $actor = $this->providerAuthorization->authorizeConnectionTrust($actor, $connection);
            $locks = $this->providerLifecycleLocks->acquire((string) $connection->getKey());
        }

        try {
            DB::transaction(function () use ($account, $actor, $connection, $data, $grants): void {
                $lockedAccount = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
                if ($connection) {
                    $lockedConnection = EmailProviderConnection::query()
                        ->lockForUpdate()
                        ->findOrFail($connection->getKey());
                    $actor = $this->providerAuthorization->authorizeConnectionTrust($actor, $lockedConnection);
                    $activeCredential = $lockedConnection->active_credential_version_id
                        ? EmailProviderCredentialVersion::query()
                            ->lockForUpdate()
                            ->find($lockedConnection->active_credential_version_id)
                        : null;

                    if (($data['is_active'] ?? false)
                        && ! $this->providerRuntime->databaseReadySnapshot($lockedConnection, $activeCredential)) {
                        throw ValidationException::withMessages([
                            'is_active' => 'The Email provider must remain active and exactly verified.',
                        ]);
                    }
                }

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
            }, 3);
        } finally {
            $this->providerLifecycleLocks->release($locks);
        }

        return redirect()->route('tech.admin.settings.email.accounts');
    }

    public function toggleActive(EmailAccount $account, Request $request)
    {
        $actor = $this->providerAuthorization->authorizeBinding($request->user());
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
            'provider_integration_id' => $id
                ? ['prohibited']
                : ['required', 'uuid', 'exists:integration_email_provider_connections,integration_id'],
            // Integration is the sole endpoint and credential writer.
            'imap_host' => ['prohibited'],
            'imap_port' => ['prohibited'],
            'imap_encryption' => ['prohibited'],
            'imap_username' => ['prohibited'],
            'imap_secret' => ['prohibited'],
            'imap_auth_type' => ['prohibited'],
            'smtp_host' => ['prohibited'],
            'smtp_port' => ['prohibited'],
            'smtp_encryption' => ['prohibited'],
            'smtp_username' => ['prohibited'],
            'smtp_secret' => ['prohibited'],
            'smtp_auth_type' => ['prohibited'],
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

    public function test(EmailAccount $account, EmailTestService $tester, Request $request)
    {
        $actor = $this->providerAuthorization->authorizeBinding($request->user());
        if ($account->provider_credential_source === 'integration') {
            $connection = $account->providerConnection()->firstOrFail();
            $this->providerAuthorization->authorizeConnectionTrust($actor, $connection);
        }

        $result = $tester->run($account);

        return Redirect::back()->with('email_test', [
            'overall' => $result->overall(),
            'imap_ok' => $result->imap_ok,
            'imap_ms' => round($result->imap_ms, 1),
            'imap_error' => $result->imap_error_message,
            'smtp_ok' => $result->smtp_ok,
            'smtp_ms' => round($result->smtp_ms, 1),
            'smtp_error' => $result->smtp_error_message,
        ]);
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

    private function availableProviders(User $actor): Collection
    {
        return EmailProviderConnection::query()
            ->with(['integration', 'activeCredentialVersion'])
            ->where('status', 'active')
            ->orderBy('integration_id')
            ->get()
            ->filter(function (EmailProviderConnection $connection) use ($actor): bool {
                try {
                    $this->providerAuthorization->authorizeConnectionTrust($actor, $connection);
                } catch (AuthorizationException) {
                    return false;
                }

                return $this->providerRuntime->databaseReadySnapshot(
                    $connection,
                    $connection->activeCredentialVersion,
                );
            })
            ->values();
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
