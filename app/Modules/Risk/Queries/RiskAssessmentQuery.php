<?php

namespace App\Modules\Risk\Queries;

use App\Models\Risk\RiskAssessment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read model for listing risk assessments in the active tdPSA context.
 *
 * Query objects keep controller methods small and make context-sensitive list
 * behavior easier to reuse from future dashboard widgets or API endpoints.
 */
class RiskAssessmentQuery
{
    /**
     * Return paginated assessments filtered by the current session context.
     *
     * Context rules:
     * - only_internal: show assessments where client_id is null.
     * - active_client_id: show assessments for that client.
     * - neither value set: show every assessment.
     */
    public function paginateForCurrentContext(int $perPage = 20): LengthAwarePaginator
    {
        $query = RiskAssessment::query()->with(['client', 'items']);

        if (session('only_internal')) {
            $query->whereNull('client_id');
        } elseif (session('active_client_id')) {
            $query->where('client_id', session('active_client_id'));
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
