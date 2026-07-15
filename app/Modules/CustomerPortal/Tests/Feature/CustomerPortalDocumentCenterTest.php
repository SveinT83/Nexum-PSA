<?php

namespace App\Modules\CustomerPortal\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Models\DocumentationTemplate;
use App\Modules\Taxonomy\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerPortalDocumentCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Tech']);
    }

    #[Test]
    public function portal_user_only_sees_published_documents_and_knowledge_inside_scope(): void
    {
        [$client, $site, $portalUser] = $this->portalFixture('document-center@example.test');
        $otherClient = Client::factory()->create(['name' => 'Other Client AS', 'active' => true]);
        $otherSite = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Warehouse']);
        $docCategory = $this->category('Portal Documents', 'documentation');
        $knowledgeCategory = $this->category('Portal Knowledge', 'knowledge');
        $template = DocumentationTemplate::query()->create([
            'category_id' => $docCategory->id,
            'name' => 'Portal Template',
            'fields' => [
                ['Name' => 'content', 'labelName' => 'Content', 'type' => 'textarea'],
            ],
            'is_active' => true,
        ]);

        $visibleDocument = $this->documentation($template, $docCategory, $client, $site, 'Portal VPN Runbook', 'VPN instructions for portal.', now());
        $this->documentation($template, $docCategory, $client, $site, 'Hidden Password Runbook', 'Hidden body.', null);
        $this->documentation($template, $docCategory, $client, $otherSite, 'Other Site Runbook', 'Other site body.', now());
        $this->documentation($template, $docCategory, $otherClient, null, 'Other Client Runbook', 'Other client body.', now());

        $clientArticle = $this->article($knowledgeCategory, 'Client Portal Article', 'Client knowledge body.', 'client-wide', 'published', $client);
        $publicArticle = $this->article($knowledgeCategory, 'General Portal Article', 'General knowledge body.', 'public', 'published');
        $internalArticle = $this->article($knowledgeCategory, 'Internal Only Article', 'Internal body.', 'internal', 'published');
        $draftArticle = $this->article($knowledgeCategory, 'Draft Portal Article', 'Draft body.', 'public', 'draft');
        $otherClientArticle = $this->article($knowledgeCategory, 'Other Client Article', 'Other client article body.', 'client-wide', 'published', $otherClient);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.documents.index'))
            ->assertOk()
            ->assertSee('Portal VPN Runbook')
            ->assertDontSee('Hidden Password Runbook')
            ->assertDontSee('Other Site Runbook')
            ->assertDontSee('Other Client Runbook');

        $this->actingAs($portalUser)
            ->get(route('customer-portal.documents.show', $visibleDocument))
            ->assertOk()
            ->assertSee('VPN instructions for portal.');

        $this->actingAs($portalUser)
            ->get(route('customer-portal.knowledge.index'))
            ->assertOk()
            ->assertSee('Client Portal Article')
            ->assertSee('General Portal Article')
            ->assertDontSee('Internal Only Article')
            ->assertDontSee('Draft Portal Article')
            ->assertDontSee('Other Client Article');

        $this->actingAs($portalUser)
            ->get(route('customer-portal.knowledge.show', $clientArticle))
            ->assertOk()
            ->assertSee('Client knowledge body.');

        $this->assertSame(1, $clientArticle->fresh()->view_count);

        foreach ([$internalArticle, $draftArticle, $otherClientArticle] as $article) {
            $this->actingAs($portalUser)
                ->get(route('customer-portal.knowledge.show', $article))
                ->assertNotFound();
        }

        $this->actingAs($portalUser)
            ->get(route('customer-portal.knowledge.show', $publicArticle))
            ->assertOk()
            ->assertSee('General knowledge body.');
    }

    #[Test]
    public function technician_can_publish_and_hide_client_documentation_for_portal(): void
    {
        [$client, $site, $portalUser] = $this->portalFixture('publish-document@example.test');
        $docCategory = $this->category('Portal Documents', 'documentation');
        $template = DocumentationTemplate::query()->create([
            'category_id' => $docCategory->id,
            'name' => 'Portal Template',
            'fields' => [
                ['Name' => 'content', 'labelName' => 'Content', 'type' => 'textarea'],
            ],
            'is_active' => true,
        ]);
        $documentation = $this->documentation($template, $docCategory, $client, $site, 'Publish Me', 'Publishable content.', null);
        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');

        $this->actingAs($portalUser)
            ->get(route('customer-portal.documents.index'))
            ->assertOk()
            ->assertDontSee('Publish Me');

        $this->actingAs($tech)
            ->post(route('tech.documentations.portal-visibility.update', $documentation), ['portal_visible' => '1'])
            ->assertRedirect(route('tech.documentations.show', $documentation))
            ->assertSessionHas('success');

        $documentation->refresh();
        $this->assertNotNull($documentation->portal_visible_at);
        $this->assertSame($tech->id, $documentation->portal_visible_by);
        $this->assertDatabaseHas('customer_portal_audit_events', [
            'event' => 'portal_documentation_visibility_enabled',
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.documents.index'))
            ->assertOk()
            ->assertSee('Publish Me');

        $this->actingAs($tech)
            ->post(route('tech.documentations.portal-visibility.update', $documentation), ['portal_visible' => '0'])
            ->assertRedirect(route('tech.documentations.show', $documentation));

        $this->assertNull($documentation->fresh()->portal_visible_at);
    }

    /**
     * @return array{0: Client, 1: ClientSite, 2: User}
     */
    private function portalFixture(string $email): array
    {
        $client = Client::factory()->create(['name' => 'Portal Document Client AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main Office']);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Portal Document Contact',
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

        $user = User::factory()->create([
            'contact_id' => $contact->id,
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
            'site_id' => $site->id,
            'role' => CustomerPortalMembership::ROLE_VIEWER,
            'status' => CustomerPortalMembership::STATUS_ACTIVE,
        ]);

        return [$client, $site, $user];
    }

    private function category(string $name, string $type): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => str($name.' '.$type)->slug()->toString(),
            'type' => $type,
            'is_active' => true,
        ]);
    }

    private function documentation(DocumentationTemplate $template, Category $category, Client $client, ?ClientSite $site, string $title, string $content, mixed $portalVisibleAt): Documentation
    {
        return Documentation::query()->create([
            'template_id' => $template->id,
            'category_id' => $category->id,
            'client_id' => $client->id,
            'site_id' => $site?->id,
            'title' => $title,
            'scope_type' => $site ? 'site' : 'client',
            'template_snapshot_json' => $template->fields,
            'data_json' => ['content' => $content],
            'portal_visible_at' => $portalVisibleAt,
        ]);
    }

    private function article(Category $category, string $title, string $body, string $visibility, string $status, ?Client $client = null): Article
    {
        $owner = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        return Article::query()->create([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'body_markdown' => $body,
            'body_html' => '<p>'.$body.'</p>',
            'visibility' => $visibility,
            'status' => $status,
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'client_scope_id' => $visibility === 'client-wide' ? $client?->id : null,
            'priority' => 0,
            'view_count' => 0,
        ]);
    }
}
