<?php

namespace App\Modules\Storage\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\GetCurrentPurchaseOrderAutomationPolicy;
use App\Modules\Storage\Actions\UpdatePurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Queries\PurchaseOrderAutomationUiQuery;
use App\Modules\Storage\Requests\Admin\UpdatePurchaseOrderAutomationPolicyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PurchaseOrderAutomationController extends Controller
{
    public function edit(
        GetCurrentPurchaseOrderAutomationPolicy $currentPolicy,
        PurchaseOrderAutomationUiQuery $automationUi
    ): View {
        ['policy' => $policy, 'revision' => $revision] = $currentPolicy->handle();
        $policy->load([
            'defaultWarehouse',
            'aiAgent',
            'revisions.creator',
        ]);

        return view('storage::Admin.PurchaseOrderAutomation.edit', [
            'policy' => $policy,
            'revision' => $revision,
            'storageAgents' => $automationUi->storageAgents(),
            'warehouses' => Warehouse::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'aiAvailability' => $automationUi->forPolicy($policy),
        ]);
    }

    public function update(
        UpdatePurchaseOrderAutomationPolicyRequest $request,
        UpdatePurchaseOrderAutomationPolicy $updatePolicy
    ): RedirectResponse {
        $data = $request->validated();

        $policy = $updatePolicy->handle($data, $request->user());

        return redirect()
            ->route('tech.admin.settings.storage.purchase-order-automation.edit')
            ->with('success', 'Supplier-order automation settings saved.');
    }
}
