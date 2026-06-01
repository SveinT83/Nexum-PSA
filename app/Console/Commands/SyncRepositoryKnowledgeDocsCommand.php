<?php

namespace App\Console\Commands;

use App\Modules\Integration\Jobs\PushPendingKnowledgeToBookStack;
use App\Modules\Knowledge\Actions\SyncRepositoryKnowledgeDocs;
use Illuminate\Console\Command;

class SyncRepositoryKnowledgeDocsCommand extends Command
{
    protected $signature = 'knowledge:sync-docs
        {--module=* : Limit sync to one or more module names, for example --module=Ticket}
        {--push : Queue the BookStack push worker after Knowledge records are updated}';

    protected $description = 'Sync repository Markdown documentation into Knowledge and optionally queue BookStack push';

    public function handle(SyncRepositoryKnowledgeDocs $sync): int
    {
        $summary = $sync->handle($this->option('module'));

        $this->line('chapters: '.$summary['chapters']);
        $this->line('articles: '.$summary['articles']);
        $this->line('skipped: '.$summary['skipped']);

        if ($summary['modules'] !== []) {
            $this->line('modules: '.implode(', ', $summary['modules']));
        }

        if ($this->option('push')) {
            PushPendingKnowledgeToBookStack::dispatch();
            $this->info('BookStack push queued.');
        }

        return self::SUCCESS;
    }
}
