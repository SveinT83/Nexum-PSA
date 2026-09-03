<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Support\EmailProviderIdlePresenceLease;
use App\Modules\Integration\Actions\ActivateEmailProviderCredential;
use App\Modules\Integration\Actions\ActivateLegacyEmailProviderMigrationItem;
use App\Modules\Integration\Actions\ApplyEmailProviderCutover;
use App\Modules\Integration\Actions\CreateEmailProviderConnection;
use App\Modules\Integration\Actions\PauseEmailProviderAccountRuntime;
use App\Modules\Integration\Actions\PreviewEmailProviderCutover;
use App\Modules\Integration\Actions\PreviewLegacyEmailProviderMigration;
use App\Modules\Integration\Actions\RollbackEmailProviderCutover;
use App\Modules\Integration\Actions\StageLegacyEmailProviderMigration;
use App\Modules\Integration\Actions\VerifyEmailProviderCredential;
use App\Modules\Integration\Actions\VerifyLegacyEmailProviderMigrationItem;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Models\EmailProviderMigrationRun;
use App\Modules\Integration\Services\EmailProviderConnectionVerifier;
use App\Modules\Integration\Services\EmailProviderLegacyPurgeReadiness;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use App\Modules\Integration\Support\EmailProviderRuntimeCredentials;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailProviderLegacyMigrationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private LegacyMigrationFakeVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach ([
            EmailProviderManagementAuthorization::MANAGE_PERMISSION,
            EmailProviderManagementAuthorization::PRIVATE_ENDPOINT_PERMISSION,
            EmailProviderManagementAuthorization::MAILBOX_SYNC_PERMISSION,
            EmailProviderManagementAuthorization::EMAIL_ACCOUNT_PERMISSION,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->operator->givePermissionTo([
            EmailProviderManagementAuthorization::MANAGE_PERMISSION,
            EmailProviderManagementAuthorization::MAILBOX_SYNC_PERMISSION,
            EmailProviderManagementAuthorization::EMAIL_ACCOUNT_PERMISSION,
        ]);
        $this->verifier = new LegacyMigrationFakeVerifier;
        $this->app->instance(EmailProviderConnectionVerifier::class, $this->verifier);
    }

    #[Test]
    public function preview_stage_verify_activate_cutover_and_rollback_are_exact_local_and_crash_resumable(): void
    {
        $account = $this->legacyAccount();
        $preview = app(PreviewLegacyEmailProviderMigration::class)->execute($this->operator, [$account->id]);

        $this->assertSame('previewed', $preview->status);
        $this->assertSame('ready', $preview->items->sole()->status);
        $this->assertDatabaseCount('integration_email_provider_connections', 0);
        $this->assertSame(0, $this->verifier->calls);

        $staging = app(StageLegacyEmailProviderMigration::class)->execute($this->operator, $preview);
        $item = $staging->items->sole();
        $connection = EmailProviderConnection::query()->findOrFail($item->provider_integration_id);
        $credential = EmailProviderCredentialVersion::query()->findOrFail($item->credential_version_id);
        $this->assertSame('staged', $staging->status);
        $this->assertSame('staged', $item->status);
        $this->assertSame('password', $connection->imap_auth_type, 'Legacy plain is canonicalized to password semantics.');
        $this->assertSame('password', $connection->smtp_auth_type, 'Legacy login is canonicalized to password semantics.');
        $this->assertSame(0, $this->verifier->calls, 'Staging is local-only and cannot call the provider.');

        // Simulate a crash after credential verification committed but before
        // the migration item transition. Wrapper retry must reconcile locally.
        app(VerifyEmailProviderCredential::class)->execute($this->operator, $connection, $credential);
        $this->assertSame('staged', $item->fresh()->status);
        $item = app(VerifyLegacyEmailProviderMigrationItem::class)->execute($this->operator, $item->fresh());
        $this->assertSame('verified', $item->status);
        $this->assertSame(1, $this->verifier->calls);

        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        try {
            app(VerifyLegacyEmailProviderMigrationItem::class)->execute($unauthorized, $item);
            $this->fail('Terminal/idempotent verification paths must still authorize first.');
        } catch (AuthorizationException) {
            $this->assertSame('verified', $item->fresh()->status);
        }

        // Simulate the matching activation crash boundary and reconcile it
        // without a second activation/provider operation.
        app(ActivateEmailProviderCredential::class)->execute(
            $this->operator,
            $connection->fresh(),
            $credential->fresh(),
        );
        $this->assertSame('verified', $item->fresh()->status);
        $item = app(ActivateLegacyEmailProviderMigrationItem::class)->execute($this->operator, $item->fresh());
        $this->assertSame('active', $item->status);
        $this->assertSame(1, $this->verifier->calls);

        app(PauseEmailProviderAccountRuntime::class)->execute(
            $this->operator,
            $account,
            'verified_provider_cutover',
        );
        $cutoverPreview = app(PreviewEmailProviderCutover::class)->execute(
            $this->operator,
            $staging->fresh(),
        );
        $this->assertSame($staging->id, $cutoverPreview->source_run_id);
        $this->assertSame('ready', $cutoverPreview->items->sole()->status);
        $this->assertSame(1, $this->verifier->calls);

        $cutover = app(ApplyEmailProviderCutover::class)->execute($this->operator, $cutoverPreview);
        $account = $account->fresh();
        $this->assertSame('integration', $account->provider_credential_source);
        $this->assertSame($connection->getKey(), $account->provider_integration_id);
        $this->assertSame(2, (int) $account->provider_binding_version);
        $this->assertSame('applied', $cutover->status);
        $this->assertSame(1, $this->verifier->calls, 'Cutover is a local binding mutation only.');

        $purge = app(EmailProviderLegacyPurgeReadiness::class)->evaluate($account);
        $this->assertFalse($purge['ready']);
        $this->assertContains('rollback_window_open', $purge['block_codes']);
        $this->assertContains('named_human_review_required', $purge['block_codes']);
        $this->assertContains('backup_recovery_evidence_required', $purge['block_codes']);

        $rollback = app(RollbackEmailProviderCutover::class)->execute($this->operator, $cutover);
        $account = $account->fresh();
        $this->assertSame('legacy', $account->provider_credential_source);
        $this->assertNull($account->provider_integration_id);
        $this->assertSame(3, (int) $account->provider_binding_version);
        $this->assertSame($staging->id, $rollback->source_run_id);
        $this->assertSame($cutover->id, $rollback->rollback_of_run_id);
        $this->assertSame('imap-user-legacy-canary', Crypt::decryptString($account->imap_username));
        $this->assertSame('imap-secret-legacy-canary', Crypt::decryptString($account->imap_secret));
        $this->assertSame(1, $this->verifier->calls, 'Rollback is local-only and cannot call the provider.');
    }

    #[Test]
    public function mixed_public_and_private_stage_requires_per_item_superuser_authority_and_named_cidr(): void
    {
        config()->set('email_provider_security.trusted_private_cidrs.internal_mail', ['10.20.0.0/16']);
        $public = $this->legacyAccount();
        $private = $this->legacyAccount([
            'address' => 'private-migration@example.test',
            'imap_host' => '10.20.1.10',
            'smtp_host' => '10.20.1.11',
        ]);
        $preview = app(PreviewLegacyEmailProviderMigration::class)->execute(
            $this->operator,
            [$public->id, $private->id],
        );
        $trust = [
            $public->id => ['trust_mode' => 'public'],
            $private->id => [
                'trust_mode' => 'trusted_private',
                'trusted_cidr_name' => 'internal_mail',
                'private_endpoint_reason' => 'Reviewed internal mailbox path',
            ],
        ];

        try {
            app(StageLegacyEmailProviderMigration::class)->execute($this->operator, $preview, $trust);
            $this->fail('Private migration staging requires the separate private endpoint permission.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('integration_email_provider_connections', 0);
            $this->assertSame('previewed', $preview->fresh()->status);
        }

        $this->operator->givePermissionTo(EmailProviderManagementAuthorization::PRIVATE_ENDPOINT_PERMISSION);
        $staged = app(StageLegacyEmailProviderMigration::class)->execute(
            $this->operator->fresh(),
            $preview->fresh(),
            $trust,
        );
        $connections = EmailProviderConnection::query()->orderBy('integration_id')->get()->keyBy('trust_mode');
        $this->assertCount(2, $connections);
        $this->assertNotNull($connections->get('public'));
        $this->assertSame('internal_mail', $connections->get('trusted_private')?->trusted_cidr_name);
        $this->assertSame('staged', $staged->status);
        $this->assertSame(0, $this->verifier->calls);
    }

    #[Test]
    public function blocked_legacy_mailbox_can_bind_a_separately_verified_provider_and_roll_back(): void
    {
        $account = $this->legacyAccount([
            'is_active' => false,
            'imap_port' => 143,
            'imap_encryption' => 'ssl',
        ]);
        $preview = app(PreviewLegacyEmailProviderMigration::class)->execute($this->operator, [$account->id]);
        $item = $preview->items->sole();
        $this->assertSame('blocked', $item->status);
        $this->assertSame('legacy_configuration_not_supported', $item->block_code);

        $connection = $this->verifiedProvider();
        $this->assertSame(1, $this->verifier->calls);
        app(PauseEmailProviderAccountRuntime::class)->execute(
            $this->operator,
            $account,
            'provider_replacement',
        );

        $this->actingAs($this->operator)
            ->get(route('tech.admin.system.integrations.email-providers.migrations.show', $preview->public_id))
            ->assertRedirect(route('tech.admin.settings.email.accounts'))
            ->assertSessionHas('status');

        $response = $this->actingAs($this->operator)->post(route(
            'tech.admin.system.integrations.email-providers.migrations.items.rebind',
            [$preview->public_id, $item->id],
        ), [
            'provider_integration_id' => $connection->getKey(),
        ]);

        $cutover = EmailProviderMigrationRun::query()
            ->where('operation', 'cutover')
            ->where('source_run_id', $preview->id)
            ->sole();
        $response
            ->assertRedirect(route('tech.admin.system.integrations.email-providers.migrations.show', $cutover->public_id))
            ->assertSessionHas('status');

        $account = $account->fresh();
        $this->assertFalse($account->is_active);
        $this->assertSame('integration', $account->provider_credential_source);
        $this->assertSame($connection->getKey(), $account->provider_integration_id);
        $this->assertSame(2, (int) $account->provider_binding_version);
        $this->assertSame('applied', $cutover->status);
        $this->assertSame('rebound', $item->fresh()->status);
        $this->assertSame('superseded', $preview->fresh()->status);
        $this->assertSame('imap-secret-legacy-canary', Crypt::decryptString($account->imap_secret));
        $this->assertSame(1, $this->verifier->calls, 'The local binding must not call IMAP or SMTP.');

        $rollback = app(RollbackEmailProviderCutover::class)->execute($this->operator, $cutover);
        $account = $account->fresh();
        $this->assertSame('legacy', $account->provider_credential_source);
        $this->assertNull($account->provider_integration_id);
        $this->assertSame(3, (int) $account->provider_binding_version);
        $this->assertSame('applied', $rollback->status);
        $this->assertSame(1, $this->verifier->calls, 'Rollback is local-only.');
    }

    #[Test]
    public function rollback_rejects_every_unresolved_provider_work_category_idle_and_reconciliation_before_binding_flip(): void
    {
        [$account, $cutover] = $this->appliedCutover();
        $binding = (int) $account->provider_binding_version;

        $cases = [
            'remote_operation' => fn (): array => [
                'email_remote_operations',
                DB::table('email_remote_operations')->insertGetId([
                    'account_id' => $account->id,
                    'provider_binding_version' => $binding,
                    'operation_type' => 'move',
                    'status' => 'pending',
                    'idempotency_key' => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'rollback_provider_operation_unresolved',
            ],
            'provider_draft' => fn (): array => [
                'email_composer_drafts',
                DB::table('email_composer_drafts')->insertGetId([
                    'user_id' => $this->operator->id,
                    'email_account_id' => $account->id,
                    'provider_binding_version' => $binding,
                    'mode' => 'new',
                    'draft_key' => (string) Str::uuid(),
                    'status' => EmailComposerDraft::STATUS_ACTIVE,
                    'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_PENDING,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'rollback_provider_binding_work_unresolved',
            ],
            'sent_reconciliation' => function () use ($account, $binding): array {
                $logId = DB::table('email_logs')->insertGetId([
                    'direction' => 'outbound',
                    'account_id' => $account->id,
                    'level' => 'info',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'email_sent_reconciliations',
                    DB::table('email_sent_reconciliations')->insertGetId([
                        'email_log_id' => $logId,
                        'account_id' => $account->id,
                        'provider_binding_version' => $binding,
                        'rfc_message_id' => '<cutover-test@example.test>',
                        'normalized_message_id' => 'cutover-test@example.test',
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]),
                    'rollback_provider_binding_work_unresolved',
                ];
            },
            'historical_import' => fn (): array => [
                'email_historical_import_runs',
                DB::table('email_historical_import_runs')->insertGetId([
                    'account_id' => $account->id,
                    'provider_binding_version' => $binding,
                    'requested_by' => $this->operator->id,
                    'status' => 'running',
                    'date_from' => now()->subDay()->toDateString(),
                    'date_to' => now()->toDateString(),
                    'requested_cap' => 1,
                    'effective_cap' => 1,
                    'folder_scope_json' => '[]',
                    'provider_snapshot_json' => '[]',
                    'preview_fingerprint' => hash('sha256', 'historical-preview'),
                    'idempotency_key' => hash('sha256', (string) Str::uuid()),
                    'preview_expires_at' => now()->addMinute(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'rollback_provider_binding_work_unresolved',
            ],
            'cursor_rebaseline' => fn (): array => [
                'email_cursor_rebaseline_runs',
                DB::table('email_cursor_rebaseline_runs')->insertGetId([
                    'account_id' => $account->id,
                    'provider_binding_version' => $binding,
                    'requested_by' => $this->operator->id,
                    'reason' => 'cutover readiness test',
                    'status' => 'previewed',
                    'idempotency_key' => hash('sha256', (string) Str::uuid()),
                    'preview_fingerprint' => hash('sha256', 'cursor-preview'),
                    'preview_expires_at' => now()->addMinute(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'rollback_provider_binding_work_unresolved',
            ],
            'provider_inventory' => fn (): array => [
                'email_provider_inventory_runs',
                DB::table('email_provider_inventory_runs')->insertGetId([
                    'account_id' => $account->id,
                    'provider_binding_version' => $binding,
                    'status' => 'running',
                    'max_folders' => 1,
                    'max_messages_per_folder' => 1,
                    'started_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'rollback_provider_binding_work_unresolved',
            ],
            'deletion_cleanup' => fn (): array => [
                'email_provider_deletion_cleanup_attempts',
                DB::table('email_provider_deletion_cleanup_attempts')->insertGetId([
                    'account_id' => $account->id,
                    'provider_binding_version' => $binding,
                    'email_message_id' => 999999,
                    'status' => 'checking',
                    'started_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'rollback_provider_binding_work_unresolved',
            ],
        ];

        foreach ($cases as $name => $create) {
            [$table, $id, $reason] = $create();
            $this->assertSecurityReason(
                fn () => app(RollbackEmailProviderCutover::class)->execute(
                    $this->operator,
                    $cutover->fresh(),
                ),
                $reason,
                $name,
            );
            DB::table($table)->where('id', $id)->delete();
        }

        $idle = EmailProviderIdlePresenceLease::acquire($account->id, 35);
        $this->assertNotNull($idle);
        try {
            $this->assertSecurityReason(
                fn () => app(RollbackEmailProviderCutover::class)->execute($this->operator, $cutover->fresh()),
                'provider_work_not_drained',
                'idle presence',
            );
        } finally {
            $idle?->release();
        }

        $reconciliationId = DB::table('email_provider_reconciliation_runs')->insertGetId([
            'account_id' => $account->id,
            'requested_by' => $this->operator->id,
            'trigger' => 'manual',
            'status' => EmailProviderReconciliationRun::STATUS_QUEUED,
            'phase' => 'discover_start',
            'active_slot' => 1,
            'idempotency_key' => hash('sha256', (string) Str::uuid()),
            'provider_binding_version' => $binding,
            'max_folders' => 1,
            'uid_batch_size' => 1,
            'provider_time_cap_seconds' => 1,
            'normal_interval_seconds' => 60,
            'queued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSecurityReason(
            fn () => app(RollbackEmailProviderCutover::class)->execute($this->operator, $cutover->fresh()),
            'provider_reconciliation_active',
            'active reconciliation',
        );
        DB::table('email_provider_reconciliation_runs')->where('id', $reconciliationId)->delete();

        $rollback = app(RollbackEmailProviderCutover::class)->execute($this->operator, $cutover->fresh());
        $this->assertSame('applied', $rollback->status);
        $this->assertSame('legacy', $account->fresh()->provider_credential_source);
    }

    private function verifiedProvider(): EmailProviderConnection
    {
        $connection = app(CreateEmailProviderConnection::class)->execute($this->operator, [
            'name' => 'Replacement provider',
            'imap_host' => '8.8.8.8',
            'imap_port' => 993,
            'imap_transport' => 'implicit_tls',
            'imap_auth_type' => 'password',
            'imap_username' => 'replacement-imap-user',
            'imap_secret' => 'replacement-imap-secret',
            'smtp_host' => '1.1.1.1',
            'smtp_port' => 465,
            'smtp_transport' => 'implicit_tls',
            'smtp_auth_type' => 'password',
            'smtp_username' => 'replacement-smtp-user',
            'smtp_secret' => 'replacement-smtp-secret',
            'trust_mode' => 'public',
        ]);
        $credential = $connection->credentialVersions()->sole();
        app(VerifyEmailProviderCredential::class)->execute($this->operator, $connection, $credential);
        app(ActivateEmailProviderCredential::class)->execute(
            $this->operator,
            $connection->fresh(),
            $credential->fresh(),
        );

        return $connection->fresh(['activeCredentialVersion']);
    }

    /** @return array{EmailAccount, EmailProviderMigrationRun} */
    private function appliedCutover(): array
    {
        $account = $this->legacyAccount();
        $preview = app(PreviewLegacyEmailProviderMigration::class)->execute($this->operator, [$account->id]);
        $staging = app(StageLegacyEmailProviderMigration::class)->execute($this->operator, $preview);
        $item = $staging->items->sole();
        $item = app(VerifyLegacyEmailProviderMigrationItem::class)->execute($this->operator, $item);
        app(ActivateLegacyEmailProviderMigrationItem::class)->execute($this->operator, $item);
        app(PauseEmailProviderAccountRuntime::class)->execute($this->operator, $account, 'rollback_test');
        $preview = app(PreviewEmailProviderCutover::class)->execute($this->operator, $staging->fresh());
        $cutover = app(ApplyEmailProviderCutover::class)->execute($this->operator, $preview);

        return [$account->fresh(), $cutover];
    }

    /** @param array<string, mixed> $overrides */
    private function legacyAccount(array $overrides = []): EmailAccount
    {
        return EmailAccount::query()->create(array_merge([
            'address' => 'legacy-migration-'.Str::lower(Str::random(8)).'@example.test',
            'description' => 'Legacy migration test mailbox',
            'from_name' => 'Legacy Migration',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'provider_credential_source' => 'legacy',
            'provider_binding_version' => 1,
            'imap_host' => '8.8.8.8',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => Crypt::encryptString('imap-user-legacy-canary'),
            'imap_secret' => Crypt::encryptString('imap-secret-legacy-canary'),
            'imap_auth_type' => 'plain',
            'smtp_host' => '1.1.1.1',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => Crypt::encryptString('smtp-user-legacy-canary'),
            'smtp_secret' => Crypt::encryptString('smtp-secret-legacy-canary'),
            'smtp_auth_type' => 'login',
        ], $overrides));
    }

    private function assertSecurityReason(
        callable $callback,
        string $reasonCode,
        string $label = '',
    ): void {
        try {
            $callback();
            $this->fail('Expected security rejection for '.$label);
        } catch (EmailProviderSecurityException $exception) {
            $this->assertSame($reasonCode, $exception->reasonCode, $label);
            $this->assertNull($exception->getPrevious(), $label);
        }
    }
}

final class LegacyMigrationFakeVerifier extends EmailProviderConnectionVerifier
{
    public int $calls = 0;

    public function __construct() {}

    public function verify(#[\SensitiveParameter] EmailProviderRuntimeCredentials $runtime): array
    {
        $this->calls++;

        return [
            'capabilities' => [
                'imap' => true,
                'smtp' => true,
                'folder_discovery' => false,
            ],
        ];
    }
}
