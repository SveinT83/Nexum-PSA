<?php

namespace App\Modules\Commercial\Services;

use App\Modules\Commercial\Models\Terms\TermVersion;
use App\Modules\Commercial\Models\Terms\terms;
use Illuminate\Support\Arr;

class LegalDocumentVersioning
{
    public function record(terms $term, array $document = []): TermVersion
    {
        $name = trim((string) ($document['name'] ?? $term->name));
        $type = (string) ($document['type'] ?? $term->type ?: 'terms');
        $issuer = filled($document['issuer'] ?? null)
            ? trim((string) $document['issuer'])
            : $term->issuer;
        $content = trim((string) ($document['content'] ?? $term->content ?? ''));
        $sourceUrl = filled($document['source_url'] ?? null)
            ? trim((string) $document['source_url'])
            : $term->source_url;
        $versionLabel = filled($document['version_label'] ?? null)
            ? trim((string) $document['version_label'])
            : ($term->origin === 'provider' ? 'Unversioned' : (string) ($term->versions()->count() + 1));

        $checksum = hash('sha256', json_encode([
            'name' => $name,
            'type' => $type,
            'issuer' => $issuer,
            'version_label' => $versionLabel,
            'content' => $content,
            'source_url' => $sourceUrl,
            'effective_at' => $document['effective_at'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $version = $term->versions()->firstOrCreate(
            ['checksum' => $checksum],
            [
                'name' => $name,
                'type' => $type,
                'issuer' => $issuer,
                'version_label' => $versionLabel,
                'content' => $content,
                'source_url' => $sourceUrl,
                'effective_at' => $document['effective_at'] ?? null,
                'provider_published_at' => $document['provider_published_at'] ?? null,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'metadata' => Arr::wrap($document['metadata'] ?? []),
            ]
        );

        if (! $version->wasRecentlyCreated) {
            $version->forceFill(['last_seen_at' => now()])->save();
        }

        $term->forceFill([
            'name' => $name,
            'type' => $type,
            'issuer' => $issuer,
            'content' => $content,
            'source_url' => $sourceUrl,
            'current_version_id' => $version->id,
            'sync_status' => 'current',
            'last_checked_at' => now(),
        ])->save();

        return $version->refresh();
    }
}
