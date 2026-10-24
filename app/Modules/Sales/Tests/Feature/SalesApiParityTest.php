<?php

namespace App\Modules\Sales\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Sales\Actions\EnsureSalesQuoteDraft;
use App\Modules\Sales\Actions\StoreSalesOpportunity;
use App\Modules\Sales\Models\SalesApiCommandReceipt;
use App\Modules\Sales\Models\SalesDeliveryAttempt;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuoteAcceptanceSnapshot;
use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteTemplate;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Models\SalesSetting;
use App\Modules\Sales\Services\SalesCommunications;
use App\Modules\Sales\Support\SalesRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Exercise real HTTP workflows and persisted effects, not only a matching endpoint manifest. */
class SalesApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $client;

    private ClientUser $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Tech');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        $this->actor->givePermissionTo(['sales.view', 'sales.opportunity_manage', 'sales.lead_manage', 'sales.quote_manage']);
        $this->client = Client::create(['name' => 'API Buyer', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $this->client->id]);
        $this->contact = ClientUser::factory()->create(['client_site_id' => $site->id, 'active' => true, 'email' => 'buyer@example.test']);
        Sanctum::actingAs($this->actor, ['*']);
        Queue::fake();
    }

    private function opportunity(): SalesOpportunity
    {
        return app(StoreSalesOpportunity::class)->handle(['client_id' => $this->client->id, 'primary_contact_id' => $this->contact->id, 'title' => 'HTTP quoted work', 'type' => 'other'], $this->actor);
    }

    private function draft(): array
    {
        $opportunity = $this->opportunity();
        $version = app(EnsureSalesQuoteDraft::class)->handle($opportunity, $this->actor);

        return [$opportunity, $version];
    }

    private function url(SalesOpportunity $opportunity, ?SalesQuoteVersion $version = null, string $suffix = ''): string
    {
        return '/api/v1/sales/opportunities/'.$opportunity->opportunity_key.($version ? '/quote/versions/'.$version->id : '').$suffix;
    }

    private function headers($model, string $key): array
    {
        return ['If-Match' => SalesRevision::of($model), 'Idempotency-Key' => $key];
    }

    private function line(): array
    {
        return ['source_type' => 'custom', 'section' => 'implementation', 'downstream_type' => 'implementation', 'name' => 'Agreed work', 'quantity' => 1, 'unit_price_ex_vat' => 500, 'unit_cost_ex_vat' => 120, 'discount_type' => 'amount', 'vat_rate' => 25];
    }

    public function test_reads_are_pure_bounded_and_require_current_actor_as_well_as_token(): void
    {
        SalesSetting::query()->delete();
        SalesQuoteTemplate::query()->delete();
        $counts = [SalesSetting::count(), SalesQuoteTemplate::withTrashed()->count(), Permission::count()];
        $this->getJson('/api/v1/sales/context?client_id='.$this->client->id)->assertOk()->assertJsonPath('data.setup_required', true);
        $this->getJson('/api/v1/sales/opportunities')->assertOk();
        $this->getJson('/api/v1/sales/leads')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($counts, [SalesSetting::count(), SalesQuoteTemplate::withTrashed()->count(), Permission::count()]);
        $this->getJson('/api/v1/sales/opportunities?per_page=101')->assertUnprocessable();
        $this->actor->revokePermissionTo('sales.view');
        $this->getJson('/api/v1/sales/context')->assertForbidden();
        $this->actor->givePermissionTo('sales.view');
        $this->actor->update(['status' => User::STATUS_DISABLED]);
        $this->getJson('/api/v1/sales/context')->assertForbidden();
    }

    public function test_opportunity_stakeholders_activity_replay_and_strict_fields_persist_real_results(): void
    {
        $body = ['client_id' => $this->client->id, 'primary_contact_id' => $this->contact->id, 'title' => 'Created through HTTP', 'type' => 'other'];
        $headers = ['Idempotency-Key' => 'create-opportunity-01'];
        $created = $this->postJson('/api/v1/sales/opportunities', $body, $headers)->assertCreated();
        $again = $this->postJson('/api/v1/sales/opportunities', $body, $headers)->assertCreated()->assertJsonPath('operation.replayed', true);
        $this->assertSame($created->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, SalesOpportunity::count());
        $this->postJson('/api/v1/sales/opportunities', $body + ['created_by' => 999], ['Idempotency-Key' => 'create-opportunity-02'])->assertUnprocessable();
        $opportunity = SalesOpportunity::firstOrFail();
        $h = $this->headers($opportunity, 'stakeholder-command-01');
        $data = ['client_user_id' => $this->contact->id, 'role' => 'Buyer', 'is_primary' => true, 'notes' => 'Internal contact context'];
        $this->postJson($this->url($opportunity, null, '/stakeholders'), $data, $h)->assertOk()->assertJsonPath('data.stakeholders.0.role', 'Buyer');
        $this->postJson($this->url($opportunity, null, '/stakeholders'), $data, $h)->assertOk()->assertJsonPath('operation.replayed', true);
        $this->postJson($this->url($opportunity, null, '/stakeholders'), $data + ['actor_id' => 12], $h)->assertUnprocessable();
        $this->assertSame(1, $opportunity->stakeholders()->count());
        $activity = $this->postJson($this->url($opportunity, null, '/activities'), ['type' => 'email_in', 'body' => 'Please follow up'], ['Idempotency-Key' => 'incoming-activity-001'])->assertCreated()->json('data.id');
        $this->postJson($this->url($opportunity, null, '/activities/'.$activity.'/read'), [], $this->headers($opportunity, 'activity-read-command-01'))->assertOk()->assertJsonPath('data.is_unread', false);
        $this->assertFalse($opportunity->fresh()->is_unread);
        $this->getJson($this->url($opportunity, null, '/activities'))->assertOk()->assertJsonPath('meta.total', 2);
        Sanctum::actingAs($this->actor, ['sales.read']);
        $this->postJson($this->url($opportunity, null, '/activities'), ['type' => 'journal', 'body' => 'Forbidden'])->assertForbidden();
    }

    public function test_cpq_http_customer_acceptance_is_immutable_private_safe_and_replayable(): void
    {
        [$opportunity, $version] = $this->draft();
        $line = $this->postJson($this->url($opportunity, $version, '/lines'), $this->line(), $this->headers($version, 'quote-line-add-001'))->assertOk()->json('operation.affected.line_id');
        $this->assertNotNull($line);
        $details = ['title' => 'Approved scope', 'scope_text' => 'One agreed delivery', 'acknowledgement_title' => 'Scope accepted', 'acknowledgement_body' => 'Customer confirms the exact scope.', 'acknowledgement_required' => true];
        $this->patchJson($this->url($opportunity, $version, '/details'), $details, $this->headers($version, 'quote-details-001'))->assertOk();
        $preview = $this->postJson($this->url($opportunity, $version, '/send-preview'), [], $this->headers($version, 'quote-send-preview-001'))->assertCreated();
        $sendData = ['preview_id' => $preview->json('data.id'), 'preview_hash' => $preview->json('data.preview_hash')];
        $sendHeaders = $this->headers($version, 'quote-send-intent-001');
        $sent = $this->postJson($this->url($opportunity, $version, '/send'), $sendData, $sendHeaders)->assertOk()->assertJsonPath('data.quote.status', 'sent')->assertJsonPath('data.delivery.state', 'queued');
        $this->postJson($this->url($opportunity, $version, '/send'), $sendData, $sendHeaders)->assertOk()->assertJsonPath('operation.replayed', true);
        $this->assertSame(1, SalesDeliveryAttempt::where('state', 'queued')->count());
        $this->getJson('/api/v1/sales/operations/'.$sent->json('operation.operation_id'))->assertOk()->assertJsonPath('data.delivery.inbox_receipt_verified', false);
        $version->refresh();
        $token = $version->secure_token;
        $public = '/api/v1/sales/public/quotes/'.$token;
        $this->assertNull($version->viewed_at);
        $response = $this->getJson($public)->assertOk();
        foreach (['unit_cost_ex_vat', 'margin_amount', 'approval_policy_snapshot', 'secure_token', 'source_snapshot'] as $private) {
            $this->assertStringNotContainsString('"'.$private.'"', $response->getContent());
        }
        $this->assertNull($version->fresh()->viewed_at);
        $ack = $version->acknowledgements()->firstOrFail()->id;
        $accept = ['name' => 'Customer Buyer', 'confirm' => true, 'selected_line_ids' => [$line], 'acknowledgement_ids' => [$ack]];
        $this->postJson($public.'/accept', $accept, ['Idempotency-Key' => 'customer-accept-001'])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->postJson($public.'/accept', $accept, ['Idempotency-Key' => 'customer-accept-001'])->assertOk()->assertJsonPath('operation.replayed', true);
        $this->assertSame(1, SalesQuoteAcceptanceSnapshot::where('quote_version_id', $version->id)->count());
        $this->assertSame('won', $opportunity->fresh()->status);
        $snapshot = SalesQuoteAcceptanceSnapshot::firstOrFail()->getRawOriginal();
        $bad = $accept;
        $bad['name'] = 'Another Buyer';
        $this->postJson($public.'/accept', $bad, ['Idempotency-Key' => 'customer-accept-001'])->assertConflict();
        $this->assertSame($snapshot, SalesQuoteAcceptanceSnapshot::firstOrFail()->getRawOriginal());
    }

    public function test_version_and_line_parent_revision_and_delete_replay_are_enforced(): void
    {
        [$opportunity, $version] = $this->draft();
        $stale = $this->headers($version, 'stale-edit-command-001');
        $added = $this->postJson($this->url($opportunity, $version, '/lines'), $this->line(), $this->headers($version, 'add-line-for-delete-001'))->assertOk();
        $lineId = $added->json('operation.affected.line_id');
        $this->patchJson($this->url($opportunity, $version, '/details'), ['title' => 'Stale edit'], $stale)->assertConflict();
        $this->postJson($this->url($opportunity, $version, '/lines'), $this->line() + ['quote_version_id' => 999], $this->headers($version, 'unsupported-field-001'))->assertUnprocessable();
        [$other, $otherVersion] = $this->draft();
        $this->getJson($this->url($other, $version))->assertNotFound();
        $h = $this->headers($version, 'delete-line-replay-001');
        $this->deleteJson($this->url($opportunity, $version, '/lines/'.$lineId), [], $h)->assertOk()->assertJsonPath('operation.deleted', true);
        $this->deleteJson($this->url($opportunity, $version, '/lines/'.$lineId), [], $h)->assertOk()->assertJsonPath('operation.replayed', true);
        $this->assertNull(SalesQuoteLine::find($lineId));
        $this->postJson($this->url($opportunity, $version, '/lines'), $this->line(), ['Idempotency-Key' => 'missing-revision-001'])->assertStatus(428);
    }

    public function test_a_reviewed_send_rejects_changed_recipient_and_actor_revocation(): void
    {
        [$opportunity, $version] = $this->draft();
        $this->postJson($this->url($opportunity, $version, '/lines'), $this->line(), $this->headers($version, 'recipient-line-001'))->assertOk();
        $preview = $this->postJson($this->url($opportunity, $version, '/send-preview'), [], $this->headers($version, 'recipient-preview-001'))->assertCreated();
        $this->contact->update(['email' => 'changed@example.test']);
        $data = ['preview_id' => $preview->json('data.id'), 'preview_hash' => $preview->json('data.preview_hash')];
        $this->postJson($this->url($opportunity, $version, '/send'), $data, $this->headers($version, 'stale-recipient-send-001'))->assertConflict();
        $this->assertSame('draft', $version->fresh()->status);
        $this->assertSame(0, SalesDeliveryAttempt::where('state', 'queued')->count());
        $this->actor->revokePermissionTo('sales.quote_manage');
        $this->postJson($this->url($opportunity, $version, '/send-preview'), [], $this->headers($version, 'revoked-preview-001'))->assertForbidden();
    }

    public function test_lead_classification_start_and_canonical_quick_contact_are_operational(): void
    {
        $this->patchJson('/api/v1/sales/leads/'.$this->client->id.'/classification', ['lead_temperature' => 5, 'website' => 'https://example.test', 'tag_names' => ['API Prospect']], $this->headers($this->client, 'classify-lead-001'))->assertOk()->assertJsonPath('data.lead_temperature', 5);
        $this->getJson('/api/v1/sales/leads?temperature=5&group_by=temperature')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/sales/leads/'.$this->client->id.'/start', ['title' => 'Qualified lead', 'type' => 'other'], ['Idempotency-Key' => 'start-lead-once-001'])->assertCreated();
        $this->assertSame(1, SalesOpportunity::count());
        $body = ['name' => 'Additional Buyer', 'email' => 'additional@example.test', 'role' => 'Buyer'];
        $response = $this->postJson('/api/v1/sales/clients/'.$this->client->id.'/contacts/quick-create', $body, ['Idempotency-Key' => 'quick-contact-001'])->assertCreated();
        $this->assertNotNull($response->json('data.contact.contact_id'));
        $this->postJson('/api/v1/sales/clients/'.$this->client->id.'/contacts/quick-create', $body, ['Idempotency-Key' => 'quick-contact-001'])->assertCreated()->assertJsonPath('operation.replayed', true);
        $this->assertSame(1, \App\Modules\Contact\Models\Contact::where('display_name', 'Additional Buyer')->count());
        $otherSite = ClientSite::factory()->create();
        $this->postJson('/api/v1/sales/clients/'.$this->client->id.'/contacts/quick-create', $body + ['client_site_id' => $otherSite->id], ['Idempotency-Key' => 'cross-site-contact-001'])->assertUnprocessable();
    }

    public function test_unknown_or_failed_delivery_is_never_automatically_resent(): void
    {
        $row = SalesDeliveryAttempt::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'actor_id' => $this->actor->id, 'opportunity_id' => $this->opportunity()->id, 'kind' => 'activity', 'state' => 'unknown', 'revision' => str_repeat('1', 64), 'preview_hash' => str_repeat('2', 64), 'payload' => ['to_email' => 'buyer@example.test'], 'expires_at' => now()->addMinutes(15), 'started_at' => now()]);
        $mailer = \Mockery::mock(\App\Modules\Email\Services\SmtpAccountMailer::class);
        $mailer->shouldNotReceive('send');
        $this->app->instance(\App\Modules\Email\Services\SmtpAccountMailer::class, $mailer);
        app(SalesCommunications::class)->deliver($row->id);
        app(SalesCommunications::class)->deliver($row->id);
        $this->assertSame('unknown', $row->fresh()->state);
        $this->assertStringNotContainsString('buyer@example.test', $row->getRawOriginal('payload'));
    }

    public function test_public_get_does_not_mark_views_or_expiry_and_explicit_view_is_replay_safe(): void
    {
        [$opportunity, $version] = $this->draft();
        $version->update(['status' => 'sent', 'expires_at' => now()->subDay()]);
        $path = '/api/v1/sales/public/quotes/'.$version->secure_token;
        $this->getJson($path)->assertOk()->assertJsonPath('data.effective_status', 'expired');
        $this->assertSame('sent', $version->fresh()->status);
        $this->assertNull($version->viewed_at);
        $this->postJson($path.'/viewed', [], ['Idempotency-Key' => 'customer-view-001'])->assertOk()->assertJsonPath('data.status', 'expired');
        $this->postJson($path.'/viewed', [], ['Idempotency-Key' => 'customer-view-001'])->assertOk()->assertJsonPath('operation.replayed', true);
        $this->assertSame(1, $opportunity->activities()->where('type', 'quote_viewed')->count());
        $this->getJson('/api/v1/sales/public/quotes/not-the-capability')->assertNotFound();
        $this->postJson($path.'/accept', ['name' => 'Buyer', 'confirm' => true, 'portal_account_id' => 99], ['Idempotency-Key' => 'customer-forged-001'])->assertUnprocessable();
    }

    public function test_approval_settings_template_apply_and_conversion_api_use_the_current_permissions(): void
    {
        $this->getJson('/api/v1/sales/settings/approval-policy')->assertForbidden();
        $this->actor->givePermissionTo(['sales.manage_settings', 'sales.quote.approve']);
        $policy = ['enabled' => true, 'discount_percent_threshold' => 5, 'minimum_margin_percent' => 10, 'quote_total_ex_vat_threshold' => 1, 'manual_line_ex_vat_threshold' => 1];
        $this->patchJson('/api/v1/sales/settings/approval-policy', $policy, ['Idempotency-Key' => 'approval-policy-001', 'If-Match' => $this->getJson('/api/v1/sales/settings/approval-policy')->assertOk()->json('data.revision')])->assertOk()->assertJsonPath('data.enabled', true);
        $template = $this->postJson('/api/v1/sales/quote-templates', ['name' => 'HTTP reviewed template', 'is_active' => true], ['Idempotency-Key' => 'template-create-001'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/sales/quote-templates/'.$template.'/lines', ['source_reference' => 'custom', 'name' => 'Template line', 'section' => 'implementation', 'downstream_type' => 'implementation', 'billing_cadence' => 'one_time', 'quantity' => 1, 'unit_price_ex_vat' => 500, 'discount_type' => 'amount'], ['Idempotency-Key' => 'template-line-001'])->assertCreated();
        [$opportunity, $version] = $this->draft();
        $this->postJson($this->url($opportunity, $version, '/template'), ['template_id' => $template], $this->headers($version, 'apply-template-001'))->assertOk()->assertJsonPath('data.lines.0.name', 'Template line');
        $this->postJson($this->url($opportunity, $version, '/approval/request'), [], $this->headers($version, 'request-approval-001'))->assertOk()->assertJsonPath('data.approval_status', 'pending');
        $this->postJson($this->url($opportunity, $version, '/approval/approve'), ['note' => 'Reviewed scope'], $this->headers($version, 'approve-quote-001'))->assertOk()->assertJsonPath('data.approval_status', 'approved');
        $this->patchJson($this->url($opportunity, $version, '/details'), ['title' => 'Changed after approval'], $this->headers($version, 'invalidate-approval-001'))->assertOk()->assertJsonPath('data.approval_status', 'not_required');
        $this->getJson('/api/v1/sales/reports/quotes')->assertOk()->assertJsonStructure(['data' => ['templates', 'pending_conversion_plans']]);
        $this->postJson('/api/v1/sales/initialize', ['unrecognized' => true], ['Idempotency-Key' => 'init-unknown-001'])->assertUnprocessable();
    }

    public function test_revise_decline_and_conversion_plan_do_not_rewrite_accepted_or_sent_history(): void
    {
        [$opportunity, $version] = $this->draft();
        $this->postJson($this->url($opportunity, $version, '/lines'), $this->line(), $this->headers($version, 'revision-line-001'))->assertOk();
        $version->update(['status' => 'sent']);
        $opportunity->update(['status' => 'negotiation']);
        $oldLines = $version->lines()->get()->toArray();
        $h = $this->headers($version, 'revise-once-001');
        $revised = $this->postJson($this->url($opportunity, $version, '/revise'), [], $h)->assertOk();
        $this->assertNotSame($version->id, $revised->json('data.id'));
        $this->assertSame('superseded', $version->fresh()->status);
        $this->assertSame($oldLines, $version->lines()->get()->toArray());
        $this->postJson($this->url($opportunity, $version, '/revise'), [], $h)->assertOk()->assertJsonPath('operation.replayed', true);
        $current = SalesQuoteVersion::findOrFail($revised->json('data.id'));
        $current->update(['status' => 'sent']);
        $public = '/api/v1/sales/public/quotes/'.$current->secure_token;
        $this->postJson($public.'/decline', ['name' => 'Buyer', 'reason' => 'Timing'], ['Idempotency-Key' => 'decline-once-001'])->assertOk()->assertJsonPath('data.status', 'declined');
        $this->postJson($public.'/accept', ['name' => 'Buyer', 'confirm' => true], ['Idempotency-Key' => 'declined-no-accept-001'])->assertUnprocessable();
        $this->assertSame(0, SalesQuoteAcceptanceSnapshot::count());
    }

    public function test_outbound_activity_preview_is_frozen_and_smtp_is_claimed_only_once(): void
    {
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);
        \App\Modules\Email\Models\EmailAccount::create(['address' => 'sales@example.test', 'from_name' => 'Sales', 'is_active' => true, 'is_global_default' => false, 'defaults_for' => ['sales'], 'imap_host' => 'imap.example.test', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_username' => 'sales@example.test', 'imap_secret' => 'synthetic-test-secret', 'imap_auth_type' => 'password', 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => 'sales@example.test', 'smtp_secret' => 'synthetic-test-secret', 'smtp_auth_type' => 'password']);
        $opportunity = $this->opportunity();
        $preview = $this->postJson($this->url($opportunity, null, '/emails/preview'), ['subject' => 'Agreed follow-up', 'body' => 'Reviewed body only', 'recipient_contact_id' => $this->contact->id, 'cc' => 'copy@example.test'], $this->headers($opportunity, 'activity-preview-001'))->assertCreated()->assertJsonPath('data.ready', true);
        $send = $this->postJson($this->url($opportunity, null, '/emails'), ['preview_id' => $preview->json('data.id'), 'preview_hash' => $preview->json('data.preview_hash')], $this->headers($opportunity, 'activity-send-001'))->assertCreated()->assertJsonPath('data.delivery.state', 'queued');
        $attempt = SalesDeliveryAttempt::findOrFail($send->json('data.delivery.id'));
        $frozen = $attempt->payload;
        $this->contact->update(['email' => 'later@example.test']);
        $mailer = \Mockery::mock(\App\Modules\Email\Services\SmtpAccountMailer::class);
        $mailer->shouldReceive('send')->once()->withArgs(function ($account, $to, $name, $subject, $html, $text, $attachments, $cc) use ($frozen) {
            return $to === $frozen['to_email'] && $subject === $frozen['subject'] && $html === $frozen['html'] && $text === $frozen['text'] && $cc[0]['email'] === 'copy@example.test';
        })->andReturn('<frozen-test@example.test>');
        $this->app->instance(\App\Modules\Email\Services\SmtpAccountMailer::class, $mailer);
        app(SalesCommunications::class)->deliver($attempt->id);
        app(SalesCommunications::class)->deliver($attempt->id);
        $this->assertSame('smtp_accepted', $attempt->fresh()->state);
        $this->assertFalse(app(SalesCommunications::class)->project($attempt->fresh())['inbox_receipt_verified']);
        $this->assertSame(1, \App\Modules\Email\Models\EmailLog::where('code', 'SALES_EMAIL_SENT')->count());
    }

    public function test_provider_intent_survives_a_failed_http_attempt_without_an_outer_transaction(): void
    {
        $connection = new \App\Models\System\Integrations\Integration(['id' => 'synthetic-provider', 'type' => 'tripletex']);
        $authority = \Mockery::mock(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class);
        $authority->shouldReceive('connection')->andReturn($connection);
        $this->app->instance(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class, $authority);
        $data = ['name' => 'Provider prospect', 'site_name' => 'Office', 'user_name' => 'Contact', 'user_email' => 'contact@example.test', 'tripletex_number_mode' => true, 'tripletex_request_key' => (string) \Illuminate\Support\Str::uuid()];
        $level = \Illuminate\Support\Facades\DB::transactionLevel();
        $create = \Mockery::mock(\App\Modules\Sales\Actions\SalesQuickCreate::class);
        $create->shouldReceive('clientData')->andReturn($data);
        $create->shouldReceive('client')->twice()->andReturnUsing(function () use ($level) {
            $this->assertSame($level, \Illuminate\Support\Facades\DB::transactionLevel());
            throw \Illuminate\Validation\ValidationException::withMessages(['client_number' => 'Synthetic unknown provider outcome; reconcile before retry.']);
        });
        $this->app->instance(\App\Modules\Sales\Actions\SalesQuickCreate::class, $create);
        $headers = ['Idempotency-Key' => 'provider-intent-001'];
        $this->postJson('/api/v1/sales/clients/quick-create', $data, $headers)->assertUnprocessable();
        $this->postJson('/api/v1/sales/clients/quick-create', $data, $headers)->assertUnprocessable();
        $this->assertSame(1, SalesApiCommandReceipt::where('operation', 'sales.client.provider')->count());
        $this->assertSame('provider_intent', SalesApiCommandReceipt::where('operation', 'sales.client.provider')->firstOrFail()->result['type']);
        $this->assertSame(1, Client::count());
    }

    public function test_local_client_creation_deduplicates_and_does_not_infer_billing_email(): void
    {
        config(['tripletex.enabled' => false]);
        $data = ['name' => 'New Sales customer', 'site_name' => 'Office', 'user_name' => 'Buyer', 'user_email' => 'primary@example.test', 'client_number' => '54321'];
        $h = ['Idempotency-Key' => 'create-sales-client-001'];
        $created = $this->postJson('/api/v1/sales/clients/quick-create', $data, $h)->assertCreated();
        $this->postJson('/api/v1/sales/clients/quick-create', $data, $h)->assertCreated()->assertJsonPath('operation.replayed', true);
        $this->assertSame(1, Client::where('name', 'New Sales customer')->count());
        $this->assertNull(Client::findOrFail($created->json('data.id'))->billing_email);
        $this->assertNotNull($created->json('data.contacts.0.contact_id'));
    }

    public function test_draft_partial_fields_preserve_acknowledgements_and_pdf_is_customer_safe(): void
    {
        [$opportunity, $version] = $this->draft();
        $line = $this->line() + ['line_acknowledgement_title' => 'Line terms', 'line_acknowledgement_body' => 'Agreed work terms'];
        $added = $this->postJson($this->url($opportunity, $version, '/lines'), $line, $this->headers($version, 'terms-line-001'))->assertOk();
        $id = $added->json('operation.affected.line_id');
        $update = $this->line();
        unset($update['source_type'], $update['unit_cost_ex_vat']);
        $update['name'] = 'Renamed agreed work';
        $this->patchJson($this->url($opportunity, $version, '/lines/'.$id), $update, $this->headers($version, 'terms-edit-001'))->assertOk();
        $this->assertSame(1, $version->acknowledgements()->count());
        $this->assertSame('120.00', SalesQuoteLine::findOrFail($id)->unit_cost_ex_vat);
        $this->getJson($this->url($opportunity, $version, '/pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('/api/v1/sales/public/quotes/'.$version->secure_token.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_every_staff_api_requires_authentication_before_domain_dispatch(): void
    {
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $contract = json_decode(file_get_contents(app_path('Modules/Sales/Docs/api-contract.json')), true);
        foreach ($contract['operations'] as $operation) {
            if ($operation['mode'] !== 'staff') {
                continue;
            }
            $path = '/api/v1/'.preg_replace('/\{[^}]+\}/', '1', $operation['path']);
            $this->json($operation['method'], $path, [])->assertUnauthorized();
        }
    }

    public function test_missing_recipient_receipt_contains_no_quote_capability_and_no_false_delivery(): void
    {
        [$opportunity, $version] = $this->draft();
        $this->postJson($this->url($opportunity, $version, '/lines'), $this->line(), $this->headers($version, 'no-recipient-line-001'))->assertOk();
        $this->contact->update(['email' => null]);
        $preview = $this->postJson($this->url($opportunity, $version, '/send-preview'), [], $this->headers($version, 'no-recipient-preview-001'))->assertCreated()->assertJsonPath('data.preview.error_code', 'no_recipient');
        $sent = $this->postJson($this->url($opportunity, $version, '/send'), ['preview_id' => $preview->json('data.id'), 'preview_hash' => $preview->json('data.preview_hash')], $this->headers($version, 'no-recipient-send-001'))->assertOk()->assertJsonPath('data.quote.status', 'sent')->assertJsonPath('data.delivery.state', 'no_recipient');
        $receipt = SalesApiCommandReceipt::findOrFail($sent->json('operation.operation_id'));
        $this->assertStringNotContainsString($version->secure_token, $receipt->getRawOriginal('result'));
        Queue::assertNotPushed(\App\Modules\Sales\Jobs\SendSalesQuoteEmail::class);
    }

    public function test_new_internal_notification_requires_key_and_revision_and_has_delivery_readback(): void
    {
        $opportunity = $this->opportunity();
        $data = ['type' => 'internal_note', 'body' => 'Internal-only follow-up', 'notify_user_id' => $this->actor->id];
        $path = $this->url($opportunity, null, '/activities');
        $this->postJson($path, $data)->assertUnprocessable();
        $this->postJson($path, $data, ['Idempotency-Key' => 'notify-no-revision-001'])->assertStatus(428);
        $h = $this->headers($opportunity, 'notify-complete-intent-001');
        $this->postJson($path, $data, $h)->assertCreated()->assertJsonPath('data.delivery.state', 'queued');
        $this->postJson($path, $data, $h)->assertCreated()->assertJsonPath('operation.replayed', true);
        $this->assertSame(1, $opportunity->activities()->where('type', 'internal_note')->count());
        Queue::assertPushed(\App\Modules\Sales\Jobs\SendSalesInternalNotificationEmail::class, 1);
    }

    public function test_keyed_dispatch_replays_and_refuses_a_started_or_failed_delivery(): void
    {
        [$opportunity, $version] = $this->draft();
        $this->postJson($this->url($opportunity, $version, '/lines'), $this->line(), $this->headers($version, 'dispatch-line-001'))->assertOk();
        $preview = $this->postJson($this->url($opportunity, $version, '/send-preview'), [], $this->headers($version, 'dispatch-preview-001'))->assertCreated();
        $sent = $this->postJson($this->url($opportunity, $version, '/send'), ['preview_id' => $preview->json('data.id'), 'preview_hash' => $preview->json('data.preview_hash')], $this->headers($version, 'dispatch-send-001'))->assertOk();
        $path = '/api/v1/sales/operations/'.$sent->json('operation.operation_id').'/dispatch';
        $h = ['Idempotency-Key' => 'dispatch-recovery-001'];
        $this->postJson($path, [], $h)->assertOk();
        $this->postJson($path, [], $h)->assertOk()->assertJsonPath('operation.replayed', true);
        Queue::assertPushed(\App\Modules\Sales\Jobs\SendSalesQuoteEmail::class, 2);
        $attempt = SalesDeliveryAttempt::findOrFail($sent->json('data.delivery.id'));
        app(SalesCommunications::class)->deliver($attempt->id);
        $this->assertSame('failed', $attempt->fresh()->state);
        $this->postJson($path, [], ['Idempotency-Key' => 'do-not-redispatch-started-001'])->assertConflict();
        Queue::assertPushed(\App\Modules\Sales\Jobs\SendSalesQuoteEmail::class, 2);
    }

    public function test_nested_selection_cc_and_policy_preconditions_reject_ignored_or_stale_input(): void
    {
        [$opportunity, $version] = $this->draft();
        $this->postJson($this->url($opportunity, $version, '/lines'), $this->line(), $this->headers($version, 'nested-line-001'))->assertOk();
        $version->update(['status' => 'sent']);
        $this->postJson('/api/v1/sales/public/quotes/'.$version->secure_token.'/accept', ['name' => 'Buyer', 'confirm' => true, 'quantities' => ['unsupported_key' => 3]], ['Idempotency-Key' => 'nested-quantity-001'])->assertUnprocessable();
        $this->postJson($this->url($opportunity, null, '/emails/preview'), ['body' => 'Message', 'to_email' => 'buyer@example.test', 'cc' => 'not-an-email'], $this->headers($opportunity, 'invalid-cc-preview-001'))->assertUnprocessable();
        $this->actor->givePermissionTo('sales.manage_settings');
        $revision = $this->getJson('/api/v1/sales/settings/approval-policy')->assertOk()->json('data.revision');
        $policy = ['enabled' => true, 'discount_percent_threshold' => 5, 'minimum_margin_percent' => 10, 'quote_total_ex_vat_threshold' => 100, 'manual_line_ex_vat_threshold' => 100];
        $h = ['Idempotency-Key' => 'policy-replay-001', 'If-Match' => $revision];
        $this->patchJson('/api/v1/sales/settings/approval-policy', $policy, $h)->assertOk();
        $this->patchJson('/api/v1/sales/settings/approval-policy', $policy, $h)->assertOk()->assertJsonPath('operation.replayed', true);
        $this->patchJson('/api/v1/sales/settings/approval-policy', $policy, ['Idempotency-Key' => 'policy-stale-001', 'If-Match' => $revision])->assertConflict();
    }
}
