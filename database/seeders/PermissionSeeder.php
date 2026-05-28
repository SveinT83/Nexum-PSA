<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the complete tdPSA permission catalog.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function permissions(): array
    {
        return [
            'warroom.view',

            'client.view',
            'client.create',
            'client.update',
            'client.delete',
            'client.manage_settings',

            'asset.view',
            'asset.create',
            'asset.update',
            'asset.delete',
            'asset.sync',
            'asset.manage_settings',

            'ticket.view',
            'ticket.create',
            'ticket.update',
            'ticket.delete',
            'ticket.assign',
            'ticket.reply_customer',
            'ticket.note_internal',
            'ticket.register_time',
            'ticket.close',
            'ticket.reopen',
            'ticket.manage_rules',
            'ticket.manage_workflows',
            'ticket.manage_settings',

            'task.view',
            'task.create',
            'task.update',
            'task.delete',
            'task.assign',
            'task.complete',
            'task.manage_templates',

            'calendar.view',
            'calendar.create',
            'calendar.update',
            'calendar.delete',
            'calendar.share',
            'calendar.view_private',
            'calendar.manage_settings',

            'knowledge.view',
            'knowledge.create',
            'knowledge.update',
            'knowledge.delete',
            'knowledge.publish',
            'knowledge.sync_bookstack',
            'knowledge.manage_structure',

            'documentation.view',
            'documentation.create',
            'documentation.update',
            'documentation.delete',
            'documentation.manage_templates',

            'commercial.view',
            'commercial.service_manage',
            'commercial.contract_manage',
            'commercial.package_manage',
            'commercial.cost_manage',
            'commercial.sla_manage',
            'commercial.rate_manage',

            'economy.view',
            'economy.order_manage',
            'economy.generate_orders',
            'economy.delete_orders',
            'economy.manage_settings',

            'storage.view',
            'storage.item_manage',
            'storage.stock_adjust',
            'storage.reserve',
            'storage.pick',
            'storage.purchase_manage',
            'storage.export',
            'storage.manage_settings',

            'email.inbox_view',
            'email.inbox_manage',
            'email.account_manage',
            'email.rule_manage',
            'email.template_manage',

            'notification.view_settings',
            'notification.manage_channels',

            'sales.view',
            'sales.lead_manage',
            'sales.opportunity_manage',
            'sales.quote_manage',
            'sales.email_send',
            'sales.manage_settings',

            'risk.view',
            'risk.create',
            'risk.update',
            'risk.delete',
            'risk.manage_settings',

            'integration.view',
            'integration.api_manage',
            'integration.ai_manage',
            'integration.bookstack_manage',
            'integration.rmm_manage',

            'nextcloud.view',
            'nextcloud.connection_manage',
            'nextcloud.sync',
            'nextcloud.folder_manage',
            'nextcloud.user_mapping_manage',
            'nextcloud.talk_manage',

            'taxonomy.view',
            'taxonomy.manage_categories',
            'taxonomy.manage_tags',

            'user.view',
            'user.create',
            'user.update',
            'user.delete',
            'user.invite',
            'user.manage_roles',
            'user.manage_permissions',
            'user.manage_2fa',

            'system.view',
            'system.queue_manage',
            'system.security_manage',
            'system.backup_manage',
            'system.settings_manage',

            'report.view',
            'report.export',
        ];
    }
}
