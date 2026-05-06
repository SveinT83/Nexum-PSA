<?php

namespace App\Providers;

use App\Modules\Asset\Livewire\Tech\Alerts\AlertSyncProcessor as AssetAlertSyncProcessor;
use App\Modules\Asset\Livewire\Tech\AssetAlerts;
use App\Modules\Asset\Livewire\Tech\AssetForm;
use App\Modules\Asset\Livewire\Tech\ClientAlertsSummary;
use App\Modules\Knowledge\Livewire\ArticleForm as KnowledgeArticleForm;
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
        Livewire::component('knowledge.article-form', KnowledgeArticleForm::class);
        Livewire::component('tech.admin.user_management.roles.role-permissions', UserManagementRolePermissions::class);

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
