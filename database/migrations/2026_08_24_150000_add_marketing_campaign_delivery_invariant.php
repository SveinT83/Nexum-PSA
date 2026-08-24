<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PROVEN_PRE_PROVIDER_FAILURES = [
        'No campaign email content exists for this recipient.',
        'No active marketing template exists for this legacy campaign email.',
        'Campaign content could not be rendered before transmission.',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('marketing_campaign_recipients')) {
            return;
        }

        // Validate the whole legacy graph before the first DDL statement. MySQL
        // may auto-commit schema changes, so an ambiguous split must block the
        // migration before it can leave a partially installed invariant.
        $this->assertLegacyDeliveryHistoryCanBeBackfilled();
        $this->convertCampaignCompletionBehavior();

        if (! Schema::hasTable('marketing_campaign_deliveries')) {
            Schema::create('marketing_campaign_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketing_campaign_id');
                $table->foreignId('marketing_campaign_email_id');
                $table->foreignId('marketing_campaign_recipient_id')->nullable();
                $table->string('status', 40)->index();
                $table->string('source', 40)->default('runtime')->index();
                $table->char('claim_token', 64)->unique();
                $table->string('rfc_message_id')->nullable();
                $table->timestamp('claimed_at')->nullable()->index();
                $table->timestamp('provider_write_started_at')->nullable()->index();
                $table->timestamp('sent_at')->nullable()->index();
                $table->timestamp('outcome_unknown_at')->nullable()->index();
                $table->string('last_error_code', 100)->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(
                    ['marketing_campaign_id', 'status', 'claimed_at'],
                    'mcd_campaign_status_claimed_index'
                );
                $table->unique('rfc_message_id', 'mcd_rfc_message_id_unique');
                $table->foreign('marketing_campaign_id', 'mcd_campaign_fk')
                    ->references('id')->on('marketing_campaigns')->restrictOnDelete();
                $table->foreign('marketing_campaign_email_id', 'mcd_email_fk')
                    ->references('id')->on('marketing_campaign_emails')->restrictOnDelete();
                $table->foreign('marketing_campaign_recipient_id', 'mcd_recipient_fk')
                    ->references('id')->on('marketing_campaign_recipients')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('marketing_campaign_delivery_identity_keys')) {
            Schema::create('marketing_campaign_delivery_identity_keys', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketing_campaign_delivery_id');
                $table->foreignId('marketing_campaign_email_id');
                $table->string('identity_type', 24);
                $table->char('identity_hash', 64);
                $table->timestamps();

                $table->unique(
                    ['marketing_campaign_email_id', 'identity_type', 'identity_hash'],
                    'mcd_identity_unique'
                );
                $table->index(
                    ['marketing_campaign_delivery_id', 'identity_type'],
                    'mcd_identity_delivery_index'
                );
                $table->foreign('marketing_campaign_delivery_id', 'mcd_identity_delivery_fk')
                    ->references('id')->on('marketing_campaign_deliveries')->cascadeOnDelete();
                $table->foreign('marketing_campaign_email_id', 'mcd_identity_email_fk')
                    ->references('id')->on('marketing_campaign_emails')->restrictOnDelete();
            });
        }

        Schema::table('marketing_campaign_recipients', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_campaign_recipients', 'marketing_campaign_delivery_id')) {
                // This intentionally remains an indexed compatibility link rather than a foreign key.
                // The delivery ledger already points back to the canonical recipient, and avoiding a
                // circular foreign key keeps historical guards durable across recipient maintenance.
                $table->unsignedBigInteger('marketing_campaign_delivery_id')->nullable();
            }

            if (! Schema::hasColumn('marketing_campaign_recipients', 'claimed_at')) {
                $table->timestamp('claimed_at')->nullable();
            }

            if (! Schema::hasColumn('marketing_campaign_recipients', 'outcome_unknown_at')) {
                $table->timestamp('outcome_unknown_at')->nullable();
            }
        });

        $this->ensureRecipientIndex('marketing_campaign_delivery_id', 'mcr_delivery_id_idx');
        $this->ensureRecipientIndex('claimed_at', 'mcr_claimed_at_idx');
        $this->ensureRecipientIndex('outcome_unknown_at', 'mcr_outcome_unknown_at_idx');

        $this->backfillConsumedHistory();
        $this->skipPendingHistoricalDuplicates();
    }

    public function down(): void
    {
        if (
            Schema::hasTable('marketing_campaign_deliveries')
            && DB::table('marketing_campaign_deliveries')->where('source', 'runtime')->exists()
        ) {
            throw new RuntimeException(
                'Runtime Marketing delivery claims exist. Preserve the delivery ledger to avoid duplicate sends.'
            );
        }

        $this->restoreCampaignCompletionBehavior();

        if (Schema::hasTable('marketing_campaign_recipients')) {
            // SQLite cannot drop an indexed column until its index is removed.
            // Keep the same explicit order on every driver so the rollback
            // remains portable and can be exercised safely in isolation tests.
            foreach ([
                'mcr_delivery_id_idx',
                'mcr_claimed_at_idx',
                'mcr_outcome_unknown_at_idx',
                'marketing_campaign_recipients_marketing_campaign_delivery_id_index',
                'marketing_campaign_recipients_claimed_at_index',
                'marketing_campaign_recipients_outcome_unknown_at_index',
            ] as $index) {
                if (Schema::hasIndex('marketing_campaign_recipients', $index)) {
                    Schema::table('marketing_campaign_recipients', function (Blueprint $table) use ($index): void {
                        $table->dropIndex($index);
                    });
                }
            }

            Schema::table('marketing_campaign_recipients', function (Blueprint $table): void {
                foreach (['marketing_campaign_delivery_id', 'claimed_at', 'outcome_unknown_at'] as $column) {
                    if (Schema::hasColumn('marketing_campaign_recipients', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('marketing_campaign_delivery_identity_keys');
        Schema::dropIfExists('marketing_campaign_deliveries');
    }

    private function ensureRecipientIndex(string $column, string $index): void
    {
        if (Schema::hasIndex('marketing_campaign_recipients', $index)) {
            return;
        }

        Schema::table(
            'marketing_campaign_recipients',
            function (Blueprint $table) use ($column, $index): void {
                $table->index($column, $index);
            },
        );
    }

    private function convertCampaignCompletionBehavior(): void
    {
        if (! Schema::hasTable('marketing_campaigns')) {
            return;
        }

        if (! Schema::hasColumn('marketing_campaigns', 'legacy_completion_behavior')) {
            Schema::table('marketing_campaigns', function (Blueprint $table): void {
                $table->string('legacy_completion_behavior', 20)
                    ->nullable()
                    ->after('completion_behavior');
            });
        }

        DB::table('marketing_campaigns')
            ->whereNull('legacy_completion_behavior')
            ->whereIn('completion_behavior', ['stop', 'repeat'])
            ->update([
                'legacy_completion_behavior' => DB::raw('completion_behavior'),
            ]);

        // Status and all cycle/timestamp fields deliberately remain untouched.
        // In particular, completed campaigns stay inert until an explicit
        // continuation action reactivates them.
        DB::table('marketing_campaigns')
            ->whereIn('completion_behavior', ['stop', 'repeat'])
            ->update([
                'completion_behavior' => 'continue',
            ]);

        $this->changeCompletionBehaviorDefault('continue');
    }

    private function restoreCampaignCompletionBehavior(): void
    {
        if (! Schema::hasTable('marketing_campaigns')) {
            return;
        }

        if (Schema::hasColumn('marketing_campaigns', 'legacy_completion_behavior')) {
            DB::table('marketing_campaigns')
                ->whereNotNull('legacy_completion_behavior')
                ->update([
                    'completion_behavior' => DB::raw('legacy_completion_behavior'),
                ]);
        }

        $this->changeCompletionBehaviorDefault('stop');

        if (Schema::hasColumn('marketing_campaigns', 'legacy_completion_behavior')) {
            Schema::table('marketing_campaigns', function (Blueprint $table): void {
                $table->dropColumn('legacy_completion_behavior');
            });
        }
    }

    private function changeCompletionBehaviorDefault(string $default): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite rebuilds the parent table for a default-only change. In a
            // surrounding test transaction that can apply child-table cascade
            // actions to existing campaign history. The application model owns
            // the same default, while production MySQL receives the schema default.
            return;
        }

        Schema::table('marketing_campaigns', function (Blueprint $table) use ($default): void {
            $table->string('completion_behavior', 20)
                ->default($default)
                ->change();
        });
    }

    private function assertLegacyDeliveryHistoryCanBeBackfilled(): void
    {
        $clustersByCampaignEmail = [];
        $nextClusterId = 1;

        DB::table('marketing_campaign_recipients')
            ->orderBy('id')
            ->chunkById(250, function ($recipients) use (&$clustersByCampaignEmail, &$nextClusterId): void {
                foreach ($recipients as $recipient) {
                    if (! $this->consumesDeliveryIdentity($recipient)) {
                        continue;
                    }

                    $identityKeys = $this->identityKeys($recipient);

                    if ($identityKeys === []) {
                        throw new RuntimeException(
                            'A historical Marketing transmission has no stable recipient identity.'
                        );
                    }

                    $campaignEmailId = (int) $recipient->marketing_campaign_email_id;
                    $clusters = &$clustersByCampaignEmail[$campaignEmailId];

                    if (! is_array($clusters)) {
                        $clusters = [];
                    }

                    $matchedClusters = $this->matchedLegacyClusters($clusters, $identityKeys);

                    if (count($matchedClusters) > 1) {
                        throw new RuntimeException(
                            'Historical Marketing identity evidence joins more than one consumed delivery. Resolve the ambiguity before migrating.'
                        );
                    }

                    $clusterId = $matchedClusters[0] ?? $nextClusterId++;

                    foreach ($identityKeys as $identityKey) {
                        $clusters[$this->identityKeyToken($identityKey)] = $clusterId;
                    }

                    unset($clusters);
                }
            }, 'id');

        // Pending rows do not create consumed clusters, but a pending bridge
        // across two clusters is equally unsafe to classify automatically.
        DB::table('marketing_campaign_recipients')
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(250, function ($recipients) use ($clustersByCampaignEmail): void {
                foreach ($recipients as $recipient) {
                    if ($this->consumesDeliveryIdentity($recipient)) {
                        continue;
                    }

                    $clusters = $clustersByCampaignEmail[(int) $recipient->marketing_campaign_email_id] ?? [];
                    $matchedClusters = $this->matchedLegacyClusters($clusters, $this->identityKeys($recipient));

                    if (count($matchedClusters) > 1) {
                        throw new RuntimeException(
                            'Pending Marketing identity evidence joins more than one consumed delivery. Resolve the ambiguity before migrating.'
                        );
                    }
                }
            }, 'id');
    }

    private function backfillConsumedHistory(): void
    {
        DB::table('marketing_campaign_recipients')
            ->orderBy('id')
            ->chunkById(250, function ($recipients): void {
                foreach ($recipients as $recipient) {
                    if (! $this->consumesDeliveryIdentity($recipient)) {
                        continue;
                    }

                    DB::transaction(function () use ($recipient): void {
                        $identityKeys = $this->identityKeys($recipient);

                        if ($identityKeys === []) {
                            throw new RuntimeException(
                                'A historical Marketing transmission has no stable recipient identity.'
                            );
                        }

                        $deliveryId = $this->existingDeliveryId(
                            (int) $recipient->marketing_campaign_email_id,
                            $identityKeys,
                        );

                        if (! $deliveryId) {
                            $deliveryId = DB::table('marketing_campaign_deliveries')->insertGetId([
                                'marketing_campaign_id' => (int) $recipient->marketing_campaign_id,
                                'marketing_campaign_email_id' => (int) $recipient->marketing_campaign_email_id,
                                'marketing_campaign_recipient_id' => (int) $recipient->id,
                                'status' => $this->historicalDeliveryStatus($recipient),
                                'source' => 'historical_backfill',
                                'claim_token' => hash('sha256', 'marketing-history-v1:'.(int) $recipient->id),
                                'rfc_message_id' => $this->availableHistoricalMessageId($recipient->rfc_message_id),
                                'claimed_at' => $this->historicalTransmissionAt($recipient),
                                'provider_write_started_at' => $this->historicalTransmissionAt($recipient),
                                'sent_at' => $recipient->status === 'sent'
                                    ? $this->historicalTransmissionAt($recipient)
                                    : null,
                                'outcome_unknown_at' => $this->historicalDeliveryStatus($recipient) === 'outcome_unknown'
                                    ? ($recipient->updated_at ?: $recipient->created_at)
                                    : null,
                                'last_error_code' => $this->historicalDeliveryStatus($recipient) === 'outcome_unknown'
                                    ? 'LEGACY_SMTP_OUTCOME_UNRESOLVED'
                                    : null,
                                'metadata' => json_encode([
                                    'backfill' => [
                                        'recipient_status' => (string) $recipient->status,
                                        'cycle_number' => (int) ($recipient->cycle_number ?: 1),
                                    ],
                                ], JSON_THROW_ON_ERROR),
                                'created_at' => $recipient->created_at ?: now(),
                                'updated_at' => now(),
                            ]);
                        } elseif ($recipient->status === 'sent') {
                            // Confirmed provider acceptance is stronger than an older ambiguous row
                            // that happened to be encountered first in the historical cluster.
                            DB::table('marketing_campaign_deliveries')
                                ->where('id', $deliveryId)
                                ->update([
                                    'status' => 'sent',
                                    'sent_at' => $this->historicalTransmissionAt($recipient),
                                    'outcome_unknown_at' => null,
                                    'last_error_code' => null,
                                    'updated_at' => now(),
                                ]);

                            $availableMessageId = $this->availableHistoricalMessageId(
                                $recipient->rfc_message_id,
                                $deliveryId,
                            );

                            if ($availableMessageId) {
                                DB::table('marketing_campaign_deliveries')
                                    ->where('id', $deliveryId)
                                    ->whereNull('rfc_message_id')
                                    ->update(['rfc_message_id' => $availableMessageId]);
                            }
                        }

                        foreach ($identityKeys as $identityKey) {
                            DB::table('marketing_campaign_delivery_identity_keys')->insertOrIgnore([
                                'marketing_campaign_delivery_id' => $deliveryId,
                                'marketing_campaign_email_id' => (int) $recipient->marketing_campaign_email_id,
                                'identity_type' => $identityKey['type'],
                                'identity_hash' => $identityKey['hash'],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        $updates = [
                            'marketing_campaign_delivery_id' => $deliveryId,
                            'claimed_at' => $recipient->sent_at ?: $recipient->updated_at ?: $recipient->created_at,
                            'updated_at' => now(),
                        ];

                        if ($recipient->status === 'pending') {
                            $updates['status'] = 'outcome_unknown';
                            $updates['due_at'] = null;
                            $updates['outcome_unknown_at'] = $recipient->updated_at ?: $recipient->created_at;
                            $updates['last_error'] = 'A historical transmission attempt has an unresolved outcome; automatic resend is blocked.';
                            $updates['metadata'] = $this->mergedMetadata($recipient, [
                                'delivery_backfill_previous_status' => 'pending',
                                'delivery_backfill_classification' => 'outcome_unknown',
                            ]);
                        }

                        DB::table('marketing_campaign_recipients')
                            ->where('id', $recipient->id)
                            ->update($updates);
                    });
                }
            }, 'id');
    }

    private function skipPendingHistoricalDuplicates(): void
    {
        DB::table('marketing_campaign_recipients')
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(250, function ($recipients): void {
                foreach ($recipients as $recipient) {
                    $identityKeys = $this->identityKeys($recipient);
                    $deliveryId = $this->existingDeliveryId(
                        (int) $recipient->marketing_campaign_email_id,
                        $identityKeys,
                    );

                    if (! $deliveryId) {
                        continue;
                    }

                    DB::table('marketing_campaign_recipients')
                        ->where('id', $recipient->id)
                        ->where('status', 'pending')
                        ->update([
                            'marketing_campaign_delivery_id' => $deliveryId,
                            'status' => 'duplicate_skipped',
                            'due_at' => null,
                            'last_error' => 'A lifetime delivery guard already exists for this campaign email and recipient identity.',
                            'metadata' => $this->mergedMetadata($recipient, [
                                'delivery_backfill_previous_status' => 'pending',
                                'delivery_backfill_classification' => 'duplicate_skipped',
                            ]),
                            'updated_at' => now(),
                        ]);
                }
            }, 'id');
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

    private function historicalDeliveryStatus(object $recipient): string
    {
        return $recipient->status === 'sent' ? 'sent' : 'outcome_unknown';
    }

    /** @return array<int, array{type: string, hash: string}> */
    private function identityKeys(object $recipient): array
    {
        $keys = [];

        if ($recipient->contact_id) {
            $keys[] = $this->identityKey('contact', (string) (int) $recipient->contact_id);
        }

        if ($recipient->client_user_id) {
            $keys[] = $this->identityKey('client_user', (string) (int) $recipient->client_user_id);
        }

        $email = mb_strtolower(trim((string) $recipient->email));

        if ($email !== '') {
            $keys[] = $this->identityKey('email', $email);
        }

        return $keys;
    }

    /** @return array{type: string, hash: string} */
    private function identityKey(string $type, string $value): array
    {
        return [
            'type' => $type,
            'hash' => hash('sha256', 'marketing-delivery-identity-v1:'.$type.':'.$value),
        ];
    }

    /** @param array<int, array{type: string, hash: string}> $identityKeys */
    private function existingDeliveryId(int $campaignEmailId, array $identityKeys): ?int
    {
        if ($identityKeys === []) {
            return null;
        }

        $query = DB::table('marketing_campaign_delivery_identity_keys as identity_keys')
            ->join(
                'marketing_campaign_deliveries as deliveries',
                'deliveries.id',
                '=',
                'identity_keys.marketing_campaign_delivery_id'
            )
            ->where('identity_keys.marketing_campaign_email_id', $campaignEmailId)
            ->where(function ($query) use ($identityKeys): void {
                foreach ($identityKeys as $identityKey) {
                    $query->orWhere(function ($query) use ($identityKey): void {
                        $query->where('identity_keys.identity_type', $identityKey['type'])
                            ->where('identity_keys.identity_hash', $identityKey['hash']);
                    });
                }
            });

        $deliveryIds = $query
            ->distinct()
            ->pluck('deliveries.id')
            ->map(fn ($deliveryId): int => (int) $deliveryId)
            ->unique()
            ->values();

        if ($deliveryIds->count() > 1) {
            throw new RuntimeException(
                'Marketing identity evidence matches more than one delivery. Automatic canonicalization is unsafe.'
            );
        }

        return $deliveryIds->first();
    }

    /**
     * @param  array<string, int>  $clusters
     * @param  array<int, array{type: string, hash: string}>  $identityKeys
     * @return array<int, int>
     */
    private function matchedLegacyClusters(array $clusters, array $identityKeys): array
    {
        return collect($identityKeys)
            ->map(fn (array $identityKey): ?int => $clusters[$this->identityKeyToken($identityKey)] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array{type: string, hash: string} $identityKey */
    private function identityKeyToken(array $identityKey): string
    {
        return $identityKey['type'].':'.$identityKey['hash'];
    }

    private function historicalTransmissionAt(object $recipient): mixed
    {
        return $recipient->sent_at ?: $recipient->updated_at ?: $recipient->created_at ?: now();
    }

    private function availableHistoricalMessageId(mixed $messageId, ?int $exceptDeliveryId = null): ?string
    {
        $messageId = trim((string) $messageId);

        if ($messageId === '') {
            return null;
        }

        $query = DB::table('marketing_campaign_deliveries')
            ->where('rfc_message_id', $messageId);

        if ($exceptDeliveryId) {
            $query->where('id', '!=', $exceptDeliveryId);
        }

        // The recipient history remains untouched. A duplicate legacy header
        // is omitted only from the ledger so the runtime uniqueness guarantee
        // cannot be weakened by historical provider reuse.
        return $query->exists() ? null : $messageId;
    }

    private function mergedMetadata(object $recipient, array $deliveryMetadata): string
    {
        $metadata = [];

        if (is_string($recipient->metadata) && trim($recipient->metadata) !== '') {
            $decoded = json_decode($recipient->metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        } elseif (is_array($recipient->metadata)) {
            $metadata = $recipient->metadata;
        }

        $metadata['delivery_invariant'] = array_merge(
            (array) ($metadata['delivery_invariant'] ?? []),
            $deliveryMetadata,
        );

        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }
};
