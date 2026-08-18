<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailCanonicalMessage;
use App\Modules\Email\Models\EmailCanonicalMessageAttachment;
use App\Modules\Email\Models\EmailCanonicalMessageSource;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;

/**
 * Internal transaction helper. Callers must lock and authorize the complete source component before
 * invoking mapping methods; this writer never makes an authorization or correlation decision.
 */
final class EmailCanonicalProjectionWriter
{
    /** @param array<string,mixed> $snapshot */
    public function createProjection(EmailMessage $root, array $snapshot): EmailCanonicalMessage
    {
        /** @var array<string,mixed> $projection */
        $projection = $snapshot['projection'];
        $canonical = EmailCanonicalMessage::query()->create([
            'root_source_email_message_id' => $root->id,
            'algorithm_version' => EmailCanonicalCutoverEvidence::ALGORITHM_VERSION,
            'status' => EmailCanonicalMessage::STATUS_ACTIVE,
            ...$projection,
            'strict_evidence_hash' => $snapshot['strict_evidence_hash'],
            'root_projection_hash' => $snapshot['root_projection_hash'],
            'evidence_complete' => $snapshot['complete'],
            'source_count' => 1,
            'last_verified_at' => now(),
            'drifted_at' => null,
        ]);

        foreach ($snapshot['attachments'] as $position => $attachment) {
            EmailCanonicalMessageAttachment::query()->create([
                'canonical_email_message_id' => $canonical->id,
                'position' => $position + 1,
                ...$attachment,
                'created_at' => now(),
            ]);
        }

        return $canonical;
    }

    /** @param array<string,mixed> $snapshot */
    public function mapSource(
        EmailMessage $source,
        EmailCanonicalMessage $canonical,
        array $snapshot,
        string $mappingKind,
        ?User $actor,
    ): EmailCanonicalMessageSource {
        $mapping = EmailCanonicalMessageSource::query()->updateOrCreate([
            'source_email_message_id' => $source->id,
        ], [
            'canonical_email_message_id' => $canonical->id,
            'mapping_kind' => $mappingKind,
            'strict_evidence_hash' => $snapshot['strict_evidence_hash'],
            'source_state_hash' => $snapshot['source_state_hash'],
            'evidence_complete' => $snapshot['complete'],
            'mapped_by' => $actor?->id,
            'mapped_at' => now(),
        ]);

        EmailMailboxPlacement::query()
            ->where('email_message_id', $source->id)
            ->update(['canonical_email_message_id' => $canonical->id]);

        return $mapping;
    }

    /** @param list<int> $canonicalIds */
    public function refreshComponentCounts(array $canonicalIds): void
    {
        foreach (collect($canonicalIds)->filter()->unique()->sort()->values() as $canonicalId) {
            $canonical = EmailCanonicalMessage::query()->lockForUpdate()->find($canonicalId);
            if (! $canonical) {
                continue;
            }

            $count = EmailCanonicalMessageSource::query()
                ->where('canonical_email_message_id', $canonical->id)
                ->count();
            $canonical->forceFill([
                'source_count' => $count,
                'status' => $count === 0
                    ? EmailCanonicalMessage::STATUS_RETIRED
                    : EmailCanonicalMessage::STATUS_ACTIVE,
                'last_verified_at' => $count === 0 ? $canonical->last_verified_at : now(),
            ])->save();
        }
    }
}
