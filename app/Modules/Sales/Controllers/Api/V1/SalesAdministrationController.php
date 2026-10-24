<?php

namespace App\Modules\Sales\Controllers\Api\V1;

use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Sales\Actions\EnsureSalesDefaults;
use App\Modules\Sales\Models\SalesSetting;
use App\Modules\Sales\Queries\SalesApiWorkspace;
use Illuminate\Http\Request;

/** Administrative API mirrors the current settings permission without granting roles during reads. */
class SalesAdministrationController extends SalesApiController
{
    public function policy(SalesApiWorkspace $workspace)
    {
        return response()->json(['data' => $workspace->policy() + ['revision' => $workspace->policyRevision()]]);
    }

    public function reporting(SalesApiWorkspace $workspace)
    {
        return response()->json(['data' => $workspace->reporting()]);
    }

    public function updatePolicy(Request $request, SalesApiWorkspace $workspace)
    {
        $data = DomainApiInput::validate($request, ['enabled' => 'nullable|boolean', 'discount_percent_threshold' => 'required|numeric|min:0|max:100', 'minimum_margin_percent' => 'required|numeric|min:-100|max:100', 'quote_total_ex_vat_threshold' => 'required|numeric|min:0', 'manual_line_ex_vat_threshold' => 'required|numeric|min:0']);
        $receipt = $this->command($request, null, function () use ($data, $request, $workspace) {
            SalesSetting::query()->where('key', 'cpq_approval_policy')->lockForUpdate()->first();
            $revision = trim((string) $request->header('If-Match'), '"');
            abort_if($revision === '', 428, 'If-Match is required. Read the current approval policy first.');
            abort_unless(hash_equals($workspace->policyRevision(), $revision), 409, 'The approval policy changed. Read and review it again.');
            app(\App\Modules\Sales\Actions\SalesApprovalPolicy::class)->handle($data);

            return ['type' => 'settings', 'key' => 'cpq_approval_policy'];
        });

        return response()->json(['data' => $workspace->policy() + ['revision' => $workspace->policyRevision()], 'operation' => $receipt]);
    }

    public function initialize(Request $request)
    {
        DomainApiInput::validate($request, []);
        $receipt = $this->command($request, null, function () {
            app(EnsureSalesDefaults::class)->handle(false);

            return ['type' => 'setup', 'initialized' => true];
        });

        return response()->json(['data' => ['initialized' => true], 'operation' => $receipt]);
    }
}
