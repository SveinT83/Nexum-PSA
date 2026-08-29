<?php

namespace App\Console\Commands;

use App\Modules\Ticket\Actions\BackfillTicketRuleCompatibilityVersions;
use Illuminate\Console\Command;
use Throwable;

class BackfillTicketRuleCompatibilityCommand extends Command
{
    protected $signature = 'ticket-rules:backfill-compatibility
        {--expected-generation= : Exact generation from the reviewed preflight}
        {--expected-checksum= : Exact catalogue checksum from the reviewed preflight}
        {--provenance-key= : Optional non-user deployment or operator provenance key}
        {--confirm-write : Confirm the additive compatibility-version write}';

    protected $description = 'Record gated, immutable Ticket Rule compatibility versions without changing runtime authority';

    public function handle(BackfillTicketRuleCompatibilityVersions $backfill): int
    {
        if (! $this->option('confirm-write')) {
            $this->error('The --confirm-write flag is required.');

            return self::INVALID;
        }

        $generation = filter_var($this->option('expected-generation'), FILTER_VALIDATE_INT);
        $checksum = strtolower(trim((string) $this->option('expected-checksum')));

        if ($generation === false || $generation < 0) {
            $this->error('Expected generation must be a non-negative integer.');

            return self::INVALID;
        }
        if (! preg_match('/\A[a-f0-9]{64}\z/', $checksum)) {
            $this->error('Expected checksum must be a lowercase SHA-256 value.');

            return self::INVALID;
        }

        try {
            $result = $backfill->handle(
                $generation,
                $checksum,
                $this->option('provenance-key') ?: null,
            );
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
            $this->info('Compatibility backfill complete. Legacy Ticket Rule runtime authority remains active.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Compatibility backfill refused the write. Run the read-only preflight again and review its result.');

            return self::FAILURE;
        }
    }
}
