<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\DTOs\EmailProviderReconciliationFolderDescriptor;

final class EmailProviderReconciliationFingerprint
{
    /**
     * @param  array<int, EmailProviderReconciliationFolderDescriptor>  $folders
     */
    public function folderScope(#[\SensitiveParameter] array $folders): string
    {
        usort(
            $folders,
            fn (EmailProviderReconciliationFolderDescriptor $left, EmailProviderReconciliationFolderDescriptor $right): int => strcmp(
                $left->path,
                $right->path,
            ),
        );

        return $this->make(array_map(
            fn (EmailProviderReconciliationFolderDescriptor $folder): array => $folder->scopeFacts(),
            $folders,
        ));
    }

    /** @param array<string|int, mixed> $facts */
    public function make(#[\SensitiveParameter] array $facts): string
    {
        return hash('sha256', json_encode(
            $this->normalize($facts),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @param array<string|int, mixed> $value */
    private function normalize(#[\SensitiveParameter] array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }
}
