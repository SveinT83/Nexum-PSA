<?php

namespace App\Modules\Ticket\Services;

use App\Models\Settings\CommonSetting;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMergeSuggestionDismissal;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TicketMergeSuggestionService
{
    public function settings(): array
    {
        return [
            'ai_merge_enabled' => $this->enabled(),
            'ai_similarity_threshold' => $this->threshold(),
        ];
    }

    public function enabled(): bool
    {
        return CommonSetting::where('type', 'ticket_merge')
            ->where('name', 'ai_merge_enabled')
            ->value('value') === '1';
    }

    public function threshold(): int
    {
        $value = (int) CommonSetting::where('type', 'ticket_merge')
            ->where('name', 'ai_similarity_threshold')
            ->value('value');

        return min(100, max(70, $value ?: 90));
    }

    public function suggestionsForIndex(int $limit = 5): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }

        $threshold = $this->threshold();
        $dismissedPairs = $this->dismissedPairs();
        $tickets = Ticket::query()
            ->with(['client:id,name', 'contact:id,name,email', 'status:id,name,is_closed'])
            ->whereNull('merged_into_ticket_id')
            ->whereHas('status', fn ($query) => $query->where('is_closed', false))
            ->latest('updated_at')
            ->limit(100)
            ->get();

        $pairSuggestions = collect();

        for ($left = 0; $left < $tickets->count(); $left++) {
            for ($right = $left + 1; $right < $tickets->count(); $right++) {
                $first = $tickets[$left];
                $second = $tickets[$right];

                if (! $this->canCompare($first, $second)) {
                    continue;
                }

                if ($dismissedPairs->contains($this->pairKey($first, $second))) {
                    continue;
                }

                $confidence = $this->confidence($first, $second);

                if ($confidence < $threshold) {
                    continue;
                }

                [$target, $source] = $this->targetAndSource($first, $second);

                $pairSuggestions->push([
                    'tickets' => collect([$target, $source]),
                    'confidence' => $confidence,
                    'reason' => $this->reason($first, $second, $confidence),
                    'details' => $this->details($first, $second),
                ]);
            }
        }

        $usedTicketIds = collect();

        return $this->clusterSuggestions($pairSuggestions)
            ->sortByDesc('confidence')
            ->filter(function (array $suggestion) use ($usedTicketIds) {
                $ticketIds = $suggestion['tickets']->pluck('id');

                if ($ticketIds->intersect($usedTicketIds)->isNotEmpty()) {
                    return false;
                }

                $ticketIds->each(fn (int $id) => $usedTicketIds->push($id));

                return true;
            })
            ->take($limit)
            ->values();
    }

    private function clusterSuggestions(Collection $pairSuggestions): Collection
    {
        $clusters = collect();

        foreach ($pairSuggestions->sortByDesc('confidence') as $pair) {
            $pairTicketIds = $pair['tickets']->pluck('id');
            $matchingClusterIndexes = $clusters
                ->keys()
                ->filter(fn ($index) => $clusters[$index]['tickets']->pluck('id')->intersect($pairTicketIds)->isNotEmpty())
                ->values();

            if ($matchingClusterIndexes->isEmpty()) {
                $clusters->push([
                    'tickets' => $pair['tickets'],
                    'confidence' => $pair['confidence'],
                    'reasons' => collect([$pair['reason']]),
                    'details' => collect([$pair['details']]),
                ]);

                continue;
            }

            $primaryIndex = $matchingClusterIndexes->first();
            $cluster = $clusters[$primaryIndex];
            $cluster['tickets'] = $cluster['tickets']
                ->merge($pair['tickets'])
                ->unique('id')
                ->values();
            $cluster['confidence'] = min($cluster['confidence'], $pair['confidence']);
            $cluster['reasons'] = $cluster['reasons']->push($pair['reason'])->unique()->values();
            $cluster['details'] = $cluster['details']->push($pair['details'])->unique()->values();

            foreach ($matchingClusterIndexes->skip(1)->reverse() as $index) {
                $cluster['tickets'] = $cluster['tickets']->merge($clusters[$index]['tickets'])->unique('id')->values();
                $cluster['confidence'] = min($cluster['confidence'], $clusters[$index]['confidence']);
                $cluster['reasons'] = $cluster['reasons']->merge($clusters[$index]['reasons'])->unique()->values();
                $cluster['details'] = $cluster['details']->merge($clusters[$index]['details'])->unique()->values();
                $clusters->forget($index);
            }

            $clusters[$primaryIndex] = $cluster;
            $clusters = $clusters->values();
        }

        return $clusters
            ->filter(fn (array $cluster) => $cluster['tickets']->count() > 1)
            ->map(function (array $cluster) {
                $target = $this->primaryTicket($cluster['tickets']);
                $sources = $cluster['tickets']
                    ->reject(fn (Ticket $ticket) => $ticket->id === $target->id)
                    ->sortByDesc('updated_at')
                    ->values();

                return [
                    'tickets' => $cluster['tickets'],
                    'target' => $target,
                    'source' => $sources->first(),
                    'sources' => $sources,
                    'confidence' => $cluster['confidence'],
                    'reason' => $cluster['reasons']->take(3)->implode('; '),
                    'details' => $this->clusterDetails($cluster),
                ];
            })
            ->values();
    }

    private function canCompare(Ticket $first, Ticket $second): bool
    {
        if ($first->id === $second->id) {
            return false;
        }

        if ($first->client_id && $second->client_id && $first->client_id !== $second->client_id) {
            return false;
        }

        if (! $first->client_id && ! $second->client_id && $first->contact_id && $second->contact_id && $first->contact_id !== $second->contact_id) {
            return false;
        }

        return filled($this->comparisonText($first)) && filled($this->comparisonText($second));
    }

    private function confidence(Ticket $first, Ticket $second): int
    {
        $firstSubject = $this->normalizeSubject($first->subject);
        $secondSubject = $this->normalizeSubject($second->subject);

        if ($firstSubject !== '' && $firstSubject === $secondSubject) {
            return 100;
        }

        $sharedReferences = $this->sharedReferenceTokens($first->subject, $second->subject);

        similar_text($firstSubject, $secondSubject, $subjectPercent);
        similar_text($this->comparisonText($first), $this->comparisonText($second), $bodyPercent);

        $context = 0;

        if ($first->client_id && $first->client_id === $second->client_id) {
            $context += 3;
        }

        if ($first->contact_id && $first->contact_id === $second->contact_id) {
            $context += 2;
        }

        if ($sharedReferences->isNotEmpty()) {
            $context += 25;
        }

        return (int) round(min(100, ($subjectPercent * 0.6) + ($bodyPercent * 0.35) + $context));
    }

    private function comparisonText(Ticket $ticket): string
    {
        return trim($this->normalizeSubject($ticket->subject).' '.$this->normalize($ticket->description ?? ''));
    }

    private function normalizeSubject(?string $value): string
    {
        $subject = (string) Str::of($value ?? '')
            ->replaceMatches('/^\s*(?:(?:re|fw|fwd)\s*:\s*)+/iu', '')
            ->replaceMatches('/\[(?:td|nexum)-\d{4}-\d+\]/iu', '');

        return $this->normalize($subject);
    }

    private function normalize(?string $value): string
    {
        return (string) Str::of($value ?? '')
            ->lower()
            ->replaceMatches('/[^\pL\pN\s]+/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim();
    }

    private function targetAndSource(Ticket $first, Ticket $second): array
    {
        $target = $this->primaryTicket(collect([$first, $second]));

        return $target->id === $first->id
            ? [$first, $second]
            : [$second, $first];
    }

    private function primaryTicket(Collection $tickets): Ticket
    {
        return $tickets
            ->sortBy(fn (Ticket $ticket) => sprintf('%s-%010d', $ticket->created_at?->timestamp ?? 0, $ticket->id))
            ->first();
    }

    private function sharedReferenceTokens(?string $firstSubject, ?string $secondSubject): Collection
    {
        $firstTokens = $this->referenceTokens($firstSubject);
        $secondTokens = $this->referenceTokens($secondSubject);

        return $firstTokens->intersect($secondTokens)->values();
    }

    private function dismissedPairs(): Collection
    {
        return TicketMergeSuggestionDismissal::query()
            ->get(['first_ticket_id', 'second_ticket_id'])
            ->map(fn (TicketMergeSuggestionDismissal $dismissal) => $dismissal->first_ticket_id.':'.$dismissal->second_ticket_id);
    }

    private function pairKey(Ticket $first, Ticket $second): string
    {
        $ids = [$first->id, $second->id];
        sort($ids);

        return $ids[0].':'.$ids[1];
    }

    private function referenceTokens(?string $subject): Collection
    {
        $subject = (string) Str::of($subject ?? '')
            ->replaceMatches('/\[(?:td|nexum)-\d{4}-\d+\]/iu', ' ');

        preg_match_all('/\b(?:[a-z]{1,12}[-_\s]?\d{3,}|\d{5,}|(?=[a-z0-9-]*[a-z])(?=[a-z0-9-]*\d)[a-z0-9][a-z0-9-]{5,31})\b/iu', $subject, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $token) => (string) Str::of($token)->lower()->replaceMatches('/\s+/', '')->replace('_', '-'))
            ->filter()
            ->unique()
            ->values();
    }

    private function reason(Ticket $first, Ticket $second, int $confidence): string
    {
        $parts = [$confidence.'% match'];

        if ($this->normalizeSubject($first->subject) !== '' && $this->normalizeSubject($first->subject) === $this->normalizeSubject($second->subject)) {
            $parts[] = 'same normalized subject';
        }

        $sharedReferences = $this->sharedReferenceTokens($first->subject, $second->subject);

        if ($sharedReferences->isNotEmpty()) {
            $parts[] = 'same reference '.$sharedReferences->implode(', ');
        }

        if ($first->client_id && $first->client_id === $second->client_id) {
            $parts[] = 'same client';
        }

        if ($first->contact_id && $first->contact_id === $second->contact_id) {
            $parts[] = 'same contact';
        }

        return implode(', ', $parts);
    }

    private function details(Ticket $first, Ticket $second): string
    {
        $firstSubject = $this->normalizeSubject($first->subject);
        $secondSubject = $this->normalizeSubject($second->subject);

        if ($firstSubject !== '' && $firstSubject === $secondSubject) {
            return 'Subject matches after removing reply prefixes and internal ticket references.';
        }

        $sharedReferences = $this->sharedReferenceTokens($first->subject, $second->subject);

        if ($sharedReferences->isNotEmpty()) {
            return 'Both subjects contain the same reference: '.$sharedReferences->implode(', ').'.';
        }

        if ($first->client_id && $first->client_id === $second->client_id) {
            return 'Same client and similar subject/body text.';
        }

        return 'Similar subject/body text.';
    }

    private function clusterDetails(array $cluster): string
    {
        if ($cluster['tickets']->count() > 2) {
            return $cluster['tickets']->count().' tickets appear related. '.$cluster['details']->first();
        }

        return $cluster['details']->first();
    }
}
