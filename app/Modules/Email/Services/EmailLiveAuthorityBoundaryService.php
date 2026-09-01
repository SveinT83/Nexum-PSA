<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Publishes time-bound access transitions without scanning an unbounded mailbox audience. */
class EmailLiveAuthorityBoundaryService
{
    public function __construct(private readonly EmailLiveAuthorityCoordinator $authority) {}

    public function processDue(int $limit = 100): int
    {
        if (! Schema::hasTable('email_live_account_authority_states')) {
            return 0;
        }

        $limit = min(100, max(1, $limit));
        $delegationLimit = intdiv($limit + 1, 2);
        $breakGlassLimit = $limit - $delegationLimit;
        $processed = $this->processTable(
            table: 'email_mailbox_delegations',
            userColumn: 'delegate_id',
            limit: $delegationLimit,
        );

        return $processed + $this->processTable(
            table: 'email_break_glass_accesses',
            userColumn: 'actor_id',
            limit: $breakGlassLimit + max(0, $delegationLimit - $processed),
        );
    }

    private function processTable(string $table, string $userColumn, int $limit): int
    {
        if ($limit < 1 || ! Schema::hasTable($table)) {
            return 0;
        }

        $ids = DB::table($table)
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->where(function ($start): void {
                    $start->whereNull('email_live_start_invalidated_at')
                        ->where('starts_at', '<=', now());
                })->orWhere(function ($expiry): void {
                    $expiry->whereNull('email_live_expiry_invalidated_at')
                        ->where('expires_at', '<=', now());
                });
            })
            ->orderBy('id')
            ->limit(min(100, $limit))
            ->pluck('id');

        $processed = 0;
        foreach ($ids as $id) {
            $changed = DB::transaction(function () use ($table, $userColumn, $id): bool {
                $candidate = DB::table($table)->where('id', $id)->first();
                if (! $candidate) {
                    return false;
                }

                $account = EmailAccount::query()->lockForUpdate()->find($candidate->email_account_id);
                $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
                if (! $account || ! $row || $row->revoked_at !== null) {
                    return false;
                }

                $updates = [];
                if ($row->email_live_start_invalidated_at === null && CarbonImmutable::parse($row->starts_at)->lessThanOrEqualTo(now())) {
                    $updates['email_live_start_invalidated_at'] = now();
                }
                if ($row->email_live_expiry_invalidated_at === null && CarbonImmutable::parse($row->expires_at)->lessThanOrEqualTo(now())) {
                    $updates['email_live_expiry_invalidated_at'] = now();
                }
                if ($updates === []) {
                    return false;
                }

                $updates['email_live_enable_generation'] = $this->authority->prepareAccountMutation(
                    $account,
                    [(int) $row->{$userColumn}],
                );
                $updates['updated_at'] = now();
                DB::table($table)->where('id', $id)->update($updates);

                return true;
            }, 3);
            $processed += $changed ? 1 : 0;
        }

        return $processed;
    }
}
