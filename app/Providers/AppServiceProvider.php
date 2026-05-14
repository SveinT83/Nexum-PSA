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
use App\Modules\Integration\Livewire\Tech\Admin\System\Integrations\TacticalRmmSync as IntegrationTacticalRmmSync;
use App\Modules\Knowledge\Livewire\ArticleForm as KnowledgeArticleForm;
use App\Modules\Taxonomy\Livewire\TagManager as TaxonomyTagManager;
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
        Livewire::component('tech.admin.system.templates-management.doc.template-form', DocumentationTemplateForm::class);
        Livewire::component('tech.admin.user_management.roles.role-permissions', UserManagementRolePermissions::class);
        Livewire::component('tech.admin.system.integrations.n-able-rmm-sync', IntegrationNAbleRmmSync::class);
        Livewire::component('tech.admin.system.integrations.tactical-rmm-sync', IntegrationTacticalRmmSync::class);

        foreach (glob(app_path('Modules/*/Views')) as $viewPath) {
            // Register both plain lookup paths and module namespaces.
            // Example: app/Modules/Risk/Views becomes view namespace "risk",
            // which allows module views to be referenced as "risk::Tech.index".
            View::addLocation($viewPath);

            $module = strtolower(basename(dirname($viewPath)));
            View::addNamespace($module, $viewPath);
        }
    }
}
