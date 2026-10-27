<?php

namespace App\Modules\System\Queries;

/** One registry preserves the current Main browser destinations and API discovery. */
final class SystemAdminNavigation
{
    public function all(): array
    {
        $adminSections = [
            [
                'title' => 'Commercial',
                'icon' => 'bi-briefcase',
                'description' => 'Configure contracts, service catalogue, rates, and commercial units.',
                'links' => [
                    ['label' => 'Contracts', 'route' => route('tech.admin.settings.cs.contracts')],
                    ['label' => 'Services', 'route' => route('tech.admin.settings.cs.services')],
                    ['label' => 'Units', 'route' => route('tech.admin.settings.economy.units')],
                ],
            ],
            [
                'title' => 'Economy',
                'icon' => 'bi-receipt',
                'description' => 'Control order and billing settings used by ticket time and sales.',
                'links' => [
                    ['label' => 'Orders and billing', 'route' => route('tech.admin.settings.economy')],
                ],
            ],
            [
                'title' => 'Email',
                'icon' => 'bi-envelope-at',
                'description' => 'Manage mail accounts, routing rules, parser config, and templates.',
                'links' => [
                    ['label' => 'Accounts', 'route' => route('tech.admin.settings.email.accounts')],
                    ['label' => 'Config', 'route' => route('tech.admin.settings.email.config')],
                    ['label' => 'Rules', 'route' => route('tech.admin.settings.email.rules')],
                    ['label' => 'Templates', 'route' => route('tech.admin.system.templatesManagement.email.index')],
                ],
            ],
            [
                'title' => 'Sales',
                'icon' => 'bi-graph-up-arrow',
                'description' => 'Tune sales rules, quote templates, and opportunity behavior.',
                'links' => [
                    ['label' => 'Rules', 'route' => route('tech.admin.settings.sales.rules')],
                    ['label' => 'Quote Templates', 'route' => route('tech.admin.settings.sales.quote-templates.index')],
                ],
            ],
            [
                'title' => 'Marketing',
                'icon' => 'bi-megaphone',
                'description' => 'Control campaign consent, unsubscribe behavior, tracking defaults, and send batching.',
                'links' => [
                    ['label' => 'Marketing settings', 'route' => route('tech.admin.settings.marketing')],
                    ['label' => 'Email accounts', 'route' => route('tech.admin.settings.email.accounts')],
                    ['label' => 'Email templates', 'route' => route('tech.admin.system.templatesManagement.email.index')],
                ],
            ],
            [
                'title' => 'Lead Intelligence',
                'icon' => 'bi-search-heart',
                'description' => 'Configure prospecting policy, segment targeting, planned research runs, and suppression-aware contact eligibility.',
                'links' => [
                    ['label' => 'Settings', 'route' => route('tech.admin.settings.lead-intelligence')],
                    ['label' => 'Segments', 'route' => route('tech.lead-intelligence.segments.index')],
                    ['label' => 'Research runs', 'route' => route('tech.lead-intelligence.runs.index')],
                    ['label' => 'Scan ledger', 'route' => route('tech.lead-intelligence.scan-ledger.index')],
                ],
            ],
            [
                'title' => 'Clients',
                'icon' => 'bi-buildings',
                'description' => 'Client and contact domain settings and reusable customer classifications.',
                'links' => [
                    ['label' => 'Client formats', 'route' => route('tech.admin.settings.clients.client-formats')],
                    ['label' => 'Contact settings', 'route' => route('tech.admin.settings.contacts')],
                ],
            ],
            [
                'title' => 'Calendar',
                'icon' => 'bi-calendar-week',
                'description' => 'Shared calendars, default calendar behavior, access, privacy, and scheduling rules.',
                'links' => [
                    ['label' => 'Calendar settings', 'route' => route('tech.admin.settings.calendar')],
                ],
            ],
            [
                'title' => 'Storage',
                'icon' => 'bi-box-seam',
                'description' => 'Inventory administration, warehouse structure, stock defaults, and supplier-order automation.',
                'links' => [
                    ['label' => 'Inventory settings', 'route' => route('tech.admin.settings.storage.inventory')],
                    ['label' => 'Supplier order automation', 'route' => route('tech.admin.settings.storage.purchase-order-automation.edit')],
                    ['label' => 'Supplier profiles', 'route' => route('tech.admin.settings.storage.supplier-order-profiles.index')],
                ],
            ],
            [
                'title' => 'Assets',
                'icon' => 'bi-hdd-network',
                'description' => 'Configure manual asset registration defaults and available asset types.',
                'links' => [
                    ['label' => 'Asset settings', 'route' => route('tech.admin.settings.assets')],
                ],
            ],
            [
                'title' => 'Tickets',
                'icon' => 'bi-ticket-detailed',
                'description' => 'Queues, priorities, workflows, assignment logic, task defaults, and ticket rules.',
                'links' => [
                    ['label' => 'Ticket settings', 'route' => route('tech.admin.settings.tickets')],
                    ['label' => 'Task settings', 'route' => route('tech.admin.settings.tasks')],
                    ['label' => 'Task Templates', 'route' => route('tech.admin.task-templates.index')],
                    ['label' => 'Technicians', 'route' => route('tech.admin.settings.tickets.technicians')],
                    ['label' => 'Assignment rules', 'route' => route('tech.admin.settings.tickets.assignment-rules')],
                    ['label' => 'Rules', 'route' => route('tech.admin.settings.tickets.rules')],
                    ['label' => 'Workflows', 'route' => route('tech.admin.settings.tickets.workflows')],
                ],
            ],
            [
                'title' => 'Templates',
                'icon' => 'bi-layout-text-window-reverse',
                'description' => 'Reusable document, email, and Knowledge defaults used across modules.',
                'links' => [
                    ['label' => 'Knowledge settings', 'route' => route('tech.admin.settings.knowledge')],
                    ['label' => 'Template management', 'route' => route('tech.admin.system.templatesManagement.index')],
                ],
            ],
            [
                'title' => 'Users',
                'icon' => 'bi-people',
                'description' => 'Users, roles, permissions, and account-level access settings.',
                'links' => [
                    ['label' => 'User management', 'route' => route('tech.admin.user_management.index')],
                    ['label' => 'Roles', 'route' => route('tech.admin.user_management.roles.index')],
                    ['label' => 'Permissions', 'route' => route('tech.admin.user_management.permissions.index')],
                    ['label' => 'Two-factor auth', 'route' => route('tech.admin.user_management.2fa-settings')],
                ],
            ],
            [
                'title' => 'System',
                'icon' => 'bi-sliders',
                'description' => 'Company profile, branding, shared taxonomy, background workers, and platform settings.',
                'links' => [
                    ['label' => 'Company profile', 'route' => route('tech.admin.system.company-profile.edit')],
                    ['label' => 'Branding', 'route' => route('tech.admin.system.branding.edit')],
                    ['label' => 'Warroom', 'route' => route('tech.admin.settings.warroom')],
                    ['label' => 'Risk settings', 'route' => route('tech.admin.settings.risk')],
                    ['label' => 'Custom fields', 'route' => route('tech.admin.settings.custom-fields.index')],
                    ['label' => 'Categories', 'route' => route('tech.admin.system.category.index')],
                    ['label' => 'Tags', 'route' => route('tech.admin.system.tag.index')],
                    ['label' => 'Queues and workers', 'route' => route('tech.admin.system.queues-workers.index')],
                    ['label' => 'Notification channels', 'route' => route('tech.admin.notification-channels.index')],
                    ['label' => 'Signal feed', 'route' => route('tech.admin.system.signals.index')],
                    ['label' => 'Signal rules', 'route' => route('tech.admin.system.signals.rules.index')],
                    ['label' => 'Signal settings', 'route' => route('tech.admin.system.signals.settings.edit')],
                ],
            ],
            [
                'title' => 'Integrations',
                'icon' => 'bi-plug',
                'description' => 'External systems, API access, AI providers, and sync connections.',
                'links' => [
                    ['label' => 'All integrations', 'route' => route('tech.admin.system.integrations.index')],
                    ['label' => 'Nexum relationships', 'route' => route('tech.admin.system.relationships.index')],
                    ['label' => 'N-able RMM', 'route' => route('tech.admin.system.integrations.nable_rmm.settings')],
                    ['label' => 'Tactical RMM', 'route' => route('tech.admin.system.integrations.tactical_rmm.settings')],
                    ['label' => 'BookStack', 'route' => route('tech.admin.system.integrations.book_stack.settings')],
                    ['label' => 'Nextcloud', 'route' => route('tech.admin.nextcloud.connections.index')],
                    ['label' => 'API management', 'route' => route('tech.admin.system.integrations.api.index')],
                    ['label' => 'AI settings', 'route' => route('tech.admin.system.integrations.ai.index')],
                ],
            ],
            [
                'title' => 'Intake',
                'icon' => 'bi-inboxes',
                'description' => 'Configure public inquiry forms, review submissions, attachments, and Sales routing.',
                'links' => [
                    ['label' => 'Forms and submissions', 'route' => route('tech.admin.system.intake.index')],
                    ['label' => 'New form', 'route' => route('tech.admin.system.intake.forms.create')],
                ],
            ],
            [
                'title' => 'Booking',
                'icon' => 'bi-calendar-check',
                'description' => 'Configure bookable services, public slot requests, staff confirmation, and Calendar handoff.',
                'links' => [
                    ['label' => 'Services and requests', 'route' => route('tech.admin.system.booking.index')],
                    ['label' => 'New booking service', 'route' => route('tech.admin.system.booking.settings.create')],
                ],
            ],
            [
                'title' => 'Data Exchange',
                'icon' => 'bi-arrow-left-right',
                'description' => 'Reusable import/export profiles, run history, generated files, and safe data source registration.',
                'links' => [
                    ['label' => 'Profiles and runs', 'route' => route('tech.admin.system.data-exchange.index')],
                ],
            ],
        ];
        if (auth()->user()?->can('workday.manage_settings')) {
            $adminSections[] = [
                'title' => 'Workday', 'icon' => 'bi-clock-history',
                'description' => 'Employee actual-time activation and retention policy.',
                'links' => [['label' => 'Workday settings', 'route' => route('tech.admin.settings.workday')]],
            ];
        }

        return $adminSections;
    }
}
