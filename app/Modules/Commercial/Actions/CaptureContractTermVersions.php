<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Terms\ContractTermSnapshot;
use App\Modules\Commercial\Services\LegalDocumentVersioning;
use Illuminate\Support\Facades\DB;

class CaptureContractTermVersions
{
    public function __construct(private readonly LegalDocumentVersioning $versions)
    {
    }

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
        });
    }
}
