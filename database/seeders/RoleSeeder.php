<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Seed standard Nexum PSA roles and their default permission sets.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->roles() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function roles(): array
    {
        return [
            'Superuser' => $this->allPermissions(),
            'Admin' => $this->adminPermissions(),
            'Tech' => $this->technicianPermissions(),
            'Sales' => $this->salesPermissions(),
            'Economy' => $this->economyPermissions(),
            'Storage' => $this->storagePermissions(),
            'Viewer' => $this->viewerPermissions(),
        ];
    }

    private function allPermissions(): array
    {
        return Permission::query()->pluck('name')->all();
    }

    private function adminPermissions(): array
    {
        return array_values(array_unique(array_merge(
            $this->viewerPermissions(),
            [
                'client.create',
                'client.update',
                'client.delete',
                'client.manage_settings',
                'client.custom_fields.view_integrations',
                'client.custom_fields.edit_integrations',
                'customfield.manage_settings',
                'contact.create',
                'contact.update',
                'contact.delete',
                'contact.merge',
                'contact.manage_settings',
                'asset.manage_settings',
                'ticket.manage_rules',
                'ticket.manage_workflows',
                'ticket.workflow_publish',
                'ticket.workflow_migrate',
                'ticket.workflow_escalate',
                'ticket.workflow_override',
                'ticket.review_request',
                'ticket.review_senior',
                'ticket.evidence_classify',
                'ticket.approval_record',
                'ticket.plan_cost',
                'ticket.manage_settings',
                'task.manage_templates',
                'task.manage_settings',
                'calendar.manage_settings',
                'knowledge.manage_structure',
                'knowledge.manage_settings',
                'documentation.manage_templates',
                'commercial.service_manage',
                'commercial.contract_manage',
                'commercial.package_manage',
                'commercial.cost_manage',
                'commercial.sla_manage',
                'commercial.rate_manage',
                'commercial.timebank.view',
                'commercial.timebank.quick-consume',
                'commercial.timebank.overconsume',
                'economy.manage_settings',
                'storage.manage_settings',
                'email.account_manage',
                'email.rule_manage',
                'email.template_manage',
                'notification.manage_channels',
                'telephony.view',
                'sales.manage_settings',
                'marketing.view',
                'marketing.list.manage',
                'marketing.campaign.create',
                'marketing.campaign.edit',
                'marketing.campaign.approve',
                'marketing.campaign.send',
                'marketing.analytics.view',
                'marketing.settings.manage',
                'signal.view',
                'signal.rule.manage',
                'signal.webhook.manage',
                'signal.action.execute',
                'risk.manage_settings',
                'integration.view',
                'integration.api_manage',
                'integration.ai_manage',
                'integration.bookstack_manage',
                'integration.rmm_manage',
                'integration.cloudfactory_view',
                'integration.cloudfactory_manage',
                'integration.cloudfactory_write',
                'data_exchange.view',
                'data_exchange.manage',
                'data_exchange.run',
                'data_exchange.download',
                'data_exchange.import',
                'data_exchange.approve_import',
                'data_exchange.schedule',
                'data_exchange.delivery',
                'customer_portal.view',
                'customer_portal.manage',
                'customer_portal.invite',
                'intake.view',
                'intake.manage',
                'intake.submission_review',
                'booking.view',
                'booking.manage',
                'booking.request_review',
                'relationships.view',
                'relationships.manage',
                'relationships.escalate',
                'relationships.sync',
                'nextcloud.view',
                'nextcloud.connection_manage',
                'nextcloud.sync',
                'nextcloud.folder_manage',
                'nextcloud.user_mapping_manage',
                'nextcloud.talk_manage',
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
                'warroom.manage_settings',
                'report.export',
            ]
        )));
    }

    private function technicianPermissions(): array
    {
        return [
            'warroom.view',
            'client.view',
            'contact.view',
            'contact.create',
            'contact.update',
            'client.create',
            'client.update',
            'asset.view',
            'asset.create',
            'asset.update',
            'asset.sync',
            'ticket.view',
            'ticket.create',
            'ticket.update',
            'ticket.assign',
            'ticket.reply_customer',
            'ticket.note_internal',
            'ticket.register_time',
            'ticket.close',
            'ticket.reopen',
            'ticket.workflow_escalate',
            'ticket.review_request',
            'ticket.evidence_classify',
            'ticket.plan_cost',
            'relationships.view',
            'relationships.escalate',
            'commercial.timebank.view',
            'commercial.timebank.quick-consume',
            'task.view',
            'task.create',
            'task.update',
            'task.delete',
            'task.assign',
            'task.complete',
            'calendar.view',
            'calendar.create',
            'calendar.update',
            'calendar.delete',
            'calendar.share',
            'knowledge.view',
            'knowledge.create',
            'knowledge.update',
            'documentation.view',
            'documentation.create',
            'documentation.update',
            'storage.view',
            'storage.reserve',
            'storage.pick',
            'email.inbox_view',
            'email.inbox_manage',
            'notification.view_settings',
            'telephony.view',
            'risk.view',
            'risk.create',
            'risk.update',
            'report.view',
        ];
    }

    private function salesPermissions(): array
    {
        return [
            'warroom.view',
            'client.view',
            'contact.view',
            'contact.create',
            'contact.update',
            'client.create',
            'client.update',
            'calendar.view',
            'calendar.create',
            'calendar.update',
            'commercial.view',
            'commercial.timebank.view',
            'sales.view',
            'sales.lead_manage',
            'sales.opportunity_manage',
            'sales.quote_manage',
            'sales.email_send',
            'marketing.view',
            'marketing.analytics.view',
            'email.inbox_view',
            'notification.view_settings',
            'report.view',
        ];
    }

    private function economyPermissions(): array
    {
        return [
            'warroom.view',
            'client.view',
            'contact.view',
            'ticket.view',
            'ticket.register_time',
            'commercial.view',
            'commercial.service_manage',
            'commercial.contract_manage',
            'commercial.package_manage',
            'commercial.cost_manage',
            'commercial.sla_manage',
            'commercial.rate_manage',
            'commercial.timebank.view',
            'commercial.timebank.quick-consume',
            'commercial.timebank.overconsume',
            'economy.view',
            'economy.order_manage',
            'economy.generate_orders',
            'economy.delete_orders',
            'data_exchange.view',
            'data_exchange.run',
            'data_exchange.download',
            'storage.view',
            'notification.view_settings',
            'report.view',
            'report.export',
        ];
    }

    private function storagePermissions(): array
    {
        return [
            'warroom.view',
            'client.view',
            'contact.view',
            'asset.view',
            'ticket.view',
            'storage.view',
            'storage.item_manage',
            'storage.stock_adjust',
            'storage.reserve',
            'storage.pick',
            'storage.purchase_manage',
            'storage.export',
            'notification.view_settings',
            'report.view',
        ];
    }

    private function viewerPermissions(): array
    {
        return [
            'warroom.view',
            'client.view',
            'contact.view',
            'asset.view',
            'ticket.view',
            'task.view',
            'calendar.view',
            'knowledge.view',
            'documentation.view',
            'commercial.view',
            'economy.view',
            'storage.view',
            'email.inbox_view',
            'notification.view_settings',
            'sales.view',
            'risk.view',
            'integration.view',
            'relationships.view',
            'nextcloud.view',
            'taxonomy.view',
            'user.view',
            'system.view',
            'report.view',
        ];
    }
}
