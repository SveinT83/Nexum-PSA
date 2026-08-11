<?php

namespace App\Modules\Documentation\Actions;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Create a canonical supplier only from a verified Storage import identity.
 *
 * Storage owns import interpretation and policy. Documentation owns the Vendor
 * mutation and independently verifies the immutable import evidence before it
 * creates or reuses supplier master data.
 */
class CreateSupplierFromPurchaseImport
{
    public const MODE_REVIEW_CANDIDATE = 'review_candidate';

    public const MODE_ACTIVE = 'active';

    private const PROVENANCE_SCHEMA_VERSION = 1;

    /**
     * Create or resolve the supplier represented by an import identity claim.
     *
     * @param  array<string, mixed>  $evidence
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(array $evidence, string $mode, ?User $actor = null): Vendor
    {
        if (! in_array($mode, [self::MODE_REVIEW_CANDIDATE, self::MODE_ACTIVE], true)) {
            throw ValidationException::withMessages([
                'mode' => 'The supplier bootstrap mode is not supported.',
            ]);
        }

        $normalized = $this->validateAndNormalizeEvidence($evidence);

        return DB::transaction(function () use ($normalized, $mode, $actor): Vendor {
            $currentActor = $this->resolveAuthorizedActor($actor);

            if ($mode === self::MODE_ACTIVE && $currentActor === null) {
                throw new AuthorizationException(
                    'Active supplier bootstrap requires an explicit authorized actor.'
                );
            }

            if (
                $mode === self::MODE_REVIEW_CANDIDATE
                && $currentActor === null
                && $normalized['service_identity'] === null
            ) {
                throw ValidationException::withMessages([
                    'service_identity' => 'A service identity is required when no actor is supplied.',
                ]);
            }

            $import = DB::table('storage_purchase_order_imports')
                ->where('id', $normalized['source_import_id'])
                ->lockForUpdate()
                ->first();

            if ($import === null) {
                throw ValidationException::withMessages([
                    'source_import_id' => 'The source purchase import does not exist.',
                ]);
            }

            $storedFingerprint = strtolower(trim((string) $import->source_fingerprint));

            if (! hash_equals($storedFingerprint, $normalized['source_fingerprint'])) {
                throw ValidationException::withMessages([
                    'source_fingerprint' => 'The source fingerprint does not match the import ledger.',
                ]);
            }

            $trustedAuth = $this->normalizeAuthenticationSnapshot(
                $this->decodeJsonObject($import->trusted_auth_snapshot ?? null),
                'trusted_auth_snapshot'
            );

            if ($trustedAuth !== $normalized['authentication']) {
                throw ValidationException::withMessages([
                    'authentication' => 'The supplied authentication evidence does not match the import ledger.',
                ]);
            }

            $trustedIdentity = $trustedAuth['authentication_passed'] && $trustedAuth['aligned'];

            if ($mode === self::MODE_ACTIVE && ! $trustedIdentity) {
                throw ValidationException::withMessages([
                    'authentication' => 'Active supplier bootstrap requires passed and aligned sender authentication.',
                ]);
            }

            $identityClaim = $trustedIdentity
                ? $this->identityClaim($trustedAuth)
                : null;
            $identityHash = $identityClaim === null
                ? null
                : $this->identityHash($identityClaim);

            if ($identityHash !== null) {
                $existing = Vendor::query()
                    ->where('supplier_import_identity_hash', $identityHash)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->assertExactExistingIdentity(
                        $existing,
                        $identityClaim,
                        $normalized
                    );
                }
            }

            $sourceVendors = Vendor::query()
                ->where('created_from_purchase_import_id', $normalized['source_import_id'])
                ->lockForUpdate()
                ->get();

            if ($sourceVendors->isNotEmpty()) {
                if ($sourceVendors->count() === 1 && $identityHash === null) {
                    $existing = $sourceVendors->first();

                    if ($this->isExactUntrustedSourceCandidate($existing, $normalized)) {
                        return $existing;
                    }
                }

                throw ValidationException::withMessages([
                    'source_import_id' => 'The import is already linked to a different supplier identity claim.',
                ]);
            }

            $this->assertNoIdentifierConflict($normalized);

            $provenance = [
                'schema_version' => self::PROVENANCE_SCHEMA_VERSION,
                'source' => [
                    'purchase_import_id' => $normalized['source_import_id'],
                    'fingerprint' => $normalized['source_fingerprint'],
                ],
                'authentication' => $trustedAuth,
                'identity_claim' => $identityClaim,
                'bootstrap' => [
                    'mode' => $mode,
                    'actor_id' => $currentActor?->getKey(),
                    'service_identity' => $normalized['service_identity'],
                ],
            ];

            try {
                return Vendor::query()->create([
                    'name' => $normalized['supplier_name'],
                    'vendor_code' => $normalized['vendor_code'],
                    'org_no' => $normalized['org_no'],
                    'url' => $normalized['url'],
                    'email' => $normalized['email'],
                    'is_vendor' => true,
                    'is_supplier' => true,
                    'is_manufacturer' => false,
                    'is_active' => $mode === self::MODE_ACTIVE,
                    'created_from_purchase_import_id' => $normalized['source_import_id'],
                    'supplier_import_identity_hash' => $identityHash,
                    'supplier_bootstrap_status' => $mode,
                    'source_provenance' => $provenance,
                ]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                // A concurrent exact identity claim is idempotent. Other unique
                // collisions, including vendor_code, remain review conflicts.
                if ($identityHash !== null) {
                    $raced = Vendor::query()
                        ->where('supplier_import_identity_hash', $identityHash)
                        ->lockForUpdate()
                        ->first();

                    if ($raced !== null) {
                        return $this->assertExactExistingIdentity(
                            $raced,
                            $identityClaim,
                            $normalized
                        );
                    }
                }

                throw ValidationException::withMessages([
                    'supplier' => 'The supplier conflicts with an existing unique master-data value.',
                ]);
            }
        }, 3);
    }

    /**
     * Validate caller input and reduce it to the action's explicit contract.
     *
     * Extra input is intentionally discarded so raw bodies, headers, tokens,
     * and unrelated source data can never enter Vendor provenance.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function validateAndNormalizeEvidence(array $evidence): array
    {
        $validated = Validator::make($evidence, [
            'source_import_id' => ['required', 'integer', 'min:1'],
            'source_fingerprint' => ['required', 'string', 'regex:/\A[0-9a-fA-F]{64}\z/'],
            'supplier_name' => ['required', 'string', 'max:255'],
            'authentication_passed' => ['required', 'boolean'],
            'aligned' => ['required', 'boolean'],
            'authenticated_supplier_identity' => ['required', 'string', 'max:320'],
            'authenticated_supplier_domain' => ['required', 'string', 'max:253'],
            'authserv_id' => ['required', 'string', 'max:255'],
            'spf' => ['required', 'string', 'max:32'],
            'dkim' => ['required', 'string', 'max:32'],
            'dmarc' => ['required', 'string', 'max:32'],
            'org_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255'],
            'url' => ['sometimes', 'nullable', 'string', 'url', 'max:255'],
            'vendor_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service_identity' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();

        if (
            ! is_bool($evidence['authentication_passed'] ?? null)
            || ! is_bool($evidence['aligned'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'authentication' => 'Authentication state must use canonical boolean values.',
            ]);
        }

        $supplierName = $this->cleanText($validated['supplier_name'], 'supplier_name');
        $serviceIdentity = $this->optionalCleanText($validated, 'service_identity');

        if (
            $serviceIdentity !== null
            && ! preg_match('/\A[a-zA-Z0-9._:@\/-]+\z/', $serviceIdentity)
        ) {
            throw ValidationException::withMessages([
                'service_identity' => 'The service identity contains unsupported characters.',
            ]);
        }

        $url = $this->optionalCleanText($validated, 'url');

        if (
            $url !== null
            && ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
        ) {
            throw ValidationException::withMessages([
                'url' => 'The supplier URL must use HTTP or HTTPS.',
            ]);
        }

        if (
            $url !== null
            && (
                parse_url($url, PHP_URL_USER) !== null
                || parse_url($url, PHP_URL_PASS) !== null
            )
        ) {
            throw ValidationException::withMessages([
                'url' => 'The supplier URL must not contain credentials.',
            ]);
        }

        return [
            'source_import_id' => (int) $validated['source_import_id'],
            'source_fingerprint' => strtolower($validated['source_fingerprint']),
            'supplier_name' => $supplierName,
            'authentication' => $this->normalizeAuthenticationSnapshot($validated, 'authentication'),
            'org_no' => $this->optionalCleanText($validated, 'org_no'),
            'email' => $this->optionalCleanText($validated, 'email'),
            'url' => $url,
            'vendor_code' => $this->optionalCleanText($validated, 'vendor_code'),
            'service_identity' => $serviceIdentity,
        ];
    }

    /**
     * Normalize only the canonical trusted-auth keys.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, bool|string>
     */
    private function normalizeAuthenticationSnapshot(array $snapshot, string $errorKey): array
    {
        foreach ([
            'authentication_passed',
            'aligned',
            'authenticated_supplier_identity',
            'authenticated_supplier_domain',
            'authserv_id',
            'spf',
            'dkim',
            'dmarc',
        ] as $requiredKey) {
            if (! array_key_exists($requiredKey, $snapshot)) {
                throw ValidationException::withMessages([
                    $errorKey => "Missing canonical trusted-auth key: {$requiredKey}.",
                ]);
            }
        }

        if (
            ! is_bool($snapshot['authentication_passed'])
            || ! is_bool($snapshot['aligned'])
        ) {
            throw ValidationException::withMessages([
                $errorKey => 'Trusted-auth pass and alignment values must be booleans.',
            ]);
        }

        $identity = $this->normalizeIdentity(
            $snapshot['authenticated_supplier_identity'],
            $errorKey
        );
        $domain = $this->normalizeDomain(
            $snapshot['authenticated_supplier_domain'],
            $errorKey
        );
        $authservId = strtolower($this->cleanText($snapshot['authserv_id'], $errorKey));

        if (! preg_match('/\A[a-z0-9.-]+\z/', $authservId)) {
            throw ValidationException::withMessages([
                $errorKey => 'The authentication service identifier is invalid.',
            ]);
        }

        $results = [];

        foreach (['spf', 'dkim', 'dmarc'] as $resultKey) {
            $result = strtolower($this->cleanText($snapshot[$resultKey], $errorKey));

            if (! preg_match('/\A[a-z0-9_-]+\z/', $result)) {
                throw ValidationException::withMessages([
                    $errorKey => "The {$resultKey} result is invalid.",
                ]);
            }

            $results[$resultKey] = $result;
        }

        if ($snapshot['aligned'] && str_contains($identity, '@')) {
            $identityDomain = substr($identity, strrpos($identity, '@') + 1);

            if (! hash_equals($domain, $this->normalizeDomain($identityDomain, $errorKey))) {
                throw ValidationException::withMessages([
                    $errorKey => 'The authenticated identity is not aligned with its supplier domain.',
                ]);
            }
        }

        return [
            'authentication_passed' => $snapshot['authentication_passed'],
            'aligned' => $snapshot['aligned'],
            'authenticated_supplier_identity' => $identity,
            'authenticated_supplier_domain' => $domain,
            'authserv_id' => $authservId,
            'spf' => $results['spf'],
            'dkim' => $results['dkim'],
            'dmarc' => $results['dmarc'],
        ];
    }

    /**
     * Resolve the current actor from the database before checking authorization.
     */
    private function resolveAuthorizedActor(?User $actor): ?User
    {
        if ($actor === null) {
            return null;
        }

        if (! $actor->exists || $actor->getKey() === null) {
            throw new AuthorizationException('The supplier bootstrap actor is invalid.');
        }

        $current = User::query()
            ->whereKey($actor->getKey())
            ->lockForUpdate()
            ->first();

        $isSupplierOrderSystemActor = $current?->isSystemActor() === true
            && $current->system_actor_key === 'storage_supplier_order_automation';
        if (
            $current === null
            || (! $current->isActive() && ! $isSupplierOrderSystemActor)
            || ! $current->can('documentation.create')
        ) {
            throw new AuthorizationException(
                implode(' ', [
                    'The supplier bootstrap actor must be an active user or the protected supplier-order system actor',
                    'and have documentation.create.',
                ])
            );
        }

        return $current;
    }

    /**
     * @param  array<string, string>  $identityClaim
     * @param  array<string, mixed>  $normalized
     */
    private function assertExactExistingIdentity(
        Vendor $vendor,
        array $identityClaim,
        array $normalized
    ): Vendor {
        $storedClaim = $vendor->source_provenance['identity_claim'] ?? null;

        if (
            $storedClaim !== $identityClaim
            || ! $vendor->is_vendor
            || ! $vendor->is_supplier
            || ! in_array(
                $vendor->supplier_bootstrap_status,
                [self::MODE_REVIEW_CANDIDATE, self::MODE_ACTIVE],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'supplier' => 'The supplier identity claim conflicts with existing master data.',
            ]);
        }

        $this->assertExistingOptionalValuesMatch($vendor, $normalized);

        return $vendor;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function isExactUntrustedSourceCandidate(Vendor $vendor, array $normalized): bool
    {
        $provenance = $vendor->source_provenance ?? [];

        if (
            $vendor->supplier_import_identity_hash !== null
            || $vendor->supplier_bootstrap_status !== self::MODE_REVIEW_CANDIDATE
            || $vendor->is_active
            || ($provenance['source']['purchase_import_id'] ?? null) !== $normalized['source_import_id']
            || ($provenance['source']['fingerprint'] ?? null) !== $normalized['source_fingerprint']
            || ($provenance['authentication'] ?? null) !== $normalized['authentication']
            || $vendor->name !== $normalized['supplier_name']
        ) {
            return false;
        }

        $this->assertExistingOptionalValuesMatch($vendor, $normalized);

        return true;
    }

    /**
     * Exact identifiers may expose a duplicate, but never authorize a merge.
     *
     * @param  array<string, mixed>  $normalized
     */
    private function assertNoIdentifierConflict(array $normalized): void
    {
        foreach (['vendor_code', 'org_no', 'email', 'url'] as $field) {
            $value = $normalized[$field];

            if ($value === null) {
                continue;
            }

            $query = Vendor::query();

            if ($field === 'email') {
                $query->whereRaw('LOWER(email) = ?', [strtolower($value)]);
            } else {
                $query->where($field, $value);
            }

            if ($query->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    $field => "The supplier {$field} conflicts with existing master data.",
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function assertExistingOptionalValuesMatch(Vendor $vendor, array $normalized): void
    {
        foreach (['vendor_code', 'org_no', 'email', 'url'] as $field) {
            $provided = $normalized[$field];

            if ($provided === null) {
                continue;
            }

            $current = $vendor->{$field};
            $matches = $field === 'email'
                ? strtolower((string) $current) === strtolower($provided)
                : (string) $current === $provided;

            if (! $matches) {
                throw ValidationException::withMessages([
                    $field => "The supplier {$field} conflicts with the existing identity claim.",
                ]);
            }
        }
    }

    /**
     * @param  array<string, bool|string>  $trustedAuth
     * @return array<string, string>
     */
    private function identityClaim(array $trustedAuth): array
    {
        return [
            'version' => '1',
            'authenticated_supplier_identity' => $trustedAuth['authenticated_supplier_identity'],
            'authenticated_supplier_domain' => $trustedAuth['authenticated_supplier_domain'],
        ];
    }

    /**
     * @param  array<string, string>  $identityClaim
     */
    private function identityHash(array $identityClaim): string
    {
        return hash('sha256', json_encode(
            $identityClaim,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeIdentity(mixed $identity, string $errorKey): string
    {
        if (! is_string($identity)) {
            throw ValidationException::withMessages([
                $errorKey => 'The authenticated supplier identity must be a string.',
            ]);
        }

        $identity = strtolower($this->cleanText($identity, $errorKey));

        if (! preg_match('/\A[^\s<>]{1,320}\z/u', $identity)) {
            throw ValidationException::withMessages([
                $errorKey => 'The authenticated supplier identity is invalid.',
            ]);
        }

        if (str_contains($identity, '@') && filter_var($identity, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                $errorKey => 'The authenticated supplier email identity is invalid.',
            ]);
        }

        return $identity;
    }

    private function normalizeDomain(mixed $domain, string $errorKey): string
    {
        if (! is_string($domain)) {
            throw ValidationException::withMessages([
                $errorKey => 'The authenticated supplier domain must be a string.',
            ]);
        }

        $domain = rtrim(strtolower($this->cleanText($domain, $errorKey)), '.');

        if (preg_match('/[^\x20-\x7e]/', $domain)) {
            if (! function_exists('idn_to_ascii')) {
                throw ValidationException::withMessages([
                    $errorKey => 'International supplier domains require IDN support.',
                ]);
            }

            $domain = idn_to_ascii(
                $domain,
                IDNA_DEFAULT,
                INTL_IDNA_VARIANT_UTS46
            );
        }

        if (
            ! is_string($domain)
            || $domain === ''
            || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw ValidationException::withMessages([
                $errorKey => 'The authenticated supplier domain is invalid.',
            ]);
        }

        return strtolower($domain);
    }

    private function cleanText(mixed $value, string $errorKey): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $errorKey => 'The value must be text.',
            ]);
        }

        $value = trim($value);

        if ($value === '' || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
            throw ValidationException::withMessages([
                $errorKey => 'The value is empty or contains unsafe control characters.',
            ]);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function optionalCleanText(array $values, string $key): ?string
    {
        if (! array_key_exists($key, $values) || $values[$key] === null || $values[$key] === '') {
            return null;
        }

        return $this->cleanText($values[$key], $key);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
