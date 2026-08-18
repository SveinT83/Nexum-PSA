<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Prevents attachment counter repairs from triggering the historical MySQL
 * received_at ON UPDATE defect before its dedicated schema repair is applied.
 */
class EmailAttachmentRecoveryReadiness
{
    /** @var array{safe: bool, reason_code: string}|null */
    private ?array $result = null;

    /** @return array{safe: bool, reason_code: string} */
    public function check(): array
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $connection = DB::connection();
        $schemaReason = 'non_mysql';

        if ($connection->getDriverName() === 'mysql') {
            try {
                $extra = $connection->table('information_schema.COLUMNS')
                    ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                    ->where('TABLE_NAME', (new EmailMessage)->getTable())
                    ->where('COLUMN_NAME', 'received_at')
                    ->value('EXTRA');
            } catch (Throwable $exception) {
                Log::warning('Email attachment recovery readiness check failed.', [
                    'reason' => 'column_metadata_read_failed',
                    'exception' => $exception::class,
                ]);

                return $this->result = ['safe' => false, 'reason_code' => 'column_metadata_read_failed'];
            }

            if (! is_string($extra)) {
                return $this->result = ['safe' => false, 'reason_code' => 'received_at_metadata_missing'];
            }

            if (str_contains(mb_strtolower($extra), 'on update')) {
                return $this->result = ['safe' => false, 'reason_code' => 'received_at_on_update_present'];
            }

            $schemaReason = 'received_at_schema_safe';
        }

        if (! $this->attachmentStorageWritable()) {
            return $this->result = ['safe' => false, 'reason_code' => 'attachment_storage_not_writable'];
        }

        return $this->result = ['safe' => true, 'reason_code' => $schemaReason];
    }

    private function attachmentStorageWritable(): bool
    {
        try {
            $disk = Storage::disk(EmailPrivateStorage::DISK);
            $root = realpath($disk->path(''));
            $candidate = $disk->path('email/attachments');

            if ($root === false || ! str_starts_with($candidate, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                return false;
            }

            while (! file_exists($candidate)) {
                $parent = dirname($candidate);
                if ($parent === $candidate || ! str_starts_with($parent, $root)) {
                    return false;
                }

                $candidate = $parent;
            }

            return is_dir($candidate)
                && is_readable($candidate)
                && is_writable($candidate)
                && is_executable($candidate);
        } catch (Throwable $exception) {
            Log::warning('Email attachment recovery readiness check failed.', [
                'reason' => 'attachment_storage_check_failed',
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
