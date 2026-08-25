<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Contracts\Contracts;
use Illuminate\Support\Collection;

class BuildContractTermSnapshots
{
    private const SOURCE_FINGERPRINT_VERSION = 1;

    public const SNAPSHOT_FIELDS = [
        'terms_snapshot',
        'dpa_snapshot',
        'legal_snapshot',
        'sla_snapshot',
        'general_snapshot',
    ];

    /**
     * Build legal snapshots from the terms attached to all services in a contract.
     */
    public function handle(Contracts $contract): array
    {
        $contract->load(['sla', 'items.slaPolicy', 'items.service.serviceTerms.currentVersion']);

        $termsByType = $this->groupTermsByType($contract);

        return [
            'terms_snapshot' => $this->combine($termsByType['terms']),
            'dpa_snapshot' => $this->combine($termsByType['dpa']),
            'legal_snapshot' => $this->combine($termsByType['legal']),
            'sla_snapshot' => $this->combineSlaSnapshot($contract, $termsByType['sla']),
            'general_snapshot' => $this->combine($termsByType['general']),
        ];
    }

    /**
     * Group service terms into the contract snapshot buckets.
     */
    public function groupTermsByType(Contracts $contract): array
    {
        $contract->load(['items.service.serviceTerms.currentVersion']);

        $termsByType = [
            'terms' => collect(),
            'dpa' => collect(),
            'legal' => collect(),
            'sla' => collect(),
            'general' => collect(),
        ];

        foreach ($contract->items as $item) {
            if (! $item->service) {
                continue;
            }

            foreach ($item->service->serviceTerms as $term) {
                $type = $term->type ?: 'terms';

                if (! isset($termsByType[$type])) {
                    $type = 'terms';
                }

                if (! $termsByType[$type]->has($term->id)) {
                    $termsByType[$type]->put($term->id, $term);
                }
            }
        }

        return $termsByType;
    }

    /**
     * Fingerprint only the versioned catalogue/SLA sources that generate legal
     * snapshot text. Manually authored terms without a linked source do not
     * need a catalogue acknowledgement.
     */
    public function sourceFingerprint(Contracts $contract): ?string
    {
        $contract->load(['sla', 'items.slaPolicy', 'items.service.serviceTerms.currentVersion']);
        $termsByType = $this->groupTermsByType($contract);
        $termSources = [];

        foreach ($termsByType as $type => $terms) {
            foreach ($terms->sortKeys() as $term) {
                $version = $term->currentVersion;
                $content = trim((string) ($version?->content ?? $term->content));
                $sourceUrl = $version?->source_url ?? $term->source_url;

                if ($content === '' && blank($sourceUrl)) {
                    continue;
                }

                $versionLabel = $version?->version_label;
                if (blank($versionLabel)) {
                    $versionLabel = $term->origin === 'provider'
                        ? 'Unversioned'
                        : (string) ($term->versions()->count() + 1);
                }

                $termSources[] = [
                    'type' => $type,
                    'term_id' => (int) $term->id,
                    'name' => $version?->name ?? $term->name,
                    'issuer' => $version?->issuer ?? $term->issuer,
                    'version_label' => $versionLabel,
                    'content_checksum' => hash('sha256', $content),
                    'source_url' => $sourceUrl,
                    'effective_at' => $version?->effective_at?->toIso8601String(),
                    'provider_published_at' => $version?->provider_published_at?->toIso8601String(),
                ];
            }
        }

        $slaSources = collect();
        if ($contract->sla) {
            $slaSources->put('contract-'.$contract->sla->id, $this->slaFingerprint($contract->sla));
        }

        foreach ($contract->items as $item) {
            if (! $item->uses_contract_default_sla && $item->slaPolicy) {
                $slaSources->put('item-'.$item->slaPolicy->id, $this->slaFingerprint($item->slaPolicy));
            }
        }

        if ($termSources === [] && $slaSources->isEmpty()) {
            return null;
        }

        return hash('sha256', json_encode([
            'version' => self::SOURCE_FINGERPRINT_VERSION,
            'terms' => $termSources,
            'sla' => $slaSources->sortKeys()->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Fingerprint the exact Contract-owned text that a reviewer approved.
     */
    public function snapshotFingerprint(Contracts $contract): string
    {
        $snapshots = [];

        foreach (self::SNAPSHOT_FIELDS as $field) {
            $snapshots[$field] = $this->normalizeSnapshotText($contract->{$field});
        }

        return hash('sha256', json_encode([
            'version' => 1,
            'snapshots' => $snapshots,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Legacy sent contracts are safe to accept only when every catalogue/SLA
     * generated field still matches the Contract-owned text exactly. Fields
     * without a generated source remain explicit contract-authored wording.
     */
    public function contractSnapshotsMatchCurrentSources(Contracts $contract): bool
    {
        $generated = $this->handle($contract);
        $legacyGenerated = $this->handleWithoutVersionLabels($contract);

        foreach (self::SNAPSHOT_FIELDS as $field) {
            $source = $this->normalizeSnapshotText($generated[$field] ?? null);

            if ($source === '') {
                // Pre-metadata sent documents have no durable provenance for
                // Contract-authored text. If a populated field no longer has
                // a current source, require manual reconstruction instead of
                // assuming that a removed catalogue term was always manual.
                if ($this->normalizeSnapshotText($contract->{$field}) !== '') {
                    return false;
                }

                continue;
            }

            $contractChecksum = $this->contentChecksum($contract->{$field});
            $currentChecksum = $this->contentChecksum($source);
            $legacyChecksum = $this->contentChecksum($legacyGenerated[$field] ?? null);

            if (! hash_equals($currentChecksum, $contractChecksum)
                && ! hash_equals($legacyChecksum, $contractChecksum)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    public function sourceSnapshotChecksums(Contracts $contract): array
    {
        $generated = $this->handle($contract);
        $checksums = [];

        foreach (self::SNAPSHOT_FIELDS as $field) {
            $checksums[$field] = $this->contentChecksum($generated[$field] ?? null);
        }

        return $checksums;
    }

    public function contentChecksum(mixed $content): string
    {
        return hash('sha256', $this->normalizeSnapshotText($content));
    }

    /**
     * Older generated snapshots predate visible version annotations. Compare
     * that historical form without weakening checks for names or content.
     *
     * @return array<string, string>
     */
    private function handleWithoutVersionLabels(Contracts $contract): array
    {
        $contract->load(['sla', 'items.slaPolicy', 'items.service.serviceTerms.currentVersion']);
        $termsByType = $this->groupTermsByType($contract);

        return [
            'terms_snapshot' => $this->combine($termsByType['terms'], false),
            'dpa_snapshot' => $this->combine($termsByType['dpa'], false),
            'legal_snapshot' => $this->combine($termsByType['legal'], false),
            'sla_snapshot' => $this->combineSlaSnapshot($contract, $termsByType['sla'], false),
            'general_snapshot' => $this->combine($termsByType['general'], false),
        ];
    }

    private function normalizeSnapshotText(mixed $content): string
    {
        return trim(str_replace(
            ["\r\n", "\r"],
            "\n",
            (string) ($content ?? ''),
        ));
    }

    private function combine(Collection $terms, bool $includeVersionLabels = true): string
    {
        return $terms
            ->map(function ($term) use ($includeVersionLabels) {
                $version = $term->currentVersion;
                $content = trim((string) ($version?->content ?? $term->content));
                $sourceUrl = $version?->source_url ?? $term->source_url;

                if ($content === '' && blank($sourceUrl)) {
                    return null;
                }

                $header = trim((string) ($version?->name ?? $term->name));
                if ($includeVersionLabels && filled($version?->version_label)) {
                    $header .= ' (versjon '.$this->customerVersionLabel((string) $version->version_label).')';
                }

                return collect([$header, $content, filled($sourceUrl) ? 'Kilde: '.$sourceUrl : null])
                    ->filter()
                    ->implode("\n");
            })
            ->filter()
            ->unique()
            ->implode("\n\n---\n\n");
    }

    private function customerVersionLabel(string $version): string
    {
        $version = trim($version);

        return strcasecmp($version, 'Unversioned') === 0
            ? 'ikke versjonert'
            : $version;
    }

    private function combineSlaSnapshot(Contracts $contract, Collection $slaTerms, bool $includeVersionLabels = true): string
    {
        $parts = collect([$this->combine($slaTerms, $includeVersionLabels)])
            ->filter();

        $slaPolicies = collect();

        if ($contract->sla) {
            $slaPolicies->put('contract-'.$contract->sla->id, [
                'title' => 'Avtalens felles responspolicy: '.$contract->sla->name,
                'sla' => $contract->sla,
            ]);
        }

        foreach ($contract->items as $item) {
            if (! $item->uses_contract_default_sla && $item->slaPolicy) {
                $slaPolicies->put('item-'.$item->slaPolicy->id, [
                    'title' => 'Tjenestens responspolicy: '.$item->slaPolicy->name,
                    'sla' => $item->slaPolicy,
                ]);
            }
        }

        foreach ($slaPolicies as $policy) {
            $parts->push($this->formatSlaPolicy($policy['title'], $policy['sla']));
        }

        return $parts
            ->filter()
            ->unique()
            ->implode("\n\n---\n\n");
    }

    private function formatSlaPolicy(string $title, object $sla): string
    {
        $lines = [$title];

        if (! empty($sla->description)) {
            $lines[] = trim((string) $sla->description);
        }

        $lines[] = 'Lav prioritet: første respons '.$this->duration($sla->low_firstResponse, $sla->low_firstResponse_type).', oppmøte '.$this->duration($sla->low_onsite, $sla->low_onsite_type).'.';
        $lines[] = 'Middels prioritet: første respons '.$this->duration($sla->medium_firstResponse, $sla->medium_firstResponse_type).', oppmøte '.$this->duration($sla->medium_onsite, $sla->medium_onsite_type).'.';
        $lines[] = 'Høy prioritet: første respons '.$this->duration($sla->high_firstResponse, $sla->high_firstResponse_type).', oppmøte '.$this->duration($sla->high_onsite, $sla->high_onsite_type).'.';

        return implode("\n", array_filter($lines));
    }

    /** @return array<string, mixed> */
    private function slaFingerprint(object $sla): array
    {
        return collect([
            'id',
            'name',
            'description',
            'low_firstResponse',
            'low_firstResponse_type',
            'low_onsite',
            'low_onsite_type',
            'medium_firstResponse',
            'medium_firstResponse_type',
            'medium_onsite',
            'medium_onsite_type',
            'high_firstResponse',
            'high_firstResponse_type',
            'high_onsite',
            'high_onsite_type',
        ])->mapWithKeys(fn (string $field): array => [$field => $sla->{$field} ?? null])->all();
    }

    private function duration($value, $type): string
    {
        if ($value === null || $value === '') {
            return 'ikke angitt';
        }

        $unit = match (strtolower(trim((string) ($type ?: 'hours')))) {
            'minute', 'minutes', 'minutt', 'minutter' => 'minutter',
            'hour', 'hours', 'time', 'timer' => 'timer',
            'day', 'days', 'dag', 'dager' => 'dager',
            'business_day', 'business_days', 'business day', 'business days', 'virkedag', 'virkedager' => 'virkedager',
            'week', 'weeks', 'uke', 'uker' => 'uker',
            default => strtolower(trim((string) $type)),
        };

        return trim((string) $value.' '.$unit);
    }
}
