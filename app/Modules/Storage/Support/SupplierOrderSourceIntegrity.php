<?php

namespace App\Modules\Storage\Support;

use Illuminate\Validation\ValidationException;

class SupplierOrderSourceIntegrity
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $trustedAuth
     * @return list<string>
     */
    public function errors(array $snapshot, string $fingerprint, array $trustedAuth): array
    {
        $errors = [];
        $actualFingerprint = StableJson::checksum($snapshot);
        if (strlen($fingerprint) !== 64 || ! hash_equals($actualFingerprint, $fingerprint)) {
            $errors[] = 'source_snapshot_fingerprint_mismatch';
        }

        $embeddedAuth = is_array($snapshot['trusted_auth'] ?? null)
            ? $snapshot['trusted_auth']
            : [];
        if (! hash_equals(
            StableJson::checksum($embeddedAuth),
            StableJson::checksum($trustedAuth),
        )) {
            $errors[] = 'trusted_auth_snapshot_mismatch';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $trustedAuth
     */
    public function validateOrFail(array $snapshot, string $fingerprint, array $trustedAuth): void
    {
        $errors = $this->errors($snapshot, $fingerprint, $trustedAuth);
        if ($errors === []) {
            return;
        }

        throw ValidationException::withMessages([
            'source_integrity' => $errors,
        ]);
    }
}
