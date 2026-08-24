<?php

namespace App\Modules\Marketing\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InspectMarketingCampaignDeliveryHistory
{
    private const PROVEN_PRE_PROVIDER_FAILURES = [
        'No campaign email content exists for this recipient.',
        'No active marketing template exists for this legacy campaign email.',
        'Campaign content could not be rendered before transmission.',
    ];

    /**
     * Return sanitized aggregate evidence only. No recipient address or other
     * identity value leaves this read-only preflight boundary.
     *
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        if (! Schema::hasTable('marketing_campaign_recipients')) {
            return [
                'status' => 'not_installed',
                'read_only' => true,
            ];
        }

        $campaigns = Schema::hasTable('marketing_campaigns')
            ? DB::table('marketing_campaigns')
                ->select(['status', 'completion_behavior'])
                ->selectRaw('COUNT(*) as aggregate')
                ->groupBy(['status', 'completion_behavior'])
                ->orderBy('status')
                ->orderBy('completion_behavior')
                ->get()
                ->map(fn ($row): array => [
                    'status' => (string) $row->status,
                    'completion_behavior' => (string) $row->completion_behavior,
                    'count' => (int) $row->aggregate,
                ])->values()->all()
            : [];

        $cycles = Schema::hasTable('marketing_campaigns')
            ? DB::table('marketing_campaigns')
                ->select(['current_cycle'])
                ->selectRaw('CASE WHEN next_cycle_at IS NULL THEN 0 ELSE 1 END as has_next_cycle')
                ->selectRaw('COUNT(*) as aggregate')
                ->groupBy(['current_cycle'])
                ->groupByRaw('CASE WHEN next_cycle_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('current_cycle')
                ->get()
                ->map(fn ($row): array => [
                    'current_cycle' => (int) ($row->current_cycle ?: 1),
                    'has_next_cycle' => (bool) $row->has_next_cycle,
                    'count' => (int) $row->aggregate,
                ])->values()->all()
            : [];

        $recipients = DB::table('marketing_campaign_recipients')
            ->select(['status', 'cycle_number'])
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy(['status', 'cycle_number'])
            ->orderBy('status')
            ->orderBy('cycle_number')
            ->get()
            ->map(fn ($row): array => [
                'status' => (string) $row->status,
                'cycle_number' => (int) ($row->cycle_number ?: 1),
                'count' => (int) $row->aggregate,
            ])->values()->all();

        $pendingReplayCandidates = $this->pendingReplayCandidates();
        $uncertainOutcomes = $this->uncertainOutcomeCount();
        $identityClusters = [
            'contact' => $this->clusterSummary('contact_id'),
            'client_user' => $this->clusterSummary('client_user_id'),
            'normalized_email' => $this->emailClusterSummary(),
        ];
        $hasIdentityClusters = collect($identityClusters)
            ->contains(fn (array $summary): bool => $summary['clusters'] > 0);
        $identityGraph = $this->legacyIdentityGraphSummary();

        return [
            'status' => $pendingReplayCandidates > 0
                || $uncertainOutcomes > 0
                || $hasIdentityClusters
                || $identityGraph['ambiguous_splits'] > 0
                || $identityGraph['consumed_without_identity'] > 0
                ? 'review_required'
                : 'ready',
            'read_only' => true,
            'campaigns' => $campaigns,
            'cycles' => $cycles,
            'recipients' => $recipients,
            'identity_clusters' => $identityClusters,
            'ambiguous_identity_splits' => $identityGraph['ambiguous_splits'],
            'consumed_without_stable_identity' => $identityGraph['consumed_without_identity'],
            'pending_replay_candidates' => $pendingReplayCandidates,
            'uncertain_or_incomplete_outcomes' => $uncertainOutcomes,
            'sent_without_rfc_message_id' => DB::table('marketing_campaign_recipients')
                ->where('status', 'sent')
                ->where(function ($query): void {
                    $query->whereNull('rfc_message_id')->orWhere('rfc_message_id', '');
                })
                ->count(),
            'delivery_ledger' => $this->deliveryLedgerSummary(),
        ];
    }

    /** @return array{clusters: int, rows: int} */
    private function clusterSummary(string $column): array
    {
        $groups = DB::table('marketing_campaign_recipients')
            ->select(['marketing_campaign_email_id', $column])
            ->selectRaw('COUNT(*) as aggregate')
            ->whereNotNull($column)
            ->groupBy(['marketing_campaign_email_id', $column])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return [
            'clusters' => $groups->count(),
            'rows' => (int) $groups->sum('aggregate'),
        ];
    }

    /** @return array{clusters: int, rows: int} */
    private function emailClusterSummary(): array
    {
        $groups = DB::table('marketing_campaign_recipients')
            ->select(['marketing_campaign_email_id'])
            ->selectRaw('LOWER(TRIM(email)) as normalized_email')
            ->selectRaw('COUNT(*) as aggregate')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->groupBy('marketing_campaign_email_id')
            ->groupByRaw('LOWER(TRIM(email))')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return [
            'clusters' => $groups->count(),
            'rows' => (int) $groups->sum('aggregate'),
        ];
    }

    private function pendingReplayCandidates(): int
    {
        $consumedIdentityKeys = [];

        DB::table('marketing_campaign_recipients')
            ->orderBy('id')
            ->chunkById(500, function ($recipients) use (&$consumedIdentityKeys): void {
                foreach ($recipients as $recipient) {
                    if (! $this->consumesDeliveryIdentity($recipient)) {
                        continue;
                    }

                    foreach ($this->identityKeys($recipient) as $identityKey) {
                        $consumedIdentityKeys[$identityKey][(int) $recipient->id] = true;
                    }
                }
            }, 'id');

        if ($consumedIdentityKeys === []) {
            return 0;
        }

        $count = 0;

        DB::table('marketing_campaign_recipients')
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(500, function ($recipients) use (&$count, $consumedIdentityKeys): void {
                foreach ($recipients as $recipient) {
                    foreach ($this->identityKeys($recipient) as $identityKey) {
                        foreach (array_keys($consumedIdentityKeys[$identityKey] ?? []) as $consumedRecipientId) {
                            if ((int) $consumedRecipientId !== (int) $recipient->id) {
                                $count++;

                                continue 3;
                            }
                        }
                    }
                }
            }, 'id');

        return $count;
    }

    private function uncertainOutcomeCount(): int
    {
        $count = 0;

        DB::table('marketing_campaign_recipients')
            ->orderBy('id')
            ->chunkById(500, function ($recipients) use (&$count): void {
                foreach ($recipients as $recipient) {
                    if (
                        in_array($recipient->status, ['claimed', 'provider_write_started', 'outcome_unknown'], true)
                        || (
                            $recipient->status === 'pending'
                            && ((int) $recipient->attempts > 0 || filled($recipient->rfc_message_id))
                        )
                        || (
                            $recipient->status === 'failed'
                            && ! in_array(trim((string) $recipient->last_error), self::PROVEN_PRE_PROVIDER_FAILURES, true)
                        )
                    ) {
                        $count++;
                    }
                }
            }, 'id');

        return $count;
    }

    /** @return array{ambiguous_splits: int, consumed_without_identity: int} */
    private function legacyIdentityGraphSummary(): array
    {
        $clusters = [];
        $nextClusterId = 1;
        $ambiguousSplits = 0;
        $consumedWithoutIdentity = 0;

        DB::table('marketing_campaign_recipients')
            ->orderBy('id')
            ->chunkById(500, function ($recipients) use (
                &$clusters,
                &$nextClusterId,
                &$ambiguousSplits,
                &$consumedWithoutIdentity,
            ): void {
                foreach ($recipients as $recipient) {
                    if (! $this->consumesDeliveryIdentity($recipient)) {
                        continue;
                    }

                    $identityKeys = $this->identityKeys($recipient);

                    if ($identityKeys === []) {
                        $consumedWithoutIdentity++;

                        continue;
                    }

                    $matchedClusters = $this->matchedClusters($clusters, $identityKeys);

                    if (count($matchedClusters) > 1) {
                        $ambiguousSplits++;
                    }

                    $clusterId = $matchedClusters[0] ?? $nextClusterId++;

                    foreach ($identityKeys as $identityKey) {
                        $clusters[$identityKey] = $clusterId;
                    }
                }
            }, 'id');

        DB::table('marketing_campaign_recipients')
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(500, function ($recipients) use (&$ambiguousSplits, $clusters): void {
                foreach ($recipients as $recipient) {
                    if ($this->consumesDeliveryIdentity($recipient)) {
                        continue;
                    }

                    if (count($this->matchedClusters($clusters, $this->identityKeys($recipient))) > 1) {
                        $ambiguousSplits++;
                    }
                }
            }, 'id');

        return [
            'ambiguous_splits' => $ambiguousSplits,
            'consumed_without_identity' => $consumedWithoutIdentity,
        ];
    }

    /**
     * @param  array<string, int>  $clusters
     * @param  array<int, string>  $identityKeys
     * @return array<int, int>
     */
    private function matchedClusters(array $clusters, array $identityKeys): array
    {
        return collect($identityKeys)
            ->map(fn (string $identityKey): ?int => $clusters[$identityKey] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, array{status: string, source: string, count: int}> */
    private function deliveryLedgerSummary(): array
    {
        if (! Schema::hasTable('marketing_campaign_deliveries')) {
            return [];
        }

        return DB::table('marketing_campaign_deliveries')
            ->select(['status', 'source'])
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy(['status', 'source'])
            ->orderBy('status')
            ->orderBy('source')
            ->get()
            ->map(fn ($row): array => [
                'status' => (string) $row->status,
                'source' => (string) $row->source,
                'count' => (int) $row->aggregate,
            ])->values()->all();
    }

    private function consumesDeliveryIdentity(object $recipient): bool
    {
        if (in_array($recipient->status, ['sent', 'claimed', 'provider_write_started', 'outcome_unknown'], true)) {
            return true;
        }

        if (
            $recipient->status === 'pending'
            && ((int) $recipient->attempts > 0 || filled($recipient->rfc_message_id))
        ) {
            return true;
        }

        return $recipient->status === 'failed'
            && ! in_array(trim((string) $recipient->last_error), self::PROVEN_PRE_PROVIDER_FAILURES, true);
    }

    /** @return array<int, string> */
    private function identityKeys(object $recipient): array
    {
        $prefix = (int) $recipient->marketing_campaign_email_id.':';
        $keys = [];

        if ($recipient->contact_id) {
            $keys[] = $prefix.'contact:'.hash(
                'sha256',
                'marketing-delivery-identity-v1:contact:'.(int) $recipient->contact_id,
            );
        }

        if ($recipient->client_user_id) {
            $keys[] = $prefix.'client_user:'.hash(
                'sha256',
                'marketing-delivery-identity-v1:client_user:'.(int) $recipient->client_user_id,
            );
        }

        $email = mb_strtolower(trim((string) $recipient->email));

        if ($email !== '') {
            $keys[] = $prefix.'email:'.hash(
                'sha256',
                'marketing-delivery-identity-v1:email:'.$email,
            );
        }

        return $keys;
    }
}
