<?php

namespace App\Modules\Intake\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Task\Models\Task;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkIntakeSubmissionTarget
{
    public const TARGET_CLIENT = 'client';
    public const TARGET_CONTACT = 'contact';
    public const TARGET_TICKET = 'ticket';
    public const TARGET_TASK = 'task';
    public const TARGET_SALES_OPPORTUNITY = 'sales_opportunity';

    public function handle(IntakeSubmission $submission, string $targetType, string $reference, ?User $actor = null): IntakeSubmission
    {
        $target = $this->findTarget($targetType, trim($reference));

        return DB::transaction(function () use ($submission, $targetType, $target, $actor, $reference): IntakeSubmission {
            $before = [
                'status' => $submission->status,
                'matched_client_id' => $submission->matched_client_id,
                'matched_site_id' => $submission->matched_site_id,
                'matched_contact_id' => $submission->matched_contact_id,
                'matched_client_user_id' => $submission->matched_client_user_id,
                'target_type' => $submission->target_type,
                'target_id' => $submission->target_id,
            ];

            $updates = [];
            $metadata = [
                'linked_target_type' => $targetType,
                'linked_target_class' => $target::class,
                'linked_target_id' => $target->getKey(),
                'reference' => $reference,
            ];

            if ($target instanceof Client) {
                $updates['matched_client_id'] = $target->id;
                $updates['matched_site_id'] = $this->defaultSite($target)?->id;
                $metadata['match_method'] = 'manual_client_link';
            } elseif ($target instanceof Contact) {
                $target->loadMissing('clientUser.site.client');
                $updates['matched_contact_id'] = $target->id;
                $updates['matched_client_user_id'] = $target->clientUser?->id;
                $updates['matched_site_id'] = $target->clientUser?->client_site_id;
                $updates['matched_client_id'] = $target->clientUser?->site?->client_id ?: $submission->matched_client_id;
                $metadata['match_method'] = 'manual_contact_link';
            } else {
                $updates['status'] = IntakeSubmission::STATUS_ROUTED;
                $updates['target_type'] = $target::class;
                $updates['target_id'] = $target->getKey();
                $updates['routing_result'] = $metadata + [
                    'action' => 'linked_existing_target',
                    'message' => 'Submission linked to existing '.$targetType.'.',
                ];
            }

            $submission->forceFill($updates)->save();

            $submission->events()->create([
                'actor_id' => $actor?->id,
                'type' => 'linked_existing_'.$targetType,
                'message' => 'Submission linked to existing '.str_replace('_', ' ', $targetType).'.',
                'before' => $before,
                'after' => [
                    'status' => $submission->status,
                    'matched_client_id' => $submission->matched_client_id,
                    'matched_site_id' => $submission->matched_site_id,
                    'matched_contact_id' => $submission->matched_contact_id,
                    'matched_client_user_id' => $submission->matched_client_user_id,
                    'target_type' => $submission->target_type,
                    'target_id' => $submission->target_id,
                ],
                'metadata' => $metadata,
            ]);

            return $submission->refresh();
        });
    }

    public static function targetLabels(): array
    {
        return [
            self::TARGET_CLIENT => 'Client',
            self::TARGET_CONTACT => 'Contact',
            self::TARGET_TICKET => 'Ticket',
            self::TARGET_TASK => 'Task',
            self::TARGET_SALES_OPPORTUNITY => 'Sales opportunity',
        ];
    }

    private function findTarget(string $targetType, string $reference): Model
    {
        if ($reference === '') {
            throw ValidationException::withMessages([
                'reference' => 'Enter an existing record reference.',
            ]);
        }

        $target = match ($targetType) {
            self::TARGET_CLIENT => $this->findClient($reference),
            self::TARGET_CONTACT => $this->findContact($reference),
            self::TARGET_TICKET => $this->findTicket($reference),
            self::TARGET_TASK => $this->findTask($reference),
            self::TARGET_SALES_OPPORTUNITY => $this->findSalesOpportunity($reference),
            default => null,
        };

        if (! $target) {
            throw ValidationException::withMessages([
                'reference' => 'No matching record was found.',
            ]);
        }

        return $target;
    }

    private function findClient(string $reference): ?Client
    {
        return Client::query()
            ->when(is_numeric($reference), fn ($query) => $query->where('id', (int) $reference))
            ->orWhere('client_number', $reference)
            ->orWhere('org_no', $reference)
            ->orWhereRaw('LOWER(name) = ?', [strtolower($reference)])
            ->first();
    }

    private function findContact(string $reference): ?Contact
    {
        return Contact::query()
            ->when(is_numeric($reference), fn ($query) => $query->where('id', (int) $reference))
            ->orWhereRaw('LOWER(display_name) = ?', [strtolower($reference)])
            ->orWhereHas('emails', fn ($query) => $query->where('email', $reference))
            ->first();
    }

    private function findTicket(string $reference): ?Ticket
    {
        return Ticket::query()
            ->when(is_numeric($reference), fn ($query) => $query->where('id', (int) $reference))
            ->orWhere('ticket_key', $reference)
            ->first();
    }

    private function findTask(string $reference): ?Task
    {
        if (! is_numeric($reference)) {
            return null;
        }

        return Task::query()->find((int) $reference);
    }

    private function findSalesOpportunity(string $reference): ?SalesOpportunity
    {
        return SalesOpportunity::query()
            ->when(is_numeric($reference), fn ($query) => $query->where('id', (int) $reference))
            ->orWhere('opportunity_key', $reference)
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
}
