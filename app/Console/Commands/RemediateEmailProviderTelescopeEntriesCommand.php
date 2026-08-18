<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory and optionally purge only historical Telescope entries capable of
 * containing Email provider endpoint or credential material. Entry content is
 * never printed or copied into application logs.
 */
final class RemediateEmailProviderTelescopeEntriesCommand extends Command
{
    private const MAX_LIMIT = 100000;

    /** @var list<string> */
    private const CONTENT_MARKERS = [
        'email_accounts',
        'integration_email_provider_connections',
        'integration_email_provider_credential_versions',
        'integration_email_provider_migration_items',
        'App\\Modules\\Email\\Models\\EmailAccount',
        'App\\Modules\\Integration\\Models\\EmailProviderConnection',
        'App\\Modules\\Integration\\Models\\EmailProviderCredentialVersion',
        'App\\Modules\\Integration\\Models\\EmailProviderMigrationItem',
        'App\\\\Modules\\\\Email\\\\Models\\\\EmailAccount',
        'App\\\\Modules\\\\Integration\\\\Models\\\\EmailProviderConnection',
        'App\\\\Modules\\\\Integration\\\\Models\\\\EmailProviderCredentialVersion',
        'App\\\\Modules\\\\Integration\\\\Models\\\\EmailProviderMigrationItem',
        'imap_secret',
        'imap_password',
        'smtp_secret',
        'smtp_password',
        'private_endpoint_reason',
        'trusted_cidr_name',
        'provider_secret',
        'provider_password',
        'email-providers',
        'settings/email/accounts',
    ];

    protected $signature = 'email-provider:telescope-remediate
        {--limit=20000 : Maximum matching entries to inventory (hard maximum 100000)}
        {--after-sequence=0 : Start strictly after this Telescope sequence}
        {--through-sequence= : End at this reviewed Telescope sequence; required for purge}
        {--cohort-hash= : Exact hash printed by the prior read-only preview; required for purge}
        {--purge : Delete exactly the inventoried provider-sensitive entries}
        {--acknowledge-observability-loss : Required with --purge}';

    protected $description = 'Preview or purge historical provider-sensitive Telescope entries without exposing their content.';

    public function handle(): int
    {
        $connection = config('telescope.storage.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('telescope_entries')) {
            $this->line('Telescope storage is absent; no provider-sensitive entries can be inventoried.');

            return self::SUCCESS;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_LIMIT],
        ]);
        if ($limit === false) {
            $this->error('--limit must be between 1 and '.self::MAX_LIMIT.'.');

            return self::INVALID;
        }

        $after = filter_var($this->option('after-sequence'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        $throughOption = $this->option('through-sequence');
        $through = $throughOption === null || $throughOption === ''
            ? null
            : filter_var($throughOption, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
        if ($after === false || $through === false || ($through !== null && $through <= $after)) {
            $this->error('Sequence bounds must be positive and --through-sequence must be greater than --after-sequence.');

            return self::INVALID;
        }

        if ($this->option('purge') && $through === null) {
            $this->error('--purge requires the exact --through-sequence printed by a prior read-only preview.');

            return self::INVALID;
        }
        $expectedCohortHash = trim((string) $this->option('cohort-hash'));
        if ($this->option('purge') && preg_match('/^[a-f0-9]{64}$/', $expectedCohortHash) !== 1) {
            $this->error('--purge requires the exact 64-character --cohort-hash printed by a prior preview.');

            return self::INVALID;
        }

        $query = $this->sensitiveEntries(DB::connection($connection)->table('telescope_entries'))
            ->where('sequence', '>', (int) $after)
            ->when($through !== null, fn (Builder $entries) => $entries->where('sequence', '<=', $through));
        $entries = (clone $query)
            ->orderBy('sequence')
            ->limit((int) $limit + 1)
            ->get(['sequence', 'uuid', 'type', 'content', 'created_at']);

        if ($entries->count() > (int) $limit) {
            $suggestedThrough = (int) $entries->get((int) $limit - 1)->sequence;
            $this->error('Inventory exceeded the bounded cohort; no purge was attempted.');
            $this->line('Re-run the read-only preview with --after-sequence='.(int) $after
                .' --through-sequence='.$suggestedThrough.', then purge that exact reviewed cohort.');

            return self::FAILURE;
        }

        $this->line('Provider Telescope remediation inventory; entry content was not printed or copied.');
        $this->table(
            ['Entry type', 'Count'],
            $entries->groupBy('type')->map(
                fn ($items, $type): array => [(string) $type, $items->count()],
            )->values()->all(),
        );
        $this->line('matched='.$entries->count());
        $cohortThrough = $entries->isEmpty() ? (int) $after : (int) $entries->max('sequence');
        $cohortHash = $this->cohortHash($entries);
        $this->line('cohort_after_sequence='.(int) $after);
        $this->line('cohort_through_sequence='.$cohortThrough);
        $this->line('cohort_hash='.$cohortHash);

        if (! $this->option('purge')) {
            $this->line('Read-only preview complete. Re-run with the same sequence bounds plus --purge --acknowledge-observability-loss after operator review.');

            return $entries->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        if (! $this->option('acknowledge-observability-loss')) {
            $this->error('--purge requires --acknowledge-observability-loss.');

            return self::INVALID;
        }

        try {
            $purged = DB::connection($connection)->transaction(function () use (
                $after,
                $connection,
                $expectedCohortHash,
                $limit,
                $schema,
                $through,
            ): int {
                $reviewed = $this->sensitiveEntries(DB::connection($connection)->table('telescope_entries'))
                    ->where('sequence', '>', (int) $after)
                    ->where('sequence', '<=', (int) $through)
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->limit((int) $limit + 1)
                    ->get(['sequence', 'uuid', 'type', 'content', 'created_at']);

                if ($reviewed->count() > (int) $limit
                    || ! hash_equals($expectedCohortHash, $this->cohortHash($reviewed))) {
                    throw new \RuntimeException('provider_telescope_cohort_changed');
                }

                foreach ($reviewed->chunk(500) as $chunk) {
                    $uuids = $chunk->pluck('uuid')->all();
                    if ($schema->hasTable('telescope_entries_tags')) {
                        DB::connection($connection)->table('telescope_entries_tags')
                            ->whereIn('entry_uuid', $uuids)
                            ->delete();
                    }
                    DB::connection($connection)->table('telescope_entries')
                        ->whereIn('uuid', $uuids)
                        ->delete();
                }

                return $reviewed->count();
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== 'provider_telescope_cohort_changed') {
                throw $exception;
            }

            $this->error('The reviewed Telescope cohort changed; nothing was purged. Run a new read-only preview.');

            return self::FAILURE;
        }

        $remaining = $this->sensitiveEntries(DB::connection($connection)->table('telescope_entries'))
            ->where('sequence', '>', (int) $after)
            ->where('sequence', '<=', (int) $through)
            ->exists();
        if ($remaining) {
            $this->error('Provider-sensitive Telescope entries remain; access must stay gated and human review remains blocked.');

            return self::FAILURE;
        }

        $this->info('Purged '.$purged.' provider-sensitive Telescope entries. Unrelated Telescope history was preserved.');
        $this->line('Run the next read-only cohort with --after-sequence='.(int) $through.'.');

        return self::SUCCESS;
    }

    private function sensitiveEntries(Builder $query): Builder
    {
        $driver = $query->getConnection()->getDriverName();

        return $query->where(function (Builder $entries) use ($driver): void {
            foreach (self::CONTENT_MARKERS as $marker) {
                if ($driver === 'sqlite') {
                    $entries->orWhereRaw('instr(content, ?) > 0', [$marker]);
                } elseif ($driver === 'pgsql') {
                    $entries->orWhereRaw('position(? in content) > 0', [$marker]);
                } else {
                    $entries->orWhereRaw('locate(?, content) > 0', [$marker]);
                }
            }
        });
    }

    /**
     * Bind the preview to exact ordered UUIDs and entry content without ever
     * printing the sensitive content itself. A row changed between preview and
     * purge invalidates the cohort and requires a new review.
     */
    private function cohortHash(\Illuminate\Support\Collection $entries): string
    {
        $context = hash_init('sha256');

        foreach ($entries as $entry) {
            hash_update($context, implode("\0", [
                (string) $entry->sequence,
                (string) $entry->uuid,
                hash('sha256', (string) $entry->content),
            ])."\n");
        }

        return hash_final($context);
    }
}
