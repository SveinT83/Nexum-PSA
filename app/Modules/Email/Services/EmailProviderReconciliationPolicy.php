<?php

namespace App\Modules\Email\Services;

final class EmailProviderReconciliationPolicy
{
    public const HARD_MAX_FOLDERS = 500;

    // Ordinary runs must be able to reconcile every folder that the bounded
    // provider inventory reader is allowed to return.
    public const DEFAULT_MAX_FOLDERS = self::HARD_MAX_FOLDERS;

    public const DEFAULT_UID_BATCH_SIZE = 250;

    public const HARD_UID_BATCH_SIZE = 500;

    /**
     * IMAP SEARCH returns one unpaged result set. Readers must therefore search
     * no more than this numeric UID span per provider response, even when a
     * sparse mailbox has a much larger frozen UIDNEXT. The durable cursor makes
     * this a per-window safety bound rather than a mailbox-lifetime ceiling.
     */
    public const HARD_UID_WINDOW_SPAN = 10_000;

    /** No reconciliation import may request a body larger than 25 MiB. */
    public const HARD_MESSAGE_BYTES = 25 * 1024 * 1024;

    /** Provider headers are fetched as a cap-plus-one partial literal. */
    public const HARD_HEADER_BYTES = 256 * 1024;

    public const DEFAULT_MESSAGE_SIZE_LIMIT_MB = 25;

    public const PROVIDER_TIME_CAP_SECONDS = 10;

    public const DEFAULT_NORMAL_INTERVAL_SECONDS = 300;

    public function bodyByteCap(?int $configuredMegabytes): int
    {
        $megabytes = $configuredMegabytes && $configuredMegabytes > 0
            ? $configuredMegabytes
            : self::DEFAULT_MESSAGE_SIZE_LIMIT_MB;

        return min(self::HARD_MESSAGE_BYTES, $megabytes * 1024 * 1024);
    }

    /** @return array{max_folders: int, uid_batch_size: int, provider_time_cap_seconds: int, normal_interval_seconds: int} */
    public function bounds(
        ?int $maxFolders = null,
        ?int $uidBatchSize = null,
        ?int $normalIntervalSeconds = null,
    ): array {
        return [
            'max_folders' => max(1, min(
                self::HARD_MAX_FOLDERS,
                $maxFolders ?? self::DEFAULT_MAX_FOLDERS,
            )),
            'uid_batch_size' => max(1, min(
                self::HARD_UID_BATCH_SIZE,
                $uidBatchSize ?? self::DEFAULT_UID_BATCH_SIZE,
            )),
            'provider_time_cap_seconds' => self::PROVIDER_TIME_CAP_SECONDS,
            'normal_interval_seconds' => max(
                60,
                $normalIntervalSeconds ?? self::DEFAULT_NORMAL_INTERVAL_SECONDS,
            ),
        ];
    }
}
