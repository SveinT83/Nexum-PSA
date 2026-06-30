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
        'custom-fields.read' => [
            'label' => 'Read custom fields',
            'description' => 'List and view custom field definitions for supported domains.',
            'domain' => 'Custom Fields',
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
        'contacts.ownership_manage' => [
            'label' => 'Repair contact ownership',
            'description' => 'Inspect, move, bulk repair, and detach Contact ownership across clients and legacy client users.',
            'domain' => 'Contacts',
        ],
        'marketing.read' => [
            'label' => 'Read marketing',
            'description' => 'List and view marketing lists, campaigns, recipients, and settings.',
            'domain' => 'Marketing',
        ],
        'marketing.lists.manage' => [
            'label' => 'Manage marketing lists',
            'description' => 'Create, update, delete, refresh, and manage contacts on marketing mailing lists.',
            'domain' => 'Marketing',
        ],
        'marketing.campaigns.create' => [
            'label' => 'Create marketing campaigns',
            'description' => 'Create marketing campaigns from mailing lists.',
            'domain' => 'Marketing',
        ],
        'marketing.campaigns.update' => [
            'label' => 'Update marketing campaigns',
            'description' => 'Update marketing campaigns, schedules, campaign emails, test sends, and AI draft requests.',
            'domain' => 'Marketing',
        ],
        'marketing.campaigns.approve' => [
            'label' => 'Approve marketing campaigns',
            'description' => 'Approve draft or paused marketing campaigns and materialize recipient queues.',
            'domain' => 'Marketing',
        ],
        'marketing.campaigns.send' => [
            'label' => 'Send marketing campaigns',
            'description' => 'Queue due-send processing for approved marketing campaigns.',
            'domain' => 'Marketing',
        ],
        'marketing.settings.update' => [
            'label' => 'Update marketing settings',
            'description' => 'Update consent, unsubscribe, tracking, quiet hours, and batching settings.',
            'domain' => 'Marketing',
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
            'description' => 'List and view knowledge shelves, books, chapters, articles, and documentation records.',
            'domain' => 'Knowledge',
        ],
        'knowledge.create' => [
            'label' => 'Create knowledge',
            'description' => 'Create knowledge shelves, books, chapters, articles, documentation categories, templates, and records.',
            'domain' => 'Knowledge',
        ],
        'knowledge.update' => [
            'label' => 'Update knowledge',
            'description' => 'Update or delete knowledge shelves, books, chapters, articles, and documentation records.',
            'domain' => 'Knowledge',
        ],
        'integration.bookstack.read' => [
            'label' => 'Read BookStack sync status',
            'description' => 'Read sanitized BookStack integration health, sync status, and sync summaries.',
            'domain' => 'Integration',
        ],
        'integration.bookstack.run' => [
            'label' => 'Run BookStack sync',
            'description' => 'Test the BookStack connection and run pull or push sync operations.',
            'domain' => 'Integration',
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
            'description' => 'Update storage records, adjust item stock, and soft-delete zero-stock items.',
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
        'lead-intelligence.read' => [
            'label' => 'Read lead intelligence',
            'description' => 'Read Lead Intelligence settings, segments, research runs, scan ledger, and policy results.',
            'domain' => 'Lead Intelligence',
        ],
        'lead-intelligence.manage' => [
            'label' => 'Manage lead intelligence',
            'description' => 'Update Lead Intelligence settings and manage lead segments.',
            'domain' => 'Lead Intelligence',
        ],
        'lead-intelligence.run' => [
            'label' => 'Run lead intelligence research',
            'description' => 'Create planned research runs, evaluate contact marketing eligibility, and promote approved candidates.',
            'domain' => 'Lead Intelligence',
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
        'economy.read' => [
            'label' => 'Read economy',
            'description' => 'List and view economy orders and generated order lines.',
            'domain' => 'Economy',
        ],
        'economy.create' => [
            'label' => 'Create economy',
            'description' => 'Generate economy orders from billable ticket time and picked ticket costs.',
            'domain' => 'Economy',
        ],
        'economy.update' => [
            'label' => 'Update economy',
            'description' => 'Move economy orders between draft and ready states.',
            'domain' => 'Economy',
        ],
        'economy.delete' => [
            'label' => 'Delete economy',
            'description' => 'Delete empty economy orders and draft order lines.',
            'domain' => 'Economy',
        ],
        'report.read' => [
            'label' => 'Read reports',
            'description' => 'List and view available report definitions.',
            'domain' => 'Reports',
        ],
        'signals.create' => [
            'label' => 'Create signals',
            'description' => 'Record normalized Signal events from integrations and webhooks.',
            'domain' => 'Signal',
        ],
        'users.read' => [
            'label' => 'Read users',
            'description' => 'List and view users, roles, and user profile metadata.',
            'domain' => 'User Management',
        ],
        'users.create' => [
            'label' => 'Create users',
            'description' => 'Create users and queue invitations for pending users.',
            'domain' => 'User Management',
        ],
        'users.update' => [
            'label' => 'Update users',
            'description' => 'Update user profiles, statuses, roles, and resend invitations.',
            'domain' => 'User Management',
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
