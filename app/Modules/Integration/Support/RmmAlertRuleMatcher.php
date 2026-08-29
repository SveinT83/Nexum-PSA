<?php

namespace App\Modules\Integration\Support;

use App\Modules\Integration\Models\RmmAlertOccurrence;
use Illuminate\Support\Str;

class RmmAlertRuleMatcher
{
    /** @return array{matched: bool, results: list<array<string, mixed>>} */
    public function evaluate(RmmAlertOccurrence $occurrence, array $conditions): array
    {
        $assetId = data_get($occurrence->context, 'asset_id');
        $clientId = data_get($occurrence->context, 'client_id');
        $results = [];

        if (array_key_exists('subject_contains', $conditions)) {
            $expected = (string) $conditions['subject_contains'];
            $results[] = $this->result(
                'subject_contains',
                $expected,
                Str::limit((string) $occurrence->title, 160),
                str_contains(mb_strtolower((string) $occurrence->title), mb_strtolower($expected)),
            );
        }
        if (array_key_exists('severities', $conditions)) {
            $expected = array_values((array) $conditions['severities']);
            $results[] = $this->result('severities', $expected, $occurrence->severity, in_array($occurrence->severity, $expected, true));
        }
        if (array_key_exists('asset_id', $conditions)) {
            $expected = (int) $conditions['asset_id'];
            $results[] = $this->result('asset_id', $expected, $assetId, $assetId !== null && (int) $assetId === $expected);
        }
        if (array_key_exists('client_id', $conditions)) {
            $expected = (int) $conditions['client_id'];
            $results[] = $this->result('client_id', $expected, $clientId, $clientId !== null && (int) $clientId === $expected);
        }
        if (array_key_exists('fingerprint', $conditions)) {
            $expected = (string) $conditions['fingerprint'];
            $results[] = $this->result('fingerprint', $expected, $occurrence->fingerprint, hash_equals($expected, $occurrence->fingerprint));
        }
        if (array_key_exists('integration_types', $conditions)) {
            $expected = array_values((array) $conditions['integration_types']);
            $results[] = $this->result(
                'integration_types',
                $expected,
                $occurrence->integration_type,
                in_array($occurrence->integration_type, $expected, true),
            );
        }

        return [
            'matched' => $results !== [] && ! collect($results)->contains(fn (array $result): bool => ! $result['matched']),
            'results' => $results,
        ];
    }

    /** @return array<string, mixed> */
    private function result(string $condition, mixed $expected, mixed $actual, bool $matched): array
    {
        return compact('condition', 'expected', 'actual', 'matched');
    }
}
