<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Terms\ContractTermSnapshot;
use App\Modules\Commercial\Services\LegalDocumentVersioning;
use Illuminate\Support\Facades\DB;

class CaptureContractTermVersions
{
    private const CONTRACT_SNAPSHOT_DEFINITIONS = [
        'terms_snapshot' => ['type' => 'terms', 'name' => 'Alminnelige avtalevilkår'],
        'dpa_snapshot' => ['type' => 'dpa', 'name' => 'Databehandleravtale'],
        'legal_snapshot' => ['type' => 'legal', 'name' => 'Juridiske vilkår og personvern'],
        'sla_snapshot' => ['type' => 'sla', 'name' => 'Support og responstid'],
        'general_snapshot' => ['type' => 'general', 'name' => 'Generelle merknader'],
    ];

    public function __construct(
        private readonly LegalDocumentVersioning $versions,
        private readonly BuildContractTermSnapshots $builder,
    ) {}

    public function replace(Contracts $contract): void
    {
        $contract->load(['items.service.serviceTerms.currentVersion']);

        DB::transaction(function () use ($contract): void {
            ContractTermSnapshot::query()->where('contract_id', $contract->id)->delete();

            foreach ($contract->items as $item) {
                if (! $item->service) {
                    continue;
                }

                foreach ($item->service->serviceTerms as $term) {
                    $version = $term->currentVersion
                        ?? $this->versions->record($term);

                    ContractTermSnapshot::query()->create([
                        'contract_id' => $contract->id,
                        'contract_item_id' => $item->id,
                        'term_id' => $term->id,
                        'term_version_id' => $version->id,
                        'name' => $version->name,
                        'type' => $version->type,
                        'origin' => $term->origin ?: 'nexum',
                        'issuer' => $version->issuer,
                        'version_label' => $version->version_label,
                        'content' => $version->content,
                        'source_url' => $version->source_url,
                        'checksum' => $version->checksum,
                        'metadata' => [
                            'service_id' => $item->service_id,
                            'cloudfactory_offer_id' => $item->cloudfactory_offer_id,
                        ],
                    ]);
                }
            }

            $this->captureContractOwnedSnapshots($contract);
        });

        $contract->unsetRelation('termSnapshots');
    }

    /**
     * Preserve manually reviewed wording as its own version instead of naming
     * it after a catalogue version whose content is different.
     */
    private function captureContractOwnedSnapshots(Contracts $contract): void
    {
        $generated = $this->builder->handle($contract);
        $review = data_get($contract->approval_metadata, 'customer_document_terms', []);
        $reviewedSourceChecksums = is_array($review)
            ? ($review['source_snapshot_checksums'] ?? [])
            : [];

        foreach (self::CONTRACT_SNAPSHOT_DEFINITIONS as $field => $definition) {
            $content = (string) ($contract->{$field} ?? '');

            if (trim($content) === '') {
                continue;
            }

            $generatedChecksum = is_string($reviewedSourceChecksums[$field] ?? null)
                ? $reviewedSourceChecksums[$field]
                : $this->builder->contentChecksum($generated[$field] ?? null);
            $contractChecksum = $this->builder->contentChecksum($content);

            if (hash_equals($generatedChecksum, $contractChecksum)) {
                continue;
            }

            ContractTermSnapshot::query()->create([
                'contract_id' => $contract->id,
                'contract_item_id' => null,
                'term_id' => null,
                'term_version_id' => null,
                'name' => $definition['name'],
                'type' => $definition['type'],
                'origin' => 'contract',
                'issuer' => null,
                'version_label' => '1 (kontraktsspesifikk)',
                'content' => $content,
                'source_url' => null,
                'checksum' => $contractChecksum,
                'metadata' => [
                    'contract_snapshot_field' => $field,
                    'reviewed_at' => is_array($review) ? ($review['reviewed_at'] ?? null) : null,
                    'reviewed_by_user_id' => is_array($review) ? ($review['reviewed_by_user_id'] ?? null) : null,
                ],
            ]);
        }
    }
}
