<?php

namespace App\Modules\Storage\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\ActivateSupplierOrderProfileVersion;
use App\Modules\Storage\Actions\CloneSupplierOrderProfileVersion;
use App\Modules\Storage\Actions\CreateProtectedSupplierOrderProfileFixtureFromImport;
use App\Modules\Storage\Actions\CreateSupplierOrderProfileVersion;
use App\Modules\Storage\Actions\ExportSupplierOrderProfile;
use App\Modules\Storage\Actions\ImportSupplierOrderProfile;
use App\Modules\Storage\Actions\PauseSupplierOrderProfile;
use App\Modules\Storage\Actions\RetireSupplierOrderProfile;
use App\Modules\Storage\Actions\RollbackSupplierOrderProfileVersion;
use App\Modules\Storage\Actions\UpdateSupplierOrderProfileMetadata;
use App\Modules\Storage\Actions\ValidateSupplierOrderProfileVersion;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Queries\PurchaseOrderImportProfileIndexQuery;
use App\Modules\Storage\Requests\Admin\ImportPurchaseOrderProfileRequest;
use App\Modules\Storage\Requests\Admin\PurchaseOrderImportProfileReasonRequest;
use App\Modules\Storage\Requests\Admin\StoreProtectedSupplierOrderProfileFixtureRequest;
use App\Modules\Storage\Requests\Admin\StorePurchaseOrderImportProfileRequest;
use App\Modules\Storage\Requests\Admin\StorePurchaseOrderImportProfileVersionRequest;
use App\Modules\Storage\Requests\Admin\UpdatePurchaseOrderImportProfileMetadataRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchaseOrderImportProfileController extends Controller
{
    public function index(Request $request, PurchaseOrderImportProfileIndexQuery $profiles): View
    {
        return view('storage::Admin.PurchaseOrderImportProfiles.index', [
            'profiles' => $profiles->paginate($request),
            'vendors' => $this->vendors(),
        ]);
    }

    public function create(): View
    {
        $definition = $this->defaultDefinition();

        return view('storage::Admin.PurchaseOrderImportProfiles.form', [
            'vendors' => $this->vendors(),
            'definition' => $definition,
            'matchingScope' => $definition['match'],
        ]);
    }

    public function importForm(): View
    {
        return view('storage::Admin.PurchaseOrderImportProfiles.import-form');
    }

    public function store(
        StorePurchaseOrderImportProfileRequest $request,
        CreateSupplierOrderProfileVersion $createVersion
    ): RedirectResponse {
        $data = $request->validated();
        $result = ['profile' => app(\App\Modules\Storage\Actions\CreateSupplierOrderProfile::class)->handle($data, $request->user())];

        return redirect()
            ->route('tech.admin.settings.storage.supplier-order-profiles.show', $result['profile'])
            ->with('success', 'Draft supplier profile and version created.');
    }

    public function show(PurchaseOrderImportProfile $purchaseOrderImportProfile): View
    {
        $purchaseOrderImportProfile->load([
            'vendor',
            'activeVersion',
            'versions.creator',
            'versions.activator',
            'fixtures.profileVersion',
            'metadataAudits.actor',
        ]);
        $fixtureCandidates = PurchaseOrderImport::query()
            ->where('profile_id', $purchaseOrderImportProfile->id)
            ->whereNotNull('normalized_document')
            ->whereIn('status', [
                PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
                PurchaseOrderImport::STATUS_IMPORTED,
            ])
            ->where('validation_results->valid', true)
            ->latest('id')
            ->limit(100)
            ->get(['id', 'external_order_number', 'status', 'safe_source_snapshot']);

        return view('storage::Admin.PurchaseOrderImportProfiles.show', [
            'profile' => $purchaseOrderImportProfile,
            'fixtureCandidates' => $fixtureCandidates,
        ]);
    }

    public function edit(PurchaseOrderImportProfile $purchaseOrderImportProfile): View
    {
        $purchaseOrderImportProfile->load('activeVersion');

        return view('storage::Admin.PurchaseOrderImportProfiles.edit', [
            'profile' => $purchaseOrderImportProfile,
        ]);
    }

    public function update(
        UpdatePurchaseOrderImportProfileMetadataRequest $request,
        PurchaseOrderImportProfile $purchaseOrderImportProfile,
        UpdateSupplierOrderProfileMetadata $updateMetadata,
    ): RedirectResponse {
        $updateMetadata->handle($purchaseOrderImportProfile, $request->validated(), $request->user());

        return redirect()
            ->route('tech.admin.settings.storage.supplier-order-profiles.show', $purchaseOrderImportProfile)
            ->with('success', 'Supplier-profile metadata updated and added to the immutable audit trail.');
    }

    public function storeFixture(
        StoreProtectedSupplierOrderProfileFixtureRequest $request,
        PurchaseOrderImportProfile $purchaseOrderImportProfile,
        CreateProtectedSupplierOrderProfileFixtureFromImport $createFixture
    ): RedirectResponse {
        $version = $purchaseOrderImportProfile->versions()
            ->findOrFail($request->integer('profile_version_id'));
        $import = PurchaseOrderImport::query()
            ->findOrFail($request->integer('purchase_order_import_id'));
        $result = $createFixture->handle(
            $purchaseOrderImportProfile,
            $version,
            $import,
            $request->validated('fixture_name'),
            $request->user(),
        );
        $passed = $result['replay']->protectedPassed();

        return back()->with(
            $passed ? 'success' : 'warning',
            $passed
                ? 'Protected fixture saved and the selected profile version passed fresh replay.'
                : 'Protected fixture saved, but the selected profile version failed protected replay.',
        );
    }

    public function createVersion(
        Request $request,
        PurchaseOrderImportProfile $purchaseOrderImportProfile
    ): View {
        $sourceVersion = $request->integer('from_version')
            ? $purchaseOrderImportProfile->versions()->findOrFail($request->integer('from_version'))
            : $purchaseOrderImportProfile->activeVersion
                ?? $purchaseOrderImportProfile->versions()->first();

        return view('storage::Admin.PurchaseOrderImportProfiles.version-form', [
            'profile' => $purchaseOrderImportProfile,
            'sourceVersion' => $sourceVersion,
            'definition' => $sourceVersion?->definition ?? $this->defaultDefinition(),
        ]);
    }

    public function storeVersion(
        StorePurchaseOrderImportProfileVersionRequest $request,
        PurchaseOrderImportProfile $purchaseOrderImportProfile,
        CreateSupplierOrderProfileVersion $createVersion,
        CloneSupplierOrderProfileVersion $cloneVersion
    ): RedirectResponse {
        $parent = $request->validated('parent_version_id')
            ? $purchaseOrderImportProfile->versions()->findOrFail($request->integer('parent_version_id'))
            : null;
        $version = $parent
            ? $cloneVersion->handle($parent, $request->validated('definition'), $request->user())
            : $createVersion->handle(
                $purchaseOrderImportProfile,
                $request->validated('definition'),
                'manual',
                $request->user(),
            );

        return redirect()
            ->route('tech.admin.settings.storage.supplier-order-profiles.show', $purchaseOrderImportProfile)
            ->with('success', 'Draft profile version '.$version->version_number.' saved.');
    }

    public function testVersion(
        PurchaseOrderImportProfile $purchaseOrderImportProfile,
        PurchaseOrderImportProfileVersion $profileVersion,
        ValidateSupplierOrderProfileVersion $validateVersion
    ): RedirectResponse {
        $this->ensureVersionBelongsToProfile($purchaseOrderImportProfile, $profileVersion);
        $result = $validateVersion->handle($profileVersion);

        return back()
            ->with(
                $result->valid() ? 'success' : 'warning',
                $result->valid()
                    ? 'Definition and protected fixture replay passed.'
                    : 'Profile validation failed. Review the recorded errors and fixture results.'
            )
            ->with('profile_test_result', $result->toArray());
    }

    public function activateVersion(
        PurchaseOrderImportProfileReasonRequest $request,
        PurchaseOrderImportProfile $purchaseOrderImportProfile,
        PurchaseOrderImportProfileVersion $profileVersion,
        ActivateSupplierOrderProfileVersion $activateVersion
    ): RedirectResponse {
        $this->ensureVersionBelongsToProfile($purchaseOrderImportProfile, $profileVersion);
        $activateVersion->handle($profileVersion, $request->user(), $request->validated('reason'));

        return back()->with('success', 'Profile version activated after validation gates.');
    }

    public function rollbackVersion(
        PurchaseOrderImportProfileReasonRequest $request,
        PurchaseOrderImportProfile $purchaseOrderImportProfile,
        PurchaseOrderImportProfileVersion $profileVersion,
        RollbackSupplierOrderProfileVersion $rollbackVersion
    ): RedirectResponse {
        $this->ensureVersionBelongsToProfile($purchaseOrderImportProfile, $profileVersion);
        $rollbackVersion->handle(
            $purchaseOrderImportProfile,
            $profileVersion,
            $request->user(),
            $request->validated('reason')
        );

        return back()->with('success', 'Supplier profile rolled back to version '.$profileVersion->version_number.'.');
    }

    public function pause(
        PurchaseOrderImportProfileReasonRequest $request,
        PurchaseOrderImportProfile $purchaseOrderImportProfile,
        PauseSupplierOrderProfile $pauseProfile
    ): RedirectResponse {
        $pauseProfile->handle(
            $purchaseOrderImportProfile,
            $request->validated('reason'),
            $request->user()
        );

        return back()->with('success', 'Supplier profile paused.');
    }

    public function retire(
        PurchaseOrderImportProfileReasonRequest $request,
        PurchaseOrderImportProfile $purchaseOrderImportProfile,
        RetireSupplierOrderProfile $retireProfile
    ): RedirectResponse {
        $retireProfile->handle(
            $purchaseOrderImportProfile,
            $request->validated('reason'),
            $request->user()
        );

        return back()->with('success', 'Supplier profile retired.');
    }

    public function export(
        PurchaseOrderImportProfile $purchaseOrderImportProfile,
        ExportSupplierOrderProfile $exportProfile
    ): JsonResponse {
        $export = $exportProfile->handle($purchaseOrderImportProfile);

        return response()->json(
            $export,
            200,
            [
                'Content-Disposition' => 'attachment; filename="supplier-profile-'.$purchaseOrderImportProfile->slug.'.json"',
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    public function import(
        ImportPurchaseOrderProfileRequest $request,
        ImportSupplierOrderProfile $importProfile
    ): RedirectResponse {
        $result = $importProfile->handle(
            $request->validated('export'),
            $request->user(),
            $request->validated('slug')
        );

        return redirect()
            ->route('tech.admin.settings.storage.supplier-order-profiles.show', $result['profile'])
            ->with('success', 'Supplier profile imported as an inactive draft.');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Vendor>
     */
    private function vendors()
    {
        return Vendor::query()
            ->where('is_supplier', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    private function ensureVersionBelongsToProfile(
        PurchaseOrderImportProfile $profile,
        PurchaseOrderImportProfileVersion $version
    ): void {
        abort_unless((int) $version->profile_id === (int) $profile->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultDefinition(): array
    {
        return app(\App\Modules\Storage\Support\SupplierOrderProfileDefaults::class)->definition();
    }
}
