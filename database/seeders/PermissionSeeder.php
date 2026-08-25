<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the complete Nexum PSA permission catalog.
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
            'warroom.manage_settings',

            'client.view',
            'client.create',
            'client.update',
            'client.delete',
            'client.manage_settings',
            'client.custom_fields.view_integrations',
            'client.custom_fields.edit_integrations',

            'customfield.manage_settings',

            'contact.view',
            'contact.create',
            'contact.update',
            'contact.delete',
            'contact.merge',
            'contact.manage_settings',

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

            'task.view',
            'task.create',
            'task.update',
            'task.delete',
            'task.assign',
            'task.complete',
            'task.manage_templates',
            'task.manage_settings',

            'calendar.view',
            'calendar.create',
            'calendar.update',
            'calendar.delete',
            'calendar.share',
            'calendar.view_private',
            'calendar.view_all',
            'calendar.manage_all',
            'calendar.manage_shared',
            'calendar.manage_access',
            'calendar.view_free_busy',
            'calendar.book_resources',
            'calendar.manage_shift',
            'calendar.manage_absence',
            'calendar.manage_settings',

            'knowledge.view',
            'knowledge.create',
            'knowledge.update',
            'knowledge.delete',
            'knowledge.publish',
            'knowledge.sync_bookstack',
            'knowledge.manage_structure',
            'knowledge.manage_settings',

            'documentation.view',
            'documentation.create',
            'documentation.update',
            'documentation.delete',
            'documentation.manage_templates',
            'documentation.carrier_manage',

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
            'economy.manage_settings',

            'storage.view',
            'storage.item_manage',
            'storage.stock_adjust',
            'storage.reserve',
            'storage.pick',
            'storage.purchase_view',
            'storage.purchase_manage',
            'storage.purchase_receive',
            'storage.purchase_receive_overage',
            'storage.purchase_reverse',
            'storage.purchase_import_view',
            'storage.purchase_import_resolve',
            'storage.purchase_import_execute',
            'storage.purchase_import_profile_manage',
            'storage.purchase_import_policy_manage',
            'storage.export',
            'storage.manage_settings',

            'email.inbox_view',
            'email.inbox_manage',
            'email.account_manage',
            'email.mailbox_sync_manage',
            'email.canonical_cutover_manage',
            'email.break_glass_activate',
            'email.break_glass_audit',
            'email.raw_source_view',
            'email.rule_manage',
            'email.template_manage',

            'notification.view_settings',
            'notification.manage_channels',

            'telephony.view',

            'sales.view',
            'sales.manage',
            'sales.lead_manage',
            'sales.opportunity_manage',
            'sales.quote_manage',
            'sales.quote.approve',
            'sales.quote.send',
            'sales.quote.approve_discount',
            'sales.email_send',
            'sales.settings',
            'sales.admin',
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

            'risk.view',
            'risk.create',
            'risk.update',
            'risk.delete',
            'risk.manage_settings',

            'integration.view',
            'integration.api_manage',
            'integration.ai_manage',
            'integration.ai_policy_manage',
            'integration.ai_governance_manage',
            'integration.ai_workload_manage',
            'integration.ai_audit_view',
            'integration.email_provider_manage',
            'integration.email_private_endpoint_manage',
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
            'system.telescope_view',
            'system.queue_manage',
            'system.security_manage',
            'system.backup_manage',
            'system.settings_manage',

            'report.view',
            'report.export',
        ];
    }
}
