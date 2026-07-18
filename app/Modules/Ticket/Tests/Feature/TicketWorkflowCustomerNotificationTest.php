<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\CustomerPortalNotification;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Jobs\SendTicketWorkflowCustomerUpdate;
use App\Modules\Ticket\Livewire\Admin\WorkflowEditor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Services\TicketWorkflowDefinitionService;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketWorkflowCustomerNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Tech', 'web');
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        app(EnsureTicketDefaults::class)->handle();
        app(EnsureDefaultEmailTemplates::class)->handle();
    }

    #[Test]
    public function workflow_editor_exposes_a_compact_customer_update_policy(): void
    {
        $component = Livewire::test(WorkflowEditor::class, [
            'statuses' => \App\Modules\Ticket\Models\TicketStatus::query()->orderBy('sort_order')->get(),
            'triggerActions' => TicketAction::definitions(),
        ])->call('addStateAfter', 0);

        $component
            ->assertSee('Customer update')
            ->assertSee('Notify customer')
            ->assertSee('Ticket status update')
            ->assertSet('transitions.0.customer_notification.enabled', false)
            ->set('transitions.0.customer_notification.enabled', true)
            ->set('transitions.0.customer_notification.channels', ['email'])
            ->set('transitions.0.customer_notification.message', 'We have started working on your Ticket.')
            ->assertSet('transitions.0.customer_notification.email_template_key', 'ticket_status_update');
    }

    #[Test]
    public function draft_and_published_definition_preserve_the_notification_policy_and_reject_unknown_channels(): void
    {
        [$workflow, $definition] = $this->workflowDefinition('definition-customer-update');
        $definition['transitions'][0]['customer_notification'] = $this->policy(['email', 'portal']);
        $definitions = app(TicketWorkflowDefinitionService::class);

        $definitions->saveDraft($workflow, $definition);
        $version = $definitions->publish($workflow, $this->tech);

        $this->assertSame(['email', 'portal'], $workflow->refresh()->transitions()->firstOrFail()->customer_notification['channels']);
        $this->assertSame('ticket_status_update', data_get($version->definition, 'transitions.0.customer_notification.email_template_key'));
        $this->assertSame(TicketWorkflowDefinitionService::CURRENT_SCHEMA_VERSION, $version->definition['schema_version']);

        $definition['transitions'][0]['customer_notification'] = $this->policy(['carrier_pigeon']);
        try {
            $definitions->saveDraft($workflow, $definition);
            $this->fail('Unknown customer notification channels must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transitions', $exception->errors());
        }

        $definition['transitions'][0]['customer_notification'] = $this->policy(['email']);
        $definition['transitions'][0]['customer_notification']['email_template_key'] = 'missing-template';
        try {
            $definitions->saveDraft($workflow, $definition);
            $this->fail('An unavailable Ticket Email template must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transitions', $exception->errors());
        }
    }

    #[Test]
    public function internal_note_trigger_queues_one_public_customer_update_for_a_published_ticket(): void
    {
        Queue::fake();
        [$workflow, $definition] = $this->workflowDefinition('internal-note-customer-update');
        $definition['transitions'][0]['trigger_actions'] = [TicketAction::ADD_INTERNAL_NOTE];
        $definition['transitions'][0]['manual_enabled'] = false;
        $definition['transitions'][0]['customer_notification'] = $this->policy(['email']);
        app(TicketWorkflowDefinitionService::class)->saveDraft($workflow, $definition);
        app(TicketWorkflowDefinitionService::class)->publish($workflow, $this->tech);
        [$ticket] = $this->publishedTicket($workflow);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.messages.store', $ticket), [
                'type' => 'internal_note',
                'visibility' => 'internal',
                'body' => 'Internal diagnosis that starts work.',
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame('work', $ticket->workflow_state_key);
        $statusUpdate = $ticket->messages()->where('type', 'status_update')->sole();
        $this->assertSame('public', $statusUpdate->visibility);
        $this->assertStringContainsString('Status: In Progress', $statusUpdate->body);
        $this->assertStringNotContainsString('Internal diagnosis', $statusUpdate->body);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'workflow_customer_update_queued',
        ]);
        Queue::assertPushed(SendTicketWorkflowCustomerUpdate::class, fn ($job) => $job->ticketMessageId === $statusUpdate->id);
    }

    #[Test]
    public function manual_ticket_page_transition_uses_the_same_customer_update_runtime(): void
    {
        Queue::fake();
        [$workflow, $definition] = $this->workflowDefinition('manual-page-customer-update');
        $definition['transitions'][0]['customer_notification'] = $this->policy(['portal']);
        app(TicketWorkflowDefinitionService::class)->saveDraft($workflow, $definition);
        app(TicketWorkflowDefinitionService::class)->publish($workflow, $this->tech);
        [$ticket] = $this->publishedTicket($workflow);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.workflow-v3.transition', [$ticket, 'start-work']), [
                'idempotency_key' => 'manual-page-'.$ticket->id,
            ])
            ->assertRedirect(route('tech.tickets.show', $ticket));

        $this->assertSame('work', $ticket->refresh()->workflow_state_key);
        $statusUpdate = $ticket->messages()->where('type', 'status_update')->sole();
        $this->assertSame(['portal'], data_get($statusUpdate->metadata, 'customer_status_update.policy.channels'));
        Queue::assertPushed(
            SendTicketWorkflowCustomerUpdate::class,
            fn ($job) => $job->ticketMessageId === $statusUpdate->id,
        );
    }

    #[Test]
    public function unpublished_ticket_transitions_but_remains_silent(): void
    {
        Queue::fake();
        [$workflow, $definition] = $this->workflowDefinition('unpublished-customer-update');
        $definition['transitions'][0]['customer_notification'] = $this->policy(['email', 'portal']);
        app(TicketWorkflowDefinitionService::class)->saveDraft($workflow, $definition);
        app(TicketWorkflowDefinitionService::class)->publish($workflow, $this->tech);
        [$ticket] = $this->ticketWithContact($workflow);

        Sanctum::actingAs($this->tech, ['tickets.actions']);
        $this->postJson(route('api.v1.tickets.workflow-transitions.store', [$ticket, 'start-work']))
            ->assertOk()
            ->assertJsonPath('data.workflow_state_key', 'work');

        $this->assertSame(0, $ticket->messages()->where('type', 'status_update')->count());
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'workflow_customer_update_skipped',
        ]);
        $history = $ticket->workflowHistory()->where('transition_key', 'start-work')->sole();
        $this->assertSame('skipped', data_get($history->metadata, 'customer_notification.status'));
        Queue::assertNotPushed(SendTicketWorkflowCustomerUpdate::class);
    }

    #[Test]
    public function api_idempotency_does_not_create_or_queue_the_update_twice(): void
    {
        Queue::fake();
        [$workflow, $definition] = $this->workflowDefinition('idempotent-customer-update');
        $definition['transitions'][0]['customer_notification'] = $this->policy(['email']);
        app(TicketWorkflowDefinitionService::class)->saveDraft($workflow, $definition);
        app(TicketWorkflowDefinitionService::class)->publish($workflow, $this->tech);
        [$ticket] = $this->publishedTicket($workflow);
        Sanctum::actingAs($this->tech, ['tickets.actions']);
        $payload = ['idempotency_key' => 'customer-update-'.$ticket->id];

        $this->postJson(route('api.v1.tickets.workflow-transitions.store', [$ticket, 'start-work']), $payload)->assertOk();
        $this->postJson(route('api.v1.tickets.workflow-transitions.store', [$ticket, 'start-work']), $payload)->assertOk();

        $this->assertSame(1, $ticket->messages()->where('type', 'status_update')->count());
        $this->assertSame(1, $ticket->workflowHistory()->where('idempotency_key', $payload['idempotency_key'])->count());
        Queue::assertPushed(SendTicketWorkflowCustomerUpdate::class, 1);
    }

    #[Test]
    public function email_job_renders_selected_template_and_records_delivery_without_portal_email_duplication(): void
    {
        [$ticket, $client, $site, $contact] = $this->publishedTicket();
        $portalUser = $this->portalUser($client, $site);
        $account = EmailAccount::query()->create($this->emailAccountData([
            'address' => 'workflow@example.test',
            'defaults_for' => ['tickets'],
        ]));
        $message = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'status_update',
            'visibility' => 'public',
            'subject' => '['.$ticket->ticket_key.'] Status update',
            'body' => "Status: In Progress\n\nWe have started working on your Ticket.",
            'metadata' => [
                'workflow_history_id' => 123,
                'customer_status_update' => [
                    'policy' => $this->policy(['email', 'portal']),
                    'previous_status' => 'New',
                    'current_status' => 'In Progress',
                    'customer_message' => 'We have started working on your Ticket.',
                    'delivery' => [],
                ],
            ],
        ]);

        $this->mock(SmtpAccountMailer::class, function ($mock) use ($account, $contact): void {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn ($resolvedAccount, $toEmail, $toName, $subject, $html, $text) => $resolvedAccount->is($account)
                    && $toEmail === $contact->email
                    && $toName === $contact->name
                    && str_contains($subject, 'Status update')
                    && str_contains($html, 'In Progress')
                    && str_contains($html, 'We have started working')
                    && str_contains($text, 'Previous status: New')
                )
                ->andReturn('<workflow-status@example.test>');
        });

        (new SendTicketWorkflowCustomerUpdate($message->id))->handle(
            app(DefaultEmailAccountResolver::class),
            app(EmailTemplateRenderer::class),
            app(SmtpAccountMailer::class),
            app(SendCustomerPortalNotification::class),
        );

        $this->assertDatabaseHas('email_logs', [
            'account_id' => $account->id,
            'scope' => 'tickets',
            'code' => 'TICKET_STATUS_UPDATE_SENT',
            'rfc_message_id' => '<workflow-status@example.test>',
        ]);
        $this->assertSame('sent', data_get($message->fresh()->metadata, 'customer_status_update.delivery.email.status'));
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'workflow_customer_update_sent',
        ]);
        $this->assertSame('sent', data_get($message->fresh()->metadata, 'customer_status_update.delivery.portal.status'));
        $portalNotification = $portalUser->notifications()->where('type', CustomerPortalNotification::class)->sole();
        $this->assertSame('portal_ticket_status_changed', $portalNotification->data['type']);
        $this->assertSame($message->id, $portalNotification->data['metadata']['ticket_message_id']);
    }

    #[Test]
    public function email_job_records_missing_customer_and_configuration_failures(): void
    {
        [$ticket, , , $contact] = $this->publishedTicket();
        $message = $this->statusUpdateMessage($ticket);
        $contact->forceFill(['active' => false])->save();

        (new SendTicketWorkflowCustomerUpdate($message->id))->handle(
            app(DefaultEmailAccountResolver::class),
            app(EmailTemplateRenderer::class),
            app(SmtpAccountMailer::class),
            app(SendCustomerPortalNotification::class),
        );

        $this->assertDatabaseHas('email_logs', [
            'scope' => 'tickets',
            'code' => 'TICKET_STATUS_UPDATE_NO_CONTACT',
        ]);
        $this->assertSame('failed', data_get($message->fresh()->metadata, 'customer_status_update.delivery.email.status'));

        $contact->forceFill(['active' => true])->save();
        $messageWithoutAccount = $this->statusUpdateMessage($ticket);
        (new SendTicketWorkflowCustomerUpdate($messageWithoutAccount->id))->handle(
            app(DefaultEmailAccountResolver::class),
            app(EmailTemplateRenderer::class),
            app(SmtpAccountMailer::class),
            app(SendCustomerPortalNotification::class),
        );
        $this->assertDatabaseHas('email_logs', [
            'scope' => 'tickets',
            'code' => 'TICKET_STATUS_UPDATE_NO_ACCOUNT',
        ]);

        EmailAccount::query()->create($this->emailAccountData(['defaults_for' => ['tickets']]));
        \App\Modules\Email\Models\EmailTemplate::query()
            ->where('scope', 'tickets')
            ->where('key', 'ticket_status_update')
            ->update(['is_active' => false]);
        $messageWithoutTemplate = $this->statusUpdateMessage($ticket);
        (new SendTicketWorkflowCustomerUpdate($messageWithoutTemplate->id))->handle(
            app(DefaultEmailAccountResolver::class),
            app(EmailTemplateRenderer::class),
            app(SmtpAccountMailer::class),
            app(SendCustomerPortalNotification::class),
        );
        $this->assertDatabaseHas('email_logs', [
            'scope' => 'tickets',
            'code' => 'TICKET_STATUS_UPDATE_NO_TEMPLATE',
        ]);
    }

    #[Test]
    public function smtp_failure_is_audited_without_reverting_the_completed_transition(): void
    {
        Queue::fake();
        [$workflow, $definition] = $this->workflowDefinition('smtp-failure-customer-update');
        $definition['transitions'][0]['customer_notification'] = $this->policy(['email']);
        app(TicketWorkflowDefinitionService::class)->saveDraft($workflow, $definition);
        app(TicketWorkflowDefinitionService::class)->publish($workflow, $this->tech);
        [$ticket] = $this->publishedTicket($workflow);

        $this->actingAs($this->tech)
            ->post(route('tech.tickets.workflow-v3.transition', [$ticket, 'start-work']))
            ->assertRedirect();
        $message = $ticket->messages()->where('type', 'status_update')->sole();
        EmailAccount::query()->create($this->emailAccountData(['defaults_for' => ['tickets']]));
        $this->mock(SmtpAccountMailer::class, function ($mock): void {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        });

        try {
            (new SendTicketWorkflowCustomerUpdate($message->id))->handle(
                app(DefaultEmailAccountResolver::class),
                app(EmailTemplateRenderer::class),
                app(SmtpAccountMailer::class),
                app(SendCustomerPortalNotification::class),
            );
            $this->fail('The queued job must rethrow SMTP failures so the queue can retry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SMTP unavailable', $exception->getMessage());
        }

        $this->assertSame('work', $ticket->refresh()->workflow_state_key);
        $this->assertDatabaseHas('email_logs', [
            'scope' => 'tickets',
            'code' => 'TICKET_STATUS_UPDATE_SEND_FAILED',
        ]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'type' => 'workflow_customer_update_failed',
        ]);
    }

    #[Test]
    public function portal_channel_override_prevents_the_generic_notification_mail_channel(): void
    {
        $portalUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        NotificationSetting::query()->create([
            'user_id' => $portalUser->id,
            'notification_type' => 'portal_ticket_status_changed',
            'mail_enabled' => true,
            'database_enabled' => true,
            'nextcloud_talk_enabled' => false,
        ]);
        $notification = new CustomerPortalNotification([
            'type' => 'portal_ticket_status_changed',
            'title' => 'Status updated',
            'body' => 'The Ticket is now In Progress.',
            'url' => '/portal/tickets/1',
        ], ['database']);

        $this->assertSame(['database'], $notification->via($portalUser));
    }

    /** @return array{0: TicketWorkflow, 1: array<string, mixed>} */
    private function workflowDefinition(string $slug): array
    {
        $new = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'new')->firstOrFail();
        $inProgress = \App\Modules\Ticket\Models\TicketStatus::query()->where('slug', 'in-progress')->firstOrFail();
        $workflow = TicketWorkflow::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 90,
        ]);

        return [$workflow, [
            'states' => [
                $this->state('intake', $new->id, 'Intake', true),
                $this->state('work', $inProgress->id, 'Work'),
            ],
            'transitions' => [[
                'transition_key' => 'start-work',
                'from_state_key' => 'intake',
                'to_state_key' => 'work',
                'label' => 'Start work',
                'manual_enabled' => true,
                'trigger_actions' => [],
                'requirements' => ['match' => 'all', 'groups' => []],
                'customer_notification' => $this->policy([]),
                'sort_order' => 10,
            ]],
            'escalation_paths' => [],
        ]];
    }

    /** @return array{0: Ticket, 1: Client, 2: ClientSite, 3: ClientUser} */
    private function ticketWithContact(?TicketWorkflow $workflow = null): array
    {
        $client = Client::factory()->create(['name' => 'Workflow Notification Customer']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main site']);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Kari Customer',
            'email' => 'kari@example.test',
            'active' => true,
        ]);
        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'Workflow customer update',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'contact_id' => $contact->id,
            'workflow_id' => $workflow?->id,
        ], $this->tech);

        return [$ticket, $client, $site, $contact];
    }

    /** @return array{0: Ticket, 1: Client, 2: ClientSite, 3: ClientUser} */
    private function publishedTicket(?TicketWorkflow $workflow = null): array
    {
        [$ticket, $client, $site, $contact] = $this->ticketWithContact($workflow);
        $ticket->forceFill([
            'portal_visible_at' => now(),
            'portal_visible_by' => $this->tech->id,
        ])->save();

        return [$ticket->refresh(), $client, $site, $contact];
    }

    private function portalUser(Client $client, ClientSite $site): User
    {
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Workflow Portal Customer',
        ]);
        ContactEmail::query()->create([
            'contact_id' => $contact->id,
            'label' => 'work',
            'email' => 'portal-workflow@example.test',
            'is_primary' => true,
            'is_verified' => true,
        ]);
        foreach ([$client, $site] as $related) {
            ContactRelation::query()->create([
                'contact_id' => $contact->id,
                'related_type' => $related->getMorphClass(),
                'related_id' => $related->id,
                'relation_type' => 'contact',
                'is_primary' => true,
            ]);
        }

        $user = User::factory()->create([
            'contact_id' => $contact->id,
            'name' => $contact->display_name,
            'email' => 'portal-workflow@example.test',
            'status' => User::STATUS_ACTIVE,
        ]);
        $account = CustomerPortalAccount::query()->create([
            'user_id' => $user->id,
            'contact_id' => $contact->id,
            'status' => CustomerPortalAccount::STATUS_ACTIVE,
        ]);
        CustomerPortalMembership::query()->create([
            'customer_portal_account_id' => $account->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'role' => CustomerPortalMembership::ROLE_VIEWER,
            'status' => CustomerPortalMembership::STATUS_ACTIVE,
        ]);
        NotificationSetting::query()->create([
            'user_id' => $user->id,
            'notification_type' => 'portal_ticket_status_changed',
            'mail_enabled' => true,
            'database_enabled' => true,
            'nextcloud_talk_enabled' => false,
        ]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function state(string $key, int $statusId, string $name, bool $initial = false): array
    {
        return [
            'state_key' => $key,
            'ticket_status_id' => $statusId,
            'name' => $name,
            'is_initial' => $initial,
            'is_terminal' => false,
            'requirements' => ['match' => 'all', 'groups' => []],
            'action_policy' => [],
            'assignment_policy' => [],
            'commercial_policy' => [],
            'sort_order' => $initial ? 10 : 20,
        ];
    }

    /** @return array<string, mixed> */
    private function policy(array $channels): array
    {
        return [
            'enabled' => $channels !== [],
            'channels' => $channels,
            'email_template_key' => 'ticket_status_update',
            'message' => 'We have started working on your Ticket.',
        ];
    }

    private function statusUpdateMessage(Ticket $ticket): TicketMessage
    {
        return TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->tech->id,
            'author_type' => 'user',
            'type' => 'status_update',
            'visibility' => 'public',
            'subject' => '['.$ticket->ticket_key.'] Status update',
            'body' => "Status: In Progress\n\nWe have started working on your Ticket.",
            'metadata' => [
                'workflow_history_id' => 321,
                'customer_status_update' => [
                    'policy' => $this->policy(['email']),
                    'previous_status' => 'New',
                    'current_status' => 'In Progress',
                    'customer_message' => 'We have started working on your Ticket.',
                    'delivery' => [],
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function emailAccountData(array $overrides = []): array
    {
        return array_merge([
            'address' => 'ticket@example.test',
            'description' => 'Ticket account',
            'from_name' => 'Support',
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'ticket@example.test',
            'imap_secret' => encrypt('secret'),
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => 'ticket@example.test',
            'smtp_secret' => encrypt('secret'),
            'smtp_auth_type' => 'password',
        ], $overrides);
    }
}
