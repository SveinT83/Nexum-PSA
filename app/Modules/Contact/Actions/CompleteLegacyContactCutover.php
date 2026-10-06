<?php

namespace App\Modules\Contact\Actions;

use App\Models\Clients\ClientUser;
use App\Modules\Marketing\Actions\EnrichMarketingCampaignRecipientIdentityEvidence;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Models\MarketingListMember;
use App\Modules\Signal\Models\Signal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CompleteLegacyContactCutover
{
    public function __construct(
        private readonly MigrateClientUsersToContacts $migrateClientUsers,
        private readonly EnrichMarketingCampaignRecipientIdentityEvidence $enrichMarketingIdentity,
    ) {}

    public function handle(): array
    {
        $summary = $this->migrateClientUsers->handle();
        $mapping = $this->contactMapping();
        $unlinked = ClientUser::query()->whereNull('contact_id')->count();

        if ($unlinked > 0) {
            throw new RuntimeException("Canonical Contact cutover stopped: {$unlinked} legacy Contact row(s) remain unlinked.");
        }

        $summary['unlinked_legacy_rows'] = 0;
        $summary['legacy_bridge_ids_preserved'] = $mapping->count();
        $summary['marketing_list_members'] = $this->backfillDualIdentityRows(
            'marketing_list_members',
            'client_user_id',
            'contact_id',
            $mapping,
        );
        $summary['marketing_list_criteria'] = $this->backfillMarketingListCriteria($mapping);
        $summary['marketing_recipients'] = $this->backfillMarketingRecipients($mapping);
        $summary['marketing_events'] = $this->backfillMarketingEvents();
        $summary['telephony_calls'] = $this->backfillDualIdentityRows(
            'telephony_calls',
            'client_user_id',
            'contact_id',
            $mapping,
        );
        $summary['intake_submissions'] = $this->backfillDualIdentityRows(
            'intake_submissions',
            'matched_client_user_id',
            'matched_contact_id',
            $mapping,
        );
        $summary['signals'] = $this->backfillSignals($mapping);
        $summary['user_links_verified'] = $this->verifyUserLinks($mapping);

        Log::info('Canonical Contact cutover completed.', $summary);

        return $summary;
    }

    /**
     * @return Collection<int, int>
     */
    private function contactMapping(): Collection
    {
        return ClientUser::query()
            ->whereNotNull('contact_id')
            ->pluck('contact_id', 'id')
            ->mapWithKeys(fn ($contactId, $clientUserId): array => [
                (int) $clientUserId => (int) $contactId,
            ]);
    }

    private function backfillDualIdentityRows(
        string $table,
        string $legacyColumn,
        string $contactColumn,
        Collection $mapping,
    ): int {
        if (
            ! Schema::hasTable($table)
            || ! Schema::hasColumn($table, $legacyColumn)
            || ! Schema::hasColumn($table, $contactColumn)
        ) {
            return 0;
        }

        $updated = 0;

        DB::table($table)
            ->select(['id', $legacyColumn, $contactColumn])
            ->whereNotNull($legacyColumn)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (
                $contactColumn,
                $legacyColumn,
                $mapping,
                $table,
                &$updated,
            ): void {
                foreach ($rows as $row) {
                    $contactId = $mapping->get((int) $row->{$legacyColumn});

                    if (! $contactId) {
                        throw new RuntimeException('Canonical Contact cutover stopped because a dual-identity row has no Contact mapping.');
                    }

                    $currentContactId = $row->{$contactColumn};

                    if ($currentContactId && (int) $currentContactId !== (int) $contactId) {
                        throw new RuntimeException('Canonical Contact cutover stopped because a dual-identity row has conflicting Contact evidence.');
                    }

                    if ($currentContactId) {
                        continue;
                    }

                    $updated += DB::table($table)
                        ->where('id', $row->id)
                        ->whereNull($contactColumn)
                        ->update([$contactColumn => $contactId]);
                }
            });

        return $updated;
    }

    private function backfillMarketingListCriteria(Collection $mapping): int
    {
        if (! Schema::hasTable('marketing_lists')) {
            return 0;
        }

        $updated = 0;

        MarketingList::query()
            ->whereNotNull('segment_criteria')
            ->orderBy('id')
            ->chunkById(100, function ($lists) use ($mapping, &$updated): void {
                foreach ($lists as $list) {
                    $criteria = $list->segment_criteria ?? [];
                    $legacyIds = collect($criteria['manual_client_user_ids'] ?? [])
                        ->map(fn ($id): int => (int) $id)
                        ->filter()
                        ->unique()
                        ->values();

                    if ($legacyIds->isEmpty()) {
                        continue;
                    }

                    $mappedContactIds = $legacyIds
                        ->map(function (int $clientUserId) use ($mapping): int {
                            $contactId = $mapping->get($clientUserId);

                            if (! $contactId) {
                                throw new RuntimeException('Canonical Contact cutover stopped because Marketing criteria has no Contact mapping.');
                            }

                            return (int) $contactId;
                        });

                    $existingContactIds = collect($criteria['manual_contact_ids'] ?? [])
                        ->map(fn ($id): int => (int) $id)
                        ->filter();

                    $canonicalIds = $existingContactIds
                        ->merge($mappedContactIds)
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                    if ($canonicalIds === $existingContactIds->unique()->sort()->values()->all()) {
                        continue;
                    }

                    $criteria['manual_contact_ids'] = $canonicalIds;
                    $list->forceFill(['segment_criteria' => $criteria])->save();
                    $updated++;
                }
            });

        return $updated;
    }

    private function backfillMarketingRecipients(Collection $mapping): int
    {
        if (
            ! Schema::hasTable('marketing_campaign_recipients')
            || ! Schema::hasTable('marketing_list_members')
        ) {
            return 0;
        }

        $updated = 0;

        MarketingCampaignRecipient::query()
            ->with('listMember')
            ->orderBy('id')
            ->chunkById(100, function ($recipients) use ($mapping, &$updated): void {
                foreach ($recipients as $recipient) {
                    $member = $recipient->listMember;
                    $legacyId = $recipient->client_user_id ?: $member?->client_user_id;

                    if (! $legacyId) {
                        continue;
                    }

                    $contactId = $mapping->get((int) $legacyId);

                    if (! $contactId) {
                        throw new RuntimeException('Canonical Contact cutover stopped because a Marketing recipient has no Contact mapping.');
                    }

                    if ($recipient->contact_id && (int) $recipient->contact_id !== (int) $contactId) {
                        throw new RuntimeException('Canonical Contact cutover stopped because a Marketing recipient has conflicting Contact evidence.');
                    }

                    if ($recipient->client_user_id && (int) $recipient->client_user_id !== (int) $legacyId) {
                        throw new RuntimeException('Canonical Contact cutover stopped because a Marketing recipient has conflicting legacy identity evidence.');
                    }

                    $evidenceMember = $member ?: new MarketingListMember([
                        'contact_id' => $contactId,
                        'client_user_id' => $legacyId,
                        'email' => $recipient->email,
                    ]);
                    $evidenceMember->forceFill([
                        'contact_id' => $contactId,
                        'client_user_id' => $legacyId,
                    ]);

                    $before = [
                        (int) ($recipient->contact_id ?? 0),
                        (int) ($recipient->client_user_id ?? 0),
                    ];

                    if (! $this->enrichMarketingIdentity->handle($recipient, $evidenceMember)) {
                        throw new RuntimeException('Canonical Contact cutover stopped because Marketing delivery identity evidence requires review.');
                    }

                    $recipient->refresh();
                    $after = [
                        (int) ($recipient->contact_id ?? 0),
                        (int) ($recipient->client_user_id ?? 0),
                    ];

                    if ($before !== $after) {
                        $updated++;
                    }
                }
            });

        return $updated;
    }

    private function backfillMarketingEvents(): int
    {
        if (
            ! Schema::hasTable('marketing_campaign_events')
            || ! Schema::hasTable('marketing_campaign_recipients')
        ) {
            return 0;
        }

        $updated = 0;

        DB::table('marketing_campaign_events')
            ->select(['id', 'marketing_campaign_recipient_id', 'contact_id'])
            ->whereNotNull('marketing_campaign_recipient_id')
            ->orderBy('id')
            ->chunkById(100, function ($events) use (&$updated): void {
                foreach ($events as $event) {
                    $contactId = DB::table('marketing_campaign_recipients')
                        ->where('id', $event->marketing_campaign_recipient_id)
                        ->value('contact_id');

                    if (! $contactId) {
                        continue;
                    }

                    if ($event->contact_id && (int) $event->contact_id !== (int) $contactId) {
                        throw new RuntimeException('Canonical Contact cutover stopped because a Marketing event has conflicting Contact evidence.');
                    }

                    if (! $event->contact_id) {
                        $updated += DB::table('marketing_campaign_events')
                            ->where('id', $event->id)
                            ->whereNull('contact_id')
                            ->update(['contact_id' => $contactId]);
                    }
                }
            });

        return $updated;
    }

    private function backfillSignals(Collection $mapping): int
    {
        if (! Schema::hasTable('signals')) {
            return 0;
        }

        $updated = 0;
        $clientUserType = (new ClientUser)->getMorphClass();

        Signal::query()
            ->orderBy('id')
            ->chunkById(100, function ($signals) use ($clientUserType, $mapping, &$updated): void {
                foreach ($signals as $signal) {
                    $legacyId = (int) (
                        data_get($signal->payload, 'matched_client_user_id')
                        ?? data_get($signal->payload, 'client_user_id')
                        ?? ($signal->subject_type === $clientUserType ? $signal->subject_id : null)
                        ?? ($signal->source_type === $clientUserType ? $signal->source_id : null)
                        ?? 0
                    );

                    if (! $legacyId) {
                        continue;
                    }

                    $contactId = $mapping->get($legacyId);

                    if (! $contactId) {
                        throw new RuntimeException('Canonical Contact cutover stopped because a Signal has no Contact mapping.');
                    }

                    if ($signal->contact_id && (int) $signal->contact_id !== (int) $contactId) {
                        throw new RuntimeException('Canonical Contact cutover stopped because a Signal has conflicting Contact evidence.');
                    }

                    if (! $signal->contact_id) {
                        $signal->forceFill(['contact_id' => $contactId])->save();
                        $updated++;
                    }
                }
            });

        return $updated;
    }

    private function verifyUserLinks(Collection $mapping): int
    {
        if (! Schema::hasTable((new \App\Models\Core\User)->getTable())) {
            return 0;
        }

        $verified = 0;

        ClientUser::query()
            ->whereNotNull('user_id')
            ->with('user')
            ->orderBy('id')
            ->chunkById(100, function ($clientUsers) use ($mapping, &$verified): void {
                foreach ($clientUsers as $clientUser) {
                    $contactId = $mapping->get((int) $clientUser->id);

                    if (! $clientUser->user || ! $contactId) {
                        throw new RuntimeException('Canonical Contact cutover stopped because a linked user is unavailable.');
                    }

                    if ((int) $clientUser->user->contact_id !== (int) $contactId) {
                        throw new RuntimeException('Canonical Contact cutover stopped because a user Contact link conflicts.');
                    }

                    $verified++;
                }
            });

        return $verified;
    }
}
