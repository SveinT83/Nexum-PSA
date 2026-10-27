<?php

namespace App\Modules\Intake\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Intake\Actions\EnsureIntakeDefaults;
use App\Modules\Intake\Models\IntakeApiCommand;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Intake\Models\IntakeSubmissionAttachment;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntakeApiParityTest extends TestCase
{
    use IntakeApiContractAssertions;
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        // Concurrent domain suites must not clean this suite's fake uploads.
        \Illuminate\Support\Facades\ParallelTesting::resolveTokenUsing(fn () => 'intake-'.getmypid());
        $this->seed(PermissionSeeder::class);
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo(['system.view', 'intake.view', 'intake.manage', 'intake.submission_review',
            'client.view', 'contact.view', 'ticket.view', 'task.view', 'sales.view']);
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
    }

    private function token(array $scopes = ['*'], ?User $actor = null): string
    {
        $key = ($actor ?: $this->actor)->createToken('Intake API test', $scopes)->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$key);

        return $key;
    }

    private function formData(array $overrides = []): array
    {
        return array_replace(['name' => 'API inquiry', 'slug' => 'api-inquiry', 'status' => 'published',
            'target_type' => 'review_only', 'routing_mode' => 'manual_review', 'scope_type' => 'global',
            'auto_create_client' => false, 'auto_create_contact' => false, 'max_files' => 5,
            'max_file_size_kb' => 20480, 'fields' => [
                ['key' => 'message', 'label' => 'Message', 'field_type' => 'textarea', 'maps_to' => 'message',
                    'is_required' => true, 'is_active' => true, 'layout_width' => 6],
            ]], $overrides);
    }

    private function submission(?IntakeForm $form = null): IntakeSubmission
    {
        return IntakeSubmission::create(['intake_form_id' => ($form ?: app(EnsureIntakeDefaults::class)->handle())->id,
            'status' => 'new', 'raw_payload' => ['fields' => ['message' => 'Help']],
            'normalized_payload' => ['subject' => 'API help', 'message' => 'Help'],
            'submitted_at' => now()]);
    }

    private function headers(?IntakeForm $form = null, ?IntakeSubmission $submission = null, ?string $key = null): array
    {
        $headers = ['Idempotency-Key' => $key ?: (string) Str::uuid()];
        $record = $form ?: $submission;
        if ($record) {
            $headers['If-Match'] = \App\Modules\Intake\Support\IntakeApiInput::revision($record);
        }

        return $headers;
    }

    #[Test]
    public function discovery_is_read_only_and_native_rights_and_scopes_are_independent(): void
    {
        $this->getJson('/api/v1/intake/context')->assertUnauthorized();
        $this->token(['intake.read']);
        $this->getJson('/api/v1/intake/context')->assertOk()->assertJsonPath('data.form_count', 0);
        $this->getJson('/api/v1/intake/forms')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('intake_forms', 0);
        $this->getJson('/api/v1/intake/forms/context')->assertForbidden();
        $outsider = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $outsider->givePermissionTo('system.view');
        $this->token(['*'], $outsider);
        $this->getJson('/api/v1/intake/context')->assertForbidden();
    }

    #[Test]
    public function narrow_manager_can_use_context_create_and_exact_readback_without_workspace_grant(): void
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->givePermissionTo('intake.manage');
        $this->token(['intake.forms.write'], $manager);
        $this->getJson('/api/v1/intake/forms/context')->assertOk()->assertJsonCount(10, 'data.field_types');
        foreach (['owners', 'clients', 'services'] as $kind) {
            $this->getJson('/api/v1/intake/lookups/'.$kind)->assertOk()->assertJsonStructure(['data', 'meta']);
        }
        $created = $this->postJson('/api/v1/intake/forms', $this->formData(), $this->headers())->assertCreated();
        $this->getJson('/api/v1/intake/forms/api-inquiry')->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
        $this->getJson('/api/v1/intake/context')->assertForbidden();
    }

    #[Test]
    public function create_update_toggle_and_receipt_are_idempotent_encrypted_and_revision_guarded(): void
    {
        $this->token();
        $headers = $this->headers();
        $data = $this->formData();
        $first = $this->postJson('/api/v1/intake/forms', $data, $headers)->assertCreated();
        $this->postJson('/api/v1/intake/forms', $data, $headers)->assertCreated()->assertJsonPath('replayed', true)
            ->assertJsonPath('operation_id', $first->json('operation_id'));
        $this->assertDatabaseCount('intake_forms', 1);
        $this->postJson('/api/v1/intake/forms', $this->formData(['name' => 'Different']), $headers)->assertConflict();
        $this->getJson('/api/v1/intake/commands/'.$first->json('operation_id'))->assertCreated()->assertJsonPath('data.name', 'API inquiry');
        $encrypted = \Illuminate\Support\Facades\DB::table('intake_api_commands')->value('result');
        $this->assertStringNotContainsString('API inquiry', $encrypted);
        $form = IntakeForm::firstOrFail();
        $update = $this->formData(['name' => 'Edited', 'fields' => [
            ['id' => $form->fields()->first()->id, 'key' => 'message', 'label' => 'Edited field', 'field_type' => 'textarea',
                'is_active' => true, 'is_required' => true],
        ]]);
        $old = $this->headers($form);
        $this->putJson('/api/v1/intake/forms/'.$form->slug, $update, $old)->assertOk()->assertJsonPath('data.name', 'Edited');
        $this->postJson('/api/v1/intake/forms/'.$form->slug.'/toggle', [], array_replace($old, ['Idempotency-Key' => (string) Str::uuid()]))->assertStatus(412);
        $toggleHeaders = $this->headers($form->fresh());
        $url = '/api/v1/intake/forms/'.$form->slug.'/toggle';
        $this->postJson($url, [], $toggleHeaders)->assertOk()->assertJsonPath('data.status', 'paused');
        $this->postJson($url, [], $toggleHeaders)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.status', 'paused');
        $this->assertSame('paused', $form->fresh()->status);
        $this->postJson($url, [])->assertStatus(428);
        $this->postJson($url, [], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(428);
    }

    #[Test]
    public function form_updates_preserve_unrelated_metadata_and_reject_foreign_fields_and_unknown_keys(): void
    {
        $this->token();
        $form = app(EnsureIntakeDefaults::class)->handle();
        $form->update(['metadata' => ['extension' => ['keep' => true]]]);
        $foreign = IntakeForm::create(['name' => 'Other', 'slug' => 'other', 'status' => 'draft', 'target_type' => 'review_only']);
        $field = $foreign->fields()->create(['key' => 'foreign', 'label' => 'Foreign', 'field_type' => 'text', 'is_active' => true]);
        $bad = $this->formData(['fields' => [['id' => $field->id, 'key' => 'x', 'label' => 'X', 'field_type' => 'text', 'is_active' => true]]]);
        $this->putJson('/api/v1/intake/forms/'.$form->slug, $bad, $this->headers($form))->assertUnprocessable();
        $this->putJson('/api/v1/intake/forms/'.$form->slug, $this->formData(), $this->headers($form))->assertOk();
        $this->assertTrue($form->fresh()->metadata['extension']['keep']);
        $this->assertSame('Foreign', $field->fresh()->label);
        $this->postJson('/api/v1/intake/forms', $this->formData(['bogus' => 'x']), $this->headers())->assertUnprocessable();
        $this->getJson('/api/v1/intake/forms?per_page=101')->assertUnprocessable();
    }

    public static function restrictedActions(): array
    {
        return array_map(fn ($action) => [$action], ['outcome', 'link-existing', 'route-ticket', 'route-task', 'route-sales', 'reviewed']);
    }

    #[Test]
    #[DataProvider('restrictedActions')]
    public function view_only_actor_cannot_mutate_via_browser_or_api(string $action): void
    {
        $submission = $this->submission();
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo(['system.view', 'intake.view']);
        $this->token(['*'], $viewer);
        $this->postJson('/api/v1/intake/submissions/'.$submission->id.'/'.$action, [], $this->headers(submission: $submission))->assertForbidden();
        $this->actingAs($viewer)->post(route('tech.admin.system.intake.submissions.'.$action, $submission), [])->assertForbidden();
        $this->assertSame('new', $submission->fresh()->status);
        $this->assertDatabaseCount('intake_submission_events', 0);
    }

    #[Test]
    public function review_outcomes_duplicate_validation_and_receipt_revocation_work(): void
    {
        $this->token();
        $submission = $this->submission();
        $url = '/api/v1/intake/submissions/'.$submission->id;
        $this->getJson($url)->assertOk()->assertHeader('ETag');
        $result = $this->postJson($url.'/reviewed', [], $this->headers(submission: $submission))->assertOk()
            ->assertJsonPath('data.status', 'reviewed');
        $this->getJson($url.'/events')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($url.'/outcome', ['status' => 'duplicate', 'duplicate_of_submission_id' => $submission->id],
            $this->headers(submission: $submission->fresh()))->assertUnprocessable();
        $other = $this->submission();
        $this->postJson($url.'/outcome', ['status' => 'duplicate', 'duplicate_of_submission_id' => $other->id, 'reason' => 'Same request'],
            $this->headers(submission: $submission->fresh()))->assertOk()->assertJsonPath('data.status', 'duplicate');
        $this->actor->revokePermissionTo('intake.submission_review');
        $this->getJson('/api/v1/intake/commands/'.$result->json('operation_id'))->assertForbidden();
    }

    #[Test]
    public function client_link_requires_current_related_domain_access(): void
    {
        $this->token();
        $submission = $this->submission();
        $client = Client::factory()->create();
        $url = '/api/v1/intake/submissions/'.$submission->id.'/link-existing';
        $this->actor->revokePermissionTo('client.view');
        $this->postJson($url, ['target_type' => 'client', 'reference' => (string) $client->id],
            $this->headers(submission: $submission))->assertForbidden();
        $this->actor->givePermissionTo('client.view');
        $this->postJson($url, ['target_type' => 'client', 'reference' => (string) $client->id],
            $this->headers(submission: $submission))->assertOk()->assertJsonPath('data.matched_client_id', $client->id);
        $this->assertSame($client->id, $submission->fresh()->matched_client_id);
        $this->getJson('/api/v1/intake/lookups/link-clients?q='.$client->name)->assertOk();
    }

    public static function routingTargets(): array
    {
        return [['route-ticket', \App\Modules\Ticket\Models\Ticket::class],
            ['route-task', \App\Modules\Task\Models\Task::class],
            ['route-sales', \App\Modules\Sales\Models\SalesOpportunity::class]];
    }

    #[Test]
    #[DataProvider('routingTargets')]
    public function routing_uses_native_target_and_replay_never_creates_a_second_target(string $action, string $target): void
    {
        $this->token();
        $submission = $this->submission();
        $submission->update(['matched_client_id' => Client::factory()->create()->id]);
        $headers = $this->headers(submission: $submission);
        $url = '/api/v1/intake/submissions/'.$submission->id.'/'.$action;
        $first = $this->postJson($url, [], $headers)->assertOk()->assertJsonPath('data.status', 'routed');
        $this->postJson($url, [], $headers)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($target, $submission->fresh()->target_type);
        $this->assertDatabaseCount($submission->fresh()->target->getTable(), 1);
        $this->assertDatabaseCount('intake_submission_attachments', 0);
        $this->getJson('/api/v1/intake/commands/'.$first->json('operation_id'))->assertOk();
        $this->postJson('/api/v1/intake/submissions/'.$submission->id.'/route-task', [],
            $this->headers(submission: $submission->fresh()))->assertOk();
        $this->assertSame($target, $submission->fresh()->target_type);
    }

    #[Test]
    public function public_schema_acceptance_upload_replay_and_honeypot_never_expose_internal_data(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $this->getJson('/api/v1/public/intake/forms/'.$form->slug)->assertOk()
            ->assertJsonMissingPath('data.owner_id')->assertJsonMissingPath('data.scope_client_id')
            ->assertJsonMissingPath('data.fields.0.maps_to');
        $fields = ['company_name' => 'Public AS', 'contact_name' => 'Public Person',
            'contact_email' => 'public@example.test', 'subject' => 'API', 'message' => 'Message', 'consent' => true];
        $url = '/api/v1/public/intake/forms/'.$form->slug.'/submissions';
        $headers = $this->headers();
        $normal = $this->postJson($url, ['fields' => $fields], $headers)->assertAccepted()
            ->assertExactJson(['accepted' => true, 'message' => $form->success_message]);
        $this->postJson($url, ['fields' => $fields], $headers)->assertAccepted();
        $this->assertDatabaseCount('intake_submissions', 1);
        $this->assertDatabaseCount('signals', 1);
        $this->postJson($url, ['fields' => ['message' => 'Changed']], $headers)->assertConflict();
        $this->postJson($url, ['fields' => [], 'intake_website' => 'robot'], $this->headers())->assertAccepted()
            ->assertExactJson($normal->json());
        $this->assertDatabaseCount('signals', 1);
        $this->assertSame('spam', IntakeSubmission::latest('id')->first()->status);
        $this->postJson($url, ['fields' => []], $this->headers())->assertUnprocessable();
        $form->update(['status' => 'paused']);
        $this->getJson('/api/v1/public/intake/forms/'.$form->slug)->assertNotFound();
        $this->postJson($url, ['fields' => $fields], $this->headers())->assertNotFound();
    }

    #[Test]
    public function public_multipart_files_stay_private_and_download_checks_exact_submission_parent(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $field = $form->fields()->create(['key' => 'upload', 'label' => 'File', 'field_type' => 'file',
            'is_active' => true, 'is_required' => false, 'allowed_mime_types' => ['application/pdf']]);
        $url = '/api/v1/public/intake/forms/'.$form->slug.'/submissions';
        $fields = ['company_name' => 'Files AS', 'contact_name' => 'Files', 'contact_email' => 'files@example.test',
            'subject' => 'Files', 'message' => 'Files', 'consent' => true];
        $headers = $this->headers();
        $payload = ['fields' => $fields, 'files' => ['upload' => [UploadedFile::fake()->create('example.pdf', 8, 'application/pdf')]]];
        $this->post($url, $payload, $headers)->assertAccepted();
        $file = IntakeSubmissionAttachment::firstOrFail();
        Storage::disk($file->disk)->assertExists($file->path);
        $this->token();
        $parent = '/api/v1/intake/submissions/'.$file->intake_submission_id.'/attachments';
        $this->getJson($parent)->assertOk()->assertJsonMissingPath('data.0.path')->assertJsonMissingPath('data.0.disk');
        $this->get($parent.'/'.$file->id)->assertOk()->assertDownload('example.pdf');
        $other = $this->submission($form);
        $this->get('/api/v1/intake/submissions/'.$other->id.'/attachments/'.$file->id)->assertNotFound();
        $this->assertDatabaseCount('intake_submission_attachments', 1);
    }

    #[Test]
    public function previews_do_not_write_and_stale_preview_revisions_cannot_execute(): void
    {
        $this->token();
        $submission = $this->submission();
        $before = IntakeSubmission::count();
        $preview = $this->postJson('/api/v1/intake/previews', ['operation' => 'route-ticket', 'submission_id' => $submission->id])
            ->assertOk()->assertJsonPath('data.valid', true);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertSame($before, IntakeSubmission::count());
        $this->assertDatabaseCount('intake_api_commands', 0);
        $submission->update(['status' => 'archived']);
        $this->postJson('/api/v1/intake/submissions/'.$submission->id.'/route-ticket', [], [
            'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => $preview->json('data.revision'),
        ])->assertStatus(412);
    }

    #[Test]
    public function receipts_are_bound_to_exact_actor_token_and_inactive_or_revoked_identity_is_denied(): void
    {
        $this->token();
        $result = $this->postJson('/api/v1/intake/forms', $this->formData(), $this->headers())->assertCreated();
        $url = '/api/v1/intake/commands/'.$result->json('operation_id');
        $this->token();
        $this->getJson($url)->assertNotFound();
        $this->actor->update(['status' => 'inactive']);
        $this->getJson('/api/v1/intake/context')->assertForbidden();
    }

    #[Test]
    public function interrupted_claim_is_never_reexecuted(): void
    {
        $this->token();
        $result = $this->postJson('/api/v1/intake/forms', $this->formData(), $this->headers())->assertCreated();
        $command = IntakeApiCommand::findOrFail($result->json('operation_id'));
        $command->update(['state' => 'running']);
        $this->getJson('/api/v1/intake/commands/'.$command->id)->assertAccepted()
            ->assertJsonPath('state', 'unknown')->assertJsonPath('reconciliation_required', true);
        $this->assertDatabaseCount('intake_forms', 1);
    }

    #[Test]
    public function local_sales_client_creation_uses_clients_authority_without_inferred_invoice_email(): void
    {
        $this->token();
        $form = app(EnsureIntakeDefaults::class)->handle();
        $form->update(['auto_create_client' => true, 'auto_create_contact' => false]);
        $submission = $this->submission($form);
        $submission->update(['normalized_payload' => ['company_name' => 'New Intake Client', 'contact_email' => 'person@example.test', 'subject' => 'New lead']]);
        $this->postJson('/api/v1/intake/submissions/'.$submission->id.'/route-sales', [],
            $this->headers(submission: $submission))->assertOk()->assertJsonPath('state', 'completed');
        $client = Client::where('name', 'New Intake Client')->firstOrFail();
        $this->assertNull($client->billing_email);
        $this->assertNotEmpty($client->client_number);
        $this->assertNotEmpty($submission->fresh()->routing_result['client_id']);
    }

    #[Test]
    public function queue_filters_and_all_link_lookups_are_typed_and_read_only(): void
    {
        $this->token();
        $submission = $this->submission();
        $this->getJson('/api/v1/intake/submissions?status=new&form_id='.$submission->intake_form_id)
            ->assertOk()->assertJsonCount(1, 'data');
        foreach (['link-clients', 'link-contacts', 'link-tickets', 'link-tasks', 'link-sales'] as $kind) {
            $this->getJson('/api/v1/intake/lookups/'.$kind)->assertOk();
        }
        $this->assertDatabaseCount('intake_submission_events', 0);
    }

    public static function everyFieldType(): array
    {
        return array_map(fn ($type) => [$type], \App\Modules\Intake\Models\IntakeFormField::FIELD_TYPES);
    }

    #[Test]
    #[DataProvider('everyFieldType')]
    public function every_field_type_can_be_configured_and_read_back(string $type): void
    {
        $this->token();
        $payload = $this->formData(['fields' => [[
            'key' => 'typed', 'label' => 'Typed field', 'field_type' => $type,
            'is_active' => true, 'is_required' => false, 'layout_width' => 4,
            'options_text' => "First\nSecond",
        ]]]);
        $this->postJson('/api/v1/intake/forms', $payload, $this->headers())->assertCreated()
            ->assertJsonPath('data.fields.0.field_type', $type)->assertJsonPath('data.fields.0.layout_width', 4);
        $this->getJson('/api/v1/public/intake/forms/api-inquiry')->assertOk()
            ->assertJsonPath('data.fields.0.field_type', $type);
    }

    #[Test]
    public function missing_scope_deleted_token_and_expired_token_cannot_use_a_cached_actor(): void
    {
        $key = $this->token(['intake.read']);
        $this->getJson('/api/v1/intake/context')->assertOk();
        $this->actor->tokens()->delete();
        $this->getJson('/api/v1/intake/context')->assertUnauthorized();
        $this->token(['intake.read']);
        $this->actor->tokens()->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/intake/context')->assertUnauthorized();
    }

    #[Test]
    public function new_api_does_not_activate_provider_customer_creation(): void
    {
        $this->token();
        $form = app(EnsureIntakeDefaults::class)->handle();
        $form->update(['auto_create_client' => true]);
        $submission = $this->submission($form);
        $authority = \Mockery::mock(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class);
        $authority->shouldReceive('connection')->andReturn(new \App\Models\System\Integrations\Integration);
        $authority->shouldNotReceive('create');
        $this->app->instance(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class, $authority);
        $this->postJson('/api/v1/intake/submissions/'.$submission->id.'/route-sales', [],
            $this->headers(submission: $submission))->assertOk()->assertJsonPath('state', 'skipped')
            ->assertJsonPath('data.routing_result.reason', 'api_provider_customer_creation_disabled');
        $this->assertDatabaseCount('clients', 0);
    }

    #[Test]
    public function public_conditional_hidden_values_and_files_are_not_persisted_or_routed(): void
    {
        $this->token();
        $this->postJson('/api/v1/intake/forms', $this->formData(['fields' => [
            ['key' => 'choice', 'label' => 'Choice', 'field_type' => 'select', 'is_active' => true,
                'options_text' => "Yes\nNo"],
            ['key' => 'secret', 'label' => 'Secret', 'field_type' => 'text', 'is_active' => true,
                'is_required' => true, 'maps_to' => 'message', 'visibility_mode' => 'conditional',
                'visibility_rules' => [['source_key' => 'choice', 'operator' => 'equals', 'value' => 'Yes']]],
        ]]), $this->headers())->assertCreated();
        $this->postJson('/api/v1/public/intake/forms/api-inquiry/submissions',
            ['fields' => ['choice' => 'No', 'secret' => 'Must be discarded']], $this->headers())->assertAccepted();
        $submission = IntakeSubmission::firstOrFail();
        $this->assertArrayNotHasKey('secret', $submission->raw_payload['fields']);
        $this->assertArrayNotHasKey('message', $submission->normalized_payload);
        $this->assertDatabaseCount('intake_submission_attachments', 0);
    }

    #[Test]
    public function html_and_json_submission_share_the_same_rate_budget(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $key = 'intake:public:submit:'.hash('sha256', '127.0.0.1');
        \Illuminate\Support\Facades\RateLimiter::clear($key);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/public/intake/forms/'.$form->slug.'/submissions',
                ['fields' => [], 'intake_website' => 'robot'], $this->headers())->assertAccepted();
        }
        $this->post(route('intake.forms.submit', $form), ['intake_website' => 'robot'])->assertStatus(429);
        $this->assertDatabaseCount('intake_submissions', 10);
    }

    public static function finalOutcomes(): array
    {
        return [['spam'], ['duplicate'], ['rejected'], ['archived']];
    }

    #[Test]
    #[DataProvider('finalOutcomes')]
    public function every_review_outcome_persists_audited_state_and_blocks_new_routing(string $status): void
    {
        $this->token();
        $submission = $this->submission();
        $this->postJson('/api/v1/intake/submissions/'.$submission->id.'/outcome',
            ['status' => $status, 'reason' => 'Reviewed test request'], $this->headers(submission: $submission))
            ->assertOk()->assertJsonPath('data.status', $status);
        $this->postJson('/api/v1/intake/submissions/'.$submission->id.'/route-ticket', [],
            $this->headers(submission: $submission->fresh()))->assertOk()->assertJsonPath('state', 'skipped');
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseHas('intake_submission_events', ['intake_submission_id' => $submission->id, 'type' => 'marked_'.$status]);
    }

    public static function remainingLinkTypes(): array
    {
        return [['contact'], ['ticket'], ['task'], ['sales_opportunity']];
    }

    #[Test]
    #[DataProvider('remainingLinkTypes')]
    public function existing_contact_and_target_links_use_exact_native_references(string $kind): void
    {
        $this->token();
        $client = Client::factory()->create();
        $source = $this->submission();
        $source->update(['matched_client_id' => $client->id]);
        if ($kind === 'contact') {
            $target = app(\App\Modules\Contact\Actions\StoreContact::class)->handle([
                'display_name' => 'Link test', 'email' => 'link@example.test', 'client_id' => $client->id,
            ]);
        } else {
            $action = match ($kind) {
                'ticket' => 'route-ticket', 'task' => 'route-task', default => 'route-sales'
            };
            $this->postJson('/api/v1/intake/submissions/'.$source->id.'/'.$action, [],
                $this->headers(submission: $source))->assertOk();
            $target = $source->fresh()->target;
        }
        $destination = $this->submission();
        $this->postJson('/api/v1/intake/submissions/'.$destination->id.'/link-existing',
            ['target_type' => $kind, 'reference' => (string) $target->id], $this->headers(submission: $destination))->assertOk();
        if ($kind === 'contact') {
            $this->assertSame($target->id, $destination->fresh()->matched_contact_id);
        } else {
            $this->assertSame($target->id, $destination->fresh()->target_id);
            $this->assertSame($target::class, $destination->fresh()->target_type);
        }
    }

    #[Test]
    public function uncertain_provider_customer_creation_has_one_stable_key_and_requires_reconciliation(): void
    {
        $this->token();
        config(['intake.api_provider_customer_creation_enabled' => true]);
        $form = app(EnsureIntakeDefaults::class)->handle();
        $form->update(['auto_create_client' => true]);
        $submission = $this->submission($form);
        $authority = \Mockery::mock(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class);
        $authority->shouldReceive('connection')->andReturn(new \App\Models\System\Integrations\Integration);
        $this->app->instance(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class, $authority);
        $create = \Mockery::mock(\App\Modules\Clients\Actions\CreateClientRecord::class);
        $seenKey = null;
        $create->shouldReceive('handle')->once()->withArgs(function ($attributes, $callback) use (&$seenKey) {
            $seenKey = $attributes['tripletex_request_key'];

            return $attributes['tripletex_number_mode'] === true
                && ! array_key_exists('client_number', $attributes) && $attributes['billing_email'] === null;
        })->andThrow(\Illuminate\Validation\ValidationException::withMessages(['client_number' => 'Reconcile provider attempt.']));
        $this->app->instance(\App\Modules\Clients\Actions\CreateClientRecord::class, $create);
        $headers = $this->headers(submission: $submission);
        $url = '/api/v1/intake/submissions/'.$submission->id.'/route-sales';
        $this->postJson($url, [], $headers)->assertAccepted()->assertJsonPath('state', 'unknown');
        $this->postJson($url, [], $headers)->assertAccepted()->assertJsonPath('replayed', true);
        $this->assertTrue(Str::isUuid($seenKey));
        $this->assertSame($seenKey, $submission->fresh()->routing_result['client_request_key']);
        $this->assertDatabaseCount('clients', 0);
    }

    #[Test]
    public function failed_public_storage_is_never_acknowledged_as_received_or_reexecuted(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $store = \Mockery::mock(\App\Modules\Intake\Actions\StoreIntakeSubmission::class);
        $store->shouldReceive('validateRequest')->once();
        $store->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Simulated storage failure.'));
        $this->app->instance(\App\Modules\Intake\Actions\StoreIntakeSubmission::class, $store);
        $url = '/api/v1/public/intake/forms/'.$form->slug.'/submissions';
        $headers = $this->headers();
        $first = $this->postJson($url, ['fields' => []], $headers)->assertStatus(503)->assertJsonMissingPath('accepted');
        $this->postJson($url, ['fields' => []], $headers)->assertStatus(503)->assertExactJson($first->json());
        $this->assertDatabaseCount('intake_submissions', 0);
    }

    #[Test]
    public function a_form_policy_change_invalidates_a_submission_preview_revision(): void
    {
        $this->token();
        $submission = $this->submission();
        $headers = $this->headers(submission: $submission);
        $submission->form->update(['auto_create_client' => true]);
        $this->postJson('/api/v1/intake/submissions/'.$submission->id.'/route-sales', [], $headers)->assertStatus(412);
        $this->assertDatabaseCount('clients', 0);
    }

    #[Test]
    public function signal_failure_preserves_receipt_and_does_not_repeat_submission_on_retry(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $signal = \Mockery::mock(\App\Modules\Intake\Actions\RecordIntakeSubmissionSignal::class);
        $signal->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Simulated Signal failure.'));
        $this->app->instance(\App\Modules\Intake\Actions\RecordIntakeSubmissionSignal::class, $signal);
        $data = ['fields' => ['company_name' => 'Signal test', 'contact_name' => 'Signal',
            'contact_email' => 'signal@example.test', 'subject' => 'Signal', 'message' => 'Signal', 'consent' => true]];
        $headers = $this->headers();
        $url = '/api/v1/public/intake/forms/'.$form->slug.'/submissions';
        $this->postJson($url, $data, $headers)->assertAccepted();
        $this->postJson($url, $data, $headers)->assertAccepted();
        $this->assertDatabaseCount('intake_submissions', 1);
        $this->assertDatabaseHas('intake_submission_events', ['type' => 'signal_recording_failed']);
        $this->assertDatabaseCount('signals', 0);
    }
}
