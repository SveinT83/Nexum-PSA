<?php

namespace App\Modules\Email\Support;

use App\Models\Settings\CommonSetting;

/**
 * Resolves bounded, fail-closed attachment limits from Email-owned settings.
 */
final class InboundAttachmentPolicy
{
    public const DEFAULT_MAX_COUNT = 20;

    public const DEFAULT_MAX_SIZE_MB = 10;

    public const DEFAULT_ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/zip',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'image/gif',
        'image/jpeg',
        'image/png',
        'message/rfc822',
        'text/csv',
        'text/plain',
    ];

    private ?array $settings = null;

    public function maxCount(): int
    {
        return $this->boundedInteger('attachment_max_count', self::DEFAULT_MAX_COUNT, 1, 100);
    }

    public function maxBytes(): int
    {
        return $this->boundedInteger('attachment_max_size_mb', self::DEFAULT_MAX_SIZE_MB, 1, 1024) * 1024 * 1024;
    }

    public function allowedMimeTypes(): array
    {
        $configured = (string) ($this->setting('attachment_allowed_mime_types')
            ?? implode("\n", self::DEFAULT_ALLOWED_MIME_TYPES));

        return collect(preg_split('/[\s,;]+/', mb_strtolower($configured)) ?: [])
            ->map(fn (string $mime): string => trim($mime))
            ->filter(fn (string $mime): bool => $mime !== '' && str_contains($mime, '/'))
            ->unique()
            ->values()
            ->all();
    }

    public function allowsMimeType(?string $mimeType): bool
    {
        $mimeType = $this->normalizeMimeType($mimeType);
        if ($mimeType === null) {
            return false;
        }

        foreach ($this->allowedMimeTypes() as $allowed) {
            if ($allowed === $mimeType) {
                return true;
            }

            if (str_ends_with($allowed, '/*')
                && str_starts_with($mimeType, substr($allowed, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    public function normalizeMimeType(?string $mimeType): ?string
    {
        $normalized = mb_strtolower(trim((string) $mimeType));
        $normalized = trim(explode(';', $normalized, 2)[0]);

        return $normalized !== '' ? $normalized : null;
    }

    private function boundedInteger(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = filter_var($this->setting($name), FILTER_VALIDATE_INT);

        return min($maximum, max($minimum, $value === false ? $default : $value));
    }

    private function setting(string $name): mixed
    {
        $this->settings ??= CommonSetting::query()
            ->where('type', 'emailhub')
            ->pluck('value', 'name')
            ->all();

        return $this->settings[$name] ?? null;
    }
}
