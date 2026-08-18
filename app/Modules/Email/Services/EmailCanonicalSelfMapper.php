<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailCanonicalMessage;
use App\Modules\Email\Models\EmailCanonicalMessageSource;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates or refreshes only a one-source projection. It is safe for ordinary ingestion because it
 * cannot correlate two messages, widen authorization, or mutate provider/workflow state.
 */
final class EmailCanonicalSelfMapper
{
    public function __construct(
        private readonly EmailCanonicalCutoverEvidence $evidence,
        private readonly EmailCanonicalProjectionWriter $writer,
    ) {}

    public function map(EmailMessage $message): ?EmailCanonicalMessageSource
    {
        if (! Schema::hasTable('email_canonical_messages')
            || ! Schema::hasTable('email_canonical_message_sources')
            || ! Schema::hasColumn('email_mailbox_placements', 'canonical_email_message_id')) {
            return null;
        }

        return DB::transaction(function () use ($message): ?EmailCanonicalMessageSource {
            $source = EmailMessage::query()
                ->with(['account:id,address', 'attachments'])
                ->lockForUpdate()
                ->find($message->id);
            if (! $source) {
                return null;
            }

            EmailMailboxPlacement::query()
                ->where('email_message_id', $source->id)
                ->lockForUpdate()
                ->get(['id']);
            $mapping = EmailCanonicalMessageSource::query()
                ->where('source_email_message_id', $source->id)
                ->lockForUpdate()
                ->first();
            $snapshot = $this->evidence->forMessage($source);

            if ($mapping) {
                $canonical = EmailCanonicalMessage::query()->lockForUpdate()->find($mapping->canonical_email_message_id);
                $componentCount = $canonical
                    ? EmailCanonicalMessageSource::query()
                        ->where('canonical_email_message_id', $canonical->id)
                        ->count()
                    : 0;

                // Ingestion must never rewrite or split a shared reviewed component. A later bounded
                // audit will either verify it or dissolve every member in one locked transaction.
                if (! $canonical) {
                    return $mapping;
                }
                if ($componentCount !== 1) {
                    EmailMailboxPlacement::query()
                        ->where('email_message_id', $source->id)
                        ->update(['canonical_email_message_id' => $canonical->id]);

                    return $mapping;
                }

                $projectionMatches = hash_equals(
                    (string) $canonical->root_projection_hash,
                    (string) $snapshot['root_projection_hash'],
                ) && hash_equals(
                    (string) $canonical->root_projection_hash,
                    $this->evidence->storedProjectionHash($canonical),
                );
                if ($projectionMatches
                    && hash_equals((string) $mapping->strict_evidence_hash, (string) $snapshot['strict_evidence_hash'])) {
                    EmailMailboxPlacement::query()
                        ->where('email_message_id', $source->id)
                        ->where(function ($query) use ($canonical): void {
                            $query->whereNull('canonical_email_message_id')
                                ->orWhere('canonical_email_message_id', '!=', $canonical->id);
                        })
                        ->update(['canonical_email_message_id' => $canonical->id]);

                    return $mapping;
                }
            }

            $previousId = $mapping?->canonical_email_message_id;
            $canonical = $this->writer->createProjection($source, $snapshot);
            $mapping = $this->writer->mapSource(
                $source,
                $canonical,
                $snapshot,
                EmailCanonicalMessageSource::KIND_SELF,
                null,
            );
            $this->writer->refreshComponentCounts(array_values(array_filter([
                $previousId,
                $canonical->id,
            ])));

            return $mapping;
        }, 3);
    }
}
