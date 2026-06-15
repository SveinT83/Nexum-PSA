<?php

namespace App\Modules\LeadIntelligence\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\LeadIntelligence\Models\LeadScanLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadScanLedgerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LeadScanLedger::query()->latest();

        if ($request->boolean('due_only')) {
            $query->due();
        }

        foreach (['domain', 'org_no', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, trim((string) $request->input($filter)));
            }
        }

        $ledger = $query->paginate($request->integer('per_page') ?: 25);

        return response()->json([
            'data' => $ledger->getCollection()->map(fn (LeadScanLedger $entry): array => $this->serialize($entry))->all(),
            'meta' => [
                'current_page' => $ledger->currentPage(),
                'per_page' => $ledger->perPage(),
                'total' => $ledger->total(),
            ],
        ]);
    }

    private function serialize(LeadScanLedger $entry): array
    {
        return [
            'id' => $entry->id,
            'domain' => $entry->domain,
            'org_no' => $entry->org_no,
            'url' => $entry->url,
            'last_scanned_at' => $entry->last_scanned_at?->toISOString(),
            'next_scan_after' => $entry->next_scan_after?->toISOString(),
            'due_for_scan' => $entry->next_scan_after === null || $entry->next_scan_after->lte(now()),
            'last_result_hash' => $entry->last_result_hash,
            'pages_scanned' => $entry->pages_scanned,
            'tokens_used' => $entry->tokens_used,
            'status' => $entry->status,
            'metadata' => $entry->metadata ?: [],
            'created_at' => $entry->created_at?->toISOString(),
            'updated_at' => $entry->updated_at?->toISOString(),
        ];
    }
}

