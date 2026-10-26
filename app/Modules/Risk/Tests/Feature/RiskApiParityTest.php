<?php

namespace App\Modules\Risk\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use App\Models\Risk\RiskItemUpdate;
use App\Models\Settings\CommonSetting;
use App\Modules\Risk\Models\RiskApiCommand;
use App\Modules\Risk\Support\RiskRevision;
use App\Modules\Taxonomy\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Operational parity: persistence, authority, replay and historical state are verified together. */
class RiskApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    private User $tech;

    private string $base = '/api/v1/risk';

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Superuser');
        Role::findOrCreate('Tech');
        $this->root = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->root->assignRole('Superuser');
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->login();
    }

    private function login(?User $actor = null, array $scopes = ['*']): void
    {
        Sanctum::actingAs($actor ?? $this->root, $scopes);
    }

    private function assessment(): RiskAssessment
    {
        $response = $this->postJson($this->base.'/assessments', [
            'title' => 'Synthetic assessment', 'description' => 'Preserve metadata', 'scope' => 'internal',
        ])->assertCreated();

        return RiskAssessment::findOrFail($response->json('data.id'));
    }

    private function item(RiskAssessment $assessment, string $status = 'open'): RiskItem
    {
        $response = $this->postJson($this->base.'/assessments/'.$assessment->id.'/items', [
            'title' => 'Synthetic item', 'likelihood' => 3, 'impact' => 4, 'status' => $status,
        ])->assertCreated()->assertJsonPath('data.score', 12);

        return RiskItem::findOrFail($response->json('data.id'));
    }

    private function headers($record, string $key): array
    {
        return ['If-Match' => RiskRevision::of($record), 'Idempotency-Key' => $key];
    }

    #[Test]
    public function every_operation_requires_its_scope_and_current_actor_permission(): void
    {
        $manifest = json_decode(file_get_contents(base_path('docs/plans/2026-10-26-risk-api-contract.json')), true);
        foreach ($manifest['operations'] as $op) {
            $url = str_replace(['{assessment}', '{item}', '{update}', '{command}'], ['999', '999', '999', 'missing-command'], $op['path']);
            $this->login($this->root, []);
            $this->json($op['method'], $url)->assertForbidden();
            if ($op['permissions']) {
                $this->login($this->tech);
                $this->json($op['method'], $url)->assertForbidden();
            }
        }
        $this->root->update(['status' => User::STATUS_DISABLED]);
        $this->login();
        $this->getJson($this->base.'/context')->assertForbidden();
    }

    #[Test]
    public function context_and_lookups_are_pure_bounded_and_minimal(): void
    {
        $before = [DB::table('work_contexts')->count(), CommonSetting::count(), RiskApiCommand::count()];
        $active = Client::factory()->create(['name' => 'Visible client', 'active' => true]);
        Client::factory()->create(['name' => 'Hidden client', 'active' => false]);
        Category::create(['name' => 'Risk category', 'type' => 'risk']);
        Category::create(['name' => 'Other category', 'type' => 'ticket']);
        $this->getJson($this->base.'/context')->assertOk()->assertJsonCount(27, 'data.capabilities');
        $this->getJson($this->base.'/settings')->assertOk();
        $this->getJson($this->base.'/context/clients?per_page=1')
            ->assertOk()->assertJsonPath('data.0.id', $active->id)->assertJsonPath('total', 1)
            ->assertJsonCount(2, 'data.0');
        $this->getJson($this->base.'/context/categories')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($this->base.'/context/clients?per_page=101')->assertUnprocessable();
        $this->assertSame($before, [DB::table('work_contexts')->count(), CommonSetting::count(), RiskApiCommand::count()]);
    }

    #[Test]
    public function metadata_patch_preserves_omission_and_rejects_ignored_or_invalid_inputs(): void
    {
        $a = $this->assessment();
        $this->patchJson($this->base.'/assessments/'.$a->id, ['title' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.description', 'Preserve metadata');
        foreach ([
            ['status' => 'approved'], ['approved_by' => $this->root->id], ['scope' => 'client'],
            ['scope' => 'internal', 'client_id' => Client::factory()->create()->id],
        ] as $input) {
            $this->patchJson($this->base.'/assessments/'.$a->id, $input)->assertUnprocessable();
        }
        $this->postJson($this->base.'/assessments', ['title' => 'No approval injection', 'status' => 'approved'])->assertUnprocessable();
        $inactive = Client::factory()->create(['active' => false]);
        $this->postJson($this->base.'/assessments', ['title' => 'Invalid', 'scope' => 'client', 'client_id' => $inactive->id])->assertUnprocessable();
        $this->assertNull($a->fresh()->client_id);
        $this->assertNull($a->fresh()->approved_at);
        $this->assertSame('Renamed', $a->fresh()->title);
    }

    #[Test]
    public function reads_have_stable_paging_legacy_scope_and_conflict_validation(): void
    {
        $a = $this->assessment();
        $i = $this->item($a);
        $this->getJson($this->base.'/assessments?scope=internal&per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->base.'/assessments?scope=internal&context_type=client')->assertUnprocessable();
        $this->getJson($this->base.'/assessments/'.$a->id.'/items?per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->base.'/items/'.$i->id.'/updates?per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->base.'/items/'.$i->id.'/context')->assertOk()->assertJsonPath('data.locked_fields.0', 'likelihood');
        $this->getJson($this->base.'/assessments/'.$a->id.'/context')->assertOk()->assertJsonPath('data.resource_id', $a->id);
    }

    #[Test]
    public function item_edit_rechecks_original_creator_and_rejects_locked_fields_atomically(): void
    {
        $a = $this->assessment();
        $i = $this->item($a);
        $this->tech->givePermissionTo(['risk.view', 'risk.update']);
        $this->login($this->tech);
        $this->patchJson($this->base.'/items/'.$i->id, ['title' => 'Not owner'])->assertForbidden();
        $this->login();
        $this->patchJson($this->base.'/items/'.$i->id, ['title' => 'No partial save', 'impact' => 5])->assertUnprocessable();
        $this->assertSame('Synthetic item', $i->fresh()->title);
        $this->patchJson($this->base.'/items/'.$i->id, ['title' => 'Changed', 'impact' => 4], ['Idempotency-Key' => 'description-once'])
            ->assertOk()->assertJsonPath('operation.replayed', false);
        $this->patchJson($this->base.'/items/'.$i->id, ['title' => 'Changed', 'impact' => 4], ['Idempotency-Key' => 'description-once'])
            ->assertOk()->assertJsonPath('operation.replayed', true);
        $this->patchJson($this->base.'/items/'.$i->id, ['title' => 'Changed'])->assertOk();
        $this->assertSame(2, $i->updates()->count());
        $this->assertSame(12, $i->fresh()->score);
    }

    #[Test]
    public function approval_requires_nonempty_addressed_items_and_never_restamps_a_replay(): void
    {
        $a = $this->assessment();
        $url = $this->base.'/assessments/'.$a->id.'/approve';
        $this->postJson($url, [], $this->headers($a, 'empty-approval'))->assertConflict();
        $i = $this->item($a);
        $this->postJson($url, [], $this->headers($a, 'open-approval'))->assertConflict();
        $i->update(['status' => 'legacy-unknown']);
        $this->postJson($url, [], $this->headers($a, 'unknown-approval'))->assertConflict();
        $this->postJson($this->base.'/items/'.$i->id.'/updates', ['note' => 'Reviewed', 'status' => 'accepted'])->assertCreated();
        $h = $this->headers($a, 'accepted-approval');
        $first = $this->postJson($url, [], $h)->assertOk()->assertJsonPath('data.approval_valid', true);
        $stamp = $a->fresh()->approved_at->toISOString();
        $this->postJson($url, [], $h)->assertOk()->assertJsonPath('operation.replayed', true)
            ->assertJsonPath('operation.id', $first->json('operation.id'));
        $this->travel(1)->minutes();
        $this->postJson($url, [], $this->headers($a, 'already-approved'))->assertOk();
        $this->assertSame($stamp, $a->fresh()->approved_at->toISOString());
        $this->postJson($this->base.'/items/'.$i->id.'/updates', ['note' => 'New information', 'status' => 'open'])->assertCreated();
        $this->getJson($this->base.'/assessments/'.$a->id)->assertOk()->assertJsonPath('data.approval_valid', false)->assertJsonPath('data.status', 'in_progress');
        $this->assertSame($stamp, $a->fresh()->approved_at->toISOString());
    }

    #[Test]
    public function new_actions_require_revision_and_key_and_detect_same_second_changes(): void
    {
        $a = $this->assessment();
        $i = $this->item($a, 'accepted');
        $url = $this->base.'/assessments/'.$a->id.'/approve';
        $this->postJson($url)->assertUnprocessable();
        $this->postJson($url, [], ['Idempotency-Key' => 'required-revision'])->assertStatus(428);
        $old = $this->headers($a, 'stale-revision');
        $i->update(['title' => 'Changed within the same second']);
        $this->postJson($url, [], $old)->assertConflict();
        $this->assertSame(0, RiskApiCommand::count());
        $good = $this->headers($a, 'single-command');
        $this->postJson($url, [], $good)->assertOk();
        $this->postJson($url, [], array_replace($good, ['If-Match' => RiskRevision::of($a)]))->assertConflict();
    }

    #[Test]
    public function deleting_newest_nonnewest_and_last_history_reconciles_deterministically(): void
    {
        $a = $this->assessment();
        $i = $this->item($a);
        $initial = $i->updates()->first();
        $second = $this->postJson($this->base.'/items/'.$i->id.'/updates', ['note' => 'Second', 'status' => 'mitigated', 'likelihood' => 2])->assertCreated()->json('data.id');
        $third = $this->postJson($this->base.'/items/'.$i->id.'/updates', ['note' => 'Third', 'status' => 'accepted', 'impact' => 1])->assertCreated()->json('data.id');
        DB::table('risk_item_updates')->where('risk_item_id', $i->id)->update(['created_at' => '2026-10-26 12:00:00']);
        $row = RiskItemUpdate::findOrFail($second);
        $this->deleteJson($this->base.'/updates/'.$second, [], $this->headers($row, 'delete-middle'))
            ->assertOk()->assertJsonPath('data.item.status', 'accepted')->assertJsonPath('data.item.history_count', 2);
        $row = RiskItemUpdate::findOrFail($third);
        $h = $this->headers($row, 'delete-newest');
        $this->deleteJson($this->base.'/updates/'.$third, [], $h)
            ->assertOk()->assertJsonPath('data.item.status', 'open')->assertJsonPath('data.item.score', 12);
        $this->deleteJson($this->base.'/updates/'.$third, [], $h)->assertOk()->assertJsonPath('operation.replayed', true);
        $this->deleteJson($this->base.'/updates/'.$initial->id, [], $this->headers($initial, 'delete-last-row'))
            ->assertOk()->assertJsonPath('data.item.history_count', 0)->assertJsonPath('data.item.score', 12);
        $this->assertSame(0, $i->updates()->count());
        $this->tech->givePermissionTo('risk.update');
        $this->login($this->tech);
        $this->patchJson($this->base.'/items/'.$i->id, ['title' => 'No invented ownership'])->assertForbidden();
    }

    #[Test]
    public function history_delete_requires_exact_creator_and_deletion_closes_all_child_paths(): void
    {
        $a = $this->assessment();
        $i = $this->item($a);
        $u = $i->updates()->first();
        $this->tech->givePermissionTo(['risk.view', 'risk.delete']);
        $this->login($this->tech);
        $this->deleteJson($this->base.'/updates/'.$u->id, [], $this->headers($u, 'foreign-history'))->assertForbidden();
        $this->deleteJson($this->base.'/items/'.$i->id, [], $this->headers($i, 'foreign-item'))->assertForbidden();
        $this->login();
        $h = $this->headers($a, 'delete-assessment');
        $this->deleteJson($this->base.'/assessments/'.$a->id, [], $h)->assertOk()->assertJsonPath('data.deleted', true);
        $this->deleteJson($this->base.'/assessments/'.$a->id, [], $h)->assertOk()->assertJsonPath('operation.replayed', true);
        foreach (['/assessments/'.$a->id, '/assessments/'.$a->id.'/items', '/assessments/'.$a->id.'/pdf', '/items/'.$i->id, '/items/'.$i->id.'/context', '/items/'.$i->id.'/updates', '/updates/'.$u->id] as $path) {
            $this->getJson($this->base.$path)->assertNotFound();
        }
        $this->assertNotNull(RiskItem::find($i->id));
        $this->assertNotNull(RiskItemUpdate::find($u->id));
    }

    #[Test]
    public function receipts_recheck_current_underlying_rights_and_token_ceiling(): void
    {
        $this->tech->givePermissionTo(['risk.create', 'risk.view']);
        $this->login($this->tech, ['risk.create', 'risk.operations.read']);
        $r = $this->postJson($this->base.'/assessments', ['title' => 'Receipt'], ['Idempotency-Key' => 'create-receipt'])->assertCreated();
        $id = $r->json('operation.id');
        $this->getJson($this->base.'/operations/'.$id)->assertOk()->assertJsonPath('data.state', 'completed');
        $this->login($this->tech, ['risk.operations.read']);
        $this->getJson($this->base.'/operations/'.$id)->assertForbidden();
        $this->tech->revokePermissionTo('risk.create');
        $this->login($this->tech);
        $this->getJson($this->base.'/operations/'.$id)->assertForbidden();
        $this->postJson($this->base.'/assessments', ['title' => 'Receipt'], ['Idempotency-Key' => 'create-receipt'])->assertForbidden();
        $this->login();
        $this->getJson($this->base.'/operations/'.$id)->assertNotFound();
        $this->assertSame(1, RiskApiCommand::count());
    }

    #[Test]
    public function settings_patch_merges_full_put_validates_and_defaults_only_affect_future_records(): void
    {
        $a = $this->assessment();
        $i = $this->item($a);
        $before = $this->getJson($this->base.'/settings')->assertOk()->json('data');
        $this->patchJson($this->base.'/settings', ['default_item_likelihood' => 5], $this->headers(null, 'settings-patch'))->assertOk()
            ->assertJsonPath('data.default_item_likelihood', 5)->assertJsonPath('data.default_item_impact', $before['default_item_impact']);
        $this->putJson($this->base.'/settings', ['default_item_likelihood' => 2], $this->headers(null, 'settings-incomplete'))->assertUnprocessable();
        $this->patchJson($this->base.'/settings', ['default_item_review_days' => 3651], $this->headers(null, 'settings-invalid'))->assertUnprocessable();
        unset($before['revision']);
        $this->putJson($this->base.'/settings', $before, $this->headers(null, 'settings-full-put'))->assertOk();
        $this->assertSame(3, $i->fresh()->likelihood);
        $this->assertSame(4, $i->fresh()->impact);
    }

    #[Test]
    public function pdf_returns_the_shared_binary_report_and_denies_deleted_parents(): void
    {
        $a = $this->assessment();
        $this->item($a);
        $r = $this->get($this->base.'/assessments/'.$a->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $r->getContent());
        $this->assertStringContainsString('.pdf', $r->headers->get('Content-Disposition'));
        $a->delete();
        $this->getJson($this->base.'/assessments/'.$a->id.'/pdf')->assertNotFound();
    }

    /** Validate real responses against the same frozen schemas used by Swagger. */
    private function checked(string $method, string $path, array $data = [], array $headers = [])
    {
        $manifest = json_decode(file_get_contents(base_path('docs/plans/2026-10-26-risk-api-contract.json')), true);
        $op = collect($manifest['operations'])->first(function ($op) use ($method, $path) {
            $pattern = preg_replace('/\{[^}]+\}/', '[^/]+', $op['path']);

            return $op['method'] === $method && preg_match('~^'.$pattern.'$~', $path);
        });
        $this->assertNotNull($op, $method.' '.$path);
        $response = $this->json($method, $path, $data, $headers)->assertStatus($op['status']);
        if ($op['response'] === 'binary_pdf') {
            $this->assertStringStartsWith('%PDF-', $response->getContent());
        } else {
            $this->schema($response->json(), $manifest['schemas'][$op['response']], $manifest['schemas'], $method.' '.$path);
        }

        return $response;
    }

    private function schema($value, array $schema, array $definitions, string $path): void
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        if (isset($schema['$ref'])) {
            $this->schema($value, $definitions[basename($schema['$ref'])], $definitions, $path);

            return;
        }
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        $type = $schema['type'] ?? null;
        if ($type === 'object') {
            $this->assertIsArray($value, $path);
            foreach ($schema['required'] ?? [] as $key) {
                $this->assertArrayHasKey($key, $value, $path);
            }
            foreach ($value as $key => $child) {
                if (isset($schema['properties'][$key])) {
                    $this->schema($child, $schema['properties'][$key], $definitions, $path.'.'.$key);
                } elseif (($schema['additionalProperties'] ?? null) === false) {
                    $this->fail('Undocumented property '.$path.'.'.$key);
                }
            }
        } elseif ($type === 'array') {
            $this->assertIsArray($value, $path);
            foreach ($value as $key => $child) {
                $this->schema($child, $schema['items'], $definitions, $path.'.'.$key);
            }
        } elseif ($type === 'integer') {
            $this->assertIsInt($value, $path);
        } elseif ($type === 'number') {
            $this->assertTrue(is_int($value) || is_float($value), $path);
        } elseif ($type === 'boolean') {
            $this->assertIsBool($value, $path);
        } elseif ($type === 'string') {
            $this->assertIsString($value, $path);
        }
        if (isset($schema['enum'])) {
            $this->assertContains($value, $schema['enum'], $path);
        }
    }

    #[Test]
    public function all_twenty_seven_success_contracts_match_real_operational_responses(): void
    {
        $b = $this->base;
        foreach (['/context', '/context/clients', '/context/categories', '/settings'] as $p) {
            $this->checked('GET', $b.$p);
        }
        $this->checked('PATCH', $b.'/settings', ['default_item_impact' => 4], $this->headers(null, 'contract-settings-patch'));
        $settings = app(\App\Modules\Risk\Support\RiskSettings::class)->get();
        $this->checked('PUT', $b.'/settings', $settings, $this->headers(null, 'contract-settings-put'));
        $r = $this->checked('POST', $b.'/assessments', ['title' => 'Contract fixture'], ['Idempotency-Key' => 'contract-create']);
        $a = RiskAssessment::findOrFail($r->json('data.id'));
        $this->checked('GET', $b.'/operations/'.$r->json('operation.id'));
        $this->checked('GET', $b.'/assessments');
        $this->checked('GET', $b.'/assessments/'.$a->id);
        $this->checked('GET', $b.'/assessments/'.$a->id.'/context');
        $this->checked('PUT', $b.'/assessments/'.$a->id, ['title' => 'Contract renamed']);
        $this->checked('PATCH', $b.'/assessments/'.$a->id, ['description' => null]);
        $r = $this->checked('POST', $b.'/assessments/'.$a->id.'/items', ['title' => 'Contract item', 'status' => 'accepted', 'next_review_at' => null]);
        $i = RiskItem::findOrFail($r->json('data.id'));
        $this->assertNull($i->next_review_at);
        $this->checked('GET', $b.'/assessments/'.$a->id.'/items');
        $this->checked('GET', $b.'/items/'.$i->id);
        $this->checked('GET', $b.'/items/'.$i->id.'/context');
        $this->checked('PUT', $b.'/items/'.$i->id, ['title' => 'Item renamed']);
        $this->checked('PATCH', $b.'/items/'.$i->id, ['conclusion' => null, 'next_review_at' => '2027-01-15']);
        $r = $this->checked('POST', $b.'/items/'.$i->id.'/updates', ['note' => 'Reviewed', 'status' => 'accepted', 'next_review_at' => null]);
        $this->assertNull($i->fresh()->next_review_at);
        $u = RiskItemUpdate::findOrFail($r->json('data.id'));
        $this->checked('GET', $b.'/updates/'.$u->id);
        $this->checked('GET', $b.'/items/'.$i->id.'/updates');
        $this->checked('DELETE', $b.'/updates/'.$u->id, [], $this->headers($u, 'contract-delete-history'));
        $this->checked('POST', $b.'/assessments/'.$a->id.'/approve', [], $this->headers($a, 'contract-approve'));
        $this->checked('GET', $b.'/assessments/'.$a->id.'/pdf');
        $this->getJson($b.'/assessments/'.$a->id.'/pdf?template=https://example.invalid')->assertUnprocessable();
        $this->checked('DELETE', $b.'/items/'.$i->id, [], $this->headers($i, 'contract-delete-item'));
        $this->checked('DELETE', $b.'/assessments/'.$a->id, [], $this->headers($a, 'contract-delete-assessment'));
    }

    #[Test]
    public function real_tokens_cannot_read_each_others_command_receipts(): void
    {
        $this->app['auth']->forgetGuards();
        $one = $this->root->createToken('fixture-one', ['risk.create', 'risk.operations.read']);
        $two = $this->root->createToken('fixture-two', ['risk.create', 'risk.operations.read']);
        $this->app['auth']->forgetGuards();
        $r = $this->withToken($one->plainTextToken)->postJson($this->base.'/assessments', ['title' => 'Bound to token'], ['Idempotency-Key' => 'token-bound-command'])->assertCreated();
        $id = $r->json('operation.id');
        $this->app['auth']->forgetGuards();
        $this->withToken($two->plainTextToken)->getJson($this->base.'/operations/'.$id)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($one->plainTextToken)->getJson($this->base.'/operations/'.$id)->assertOk();
        $one->accessToken->update(['abilities' => ['risk.operations.read']]);
        $this->app['auth']->forgetGuards();
        $this->withToken($one->plainTextToken)->getJson($this->base.'/operations/'.$id)->assertForbidden();
    }

    #[Test]
    public function browser_permission_distinctions_and_owned_history_delete_work_for_technicians(): void
    {
        $a = $this->assessment();
        $i = $this->item($a);
        $this->tech->givePermissionTo('risk.view');
        $this->login($this->tech, ['risk.update', 'risk.delete']);
        $r = $this->postJson($this->base.'/items/'.$i->id.'/updates', ['note' => 'Technician review', 'status' => 'mitigated'])->assertCreated();
        $u = RiskItemUpdate::findOrFail($r->json('data.id'));
        $this->deleteJson($this->base.'/updates/'.$u->id, [], $this->headers($u, 'owned-history-delete'))->assertForbidden();
        $this->tech->givePermissionTo('risk.delete');
        $this->login($this->tech, ['risk.update', 'risk.delete']);
        $this->deleteJson($this->base.'/updates/'.$u->id, [], $this->headers($u, 'owned-history-delete'))->assertOk()->assertJsonPath('data.item.status', 'open');
        $this->tech->givePermissionTo('risk.update');
        $this->login($this->tech, ['risk.create']);
        $this->postJson($this->base.'/assessments/'.$a->id.'/items', ['title' => 'Permitted by item-store browser right'])->assertCreated();
        $this->postJson($this->base.'/assessments', ['title' => 'Missing assessment-create right'])->assertForbidden();
    }
}
