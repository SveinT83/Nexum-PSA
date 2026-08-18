<?php

use App\Modules\Email\Models\EmailAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_message_user_states', function (Blueprint $table): void {
            $table->unsignedInteger('access_epoch')->default(1)->after('user_id');
        });

        Schema::table('email_message_user_states', function (Blueprint $table): void {
            $table->unique(
                ['email_message_id', 'user_id', 'access_epoch'],
                'em_msg_state_message_user_epoch_uq',
            );
            $table->dropUnique('email_message_user_states_unique');
            $table->index(
                ['user_id', 'access_epoch', 'is_unread', 'email_message_id'],
                'em_msg_state_user_epoch_unread_ix',
            );
        });

        Schema::create('email_account_user_read_baselines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_account_id');
            $table->foreignId('user_id');
            $table->unsignedInteger('access_epoch')->default(1);
            $table->unsignedBigInteger('baseline_message_id')->default(0);
            $table->boolean('ordinary_view_entitled')->default(false);
            $table->string('source', 64);
            $table->string('source_reference', 191)->nullable();
            $table->foreignId('recorded_by')->nullable();
            $table->dateTime('recorded_at');
            $table->dateTime('entitlement_changed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('email_account_id', 'em_read_base_account_fk')
                ->references('id')->on('email_accounts')->cascadeOnDelete();
            $table->foreign('user_id', 'em_read_base_user_fk')
                ->references('id')->on('user_management')->cascadeOnDelete();
            $table->foreign('recorded_by', 'em_read_base_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->unique(
                ['email_account_id', 'user_id'],
                'em_read_base_account_user_uq',
            );
            $table->index(
                ['user_id', 'ordinary_view_entitled'],
                'em_read_base_user_entitled_ix',
            );
        });

        $this->backfillExistingOrdinaryViewEntitlements();
    }

    public function down(): void
    {
        $this->assertLegacyUniqueKeyCanBeRestoredWithoutDataLoss();

        Schema::dropIfExists('email_account_user_read_baselines');

        Schema::table('email_message_user_states', function (Blueprint $table): void {
            $table->dropUnique('em_msg_state_message_user_epoch_uq');
            $table->dropIndex('em_msg_state_user_epoch_unread_ix');
            $table->dropColumn('access_epoch');
            $table->unique(
                ['email_message_id', 'user_id'],
                'email_message_user_states_unique',
            );
        });
    }

    /**
     * Preserve the legacy missing-row-is-unread behavior for every ordinary
     * access source that already exists when the epoch contract is deployed.
     */
    private function backfillExistingOrdinaryViewEntitlements(): void
    {
        $entitledPairs = collect();
        $blockedPersonalDirectPairs = collect();

        DB::table('email_accounts')
            ->where('account_kind', EmailAccount::KIND_PERSONAL)
            ->whereNotNull('owner_id')
            ->get(['id', 'owner_id'])
            ->each(function (object $account) use ($entitledPairs): void {
                $entitledPairs->push([(int) $account->id, (int) $account->owner_id]);
            });

        DB::table('email_account_user_grants')
            ->join('email_accounts', 'email_accounts.id', '=', 'email_account_user_grants.email_account_id')
            ->where('email_account_user_grants.can_view', true)
            ->get([
                'email_account_user_grants.email_account_id',
                'email_account_user_grants.user_id',
                'email_accounts.account_kind',
            ])
            ->each(function (object $grant) use ($blockedPersonalDirectPairs, $entitledPairs): void {
                $pair = [(int) $grant->email_account_id, (int) $grant->user_id];

                if ($grant->account_kind === EmailAccount::KIND_PERSONAL) {
                    $blockedPersonalDirectPairs->push($pair);

                    return;
                }

                $entitledPairs->push($pair);
            });

        if (Schema::hasTable('email_mailbox_delegations')) {
            $now = now();

            DB::table('email_mailbox_delegations')
                ->join('email_accounts', 'email_accounts.id', '=', 'email_mailbox_delegations.email_account_id')
                ->where('email_accounts.account_kind', EmailAccount::KIND_PERSONAL)
                ->whereColumn('email_accounts.owner_id', 'email_mailbox_delegations.owner_id')
                ->where('email_mailbox_delegations.can_view', true)
                ->whereNull('email_mailbox_delegations.revoked_at')
                ->where('email_mailbox_delegations.starts_at', '<=', $now)
                ->where('email_mailbox_delegations.expires_at', '>', $now)
                ->whereNotNull('email_mailbox_delegations.delegate_id')
                ->get([
                    'email_mailbox_delegations.email_account_id',
                    'email_mailbox_delegations.delegate_id',
                ])
                ->each(function (object $delegation) use ($entitledPairs): void {
                    $entitledPairs->push([(int) $delegation->email_account_id, (int) $delegation->delegate_id]);
                });
        }

        $now = now();
        $entitledPairs = $entitledPairs
            ->unique(fn (array $pair): string => $pair[0].'|'.$pair[1])
            ->values();
        $entitledKeys = $entitledPairs
            ->mapWithKeys(fn (array $pair): array => [$pair[0].'|'.$pair[1] => true]);
        $rows = $entitledPairs
            ->map(fn (array $pair): array => [
                'email_account_id' => $pair[0],
                'user_id' => $pair[1],
                'access_epoch' => 1,
                'baseline_message_id' => 0,
                'ordinary_view_entitled' => true,
                'source' => 'legacy_migration',
                'source_reference' => 'migration:existing-access',
                'recorded_by' => null,
                'recorded_at' => $now,
                'entitlement_changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->concat(
                $blockedPersonalDirectPairs
                    ->unique(fn (array $pair): string => $pair[0].'|'.$pair[1])
                    ->reject(fn (array $pair): bool => $entitledKeys->has($pair[0].'|'.$pair[1]))
                    ->map(fn (array $pair): array => [
                        'email_account_id' => $pair[0],
                        'user_id' => $pair[1],
                        'access_epoch' => 1,
                        'baseline_message_id' => 0,
                        'ordinary_view_entitled' => false,
                        'source' => 'legacy_personal_direct_grant_blocked',
                        'source_reference' => 'migration:privacy-hardening',
                        'recorded_by' => null,
                        'recorded_at' => $now,
                        'entitlement_changed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]),
            )
            ->values();

        $rows->chunk(500)->each(function ($chunk): void {
            DB::table('email_account_user_read_baselines')->insertOrIgnore($chunk->all());
        });
    }

    /**
     * Legacy missing-row semantics can represent only epoch 1 at baseline 0
     * with an active entitlement. Block rollback instead of changing meaning
     * or deleting durable personal-state history.
     */
    private function assertLegacyUniqueKeyCanBeRestoredWithoutDataLoss(): void
    {
        $hasMultipleEpochs = DB::table('email_message_user_states')
            ->select(['email_message_id', 'user_id'])
            ->groupBy(['email_message_id', 'user_id'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $hasNonLegacyStateEpoch = DB::table('email_message_user_states')
            ->where('access_epoch', '!=', 1)
            ->exists();
        $hasNonLegacyBaseline = DB::table('email_account_user_read_baselines')
            ->where(function ($baselines): void {
                $baselines
                    ->where('access_epoch', '!=', 1)
                    ->orWhere('baseline_message_id', '!=', 0)
                    ->orWhere('ordinary_view_entitled', '!=', true);
            })
            ->exists();

        if ($hasMultipleEpochs || $hasNonLegacyStateEpoch || $hasNonLegacyBaseline) {
            throw new RuntimeException(
                'Cannot roll back Email unread epochs while non-legacy baseline or state semantics exist; '
                .'convert and preserve/export personal state before restoring the legacy interpretation.',
            );
        }
    }
};
