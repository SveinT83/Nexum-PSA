<?php

namespace App\Modules\Signal\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Marketing\Models\MarketingCampaignEvent;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Signal\Actions\RecordSignal;
use App\Modules\Signal\Controllers\Api\V1\SignalController as SignalApiController;
use App\Modules\Signal\Controllers\Tech\SignalController;
use App\Modules\Signal\Jobs\DeliverSignalWebhook;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Signal\Models\SignalWebhookDelivery;
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
}
