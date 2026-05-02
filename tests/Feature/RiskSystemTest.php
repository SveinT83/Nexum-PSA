<?php

namespace Tests\Feature;

use App\Models\Core\User;
use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use App\Models\Risk\RiskItemUpdate;
use App\Models\System\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RiskSystemTest extends TestCase
{
    use RefreshDatabase;

    protected $superuser;
    protected $normalUser;
    protected $category;
    protected $assessment;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup roles and user_management
        Role::create(['name' => 'Superuser']);
        $this->superuser = User::factory()->create();
        $this->superuser->assignRole('Superuser');

        $this->normalUser = User::factory()->create();

        // Setup category and assessment
        $this->category = Category::create(['name' => 'IT Security', 'type' => 'risk']);
        $this->assessment = RiskAssessment::create([
            'title' => 'Test Assessment',
            'status' => 'open'
        ]);
    }

    /** @test */
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
            'status' => 'open'
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('risk_items', [
            'title' => 'New Risk',
            'recommended_actions' => 'Action 1, Action 2',
            'category_id' => $this->category->id,
            'score' => 12
        ]);
    }

    /** @test */
    public function normal_user_cannot_edit_risk_item_they_did_not_create()
    {
        $riskItem = RiskItem::create([
            'risk_assessment_id' => $this->assessment->id,
            'title' => 'Existing Risk',
            'likelihood' => 1,
            'impact' => 1,
            'status' => 'open'
        ]);

        // Note: created_by is in RiskItemUpdate, not RiskItem itself.
        // RiskController checks $item->original_state?->created_by
        RiskItemUpdate::create([
            'risk_item_id' => $riskItem->id,
            'created_by' => $this->superuser->id,
            'likelihood' => 1,
            'impact' => 1,
            'status' => 'open',
            'note' => 'Initial state'
        ]);

        $this->actingAs($this->normalUser);

        $response = $this->put(route('tech.risk.items.update', $riskItem), [
            'title' => 'Changed Title'
        ]);

        $response->assertSessionHas('error');
        $this->assertEquals('Existing Risk', $riskItem->fresh()->title);
    }

    /** @test */
    public function creator_can_edit_risk_item()
    {
        $riskItem = RiskItem::create([
            'risk_assessment_id' => $this->assessment->id,
            'title' => 'My Risk',
            'likelihood' => 1,
            'impact' => 1,
            'status' => 'open'
        ]);

        RiskItemUpdate::create([
            'risk_item_id' => $riskItem->id,
            'created_by' => $this->normalUser->id,
            'likelihood' => 1,
            'impact' => 1,
            'status' => 'open',
            'note' => 'Initial state'
        ]);

        $this->actingAs($this->normalUser);

        $response = $this->put(route('tech.risk.items.update', $riskItem), [
            'title' => 'Updated Title',
            'likelihood' => 2,
            'impact' => 2,
            'status' => 'open'
        ]);

        $response->assertSessionHas('success');
        $this->assertEquals('Updated Title', $riskItem->fresh()->title);
    }

    /** @test */
    public function critical_fields_are_locked_when_history_exists()
    {
        $riskItem = RiskItem::create([
            'risk_assessment_id' => $this->assessment->id,
            'title' => 'History Risk',
            'likelihood' => 2,
            'impact' => 2,
            'status' => 'open'
        ]);

        // Add history
        RiskItemUpdate::create([
            'risk_item_id' => $riskItem->id,
            'created_by' => $this->superuser->id,
            'likelihood' => 2,
            'impact' => 2,
            'status' => 'open',
            'note' => 'Manual update'
        ]);

        $this->actingAs($this->superuser);

        $response = $this->put(route('tech.risk.items.update', $riskItem), [
            'title' => 'New Title',
            'likelihood' => 5, // Should be ignored
            'impact' => 5,     // Should be ignored
            'status' => 'accepted' // Should be ignored
        ]);

        $riskItem = $riskItem->fresh();
        $this->assertEquals('New Title', $riskItem->title);
        $this->assertEquals(2, $riskItem->likelihood);
        $this->assertEquals(2, $riskItem->impact);
        $this->assertEquals('open', $riskItem->status);

        // Verify history log was created
        $this->assertDatabaseHas('risk_item_updates', [
            'risk_item_id' => $riskItem->id,
            'note' => 'Risk item details updated: Title'
        ]);
    }

    /** @test */
    public function deleting_update_resets_risk_item_state()
    {
        $riskItem = RiskItem::create([
            'risk_assessment_id' => $this->assessment->id,
            'title' => 'Test Risk',
            'likelihood' => 5,
            'impact' => 5,
            'status' => 'open'
        ]);

        $update1 = RiskItemUpdate::create([
            'risk_item_id' => $riskItem->id,
            'created_by' => $this->superuser->id,
            'likelihood' => 5,
            'impact' => 5,
            'status' => 'open',
            'note' => 'Initial'
        ]);

        $update2 = RiskItemUpdate::create([
            'risk_item_id' => $riskItem->id,
            'created_by' => $this->superuser->id,
            'likelihood' => 1,
            'impact' => 1,
            'status' => 'mitigated',
            'note' => 'Fixed'
        ]);

        // Update main item state (usually done in storeItemUpdate)
        $riskItem->update([
            'likelihood' => 1,
            'impact' => 1,
            'status' => 'mitigated'
        ]);

        $this->actingAs($this->superuser);

        $response = $this->delete(route('tech.risk.updates.destroy', $update2));

        $riskItem = $riskItem->fresh();
        $this->assertEquals(5, $riskItem->likelihood);
        $this->assertEquals(5, $riskItem->impact);
        $this->assertEquals('open', $riskItem->status);
    }
}
