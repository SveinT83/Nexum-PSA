<?php

namespace App\Modules\Email\DTOs;

use App\Modules\Email\Support\EmailProviderPath;
use InvalidArgumentException;

final readonly class EmailProviderReconciliationFolderDescriptor
{
    public string $path;

    public string $name;

    public ?string $delimiter;

    public ?string $parentPath;

    public ?string $remoteId;

    public ?string $specialUse;

    public bool $selectable;

    public bool $syncEnabled;

    public function __construct(
        #[\SensitiveParameter]
        string $path,
        #[\SensitiveParameter]
        string $name,
        ?string $delimiter = null,
        #[\SensitiveParameter]
        ?string $parentPath = null,
        #[\SensitiveParameter]
        ?string $remoteId = null,
        ?string $specialUse = null,
        bool $selectable = true,
        bool $syncEnabled = true,
    ) {
        $this->path = EmailProviderPath::normalize($path);
        $this->parentPath = EmailProviderPath::normalizeNullable($parentPath);

        if (trim($name) === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('Provider folder name is missing or too long.');
        }

        foreach ([
            'delimiter' => [$delimiter, 10],
            'remote ID' => [$remoteId, 1024],
            'special use' => [$specialUse, 80],
        ] as $label => [$value, $limit]) {
            if ($value !== null && mb_strlen($value) > $limit) {
                throw new InvalidArgumentException("Provider folder {$label} is too long.");
            }
        }

        $this->name = $name;
        $this->delimiter = $delimiter;
        $this->remoteId = $remoteId;
        $this->specialUse = $specialUse;
        $this->selectable = $selectable;
        $this->syncEnabled = $syncEnabled;
    }

    /** @return array<string, mixed> */
    public function scopeFacts(): array
    {
        return [
            'path' => $this->path,
            'remote_id_hash' => $this->remoteId === null ? null : hash('sha256', $this->remoteId),
            'selectable' => $this->selectable,
            'sync_enabled' => $this->syncEnabled,
            'special_use' => $this->specialUse,
        ];
    }
}
