<?php

namespace App\Console\Commands;

use App\Modules\Email\Services\EmailMessageReceivedAtRepairService;
use Illuminate\Console\Command;

class RepairEmailMessageReceivedAtCommand extends Command
{
    protected $signature = 'email:repair-received-at
        {--apply : Apply the audited local repair and narrowly recover exact-match false-stale suggestions}';

    protected $description = 'Preview or apply the migration-121200 Mail received-at repair scope';

    public function handle(EmailMessageReceivedAtRepairService $repair): int
    {
        $apply = (bool) $this->option('apply');
        $result = $repair->run($apply);

        $this->info($apply
            ? 'Mail received-at repair completed.'
            : 'Mail received-at repair preview completed; no data was changed.');
        $this->table(['Metric', 'Count'], [
            ['Exact migration scope', $result['scoped']],
            ['Pending ledger rows', $result['pending']],
            ['Already repaired', $result['already_repaired']],
            ['Repairable', $result['repairable']],
            ['Repaired this run', $result['repaired']],
            ['Already at evidence value', $result['unchanged']],
            ['Unresolved', $result['unresolved']],
            ['Smart suggestions recovered', $result['recovered_suggestions']],
        ]);

        $this->countTable('Evidence source', $result['sources']);
        $this->countTable('Unproven candidate source (not applied)', $result['candidates']);
        $this->countTable('Audit issue', $result['issues']);

        if (! $apply) {
            $this->newLine();
            $this->comment('Re-run with --apply only after reviewing this exact scope and evidence distribution.');
        }

        return $result['unresolved'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, int> $counts */
    private function countTable(string $heading, array $counts): void
    {
        if ($counts === []) {
            return;
        }

        $this->newLine();
        $this->line($heading.':');
        $this->table(
            ['Code', 'Count'],
            collect($counts)->map(fn (int $count, string $code): array => [$code, $count])->values()->all(),
        );
    }
}
