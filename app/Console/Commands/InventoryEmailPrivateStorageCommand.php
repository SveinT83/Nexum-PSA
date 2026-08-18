<?php

namespace App\Console\Commands;

use App\Modules\Email\Services\EmailPrivateStorageInventory;
use Illuminate\Console\Command;
use Throwable;

class InventoryEmailPrivateStorageCommand extends Command
{
    protected $signature = 'email:inventory-private-storage
        {--limit=10000 : Maximum files to inspect (hard maximum 20000)}
        {--show-paths : Print private relative paths instead of redacted stable path IDs}';

    protected $description = 'Read-only reconciliation of private Email files and database references.';

    public function handle(EmailPrivateStorageInventory $inventory): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => EmailPrivateStorageInventory::MAX_LIMIT],
        ]);
        if ($limit === false) {
            $this->error('--limit must be between 1 and '.EmailPrivateStorageInventory::MAX_LIMIT.'.');

            return self::INVALID;
        }

        try {
            $result = $inventory->inspect((int) $limit);
        } catch (Throwable $exception) {
            $this->error('Private Email storage inventory failed before completion.');
            report($exception);

            return self::FAILURE;
        }

        $this->line('Read-only inventory; no file, permission, database, provider, queue, or retention state was changed.');
        $this->table(['Metric', 'Count'], [
            ['files', $result['total_files']],
            ['referenced files', $result['referenced_files']],
            ['unreferenced files', $result['unreferenced_files']],
            ['missing references', $result['missing_references']],
            ['unsafe entries', $result['unsafe_entries']],
            ['unreadable files', $result['unreadable_files']],
            ['non-private modes', $result['non_private_files']],
            ['duplicate unreferenced groups', count($result['duplicate_groups'])],
        ]);

        foreach ($result['scope_counts'] as $scope => $counts) {
            $this->line(sprintf(
                'scope=%s total=%d referenced=%d unreferenced=%d',
                $scope,
                $counts['total'],
                $counts['referenced'],
                $counts['unreferenced'],
            ));
        }

        foreach (array_filter($result['files'], fn (array $file): bool => ! $file['referenced']) as $file) {
            $this->line(sprintf(
                'unreferenced scope=%s file=%s bytes=%d modified=%s mode=%s group=%s sha1=%s',
                $file['scope'],
                $this->path($file, (bool) $this->option('show-paths')),
                $file['size_bytes'],
                $file['modified_at'],
                $file['mode'],
                $file['group'],
                $file['checksum_sha1'] ?? 'unavailable',
            ));
        }

        foreach ($result['missing'] as $missing) {
            $this->line(sprintf(
                'missing reference=%s file=%s',
                $missing['reference_type'],
                $this->path($missing, (bool) $this->option('show-paths')),
            ));
        }

        foreach ($result['duplicate_groups'] as $group) {
            $files = (bool) $this->option('show-paths') ? $group['paths'] : $group['path_ids'];
            $this->line(sprintf(
                'duplicate sha1=%s bytes=%d count=%d files=%s',
                $group['checksum_sha1'],
                $group['size_bytes'],
                $group['count'],
                implode(',', array_map(fn (string $path): string => (bool) $this->option('show-paths') ? $path : substr($path, 0, 16), $files)),
            ));
        }

        if ($result['truncated']) {
            $this->error('Inventory exceeded the selected file limit and is incomplete.');
        }

        $failed = $result['truncated']
            || $result['missing_references'] > 0
            || $result['unsafe_entries'] > 0
            || $result['unreadable_files'] > 0
            || $result['non_private_files'] > 0;

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param array{path:string,path_id:string} $item */
    private function path(array $item, bool $showPaths): string
    {
        return $showPaths ? $item['path'] : substr($item['path_id'], 0, 16);
    }
}
