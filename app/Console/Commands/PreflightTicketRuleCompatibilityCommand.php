<?php

namespace App\Console\Commands;

use App\Modules\Ticket\Actions\InspectTicketRuleCompatibility;
use Illuminate\Console\Command;
use Throwable;

class PreflightTicketRuleCompatibilityCommand extends Command
{
    protected $signature = 'ticket-rules:compatibility-preflight
        {--limit=100 : Maximum sanitized detail rows, from 1 through 500}';

    protected $description = 'Run the read-only Ticket Rule legacy compatibility preflight';

    public function handle(InspectTicketRuleCompatibility $inspection): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('Limit must be an integer from 1 through 500.');

            return self::INVALID;
        }

        try {
            $result = $inspection->handle($limit);
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
            $this->info('Read-only preflight complete. No Ticket, rule, queue, Signal, or external state was changed.');

            return ($result['status'] ?? null) === 'not_installed'
                ? self::FAILURE
                : self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Ticket Rule compatibility preflight could not be completed. No write was requested.');

            return self::FAILURE;
        }
    }
}
