<?php

namespace App\Modules\CustomerPortal\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\CustomerPortal\Jobs\SendCustomerPortalInvitationEmail;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalAuditEvent;
use App\Modules\CustomerPortal\Models\CustomerPortalInvitation;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\CustomerPortal\Support\CustomerPortalContextResolver;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\SmtpAccountMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerPortalFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'customer_portal.view',
            'customer_portal.manage',
            'customer_portal.invite',
            'warroom.view',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    #[Test]
    public function admin_can_create_customer_portal_invitation_for_contact_scope(): void
    {
        Queue::fake();

        $admin = $this->adminUser();
        [$client, $site, $contact] = $this->contactFixture('ada.portal@example.test');

        $this->actingAs($admin)
            ->post(route('tech.admin.system.customer-portal.invitations.store'), [
                'contact_id' => $contact->id,
                'client_id' => $client->id,
                'site_id' => $site->id,
                'role' => CustomerPortalMembership::ROLE_VIEWER,
            ])
            ->assertRedirect(route('tech.admin.system.customer-portal.index'))
            ->assertSessionHas('success');

        $invitation = CustomerPortalInvitation::query()->firstOrFail();

        $this->assertSame($contact->id, $invitation->contact_id);
        $this->assertSame($client->id, $invitation->client_id);
        $this->assertSame($site->id, $invitation->site_id);
        $this->assertSame('ada.portal@example.test', $invitation->email);
        $this->assertSame(64, strlen($invitation->token_hash));

        Queue::assertPushed(
            SendCustomerPortalInvitationEmail::class,
            fn (SendCustomerPortalInvitationEmail $job) => $job->invitationId === $invitation->id
                && CustomerPortalInvitation::hashToken($job->token) === $invitation->token_hash
        );

        $this->assertDatabaseHas('customer_portal_audit_events', [
            'event' => 'portal_invitation_created',
            'contact_id' => $contact->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);
    }

    #[Test]
    public function invitation_acceptance_creates_portal_only_user_and_membership(): void
    {
        Queue::fake();

        $admin = $this->adminUser();
        [$client, $site, $contact] = $this->contactFixture('first.login@example.test');

        $this->actingAs($admin)->post(route('tech.admin.system.customer-portal.invitations.store'), [
            'contact_id' => $contact->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'role' => CustomerPortalMembership::ROLE_SITE_ADMIN,
        ]);

        $job = Queue::pushed(SendCustomerPortalInvitationEmail::class)->first();
        $this->assertNotNull($job);

        auth()->logout();

        $this->get(route('customer-portal.invitations.accept', ['token' => $job->token]))
            ->assertOk()
            ->assertSee('Activate portal access');

        $this->post(route('customer-portal.invitations.accept.store', ['token' => $job->token]), [
            'password' => 'PortalPass123!',
            'password_confirmation' => 'PortalPass123!',
        ])->assertRedirect(route('customer-portal.dashboard'));

        $user = User::query()->where('email', 'first.login@example.test')->firstOrFail();
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertSame($contact->id, $user->contact_id);
        $this->assertTrue(Hash::check('PortalPass123!', $user->password));
        $this->assertFalse($user->roles()->exists());
        $this->assertFalse($user->permissions()->exists());

        $account = CustomerPortalAccount::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame($contact->id, $account->contact_id);

        $membership = CustomerPortalMembership::query()->where('customer_portal_account_id', $account->id)->firstOrFail();
        $this->assertSame($client->id, $membership->client_id);
        $this->assertSame($site->id, $membership->site_id);
        $this->assertSame(CustomerPortalMembership::ROLE_SITE_ADMIN, $membership->role);

        $invitation = CustomerPortalInvitation::query()->firstOrFail();
        $this->assertNotNull($invitation->accepted_at);
        $this->assertDatabaseHas('customer_portal_audit_events', [
            'event' => 'portal_invitation_accepted',
            'customer_portal_account_id' => $account->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);
    }

    #[Test]
    public function portal_only_user_can_open_portal_but_not_tech_dashboard(): void
    {
        [$client, $site, $contact] = $this->contactFixture('portal.only@example.test');
        $user = $this->portalUser($contact, $client, $site);

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('customer-portal.dashboard'));

        $this->actingAs($user)
            ->get(route('customer-portal.dashboard'))
            ->assertOk()
            ->assertSee($client->name)
            ->assertSee($site->name);

        $this->actingAs($user)
            ->get(route('tech.dashboard'))
            ->assertForbidden();
    }

    #[Test]
    public function authenticated_user_without_portal_membership_cannot_open_portal(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($user)
            ->get(route('customer-portal.dashboard'))
            ->assertForbidden();
    }

    #[Test]
    public function dual_user_can_use_internal_dashboard_and_portal_membership(): void
    {
        [$client, $site, $contact] = $this->contactFixture('dual.user@example.test');
        $user = $this->portalUser($contact, $client, $site);
        $user->givePermissionTo('warroom.view');

        $this->actingAs($user)
            ->get(route('tech.dashboard'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('customer-portal.dashboard'))
            ->assertOk()
            ->assertSee($client->name);
    }

    #[Test]
    public function context_resolver_rejects_disabled_or_invalid_memberships(): void
    {
        [$client, $site, $contact] = $this->contactFixture('resolver@example.test');
        $user = $this->portalUser($contact, $client, $site);
        $resolver = app(CustomerPortalContextResolver::class);

        $this->assertNotNull($resolver->resolveForUser($user));

        $membership = CustomerPortalMembership::query()->firstOrFail();
        $membership->forceFill([
            'status' => CustomerPortalMembership::STATUS_DISABLED,
            'disabled_at' => now(),
        ])->save();

        $this->assertNull($resolver->resolveForUser($user));

        $membership->forceFill([
            'status' => CustomerPortalMembership::STATUS_ACTIVE,
            'disabled_at' => null,
            'site_id' => ClientSite::factory()->create(['client_id' => Client::factory()->create()->id])->id,
        ])->save();

        $this->assertNull($resolver->resolveForUser($user));
    }

    #[Test]
    public function accepting_invitation_cannot_move_existing_portal_account_to_another_contact(): void
    {
        [$client, $site, $firstContact] = $this->contactFixture('shared.portal@example.test');
        $user = $this->portalUser($firstContact, $client, $site);
        $user->forceFill(['contact_id' => null])->save();

        [, , $secondContact] = $this->contactFixture('shared.portal@example.test');
        $invitation = CustomerPortalInvitation::query()->create([
            'contact_id' => $secondContact->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $user->id,
            'email' => 'shared.portal@example.test',
            'role' => CustomerPortalMembership::ROLE_VIEWER,
            'token_hash' => CustomerPortalInvitation::hashToken('shared-token'),
            'expires_at' => now()->addHours(72),
        ]);

        try {
            app(\App\Modules\CustomerPortal\Actions\AcceptCustomerPortalInvitation::class)->handle($invitation);
            $this->fail('The invitation acceptance should have failed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $user->refresh();
        $this->assertNull($user->contact_id);
        $this->assertDatabaseHas('customer_portal_accounts', [
            'user_id' => $user->id,
            'contact_id' => $firstContact->id,
        ]);
    }

    #[Test]
    public function admin_can_disable_customer_portal_membership(): void
    {
        $admin = $this->adminUser();
        [$client, $site, $contact] = $this->contactFixture('disable.me@example.test');
        $this->portalUser($contact, $client, $site);
        $membership = CustomerPortalMembership::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('tech.admin.system.customer-portal.memberships.disable', $membership))
            ->assertRedirect(route('tech.admin.system.customer-portal.index'))
            ->assertSessionHas('success');

        $membership->refresh();

        $this->assertSame(CustomerPortalMembership::STATUS_DISABLED, $membership->status);
        $this->assertNotNull($membership->disabled_at);
        $this->assertDatabaseHas('customer_portal_audit_events', [
            'event' => 'portal_membership_disabled',
            'customer_portal_account_id' => $membership->customer_portal_account_id,
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);
    }

    #[Test]
    public function queued_invitation_email_uses_customer_portal_system_template(): void
    {
        [$client, $site, $contact] = $this->contactFixture('template.portal@example.test');
        $invitation = CustomerPortalInvitation::query()->create([
            'contact_id' => $contact->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'email' => 'template.portal@example.test',
            'role' => CustomerPortalMembership::ROLE_VIEWER,
            'token_hash' => CustomerPortalInvitation::hashToken('plain-token'),
            'expires_at' => now()->addHours(72),
        ]);

        EmailTemplate::query()->create([
            'scope' => 'system',
            'key' => 'customer_portal_invite',
            'name' => 'Customer portal invitation',
            'subject' => 'Portal for {{ client_name }}',
            'body_html' => '<p>Hello {{ contact_name }}</p><p>{{ site_name }}</p><p>{{ portal_invite_url }}</p>',
            'body_text' => "Hello {{ contact_name }}\n{{ site_name }}\n{{ portal_invite_url }}",
            'variables' => ['contact_name', 'client_name', 'site_name', 'portal_invite_url'],
            'is_default' => true,
            'is_active' => true,
        ]);

        EmailAccount::query()->create([
            'address' => 'system@example.test',
            'from_name' => 'System',
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => ['system'],
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'system@example.test',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'system@example.test',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);

        app()->instance(SmtpAccountMailer::class, new class extends SmtpAccountMailer {
            public function send(EmailAccount $account, string $toEmail, ?string $toName, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = []): string
            {
                app()->instance('customer_portal_invite_email_payload', compact('toEmail', 'toName', 'subject', 'html', 'text'));

                return '<customer-portal@example.test>';
            }
        });

        app()->call([new SendCustomerPortalInvitationEmail($invitation->id, 'plain-token'), 'handle']);

        $payload = app('customer_portal_invite_email_payload');
        $this->assertSame('template.portal@example.test', $payload['toEmail']);
        $this->assertSame('Portal for '.$client->name, $payload['subject']);
        $this->assertStringContainsString($contact->display_name, $payload['html']);
        $this->assertStringContainsString($site->name, $payload['html']);
        $this->assertStringContainsString(route('customer-portal.invitations.accept', ['token' => 'plain-token']), $payload['text']);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo('customer_portal.view', 'customer_portal.manage', 'customer_portal.invite');

        return $admin;
    }

    /**
     * @return array{0: Client, 1: ClientSite, 2: Contact}
     */
    private function contactFixture(string $email): array
    {
        $client = Client::factory()->create(['name' => 'Portal Client AS']);
        $site = ClientSite::factory()->create([
            'client_id' => $client->id,
            'name' => 'Main Office',
        ]);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Ada Portal',
        ]);

        ContactEmail::query()->create([
            'contact_id' => $contact->id,
            'label' => 'work',
            'email' => $email,
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

        return [$client, $site, $contact];
    }

    private function portalUser(Contact $contact, Client $client, ?ClientSite $site = null): User
    {
        $email = $contact->emails()->orderByDesc('is_primary')->value('email');
        $user = User::factory()->create([
            'contact_id' => $contact->id,
            'name' => $contact->display_name,
            'email' => $email,
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
            'site_id' => $site?->id,
            'role' => CustomerPortalMembership::ROLE_VIEWER,
            'status' => CustomerPortalMembership::STATUS_ACTIVE,
        ]);

        return $user;
    }
}
