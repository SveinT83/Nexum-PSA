<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\WorkContext\Models\WorkContext;
use Illuminate\Database\Eloquent\Builder;

/** Apply the installation maximum before the workload's narrower record lists. */
class CoordinatorReadScope
{
    public function records(Builder $query, AiWorkloadProfile $workload): void
    {
        $table = $query->getModel()->getTable();
        $mode = AiDataEgressPolicy::installation()->context_scope;
        if ($mode === 'internal_only') {
            $query->whereNull($table.'.client_id')
                ->whereHas('workContext', fn (Builder $context) => $context->where('type', 'internal')->whereNull('client_id'));
        } elseif (in_array($mode, ['selected_clients', 'selected_work_contexts'], true)) {
            // A corrupt/missing context must never become a fallback to broader client access.
            $query->whereHas('workContext', function (Builder $context) use ($table): void {
                $context->where(function (Builder $valid) use ($table): void {
                    $valid->where(function (Builder $internal) use ($table): void {
                        $internal->where('type', 'internal')->whereNull('client_id')->whereNull($table.'.client_id');
                    })->orWhere(function (Builder $client) use ($table): void {
                        $client->where('type', 'client')->whereColumn('work_contexts.client_id', $table.'.client_id');
                    });
                });
            });
            if ($mode === 'selected_clients' && ($workload->allowed_client_ids ?? []) === []) {
                $query->whereRaw('1 = 0');
            }
            if ($mode === 'selected_work_contexts' && ($workload->allowed_work_context_ids ?? []) === []) {
                $query->whereRaw('1 = 0');
            }
        } else {
            $query->whereRaw('1 = 0');
        }
        if (($workload->allowed_client_ids ?? []) !== []) {
            $query->whereIn($table.'.client_id', $workload->allowed_client_ids);
        }
        if (($workload->allowed_work_context_ids ?? []) !== []) {
            $query->whereIn($table.'.work_context_id', $workload->allowed_work_context_ids);
        }
    }

    /** Commercial facts are client-owned; selected context IDs resolve only through real client contexts. */
    public function clients(Builder $query, AiWorkloadProfile $workload): void
    {
        $mode = AiDataEgressPolicy::installation()->context_scope;
        $clients = $workload->allowed_client_ids ?? [];
        $contexts = $workload->allowed_work_context_ids ?? [];
        if (! in_array($mode, ['selected_clients', 'selected_work_contexts'], true)
            || ($mode === 'selected_clients' && $clients === [])
            || ($mode === 'selected_work_contexts' && $contexts === [])) {
            $query->whereRaw('1 = 0');
        }
        $column = $query->getModel()->qualifyColumn('client_id');
        if ($clients !== []) {
            $query->whereIn($column, $clients);
        }
        if ($contexts !== []) {
            $query->whereIn($column, WorkContext::query()->select('client_id')->where('type', 'client')->whereIn('id', $contexts));
        }
    }
}
