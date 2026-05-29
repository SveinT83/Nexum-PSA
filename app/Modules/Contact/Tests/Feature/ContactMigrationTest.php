<?php

namespace App\Modules\Contact\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactPhone;
use App\Modules\Contact\Models\ContactRelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContactMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_migrates_client_users_to_contacts_and_keeps_legacy_links(): void
    {
        $client = Client::factory()->create(['name' => 'Migration Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'HQ']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'user_id' => $user->id,
            'name' => 'Ada Contact',
            'email' => 'ada@example.test',
            'phone' => '+4712345678',
            'role' => 'Technical Contact',
            'is_default_for_site' => true,
            'is_default_for_client' => true,
            'active' => true,
        ]);

        $this->artisan('contacts:migrate-client-users')
            ->expectsOutput('processed: 1')
            ->expectsOutput('created: 1')
            ->assertExitCode(0);

        $clientUser->refresh();
        $user->refresh();

        $this->assertNotNull($clientUser->contact_id);
        $this->assertSame($clientUser->contact_id, $user->contact_id);

        $contact = Contact::query()->findOrFail($clientUser->contact_id);

        $this->assertSame('Ada Contact', $contact->display_name);
        $this->assertSame('Technical Contact', $contact->job_title);
        $this->assertTrue(ContactEmail::query()->where('contact_id', $contact->id)->where('email', 'ada@example.test')->where('is_primary', true)->exists());
        $this->assertTrue(ContactPhone::query()->where('contact_id', $contact->id)->where('phone', '+4712345678')->where('is_primary', true)->exists());
        $this->assertTrue(ContactRelation::query()
            ->where('contact_id', $contact->id)
            ->where('related_type', $client->getMorphClass())
            ->where('related_id', $client->id)
            ->where('relation_type', 'Technical Contact')
            ->where('is_primary', true)
            ->exists());
        $this->assertTrue(ContactRelation::query()
            ->where('contact_id', $contact->id)
            ->where('related_type', $site->getMorphClass())
            ->where('related_id', $site->id)
            ->where('relation_type', 'Technical Contact')
            ->where('is_primary', true)
            ->exists());
    }

    #[Test]
    public function migration_is_idempotent_and_reuses_contacts_by_email(): void
    {
        $client = Client::factory()->create();
        $siteA = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Site A']);
        $siteB = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Site B']);
        ClientUser::factory()->create([
            'client_site_id' => $siteA->id,
            'name' => 'Shared Contact A',
            'email' => 'shared@example.test',
        ]);
        ClientUser::factory()->create([
            'client_site_id' => $siteB->id,
            'name' => 'Shared Contact B',
            'email' => 'shared@example.test',
        ]);

        $this->artisan('contacts:migrate-client-users')->assertExitCode(0);
        $this->artisan('contacts:migrate-client-users')->assertExitCode(0);

        $this->assertSame(1, Contact::query()->whereHas('emails', fn ($query) => $query->where('email', 'shared@example.test'))->count());
        $this->assertSame(2, ClientUser::query()->whereNotNull('contact_id')->count());
        $this->assertSame(3, ContactRelation::query()->count());
    }
}
