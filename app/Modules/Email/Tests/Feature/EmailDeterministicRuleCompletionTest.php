<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Jobs\ProcessEmailRuleReprocessRun;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleActionAttempt;
use App\Modules\Email\Models\EmailRuleReprocessRun;
use App\Modules\Email\Services\EmailRulePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailDeterministicRuleCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['email.inbox_view', 'email.rule_manage', 'email.rule_publish', 'email.rule_reprocess'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo([
            'email.inbox_view', 'email.rule_manage', 'email.rule_publish', 'email.rule_reprocess',
        ]);
        $this->account = EmailAccount::query()->create($this->accountPayload());
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $this->actor->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_at' => now(),
        ]);
    }

    #[Test]
    public function draft_is_isolated_until_separately_authorized_publication(): void
    {
        $rule = $this->publishedRule('Published name');
        Sanctum::actingAs($this->actor, ['email.rules.read', 'email.rules.write']);

        $response = $this->putJson(route('api.v1.email.rules.drafts.update', $rule), $this->draftPayload('Draft name'))
            ->assertOk()
            ->assertJsonPath('data.definition.name', 'Draft name');

        $this->assertSame('Published name', $rule->fresh()->name);
        $this->assertSame(1, $rule->versions()->count());
        $checksum = (string) $response->json('data.checksum');

        $this->getJson(route('api.v1.email.rules.publish-preview', $rule))
            ->assertOk()
            ->assertJsonPath('data.changed_fields.0', 'name')
            ->assertJsonPath('data.account_ids.0', $this->account->id);

        $this->postJson(route('api.v1.email.rules.publish', $rule), ['draft_checksum' => $checksum])
            ->assertCreated()
            ->assertJsonPath('data.version_number', 2);

        $this->assertSame('Draft name', $rule->fresh()->name);
        $this->assertSame(2, $rule->versions()->count());
        $this->assertNull($rule->draft()->first());
    }

    #[Test]
    public function draft_save_has_no_taxonomy_side_effect_and_published_versions_are_immutable(): void
    {
        $rule = $this->publishedRule('Immutable published rule');
        Sanctum::actingAs($this->actor, ['email.rules.read', 'email.rules.write']);
        $payload = $this->draftPayload('Draft taxonomy target');
        $payload['actions_json'] = [['type' => 'tag_conversation', 'value' => 'Needs response']];

        $draft = $this->putJson(route('api.v1.email.rules.drafts.update', $rule), $payload)
            ->assertOk();
        $this->assertDatabaseMissing('tags', ['slug' => 'needs-response']);

        $this->postJson(route('api.v1.email.rules.publish', $rule), [
            'draft_checksum' => $draft->json('data.checksum'),
        ])->assertCreated();
        $this->assertDatabaseHas('tags', ['slug' => 'needs-response', 'active' => true]);

        $version = $rule->fresh()->publishedVersion;
        $this->expectException(\LogicException::class);
        $version->forceFill(['name' => 'Mutated snapshot'])->save();
    }

    #[Test]
    public function admin_save_defaults_to_an_inactive_draft_instead_of_implicit_publication(): void
    {
        $response = $this->actingAs($this->actor)->post(route('tech.admin.settings.email.rules.store'), [
            'name' => 'Admin draft only',
            'description' => 'Must not run before explicit publication.',
            'weight' => 10,
            'account_ids' => [$this->account->id],
            'is_active' => '1',
            'stop_processing' => '0',
            'conditions' => [[
                'field' => 'subject', 'operator' => 'contains', 'value' => 'candidate',
            ]],
            'actions' => [['type' => 'archive', 'value' => '']],
        ]);

        $rule = EmailRule::query()->where('name', 'Admin draft only')->firstOrFail();
        $response->assertRedirect(route('tech.admin.settings.email.rules.edit', $rule));
        $this->assertFalse($rule->is_active);
        $this->assertSame(EmailRule::LIFECYCLE_DRAFT, $rule->lifecycle_status);
        $this->assertNotNull($rule->draft()->first());
        $this->assertSame(0, $rule->versions()->count());
    }

    #[Test]
    public function bounded_preview_is_durable_and_full_rerun_never_replays_success(): void
    {
        Queue::fake();
        $rule = $this->publishedRule('Archive candidate');
        [$message, $placement] = $this->messageAndPlacement('Archive candidate');
        Sanctum::actingAs($this->actor, ['email.rules.read', 'email.rules.execute']);

        $preview = $this->postJson(route('api.v1.email.rules.reprocess-preview', $rule), [
            'account_id' => $this->account->id,
            'message_ids' => [$message->id],
            'cap' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'preview')
            ->assertJsonPath('data.matched_count', 1)
            ->assertJsonPath('data.items.0.actions.0.status', 'would_run');

        $run = EmailRuleReprocessRun::query()->where('public_id', $preview->json('data.id'))->firstOrFail();
        $this->assertSame('untriaged', $message->fresh()->state);

        $this->postJson(route('api.v1.email.rules.runs.apply', $run))
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued');
        (new ProcessEmailRuleReprocessRun($run->id))->handle(
            app(\App\Modules\Email\Services\InboundEmailRuleEngine::class),
            app(\App\Modules\Email\Services\EmailRuleReprocessService::class),
        );

        $this->assertSame('archived', $message->fresh()->state);
        $this->assertSame(1, EmailRuleActionAttempt::query()->where('status', 'succeeded')->count());
        $run->refresh();
        $this->assertSame(EmailRuleReprocessRun::STATUS_SUCCEEDED, $run->status);

        $rerunResponse = $this->postJson(route('api.v1.email.rules.runs.full-rerun', $run), [
            'confirmation' => 'FULL RERUN',
        ])->assertAccepted();
        $rerun = EmailRuleReprocessRun::query()->where('public_id', $rerunResponse->json('data.id'))->firstOrFail();
        (new ProcessEmailRuleReprocessRun($rerun->id))->handle(
            app(\App\Modules\Email\Services\InboundEmailRuleEngine::class),
            app(\App\Modules\Email\Services\EmailRuleReprocessService::class),
        );

        $this->assertSame(1, EmailRuleActionAttempt::query()->where('status', 'succeeded')->count());
        $this->assertSame('already_succeeded', $rerun->fresh('items')->items->first()->action_summary_json[0]['reason_code']);
        $this->assertSame($placement->id, $run->items()->first()->email_mailbox_placement_id);
    }

    #[Test]
    public function publish_and_reprocess_require_separate_global_permissions(): void
    {
        $rule = $this->publishedRule('Permission boundary');
        $draft = app(\App\Modules\Email\Services\EmailRuleDraftService::class)
            ->save($rule, $this->draftPayload('Permission draft'), $this->actor);
        $limited = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $limited->givePermissionTo('email.rule_manage');
        Sanctum::actingAs($limited, ['email.rules.write', 'email.rules.execute']);

        $this->postJson(route('api.v1.email.rules.publish', $rule), ['draft_checksum' => $draft->checksum])
            ->assertForbidden();
        $this->postJson(route('api.v1.email.rules.reprocess-preview', $rule), [
            'account_id' => $this->account->id,
            'message_ids' => [999],
        ])->assertForbidden();
    }

    #[Test]
    public function every_selection_is_bounded_and_stale_or_expired_previews_fail_closed(): void
    {
        Queue::fake();
        $rule = $this->publishedRule('Selection candidate');
        [$first, $firstPlacement] = $this->messageAndPlacement('Selection candidate alpha');
        [$second] = $this->messageAndPlacement('Selection candidate beta');
        Sanctum::actingAs($this->actor, ['email.rules.read', 'email.rules.execute']);

        $this->postJson(route('api.v1.email.rules.reprocess-preview', $rule), [
            'account_id' => $this->account->id,
            'message_ids' => [$first->id],
        ])->assertCreated()->assertJsonPath('data.requested_count', 1);

        $overflow = $this->postJson(route('api.v1.email.rules.reprocess-preview', $rule), [
            'account_id' => $this->account->id,
            'folder_id' => $firstPlacement->email_folder_id,
            'cap' => 1,
        ])->assertCreated()->assertJsonPath('data.overflow', true);
        $overflowRun = EmailRuleReprocessRun::query()->where('public_id', $overflow->json('data.id'))->firstOrFail();
        $this->postJson(route('api.v1.email.rules.runs.apply', $overflowRun))->assertUnprocessable();

        $this->postJson(route('api.v1.email.rules.reprocess-preview', $rule), [
            'account_id' => $this->account->id,
            'search' => 'alpha',
        ])->assertCreated()->assertJsonPath('data.requested_count', 1);
        $searchRun = EmailRuleReprocessRun::query()->latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('search', $searchRun->selection_json);
        $this->assertNotSame('alpha', $searchRun->selection_json['search_ciphertext']);
        $this->postJson(route('api.v1.email.rules.reprocess-preview', $rule), [
            'account_id' => $this->account->id,
            'utc_date' => now()->utc()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.requested_count', 2);

        $stale = $this->postJson(route('api.v1.email.rules.reprocess-preview', $rule), [
            'account_id' => $this->account->id,
            'message_ids' => [$second->id],
        ])->assertCreated();
        $staleRun = EmailRuleReprocessRun::query()->where('public_id', $stale->json('data.id'))->firstOrFail();
        $staleRun->items()->firstOrFail()->placement()->firstOrFail()->increment('sync_version');
        $this->postJson(route('api.v1.email.rules.runs.apply', $staleRun))->assertUnprocessable();

        $expired = EmailRuleReprocessRun::query()->where('status', EmailRuleReprocessRun::STATUS_PREVIEW)->firstOrFail();
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->postJson(route('api.v1.email.rules.runs.apply', $expired))->assertUnprocessable();
    }

    private function publishedRule(string $name): EmailRule
    {
        $rule = EmailRule::query()->create([
            'name' => $name,
            'description' => 'Deterministic completion test.',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_NORMAL,
            'rule_kind' => EmailRule::KIND_ADMIN,
            'weight' => 10,
            'is_active' => true,
            'lifecycle_status' => EmailRule::LIFECYCLE_PUBLISHED,
            'stop_processing' => false,
            'conditions_json' => [[
                'field' => 'subject',
                'operator' => 'contains',
                'value' => 'candidate',
            ]],
            'actions_json' => [['type' => 'archive', 'value' => '']],
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $rule->accounts()->sync([$this->account->id]);
        app(EmailRulePublisher::class)->publish($rule, $this->actor);

        return $rule->fresh();
    }

    /** @return array<string, mixed> */
    private function draftPayload(string $name): array
    {
        return [
            'name' => $name,
            'description' => 'Draft definition.',
            'weight' => 20,
            'routing_phase' => EmailRule::ROUTING_PHASE_NORMAL,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => ['match' => 'all', 'groups' => [[
                'name' => 'Default',
                'match' => 'all',
                'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'candidate']],
            ]]],
            'actions_json' => [['type' => 'archive', 'value' => '']],
            'account_ids' => [$this->account->id],
        ];
    }

    /** @return array{EmailMessage, EmailMailboxPlacement} */
    private function messageAndPlacement(string $subject): array
    {
        $uid = ((int) EmailMessage::query()->max('imap_uid')) + 10;
        $folder = EmailFolder::query()->firstOrCreate([
            'account_id' => $this->account->id,
            'path' => 'INBOX',
        ], [
            'name' => 'Inbox',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 55,
        ]);
        $message = EmailMessage::query()->create([
            'account_id' => $this->account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'imap_uid_validity' => 55,
            'message_id' => '<'.Str::uuid().'@example.test>',
            'subject' => $subject,
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $this->account->id,
            'email_folder_id' => $folder->id,
            'provider' => 'imap',
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 55,
            'imap_uid' => $uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);

        return [$message, $placement];
    }

    /** @return array<string, mixed> */
    private function accountPayload(): array
    {
        return [
            'address' => 'rules@example.test',
            'description' => 'Rules test account',
            'from_name' => 'Rules',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => true,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'rules@example.test',
            'imap_secret' => 'placeholder',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'rules@example.test',
            'smtp_secret' => 'placeholder',
            'smtp_auth_type' => 'password',
        ];
    }
}
