<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailMailboxPlacement;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Produces a bounded set of database aggregates for one account's complete active-placement scope.
 * Full content/file evidence is retained page by page by the parity-attestation workflow.
 */
final class EmailCanonicalParityScope
{
    /** @return array{active_count:int,max_placement_id:int,state_hash:string} */
    public function summary(int $accountId): array
    {
        $active = fn (): Builder => DB::table('email_mailbox_placements as scope_placements')
            ->whereColumn('scope_placements.email_message_id', 'email_messages.id')
            ->where('scope_placements.account_id', $accountId)
            ->where('scope_placements.local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('scope_placements.provider_missing_at');

        $placements = DB::table('email_mailbox_placements as placements')
            ->where('placements.account_id', $accountId)
            ->where('placements.local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('placements.provider_missing_at')
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('COALESCE(MIN(placements.id), 0) AS min_id')
            ->selectRaw('COALESCE(MAX(placements.id), 0) AS max_id')
            ->first();

        $messages = DB::table('email_messages')
            ->where('email_messages.account_id', $accountId)
            ->whereExists($active())
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('COALESCE(MAX(email_messages.id), 0) AS max_id')
            ->selectRaw('MAX(email_messages.updated_at) AS max_updated_at')
            ->first();

        $attachments = DB::table('email_attachments as attachments')
            ->join('email_messages', 'email_messages.id', '=', 'attachments.message_id')
            ->where('email_messages.account_id', $accountId)
            ->whereExists($active())
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('COALESCE(MAX(attachments.id), 0) AS max_id')
            ->selectRaw('COALESCE(SUM(COALESCE(attachments.size_bytes, 0)), 0) AS size_sum')
            ->first();

        $mappings = DB::table('email_canonical_message_sources as mappings')
            ->join('email_messages', 'email_messages.id', '=', 'mappings.source_email_message_id')
            ->where('email_messages.account_id', $accountId)
            ->whereExists($active())
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('COALESCE(MAX(mappings.id), 0) AS max_id')
            ->selectRaw('MAX(mappings.updated_at) AS max_updated_at')
            ->first();

        $canonicals = DB::table('email_canonical_messages as canonicals')
            ->join(
                'email_canonical_message_sources as mappings',
                'mappings.canonical_email_message_id',
                '=',
                'canonicals.id',
            )
            ->join('email_messages', 'email_messages.id', '=', 'mappings.source_email_message_id')
            ->where('email_messages.account_id', $accountId)
            ->whereExists($active())
            ->selectRaw('COUNT(DISTINCT canonicals.id) AS row_count')
            ->selectRaw('COALESCE(MAX(canonicals.id), 0) AS max_id')
            ->selectRaw('MAX(canonicals.updated_at) AS max_updated_at')
            ->first();

        $canonicalAttachments = DB::table('email_canonical_message_attachments as canonical_attachments')
            ->join(
                'email_canonical_message_sources as mappings',
                'mappings.canonical_email_message_id',
                '=',
                'canonical_attachments.canonical_email_message_id',
            )
            ->join('email_messages', 'email_messages.id', '=', 'mappings.source_email_message_id')
            ->where('email_messages.account_id', $accountId)
            ->whereExists($active())
            ->selectRaw('COUNT(DISTINCT canonical_attachments.id) AS row_count')
            ->selectRaw('COALESCE(MAX(canonical_attachments.id), 0) AS max_id')
            ->selectRaw('MAX(canonical_attachments.created_at) AS max_created_at')
            ->first();

        $facts = [
            'algorithm' => EmailCanonicalCutoverEvidence::ALGORITHM_VERSION,
            'account_id' => $accountId,
            'placements' => $this->facts($placements, ['row_count', 'min_id', 'max_id']),
            'messages' => $this->facts($messages, ['row_count', 'max_id', 'max_updated_at']),
            'attachments' => $this->facts($attachments, ['row_count', 'max_id', 'size_sum']),
            'mappings' => $this->facts($mappings, ['row_count', 'max_id', 'max_updated_at']),
            'canonicals' => $this->facts($canonicals, ['row_count', 'max_id', 'max_updated_at']),
            'canonical_attachments' => $this->facts(
                $canonicalAttachments,
                ['row_count', 'max_id', 'max_created_at'],
            ),
        ];

        return [
            'active_count' => (int) ($placements->row_count ?? 0),
            'max_placement_id' => (int) ($placements->max_id ?? 0),
            'state_hash' => hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /** @param list<string> $keys
     * @return array<string,int|string>
     */
    private function facts(?object $row, array $keys): array
    {
        return collect($keys)->mapWithKeys(function (string $key) use ($row): array {
            $value = $row?->{$key};

            return [$key => str_contains($key, 'count')
                || str_ends_with($key, '_id')
                || str_ends_with($key, '_sum')
                    ? (int) $value
                    : (string) ($value ?? '')];
        })->all();
    }
}
