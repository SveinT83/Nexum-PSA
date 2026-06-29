<?php

namespace App\Modules\Telephony\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Telephony\Actions\EnsureTelephonyToken;
use App\Modules\Telephony\Controllers\Public\TelephonyIntakeController;
use App\Modules\Telephony\Controllers\Tech\TelephonyProfileController;
use App\Modules\Telephony\Models\TelephonyCall;
use App\Modules\Telephony\Models\TelephonyToken;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TelephonyModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::findOrCreate('telephony.view', 'web');
        $role = Role::findOrCreate('Tech', 'web');
        $role->givePermissionTo($permission);

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole($role);
    }

    #[Test]
    public function telephony_routes_are_owned_by_telephony_module(): void
    {
        $this->assertSame(TelephonyIntakeController::class.'@show', Route::getRoutes()->getByName('telephony.intake')->getActionName());
        $this->assertSame(TelephonyIntakeController::class.'@call', Route::getRoutes()->getByName('telephony.intake.call')->getActionName());
        $this->assertSame(TelephonyProfileController::class.'@show', Route::getRoutes()->getByName('tech.telephony.profile')->getActionName());
        $this->assertSame(TelephonyProfileController::class.'@rotate', Route::getRoutes()->getByName('tech.telephony.profile.token.rotate')->getActionName());
    }

    #[Test]
    public function technician_can_view_and_rotate_personal_intake_url(): void
    {
        $response = $this->actingAs($this->tech)
            ->get(route('tech.telephony.profile'));

        $response->assertOk()
            ->assertViewIs('telephony::Tech.profile')
            ->assertSee('Personal Intake URL')
            ->assertSee('?caller=%no', false);

        $firstToken = TelephonyToken::query()->where('user_id', $this->tech->id)->first();
        $this->assertNotNull($firstToken);

        $this->actingAs($this->tech)
            ->post(route('tech.telephony.profile.token.rotate'))
            ->assertRedirect(route('tech.telephony.profile'))
            ->assertSessionHas('success');

        $rotatedToken = TelephonyToken::query()->where('user_id', $this->tech->id)->firstOrFail();
        $this->assertNotSame($firstToken->token_hash, $rotatedToken->token_hash);
    }

    #[Test]
    public function public_intake_matches_contact_context_and_deduplicates_provider_call_id(): void
    {
        $token = app(EnsureTelephonyToken::class)->handle($this->tech);
        [$contact, $clientUser, $client, $site] = $this->callerContext();

        $this->get(route('telephony.intake', [
            'token' => $token->token_value,
            'caller' => '99999999',
            'provider' => 'Telia',
            'call_id' => 'provider-call-1',
            'test' => '1',
        ]))
            ->assertOk()
            ->assertViewIs('telephony::Public.intake')
            ->assertSee('Call Intake')
            ->assertSee('Ada Caller')
            ->assertSee('Phone Client');

        $this->assertDatabaseHas('telephony_calls', [
            'provider_profile' => 'telia',
            'provider_call_id' => 'provider-call-1',
            'caller_number_raw' => '99999999',
            'caller_number_normalized' => '+4799999999',
            'answered_by_user_id' => $this->tech->id,
            'contact_id' => $contact->id,
            'client_user_id' => $clientUser->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'is_test' => true,
        ]);

        $this->get(route('telephony.intake', [
            'token' => $token->token_value,
            'caller' => '004799999999',
            'provider' => 'Telia',
            'call_id' => 'provider-call-1',
            'note' => 'Updated intake note',
        ]))->assertOk();

        $this->assertSame(1, TelephonyCall::query()->count());
        $this->assertSame('Updated intake note', TelephonyCall::query()->first()->notes);
    }

    #[Test]
    public function public_intake_rejects_invalid_token(): void
    {
        $this->get(route('telephony.intake', [
            'token' => 'not-a-real-token',
            'caller' => '99999999',
        ]))->assertNotFound();
    }

    #[Test]
    public function public_intake_deduplicates_without_provider_call_id(): void
    {
        $token = app(EnsureTelephonyToken::class)->handle($this->tech);

        $payload = [
            'token' => $token->token_value,
            'caller' => '+4799999999',
            'answered_at' => '2026-06-29 12:03:00',
        ];

        $this->get(route('telephony.intake', $payload))->assertOk();
        $this->get(route('telephony.intake', array_merge($payload, [
            'answered_at' => '2026-06-29 12:04:59',
        ])))->assertOk();

        $this->assertSame(1, TelephonyCall::query()->count());
    }

    #[Test]
    public function unknown_callers_without_provider_call_id_are_not_deduplicated(): void
    {
        $token = app(EnsureTelephonyToken::class)->handle($this->tech);

        $this->get(route('telephony.intake', [
            'token' => $token->token_value,
            'answered_at' => '2026-06-29 12:03:00',
        ]))->assertOk();
        $this->get(route('telephony.intake', [
            'token' => $token->token_value,
            'answered_at' => '2026-06-29 12:04:00',
        ]))->assertOk();

        $this->assertSame(2, TelephonyCall::query()->count());
    }

    #[Test]
    public function call_note_can_create_ticket_and_link_existing_ticket(): void
    {
        app(EnsureTicketDefaults::class)->handle();

        $token = app(EnsureTelephonyToken::class)->handle($this->tech);
        [, $clientUser, $client, $site] = $this->callerContext();

        $this->get(route('telephony.intake', [
            'token' => $token->token_value,
            'caller' => '99999999',
            'provider_call_id' => 'ticket-call-1',
            'notes' => 'Caller needs help with invoice sync.',
        ]))->assertOk();

        $call = TelephonyCall::query()->firstOrFail();

        $this->post(route('telephony.intake.calls.ticket', ['token' => $token->token_value, 'call' => $call]), [
            'subject' => 'Phone support follow-up',
            'description' => 'Invoice sync fails after login.',
        ])
            ->assertRedirect(route('telephony.intake.call', ['token' => $token->token_value, 'call' => $call]))
            ->assertSessionHas('success');

        $ticket = Ticket::query()->where('subject', 'Phone support follow-up')->firstOrFail();
        $this->assertSame('phone', $ticket->channel);
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($site->id, $ticket->site_id);
        $this->assertSame($clientUser->id, $ticket->contact_id);
        $this->assertSame($ticket->id, $call->refresh()->linked_ticket_id);
        $this->assertSame($call->id, $ticket->metadata['telephony_call_id']);

        $this->get(route('telephony.intake', [
            'token' => $token->token_value,
            'caller' => '99999999',
            'provider_call_id' => 'ticket-call-2',
        ]))->assertOk();

        $secondCall = TelephonyCall::query()->where('provider_call_id', 'ticket-call-2')->firstOrFail();

        $this->post(route('telephony.intake.calls.link-ticket', ['token' => $token->token_value, 'call' => $secondCall]), [
            'ticket_key' => $ticket->ticket_key,
            'note' => 'Second phone call note.',
        ])
            ->assertRedirect(route('telephony.intake.call', ['token' => $token->token_value, 'call' => $secondCall]))
            ->assertSessionHas('success');

        $this->assertSame($ticket->id, $secondCall->refresh()->linked_ticket_id);
        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'body' => 'Second phone call note.',
            'visibility' => 'internal',
        ]);
    }

    private function callerContext(): array
    {
        $client = Client::factory()->create(['name' => 'Phone Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Phone Site']);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Ada Caller',
        ]);
        $contact->phones()->create([
            'label' => 'mobile',
            'phone' => '+47 99 99 99 99',
            'is_primary' => true,
        ]);
        $clientUser = ClientUser::factory()->create([
            'contact_id' => $contact->id,
            'client_site_id' => $site->id,
            'name' => 'Ada Caller',
            'phone' => '+47 99 99 99 99',
        ]);

        return [$contact, $clientUser, $client, $site];
    }
}
