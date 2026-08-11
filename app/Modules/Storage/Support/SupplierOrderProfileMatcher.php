<?php

namespace App\Modules\Storage\Support;

use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;

class SupplierOrderProfileMatcher
{
    public function __construct(
        private SupplierOrderDocumentNormalizer $normalizer,
        private SupplierOrderProfileDefinitionValidator $definitionValidator,
    ) {}

    /** @param array<string, mixed> $sourceSnapshot */
    public function match(array $sourceSnapshot): SupplierOrderProfileMatchResult
    {
        $document = $this->normalizer->normalize($sourceSnapshot);
        $matches = PurchaseOrderImportProfile::query()
            ->with('activeVersion')
            ->whereIn('lifecycle_state', [
                PurchaseOrderImportProfile::STATE_ACTIVE,
                PurchaseOrderImportProfile::STATE_SHADOW,
                PurchaseOrderImportProfile::STATE_DEGRADED,
            ])
            ->whereNotNull('active_version_id')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->filter(function (PurchaseOrderImportProfile $profile) use ($document): bool {
                return $profile->activeVersion !== null
                    && $this->profileMatchesDocument($profile, $profile->activeVersion, $document);
            })
            ->values();

        if ($matches->isEmpty()) {
            return new SupplierOrderProfileMatchResult(
                status: SupplierOrderProfileMatchResult::STATUS_NONE,
                reasonCode: 'profile_not_matched',
            );
        }

        $priority = (int) $matches->min('priority');
        $preferred = $matches->filter(
            fn (PurchaseOrderImportProfile $profile): bool => (int) $profile->priority === $priority,
        )->values();

        if ($preferred->count() !== 1) {
            return new SupplierOrderProfileMatchResult(
                status: SupplierOrderProfileMatchResult::STATUS_AMBIGUOUS,
                candidateProfileIds: $preferred->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                reasonCode: 'profile_priority_tie',
            );
        }

        /** @var PurchaseOrderImportProfile $profile */
        $profile = $preferred->first();

        return new SupplierOrderProfileMatchResult(
            status: SupplierOrderProfileMatchResult::STATUS_MATCHED,
            profile: $profile,
            version: $profile->activeVersion,
            candidateProfileIds: [(int) $profile->id],
            reasonCode: 'unique_lowest_priority',
        );
    }

    /**
     * Recheck the complete source scope for an explicitly pinned profile/version.
     *
     * A Signal rule may select a profile directly, but that configuration is not
     * evidence that the source message belongs to the supplier. Processing must
     * therefore repeat the same mailbox, sender and authentication gates used by
     * automatic matching before any extraction or domain write is attempted.
     *
     * @param  array<string, mixed>  $sourceSnapshot
     */
    public function matches(
        PurchaseOrderImportProfile $profile,
        PurchaseOrderImportProfileVersion $version,
        array $sourceSnapshot,
    ): bool {
        return $this->profileMatchesDocument($profile, $version, $this->normalizer->normalize($sourceSnapshot));
    }

    private function profileMatchesDocument(
        PurchaseOrderImportProfile $profile,
        PurchaseOrderImportProfileVersion $version,
        SupplierOrderNormalizedDocument $document,
    ): bool {
        if ((int) $version->profile_id !== (int) $profile->id
            || ! in_array($profile->lifecycle_state, [
                PurchaseOrderImportProfile::STATE_ACTIVE,
                PurchaseOrderImportProfile::STATE_SHADOW,
                PurchaseOrderImportProfile::STATE_DEGRADED,
            ], true)
            || ! is_array($version->definition)) {
            return false;
        }

        $definition = $version->definition;
        $scope = (array) ($definition['match'] ?? []);

        return $this->definitionValidator->validate($definition)->valid()
            && $this->scopeMatches($scope, $document);
    }

    /** @param array<string, mixed> $scope */
    private function scopeMatches(array $scope, SupplierOrderNormalizedDocument $document): bool
    {
        $facts = $document->sourceFacts;
        if (! $this->exactIdSelector($scope['account_ids'] ?? [], [$facts['account_id'] ?? null])) {
            return false;
        }
        if (! $this->exactSelector($scope['mailboxes'] ?? [], [(string) ($facts['mailbox'] ?? '')])) {
            return false;
        }
        if (! $this->exactSelector($scope['recipients'] ?? [], (array) ($facts['recipients'] ?? []))) {
            return false;
        }
        if (! $this->exactSelector($scope['senders'] ?? [], [(string) ($facts['from_email'] ?? '')])) {
            return false;
        }
        if (! $this->exactSelector($scope['sender_domains'] ?? [], [(string) ($facts['from_domain'] ?? '')])) {
            return false;
        }
        if (! $this->literalMarker($scope['subject_markers'] ?? [], (string) ($facts['subject'] ?? ''))) {
            return false;
        }
        if (! $this->literalMarker($scope['body_markers'] ?? [], $document->searchText)) {
            return false;
        }

        $trustedAuth = (array) ($facts['trusted_auth'] ?? []);
        if (($scope['require_trusted_auth'] ?? false)
            && ! (bool) ($trustedAuth['authentication_passed'] ?? false)) {
            return false;
        }
        if (($scope['require_aligned'] ?? false) && ! (bool) ($trustedAuth['aligned'] ?? false)) {
            return false;
        }

        $authenticatedDomains = (array) ($scope['authenticated_supplier_domains'] ?? []);
        if ($authenticatedDomains !== []) {
            if (! (bool) ($trustedAuth['authentication_passed'] ?? false)) {
                return false;
            }
            if (! $this->exactSelector(
                $authenticatedDomains,
                [(string) ($trustedAuth['authenticated_supplier_domain'] ?? '')],
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * Empty configuration means this selector dimension is unrestricted.
     *
     * @param  array<int, mixed>  $configured
     * @param  array<int, mixed>  $actual
     */
    private function exactSelector(array $configured, array $actual): bool
    {
        if ($configured === []) {
            return true;
        }

        $allowed = collect($configured)
            ->filter(fn (mixed $value): bool => is_string($value))
            ->map(fn (string $value): string => mb_strtolower(trim($value)))
            ->filter()
            ->all();
        $values = collect($actual)
            ->filter(fn (mixed $value): bool => is_string($value))
            ->map(fn (string $value): string => mb_strtolower(trim($value)))
            ->filter()
            ->all();

        return array_intersect($allowed, $values) !== [];
    }

    /**
     * Empty account configuration is unrestricted; configured IDs are exact.
     *
     * @param  array<int, mixed>  $configured
     * @param  array<int, mixed>  $actual
     */
    private function exactIdSelector(array $configured, array $actual): bool
    {
        if ($configured === []) {
            return true;
        }

        $allowed = collect($configured)->filter(fn (mixed $value): bool => is_int($value) && $value > 0)->all();
        $values = collect($actual)->filter(fn (mixed $value): bool => is_int($value) && $value > 0)->all();

        return array_intersect($allowed, $values) !== [];
    }

    /** @param array<int, mixed> $markers */
    private function literalMarker(array $markers, string $haystack): bool
    {
        if ($markers === []) {
            return true;
        }

        return collect($markers)->contains(
            fn (mixed $marker): bool => is_string($marker)
                && trim($marker) !== ''
                && mb_stripos($haystack, trim($marker)) !== false,
        );
    }
}
