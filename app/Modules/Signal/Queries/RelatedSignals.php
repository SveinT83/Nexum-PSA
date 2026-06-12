<?php

namespace App\Modules\Signal\Queries;

use App\Models\Clients\Client;
use App\Modules\Contact\Models\Contact;
use App\Modules\Signal\Models\Signal;
use Illuminate\Support\Collection;

class RelatedSignals
{
    public function forClient(Client $client, int $limit = 10): Collection
    {
        return Signal::query()
            ->with(['contact', 'executions.rule'])
            ->where(function ($query) use ($client): void {
                $query->where('client_id', $client->id)
                    ->orWhere(fn ($subjectQuery) => $subjectQuery
                        ->where('subject_type', $client->getMorphClass())
                        ->where('subject_id', $client->id));
            })
            ->latest('occurred_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function forContact(Contact $contact, int $limit = 10): Collection
    {
        return Signal::query()
            ->with(['client', 'executions.rule'])
            ->where(function ($query) use ($contact): void {
                $query->where('contact_id', $contact->id)
                    ->orWhere(fn ($subjectQuery) => $subjectQuery
                        ->where('subject_type', $contact->getMorphClass())
                        ->where('subject_id', $contact->id));
            })
            ->latest('occurred_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
