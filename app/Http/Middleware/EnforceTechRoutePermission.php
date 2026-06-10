<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class EnforceTechRoutePermission
{
    /**
     * Route names that are user-owned surfaces rather than domain permission surfaces.
     */
    private const EXEMPT_ROUTE_PATTERNS = [
        'tech.invite.*',
        'tech.profile.*',
        'tech.context.set',
    ];

    /**
     * Map tech route names to the permission required to open or mutate that surface.
     * More specific patterns must stay before broader domain fallbacks.
     */
    private const ROUTE_PERMISSION_PATTERNS = [
        'tech.dashboard' => 'warroom.view',
        'tech.reports.*' => 'report.view',
        'tech.admin.system.signals.rules.create' => 'signal.rule.manage',
        'tech.admin.system.signals.rules.store' => 'signal.rule.manage',
        'tech.admin.system.signals.rules.update' => 'signal.rule.manage',
        'tech.admin.system.signals.rules.*' => 'signal.view',
        'tech.admin.system.signals.*' => 'signal.view',
        'tech.signals.rules.create' => 'signal.rule.manage',
        'tech.signals.rules.store' => 'signal.rule.manage',
        'tech.signals.rules.update' => 'signal.rule.manage',
        'tech.signals.rules.*' => 'signal.view',
        'tech.signals.*' => 'signal.view',
        'tech.admin.settings.marketing*' => 'marketing.settings.manage',
        'tech.marketing.lists.create' => 'marketing.list.manage',
        'tech.marketing.lists.store' => 'marketing.list.manage',
        'tech.marketing.lists.refresh' => 'marketing.list.manage',
        'tech.marketing.campaigns.create' => 'marketing.campaign.create',
        'tech.marketing.campaigns.store' => 'marketing.campaign.create',
        'tech.marketing.campaigns.emails.*' => 'marketing.campaign.edit',
        'tech.marketing.campaigns.approve' => 'marketing.campaign.approve',
        'tech.marketing.campaigns.send-due' => 'marketing.campaign.send',
        'tech.marketing.*' => 'marketing.view',

        'tech.admin.index' => 'system.view',
        'tech.admin.notification-channels.*' => 'notification.manage_channels',

        'tech.admin.user_management.roles.*' => 'user.manage_roles',
        'tech.admin.user_management.permissions.*' => 'user.manage_permissions',
        'tech.admin.user_management.2fa-settings*' => 'user.manage_2fa',
        'tech.admin.user_management.invite.*' => 'user.invite',
        'tech.admin.user_management.create' => 'user.create',
        'tech.admin.user_management.store' => 'user.create',
        'tech.admin.user_management.status.*' => 'user.update',
        'tech.admin.user_management.profile.update' => 'user.update',
        'tech.admin.user_management.roles.update-user' => 'user.manage_roles',
        'tech.admin.user_management.show' => 'user.view',
        'tech.admin.user_management.*' => 'user.view',

        'tech.admin.settings.clients.*' => 'client.manage_settings',
        'tech.admin.settings.custom-fields.*' => 'customfield.manage_settings',
        'tech.admin.settings.calendar' => 'calendar.manage_settings',
        'tech.admin.settings.calendar.*' => 'calendar.manage_settings',
        'tech.admin.settings.economy' => 'economy.manage_settings',
        'tech.admin.settings.economy.rates.*' => 'commercial.rate_manage',
        'tech.admin.settings.economy.units.*' => 'economy.manage_settings',
        'tech.admin.settings.economy.*' => 'economy.manage_settings',
        'tech.admin.settings.email.accounts' => 'email.account_manage',
        'tech.admin.settings.email.accounts.*' => 'email.account_manage',
        'tech.admin.settings.email.config' => 'email.account_manage',
        'tech.admin.settings.email.config.*' => 'email.account_manage',
        'tech.admin.settings.email.rules' => 'email.rule_manage',
        'tech.admin.settings.email.rules.*' => 'email.rule_manage',
        'tech.admin.settings.assets' => 'asset.manage_settings',
        'tech.admin.settings.assets.*' => 'asset.manage_settings',
        'tech.admin.settings.contacts' => 'contact.manage_settings',
        'tech.admin.settings.contacts.*' => 'contact.manage_settings',
        'tech.admin.settings.warroom' => 'warroom.manage_settings',
        'tech.admin.settings.warroom.*' => 'warroom.manage_settings',
        'tech.admin.settings.sales.*' => 'sales.manage_settings',
        'tech.admin.settings.storage.*' => 'storage.manage_settings',
        'tech.admin.settings.tickets' => 'ticket.manage_settings',
        'tech.admin.settings.tickets.rules.*' => 'ticket.manage_rules',
        'tech.admin.settings.tickets.workflows.*' => 'ticket.manage_workflows',
        'tech.admin.settings.tickets.assignment-rules.*' => 'ticket.manage_rules',
        'tech.admin.settings.tickets.*' => 'ticket.manage_settings',
        'tech.admin.settings.tasks' => 'task.manage_settings',
        'tech.admin.settings.tasks.*' => 'task.manage_settings',
        'tech.admin.settings.knowledge' => 'knowledge.manage_settings',
        'tech.admin.settings.knowledge.*' => 'knowledge.manage_settings',
        'tech.admin.settings.risk' => 'risk.manage_settings',
        'tech.admin.settings.risk.*' => 'risk.manage_settings',
        'tech.admin.settings.cs.timebank-policy*' => 'commercial.contract_manage',
        'tech.admin.settings.cs.*' => 'commercial.view',

        'tech.admin.system.category.*' => 'taxonomy.manage_categories',
        'tech.admin.system.tag.*' => 'taxonomy.manage_tags',
        'tech.admin.system.integrations.ai.*' => 'integration.ai_manage',
        'tech.admin.system.integrations.api.*' => 'integration.api_manage',
        'tech.admin.system.integrations.book-stack.*' => 'integration.bookstack_manage',
        'tech.admin.system.integrations.nable-rmm.*' => 'integration.rmm_manage',
        'tech.admin.system.integrations.tactical-rmm.*' => 'integration.rmm_manage',
        'tech.admin.system.integrations.*' => 'integration.view',
        'tech.admin.system.company-profile.*' => 'system.settings_manage',
        'tech.admin.system.branding.*' => 'system.settings_manage',
        'tech.admin.nextcloud.connections.sync' => 'nextcloud.sync',
        'tech.admin.nextcloud.connections.test-talk-bot' => 'nextcloud.talk_manage',
        'tech.admin.nextcloud.connections.users' => 'nextcloud.user_mapping_manage',
        'tech.admin.nextcloud.connections.groups' => 'nextcloud.user_mapping_manage',
        'tech.admin.nextcloud.connections.calendars' => 'nextcloud.sync',
        'tech.admin.nextcloud.connections.folders*' => 'nextcloud.folder_manage',
        'tech.admin.nextcloud.user-mappings.*' => 'nextcloud.user_mapping_manage',
        'tech.admin.nextcloud.group-mappings.*' => 'nextcloud.user_mapping_manage',
        'tech.admin.nextcloud.calendar-mappings.*' => 'nextcloud.sync',
        'tech.admin.nextcloud.folder-mappings.*' => 'nextcloud.folder_manage',
        'tech.admin.nextcloud.*' => 'nextcloud.connection_manage',
        'tech.admin.system.queues-workers.*' => 'system.queue_manage',
        'tech.admin.system.templatesManagement.email.*' => 'email.template_manage',
        'tech.admin.system.templatesManagement.doc.*' => 'documentation.manage_templates',
        'tech.admin.system.templatesManagement.*' => 'system.settings_manage',

        'tech.clients.assets.*' => 'asset.view',
        'tech.clients.contracts.timebank-consumptions.store' => 'commercial.timebank.quick-consume',
        'tech.clients.time-usage.update' => 'client.view',
        'tech.clients.settings.*' => 'client.manage_settings',
        'tech.clients.create' => 'client.create',
        'tech.clients.store' => 'client.create',
        'tech.clients.edit' => 'client.update',
        'tech.clients.update' => 'client.update',
        'tech.clients.delete' => 'client.delete',
        'tech.clients.destroy' => 'client.delete',
        'tech.clients.*.delete' => 'client.delete',
        'tech.clients.*.destroy' => 'client.delete',
        'tech.clients.*.create' => 'client.create',
        'tech.clients.*.store' => 'client.create',
        'tech.clients.*.edit' => 'client.update',
        'tech.clients.*.update' => 'client.update',
        'tech.clients.*' => 'client.view',
        'tech.client.*' => 'client.view',
        'tech.contacts.context.clear' => 'contact.view',
        'tech.contacts.create' => 'contact.create',
        'tech.contacts.store' => 'contact.create',
        'tech.contacts.edit' => 'contact.update',
        'tech.contacts.update' => 'contact.update',
        'tech.contacts.destroy' => 'contact.delete',
        'tech.contacts.*' => 'contact.view',

        'tech.assets.create' => 'asset.create',
        'tech.assets.store' => 'asset.create',
        'tech.assets.edit' => 'asset.update',
        'tech.assets.update' => 'asset.update',
        'tech.assets.delete' => 'asset.delete',
        'tech.assets.destroy' => 'asset.delete',
        'tech.assets.*.create' => 'asset.create',
        'tech.assets.*.store' => 'asset.create',
        'tech.assets.*.edit' => 'asset.update',
        'tech.assets.*.update' => 'asset.update',
        'tech.assets.*.delete' => 'asset.delete',
        'tech.assets.*.destroy' => 'asset.delete',
        'tech.assets.*' => 'asset.view',

        'tech.tickets.assign' => 'ticket.assign',
        'tech.tickets.close' => 'ticket.close',
        'tech.tickets.messages.store' => 'ticket.reply_customer',
        'tech.tickets.time-entries.*' => 'ticket.register_time',
        'tech.tickets.create' => 'ticket.create',
        'tech.tickets.store' => 'ticket.create',
        'tech.tickets.edit' => 'ticket.update',
        'tech.tickets.update' => 'ticket.update',
        'tech.tickets.merge' => 'ticket.update',
        'tech.tickets.documentation-request' => 'ticket.update',
        'tech.tickets.not-ticket' => 'ticket.delete',
        'tech.tickets.destroy' => 'ticket.delete',
        'tech.tickets.workflow.*' => 'ticket.update',
        'tech.tickets.cost-entries.*' => 'ticket.update',
        'tech.tickets.*' => 'ticket.view',

        'tech.tasks.assign' => 'task.assign',
        'tech.tasks.complete' => 'task.complete',
        'tech.tasks.create' => 'task.create',
        'tech.tasks.store' => 'task.create',
        'tech.tasks.edit' => 'task.update',
        'tech.tasks.update' => 'task.update',
        'tech.tasks.status.*' => 'task.update',
        'tech.tasks.checklist.*' => 'task.update',
        'tech.tasks.*' => 'task.view',

        'tech.calendar.events.store' => 'calendar.create',
        'tech.calendar.events.update' => 'calendar.update',
        'tech.calendar.events.destroy' => 'calendar.delete',
        'tech.calendar.*' => 'calendar.view',

        'tech.knowledge.create' => 'knowledge.create',
        'tech.knowledge.store' => 'knowledge.create',
        'tech.knowledge.edit' => 'knowledge.update',
        'tech.knowledge.update' => 'knowledge.update',
        'tech.knowledge.destroy' => 'knowledge.delete',
        'tech.knowledge.*.create' => 'knowledge.create',
        'tech.knowledge.*.store' => 'knowledge.create',
        'tech.knowledge.*.edit' => 'knowledge.update',
        'tech.knowledge.*.update' => 'knowledge.update',
        'tech.knowledge.*.destroy' => 'knowledge.delete',
        'tech.knowledge.*' => 'knowledge.view',
        'tech.ai.chats.*' => 'integration.ai_manage',

        'tech.documentations.create' => 'documentation.create',
        'tech.documentations.store' => 'documentation.create',
        'tech.documentations.edit' => 'documentation.update',
        'tech.documentations.update' => 'documentation.update',
        'tech.documentations.destroy' => 'documentation.delete',
        'tech.documentations.*.create' => 'documentation.create',
        'tech.documentations.*.store' => 'documentation.create',
        'tech.documentations.*.edit' => 'documentation.update',
        'tech.documentations.*.update' => 'documentation.update',
        'tech.documentations.*.destroy' => 'documentation.delete',
        'tech.documentations.*' => 'documentation.view',

        'tech.contracts.*' => 'commercial.contract_manage',
        'tech.packages.*' => 'commercial.package_manage',
        'tech.services.*' => 'commercial.service_manage',
        'tech.rates.*' => 'commercial.rate_manage',
        'tech.costs.*' => 'commercial.cost_manage',
        'tech.legal.*' => 'commercial.contract_manage',
        'tech.sla.*' => 'commercial.sla_manage',

        'tech.economy.orders.generate' => 'economy.generate_orders',
        'tech.economy.orders.destroy' => 'economy.delete_orders',
        'tech.economy.orders.lines.destroy' => 'economy.delete_orders',
        'tech.economy.orders.*' => 'economy.order_manage',

        'tech.inbox.delete' => 'email.inbox_manage',
        'tech.inbox.spam' => 'email.inbox_manage',
        'tech.inbox.poll' => 'email.inbox_manage',
        'tech.inbox.*' => 'email.inbox_view',

        'tech.risk.create' => 'risk.create',
        'tech.risk.store' => 'risk.create',
        'tech.risk.edit' => 'risk.update',
        'tech.risk.update' => 'risk.update',
        'tech.risk.destroy' => 'risk.delete',
        'tech.risk.items.store' => 'risk.update',
        'tech.risk.items.update' => 'risk.update',
        'tech.risk.items.destroy' => 'risk.delete',
        'tech.risk.updates.destroy' => 'risk.delete',
        'tech.risk.*' => 'risk.view',

        'tech.sales.quote.*' => 'sales.quote_manage',
        'tech.sales.leads.*' => 'sales.lead_manage',
        'tech.sales.create' => 'sales.opportunity_manage',
        'tech.sales.store' => 'sales.opportunity_manage',
        'tech.sales.update' => 'sales.opportunity_manage',
        'tech.sales.*' => 'sales.view',

        'tech.storage.picking.pick' => 'storage.pick',
        'tech.storage.picking*' => 'storage.pick',
        'tech.storage.items.adjust' => 'storage.stock_adjust',
        'tech.storage.items.create' => 'storage.item_manage',
        'tech.storage.items.store' => 'storage.item_manage',
        'tech.storage.items.edit' => 'storage.item_manage',
        'tech.storage.items.update' => 'storage.item_manage',
        'tech.storage.boxes.*' => 'storage.item_manage',
        'tech.storage.*' => 'storage.view',
    ];

    public function handle(Request $request, Closure $next)
    {
        $routeName = $request->route()?->getName();

        if (! $routeName || $this->isExempt($routeName)) {
            return $next($request);
        }

        $permission = $this->permissionForRoute($routeName);

        if (! $permission) {
            abort(403, 'No permission rule is defined for this route.');
        }

        if ($this->hasPrivilegedFallbackAccess($request)) {
            return $next($request);
        }

        if (! Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()) {
            return $next($request);
        }

        if ($request->user()?->can($permission)) {
            return $next($request);
        }

        abort(403, 'Missing permission: '.$permission);
    }

    private function isExempt(string $routeName): bool
    {
        foreach (self::EXEMPT_ROUTE_PATTERNS as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    private function permissionForRoute(string $routeName): ?string
    {
        foreach (self::ROUTE_PERMISSION_PATTERNS as $pattern => $permission) {
            if (Str::is($pattern, $routeName)) {
                return $permission;
            }
        }

        return null;
    }

    private function hasPrivilegedFallbackAccess(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('Superuser')) {
            return true;
        }

        $adminRole = $user->roles()->where('name', 'Admin')->first();

        if (! $adminRole) {
            return false;
        }

        return ! $adminRole->permissions()->exists();
    }
}
