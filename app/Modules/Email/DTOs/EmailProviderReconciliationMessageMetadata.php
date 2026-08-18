<?php

namespace App\Modules\Email\DTOs;

use InvalidArgumentException;

final readonly class EmailProviderReconciliationMessageMetadata
{
    /** @var array<int, string> */
    public array $customFlags;

    public string $customFlagsHash;

    /**
     * @param  array<int, string>  $customFlags
     */
    public function __construct(
        public int $uid,
        public ?int $modseq,
        public bool $seen,
        public bool $answered,
        public bool $flagged,
        public bool $deleted,
        public bool $draft,
        #[\SensitiveParameter] array $customFlags = [],
    ) {
        if ($uid < 1 || ($modseq !== null && $modseq < 0)) {
            throw new InvalidArgumentException('Provider message metadata is invalid.');
        }

        $this->customFlags = self::normalizeCustomFlags($customFlags);
        $this->customFlagsHash = hash(
            'sha256',
            json_encode($this->customFlags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * IMAP flag names are normalized case-insensitively, de-duplicated, and
     * byte-sorted so redelivery produces exactly the same evidence sequence.
     *
     * @param  array<int, string>  $flags
     * @return array<int, string>
     */
    public static function normalizeCustomFlags(#[\SensitiveParameter] array $flags): array
    {
        if (count($flags) > 128) {
            throw new InvalidArgumentException('Provider custom flag metadata is too large.');
        }

        $standard = [
            '\\seen',
            '\\answered',
            '\\flagged',
            '\\deleted',
            '\\draft',
            '\\recent',
        ];
        $normalized = [];
        $totalBytes = 0;

        foreach ($flags as $flag) {
            if (! is_string($flag)) {
                throw new InvalidArgumentException('Provider custom flags must be strings.');
            }
            $value = mb_strtolower(trim($flag));
            $totalBytes += strlen($value);
            if (mb_strlen($value) > 255 || $totalBytes > 8192) {
                throw new InvalidArgumentException('Provider custom flag metadata is too large.');
            }
            if ($value === '' || in_array($value, $standard, true)) {
                continue;
            }

            $normalized[$value] = $value;
        }

        $normalized = array_values($normalized);
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @return array<string, mixed> */
    public function evidenceFacts(): array
    {
        return [
            'uid' => $this->uid,
            'modseq' => $this->modseq,
            'seen' => $this->seen,
            'answered' => $this->answered,
            'flagged' => $this->flagged,
            'deleted' => $this->deleted,
            'draft' => $this->draft,
            'custom_flags' => $this->customFlags,
            'custom_flags_hash' => $this->customFlagsHash,
        ];
    }
}
