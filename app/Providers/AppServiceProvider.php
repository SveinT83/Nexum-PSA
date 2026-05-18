<?php

namespace App\Providers;

use App\Modules\Asset\Livewire\Tech\Alerts\AlertSyncProcessor as AssetAlertSyncProcessor;
use App\Modules\Asset\Livewire\Tech\AssetAlerts;
use App\Modules\Asset\Livewire\Tech\AssetForm;
use App\Modules\Asset\Livewire\Tech\ClientAlertsSummary;
use App\Modules\Commercial\Livewire\Tech\Contracts\ContractItemsEditor as CommercialContractItemsEditor;
use App\Modules\Commercial\Livewire\Tech\PackageLegal as CommercialPackageLegal;
use App\Modules\Commercial\Livewire\Tech\PackagePricing as CommercialPackagePricing;
use App\Modules\Commercial\Livewire\Tech\ServiceLegal as CommercialServiceLegal;
use App\Modules\Commercial\Livewire\Tech\ServicePicker as CommercialServicePicker;
use App\Modules\Commercial\Livewire\Tech\ServicePricing as CommercialServicePricing;
use App\Modules\Documentation\Livewire\Admin\TemplateForm as DocumentationTemplateForm;
use App\Modules\Integration\Livewire\Tech\Admin\System\Integrations\NAbleRmmSync as IntegrationNAbleRmmSync;
use App\Modules\Integration\Livewire\Tech\Admin\System\Integrations\AiSettings as IntegrationAiSettings;
use App\Modules\Integration\Livewire\Tech\Admin\System\Integrations\TacticalRmmSync as IntegrationTacticalRmmSync;
use App\Modules\Integration\Livewire\Tech\Ai\ContextChat as IntegrationContextChat;
use App\Modules\Knowledge\Livewire\ArticleForm as KnowledgeArticleForm;
use App\Modules\Notification\Livewire\NotificationBell;
use App\Modules\Taxonomy\Livewire\TagManager as TaxonomyTagManager;
use App\Modules\Ticket\Livewire\Admin\WorkflowEditor as TicketWorkflowEditor;
use App\Modules\UserManagement\Livewire\Roles\RolePermissions as UserManagementRolePermissions;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register module view namespaces used by module-owned controllers and Livewire views.
        foreach ([
            'asset' => 'Asset',
            'calendar' => 'Calendar',
            'clients' => 'Clients',
            'commercial' => 'Commercial',
            'documentation' => 'Documentation',
            'email' => 'Email',
            'integration' => 'Integration',
            'knowledge' => 'Knowledge',
            'notification' => 'Notification',
            'risk' => 'Risk',
            'storage' => 'Storage',
            'taxonomy' => 'Taxonomy',
            'ticket' => 'Ticket',
            'usermanagement' => 'UserManagement',
        ] as $namespace => $module) {
            $path = base_path("app/Modules/{$module}/Views");

            if (is_dir($path)) {
                View::addNamespace($namespace, $path);
            }
        }

        // Register module-local Livewire components with stable public aliases.
        // Blade templates may keep domain-specific aliases such as
        // "tech.assets.asset-form" even when the PHP class moves into a module.
        Livewire::component('tech.assets.asset-form', AssetForm::class);
        Livewire::component('tech.work.assets.asset-alerts', AssetAlerts::class);
        Livewire::component('tech.work.assets.client-alerts-summary', ClientAlertsSummary::class);
        Livewire::component('tech.work.assets.alerts.alert-sync-processor', AssetAlertSyncProcessor::class);
        Livewire::component('tech.cs.contracts.contract-items-editor', CommercialContractItemsEditor::class);
        Livewire::component('tech.cs.package-legal', CommercialPackageLegal::class);
        Livewire::component('tech.cs.package-pricing', CommercialPackagePricing::class);
        Livewire::component('tech.cs.service-legal', CommercialServiceLegal::class);
        Livewire::component('tech.cs.service-picker', CommercialServicePicker::class);
        Livewire::component('tech.cs.service-pricing', CommercialServicePricing::class);
        Livewire::component('knowledge.article-form', KnowledgeArticleForm::class);
        Livewire::component('system.tag-manager', TaxonomyTagManager::class);
        Livewire::component('tech.admin.tickets.workflow-editor', TicketWorkflowEditor::class);
        Livewire::component('tech.admin.system.templates-management.doc.template-form', DocumentationTemplateForm::class);
        Livewire::component('tech.admin.user_management.roles.role-permissions', UserManagementRolePermissions::class);
        Livewire::component('tech.admin.system.integrations.n-able-rmm-sync', IntegrationNAbleRmmSync::class);
        Livewire::component('tech.admin.system.integrations.tactical-rmm-sync', IntegrationTacticalRmmSync::class);
        Livewire::component('tech.admin.system.integrations.ai-settings', IntegrationAiSettings::class);
        Livewire::component('tech.ai.context-chat', IntegrationContextChat::class);
        Livewire::component('notification-bell', NotificationBell::class);
    }
}
