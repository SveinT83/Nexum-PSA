<?php

namespace App\Modules\Risk\Tests\Feature;

use App\Models\Core\User;
use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use App\Models\Risk\RiskItemUpdate;
use App\Models\Settings\CommonSetting;
use App\Modules\Taxonomy\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature coverage for the Risk module's core state transitions.
 *
 * These tests intentionally exercise the HTTP routes rather than the action
 * classes directly. The goal is to verify that validation, route-model binding,
 * controller authorization, action behavior, and database persistence work
 * together as the Tech UI expects.
 */
class RiskSystemTest extends TestCase
{
    use RefreshDatabase;

    /** User with module-level destructive/edit permissions. */
    protected $superuser;

    /** User without Superuser role, used for ownership authorization checks. */
    protected $normalUser;

    /** Category used to verify risk item categorization is persisted. */
    protected $category;

    /** Shared assessment used as the parent record in most test cases. */
    protected $assessment;

    /**
     * Build the minimum domain state required by the Risk routes.
     *
     * The tests use Spatie roles because RiskController currently performs
     * direct role checks for Superuser-only operations.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Superuser']);
        Role::create(['name' => 'Tech']);
        $this->superuser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->superuser->assignRole('Superuser');

        $this->normalUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->normalUser->assignRole('Tech');

        $this->category = Category::create(['name' => 'IT Security', 'type' => 'risk']);
        $this->assessment = RiskAssessment::create([
            'title' => 'Test Assessment',
            'status' => 'open',
        ]);
    }

    #[Test]
    public function superuser_can_update_risk_settings()
    {
        $this->actingAs($this->superuser);

        $this->get(route('tech.admin.settings.risk'))
            ->assertOk()
            ->assertViewIs('risk::Admin.Settings.edit')
            ->assertSee('Risk Settings')
            ->assertSee('Assessment Defaults');

        $this->put(route('tech.admin.settings.risk.update'), [
            'default_assessment_scope' => 'client',
            'default_assessment_status' => 'open',
            'default_item_likelihood' => 4,
            'default_item_impact' => 5,
            'default_item_status' => 'accepted',
            'default_item_review_days' => 45,
        ])->assertRedirect(route('tech.admin.settings.risk'));

        $settings = json_decode(CommonSetting::query()->where('type', 'risk')->where('name', 'defaults')->value('json'), true);

        $this->assertSame('client', $settings['default_assessment_scope']);
        $this->assertSame('open', $settings['default_assessment_status']);
        $this->assertSame(4, $settings['default_item_likelihood']);
        $this->assertSame(5, $settings['default_item_impact']);
        $this->assertSame('accepted', $settings['default_item_status']);
        $this->assertSame(45, $settings['default_item_review_days']);
    }

    #[Test]
    public function risk_creation_uses_configured_defaults()
    {
        $this->actingAs($this->superuser);

        $this->put(route('tech.admin.settings.risk.update'), [
            'default_assessment_scope' => 'internal',
            'default_assessment_status' => 'open',
            'default_item_likelihood' => 4,
            'default_item_impact' => 5,
            'default_item_status' => 'accepted',
            'default_item_review_days' => 45,
        ])->assertRedirect(route('tech.admin.settings.risk'));

        $this->post(route('tech.risk.store'), [
            'title' => 'Defaulted assessment',
        ])->assertRedirect();

        $assessment = RiskAssessment::query()->where('title', 'Defaulted assessment')->firstOrFail();

        $this->assertSame('open', $assessment->status);
        $this->assertNull($assessment->client_id);

        $this->post(route('tech.risk.items.store', $assessment), [
            'title' => 'Defaulted item',
        ])->assertRedirect();

        $item = RiskItem::query()->where('title', 'Defaulted item')->firstOrFail();

        $this->assertSame(4, $item->likelihood);
        $this->assertSame(5, $item->impact);
        $this->assertSame(20, $item->score);
        $this->assertSame('accepted', $item->status);
        $this->assertTrue($item->next_review_at->isSameDay(now()->addDays(45)));
    }

    #[Test]
    public function authenticated_api_user_can_manage_risk_assessments_and_items(): void
    {
        Sanctum::actingAs($this->superuser, ['risk.read', 'risk.create', 'risk.update']);

        $assessmentResponse = $this->postJson(route('api.v1.risk.assessments.store'), [
            'title' => 'API Assessment',
            'description' => 'Created through API.',
            'scope' => 'internal',
            'status' => 'open',
        ]);

        $assessmentResponse->assertCreated()
            ->assertJsonPath('data.title', 'API Assessment')
            ->assertJsonPath('data.status', 'open');

        $assessmentId = $assessmentResponse->json('data.id');

        $itemResponse = $this->postJson(route('api.v1.risk.assessments.items.store', $assessmentId), [
            'title' => 'API Risk Item',
            'description' => 'Risk created through API.',
            'recommended_actions' => 'Mitigate through controls.',
            'category_id' => $this->category->id,
            'likelihood' => 3,
            'impact' => 4,
            'status' => 'open',
        ]);

        $itemResponse->assertCreated()
            ->assertJsonPath('data.title', 'API Risk Item')
            ->assertJsonPath('data.score', 12)
            ->assertJsonPath('data.updates.0.note', 'Initial risk identified');

        $itemId = $itemResponse->json('data.id');

        $this->getJson(route('api.v1.risk.assessments.index', ['q' => 'API']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $assessmentId);

        $this->patchJson(route('api.v1.risk.items.update', $itemId), [
            'title' => 'API Risk Item Updated',
            'likelihood' => 5,
            'impact' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'API Risk Item Updated')
            ->assertJsonPath('data.score', 12);

        $this->postJson(route('api.v1.risk.items.updates.store', $itemId), [
            'note' => 'Risk accepted after review.',
            'status' => 'accepted',
            'likelihood' => 2,
            'impact' => 2,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.score', 4);

        $this->assertDatabaseHas('risk_items', [
            'id' => $itemId,
            'status' => 'accepted',
            'score' => 4,
        ]);
    }

    #[Test]
    public function risk_read_api_token_cannot_create_or_update_risk_records(): void
    {
        Sanctum::actingAs($this->superuser, ['risk.read']);

        $this->getJson(route('api.v1.risk.assessments.show', $this->assessment))
            ->assertOk()
            ->assertJsonPath('data.title', 'Test Assessment');

        $this->postJson(route('api.v1.risk.assessments.store'), [
            'title' => 'Denied Assessment',
        ])->assertForbidden();

        $this->patchJson(route('api.v1.risk.assessments.update', $this->assessment), [
            'title' => 'Denied Update',
        ])->assertForbidden();
    }

    #[Test]
    public function superuser_can_create_risk_item_with_category_and_recommended_actions()
    {
        $this->actingAs($this->superuser);

        $response = $this->post(route('tech.risk.items.store', $this->assessment), [
            'title' => 'New Risk',
            'description' => 'Description here',
            'recommended_actions' => 'Action 1, Action 2',
            'category_id' => $this->category->id,
            'likelihood' => 3,
            'impact' => 4,
            'status' => 'open',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('risk_items', [
            'title' => 'New Risk',
            'recommended_actions' => 'Action 1, Action 2',
            'category_id' => $this->category->id,
            'score' => 12,
        ]);
    }

    #[Test]
    public function normal_user_cannot_edit_risk_item_they_did_not_create()
    {
        $riskItem = RiskItem::create([
            'risk_assessment_id' => $this->assessment->id,
            'title' => 'Existing Risk',
            'likelihood' => 1,
            'impact' => 1,
            'status' => 'open',
        ]);

        // Ownership is based on the initial history row, not the RiskItem row.
        // RiskItem has no created_by column in the current schema.
        RiskItemUpdate::create([
            'risk_item_id' => $riskItem->id,
            'created_by' => $this->superuser->id,
            'likelihood' => 1,
            'impact' => 1,
            'status' => 'open',
            'note' => 'Initial state',
        ]);

        $this->actingAs($this->normalUser);

        $response = $this->put(route('tech.risk.items.update', $riskItem), [
            'title' => 'Changed Title'
        ]);

        $response->assertSessionHas('error');
        $this->assertEquals('Existing Risk', $riskItem->fresh()->title);
    }

    #[Test]
    public function creator_can_edit_risk_item()
    {
        $riskItem = RiskItem::create([
            'risk_assessment_id' => $this->assessment->id,
            'title' => 'My Risk',
            'likelihood' => 1,
            'impact' => 1,
            'status' => 'open',
        ]);

        RiskItemUpdate::create([
            'risk_item_id' => $riskItem->id,
            'created_by' => $this->normalUser->id,
            'likelihood' => 1,
            'impact' => 1,
            'status' => 'open',
            'note' => 'Initial state',
        ]);

        $this->actingAs($this->normalUser);

        $response = $this->put(route('tech.risk.items.update', $riskItem), [
            'title' => 'Updated Title',
            'likelihood' => 2,
            'impact' => 2,
            'status' => 'open',
        ]);

        $response->assertSessionHas('success');
        $this->assertEquals('Updated Title', $riskItem->fresh()->title);
    }

    #[Test]
    public function critical_fields_are_locked_when_history_exists()
    {
        $riskItem = RiskItem::create([
            'risk_assessment_id' => $this->assessment->id,
            'title' => 'History Risk',
            'likelihood' => 2,
            'impact' => 2,
            'status' => 'open',
        ]);

        // Once history exists, likelihood, impact, and status must be changed
        // through the update-history workflow, not the descriptive edit route.
        RiskItemUpdate::create([
            'risk_item_id' => $riskItem->id,
            'created_by' => $this->superuser->id,
            'likelihood' => 2,
            'impact' => 2,
            'status' => 'open',
            'note' => 'Manual update',
        ]);

        $this->actingAs($this->superuser);

        $response = $this->put(route('tech.risk.items.update', $riskItem), [
            'title' => 'New Title',
            'likelihood' => 5,
            'impact' => 5,
            'status' => 'accepted',
        ]);

        $riskItem = $riskItem->fresh();
        $this->assertEquals('New Title', $riskItem->title);
        $this->assertEquals(2, $riskItem->likelihood);
        $this->assertEquals(2, $riskItem->impact);
        $this->assertEquals('open', $riskItem->status);

        // Descriptive edits still create a history note so the audit trail
        // records that the item text changed.
        $this->assertDatabaseHas('risk_item_updates', [
            'risk_item_id' => $riskItem->id,
            'note' => 'Risk item details updated: Title',
        ]);
    }

    #[Test]
    public function deleting_update_resets_risk_item_state()
    {
        $riskItem = RiskItem::create([
            'risk_assessment_id' => $this->assessment->id,
            'title' => 'Test Risk',
            'likelihood' => 5,
            'impact' => 5,
            'status' => 'open',
        ]);

        RiskItemUpdate::create([
            'risk_item_id' => $riskItem->id,
            'created_by' => $this->superuser->id,
            'likelihood' => 5,
            'impact' => 5,
            'status' => 'open',
            'note' => 'Initial',
        ]);

        $update2 = RiskItemUpdate::create([
            'risk_item_id' => $riskItem->id,
            'created_by' => $this->superuser->id,
            'likelihood' => 1,
            'impact' => 1,
            'status' => 'mitigated',
            'note' => 'Fixed',
        ]);

        // Simulate the current snapshot after the second update. In production
        // StoreRiskItemUpdate performs this synchronization.
        $riskItem->update([
            'likelihood' => 1,
            'impact' => 1,
            'status' => 'mitigated',
        ]);

        $this->actingAs($this->superuser);

        $this->delete(route('tech.risk.updates.destroy', $update2));

        $riskItem = $riskItem->fresh();
        $this->assertEquals(5, $riskItem->likelihood);
        $this->assertEquals(5, $riskItem->impact);
        $this->assertEquals('open', $riskItem->status);
    }
}
