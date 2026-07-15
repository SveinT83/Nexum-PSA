<?php

namespace App\Modules\Signal\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\Contact\Models\Contact;
use App\Modules\CustomerPortal\Jobs\SendCustomerPortalInvitationEmail;
use App\Modules\CustomerPortal\Models\CustomerPortalInvitation;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Marketing\Models\MarketingCampaignEvent;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Signal\Actions\EnsureSignalDefaults;
use App\Modules\Signal\Actions\ExecuteSignalAction;
use App\Modules\Signal\Actions\ProcessSignalRules;
use App\Modules\Signal\Actions\RecordSignal;
use App\Modules\Signal\Controllers\Api\V1\SignalController as SignalApiController;
use App\Modules\Signal\Controllers\Tech\SignalController;
use App\Modules\Signal\Controllers\Tech\SignalSettingsController;
use App\Modules\Signal\Jobs\DeliverSignalWebhook;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Signal\Models\SignalRuleExecution;
use App\Modules\Signal\Models\SignalWebhookDelivery;
use App\Modules\Signal\Support\SignalSettings;
use App\Modules\Task\Models\Task;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SignalModuleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function signal_route_is_owned_by_signal_module(): void
    {
        $this->assertSame(
            SignalController::class.'@index',
            Route::getRoutes()->getByName('tech.admin.system.signals.index')->getActionName(),
        );
        $this->assertSame(
            SignalApiController::class.'@store',
            Route::getRoutes()->getByName('api.v1.signals.store')->getActionName(),
        );
        $this->assertSame(
            SignalSettingsController::class.'@edit',
            Route::getRoutes()->getByName('tech.admin.system.signals.settings.edit')->getActionName(),
        );
    }

    #[Test]
    public function signal_defaults_seed_default_ai_agent_for_active_provider(): void
    {
        $provider = AiProvider::query()->create([
            'name' => 'OpenAI signal',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-signal',
            'status' => 'active',
        ]);

        app(EnsureSignalDefaults::class)->handle();

        $agent = AiAgent::query()->where('slug', 'signal-classification-agent')->firstOrFail();

        $this->assertSame($provider->id, $agent->ai_provider_id);
        $this->assertSame('Signal Classification Agent', $agent->name);
        $this->assertSame('gpt-signal', $agent->model);
        $this->assertSame(['signal'], $agent->default_domains);
        $this->assertFalse($agent->can_execute_actions);
        $this->assertTrue($agent->is_active);
    }

    #[Test]
    public function signal_feed_requires_signal_view_permission(): void
    {
        Permission::findOrCreate('signal.view', 'web');
        Permission::findOrCreate('marketing.view', 'web');

        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo('signal.view');

        $blocked = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $blocked->givePermissionTo('marketing.view');

        $this->actingAs($viewer)
            ->get(route('tech.admin.system.signals.index'))
            ->assertOk()
            ->assertViewIs('signal::Tech.index');

        $this->actingAs($blocked)
            ->get(route('tech.admin.system.signals.index'))
            ->assertForbidden();
    }

    #[Test]
    public function signal_settings_can_update_ai_classification_policy(): void
    {
        Permission::findOrCreate('signal.view', 'web');
        Permission::findOrCreate('signal.rule.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['signal.view', 'signal.rule.manage']);

        $this->actingAs($user)
            ->get(route('tech.admin.system.signals.settings.edit'))
            ->assertOk()
            ->assertViewIs('signal::Tech.settings')
            ->assertSee('AI Classification');

        $this->actingAs($user)
            ->put(route('tech.admin.system.signals.settings.update'), [
                'ai_classification_enabled' => '1',
                'ai_min_confidence' => 75,
                'ai_source_domains' => "email\nqnap",
                'ai_allowed_signal_types' => "vendor_notification\nsecurity_alert",
                'ai_stop_ticket_routing_types' => "vendor_notification\nsecurity_alert",
                'ai_classification_prompt' => 'Return grounded Signal JSON only.',
            ])
            ->assertRedirect(route('tech.admin.system.signals.settings.edit'));

        $payload = json_decode(CommonSetting::query()
            ->where('type', 'signal')
            ->where('name', 'settings')
            ->value('json'), true);

        $this->assertTrue($payload['ai_classification_enabled']);
        $this->assertSame(75, $payload['ai_min_confidence']);
        $this->assertSame(['email', 'qnap'], $payload['ai_source_domains']);
        $this->assertSame(['vendor_notification', 'security_alert'], $payload['ai_allowed_signal_types']);
        $this->assertSame('Return grounded Signal JSON only.', $payload['ai_classification_prompt']);
    }

    #[Test]
    public function authenticated_api_user_can_record_signal_with_create_scope(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($user, ['signals.create']);

        $client = Client::factory()->create(['name' => 'QNAP Client']);

        SignalRule::query()->create([
            'name' => 'QNAP warning ticket',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [
                'source_domain' => ['qnap'],
                'signal_type' => ['firmware_update_available'],
                'min_confidence' => 80,
            ],
            'actions' => [
                ['type' => 'ticket_follow_up', 'subject' => 'Review QNAP firmware update'],
            ],
        ]);

        $this->postJson(route('api.v1.signals.store'), [
            'source_domain' => 'qnap',
            'client_id' => $client->id,
            'signal_type' => 'firmware_update_available',
            'severity' => 'warning',
            'confidence' => 95,
            'summary' => 'QNAP firmware update is available.',
            'payload' => [
                'device' => 'NAS-01',
                'version' => '5.2.0',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.source_domain', 'qnap')
            ->assertJsonPath('data.signal_type', 'firmware_update_available')
            ->assertJsonPath('data.executions_count', 1);

        $this->assertDatabaseHas('signals', [
            'source_domain' => 'qnap',
            'signal_type' => 'firmware_update_available',
            'client_id' => $client->id,
        ]);
        $this->assertDatabaseHas('tickets', [
            'client_id' => $client->id,
            'channel' => 'signal',
            'subject' => 'Review QNAP firmware update',
        ]);
    }

    #[Test]
    public function api_user_without_signal_create_scope_cannot_record_signal(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($user, ['signals.read']);

        $this->postJson(route('api.v1.signals.store'), [
            'source_domain' => 'qnap',
            'signal_type' => 'firmware_update_available',
        ])->assertForbidden();
    }

    #[Test]
    public function signal_rule_executes_direct_actions_and_queues_webhook(): void
    {
        Queue::fake();

        $client = Client::factory()->create();
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Signal Contact',
            'do_not_email' => false,
            'marketing_consent' => true,
        ]);

        SignalRule::query()->create([
            'name' => 'Unsubscribe automation',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [
                'source_domain' => ['marketing'],
                'signal_type' => ['unsubscribe'],
                'min_confidence' => 80,
            ],
            'actions' => [
                ['type' => 'marketing_suppress_contact_email'],
                ['type' => 'tag_contact', 'tag' => 'Unsubscribed'],
                ['type' => 'tag_client', 'tag' => 'Marketing interest'],
                ['type' => 'webhook', 'url' => 'https://example.test/signal'],
            ],
        ]);

        $signal = app(RecordSignal::class)->handle([
            'source_domain' => 'marketing',
            'source_type' => MarketingCampaignEvent::class,
            'source_id' => 123,
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->id,
            'contact_id' => $contact->id,
            'client_id' => $client->id,
            'signal_type' => 'unsubscribe',
            'severity' => 'warning',
            'confidence' => 100,
            'summary' => 'Unsubscribe requested.',
            'payload' => ['test' => true],
        ]);

        $this->assertSame(1, $signal->executions()->count());
        $this->assertTrue($contact->fresh()->do_not_email);
        $this->assertFalse($contact->fresh()->marketing_consent);
        $this->assertSame(1, $contact->fresh()->tags()->where('slug', 'unsubscribed')->count());
        $this->assertSame(1, $client->fresh()->tags()->where('slug', 'marketing-interest')->count());
        $this->assertSame(1, SignalWebhookDelivery::query()->count());

        Queue::assertPushed(DeliverSignalWebhook::class);
    }

    #[Test]
    public function signal_rule_can_create_sales_follow_up_activity(): void
    {
        $client = Client::factory()->create(['name' => 'Interested Client']);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Buyer Contact',
        ]);

        SignalRule::query()->create([
            'name' => 'Campaign click follow-up',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [
                'source_domain' => ['marketing'],
                'signal_type' => ['marketing_click'],
                'min_confidence' => 70,
            ],
            'actions' => [
                [
                    'type' => 'sales_follow_up',
                    'opportunity_title' => 'Website interest follow-up',
                    'activity_subject' => 'Call after campaign click',
                    'follow_up_minutes_from_now' => 60,
                    'next_follow_up_type' => 'call',
                ],
            ],
        ]);

        $signal = app(RecordSignal::class)->handle([
            'source_domain' => 'marketing',
            'source_type' => MarketingCampaignEvent::class,
            'source_id' => 456,
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->id,
            'contact_id' => $contact->id,
            'client_id' => $client->id,
            'signal_type' => 'marketing_click',
            'severity' => 'info',
            'confidence' => 90,
            'summary' => 'Contact clicked website campaign link.',
            'payload' => ['url' => 'https://example.test/website'],
        ]);

        $opportunity = SalesOpportunity::query()->firstOrFail();
        $activity = SalesActivity::query()->firstOrFail();

        $this->assertSame('Website interest follow-up', $opportunity->title);
        $this->assertSame('upsell', $opportunity->type);
        $this->assertTrue($opportunity->is_unread);
        $this->assertNotNull($opportunity->next_follow_up_at);
        $this->assertSame('Call after campaign click', $activity->subject);
        $this->assertSame($signal->id, $activity->metadata['signal_id']);

        app(\App\Modules\Signal\Actions\ProcessSignalRules::class)->handle($signal->fresh());

        $this->assertSame(1, SalesActivity::query()->count());
    }

    #[Test]
    public function signal_rule_can_create_ticket_follow_up(): void
    {
        $client = Client::factory()->create(['name' => 'Operational Client']);

        SignalRule::query()->create([
            'name' => 'Bounce ticket follow-up',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [
                'source_domain' => ['email'],
                'signal_type' => ['hard_bounce'],
                'min_confidence' => 80,
            ],
            'actions' => [
                [
                    'type' => 'ticket_follow_up',
                    'subject' => 'Investigate bounced customer address',
                    'description' => 'Check if the customer address should be updated.',
                ],
            ],
        ]);

        $signal = app(RecordSignal::class)->handle([
            'source_domain' => 'email',
            'client_id' => $client->id,
            'signal_type' => 'hard_bounce',
            'severity' => 'error',
            'confidence' => 95,
            'summary' => 'Customer email address bounced.',
            'payload' => ['recipient_email' => 'old@example.test'],
        ]);

        $ticket = Ticket::query()->firstOrFail();

        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame('signal', $ticket->channel);
        $this->assertSame('Investigate bounced customer address', $ticket->subject);
        $this->assertTrue($ticket->is_unread);
        $this->assertSame($signal->id, $ticket->metadata['signal_id']);
        $this->assertSame(1, TicketMessage::query()->where('ticket_id', $ticket->id)->where('type', 'internal_note')->count());

        app(\App\Modules\Signal\Actions\ProcessSignalRules::class)->handle($signal->fresh());

        $this->assertSame(1, Ticket::query()->count());
    }

    #[Test]
    public function signal_rule_can_create_task_follow_up(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $client = Client::factory()->create(['name' => 'Task Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);

        SignalRule::query()->create([
            'name' => 'Intake task follow-up',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [
                'source_domain' => ['intake'],
                'signal_type' => ['intake_submission_received'],
            ],
            'actions' => [
                [
                    'type' => 'task_follow_up',
                    'subject' => 'Prepare new user onboarding',
                    'description' => 'Review the submitted onboarding request.',
                    'assigned_to' => $actor->id,
                    'due_minutes_from_now' => 60,
                    'estimated_minutes' => 30,
                ],
            ],
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $signal = app(RecordSignal::class)->handle([
            'source_domain' => 'intake',
            'client_id' => $client->id,
            'signal_type' => 'intake_submission_received',
            'summary' => 'New user form submitted.',
            'payload' => [
                'intake_form_slug' => 'new-user',
                'intake_submission_id' => 123,
                'matched_site_id' => $site->id,
            ],
        ]);

        $task = Task::query()->firstOrFail();

        $this->assertSame('Prepare new user onboarding', $task->title);
        $this->assertSame($client->id, $task->client_id);
        $this->assertSame($site->id, $task->site_id);
        $this->assertSame($actor->id, $task->created_by);
        $this->assertSame($actor->id, $task->assigned_to);
        $this->assertSame('signal', $task->source_type);
        $this->assertSame($signal->id, $task->source_id);
        $this->assertSame($signal->id, $task->metadata['signal_id']);
        $this->assertSame(123, $task->metadata['intake_submission_id']);
        $this->assertNotNull($task->due_at);
        $this->assertSame(30, $task->estimated_minutes);

        app(\App\Modules\Signal\Actions\ProcessSignalRules::class)->handle($signal->fresh());

        $this->assertSame(1, Task::query()->count());
    }

    #[Test]
    public function signal_rule_can_send_portal_invitation(): void
    {
        Queue::fake();

        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $client = Client::factory()->create(['name' => 'Portal Client', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Portal Contact',
        ]);
        $contact->emails()->create([
            'label' => 'work',
            'email' => 'portal-contact@example.test',
            'is_primary' => true,
        ]);
        ClientUser::query()->create([
            'contact_id' => $contact->id,
            'client_site_id' => $site->id,
            'name' => 'Portal Contact',
            'email' => 'portal-contact@example.test',
            'active' => true,
        ]);
        ContactRelation::query()->create([
            'contact_id' => $contact->id,
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => 'customer',
            'is_primary' => true,
        ]);

        SignalRule::query()->create([
            'name' => 'Intake portal invitation',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [
                'source_domain' => ['intake'],
                'signal_type' => ['intake_submission_received'],
                'has_client' => true,
                'has_contact' => true,
            ],
            'actions' => [
                [
                    'type' => 'portal_invitation',
                    'role' => CustomerPortalMembership::ROLE_VIEWER,
                ],
            ],
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $signal = app(RecordSignal::class)->handle([
            'source_domain' => 'intake',
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'signal_type' => 'intake_submission_received',
            'summary' => 'Portal onboarding form submitted.',
            'payload' => [
                'intake_submission_id' => 456,
                'matched_site_id' => $site->id,
            ],
        ]);

        $invitation = CustomerPortalInvitation::query()->firstOrFail();

        $this->assertSame($contact->id, $invitation->contact_id);
        $this->assertSame($client->id, $invitation->client_id);
        $this->assertSame($site->id, $invitation->site_id);
        $this->assertSame(CustomerPortalMembership::ROLE_VIEWER, $invitation->role);
        $this->assertSame($actor->id, $invitation->created_by);
        $this->assertSame($signal->id, $invitation->metadata['signal_id']);
        $this->assertSame(456, $invitation->metadata['intake_submission_id']);

        Queue::assertPushed(SendCustomerPortalInvitationEmail::class);

        app(\App\Modules\Signal\Actions\ProcessSignalRules::class)->handle($signal->fresh());

        $this->assertSame(1, CustomerPortalInvitation::query()->count());
    }

    #[Test]
    public function signal_rules_can_match_payload_conditions(): void
    {
        $client = Client::factory()->create(['name' => 'QNAP Customer']);

        SignalRule::query()->create([
            'name' => 'QNAP firmware update',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [
                'source_domain' => ['email'],
                'signal_type' => ['vendor_notification'],
                'has_client' => true,
                'payload_equals' => ['vendor' => 'qnap'],
                'payload_contains' => ['title' => 'firmware'],
            ],
            'actions' => [
                ['type' => 'ticket_follow_up', 'subject' => 'Review QNAP firmware notice'],
            ],
        ]);

        app(RecordSignal::class)->handle([
            'source_domain' => 'email',
            'client_id' => $client->id,
            'signal_type' => 'vendor_notification',
            'severity' => 'warning',
            'confidence' => 90,
            'summary' => 'QNAP notice',
            'payload' => [
                'vendor' => 'qnap',
                'title' => 'Firmware update available for NAS devices',
            ],
        ]);

        app(RecordSignal::class)->handle([
            'source_domain' => 'email',
            'client_id' => $client->id,
            'signal_type' => 'vendor_notification',
            'severity' => 'warning',
            'confidence' => 90,
            'summary' => 'Other vendor notice',
            'payload' => [
                'vendor' => 'synology',
                'title' => 'Firmware update available',
            ],
        ]);

        $this->assertSame(1, Ticket::query()->where('subject', 'Review QNAP firmware notice')->count());
    }

    #[Test]
    public function signal_rule_ui_rejects_invalid_payload_condition_shapes(): void
    {
        Permission::findOrCreate('signal.view', 'web');
        Permission::findOrCreate('signal.rule.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['signal.view', 'signal.rule.manage']);

        $this->actingAs($user)
            ->from(route('tech.admin.system.signals.rules.create'))
            ->post(route('tech.admin.system.signals.rules.store'), [
                'name' => 'Bad payload condition',
                'is_active' => 1,
                'priority' => 50,
                'conditions_json' => json_encode(['payload_equals' => 'qnap']),
                'actions_json' => json_encode([['type' => 'marketing_suppress_contact_email']]),
            ])
            ->assertRedirect(route('tech.admin.system.signals.rules.create'))
            ->assertSessionHasErrors('conditions_json');
    }

    #[Test]
    public function webhook_delivery_records_success(): void
    {
        Http::fake([
            'example.test/*' => Http::response('ok', 200),
        ]);

        $signal = Signal::query()->create([
            'source_domain' => 'marketing',
            'signal_type' => 'marketing_click',
            'summary' => 'Click',
            'occurred_at' => now(),
        ]);
        $delivery = SignalWebhookDelivery::query()->create([
            'signal_id' => $signal->id,
            'url' => 'https://example.test/signal',
            'payload' => ['signal' => ['id' => $signal->id]],
        ]);

        DeliverSignalWebhook::dispatchSync($delivery->id);

        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame(200, $delivery->fresh()->response_status);
    }

    #[Test]
    public function signal_rules_can_be_created_from_ui(): void
    {
        Permission::findOrCreate('signal.view', 'web');
        Permission::findOrCreate('signal.rule.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['signal.view', 'signal.rule.manage']);

        $this->actingAs($user)
            ->post(route('tech.admin.system.signals.rules.store'), [
                'name' => 'Marketing open interest',
                'description' => 'Tag contact when opening marketing mail.',
                'is_active' => 1,
                'priority' => 50,
                'conditions_json' => json_encode(['source_domain' => ['marketing'], 'signal_type' => ['marketing_open']]),
                'actions_json' => json_encode([['type' => 'tag_contact', 'tag' => 'Opened marketing']]),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('signal_rules', [
            'name' => 'Marketing open interest',
            'priority' => 50,
        ]);
    }

    #[Test]
    public function signal_rules_can_be_created_with_structured_condition_and_action_fields(): void
    {
        Permission::findOrCreate('signal.view', 'web');
        Permission::findOrCreate('signal.rule.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['signal.view', 'signal.rule.manage']);

        $this->actingAs($user)
            ->post(route('tech.admin.system.signals.rules.store'), [
                'name' => 'Structured vendor follow-up',
                'description' => 'Created from structured fields.',
                'is_active' => 1,
                'priority' => 20,
                'conditions' => [
                    'source_domain' => "email\nqnap",
                    'signal_type' => 'vendor_notification',
                    'severity' => ['warning'],
                    'min_confidence' => 85,
                    'has_client' => '1',
                    'payload_equals' => "vendor=qnap",
                    'payload_contains' => "title=firmware",
                ],
                'actions' => [
                    ['type' => 'tag_client', 'tag' => 'Firmware notice'],
                    [
                        'type' => 'sales_follow_up',
                        'subject' => 'Call about firmware notice',
                        'description' => 'Check if managed firmware service is relevant.',
                        'follow_up_minutes_from_now' => 1440,
                        'next_follow_up_type' => 'call',
                    ],
                    [
                        'type' => 'ticket_follow_up',
                        'subject' => 'Review firmware notice',
                        'description' => 'Assess customer device risk.',
                    ],
                    [
                        'type' => 'task_follow_up',
                        'subject' => 'Plan firmware review',
                        'description' => 'Create an internal task for the device review.',
                        'assigned_to' => $user->id,
                        'due_minutes_from_now' => 120,
                        'estimated_minutes' => 45,
                    ],
                    [
                        'type' => 'portal_invitation',
                        'role' => CustomerPortalMembership::ROLE_VIEWER,
                    ],
                    ['type' => 'webhook', 'url' => 'https://example.test/signal'],
                ],
            ])
            ->assertRedirect();

        $rule = SignalRule::query()->where('name', 'Structured vendor follow-up')->firstOrFail();

        $this->assertSame(['email', 'qnap'], $rule->conditions['source_domain']);
        $this->assertSame(['vendor_notification'], $rule->conditions['signal_type']);
        $this->assertSame(['warning'], $rule->conditions['severity']);
        $this->assertSame(85, $rule->conditions['min_confidence']);
        $this->assertTrue($rule->conditions['has_client']);
        $this->assertSame('qnap', $rule->conditions['payload_equals']['vendor']);
        $this->assertSame('firmware', $rule->conditions['payload_contains']['title']);
        $this->assertSame('tag_client', $rule->actions[0]['type']);
        $this->assertSame('Call about firmware notice', $rule->actions[1]['activity_subject']);
        $this->assertSame('Check if managed firmware service is relevant.', $rule->actions[1]['activity_body']);
        $this->assertSame('Review firmware notice', $rule->actions[2]['subject']);
        $this->assertSame('task_follow_up', $rule->actions[3]['type']);
        $this->assertSame($user->id, $rule->actions[3]['assigned_to']);
        $this->assertSame(120, $rule->actions[3]['due_minutes_from_now']);
        $this->assertSame('portal_invitation', $rule->actions[4]['type']);
        $this->assertSame(CustomerPortalMembership::ROLE_VIEWER, $rule->actions[4]['role']);
        $this->assertSame('webhook', $rule->actions[5]['type']);
    }

    #[Test]
    public function enabled_ai_classification_records_inbound_email_signal_before_ticket_routing(): void
    {
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'decision' => 'signal',
                    'signal_type' => 'vendor_notification',
                    'severity' => 'warning',
                    'confidence' => 92,
                    'summary' => 'Datto agent update notice.',
                    'recipient_email' => null,
                    'payload' => [
                        'vendor' => 'datto',
                        'reason' => 'The email announces an RMM agent update.',
                    ],
                ]),
            ], 200),
        ]);

        app(SignalSettings::class)->update([
            'ai_classification_enabled' => true,
            'ai_min_confidence' => 80,
            'ai_source_domains' => ['email'],
            'ai_allowed_signal_types' => ['vendor_notification'],
            'ai_stop_ticket_routing_types' => ['vendor_notification'],
            'ai_classification_prompt' => SignalSettings::DEFAULT_AI_CLASSIFICATION_PROMPT,
        ]);

        $provider = AiProvider::query()->create([
            'name' => 'OpenAI signal',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-5-mini',
            'status' => 'active',
        ]);
        $provider->setSecret('api_key', 'test-key');
        $provider->save();

        AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Signal Classification Agent',
            'slug' => 'signal-classification-agent',
            'model' => 'gpt-5-mini',
            'instructions' => 'Classify Signal payloads.',
            'default_domains' => ['signal'],
            'is_active' => true,
        ]);

        $account = EmailAccount::query()->create([
            'address' => 'support@example.test',
            'from_name' => 'Support',
            'is_active' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@example.test',
            'imap_secret' => 'secret',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'support@example.test',
            'smtp_secret' => 'secret',
        ]);
        $email = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 8001,
            'message_id' => '<datto-agent-update@example.test>',
            'subject' => 'Datto RMM Agent Update',
            'from_email' => 'updates@datto.example.test',
            'headers_json' => [],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Datto RMM agent update is available for managed endpoints.',
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $signal = Signal::query()->where('source_type', EmailMessage::class)->where('source_id', $email->id)->firstOrFail();

        $this->assertSame('vendor_notification', $signal->signal_type);
        $this->assertSame('signal-ai-v1', $signal->payload['classifier']);
        $this->assertSame('datto', $signal->payload['vendor']);
        $this->assertSame('archived', $email->fresh()->state);
        $this->assertNull($email->fresh()->ticket_id);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses'
            && $request['model'] === 'gpt-5-mini'
            && str_contains((string) $request['input'], 'vendor_notification'));
    }

    #[Test]
    public function signal_rule_ui_rejects_unknown_or_incomplete_actions(): void
    {
        Permission::findOrCreate('signal.view', 'web');
        Permission::findOrCreate('signal.rule.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['signal.view', 'signal.rule.manage']);

        $this->actingAs($user)
            ->from(route('tech.admin.system.signals.rules.create'))
            ->post(route('tech.admin.system.signals.rules.store'), [
                'name' => 'Bad action',
                'is_active' => 1,
                'priority' => 50,
                'conditions_json' => json_encode(['source_domain' => ['marketing']]),
                'actions_json' => json_encode([['type' => 'does_not_exist']]),
            ])
            ->assertRedirect(route('tech.admin.system.signals.rules.create'))
            ->assertSessionHasErrors('actions_json');

        $this->actingAs($user)
            ->from(route('tech.admin.system.signals.rules.create'))
            ->post(route('tech.admin.system.signals.rules.store'), [
                'name' => 'Missing tag',
                'is_active' => 1,
                'priority' => 50,
                'conditions_json' => json_encode(['source_domain' => ['marketing']]),
                'actions_json' => json_encode([['type' => 'tag_contact']]),
            ])
            ->assertRedirect(route('tech.admin.system.signals.rules.create'))
            ->assertSessionHasErrors('actions_json');

        $this->assertSame(0, SignalRule::query()->whereIn('name', ['Bad action', 'Missing tag'])->count());
    }

    #[Test]
    public function signal_rule_ui_rejects_unknown_conditions(): void
    {
        Permission::findOrCreate('signal.view', 'web');
        Permission::findOrCreate('signal.rule.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['signal.view', 'signal.rule.manage']);

        $this->actingAs($user)
            ->from(route('tech.admin.system.signals.rules.create'))
            ->post(route('tech.admin.system.signals.rules.store'), [
                'name' => 'Bad condition',
                'is_active' => 1,
                'priority' => 50,
                'conditions_json' => json_encode(['unknown_field' => ['marketing']]),
                'actions_json' => json_encode([['type' => 'marketing_suppress_contact_email']]),
            ])
            ->assertRedirect(route('tech.admin.system.signals.rules.create'))
            ->assertSessionHasErrors('conditions_json');

        $this->assertDatabaseMissing('signal_rules', [
            'name' => 'Bad condition',
        ]);
    }

    #[Test]
    public function signal_feed_defaults_to_thirty_days_and_can_show_all_history_sorted(): void
    {
        Permission::findOrCreate('signal.view', 'web');
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo('signal.view');

        $recent = Signal::query()->create([
            'source_domain' => 'email',
            'signal_type' => 'recent_notice',
            'confidence' => 90,
            'summary' => 'Recent Signal',
            'occurred_at' => now()->subDays(2),
        ]);
        $old = Signal::query()->create([
            'source_domain' => 'qnap',
            'signal_type' => 'old_notice',
            'confidence' => 20,
            'summary' => 'Old Signal',
            'occurred_at' => now()->subDays(45),
        ]);

        $this->actingAs($user)
            ->get(route('tech.admin.system.signals.index'))
            ->assertOk()
            ->assertViewHas('signals', fn ($signals): bool => $signals->count() === 1 && $signals->first()->is($recent));

        $this->actingAs($user)
            ->get(route('tech.admin.system.signals.index', ['range' => 'all', 'sort' => 'confidence', 'direction' => 'asc']))
            ->assertOk()
            ->assertViewHas('signals', fn ($signals): bool => $signals->count() === 2 && $signals->first()->is($old));
    }

    #[Test]
    public function grouped_conditions_support_independent_all_and_any_matching(): void
    {
        $rule = SignalRule::query()->create([
            'name' => 'Grouped matching',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [
                'version' => 2,
                'match' => 'all',
                'groups' => [
                    [
                        'match' => 'any',
                        'conditions' => [
                            ['field' => 'source_domain', 'operator' => 'equals', 'value' => 'email'],
                            ['field' => 'source_domain', 'operator' => 'equals', 'value' => 'qnap'],
                        ],
                    ],
                    [
                        'match' => 'all',
                        'conditions' => [
                            ['field' => 'confidence', 'operator' => 'greater_or_equal', 'value' => 80],
                            ['field' => 'payload', 'path' => 'title', 'operator' => 'contains', 'value' => 'firmware'],
                        ],
                    ],
                ],
            ],
            'actions' => [['type' => 'marketing_suppress_contact_email']],
        ]);

        $matching = app(RecordSignal::class)->handle([
            'source_domain' => 'qnap',
            'signal_type' => 'vendor_notice',
            'confidence' => 95,
            'payload' => ['title' => 'Firmware available'],
        ]);
        $notMatching = app(RecordSignal::class)->handle([
            'source_domain' => 'qnap',
            'signal_type' => 'vendor_notice',
            'confidence' => 70,
            'payload' => ['title' => 'Firmware available'],
        ]);

        $this->assertSame(1, $matching->executions()->where('signal_rule_id', $rule->id)->count());
        $this->assertSame(0, $notMatching->executions()->where('signal_rule_id', $rule->id)->count());
    }

    #[Test]
    public function action_failure_stops_that_rule_but_allows_other_rules_to_continue(): void
    {
        $signal = Signal::query()->create([
            'source_domain' => 'test',
            'signal_type' => 'failure_test',
            'occurred_at' => now(),
        ]);
        $failingRule = SignalRule::query()->create([
            'name' => 'Failing rule',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [],
            'actions' => [
                ['type' => 'webhook', 'url' => 'https://example.test/one'],
                ['type' => 'webhook', 'url' => 'https://example.test/two'],
            ],
        ]);
        $continuingRule = SignalRule::query()->create([
            'name' => 'Continuing rule',
            'is_active' => true,
            'priority' => 20,
            'conditions' => [],
            'actions' => [['type' => 'tag_client', 'tag' => 'Continued']],
        ]);

        $executor = \Mockery::mock(ExecuteSignalAction::class);
        $executor->shouldReceive('handle')->twice()->andReturnUsing(
            function (Signal $receivedSignal, SignalRule $rule, array $action, int $index) use ($failingRule): array {
                if ($rule->is($failingRule)) {
                    throw new \RuntimeException('Synthetic action failure.');
                }

                return ['type' => $action['type'], 'status' => 'done'];
            },
        );

        (new ProcessSignalRules($executor))->handle($signal);

        $failed = SignalRuleExecution::query()->where('signal_rule_id', $failingRule->id)->firstOrFail();
        $continued = SignalRuleExecution::query()->where('signal_rule_id', $continuingRule->id)->firstOrFail();

        $this->assertSame('failed', $failed->status);
        $this->assertSame(['failed', 'not_run'], collect($failed->results)->pluck('status')->all());
        $this->assertSame('executed', $continued->status);
    }

    #[Test]
    public function successful_stop_processing_rule_prevents_lower_priority_rules(): void
    {
        $signal = Signal::query()->create([
            'source_domain' => 'test',
            'signal_type' => 'stop_test',
            'occurred_at' => now(),
        ]);
        SignalRule::query()->create([
            'name' => 'Stopping rule',
            'is_active' => true,
            'priority' => 10,
            'stop_processing' => true,
            'conditions' => [],
            'actions' => [['type' => 'tag_client', 'tag' => 'Stop']],
        ]);
        SignalRule::query()->create([
            'name' => 'Blocked rule',
            'is_active' => true,
            'priority' => 20,
            'conditions' => [],
            'actions' => [['type' => 'tag_client', 'tag' => 'Blocked']],
        ]);

        $executor = \Mockery::mock(ExecuteSignalAction::class);
        $executor->shouldReceive('handle')->once()->andReturn(['type' => 'tag_client', 'status' => 'done']);

        $this->assertSame(1, (new ProcessSignalRules($executor))->handle($signal));
        $this->assertSame(1, $signal->executions()->count());
    }

    #[Test]
    public function retry_runs_only_actions_that_have_not_succeeded_and_links_the_attempt(): void
    {
        $signal = Signal::query()->create([
            'source_domain' => 'test',
            'signal_type' => 'retry_test',
            'occurred_at' => now(),
        ]);
        $rule = SignalRule::query()->create([
            'name' => 'Retry rule',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [],
            'actions' => [
                ['type' => 'tag_client', 'tag' => 'Done'],
                ['type' => 'webhook', 'url' => 'https://example.test/retry'],
            ],
        ]);
        $root = $signal->executions()->create([
            'signal_rule_id' => $rule->id,
            'status' => 'failed',
            'actions' => $rule->actions,
            'results' => [
                ['action_index' => 0, 'type' => 'tag_client', 'status' => 'done'],
                ['action_index' => 1, 'type' => 'webhook', 'status' => 'failed'],
            ],
            'error' => 'Temporary failure.',
            'executed_at' => now(),
        ]);

        $executor = \Mockery::mock(ExecuteSignalAction::class);
        $executor->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Signal $receivedSignal, SignalRule $receivedRule, array $action, int $index): bool => $index === 1)
            ->andReturn(['type' => 'webhook', 'status' => 'queued']);
        $processor = new ProcessSignalRules($executor);

        $retry = $processor->retry($root);

        $this->assertNotNull($retry);
        $this->assertSame($root->id, $retry->retry_of_execution_id);
        $this->assertSame(2, $retry->attempt);
        $this->assertSame([1], collect($retry->results)->pluck('action_index')->all());
        $this->assertNull($processor->retry($root->fresh('retries')));
    }

    #[Test]
    public function rule_builder_stores_grouped_conditions_and_stop_processing(): void
    {
        Permission::findOrCreate('signal.view', 'web');
        Permission::findOrCreate('signal.rule.manage', 'web');
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['signal.view', 'signal.rule.manage']);

        $this->actingAs($user)->post(route('tech.admin.system.signals.rules.store'), [
            'name' => 'Builder rule',
            'is_active' => 1,
            'stop_processing' => 1,
            'priority' => 30,
            'conditions' => [
                'match' => 'all',
                'groups' => [[
                    'match' => 'any',
                    'conditions' => [
                        ['field' => 'source_domain', 'operator' => 'in', 'value' => "email\nqnap"],
                        ['field' => 'has_client', 'operator' => 'is_true'],
                    ],
                ]],
            ],
            'actions' => [['type' => 'tag_client', 'tag' => 'Builder']],
        ])->assertRedirect();

        $rule = SignalRule::query()->where('name', 'Builder rule')->firstOrFail();
        $this->assertTrue($rule->stop_processing);
        $this->assertSame('any', $rule->conditions['groups'][0]['match']);
        $this->assertSame(['email', 'qnap'], $rule->conditions['groups'][0]['conditions'][0]['value']);
    }

    #[Test]
    public function rule_builder_and_action_log_views_render_with_contextual_controls(): void
    {
        Permission::findOrCreate('signal.view', 'web');
        Permission::findOrCreate('signal.rule.manage', 'web');
        Permission::findOrCreate('signal.action.execute', 'web');
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['signal.view', 'signal.rule.manage', 'signal.action.execute']);

        $rule = SignalRule::query()->create([
            'name' => 'Rendered legacy rule',
            'is_active' => true,
            'priority' => 10,
            'conditions' => ['source_domain' => ['email']],
            'actions' => [['type' => 'tag_client', 'tag' => 'Rendered']],
        ]);
        $signal = Signal::query()->create([
            'source_domain' => 'email',
            'signal_type' => 'render_test',
            'occurred_at' => now(),
        ]);
        $execution = $signal->executions()->create([
            'signal_rule_id' => $rule->id,
            'status' => 'failed',
            'actions' => $rule->actions,
            'results' => [['action_index' => 0, 'type' => 'tag_client', 'status' => 'failed', 'message' => 'Try again.']],
            'error' => 'Try again.',
            'executed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('tech.admin.system.signals.rules.create'))
            ->assertOk()
            ->assertSee('Add condition group')
            ->assertSee('Rule Reference')
            ->assertSee('Advanced JSON');

        $this->actingAs($user)
            ->get(route('tech.admin.system.signals.rules.show', $rule))
            ->assertOk()
            ->assertSee('Rendered legacy rule')
            ->assertSee('Add action');

        $this->actingAs($user)
            ->get(route('tech.admin.system.signals.show', $signal))
            ->assertOk()
            ->assertSee('Retry failed / unstarted')
            ->assertSee('Run whole rule again')
            ->assertSee('Try again.');

        $this->assertTrue($execution->fresh()->hasRetryableActions());
    }

    #[Test]
    public function retry_route_requires_action_execute_permission(): void
    {
        Permission::findOrCreate('signal.view', 'web');
        Permission::findOrCreate('signal.action.execute', 'web');
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo('signal.view');
        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $operator->givePermissionTo(['signal.view', 'signal.action.execute']);

        $rule = SignalRule::query()->create([
            'name' => 'Protected retry',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [],
            'actions' => [['type' => 'tag_client', 'tag' => 'Retry']],
        ]);
        $signal = Signal::query()->create([
            'source_domain' => 'test',
            'signal_type' => 'protected_retry',
            'occurred_at' => now(),
        ]);
        $execution = $signal->executions()->create([
            'signal_rule_id' => $rule->id,
            'status' => 'failed',
            'actions' => $rule->actions,
            'results' => [['action_index' => 0, 'type' => 'tag_client', 'status' => 'failed']],
            'executed_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->post(route('tech.admin.system.signals.executions.retry', [$signal, $execution]), ['mode' => 'failed'])
            ->assertForbidden();

        $this->actingAs($operator)
            ->post(route('tech.admin.system.signals.executions.retry', [$signal, $execution]), ['mode' => 'failed'])
            ->assertRedirect(route('tech.admin.system.signals.show', $signal));

        $this->assertDatabaseHas('signal_rule_executions', [
            'retry_of_execution_id' => $execution->id,
            'attempt' => 2,
            'status' => 'executed',
        ]);
    }
}
