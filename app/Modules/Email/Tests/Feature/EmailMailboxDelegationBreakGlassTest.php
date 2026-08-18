<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\ActivateEmailBreakGlassAccess;
use App\Modules\Email\Actions\CreateEmailMailboxDelegation;
use App\Modules\Email\Actions\RevokeEmailBreakGlassAccess;
use App\Modules\Email\Actions\RevokeEmailMailboxDelegation;
use App\Modules\Email\Livewire\Tech\MailWorkspace;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxAccessEvent;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailMailboxAccessEventRecorder;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\MailboxAccessDecision;
use App\Modules\Email\Services\MailboxAccessUseGuard;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use App\Modules\Notification\Actions\DispatchMailboxBreakGlassActivationNotice;
use App\Modules\Notification\Jobs\DispatchMailboxBreakGlassActivationNotification;
use App\Modules\Notification\Notifications\MailboxBreakGlassActivatedNotification;
use App\Modules\Ticket\Models\Ticket;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailMailboxDelegationBreakGlassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-16 10:00:00 UTC');
        Queue::fake();

        foreach ([
            'email.inbox_view',
            'email.inbox_manage',
            'email.account_manage',
            'email.break_glass_activate',
            'email.break_glass_audit',
            'email.raw_source_view',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function schema_uses_datetime_instants_and_contains_only_metadata_audit_fields(): void
    {
        foreach ([
            'email_mailbox_delegations' => [
                'starts_at', 'expires_at', 'revoked_at', 'created_at', 'updated_at',
            ],
            'email_break_glass_accesses' => [
                'starts_at', 'expires_at', 'revoked_at', 'owner_notification_sent_at',
                'security_notification_sent_at', 'created_at', 'updated_at',
            ],
            'email_mailbox_access_events' => ['occurred_at'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame('datetime', Schema::getColumnType($table, $column), "{$table}.{$column}");
            }
        }

        foreach ([
            'subject', 'sender', 'recipient', 'filename', 'snippet', 'body', 'raw_source',
            'search_term', 'credential', 'provider_response', 'attachment_bytes', 'ai_output',
        ] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn('email_mailbox_access_events', $forbiddenColumn));
        }
    }

    #[Test]
    public function break_glass_permissions_are_explicit_and_not_default_admin_or_technician_authority(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $superuser = $this->user();
        $superuser->assignRole(Role::findByName('Superuser'));
        $admin = $this->user();
        $admin->assignRole(Role::findByName('Admin'));
        $technician = $this->user();
        $technician->assignRole(Role::findByName('Tech'));

        foreach ([
            'email.break_glass_activate',
            'email.break_glass_audit',
            'email.raw_source_view',
        ] as $permission) {
            $this->assertTrue($superuser->can($permission), $permission);
            $this->assertFalse($admin->can($permission), $permission);
            $this->assertFalse($technician->can($permission), $permission);
        }
    }

    #[Test]
    public function personal_owner_can_create_exact_bounded_delegation_and_admin_configuration_never_grants_content(): void
    {
        $owner = $this->user([
            'email.inbox_view', 'email.inbox_manage', 'email.raw_source_view',
        ]);
        $delegate = $this->user([
            'email.inbox_view', 'email.inbox_manage', 'email.raw_source_view',
        ]);
        $admin = $this->user([
            'email.inbox_view', 'email.inbox_manage', 'email.account_manage',
        ]);
        $account = $this->personalAccount($owner, 'owner-delegation@example.test');
        $reason = 'Owner-approved holiday cover; INTERNAL SECRET REASON.';

        $delegation = app(CreateEmailMailboxDelegation::class)->handle(
            $account,
            $owner,
            $delegate,
            [
                'can_view' => true,
                'can_organize' => true,
                'can_send' => true,
                'can_view_raw_source' => true,
                'reason' => $reason,
                'starts_at' => now(),
                'expires_at' => now()->addDays(31),
            ],
        );

        $resolver = app(ResolveMailboxAccessDecision::class);
        foreach ([
            MailboxAccess::VIEW,
            MailboxAccess::ORGANIZE,
            MailboxAccess::SEND,
            ResolveMailboxAccessDecision::CONTENT_VIEW,
            ResolveMailboxAccessDecision::SEARCH,
            ResolveMailboxAccessDecision::ATTACHMENT_DOWNLOAD,
            ResolveMailboxAccessDecision::RAW_SOURCE,
        ] as $operation) {
            $decision = $resolver->resolve($delegate, $account, $operation);
            $this->assertTrue($decision->allowed, $operation);
            $this->assertSame(MailboxAccessDecision::SOURCE_DELEGATION, $decision->source);
            $this->assertSame($delegation->id, $decision->delegationId);
        }

        foreach ([MailboxAccess::VIEW, ResolveMailboxAccessDecision::CONTENT_VIEW] as $operation) {
            $this->assertFalse($resolver->resolve($admin, $account, $operation)->allowed);
        }

        $event = EmailMailboxAccessEvent::query()->sole();
        $serializedEvent = json_encode($event->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertSame(EmailMailboxAccessEvent::TYPE_DELEGATION_CREATED, $event->event_type);
        $this->assertStringNotContainsString($reason, $serializedEvent);
        $this->assertStringNotContainsString('SECRET', $serializedEvent);
        $this->assertSame($reason, $delegation->reason);

        $this->expectException(ValidationException::class);
        app(CreateEmailMailboxDelegation::class)->handle(
            $account,
            $owner,
            $delegate,
            [
                'can_view' => true,
                'reason' => 'Overlapping renewal is a separate record only after the current window.',
                'starts_at' => now()->addDay(),
                'expires_at' => now()->addDays(2),
            ],
        );
    }

    #[Test]
    public function delegation_rejects_invalid_authority_duration_and_operations_then_revokes_immediately(): void
    {
        $owner = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $delegate = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $admin = $this->user(['email.inbox_view', 'email.inbox_manage', 'email.account_manage']);
        $account = $this->personalAccount($owner, 'delegation-validation@example.test');
        $action = app(CreateEmailMailboxDelegation::class);

        foreach ([
            [
                'can_view' => true,
                'reason' => 'Duration outside the maximum.',
                'starts_at' => now(),
                'expires_at' => now()->addDays(31)->addSecond(),
            ],
            [
                'can_organize' => true,
                'reason' => 'Organize without View is not a valid operation set.',
                'starts_at' => now(),
                'expires_at' => now()->addDay(),
            ],
            [
                'can_view' => true,
                'can_view_raw_source' => true,
                'reason' => 'Owner cannot delegate raw source without holding the ability.',
                'starts_at' => now(),
                'expires_at' => now()->addDay(),
            ],
        ] as $input) {
            try {
                $action->handle($account, $owner, $delegate, $input);
                $this->fail('Invalid delegation input was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        try {
            $action->handle($account, $admin, $delegate, [
                'can_view' => true,
                'reason' => 'An administrator cannot delegate another owner mailbox.',
                'starts_at' => now(),
                'expires_at' => now()->addDay(),
            ]);
            $this->fail('Administrator delegation was accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $delegation = $action->handle($account, $owner, $delegate, [
            'can_view' => true,
            'reason' => 'Short exact View cover.',
            'starts_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        try {
            app(RevokeEmailMailboxDelegation::class)->handle($delegation, $admin, 'Admin attempt.');
            $this->fail('Administrator revoked an owner delegation.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        app(RevokeEmailMailboxDelegation::class)->handle($delegation, $owner, 'Owner ended cover.');

        $this->assertFalse(app(ResolveMailboxAccessDecision::class)
            ->resolve($delegate, $account, MailboxAccess::VIEW)->allowed);
        $this->assertDatabaseHas('email_mailbox_access_events', [
            'email_mailbox_delegation_id' => $delegation->id,
            'event_type' => EmailMailboxAccessEvent::TYPE_DELEGATION_REVOKED,
        ]);

        $delegate->forceFill(['status' => User::STATUS_DISABLED])->save();
        $this->assertFalse(app(ResolveMailboxAccessDecision::class)
            ->resolve($delegate, $account, ResolveMailboxAccessDecision::CONTENT_VIEW)->allowed);
    }

    #[Test]
    public function ordinary_delegation_expiry_is_recorded_once_at_the_next_access_boundary(): void
    {
        $owner = $this->user(['email.inbox_view']);
        $delegate = $this->user(['email.inbox_view']);
        $account = $this->personalAccount($owner, 'delegation-expiry@example.test');
        $delegation = app(CreateEmailMailboxDelegation::class)->handle(
            $account,
            $owner,
            $delegate,
            [
                'can_view' => true,
                'reason' => 'One-minute expiry boundary.',
                'starts_at' => now(),
                'expires_at' => now()->addMinute(),
            ],
        );

        CarbonImmutable::setTestNow('2026-08-16 10:02:00 UTC');
        $mailboxAccess = app(MailboxAccess::class);
        $this->assertFalse($mailboxAccess->canAccessAccount($delegate, $account, MailboxAccess::VIEW));
        $this->assertFalse($mailboxAccess->canAccessAccount($delegate, $account, MailboxAccess::VIEW));

        $this->assertSame(1, EmailMailboxAccessEvent::query()
            ->where('email_mailbox_delegation_id', $delegation->id)
            ->where('event_type', EmailMailboxAccessEvent::TYPE_DELEGATION_EXPIRED_AT_USE)
            ->where('operation', MailboxAccess::VIEW)
            ->count());
    }

    #[Test]
    public function shared_grants_remain_ordinary_while_personal_direct_grants_and_break_glass_do_not_widen_view(): void
    {
        $user = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $owner = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $shared = $this->account('shared-grant@example.test');
        $personal = $this->personalAccount($owner, 'personal-grant@example.test');

        foreach ([$shared, $personal] as $account) {
            EmailAccountUserGrant::query()->create([
                'email_account_id' => $account->id,
                'user_id' => $user->id,
                'can_view' => true,
                'can_organize' => true,
                'can_send' => true,
                'granted_by' => $owner->id,
                'granted_at' => now(),
            ]);
        }

        $resolver = app(ResolveMailboxAccessDecision::class);
        $this->assertSame(
            MailboxAccessDecision::SOURCE_GRANT,
            $resolver->resolve($user, $shared, MailboxAccess::VIEW)->source,
        );
        $this->assertFalse($resolver->resolve($user, $personal, MailboxAccess::VIEW)->allowed);
        $this->assertFalse($resolver->resolve($user, $personal, ResolveMailboxAccessDecision::CONTENT_VIEW)->allowed);

        [, $ticketLinkedMessage] = $this->placedMessage(
            $personal,
            'Ticket linkage must not widen Mail access',
            'Private personal mailbox content.',
        );
        $ticketLinkedMessage->forceFill([
            'ticket_id' => Ticket::factory()->create(['owner_id' => $user->id])->id,
        ])->save();
        $this->assertFalse(app(MailboxAccess::class)->scopeContentMessages(
            EmailMessage::query()->whereKey($ticketLinkedMessage->id),
            $user,
        )->exists());

        $otherPersonal = $this->personalAccount($owner, 'cross-account@example.test');
        $this->assertFalse($resolver->resolve($user, $otherPersonal, MailboxAccess::VIEW)->allowed);
    }

    #[Test]
    public function break_glass_requires_exact_confirmation_permission_duration_and_operations(): void
    {
        $owner = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $operator = $this->user(['email.break_glass_activate', 'email.raw_source_view']);
        $account = $this->personalAccount($owner, 'emergency@example.test');
        $action = app(ActivateEmailBreakGlassAccess::class);

        foreach ([
            ['account_confirmation' => 'wrong@example.test', 'duration_minutes' => 30],
            ['account_confirmation' => $account->address, 'duration_minutes' => 121],
        ] as $invalid) {
            try {
                $action->handle($account, $operator, [
                    ...$invalid,
                    'can_view_content' => true,
                    'reason' => 'Incident response access.',
                ]);
                $this->fail('Invalid emergency activation was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $access = $action->handle($account, $operator, [
            'account_confirmation' => strtoupper($account->address),
            'duration_minutes' => 120,
            'can_view_content' => true,
            'can_search' => true,
            'can_download_attachments' => true,
            'can_view_raw_source' => true,
            'reason' => 'Investigate a confirmed customer-impacting incident.',
        ]);

        Queue::assertPushed(
            DispatchMailboxBreakGlassActivationNotification::class,
            fn (DispatchMailboxBreakGlassActivationNotification $job): bool => $job->accessId === $access->id
                && $job->queue === 'notifications'
                && $job->afterCommit === true,
        );

        $resolver = app(ResolveMailboxAccessDecision::class);
        foreach ([MailboxAccess::VIEW, MailboxAccess::ORGANIZE, MailboxAccess::SEND] as $operation) {
            $this->assertFalse($resolver->resolve($operator, $account, $operation)->allowed, $operation);
        }

        foreach (ResolveMailboxAccessDecision::BREAK_GLASS_OPERATIONS as $operation) {
            $decision = $resolver->resolve($operator, $account, $operation);
            $this->assertTrue($decision->allowed, $operation);
            $this->assertSame(MailboxAccessDecision::SOURCE_BREAK_GLASS, $decision->source);
        }

        $this->assertSame(120, (int) $access->starts_at->diffInMinutes($access->expires_at));

        $otherAccount = $this->personalAccount($owner, 'other-emergency@example.test');
        $otherAccess = $action->handle($otherAccount, $operator, [
            'account_confirmation' => $otherAccount->address,
            'duration_minutes' => 10,
            'can_view_content' => true,
            'reason' => 'A separate mailbox has an independent emergency window.',
        ]);
        $this->assertSame($otherAccount->id, $otherAccess->email_account_id);

        try {
            $action->handle($account, $operator, [
                'account_confirmation' => $account->address,
                'duration_minutes' => 10,
                'can_view_content' => true,
                'reason' => 'Same actor/account overlap must fail.',
            ]);
            $this->fail('Overlapping emergency access was accepted for the same actor/account.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $withoutRaw = $this->user(['email.break_glass_activate']);
        try {
            $action->handle($account, $withoutRaw, [
                'account_confirmation' => $account->address,
                'duration_minutes' => 10,
                'can_view_raw_source' => true,
                'reason' => 'Raw access without its double guard.',
            ]);
            $this->fail('Raw-source break glass was granted without the raw-source permission.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function break_glass_self_owner_and_operator_revocation_work_but_audit_permission_is_read_only(): void
    {
        $owner = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $firstActor = $this->user(['email.break_glass_activate']);
        $secondActor = $this->user(['email.break_glass_activate']);
        $thirdActor = $this->user(['email.break_glass_activate']);
        $emergencyOperator = $this->user(['email.break_glass_activate']);
        $auditor = $this->user(['email.break_glass_audit']);
        $account = $this->personalAccount($owner, 'revoke-emergency@example.test');
        $activate = app(ActivateEmailBreakGlassAccess::class);
        $revoke = app(RevokeEmailBreakGlassAccess::class);

        $first = $this->activateBreakGlass($activate, $account, $firstActor);
        $revoke->handle($first, $firstActor, 'Self-revoked after the incident check.');

        $second = $this->activateBreakGlass($activate, $account, $secondActor);
        $revoke->handle($second, $owner, 'Mailbox owner ended emergency access.');

        $third = $this->activateBreakGlass($activate, $account, $thirdActor);
        try {
            $revoke->handle($third, $auditor, 'Audit-only user attempted a write.');
            $this->fail('Audit-only permission revoked emergency access.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $revoke->handle($third, $emergencyOperator, 'Emergency operator ended access.');

        $this->assertSame(3, EmailMailboxAccessEvent::query()
            ->where('event_type', EmailMailboxAccessEvent::TYPE_BREAK_GLASS_REVOKED)
            ->count());
        $this->assertSame(
            ['actor', 'operator', 'owner'],
            EmailMailboxAccessEvent::query()
                ->where('event_type', EmailMailboxAccessEvent::TYPE_BREAK_GLASS_REVOKED)
                ->get()
                ->pluck('metadata_json.revocation_source')
                ->sort()
                ->values()
                ->all(),
        );

        $this->assertFalse(app(ResolveMailboxAccessDecision::class)
            ->resolve($thirdActor, $account, ResolveMailboxAccessDecision::CONTENT_VIEW)->allowed);
    }

    #[Test]
    public function notification_delivery_is_after_commit_idempotent_and_reaches_owner_and_active_security_recipients(): void
    {
        $owner = $this->user([
            'email.inbox_view', 'email.inbox_manage', 'email.break_glass_audit',
        ]);
        $security = $this->user(['email.break_glass_audit']);
        $inactiveSecurity = $this->user(['email.break_glass_audit']);
        $inactiveSecurity->forceFill(['status' => User::STATUS_DISABLED])->save();
        $operator = $this->user(['email.break_glass_activate']);
        $account = $this->personalAccount($owner, 'notice-emergency@example.test');
        $access = $this->activateBreakGlass(
            app(ActivateEmailBreakGlassAccess::class),
            $account,
            $operator,
            'Mandatory notice reason with no message content.',
        );

        app(RevokeEmailBreakGlassAccess::class)->handle(
            $access,
            $owner,
            'Owner revoked before the queued notice ran.',
        );

        $notices = app(DispatchMailboxBreakGlassActivationNotice::class);
        $notices->handle($access->id);
        $notices->handle($access->id);

        foreach ([$owner, $security] as $recipient) {
            $notification = $recipient->notifications()->sole();
            $this->assertSame(MailboxBreakGlassActivatedNotification::class, $notification->type);
            $this->assertSame('mailbox_break_glass_activated', $notification->data['type']);
            $this->assertSame($account->address, $notification->data['account']);
            $this->assertSame($operator->name, $notification->data['actor']);
            $this->assertSame($access->reason, $notification->data['reason']);
            $this->assertNotNull($notification->data['revoked_at']);
            $this->assertSame(
                route('tech.mail.access.history', ['account' => $account->id], false),
                $notification->data['url'],
            );
            $this->assertStringStartsWith('/', $notification->data['url']);
            $this->assertArrayNotHasKey('subject', $notification->data);
            $this->assertArrayNotHasKey('body', $notification->data);
            $this->assertArrayNotHasKey('search_term', $notification->data);
        }

        $this->assertSame(0, $inactiveSecurity->notifications()->count());
        $this->assertSame(0, $operator->notifications()->count());
        $this->assertNotNull($access->fresh()->owner_notification_sent_at);
        $this->assertNotNull($access->fresh()->security_notification_sent_at);
    }

    #[Test]
    public function notification_skips_an_inactive_owner_and_retries_partial_security_delivery_idempotently(): void
    {
        $owner = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $firstSecurity = $this->user(['email.break_glass_audit']);
        $secondSecurity = $this->user(['email.break_glass_audit']);
        $operator = $this->user(['email.break_glass_activate']);
        $account = $this->personalAccount($owner, 'partial-notice@example.test');
        $access = $this->activateBreakGlass(
            app(ActivateEmailBreakGlassAccess::class),
            $account,
            $operator,
            'Partial notification retry evidence.',
        );

        $owner->forceFill(['status' => User::STATUS_DISABLED])->save();

        $recordings = new class($secondSecurity->id) extends \App\Modules\Notification\Actions\RecordCanonicalNotification
        {
            private bool $failed = false;

            public function __construct(private readonly int $failOnceForUserId) {}

            /** @param  array<string, mixed>  $data */
            public function handle(
                User $user,
                string $notificationClass,
                string $deliveryIdentity,
                array $data,
                bool $unread = true,
            ): DatabaseNotification {
                if (! $this->failed && (int) $user->id === $this->failOnceForUserId) {
                    $this->failed = true;

                    throw new RuntimeException('Temporary notification storage failure.');
                }

                return parent::handle($user, $notificationClass, $deliveryIdentity, $data, $unread);
            }
        };
        $notices = new DispatchMailboxBreakGlassActivationNotice($recordings);

        try {
            $notices->handle($access->id);
            $this->fail('Partial delivery failure did not request a queue retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Temporary notification storage failure.', $exception->getMessage());
        }

        $this->assertSame(1, $firstSecurity->notifications()->count());
        $this->assertSame(0, $secondSecurity->notifications()->count());
        $this->assertSame(0, $owner->notifications()->count());
        $this->assertNull($access->fresh()->owner_notification_sent_at);
        $this->assertNull($access->fresh()->security_notification_sent_at);

        $notices->handle($access->id);
        $notices->handle($access->id);

        $this->assertSame(1, $firstSecurity->notifications()->count());
        $this->assertSame(1, $secondSecurity->notifications()->count());
        $this->assertSame(0, $owner->notifications()->count());
        $this->assertNull($access->fresh()->owner_notification_sent_at);
        $this->assertNotNull($access->fresh()->security_notification_sent_at);
    }

    #[Test]
    public function break_glass_use_is_audited_before_access_idempotently_and_expiry_is_recorded_once(): void
    {
        $owner = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $operator = $this->user(['email.break_glass_activate']);
        $account = $this->personalAccount($owner, 'use-audit@example.test');
        $access = app(ActivateEmailBreakGlassAccess::class)->handle($account, $operator, [
            'account_confirmation' => $account->address,
            'duration_minutes' => 1,
            'can_view_content' => true,
            'reason' => 'Sensitive reason that must not enter the metadata-only event.',
        ]);
        $guard = app(MailboxAccessUseGuard::class);

        $first = $guard->authorize(
            $operator,
            $account,
            ResolveMailboxAccessDecision::CONTENT_VIEW,
            'message',
            444,
            'request-secret-123',
        );
        $second = $guard->authorize(
            $operator,
            $account,
            ResolveMailboxAccessDecision::CONTENT_VIEW,
            'message',
            444,
            'request-secret-123',
        );

        $this->assertSame($access->id, $first->breakGlassAccessId);
        $this->assertSame($first->breakGlassAccessId, $second->breakGlassAccessId);
        $this->assertSame(1, EmailMailboxAccessEvent::query()
            ->where('event_type', EmailMailboxAccessEvent::TYPE_MESSAGE_VIEW)
            ->where('resource_id', 444)
            ->count());

        $useEvent = EmailMailboxAccessEvent::query()
            ->where('event_type', EmailMailboxAccessEvent::TYPE_MESSAGE_VIEW)
            ->sole();
        $serialized = json_encode($useEvent->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Sensitive reason', $serialized);
        $this->assertStringNotContainsString('request-secret-123', $serialized);

        CarbonImmutable::setTestNow('2026-08-16 10:02:00 UTC');
        foreach ([1, 2] as $attempt) {
            try {
                $guard->authorize(
                    $operator,
                    $account,
                    ResolveMailboxAccessDecision::CONTENT_VIEW,
                    'message',
                    444,
                    'expired-attempt-'.$attempt,
                );
                $this->fail('Expired emergency access disclosed content.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(1, EmailMailboxAccessEvent::query()
            ->where('event_type', EmailMailboxAccessEvent::TYPE_BREAK_GLASS_EXPIRED_AT_USE)
            ->where('email_break_glass_access_id', $access->id)
            ->count());
    }

    #[Test]
    public function audit_write_failure_fails_closed_and_execution_time_checks_honor_disablement(): void
    {
        $owner = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $operator = $this->user(['email.break_glass_activate', 'email.raw_source_view']);
        $account = $this->personalAccount($owner, 'fail-closed@example.test');
        app(ActivateEmailBreakGlassAccess::class)->handle($account, $operator, [
            'account_confirmation' => $account->address,
            'duration_minutes' => 30,
            'can_view_content' => true,
            'can_search' => true,
            'can_download_attachments' => true,
            'can_view_raw_source' => true,
            'reason' => 'Exercise fail-closed audit for every sensitive boundary.',
        ]);

        $failingRecorder = new class extends EmailMailboxAccessEventRecorder
        {
            public function recordBreakGlassUse(
                MailboxAccessDecision $decision,
                string $eventType,
                string $resourceType,
                ?int $resourceId,
                ?string $requestKey = null,
            ): EmailMailboxAccessEvent {
                throw new RuntimeException('Audit storage unavailable.');
            }
        };
        $guard = new MailboxAccessUseGuard(
            app(ResolveMailboxAccessDecision::class),
            $failingRecorder,
        );

        foreach ([
            [ResolveMailboxAccessDecision::CONTENT_VIEW, 'message', 9],
            [ResolveMailboxAccessDecision::SEARCH, 'search', $account->id],
            [ResolveMailboxAccessDecision::ATTACHMENT_DOWNLOAD, 'attachment', 19],
            [ResolveMailboxAccessDecision::RAW_SOURCE, 'raw_source', 9],
        ] as [$operation, $resourceType, $resourceId]) {
            try {
                $guard->authorize(
                    $operator,
                    $account,
                    $operation,
                    $resourceType,
                    $resourceId,
                );
                $this->fail($operation.' returned despite audit failure.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Audit storage unavailable.', $exception->getMessage());
            }
        }

        $operator->revokePermissionTo('email.break_glass_activate');
        $this->assertFalse(app(ResolveMailboxAccessDecision::class)
            ->resolve($operator, $account, ResolveMailboxAccessDecision::CONTENT_VIEW)->allowed);
        $this->actingAs($operator)
            ->get(route('tech.mail.index'))
            ->assertForbidden();
        $this->actingAs($owner)
            ->get(route('tech.mail.access.index'))
            ->assertOk()
            ->assertDontSee('Emergency access is active');

        $operator->givePermissionTo('email.break_glass_activate');
        $operator->forceFill(['status' => User::STATUS_DISABLED])->save();
        $this->assertFalse(app(ResolveMailboxAccessDecision::class)
            ->resolve($operator, $account, ResolveMailboxAccessDecision::CONTENT_VIEW)->allowed);

        $operator->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $account->forceFill(['is_active' => false])->save();
        $this->assertFalse(app(ResolveMailboxAccessDecision::class)
            ->resolve($operator, $account, ResolveMailboxAccessDecision::CONTENT_VIEW)->allowed);
        $this->actingAs($operator)
            ->get(route('tech.mail.index'))
            ->assertForbidden();
    }

    #[Test]
    public function livewire_emergency_content_is_prominent_audited_and_never_creates_personal_unread_state(): void
    {
        $owner = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $operator = $this->user(['email.break_glass_activate']);
        $account = $this->personalAccount($owner, 'livewire-emergency@example.test');
        [, $message, $placement] = $this->placedMessage(
            $account,
            'Emergency workspace subject',
            'Needle emergency workspace body.',
        );
        $access = app(ActivateEmailBreakGlassAccess::class)->handle($account, $operator, [
            'account_confirmation' => $account->address,
            'duration_minutes' => 30,
            'can_view_content' => true,
            'can_search' => true,
            'reason' => 'Investigate a mail delivery incident.',
        ]);

        $component = Livewire::actingAs($operator)
            ->test(MailWorkspace::class)
            ->assertSee('Emergency mailbox access is active')
            ->assertSee($account->address)
            ->assertSee('Emergency workspace subject')
            ->assertDontSee('Unread for me')
            ->assertDontSee('Mailbox unread')
            ->call('selectPlacement', $placement->id)
            ->assertSet('selectedPlacementId', $placement->id)
            ->assertSee('Needle emergency workspace body.')
            ->assertDontSee('Mark read')
            ->assertDontSee('Mark unread for me');

        $this->assertDatabaseHas('email_mailbox_access_events', [
            'email_break_glass_access_id' => $access->id,
            'event_type' => EmailMailboxAccessEvent::TYPE_MAILBOX_VIEW,
            'resource_type' => 'mailbox',
            'resource_id' => $account->id,
        ]);
        $this->assertSame(1, EmailMailboxAccessEvent::query()
            ->where('email_break_glass_access_id', $access->id)
            ->where('event_type', EmailMailboxAccessEvent::TYPE_MESSAGE_VIEW)
            ->where('resource_id', $message->id)
            ->count());
        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $message->id,
            'user_id' => $operator->id,
        ]);
        $this->assertDatabaseMissing('email_account_user_read_baselines', [
            'email_account_id' => $account->id,
            'user_id' => $operator->id,
        ]);

        $component
            ->call('setSelectedUnreadForMe', false)
            ->set('search', 'Needle')
            ->assertSee('Emergency workspace subject');

        $this->assertDatabaseMissing('email_message_user_states', [
            'email_message_id' => $message->id,
            'user_id' => $operator->id,
        ]);
        $this->assertSame(1, EmailMailboxAccessEvent::query()
            ->where('email_break_glass_access_id', $access->id)
            ->where('event_type', EmailMailboxAccessEvent::TYPE_SEARCH_EXECUTION)
            ->where('resource_id', $account->id)
            ->count());
    }

    #[Test]
    public function http_live_legacy_and_api_content_boundaries_share_current_break_glass_audit(): void
    {
        Storage::fake('local');
        $owner = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $operator = $this->user([
            'email.break_glass_activate',
            'email.raw_source_view',
        ]);
        $account = $this->personalAccount($owner, 'boundary-emergency@example.test');
        [, $message, $placement] = $this->placedMessage(
            $account,
            'Boundary emergency subject',
            'Needle boundary emergency body.',
        );
        $raw = "From: sender@example.test\r\nSubject: Boundary emergency subject\r\n\r\nRaw boundary body.";
        $rawPath = 'email/raw/'.$account->id.'/INBOX/'.$message->id.'.eml';
        Storage::disk('local')->put($rawPath, $raw);
        $message->forceFill(['raw_path' => $rawPath])->save();
        $attachmentPath = 'email/attachments/'.$account->id.'/INBOX/'.$message->id.'/evidence.txt';
        Storage::disk('local')->put($attachmentPath, 'attachment-boundary-bytes');
        $attachment = EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'evidence.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 25,
            'disk' => 'local',
            'path' => $attachmentPath,
            'checksum_sha1' => sha1('attachment-boundary-bytes'),
        ]);
        $access = app(ActivateEmailBreakGlassAccess::class)->handle($account, $operator, [
            'account_confirmation' => $account->address,
            'duration_minutes' => 30,
            'can_view_content' => true,
            'can_search' => true,
            'can_download_attachments' => true,
            'can_view_raw_source' => true,
            'reason' => 'Verify every emergency content boundary.',
        ]);

        $this->actingAs($operator)
            ->get(route('tech.mail.index'))
            ->assertOk();
        $rawResponse = $this->get(route('tech.mail.raw-source.show', $placement));
        $rawResponse->assertOk()->assertHeader('Content-Type', 'message/rfc822');
        $this->assertSame($raw, $rawResponse->streamedContent());

        $attachmentResponse = $this->get(route('tech.mail.attachments.download', [
            $placement,
            $attachment,
        ]));
        $attachmentResponse->assertOk();
        $this->assertSame('attachment-boundary-bytes', $attachmentResponse->streamedContent());

        $this->get(route('tech.inbox.show', $message))
            ->assertOk()
            ->assertSee('Needle boundary emergency body.');

        Sanctum::actingAs($operator, ['email.read']);
        $this->getJson(route('api.v1.email.inbox.messages.index', [
            'account_id' => $account->id,
            'q' => 'Needle',
        ]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id);
        $this->getJson(route('api.v1.email.inbox.messages.show', $message))
            ->assertOk()
            ->assertJsonPath('data.id', $message->id);

        foreach ([
            EmailMailboxAccessEvent::TYPE_RAW_SOURCE_VIEW,
            EmailMailboxAccessEvent::TYPE_ATTACHMENT_DOWNLOAD,
            EmailMailboxAccessEvent::TYPE_MESSAGE_VIEW,
            EmailMailboxAccessEvent::TYPE_SEARCH_EXECUTION,
        ] as $eventType) {
            $this->assertDatabaseHas('email_mailbox_access_events', [
                'email_break_glass_access_id' => $access->id,
                'event_type' => $eventType,
            ]);
        }

        $this->assertDatabaseMissing('email_account_user_read_baselines', [
            'email_account_id' => $account->id,
            'user_id' => $operator->id,
        ]);
    }

    #[Test]
    public function audit_history_is_append_only_source_deletion_safe_and_blocks_account_deletion(): void
    {
        $owner = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $delegate = $this->user(['email.inbox_view']);
        $account = $this->personalAccount($owner, 'retained-history@example.test');
        $delegation = app(CreateEmailMailboxDelegation::class)->handle(
            $account,
            $owner,
            $delegate,
            [
                'can_view' => true,
                'reason' => 'Create retained history.',
                'starts_at' => now(),
                'expires_at' => now()->addDay(),
            ],
        );
        $event = EmailMailboxAccessEvent::query()->sole();

        try {
            $event->forceFill(['reason_code' => 'tampered'])->save();
            $this->fail('Append-only audit event was updated.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        try {
            $event->delete();
            $this->fail('Append-only audit event was deleted.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        DB::table('email_mailbox_delegations')->where('id', $delegation->id)->delete();
        $this->assertDatabaseHas('email_mailbox_access_events', [
            'id' => $event->id,
            'email_mailbox_delegation_id' => null,
        ]);

        try {
            DB::table('email_accounts')->where('id', $account->id)->delete();
            $this->fail('Account deletion erased or orphaned mailbox access history.');
        } catch (QueryException) {
            $this->assertDatabaseHas('email_mailbox_access_events', ['id' => $event->id]);
        }

        $migration = require database_path(
            'migrations/2026_08_16_103000_create_email_mailbox_delegation_break_glass_access.php',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('retention/export decision');
        $migration->down();
    }

    private function user(array $permissions = [], bool $systemActor = false): User
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'is_system_actor' => $systemActor,
        ]);

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    private function personalAccount(User $owner, string $address): EmailAccount
    {
        return $this->account($address, [
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $owner->id,
        ]);
    }

    /** @return array{EmailFolder, EmailMessage, EmailMailboxPlacement} */
    private function placedMessage(
        EmailAccount $account,
        string $subject,
        string $body,
    ): array {
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'Inbox',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 1608,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 160801,
            'imap_uid_validity' => 1608,
            'message_id' => '<mailbox-access-'.$account->id.'@example.test>',
            'subject' => $subject,
            'from_name' => 'Emergency Sender',
            'from_email' => 'sender@example.test',
            'to_json' => [['email' => $account->address]],
            'headers_json' => [],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => $body,
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => $folder->path,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $message->imap_uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'last_reconciled_at' => now(),
        ]);

        return [$folder, $message, $placement];
    }

    /** @param  array<string, mixed>  $overrides */
    private function account(string $address, array $overrides = []): EmailAccount
    {
        return EmailAccount::query()->create(array_merge([
            'address' => $address,
            'description' => 'Mailbox access test account',
            'from_name' => 'Mailbox Access Test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'owner_id' => null,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'mailbox-access-test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'mailbox-access-test-secret',
            'smtp_auth_type' => 'password',
        ], $overrides));
    }

    private function activateBreakGlass(
        ActivateEmailBreakGlassAccess $action,
        EmailAccount $account,
        User $actor,
        string $reason = 'Confirmed incident response access.',
    ): EmailBreakGlassAccess {
        return $action->handle($account, $actor, [
            'account_confirmation' => $account->address,
            'duration_minutes' => 30,
            'can_view_content' => true,
            'reason' => $reason,
        ]);
    }
}
