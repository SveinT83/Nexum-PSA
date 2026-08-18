<?php

namespace App\Modules\Email\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Builds a metadata-only reconciliation of the private Email filesystem.
 *
 * This service deliberately has no repair/delete API. An unreferenced file is
 * evidence for later review, not evidence that the payload may be removed.
 */
final class EmailPrivateStorageInventory
{
    public const DEFAULT_LIMIT = 10_000;

    public const MAX_LIMIT = 20_000;

    /**
     * @return array{
     *   total_files:int,
     *   referenced_files:int,
     *   unreferenced_files:int,
     *   missing_references:int,
     *   unsafe_entries:int,
     *   unreadable_files:int,
     *   non_private_files:int,
     *   truncated:bool,
     *   files:array<int, array<string, mixed>>,
     *   missing:array<int, array<string, string>>,
     *   duplicate_groups:array<int, array<string, mixed>>,
     *   reference_type_counts:array<string, int>,
     *   scope_counts:array<string, array{total:int,referenced:int,unreferenced:int}>
     * }
     */
    public function inspect(int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $disk = Storage::disk(EmailPrivateStorage::DISK);
        $root = $this->root($disk);
        $references = $this->references();
        $files = [];
        $unsafeEntries = 0;
        $unreadableFiles = 0;
        $nonPrivateFiles = 0;
        $truncated = false;
        $seen = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                $unsafeEntries++;

                continue;
            }

            if (! $entry->isFile()) {
                continue;
            }

            if (count($files) >= $limit) {
                $truncated = true;

                break;
            }

            $absolute = $entry->getPathname();
            $real = realpath($absolute);
            if ($real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                $unsafeEntries++;

                continue;
            }

            $relative = 'email/'.str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1));
            $readable = is_readable($real);
            $permissions = fileperms($real);
            $mode = $permissions === false ? null : ($permissions & 0777);
            $private = $mode === 0660;
            $checksum = $readable ? hash_file('sha1', $real) : false;

            if (! $readable || $checksum === false) {
                $unreadableFiles++;
            }
            if (! $private) {
                $nonPrivateFiles++;
            }

            $seen[$relative] = true;
            $referenceTypes = array_keys($references[$relative] ?? []);
            $files[] = [
                'path' => $relative,
                'path_id' => hash('sha256', $relative),
                'scope' => $this->scope($relative),
                'referenced' => $referenceTypes !== [],
                'reference_types' => $referenceTypes,
                'size_bytes' => $entry->getSize(),
                'modified_at' => gmdate('c', $entry->getMTime()),
                'mode' => $mode === null ? 'unknown' : sprintf('%04o', $mode),
                'group' => $this->groupName($entry->getGroup()),
                'checksum_sha1' => $checksum === false ? null : $checksum,
            ];
        }

        $missing = [];
        foreach ($references as $path => $types) {
            if (! isset($seen[$path])) {
                foreach (array_keys($types) as $type) {
                    $missing[] = [
                        'path' => $path,
                        'path_id' => hash('sha256', $path),
                        'reference_type' => $type,
                    ];
                }
            }
        }

        $unreferenced = array_values(array_filter($files, fn (array $file): bool => ! $file['referenced']));
        $duplicates = collect($unreferenced)
            ->filter(fn (array $file): bool => filled($file['checksum_sha1']))
            ->groupBy(fn (array $file): string => $file['checksum_sha1'].':'.$file['size_bytes'])
            ->filter(fn ($group): bool => $group->count() > 1)
            ->map(fn ($group): array => [
                'checksum_sha1' => $group->first()['checksum_sha1'],
                'size_bytes' => $group->first()['size_bytes'],
                'count' => $group->count(),
                'path_ids' => $group->pluck('path_id')->all(),
                'paths' => $group->pluck('path')->all(),
            ])
            ->values()
            ->all();

        $scopeCounts = collect($files)
            ->groupBy('scope')
            ->map(fn ($group): array => [
                'total' => $group->count(),
                'referenced' => $group->where('referenced', true)->count(),
                'unreferenced' => $group->where('referenced', false)->count(),
            ])
            ->all();

        $referenceTypeCounts = [];
        foreach ($references as $types) {
            foreach (array_keys($types) as $type) {
                $referenceTypeCounts[$type] = ($referenceTypeCounts[$type] ?? 0) + 1;
            }
        }
        ksort($referenceTypeCounts);

        return [
            'total_files' => count($files),
            'referenced_files' => count($files) - count($unreferenced),
            'unreferenced_files' => count($unreferenced),
            'missing_references' => count($missing),
            'unsafe_entries' => $unsafeEntries,
            'unreadable_files' => $unreadableFiles,
            'non_private_files' => $nonPrivateFiles,
            'truncated' => $truncated,
            'files' => $files,
            'missing' => $missing,
            'duplicate_groups' => $duplicates,
            'reference_type_counts' => $referenceTypeCounts,
            'scope_counts' => $scopeCounts,
        ];
    }

    private function root(FilesystemAdapter $disk): string
    {
        $root = realpath($disk->path('email'));
        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            throw new RuntimeException('The private Email storage root is unavailable.');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    /** @return array<string, array<string, true>> */
    private function references(): array
    {
        $references = [];

        $this->addColumnReferences($references, 'email_messages', 'raw_path', 'message_raw');
        $this->addColumnReferences($references, 'email_attachments', 'path', 'message_attachment');
        $this->addColumnReferences($references, 'email_composer_draft_attachments', 'path', 'draft_attachment');

        if (Schema::hasTable('email_sent_reconciliations')
            && Schema::hasColumn('email_sent_reconciliations', 'context_json')) {
            DB::table('email_sent_reconciliations')
                ->whereNotNull('context_json')
                ->orderBy('id')
                ->select(['id', 'context_json'])
                ->chunkById(250, function ($rows) use (&$references): void {
                    foreach ($rows as $row) {
                        $context = json_decode((string) $row->context_json, true);
                        $this->addReference(
                            $references,
                            is_array($context) ? (string) data_get($context, 'sent_raw_path', '') : '',
                            'sent_reconciliation',
                        );
                    }
                });
        }

        return $references;
    }

    /** @param array<string, array<string, true>> $references */
    private function addColumnReferences(array &$references, string $table, string $column, string $type): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->orderBy('id')
            ->select(['id', $column])
            ->chunkById(500, function ($rows) use (&$references, $column, $type): void {
                foreach ($rows as $row) {
                    $this->addReference($references, (string) $row->{$column}, $type);
                }
            });
    }

    /** @param array<string, array<string, true>> $references */
    private function addReference(array &$references, string $path, string $type): void
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($path === '' || ! str_starts_with($path, 'email/') || str_contains($path, '../')) {
            return;
        }

        $references[$path][$type] = true;
    }

    private function scope(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'email/attachments/') => 'attachments',
            str_starts_with($path, 'email/raw/') => 'raw',
            str_starts_with($path, 'email/sent-pending/') => 'sent_pending',
            default => 'other',
        };
    }

    private function groupName(int $groupId): string
    {
        if (function_exists('posix_getgrgid')) {
            $group = posix_getgrgid($groupId);
            if (is_array($group) && filled($group['name'] ?? null)) {
                return (string) $group['name'];
            }
        }

        return (string) $groupId;
    }
}
