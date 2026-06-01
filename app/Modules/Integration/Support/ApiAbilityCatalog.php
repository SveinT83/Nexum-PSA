<?php

namespace App\Modules\Integration\Support;

class ApiAbilityCatalog
{
    public const FULL_ACCESS = '*';

    private const ABILITIES = [
        'clients.read' => [
            'label' => 'Read clients',
            'description' => 'List and view client records.',
            'domain' => 'Clients',
        ],
        'clients.create' => [
            'label' => 'Create clients',
            'description' => 'Create client records and their default site.',
            'domain' => 'Clients',
        ],
        'clients.update' => [
            'label' => 'Update clients',
            'description' => 'Update client records and manage client sites.',
            'domain' => 'Clients',
        ],
        'assets.read' => [
            'label' => 'Read assets',
            'description' => 'List and view asset records.',
            'domain' => 'Assets',
        ],
        'assets.create' => [
            'label' => 'Create assets',
            'description' => 'Create asset records.',
            'domain' => 'Assets',
        ],
        'assets.update' => [
            'label' => 'Update assets',
            'description' => 'Update asset records and ownership context.',
            'domain' => 'Assets',
        ],
        'contacts.read' => [
            'label' => 'Read contacts',
            'description' => 'List and view contact records.',
            'domain' => 'Contacts',
        ],
        'contacts.create' => [
            'label' => 'Create contacts',
            'description' => 'Create contacts and use the contact upsert endpoint.',
            'domain' => 'Contacts',
        ],
        'contacts.update' => [
            'label' => 'Update contacts',
            'description' => 'Update contacts, including client and site relations.',
            'domain' => 'Contacts',
        ],
    ];

    public function all(): array
    {
        return self::ABILITIES;
    }

    public function values(): array
    {
        return array_keys(self::ABILITIES);
    }

    public function normalize(array $abilities, bool $fullAccess = false): array
    {
        if ($fullAccess) {
            return [self::FULL_ACCESS];
        }

        $selected = array_values(array_intersect(array_map('strval', $abilities), $this->values()));

        return $selected === [] ? $this->values() : $selected;
    }

    public function labelFor(string $ability): string
    {
        if ($ability === self::FULL_ACCESS) {
            return 'Full access';
        }

        return self::ABILITIES[$ability]['label'] ?? $ability;
    }
}
