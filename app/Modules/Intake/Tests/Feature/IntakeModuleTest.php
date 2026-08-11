<?php

namespace App\Modules\Intake\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Intake\Actions\EnsureIntakeDefaults;
use App\Modules\Intake\Controllers\Admin\IntakeController as AdminIntakeController;
use App\Modules\Intake\Controllers\Public\IntakeFormController as PublicIntakeFormController;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeFormField;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Intake\Models\IntakeSubmissionAttachment;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Signal\Models\Signal;
use App\Modules\Task\Models\Task;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntakeModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->givePermissionTo([
            'system.view',
            'intake.view',
            'intake.manage',
            'intake.submission_review',
            'sales.view',
        ]);
    }

    #[Test]
    public function intake_routes_are_owned_by_intake_module(): void
    {
        $this->assertSame(
            PublicIntakeFormController::class.'@show',
            Route::getRoutes()->getByName('intake.forms.show')->getActionName(),
        );
        $this->assertSame(
            AdminIntakeController::class.'@index',
            Route::getRoutes()->getByName('tech.admin.system.intake.index')->getActionName(),
        );
    }

    #[Test]
    public function new_intake_form_starts_with_no_visible_field_rows(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.intake.forms.create'))
            ->assertOk()
            ->assertSee('data-bs-target="#intake-form-settings-panel"', false)
            ->assertSee('id="intake-form-settings-panel" class="collapse show"', false)
            ->assertSee('aria-label="New field"', false)
            ->assertSee('data-intake-field-add-row', false)
            ->assertSee('bi-plus-lg', false)
            ->assertSee('data-intake-field-empty', false)
            ->assertDontSee('name="fields[0][label]"', false);
    }

    #[Test]
    public function existing_intake_form_field_rows_start_collapsed(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.intake.forms.edit', $form))
            ->assertOk()
            ->assertSee('id="intake-form-settings-panel"', false)
            ->assertDontSee('id="intake-form-settings-panel" class="collapse show"', false)
            ->assertSee('data-toggle-intake-field', false)
            ->assertSee('data-intake-drag-handle', false)
            ->assertSee('data-intake-layout-width', false)
            ->assertDontSee('data-intake-layout-summary', false)
            ->assertSee('data-intake-required-summary', false)
            ->assertSee('data-layout-width="12"', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('data-intake-field-panel', false)
            ->assertSee('class="p-3 d-none"', false)
            ->assertSee('form-switch', false)
            ->assertSee('data-intake-submit-row', false)
            ->assertSee('data-toggle-intake-submit-row', false)
            ->assertSee('data-intake-submit-panel', false)
            ->assertSee('id="intake_submit_panel" class="p-3 d-none"', false)
            ->assertDontSee('data-toggle-intake-submit-settings', false)
            ->assertSee('Visible on form');
    }

    #[Test]
    public function admin_can_store_select_field_options_without_file_settings_noise(): void
    {
        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.intake.forms.store'), [
                'name' => 'New technician onboarding',
                'slug' => 'new-technician-onboarding',
                'description' => null,
                'status' => IntakeForm::STATUS_DRAFT,
                'success_message' => null,
                'target_type' => IntakeForm::TARGET_REVIEW_ONLY,
                'owner_id' => null,
                'spam_honeypot_field' => 'intake_website',
                'max_files' => 5,
                'max_file_size_kb' => 20480,
                'allowed_mime_types_text' => "application/pdf\nimage/png",
                'fields' => [
                    [
                        'label' => 'Department',
                        'key' => 'department',
                        'field_type' => IntakeFormField::TYPE_SELECT,
                        'maps_to' => '',
                        'options_text' => "Technicians\nSales\nOffice\nManagement",
                        'layout_width' => 6,
                        'is_active' => '1',
                    ],
                    [
                        'label' => 'Phone',
                        'key' => 'phone',
                        'field_type' => IntakeFormField::TYPE_PHONE,
                        'maps_to' => IntakeFormField::MAP_CONTACT_PHONE,
                        'options_text' => "Should\nNot\nPersist",
                        'max_files' => 9,
                        'max_file_size_kb' => 999,
                        'allowed_mime_types_text' => 'text/plain',
                        'layout_width' => 999,
                        'is_active' => '1',
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $form = IntakeForm::query()->where('slug', 'new-technician-onboarding')->firstOrFail();
        $department = $form->fields()->where('key', 'department')->firstOrFail();
        $phone = $form->fields()->where('key', 'phone')->firstOrFail();

        $this->assertSame([
            ['label' => 'Technicians', 'value' => 'Technicians'],
            ['label' => 'Sales', 'value' => 'Sales'],
            ['label' => 'Office', 'value' => 'Office'],
            ['label' => 'Management', 'value' => 'Management'],
        ], $department->options);
        $this->assertNull($department->max_files);
        $this->assertNull($phone->options);
        $this->assertNull($phone->max_files);
        $this->assertNull($phone->max_file_size_kb);
        $this->assertNull($phone->allowed_mime_types);
        $this->assertSame(6, data_get($department->metadata, 'layout.width'));
        $this->assertSame(12, data_get($phone->metadata, 'layout.width'));
        $this->assertSame(0, $department->sort_order);
        $this->assertSame(10, $phone->sort_order);
    }

    #[Test]
    public function public_form_renders_saved_field_layout_widths(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $form->fields()->where('key', 'company_name')->firstOrFail()
            ->forceFill(['metadata' => ['layout' => ['width' => 6]]])
            ->save();
        $form->fields()->where('key', 'contact_name')->firstOrFail()
            ->forceFill(['metadata' => ['layout' => ['width' => 6]]])
            ->save();

        $response = $this->get(route('intake.forms.show', $form))
            ->assertOk();

        $this->assertSame(2, substr_count($response->getContent(), 'class="col-12 col-md-6"'));
    }

    #[Test]
    public function admin_can_store_conditional_visibility_and_submit_button_text(): void
    {
        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.intake.forms.store'), [
                'name' => 'Conditional onboarding',
                'slug' => 'conditional-onboarding',
                'description' => null,
                'status' => IntakeForm::STATUS_ACTIVE,
                'success_message' => null,
                'submit_button_label' => 'Send onboarding',
                'target_type' => IntakeForm::TARGET_REVIEW_ONLY,
                'owner_id' => null,
                'spam_honeypot_field' => 'intake_website',
                'max_files' => 5,
                'max_file_size_kb' => 20480,
                'allowed_mime_types_text' => "application/pdf\nimage/png",
                'fields' => [
                    [
                        'label' => 'Customer type',
                        'key' => 'customer_type',
                        'field_type' => IntakeFormField::TYPE_SELECT,
                        'options_text' => "Private\nBusiness",
                        'is_active' => '1',
                    ],
                    [
                        'label' => 'Organization number',
                        'key' => 'org_number',
                        'field_type' => IntakeFormField::TYPE_TEXT,
                        'maps_to' => IntakeFormField::MAP_ORG_NO,
                        'is_required' => '1',
                        'is_active' => '1',
                        'visibility_mode' => IntakeFormField::VISIBILITY_MODE_CONDITIONAL,
                        'visibility_match' => IntakeFormField::VISIBILITY_MATCH_ALL,
                        'visibility_rules' => [
                            [
                                'source_key' => 'customer_type',
                                'operator' => IntakeFormField::VISIBILITY_OPERATOR_EQUALS,
                                'value' => 'Business',
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $form = IntakeForm::query()->where('slug', 'conditional-onboarding')->firstOrFail();
        $organizationNumber = $form->fields()->where('key', 'org_number')->firstOrFail();

        $this->assertSame('Send onboarding', data_get($form->metadata, 'submit_button_label'));
        $this->assertSame(IntakeFormField::VISIBILITY_MODE_CONDITIONAL, data_get($organizationNumber->metadata, 'visibility.mode'));
        $this->assertSame('customer_type', data_get($organizationNumber->metadata, 'visibility.rules.0.source_key'));
        $this->assertSame(IntakeFormField::VISIBILITY_OPERATOR_EQUALS, data_get($organizationNumber->metadata, 'visibility.rules.0.operator'));

        $this->get(route('intake.forms.show', $form))
            ->assertOk()
            ->assertSee('Send onboarding')
            ->assertSee('data-intake-field-visibility', false);
    }

    #[Test]
    public function hidden_required_conditional_fields_are_not_required_or_mapped(): void
    {
        $form = $this->conditionalBusinessForm();

        $this->post(route('intake.forms.submit', $form), [
            'fields' => [
                'customer_type' => 'Private',
                'org_number' => '999999999',
            ],
        ])->assertRedirect(route('intake.forms.thanks', $form));

        $submission = IntakeSubmission::query()->firstOrFail();

        $this->assertSame(['customer_type' => 'Private'], $submission->raw_payload['fields']);
        $this->assertArrayNotHasKey('org_no', $submission->normalized_payload);
    }

    #[Test]
    public function visible_required_conditional_fields_are_required(): void
    {
        $form = $this->conditionalBusinessForm();

        $this->post(route('intake.forms.submit', $form), [
            'fields' => [
                'customer_type' => 'Business',
            ],
        ])->assertSessionHasErrors('fields.org_number');

        $this->post(route('intake.forms.submit', $form), [
            'fields' => [
                'customer_type' => 'Business',
                'org_number' => '123456789',
            ],
        ])->assertRedirect(route('intake.forms.thanks', $form));

        $submission = IntakeSubmission::query()->firstOrFail();

        $this->assertSame('123456789', $submission->normalized_payload['org_no']);
    }

    #[Test]
    public function text_contains_visibility_rules_match_substrings(): void
    {
        $form = $this->conditionalEmailContainsForm();

        $this->post(route('intake.forms.submit', $form), [
            'fields' => [
                'contact_email' => 'admin@tdpsa.com',
            ],
        ])->assertSessionHasErrors('fields.contact_phone');

        $this->post(route('intake.forms.submit', $form), [
            'fields' => [
                'contact_email' => 'admin@tdpsa.com',
                'contact_phone' => '+47 123 45 678',
            ],
        ])->assertRedirect(route('intake.forms.thanks', $form));

        $submission = IntakeSubmission::query()->firstOrFail();

        $this->assertSame('+47 123 45 678', $submission->normalized_payload['contact_phone']);
    }

    #[Test]
    public function hidden_conditional_file_fields_do_not_store_uploaded_files(): void
    {
        Storage::fake('local');
        $form = $this->conditionalFileForm();

        $this->post(route('intake.forms.submit', $form), [
            'fields' => [
                'attach_files' => 'No',
            ],
            'files' => [
                'attachments' => [
                    UploadedFile::fake()->createWithContent('hidden.txt', 'hidden file'),
                ],
            ],
        ])->assertRedirect(route('intake.forms.thanks', $form));

        $this->assertDatabaseCount('intake_submissions', 1);
        $this->assertDatabaseCount('intake_submission_attachments', 0);
    }

    #[Test]
    public function public_user_can_submit_inquiry_with_file_upload(): void
    {
        Storage::fake('local');

        $form = app(EnsureIntakeDefaults::class)->handle();

        $this->post(route('intake.forms.submit', $form), [
            'fields' => [
                'company_name' => 'Upload Client AS',
                'contact_name' => 'Ada Upload',
                'contact_email' => 'ada.upload@example.test',
                'contact_phone' => '+47 111 22 333',
                'subject' => 'Need onboarding',
                'message' => 'We want help with a new setup.',
                'consent' => '1',
            ],
            'files' => [
                'attachments' => [
                    UploadedFile::fake()->createWithContent('brief.txt', 'project brief'),
                ],
            ],
        ])->assertRedirect(route('intake.forms.thanks', $form));

        $submission = IntakeSubmission::query()->firstOrFail();
        $this->assertSame(IntakeSubmission::STATUS_NEW, $submission->status);
        $this->assertSame('Upload Client AS', $submission->normalized_payload['company_name']);
        $this->assertSame('Need onboarding', $submission->normalized_payload['subject']);

        $attachment = IntakeSubmissionAttachment::query()->firstOrFail();
        $this->assertSame($submission->id, $attachment->intake_submission_id);
        $this->assertSame('brief.txt', $attachment->original_filename);
        Storage::disk('local')->assertExists($attachment->path);

        $signal = Signal::query()->where('source_domain', 'intake')->firstOrFail();
        $this->assertSame('intake_submission_received', $signal->signal_type);
        $this->assertSame($submission->id, $signal->source_id);
        $this->assertSame($form->slug, $signal->payload['intake_form_slug']);
        $this->assertSame('Upload Client AS', $signal->payload['normalized']['company_name']);
        $this->assertSame(1, $signal->payload['attachment_count']);
        $this->assertArrayNotHasKey('path', $signal->payload['attachments'][0]);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.intake.attachments.download', [$submission, $attachment]))
            ->assertOk();
    }

    #[Test]
    public function honeypot_submission_is_recorded_as_spam_without_storing_files(): void
    {
        Storage::fake('local');

        $form = app(EnsureIntakeDefaults::class)->handle();

        $this->post(route('intake.forms.submit', $form), [
            $form->spam_honeypot_field => 'https://spam.example',
            'fields' => [
                'company_name' => 'Spam Client AS',
            ],
            'files' => [
                'attachments' => [
                    UploadedFile::fake()->createWithContent('spam.txt', 'spam'),
                ],
            ],
        ])->assertRedirect(route('intake.forms.thanks', $form));

        $this->assertDatabaseHas('intake_submissions', [
            'status' => IntakeSubmission::STATUS_SPAM,
            'honeypot_value' => 'https://spam.example',
        ]);
        $this->assertDatabaseCount('intake_submission_attachments', 0);
        $this->assertDatabaseCount('signals', 0);
    }

    #[Test]
    public function admin_can_review_submission(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $submission = IntakeSubmission::query()->create([
            'intake_form_id' => $form->id,
            'status' => IntakeSubmission::STATUS_NEW,
            'raw_payload' => ['fields' => ['subject' => 'Review me']],
            'normalized_payload' => ['subject' => 'Review me'],
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.intake.submissions.reviewed', $submission))
            ->assertRedirect();

        $submission->refresh();
        $this->assertSame(IntakeSubmission::STATUS_REVIEWED, $submission->status);
        $this->assertSame($this->admin->id, $submission->reviewed_by);
        $this->assertNotNull($submission->reviewed_at);
    }

    #[Test]
    public function admin_can_store_final_form_scope_and_routing_policy(): void
    {
        $client = Client::factory()->create(['name' => 'Scoped Client AS']);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.intake.forms.store'), [
                'name' => 'Scoped support intake',
                'slug' => 'scoped-support-intake',
                'description' => null,
                'status' => IntakeForm::STATUS_PUBLISHED,
                'success_message' => null,
                'submit_button_label' => 'Send support request',
                'purpose' => 'Support',
                'language' => 'nb',
                'scope_type' => IntakeForm::SCOPE_CLIENT,
                'scope_client_id' => $client->id,
                'target_type' => IntakeForm::TARGET_TICKET,
                'routing_mode' => IntakeForm::ROUTING_MODE_KNOWN_CLIENT_ONLY,
                'owner_id' => $this->admin->id,
                'spam_honeypot_field' => 'intake_website',
                'max_files' => 5,
                'max_file_size_kb' => 20480,
                'allowed_mime_types_text' => "application/pdf\nimage/png",
                'fields' => [
                    [
                        'label' => 'Subject',
                        'key' => 'subject',
                        'field_type' => IntakeFormField::TYPE_TEXT,
                        'maps_to' => IntakeFormField::MAP_SUBJECT,
                        'is_required' => '1',
                        'is_active' => '1',
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $form = IntakeForm::query()->where('slug', 'scoped-support-intake')->firstOrFail();

        $this->assertTrue($form->isActive());
        $this->assertSame(IntakeForm::TARGET_TICKET, $form->target_type);
        $this->assertSame(IntakeForm::ROUTING_MODE_KNOWN_CLIENT_ONLY, $form->routingMode());
        $this->assertSame(IntakeForm::SCOPE_CLIENT, $form->scopeType());
        $this->assertSame($client->id, $form->scopeClientId());
        $this->assertSame('Support', $form->purpose());
        $this->assertSame('nb', $form->language());
        $this->assertSame('Send support request', $form->submitButtonLabel());
    }

    #[Test]
    public function public_submission_stores_form_snapshot_and_uses_scoped_client_match(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $client = Client::factory()->create(['name' => 'Scoped Snapshot AS']);
        ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Default site', 'is_default' => true]);
        $form->forceFill([
            'metadata' => array_replace($form->metadata ?: [], [
                'purpose' => 'Scoped support',
                'language' => 'nb',
                'scope' => [
                    'type' => IntakeForm::SCOPE_CLIENT,
                    'client_id' => $client->id,
                    'service_id' => null,
                    'campaign_key' => null,
                ],
                'routing' => ['mode' => IntakeForm::ROUTING_MODE_MANUAL_REVIEW],
            ]),
        ])->save();

        $this->post(route('intake.forms.submit', $form), [
            'fields' => [
                'company_name' => 'A different typed company',
                'contact_name' => 'Ada Scoped',
                'contact_email' => 'ada.scoped@example.test',
                'contact_phone' => '+47 222 33 444',
                'subject' => 'Scoped request',
                'message' => 'This form is pinned to a client.',
                'consent' => '1',
            ],
        ])->assertRedirect(route('intake.forms.thanks', $form));

        $submission = IntakeSubmission::query()->firstOrFail();

        $this->assertSame($client->id, $submission->matched_client_id);
        $this->assertSame('form_scope_client', $submission->routing_result['match_method']);
        $this->assertSame($form->slug, $submission->raw_payload['form_snapshot']['slug']);
        $this->assertSame(IntakeForm::SCOPE_CLIENT, $submission->raw_payload['form_snapshot']['scope']['type']);
        $this->assertSame($client->id, $submission->raw_payload['form_snapshot']['scope']['client_id']);
        $this->assertSame('subject', collect($submission->raw_payload['field_snapshots'])->firstWhere('key', 'subject')['key']);
    }

    #[Test]
    public function automatic_ticket_routing_honors_known_client_policy(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $client = Client::factory()->create(['name' => 'Known Ticket AS']);
        ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'HQ', 'is_default' => true]);
        $form->forceFill([
            'target_type' => IntakeForm::TARGET_TICKET,
            'metadata' => array_replace($form->metadata ?: [], [
                'scope' => [
                    'type' => IntakeForm::SCOPE_CLIENT,
                    'client_id' => $client->id,
                    'service_id' => null,
                    'campaign_key' => null,
                ],
                'routing' => ['mode' => IntakeForm::ROUTING_MODE_KNOWN_CLIENT_ONLY],
            ]),
        ])->save();

        $this->post(route('intake.forms.submit', $form), [
            'fields' => [
                'company_name' => 'Known Ticket AS',
                'contact_name' => 'Ada Known',
                'contact_email' => 'ada.known@example.test',
                'subject' => 'Create a ticket',
                'message' => 'This should become a Ticket.',
                'consent' => '1',
            ],
        ])->assertRedirect(route('intake.forms.thanks', $form));

        $submission = IntakeSubmission::query()->firstOrFail();
        $ticket = Ticket::query()->firstOrFail();

        $this->assertSame(IntakeSubmission::STATUS_ROUTED, $submission->status);
        $this->assertSame(Ticket::class, $submission->target_type);
        $this->assertSame($ticket->id, $submission->target_id);
        $this->assertSame('intake', $ticket->channel);
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($submission->id, $ticket->metadata['intake_submission_id']);
        $this->assertTrue($ticket->is_unread);
    }

    #[Test]
    public function automatic_ticket_routing_skips_unknown_clients_when_policy_requires_known_client(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $form->forceFill([
            'target_type' => IntakeForm::TARGET_TICKET,
            'metadata' => array_replace($form->metadata ?: [], [
                'scope' => ['type' => IntakeForm::SCOPE_GLOBAL],
                'routing' => ['mode' => IntakeForm::ROUTING_MODE_KNOWN_CLIENT_ONLY],
            ]),
        ])->save();

        $this->post(route('intake.forms.submit', $form), [
            'fields' => [
                'company_name' => 'Unknown Ticket AS',
                'contact_name' => 'Una Unknown',
                'contact_email' => 'unknown.ticket@example.test',
                'subject' => 'Should wait',
                'message' => 'No client exists yet.',
                'consent' => '1',
            ],
        ])->assertRedirect(route('intake.forms.thanks', $form));

        $submission = IntakeSubmission::query()->firstOrFail();

        $this->assertSame(IntakeSubmission::STATUS_ROUTING_SKIPPED, $submission->status);
        $this->assertSame('known_client_required', $submission->routing_result['reason']);
        $this->assertDatabaseCount('tickets', 0);
    }

    #[Test]
    public function reviewer_can_route_submission_to_ticket_without_copying_intake_files(): void
    {
        Storage::fake('local');
        $form = app(EnsureIntakeDefaults::class)->handle();

        $this->post(route('intake.forms.submit', $form), [
            'fields' => [
                'company_name' => 'Manual Ticket AS',
                'contact_name' => 'Tina Ticket',
                'contact_email' => 'tina.ticket@example.test',
                'subject' => 'Manual ticket route',
                'message' => 'Please route this manually.',
                'consent' => '1',
            ],
            'files' => [
                'attachments' => [
                    UploadedFile::fake()->createWithContent('manual-ticket.txt', 'manual ticket'),
                ],
            ],
        ])->assertRedirect(route('intake.forms.thanks', $form));

        $submission = IntakeSubmission::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.intake.submissions.route-ticket', $submission))
            ->assertRedirect(route('tech.admin.system.intake.submissions.show', $submission));

        $submission->refresh();
        $ticket = Ticket::query()->firstOrFail();

        $this->assertSame(IntakeSubmission::STATUS_ROUTED, $submission->status);
        $this->assertSame(Ticket::class, $submission->target_type);
        $this->assertSame($ticket->id, $submission->target_id);
        $this->assertStringContainsString('manual-ticket.txt', $ticket->description);
        $this->assertDatabaseCount('intake_submission_attachments', 1);
        $this->assertDatabaseCount('ticket_attachments', 0);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.intake.submissions.show', $submission))
            ->assertOk()
            ->assertSee('Review outcome')
            ->assertSee($ticket->ticket_key);
    }

    #[Test]
    public function reviewer_can_route_submission_to_task(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $submission = IntakeSubmission::query()->create([
            'intake_form_id' => $form->id,
            'status' => IntakeSubmission::STATUS_NEW,
            'raw_payload' => ['fields' => ['subject' => 'Create follow-up task']],
            'normalized_payload' => [
                'subject' => 'Create follow-up task',
                'message' => 'Task body',
            ],
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.intake.submissions.route-task', $submission))
            ->assertRedirect(route('tech.admin.system.intake.submissions.show', $submission));

        $submission->refresh();
        $task = Task::query()->firstOrFail();

        $this->assertSame(IntakeSubmission::STATUS_ROUTED, $submission->status);
        $this->assertSame(Task::class, $submission->target_type);
        $this->assertSame($task->id, $submission->target_id);
        $this->assertSame('intake_submission', $task->source_type);
        $this->assertSame($submission->id, $task->source_id);
        $this->assertSame($submission->id, $task->metadata['intake_submission_id']);
    }

    #[Test]
    public function reviewer_can_close_submission_and_link_existing_records(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $duplicateOf = IntakeSubmission::query()->create([
            'intake_form_id' => $form->id,
            'status' => IntakeSubmission::STATUS_REVIEWED,
            'raw_payload' => ['fields' => ['subject' => 'Original']],
            'normalized_payload' => ['subject' => 'Original'],
            'submitted_at' => now(),
        ]);
        $submission = IntakeSubmission::query()->create([
            'intake_form_id' => $form->id,
            'status' => IntakeSubmission::STATUS_NEW,
            'raw_payload' => ['fields' => ['subject' => 'Duplicate']],
            'normalized_payload' => ['subject' => 'Duplicate'],
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.intake.submissions.outcome', $submission), [
                'status' => IntakeSubmission::STATUS_DUPLICATE,
                'duplicate_of_submission_id' => $duplicateOf->id,
                'reason' => 'Same customer request.',
            ])
            ->assertRedirect();

        $submission->refresh();
        $this->assertSame(IntakeSubmission::STATUS_DUPLICATE, $submission->status);
        $this->assertDatabaseHas('intake_submission_events', [
            'intake_submission_id' => $submission->id,
            'type' => 'marked_duplicate',
        ]);

        $client = Client::factory()->create(['name' => 'Manual Link AS']);
        $linkSubmission = IntakeSubmission::query()->create([
            'intake_form_id' => $form->id,
            'status' => IntakeSubmission::STATUS_NEW,
            'raw_payload' => ['fields' => ['subject' => 'Link me']],
            'normalized_payload' => ['subject' => 'Link me'],
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.intake.submissions.link-existing', $linkSubmission), [
                'target_type' => 'client',
                'reference' => (string) $client->id,
            ])
            ->assertRedirect(route('tech.admin.system.intake.submissions.show', $linkSubmission));

        $linkSubmission->refresh();
        $this->assertSame($client->id, $linkSubmission->matched_client_id);
        $this->assertNull($linkSubmission->target_type);

        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'Existing linked ticket',
            'description' => 'Existing ticket body',
        ], $this->admin);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.intake.submissions.link-existing', $linkSubmission), [
                'target_type' => 'ticket',
                'reference' => $ticket->ticket_key,
            ])
            ->assertRedirect(route('tech.admin.system.intake.submissions.show', $linkSubmission));

        $linkSubmission->refresh();
        $this->assertSame(IntakeSubmission::STATUS_ROUTED, $linkSubmission->status);
        $this->assertSame(Ticket::class, $linkSubmission->target_type);
        $this->assertSame($ticket->id, $linkSubmission->target_id);
    }

    #[Test]
    public function sales_target_form_routes_matched_client_submission_to_sales_opportunity(): void
    {
        $form = app(EnsureIntakeDefaults::class)->handle();
        $form->forceFill([
            'target_type' => IntakeForm::TARGET_SALES_LEAD,
            'metadata' => array_replace($form->metadata ?: [], [
                'routing' => ['mode' => IntakeForm::ROUTING_MODE_KNOWN_CLIENT_ONLY],
            ]),
        ])->save();

        $client = Client::factory()->create(['name' => 'Matched Client AS']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'HQ']);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Ada Buyer',
            'email' => 'buyer@example.test',
        ]);

        $this->post(route('intake.forms.submit', $form), [
            'fields' => [
                'company_name' => 'Matched Client AS',
                'contact_name' => 'Ada Buyer',
                'contact_email' => 'buyer@example.test',
                'subject' => 'Managed services request',
                'message' => 'We want a managed services proposal.',
                'consent' => '1',
            ],
        ])->assertRedirect(route('intake.forms.thanks', $form));

        $submission = IntakeSubmission::query()->firstOrFail();
        $opportunity = SalesOpportunity::query()->firstOrFail();

        $this->assertSame(IntakeSubmission::STATUS_ROUTED, $submission->status);
        $this->assertSame(SalesOpportunity::class, $submission->target_type);
        $this->assertSame($opportunity->id, $submission->target_id);
        $this->assertSame($client->id, $opportunity->client_id);
        $this->assertSame($contact->id, $opportunity->primary_contact_id);
        $this->assertSame('Managed services request', $opportunity->title);
        $this->assertDatabaseHas('sales_activities', [
            'opportunity_id' => $opportunity->id,
            'type' => 'inbound_inquiry',
        ]);
    }

    private function conditionalBusinessForm(): IntakeForm
    {
        $form = IntakeForm::query()->create([
            'name' => 'Conditional business form',
            'slug' => 'conditional-business-form-'.uniqid(),
            'status' => IntakeForm::STATUS_ACTIVE,
            'target_type' => IntakeForm::TARGET_REVIEW_ONLY,
            'auto_create_client' => false,
            'auto_create_contact' => false,
            'spam_honeypot_field' => 'intake_website',
            'max_files' => 5,
            'max_file_size_kb' => 20480,
            'allowed_mime_types' => IntakeForm::DEFAULT_ALLOWED_MIME_TYPES,
        ]);

        $form->fields()->create([
            'key' => 'customer_type',
            'label' => 'Customer type',
            'field_type' => IntakeFormField::TYPE_SELECT,
            'options' => [
                ['label' => 'Private', 'value' => 'Private'],
                ['label' => 'Business', 'value' => 'Business'],
            ],
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 0,
            'metadata' => ['layout' => ['width' => 12]],
        ]);

        $form->fields()->create([
            'key' => 'org_number',
            'label' => 'Organization number',
            'field_type' => IntakeFormField::TYPE_TEXT,
            'maps_to' => IntakeFormField::MAP_ORG_NO,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 10,
            'metadata' => [
                'layout' => ['width' => 12],
                'visibility' => [
                    'mode' => IntakeFormField::VISIBILITY_MODE_CONDITIONAL,
                    'match' => IntakeFormField::VISIBILITY_MATCH_ALL,
                    'rules' => [
                        [
                            'source_key' => 'customer_type',
                            'operator' => IntakeFormField::VISIBILITY_OPERATOR_EQUALS,
                            'value' => 'Business',
                        ],
                    ],
                ],
            ],
        ]);

        return $form->load('activeFields');
    }

    private function conditionalEmailContainsForm(): IntakeForm
    {
        $form = IntakeForm::query()->create([
            'name' => 'Conditional email contains form',
            'slug' => 'conditional-email-contains-form-'.uniqid(),
            'status' => IntakeForm::STATUS_ACTIVE,
            'target_type' => IntakeForm::TARGET_REVIEW_ONLY,
            'auto_create_client' => false,
            'auto_create_contact' => false,
            'spam_honeypot_field' => 'intake_website',
            'max_files' => 5,
            'max_file_size_kb' => 20480,
            'allowed_mime_types' => IntakeForm::DEFAULT_ALLOWED_MIME_TYPES,
        ]);

        $form->fields()->create([
            'key' => 'contact_email',
            'label' => 'Email',
            'field_type' => IntakeFormField::TYPE_EMAIL,
            'maps_to' => IntakeFormField::MAP_CONTACT_EMAIL,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 0,
            'metadata' => ['layout' => ['width' => 12]],
        ]);

        $form->fields()->create([
            'key' => 'contact_phone',
            'label' => 'Phone',
            'field_type' => IntakeFormField::TYPE_PHONE,
            'maps_to' => IntakeFormField::MAP_CONTACT_PHONE,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 10,
            'metadata' => [
                'layout' => ['width' => 12],
                'visibility' => [
                    'mode' => IntakeFormField::VISIBILITY_MODE_CONDITIONAL,
                    'match' => IntakeFormField::VISIBILITY_MATCH_ALL,
                    'rules' => [
                        [
                            'source_key' => 'contact_email',
                            'operator' => IntakeFormField::VISIBILITY_OPERATOR_CONTAINS,
                            'value' => '@',
                        ],
                        [
                            'source_key' => 'contact_email',
                            'operator' => IntakeFormField::VISIBILITY_OPERATOR_CONTAINS,
                            'value' => '.',
                        ],
                    ],
                ],
            ],
        ]);

        return $form->load('activeFields');
    }

    private function conditionalFileForm(): IntakeForm
    {
        $form = IntakeForm::query()->create([
            'name' => 'Conditional file form',
            'slug' => 'conditional-file-form-'.uniqid(),
            'status' => IntakeForm::STATUS_ACTIVE,
            'target_type' => IntakeForm::TARGET_REVIEW_ONLY,
            'auto_create_client' => false,
            'auto_create_contact' => false,
            'spam_honeypot_field' => 'intake_website',
            'max_files' => 5,
            'max_file_size_kb' => 20480,
            'allowed_mime_types' => IntakeForm::DEFAULT_ALLOWED_MIME_TYPES,
        ]);

        $form->fields()->create([
            'key' => 'attach_files',
            'label' => 'Attach files',
            'field_type' => IntakeFormField::TYPE_SELECT,
            'options' => [
                ['label' => 'No', 'value' => 'No'],
                ['label' => 'Yes', 'value' => 'Yes'],
            ],
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 0,
            'metadata' => ['layout' => ['width' => 12]],
        ]);

        $form->fields()->create([
            'key' => 'attachments',
            'label' => 'Attachments',
            'field_type' => IntakeFormField::TYPE_FILE,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 10,
            'metadata' => [
                'layout' => ['width' => 12],
                'visibility' => [
                    'mode' => IntakeFormField::VISIBILITY_MODE_CONDITIONAL,
                    'match' => IntakeFormField::VISIBILITY_MATCH_ALL,
                    'rules' => [
                        [
                            'source_key' => 'attach_files',
                            'operator' => IntakeFormField::VISIBILITY_OPERATOR_EQUALS,
                            'value' => 'Yes',
                        ],
                    ],
                ],
            ],
        ]);

        return $form->load('activeFields');
    }
}
