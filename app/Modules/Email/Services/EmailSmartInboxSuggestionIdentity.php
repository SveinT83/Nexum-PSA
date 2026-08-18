<?php

namespace App\Modules\Email\Services;

use JsonException;

final class EmailSmartInboxSuggestionIdentity
{
    /**
     * Produce a deterministic checksum without persisting the material used to
     * calculate it. Associative keys are sorted while list order is preserved.
     *
     * @throws JsonException
     */
    public function checksum(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
