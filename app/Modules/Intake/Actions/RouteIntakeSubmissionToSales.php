<?php

namespace App\Modules\Intake\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Clients\Actions\SuggestClientNumber;
use App\Modules\Contact\Actions\StoreContact;
use App\Modules\Contact\Models\Contact;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Intake\Models\IntakeSubmissionEvent;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RouteIntakeSubmissionToSales
{
    public function __construct(
        private readonly StoreContact $storeContact,
        private readonly SuggestClientNumber $suggestClientNumber,
    ) {}

    public function handle(IntakeSubmission $submission, bool $force = false, ?User $actor = null): ?SalesOpportunity
    {
        $submission->loadMissing(['form', 'attachments', 'matchedClient.sites', 'matchedSite', 'matchedClientUser']);

        if ($submission->status === IntakeSubmission::STATUS_SPAM) {
            return null;
        }

        if (! $force && $submission->form?->target_type !== IntakeForm::TARGET_SALES_LEAD) {
            return null;
        }

        if ($submission->target_type === SalesOpportunity::class && $submission->target_id) {
            return SalesOpportunity::query()->find($submission->target_id);
        }

        return DB::transaction(function () use ($submission, $actor): ?SalesOpportunity {
            $normalized = $submission->normalized_payload ?: [];
            $form = $submission->form;
            $client = $submission->matchedClient;
            $site = $submission->matchedSite ?: ($client ? $this->defaultSite($client) : null);
            $clientUser = $submission->matchedClientUser;
            $autoCreatedClient = false;
            $autoCreatedContact = false;

            if (! $client && $form?->auto_create_client) {
                [$client, $site] = $this->createClient($submission, $normalized);
                $autoCreatedClient = true;
            }

            if (! $client) {
                $this->markSkipped($submission, 'No matched client and auto-create client is disabled.', [
                    'reason' => 'no_client',
                ], $actor);

                return null;
            }

            if (! $clientUser && $form?->auto_create_contact) {
                try {
                    $contact = $this->createOrLinkContact($client, $site, $normalized);
                    $clientUser = $this->clientUserFor($contact, $site);
                    $autoCreatedContact = (bool) $contact;
                } catch (ValidationException $exception) {
                    $this->markSkipped($submission, 'Contact could not be linked safely.', [
                        'reason' => 'contact_validation_failed',
                        'errors' => $exception->errors(),
                    ], $actor);

                    return null;
                }
            }

            $opportunity = SalesOpportunity::query()->create([
                'opportunity_key' => $this->nextKey(),
                'client_id' => $client->id,
                'primary_contact_id' => $clientUser?->id,
                'owner_id' => $form?->owner_id,
                'title' => $this->title($normalized),
                'type' => 'service_agreement',
                'status' => 'new_lead',
                'summary' => 'Public inquiry received from '.$submission->form?->name.'.',
                'needs' => $this->stringValue($normalized['message'] ?? null),
                'estimated_value_ex_vat' => 0,
                'probability_percent' => 10,
                'weighted_value_ex_vat' => 0,
                'is_unread' => true,
                'metadata' => [
                    'created_from' => 'intake_submission',
                    'intake_submission_id' => $submission->id,
                    'intake_form_id' => $submission->intake_form_id,
                    'attachment_count' => $submission->attachments->count(),
                ],
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            SalesActivity::query()->create([
                'opportunity_id' => $opportunity->id,
                'actor_id' => $actor?->id,
                'type' => 'inbound_inquiry',
                'direction' => 'inbound',
                'subject' => 'Public inquiry received',
                'body' => $this->activityBody($submission, $normalized),
                'is_unread' => true,
                'metadata' => [
                    'created_from' => 'intake_submission',
                    'intake_submission_id' => $submission->id,
                    'attachment_count' => $submission->attachments->count(),
                ],
            ]);

            $result = [
                'action' => 'sales_opportunity_created',
                'opportunity_id' => $opportunity->id,
                'opportunity_key' => $opportunity->opportunity_key,
                'client_id' => $client->id,
                'client_user_id' => $clientUser?->id,
                'auto_created_client' => $autoCreatedClient,
                'auto_created_contact' => $autoCreatedContact,
            ];

            $submission->forceFill([
                'status' => IntakeSubmission::STATUS_ROUTED,
                'matched_client_id' => $client->id,
                'matched_site_id' => $site?->id,
                'matched_client_user_id' => $clientUser?->id,
                'matched_contact_id' => $clientUser?->contact_id ?: $submission->matched_contact_id,
                'target_type' => SalesOpportunity::class,
                'target_id' => $opportunity->id,
                'routing_result' => $result,
            ])->save();

            $submission->events()->create([
                'actor_id' => $actor?->id,
                'type' => 'routed_to_sales',
                'message' => 'Created sales opportunity '.$opportunity->opportunity_key.'.',
                'metadata' => $result,
            ]);

            return $opportunity;
        });
    }

    private function createClient(IntakeSubmission $submission, array $normalized): array
    {
        $company = $this->stringValue($normalized['company_name'] ?? null) ?: $this->stringValue($normalized['contact_name'] ?? null) ?: 'Public inquiry '.$submission->id;
        $client = Client::query()->create([
            'name' => $company,
            'client_number' => $this->suggestClientNumber->handle(),
            'org_no' => $this->stringValue($normalized['org_no'] ?? null) ?: null,
            'website' => $this->stringValue($normalized['website'] ?? null) ?: null,
            'billing_email' => $this->stringValue($normalized['contact_email'] ?? null) ?: null,
            'lead_temperature' => 3,
            'notes' => 'Created from public intake submission #'.$submission->id.'.',
            'active' => true,
        ]);

        $site = ClientSite::query()->create([
            'client_id' => $client->id,
            'name' => 'Main Office',
            'is_default' => true,
        ]);

        return [$client, $site];
    }

    private function createOrLinkContact(Client $client, ?ClientSite $site, array $normalized): ?Contact
    {
        $displayName = $this->stringValue($normalized['contact_name'] ?? null)
            ?: $this->stringValue($normalized['contact_email'] ?? null);

        if ($displayName === '') {
            return null;
        }

        return $this->storeContact->handle([
            'display_name' => $displayName,
            'organization_name' => $client->name,
            'email' => $this->stringValue($normalized['contact_email'] ?? null) ?: null,
            'phone' => $this->stringValue($normalized['contact_phone'] ?? null) ?: null,
            'client_id' => $client->id,
            'site_id' => $site?->id,
            'relation_type' => 'customer',
            'marketing_consent' => false,
            'update_existing' => false,
        ]);
    }

    private function clientUserFor(?Contact $contact, ?ClientSite $site): ?ClientUser
    {
        if (! $contact || ! $site) {
            return null;
        }

        return ClientUser::query()
            ->where('contact_id', $contact->id)
            ->where('client_site_id', $site->id)
            ->first();
    }

    private function defaultSite(Client $client): ?ClientSite
    {
        return $client->sites()
            ->where('is_default', true)
            ->orderBy('name')
            ->first()
            ?: $client->sites()->orderBy('name')->first();
    }

    private function markSkipped(IntakeSubmission $submission, string $message, array $metadata, ?User $actor): void
    {
        $submission->forceFill([
            'status' => IntakeSubmission::STATUS_ROUTING_SKIPPED,
            'routing_result' => $metadata + ['message' => $message],
        ])->save();

        IntakeSubmissionEvent::query()->create([
            'intake_submission_id' => $submission->id,
            'actor_id' => $actor?->id,
            'type' => 'routing_skipped',
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }

    private function nextKey(): string
    {
        do {
            $key = 'SO-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        } while (SalesOpportunity::query()->where('opportunity_key', $key)->exists());

        return $key;
    }

    private function title(array $normalized): string
    {
        $subject = $this->stringValue($normalized['subject'] ?? null);

        if ($subject !== '') {
            return $subject;
        }

        $fallback = $this->stringValue($normalized['company_name'] ?? null)
            ?: $this->stringValue($normalized['contact_name'] ?? null)
            ?: 'Website inquiry';

        return 'Public inquiry: '.$fallback;
    }

    private function activityBody(IntakeSubmission $submission, array $normalized): string
    {
        $lines = [
            'Form: '.$submission->form?->name,
            'Company: '.($this->stringValue($normalized['company_name'] ?? null) ?: '-'),
            'Contact: '.($this->stringValue($normalized['contact_name'] ?? null) ?: '-'),
            'Email: '.($this->stringValue($normalized['contact_email'] ?? null) ?: '-'),
            'Phone: '.($this->stringValue($normalized['contact_phone'] ?? null) ?: '-'),
            'Message:',
            $this->stringValue($normalized['message'] ?? null) ?: '-',
        ];

        if ($submission->attachments->isNotEmpty()) {
            $lines[] = 'Attachments: '.$submission->attachments->pluck('original_filename')->filter()->implode(', ');
        }

        return implode("\n", $lines);
    }

    private function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode(', ', array_filter(array_map('strval', $value))));
        }

        return trim((string) $value);
    }
}
