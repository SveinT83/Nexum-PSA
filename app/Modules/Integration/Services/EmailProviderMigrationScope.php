<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;

final class EmailProviderMigrationScope
{
    /** @param array<int, mixed> $accountIds
     * @return list<int>
     */
    public function normalize(array $accountIds): array
    {
        $ids = collect($accountIds)
            ->filter(fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($ids === [] || count($ids) > 100) {
            throw new EmailProviderSecurityException('migration_scope_invalid');
        }

        return $ids;
    }

    /** @param list<int> $accountIds */
    public function fingerprint(array $accountIds): string
    {
        return hash_hmac(
            'sha256',
            json_encode($accountIds, JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
    }
}
