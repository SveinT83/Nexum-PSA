<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Services\InboundAttachmentPersister;
use App\Modules\Email\Services\InboundEmailRuleEngine;
use App\Modules\Email\Services\InboundEmailSignalClassifier;
use App\Modules\Email\Services\PersonalEmailRuleEngine;
use App\Modules\Email\Services\TrustedSenderAuthenticationFacts;
use App\Modules\Notification\Actions\DispatchInboundEmailNotification;
use App\Modules\Signal\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InboundAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Inbound persistence must never write test MIME or attachments into
        // the shared Dev private-email tree while the database is rolled back.
        Storage::fake('local');

        Permission::findOrCreate('email.account_manage', 'web');
        Permission::findOrCreate('email.rule_manage', 'web');
        Role::findOrCreate('Admin', 'web');

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo(['email.account_manage', 'email.rule_manage']);
    }

    #[Test]
    public function admin_can_store_an_opt_in_preclassification_rule(): void
    {
        $account = $this->emailAccount([
            'address' => 'preclassification@example.test',
        ]);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.rules.create'))
            ->assertOk()
            ->assertSee('Routing phase')
            ->assertSee('Preclassification - before machine and AI classification');

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.rules.store'), [
                'name' => 'Supplier order intake',
                'description' => 'Recognize deterministic supplier mail before generic classification.',
                'weight' => 5,
                'account_ids' => [$account->id],
                'routing_phase' => EmailRule::ROUTING_PHASE_PRECLASSIFICATION,
                'is_active' => '1',
                'stop_processing' => '1',
                'conditions' => [
                    ['field' => 'from_domain', 'operator' => 'equals', 'value' => 'supplier.example'],
                ],
                'actions' => [
                    ['type' => 'emit_signal', 'value' => 'supplier_order_email'],
                ],
            ])
            ->assertRedirect(route('tech.admin.settings.email.rules'));

        $this->assertDatabaseHas('email_rules', [
            'name' => 'Supplier order intake',
            'routing_phase' => EmailRule::ROUTING_PHASE_PRECLASSIFICATION,
        ]);
    }

    #[Test]
    public function preclassification_rule_can_stop_the_generic_classifier(): void
    {
        $email = $this->vendorEmail(7101);

        EmailRule::query()->create([
            'name' => 'Supplier purchase order',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_PRECLASSIFICATION,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => [
                ['field' => 'from_domain', 'operator' => 'equals', 'value' => 'qnap.com'],
            ],
            'actions_json' => [
                ['type' => 'emit_signal', 'signal_type' => 'supplier_order_email'],
            ],
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertDatabaseHas('signals', [
            'source_domain' => 'email',
            'source_id' => $email->id,
            'signal_type' => 'supplier_order_email',
        ]);
        $this->assertDatabaseMissing('signals', [
            'source_id' => $email->id,
            'signal_type' => 'vendor_notification',
        ]);
        $this->assertNull($email->fresh()->ticket_id);
    }

    #[Test]
    public function normal_rules_keep_existing_classifier_first_behavior(): void
    {
        $email = $this->vendorEmail(7102);

        EmailRule::query()->create([
            'name' => 'Normal supplier rule',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_NORMAL,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => [
                ['field' => 'from_domain', 'operator' => 'equals', 'value' => 'qnap.com'],
            ],
            'actions_json' => [
                ['type' => 'emit_signal', 'signal_type' => 'supplier_order_email'],
            ],
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertDatabaseHas('signals', [
            'source_domain' => 'email',
            'source_id' => $email->id,
            'signal_type' => 'vendor_notification',
        ]);
        $this->assertDatabaseMissing('signals', [
            'source_id' => $email->id,
            'signal_type' => 'supplier_order_email',
        ]);
        $this->assertSame('archived', $email->fresh()->state);
    }

    #[Test]
    public function inbound_rules_require_an_exact_active_nonmissing_same_account_provider_occurrence(): void
    {
        EmailRule::query()->create([
            'name' => 'Provider occurrence boundary',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_PRECLASSIFICATION,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => [
                ['field' => 'from_domain', 'operator' => 'equals', 'value' => 'boundary.example'],
            ],
            'actions_json' => [
                ['type' => 'emit_signal', 'signal_type' => 'provider_occurrence_boundary'],
            ],
        ]);

        $active = $this->emailMessage(7103);
        $active->forceFill(['from_email' => 'sender@boundary.example'])->save();
        $this->activeInboxPlacement($active);

        $providerMissing = $this->emailMessage(7104);
        $providerMissing->forceFill(['from_email' => 'sender@boundary.example'])->save();
        $this->activeInboxPlacement($providerMissing, overrides: [
            'provider_missing_at' => now(),
        ]);

        $crossAccount = $this->emailMessage(7105);
        $crossAccount->forceFill(['from_email' => 'sender@boundary.example'])->save();
        $foreignAccount = $this->emailAccount([
            'address' => 'foreign-boundary@example.test',
            'imap_username' => 'foreign-boundary@example.test',
            'smtp_username' => 'foreign-boundary@example.test',
        ]);
        $this->activeInboxPlacement($crossAccount, $foreignAccount);

        app()->call([new ProcessInboundRules($active->id, true), 'handle']);

        $ruleEngine = $this->createMock(InboundEmailRuleEngine::class);
        $ruleEngine->expects($this->never())->method('allowsInboundAutomation');
        $classifier = $this->createMock(InboundEmailSignalClassifier::class);
        $classifier->expects($this->never())->method('classifyAndRecord');
        $personalRuleEngine = $this->createMock(PersonalEmailRuleEngine::class);
        $personalRuleEngine->expects($this->never())->method('process');
        $notifications = $this->createMock(DispatchInboundEmailNotification::class);
        $notifications->expects($this->never())->method('handle');

        foreach ([$providerMissing, $crossAccount] as $message) {
            (new ProcessInboundRules($message->id, true))->handle(
                $ruleEngine,
                $classifier,
                $personalRuleEngine,
                $notifications,
            );
        }

        $this->assertDatabaseHas('signals', [
            'source_domain' => 'email',
            'source_id' => $active->id,
            'signal_type' => 'provider_occurrence_boundary',
        ]);

        foreach ([$providerMissing, $crossAccount] as $rejected) {
            $this->assertDatabaseMissing('signals', [
                'source_domain' => 'email',
                'source_id' => $rejected->id,
            ]);
            $this->assertDatabaseMissing('email_rule_execution_attempts', [
                'email_message_id' => $rejected->id,
            ]);
            $this->assertDatabaseMissing('email_rule_logs', [
                'email_message_id' => $rejected->id,
            ]);
            $this->assertNull($rejected->fresh()->ticket_id);
            $this->assertSame('untriaged', $rejected->fresh()->state);
        }

        $this->assertDatabaseCount('email_remote_operations', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    #[Test]
    public function admin_can_configure_trusted_authentication_infrastructure(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.config'))
            ->assertOk()
            ->assertSee('Email Sync & Cache Settings', false)
            ->assertSee('Provider Sync')
            ->assertSee('Local Cache & Legacy Cleanup', false)
            ->assertSee('Legacy server cleanup after successful Ticket-ingest import')
            ->assertSee('Advanced Automation Trust')
            ->assertSee('Proxmox Mail Gateway')
            ->assertSee('Trusted authserv IDs')
            ->assertSee('Trusted receiving hops');

        $payload = [
            'poll_interval' => 1,
            'concurrency' => 2,
            'batch_size' => 20,
            'size_limit_mb' => 25,
            'retention_months' => 24,
            'max_failures' => 3,
            'trusted_authserv_ids' => "MX.TRUSTED.TEST.\nmx.backup.test",
            'trusted_receiving_hops' => 'MAIL-GATEWAY.TRUSTED.TEST.',
        ];

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.email.config.update'), $payload)
            ->assertRedirect(route('tech.admin.settings.email.config'));

        $this->assertDatabaseHas('common_settings', [
            'type' => 'emailhub',
            'name' => 'trusted_authserv_ids',
            'value' => "mx.trusted.test\nmx.backup.test",
        ]);
        $this->assertDatabaseHas('common_settings', [
            'type' => 'emailhub',
            'name' => 'trusted_receiving_hops',
            'value' => 'mail-gateway.trusted.test',
        ]);

        $this->actingAs($this->admin)
            ->from(route('tech.admin.settings.email.config'))
            ->post(route('tech.admin.settings.email.config.update'), array_merge($payload, [
                'trusted_authserv_ids' => 'not/a/host',
            ]))
            ->assertRedirect(route('tech.admin.settings.email.config'))
            ->assertSessionHasErrors('trusted_authserv_ids');
    }

    #[Test]
    public function email_config_keeps_legacy_server_cleanup_off_by_default(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.email.config'))
            ->assertOk()
            ->assertSee('Normal IMAP client sync keeps provider mail on the server.');

        $this->assertMatchesRegularExpression(
            '/id="delete_on_success"[^>]*value="1"(?![^>]*checked)/',
            $response->getContent(),
        );
    }

    #[Test]
    public function admin_rejects_partial_trusted_authentication_configuration(): void
    {
        $payload = [
            'poll_interval' => 1,
            'concurrency' => 2,
            'batch_size' => 20,
            'size_limit_mb' => 25,
            'retention_months' => 24,
            'max_failures' => 3,
        ];

        $this->actingAs($this->admin)
            ->from(route('tech.admin.settings.email.config'))
            ->post(route('tech.admin.settings.email.config.update'), $payload + [
                'trusted_authserv_ids' => 'mx.trusted.test',
                'trusted_receiving_hops' => '',
            ])
            ->assertRedirect(route('tech.admin.settings.email.config'))
            ->assertSessionHasErrors('trusted_receiving_hops');

        $this->actingAs($this->admin)
            ->from(route('tech.admin.settings.email.config'))
            ->post(route('tech.admin.settings.email.config.update'), $payload + [
                'trusted_authserv_ids' => '',
                'trusted_receiving_hops' => 'mail-gateway.trusted.test',
            ])
            ->assertRedirect(route('tech.admin.settings.email.config'))
            ->assertSessionHasErrors('trusted_authserv_ids');
    }

    #[Test]
    public function forged_authentication_results_from_an_untrusted_hop_are_ignored(): void
    {
        $this->trustedAuthenticationSettings();

        $message = $this->emailMessage(7151);
        $message->forceFill([
            'from_email' => 'orders@itegra.no',
            'headers_json' => [
                'Authentication-Results' => 'mx.trusted.test; spf=pass smtp.mailfrom=orders@itegra.no; dkim=pass header.d=itegra.no; dmarc=pass header.from=itegra.no',
                'Received' => 'from attacker.test by attacker-gateway.test with ESMTP',
            ],
        ])->save();

        $facts = app(TrustedSenderAuthenticationFacts::class)->forMessage($message);

        $this->assertFalse($facts['authentication_passed']);
        $this->assertFalse($facts['aligned']);
        $this->assertNull($facts['authserv_id']);
        $this->assertNull($facts['authenticated_supplier_identity']);
        $this->assertNull($facts['authenticated_supplier_domain']);
    }

    #[Test]
    public function untrusted_authserv_results_are_ignored_even_on_a_trusted_hop(): void
    {
        $this->trustedAuthenticationSettings();

        $message = $this->emailMessage(7152);
        $message->forceFill([
            'from_email' => 'orders@itegra.no',
            'headers_json' => [
                'Authentication-Results' => 'attacker.test; spf=pass smtp.mailfrom=orders@itegra.no; dkim=pass header.d=itegra.no; dmarc=pass header.from=itegra.no',
                'Received' => 'from outbound.itegra.no by mail-gateway.trusted.test with ESMTPS',
            ],
        ])->save();

        $facts = app(TrustedSenderAuthenticationFacts::class)->forMessage($message);

        $this->assertFalse($facts['authentication_passed']);
        $this->assertFalse($facts['aligned']);
        $this->assertNull($facts['authserv_id']);
        $this->assertNull($facts['spf']);
        $this->assertNull($facts['dkim']);
        $this->assertNull($facts['dmarc']);
    }

    #[Test]
    #[DataProvider('missingTrustedReceivingHopProvider')]
    public function configured_authserv_without_an_explicit_trusted_receiving_hop_fails_closed_for_supplier_order_preclassification(
        ?string $trustedReceivingHop,
    ): void {
        CommonSetting::query()->updateOrCreate(
            ['type' => 'emailhub', 'name' => 'trusted_authserv_ids'],
            ['value' => 'mx.trusted.test'],
        );

        if ($trustedReceivingHop !== null) {
            CommonSetting::query()->updateOrCreate(
                ['type' => 'emailhub', 'name' => 'trusted_receiving_hops'],
                ['value' => $trustedReceivingHop],
            );
        }

        $message = $this->emailMessage(7153);
        $message->forceFill([
            'subject' => 'Synthetic supplier order confirmation',
            'from_email' => 'orders@supplier.test',
            'headers_json' => [
                'Authentication-Results' => 'mx.trusted.test; spf=pass smtp.mailfrom=orders@supplier.test; dkim=pass header.d=supplier.test; dmarc=pass header.from=supplier.test',
                'Received' => 'from attacker.test by mail-gateway.forged.test with ESMTPS',
            ],
        ])->save();
        $this->activeInboxPlacement($message);

        EmailRule::query()->create([
            'name' => 'Synthetic supplier confirmation',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_PRECLASSIFICATION,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => [
                ['field' => 'from_domain', 'operator' => 'equals', 'value' => 'supplier.test'],
            ],
            'actions_json' => [
                ['type' => 'emit_signal', 'signal_type' => 'supplier_order_confirmation_received'],
            ],
        ]);

        app()->call([new ProcessInboundRules($message->id), 'handle']);

        $signal = Signal::query()
            ->where('source_id', $message->id)
            ->where('signal_type', 'supplier_order_confirmation_received')
            ->firstOrFail();

        $this->assertSame($this->emptyTrustedAuthenticationFacts(), $signal->payload['trusted_auth']);
        $this->assertNull($message->fresh()->ticket_id);
    }

    #[Test]
    public function trusted_receiver_retains_failed_unaligned_sender_facts(): void
    {
        $this->trustedAuthenticationSettings();

        $message = $this->emailMessage(7153);
        $message->forceFill([
            'from_email' => 'orders@itegra.no',
            'headers_json' => [
                'Authentication-Results' => 'mx.trusted.test; spf=pass smtp.mailfrom=mailer@attacker.test; dkim=fail header.d=attacker.test; dmarc=fail header.from=itegra.no',
                'Received' => 'from outbound.attacker.test by mail-gateway.trusted.test with ESMTPS',
            ],
        ])->save();

        $facts = app(TrustedSenderAuthenticationFacts::class)->forMessage($message);

        $this->assertTrue($facts['authentication_passed']);
        $this->assertFalse($facts['aligned']);
        $this->assertSame('mx.trusted.test', $facts['authserv_id']);
        $this->assertSame('mailer@attacker.test', $facts['authenticated_supplier_identity']);
        $this->assertSame('attacker.test', $facts['authenticated_supplier_domain']);
        $this->assertSame('pass', $facts['spf']);
        $this->assertSame('fail', $facts['dkim']);
        $this->assertSame('fail', $facts['dmarc']);
    }

    #[Test]
    public function trusted_aligned_authentication_snapshot_is_emitted_without_raw_headers(): void
    {
        $this->trustedAuthenticationSettings();

        $message = $this->emailMessage(7154);
        $message->forceFill([
            'subject' => 'Itegra order confirmation',
            'from_email' => 'orders@itegra.no',
            'headers_json' => [
                'Authentication-Results' => 'mx.trusted.test; spf=pass smtp.mailfrom=orders@itegra.no; dkim=pass header.d=itegra.no; dmarc=pass header.from=itegra.no',
                'Received' => 'from outbound.itegra.no by mail-gateway.trusted.test with ESMTPS',
                'X-Private-Trace' => 'must-not-leave-email',
            ],
        ])->save();
        $this->activeInboxPlacement($message);

        EmailRule::query()->create([
            'name' => 'Trusted supplier confirmation',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_PRECLASSIFICATION,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => [
                ['field' => 'from_domain', 'operator' => 'equals', 'value' => 'itegra.no'],
            ],
            'actions_json' => [
                ['type' => 'emit_signal', 'signal_type' => 'supplier_order_confirmation_received'],
            ],
        ]);

        app()->call([new ProcessInboundRules($message->id), 'handle']);

        $signal = Signal::query()
            ->where('source_id', $message->id)
            ->where('signal_type', 'supplier_order_confirmation_received')
            ->firstOrFail();

        $this->assertSame([
            'authentication_passed' => true,
            'authenticated_supplier_identity' => 'orders@itegra.no',
            'authenticated_supplier_domain' => 'itegra.no',
            'authserv_id' => 'mx.trusted.test',
            'spf' => 'pass',
            'dkim' => 'pass',
            'dmarc' => 'pass',
            'aligned' => true,
        ], $signal->payload['trusted_auth']);
        $this->assertArrayNotHasKey('headers', $signal->payload);
        $this->assertStringNotContainsString('X-Private-Trace', json_encode($signal->payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Authentication-Results', json_encode($signal->payload, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function attachment_persistence_sanitizes_names_and_is_idempotent(): void
    {
        Storage::fake('local');
        $this->attachmentSettings(maxCount: 5, maxSizeMb: 2, mimeTypes: 'application/pdf');
        $message = $this->emailMessage(7201);

        $attachment = new FakeInboundAttachment(
            '../../Faktura?.pdf',
            '%PDF-1.7 test document',
            'application/pdf; charset=binary',
            'inline',
            '<invoice-content-id>',
        );

        $persister = app(InboundAttachmentPersister::class);

        $this->assertSame(1, $persister->persist($message, [$attachment]));
        $this->assertSame(1, $persister->persist($message, [$attachment]));

        $row = EmailAttachment::query()->where('message_id', $message->id)->firstOrFail();

        $this->assertSame('Faktura_.pdf', $row->filename);
        $this->assertSame('application/pdf', $row->content_type);
        $this->assertSame(strlen('%PDF-1.7 test document'), $row->size_bytes);
        $this->assertSame(sha1('%PDF-1.7 test document'), $row->checksum_sha1);
        $this->assertTrue($row->is_inline);
        $this->assertSame('invoice-content-id', $row->cid);
        $this->assertStringNotContainsString('..', $row->path);
        $this->assertSame(1, EmailAttachment::query()->where('message_id', $message->id)->count());
        Storage::disk('local')->assertExists($row->path);
    }

    #[Test]
    public function attachment_policy_skips_disallowed_oversized_and_excess_files(): void
    {
        Storage::fake('local');
        $this->attachmentSettings(maxCount: 3, maxSizeMb: 1, mimeTypes: 'application/pdf');
        $message = $this->emailMessage(7202);

        $persisted = app(InboundAttachmentPersister::class)->persist($message, [
            new FakeInboundAttachment('accepted.pdf', 'small', 'application/pdf'),
            new FakeInboundAttachment('image.png', 'image', 'image/png'),
            new FakeInboundAttachment('large.pdf', str_repeat('x', 1024 * 1024 + 1), 'application/pdf'),
            new FakeInboundAttachment('excess.pdf', 'small', 'application/pdf'),
        ]);

        $this->assertSame(1, $persisted);
        $this->assertSame(['accepted.pdf'], EmailAttachment::query()
            ->where('message_id', $message->id)
            ->pluck('filename')
            ->all());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    private function vendorEmail(int $uid): EmailMessage
    {
        $message = EmailMessage::query()->create([
            'account_id' => $this->emailAccount()->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => "<qnap-{$uid}@example.test>",
            'subject' => 'QNAP Firmware Update Available',
            'from_email' => 'newsletter@qnap.com',
            'headers_json' => [],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'QNAP firmware update available for NAS devices. You can unsubscribe from notifications.',
        ]);

        $this->activeInboxPlacement($message);

        return $message;
    }

    private function emailMessage(int $uid): EmailMessage
    {
        return EmailMessage::query()->create([
            'account_id' => $this->emailAccount()->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => "<attachment-{$uid}@example.test>",
            'subject' => 'Attachment test',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Attachment test body.',
        ]);
    }

    /**
     * Project the exact provider occurrence required before inbound content may
     * enter any rule, Signal, notification, Ticket, or provider-write path.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function activeInboxPlacement(
        EmailMessage $message,
        ?EmailAccount $occurrenceAccount = null,
        array $overrides = [],
    ): EmailMailboxPlacement {
        $account = $occurrenceAccount ?? $message->account()->firstOrFail();
        $folder = EmailFolder::query()->firstOrCreate(
            [
                'account_id' => $account->id,
                'path' => 'INBOX',
            ],
            [
                'name' => 'INBOX',
                'role' => EmailFolder::ROLE_INBOX,
                'is_selectable' => true,
                'sync_enabled' => true,
                'uid_validity' => 1,
            ],
        );

        return EmailMailboxPlacement::query()->create(array_merge([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'provider' => 'imap',
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 1,
            'imap_uid' => $message->imap_uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
            'provider_missing_at' => null,
        ], $overrides));
    }

    private function emailAccount(array $overrides = []): EmailAccount
    {
        return EmailAccount::query()->create(array_merge([
            'address' => 'inbound-'.uniqid().'@example.test',
            'from_name' => 'Inbound',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'ticket_ingress_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'inbound@example.test',
            'imap_secret' => 'secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'inbound@example.test',
            'smtp_secret' => 'secret',
            'smtp_auth_type' => 'password',
        ], $overrides));
    }

    private function trustedAuthenticationSettings(): void
    {
        foreach ([
            'trusted_authserv_ids' => 'mx.trusted.test',
            'trusted_receiving_hops' => 'mail-gateway.trusted.test',
        ] as $name => $value) {
            CommonSetting::query()->updateOrCreate(
                ['type' => 'emailhub', 'name' => $name],
                ['value' => $value],
            );
        }
    }

    /** @return array<string, array{0: string|null}> */
    public static function missingTrustedReceivingHopProvider(): array
    {
        return [
            'missing setting' => [null],
            'empty setting' => [''],
        ];
    }

    /** @return array<string, bool|string|null> */
    private function emptyTrustedAuthenticationFacts(): array
    {
        return [
            'authentication_passed' => false,
            'authenticated_supplier_identity' => null,
            'authenticated_supplier_domain' => null,
            'authserv_id' => null,
            'spf' => null,
            'dkim' => null,
            'dmarc' => null,
            'aligned' => false,
        ];
    }

    private function attachmentSettings(int $maxCount, int $maxSizeMb, string $mimeTypes): void
    {
        foreach ([
            'attachment_max_count' => $maxCount,
            'attachment_max_size_mb' => $maxSizeMb,
            'attachment_allowed_mime_types' => $mimeTypes,
        ] as $name => $value) {
            CommonSetting::query()->updateOrCreate(
                ['type' => 'emailhub', 'name' => $name],
                ['value' => (string) $value],
            );
        }
    }
}

final class FakeInboundAttachment
{
    public function __construct(
        private readonly string $name,
        private readonly string $content,
        private readonly string $mimeType,
        private readonly string $disposition = 'attachment',
        private readonly ?string $contentId = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getFilename(): string
    {
        return $this->name;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getContentType(): string
    {
        return $this->mimeType;
    }

    public function getDisposition(): string
    {
        return $this->disposition;
    }

    public function getId(): ?string
    {
        return $this->contentId;
    }
}
