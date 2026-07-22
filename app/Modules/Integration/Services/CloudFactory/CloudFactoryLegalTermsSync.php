<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Modules\Commercial\Models\Terms\terms;
use App\Modules\Commercial\Services\LegalDocumentVersioning;
use App\Modules\Integration\Models\CloudFactory\Offer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CloudFactoryLegalTermsSync
{
    public function __construct(private readonly LegalDocumentVersioning $versions)
    {
    }

    public function syncOffer(Offer $offer, ?array $product = null): void
    {
        $product ??= (array) data_get($offer->provider_payload, 'product', []);
        $documents = $this->extract($offer, $product);
        $seenTermIds = [];

        DB::transaction(function () use ($offer, $documents, &$seenTermIds): void {
            foreach ($documents as $document) {
                $term = terms::query()->updateOrCreate(
                    [
                        'source_integration_id' => $offer->integration_id,
                        'external_document_id' => $document['external_document_id'],
                    ],
                    [
                        'name' => $document['name'],
                        'type' => $document['type'],
                        'origin' => 'provider',
                        'issuer' => $document['issuer'],
                        'source_url' => $document['source_url'],
                        'managed_externally' => true,
                        'sync_status' => 'current',
                        'content' => $document['content'],
                        'last_checked_at' => now(),
                        'metadata' => [
                            'provider_family' => $offer->provider_family,
                            'external_product_id' => $offer->external_product_id,
                        ],
                    ]
                );

                $this->versions->record($term, $document);
                $seenTermIds[] = $term->id;

                DB::table('cloudfactory_offer_term')->updateOrInsert(
                    ['offer_id' => $offer->id, 'term_id' => $term->id],
                    ['is_active' => true, 'last_seen_at' => now(), 'updated_at' => now(), 'created_at' => now()]
                );

                if ($offer->service_id) {
                    DB::table('service_term_pivot')->updateOrInsert(
                        ['service_id' => $offer->service_id, 'term_id' => $term->id],
                        ['updated_at' => now(), 'created_at' => now()]
                    );
                }
            }

            $stale = DB::table('cloudfactory_offer_term')
                ->where('offer_id', $offer->id)
                ->when($seenTermIds !== [], fn ($query) => $query->whereNotIn('term_id', $seenTermIds))
                ->when($seenTermIds === [], fn ($query) => $query)
                ->pluck('term_id');

            DB::table('cloudfactory_offer_term')
                ->where('offer_id', $offer->id)
                ->when($seenTermIds !== [], fn ($query) => $query->whereNotIn('term_id', $seenTermIds))
                ->update(['is_active' => false, 'updated_at' => now()]);

            foreach ($stale as $termId) {
                $activeElsewhere = DB::table('cloudfactory_offer_term')
                    ->where('term_id', $termId)
                    ->where('is_active', true)
                    ->exists();

                if (! $activeElsewhere) {
                    terms::query()->whereKey($termId)->update([
                        'sync_status' => 'not_returned',
                        'last_checked_at' => now(),
                    ]);
                }
            }

            $payload = $offer->provider_payload ?? [];
            $payload['legal_sync'] = [
                'status' => $documents === [] ? 'not_supplied' : 'current',
                'document_count' => count($documents),
                'checked_at' => now()->toIso8601String(),
            ];
            $offer->forceFill(['provider_payload' => $payload])->save();
        });
    }

    private function extract(Offer $offer, array $product): array
    {
        $candidates = collect([
            data_get($product, 'legalDocuments'),
            data_get($product, 'legal.documents'),
            data_get($product, 'legalTerms'),
            data_get($product, 'terms'),
            data_get($product, 'termsOfService'),
            data_get($product, 'agreements'),
            data_get($product, 'attributes.legalDocuments'),
            data_get($product, 'attributes.legalTerms'),
            data_get($product, 'attributes.terms'),
            data_get($product, 'attributes.agreements'),
        ])->filter(fn ($value) => $value !== null);

        $documents = collect();

        foreach ($candidates as $candidate) {
            $records = is_array($candidate) && Arr::isAssoc($candidate)
                ? [$candidate]
                : Arr::wrap($candidate);

            foreach ($records as $record) {
                $document = $this->normalize($offer, $record);

                if ($document) {
                    $documents->put($document['external_document_id'], $document);
                }
            }
        }

        return $documents->values()->all();
    }

    private function normalize(Offer $offer, mixed $record): ?array
    {
        if (is_string($record)) {
            $record = trim($record);
            if (! Str::startsWith($record, ['http://', 'https://']) && mb_strlen($record) < 80) {
                return null;
            }

            $record = Str::startsWith($record, ['http://', 'https://'])
                ? ['url' => $record]
                : ['content' => $record];
        }

        if (! is_array($record)) {
            return null;
        }

        $sourceUrl = $this->first($record, ['url', 'uri', 'href', 'link', 'documentUrl']);
        $content = $this->first($record, ['content', 'text', 'body', 'description']);
        if (blank($sourceUrl) && blank($content)) {
            return null;
        }

        $name = $this->first($record, ['title', 'name', 'displayName', 'documentType'])
            ?: Str::title(($offer->provider_family ?: 'Provider').' terms');
        $external = $this->first($record, ['id', 'documentId', 'agreementId', 'termsId', 'code'])
            ?: $sourceUrl
            ?: $offer->external_product_id.'|'.Str::slug($name);
        $issuer = $this->first($record, ['issuer', 'publisher', 'provider'])
            ?: ($offer->vendor_name ?: Str::title((string) $offer->provider_family));

        return [
            'external_document_id' => Str::limit(
                ($offer->provider_family ?: 'provider').':'.hash('sha256', (string) $external),
                191,
                ''
            ),
            'name' => trim((string) $name),
            'type' => $this->type((string) $name, (string) ($record['type'] ?? '')),
            'issuer' => trim((string) $issuer),
            'version_label' => $this->first($record, ['version', 'versionId', 'revision']),
            'content' => trim((string) $content),
            'source_url' => filled($sourceUrl) ? trim((string) $sourceUrl) : null,
            'effective_at' => $this->first($record, ['effectiveAt', 'effectiveDate']),
            'provider_published_at' => $this->first($record, ['publishedAt', 'updatedAt']),
            'metadata' => ['provider_record' => $record],
        ];
    }

    private function first(array $record, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (filled(data_get($record, $key))) {
                return data_get($record, $key);
            }
        }

        return null;
    }

    private function type(string $name, string $type): string
    {
        $haystack = Str::lower($name.' '.$type);

        return match (true) {
            Str::contains($haystack, ['data processing', 'dpa', 'databehandler']) => 'dpa',
            Str::contains($haystack, ['service level', 'sla']) => 'sla',
            Str::contains($haystack, ['privacy', 'legal', 'license', 'licence']) => 'legal',
            Str::contains($haystack, 'general') => 'general',
            default => 'terms',
        };
    }
}
