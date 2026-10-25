<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Terms\terms;
use App\Modules\Commercial\Services\LegalDocumentVersioning;
use App\Modules\Commercial\Support\CommercialInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** One versioned legal mutation shared by browser and API. */
class LegalCatalogueWorkflow
{
    public function save(Request $request, ?terms $term = null): terms
    {
        return DB::transaction(function () use ($request, $term) {
            if ($term) {
                $term = terms::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();
            }
            abort_if($term?->isProviderManaged(), 403, 'Provider-managed legal documents are read-only.');
            $data = CommercialInput::validate($request, ['name' => 'required|string|max:255', 'type' => 'required|string|max:100', 'content' => 'required|string'], array_merge($term?->only(['name', 'type', 'content']) ?? [], $request->all()));
            if ($term) {
                $term->update($data);
            } else {
                $term = terms::query()->create($data + ['origin' => 'nexum', 'managed_externally' => false]);
            }
            app(LegalDocumentVersioning::class)->record($term, $data);

            return $term->refresh();
        });
    }

    public function delete(terms $term): void
    {
        abort_if($term->isProviderManaged(), 403, 'Provider-managed legal documents cannot be deleted manually.');
        abort_if($term->isInUse(), 409, 'This legal document is currently in use.');
        $term->delete();
    }
}
