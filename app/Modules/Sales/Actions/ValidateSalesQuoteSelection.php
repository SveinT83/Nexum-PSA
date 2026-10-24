<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Sales\Models\SalesQuoteVersion;
use Illuminate\Validation\ValidationException;

/** Do not silently ignore nested selection keys outside this exact quoted version. */
class ValidateSalesQuoteSelection
{
    public function handle(SalesQuoteVersion $version, array $data): void
    {
        foreach (['selected_line_ids', 'acknowledgement_ids'] as $field) {
            if (isset($data[$field]) && ! array_is_list($data[$field])) {
                throw ValidationException::withMessages([$field => 'Use a list of exact IDs from this quote version.']);
            }
        }
        $allowed = $version->lines()->pluck('id')->map(fn ($id) => (string) $id)->all();
        foreach (array_keys($data['quantities'] ?? []) as $id) {
            if (! ctype_digit((string) $id) || ! in_array((string) $id, $allowed, true)) {
                throw ValidationException::withMessages(['quantities' => 'Quantity keys must identify lines from this quote version.']);
            }
        }
    }
}
