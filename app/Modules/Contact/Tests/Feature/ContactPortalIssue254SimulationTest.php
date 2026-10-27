<?php

namespace App\Modules\Contact\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Contact\Livewire\Tech\ContactForm;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Support\ContactSettings;
use App\Modules\CustomerPortal\Jobs\SendCustomerPortalInvitationEmail;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalAuditEvent;
use App\Modules\CustomerPortal\Models\CustomerPortalInvitation;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Issue #254: simulate the complete create-to-activation workflow with isolated test data. */
class ContactPortalIssue254SimulationTest extends TestCase
{
    use RefreshDatabase;

    public static function entryPointsAndRoles(): array
    {
        $cases = [];
        foreach (['central', 'client', 'site'] as $entry) {
            foreach (CustomerPortalMembership::roleOptions() as $role => $label) {
                $cases[$entry.' / '.$label] = [$entry, $role];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('entryPointsAndRoles')]
    public function contact_created_invitation_activates_exactly_the_selected_portal_membership(string $entry, string $role): void
    {
        Queue::fake();
        Role::findOrCreate('Tech', 'web');
        $staff = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $staff->assignRole('Tech');
        foreach (['contact.view', 'contact.create', 'contact.update', 'customer_portal.invite'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $staff->givePermissionTo($permission);
        }
        app(ContactSettings::class)->update(['send_portal_invitation_by_default' => false]);
        $client = Client::factory()->create(['active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'is_default' => true]);
        $arguments = match ($entry) {
            'client' => ['activeClientId' => $client->id],
            'site' => ['activeClientId' => $client->id, 'activeSiteId' => $site->id],
            default => [],
        };
        $expectedSiteId = $entry === 'client' ? null : $site->id;
        $email = 'issue254.'.$entry.'.'.$role.'@example.test';

        // Use the same Livewire create action as all three browser entry points.
        $form = Livewire::actingAs($staff)->test(ContactForm::class, $arguments)
            ->assertSet('send_customer_portal_invitation', false)
            ->assertSet('portal_role', CustomerPortalMembership::ROLE_VIEWER)
            ->assertDontSee('Portal role');
        if ($entry === 'central') {
            $form->call('selectClient', $client->id);
        }
        $form->set('display_name', 'Issue 254 simulation')
            ->set('email', $email)
            ->set('job_title', 'Accountant')
            ->set('relation_type', 'billing_contact')
            ->set('send_customer_portal_invitation', true)
            ->assertSee('Portal role')
            ->set('portal_role', $role)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('customer_portal_invitations', 1);
        $this->assertDatabaseCount('customer_portal_memberships', 0);
        $contact = Contact::query()->sole();
        $invitation = CustomerPortalInvitation::query()->sole();
        $this->assertSame('Accountant', $contact->job_title);
        $this->assertDatabaseHas('contact_relations', ['contact_id' => $contact->id, 'relation_type' => 'billing_contact']);
        $this->assertSame($contact->id, $invitation->contact_id);
        $this->assertSame($client->id, $invitation->client_id);
        $this->assertSame($expectedSiteId, $invitation->site_id);
        $this->assertSame($role, $invitation->role);
        $this->assertNull($invitation->accepted_at);
        $this->assertNull($invitation->revoked_at);
        $audit = CustomerPortalAuditEvent::query()->where('event', 'portal_invitation_created')->sole();
        $this->assertSame($role, $audit->metadata['role']);
        Queue::assertPushed(SendCustomerPortalInvitationEmail::class, 1);
        $job = Queue::pushed(SendCustomerPortalInvitationEmail::class)->sole();
        $this->assertSame($invitation->id, $job->invitationId);

        // Consume the test queue token through the real activation routes; no SMTP is used.
        auth()->logout();
        $this->get(route('customer-portal.invitations.accept', ['token' => $job->token]))
            ->assertOk()->assertSee('Activate portal access');
        $this->post(route('customer-portal.invitations.accept.store', ['token' => $job->token]), [
            'password' => 'SimulationOnly254!',
            'password_confirmation' => 'SimulationOnly254!',
        ])->assertRedirect(route('customer-portal.dashboard'));

        $portalUser = User::query()->where('email', $email)->sole();
        $account = CustomerPortalAccount::query()->where('user_id', $portalUser->id)->sole();
        $membership = CustomerPortalMembership::query()->sole();
        $this->assertSame($contact->id, $portalUser->contact_id);
        $this->assertSame(User::STATUS_ACTIVE, $portalUser->status);
        $this->assertSame($contact->id, $account->contact_id);
        $this->assertSame($account->id, $membership->customer_portal_account_id);
        $this->assertSame($client->id, $membership->client_id);
        $this->assertSame($expectedSiteId, $membership->site_id);
        $this->assertSame($role, $membership->role);
        $this->assertTrue($membership->isActive());
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertFalse($portalUser->roles()->exists());
        $this->assertFalse($portalUser->permissions()->exists());
        $this->assertDatabaseHas('customer_portal_audit_events', [
            'event' => 'portal_invitation_accepted',
            'customer_portal_account_id' => $account->id,
            'contact_id' => $contact->id,
            'client_id' => $client->id,
            'site_id' => $expectedSiteId,
        ]);
        $this->actingAs($portalUser)->get(route('customer-portal.dashboard'))->assertOk()->assertSee($client->name);
        $this->get(route('tech.dashboard'))->assertForbidden();
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('customer_portal_invitations', 1);
        $this->assertDatabaseCount('customer_portal_memberships', 1);
        Queue::assertPushed(SendCustomerPortalInvitationEmail::class, 1);
    }
}
