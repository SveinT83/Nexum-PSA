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
        'tickets.read' => [
            'label' => 'Read tickets',
            'description' => 'List and view ticket records.',
            'domain' => 'Tickets',
        ],
        'tickets.create' => [
            'label' => 'Create tickets',
            'description' => 'Create tickets through the ticket engine.',
            'domain' => 'Tickets',
        ],
        'tickets.update' => [
            'label' => 'Update tickets',
            'description' => 'Update ticket fields and status.',
            'domain' => 'Tickets',
        ],
        'tasks.read' => [
            'label' => 'Read tasks',
            'description' => 'List and view task records.',
            'domain' => 'Tasks',
        ],
        'tasks.create' => [
            'label' => 'Create tasks',
            'description' => 'Create task records.',
            'domain' => 'Tasks',
        ],
        'tasks.update' => [
            'label' => 'Update tasks',
            'description' => 'Update task fields and status.',
            'domain' => 'Tasks',
        ],
        'knowledge.read' => [
            'label' => 'Read knowledge',
            'description' => 'List and view knowledge articles.',
            'domain' => 'Knowledge',
        ],
        'knowledge.create' => [
            'label' => 'Create knowledge',
            'description' => 'Create knowledge articles.',
            'domain' => 'Knowledge',
        ],
        'knowledge.update' => [
            'label' => 'Update knowledge',
            'description' => 'Update knowledge articles.',
            'domain' => 'Knowledge',
        ],
        'storage.read' => [
            'label' => 'Read storage',
            'description' => 'List and view storage items, warehouses, and boxes.',
            'domain' => 'Storage',
        ],
        'storage.create' => [
            'label' => 'Create storage',
            'description' => 'Create storage items, warehouses, and boxes.',
            'domain' => 'Storage',
        ],
        'storage.update' => [
            'label' => 'Update storage',
            'description' => 'Update storage records and adjust item stock.',
            'domain' => 'Storage',
        ],
        'calendar.read' => [
            'label' => 'Read calendar',
            'description' => 'List calendars and view calendar events.',
            'domain' => 'Calendar',
        ],
        'calendar.create' => [
            'label' => 'Create calendar events',
            'description' => 'Create calendar events.',
            'domain' => 'Calendar',
        ],
        'calendar.update' => [
            'label' => 'Update calendar events',
            'description' => 'Update calendar events.',
            'domain' => 'Calendar',
        ],
        'calendar.delete' => [
            'label' => 'Delete calendar events',
            'description' => 'Delete calendar events.',
            'domain' => 'Calendar',
        ],
        'risk.read' => [
            'label' => 'Read risk',
            'description' => 'List and view risk assessments and risk items.',
            'domain' => 'Risk',
        ],
        'risk.create' => [
            'label' => 'Create risk',
            'description' => 'Create risk assessments and risk items.',
            'domain' => 'Risk',
        ],
        'risk.update' => [
            'label' => 'Update risk',
            'description' => 'Update risk assessments, risk items, and risk item history.',
            'domain' => 'Risk',
        ],
        'email.read' => [
            'label' => 'Read email inbox',
            'description' => 'List and view unrouted inbox messages.',
            'domain' => 'Email',
        ],
        'email.update' => [
            'label' => 'Update email inbox',
            'description' => 'Mark inbox messages as spam and queue inbox polling.',
            'domain' => 'Email',
        ],
        'notifications.read' => [
            'label' => 'Read notifications',
            'description' => 'List and view the authenticated user notifications.',
            'domain' => 'Notifications',
        ],
        'notifications.update' => [
            'label' => 'Update notifications',
            'description' => 'Mark the authenticated user notifications as read.',
            'domain' => 'Notifications',
        ],
        'sales.read' => [
            'label' => 'Read sales',
            'description' => 'List and view sales opportunities and activities.',
            'domain' => 'Sales',
        ],
        'sales.create' => [
            'label' => 'Create sales',
            'description' => 'Create sales opportunities through the sales engine.',
            'domain' => 'Sales',
        ],
        'sales.update' => [
            'label' => 'Update sales',
            'description' => 'Update sales opportunities and add sales activities.',
            'domain' => 'Sales',
        ],
        'taxonomy.read' => [
            'label' => 'Read taxonomy',
            'description' => 'List and view shared categories and tags.',
            'domain' => 'Taxonomy',
        ],
        'taxonomy.create' => [
            'label' => 'Create taxonomy',
            'description' => 'Create shared categories and tags.',
            'domain' => 'Taxonomy',
        ],
        'taxonomy.update' => [
            'label' => 'Update taxonomy',
            'description' => 'Update shared categories and tags.',
            'domain' => 'Taxonomy',
        ],
        'taxonomy.delete' => [
            'label' => 'Delete taxonomy',
            'description' => 'Soft-delete shared categories and tags.',
            'domain' => 'Taxonomy',
        ],
        'commercial.read' => [
            'label' => 'Read commercial',
            'description' => 'List and view commercial services, contracts, SLA policies, and time rates.',
            'domain' => 'Commercial',
        ],
        'commercial.create' => [
            'label' => 'Create commercial',
            'description' => 'Create commercial services, contracts, SLA policies, and time rates.',
            'domain' => 'Commercial',
        ],
        'commercial.update' => [
            'label' => 'Update commercial',
            'description' => 'Update commercial services, contracts, SLA policies, and time rates.',
            'domain' => 'Commercial',
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
