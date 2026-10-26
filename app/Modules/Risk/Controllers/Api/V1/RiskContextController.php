<?php

namespace App\Modules\Risk\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Risk\Support\RiskPolicy;
use App\Modules\Risk\Support\RiskRead;
use App\Modules\Risk\Support\RiskRevision;
use App\Modules\Risk\Support\RiskSettings;
use App\Modules\Taxonomy\Models\Category;
use Illuminate\Http\Request;

/** Pure, bounded discovery; no settings/context initialization or session writes. */
class RiskContextController extends Controller
{
    public function context(Request $r)
    {
        return $this->response($r);
    }

    public function assessment(Request $r, $assessment)
    {
        return $this->response($r, RiskPolicy::assessment((int) $assessment));
    }

    public function item(Request $r, $item)
    {
        return $this->response($r, RiskPolicy::item((int) $item));
    }

    private function response(Request $r, $record = null)
    {
        $settings = app(RiskSettings::class);
        $manifest = json_decode(file_get_contents(base_path('docs/plans/2026-10-26-risk-api-contract.json')), true, 512, JSON_THROW_ON_ERROR);
        $caps = [];
        foreach ($manifest['operations'] as $op) {
            $actor = collect($op['permissions'])->every(fn ($p) => RiskRead::can($p));
            if ($record instanceof \App\Models\Risk\RiskItem && $op['action'] === 'updateItem') {
                $creator = $record->original_state?->created_by;
                $actor = $actor && ($r->user()->hasRole('Superuser') || ($creator && (int) $creator === (int) $r->user()->id));
            }
            if (in_array($op['action'], ['destroyAssessment', 'destroyItem'], true)) {
                $actor = $actor && $r->user()->hasRole('Superuser');
            }
            $caps[] = ['method' => $op['method'], 'path' => $op['path'], 'ability' => $op['scope'], 'actor_authorized' => $actor, 'token_authorized' => $r->user()->tokenCan($op['scope']), 'record_checks_required' => true];
        }

        return response()->json(['data' => [
            'resource_type' => $record ? ($record instanceof \App\Models\Risk\RiskAssessment ? 'assessment' : 'item') : 'global',
            'resource_id' => $record?->id, 'revision' => $record ? RiskRevision::of($record) : null,
            'defaults' => ['assessment' => $settings->assessmentDefaults(), 'item' => $settings->itemDefaults()],
            'options' => ['scope' => RiskSettings::ASSESSMENT_SCOPE_OPTIONS, 'assessment_status' => RiskSettings::ASSESSMENT_STATUS_OPTIONS, 'item_status' => RiskSettings::ITEM_STATUS_OPTIONS],
            'constraints' => ['minimum_score_component' => 1, 'maximum_score_component' => 5, 'maximum_per_page' => 100, 'maximum_title_length' => 255, 'new_action_revision_required' => true, 'new_action_idempotency_required' => true],
            'filters' => ['q', 'scope', 'client_id', 'work_context_id', 'context_type', 'status', 'sort', 'direction', 'page', 'per_page'],
            'sorts' => ['created_at', 'updated_at', 'title', 'status'],
            'locked_fields' => $record instanceof \App\Models\Risk\RiskItem && $record->updates()->exists() ? ['likelihood', 'impact', 'status'] : [],
            'capabilities' => $caps,
            'lookups' => ['clients' => route('api.v1.risk.context.clients'), 'categories' => route('api.v1.risk.context.categories')],
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function clients(Request $r)
    {
        $r->validate(['q' => 'sometimes|string|max:255']);
        $q = Client::query()->where('active', true);
        if ($r->filled('q')) {
            $q->where('name', 'like', '%'.$r->input('q').'%');
        }

        return $q->select(['id', 'name'])->orderBy('name')->orderBy('id')->paginate(RiskRead::size($r))->through(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name]);
    }

    public function categories(Request $r)
    {
        $r->validate(['q' => 'sometimes|string|max:255']);
        $q = Category::query();
        if (Category::whereIn('type', ['risk', 'risk_item'])->exists()) {
            $q->whereIn('type', ['risk', 'risk_item']);
        }
        if ($r->filled('q')) {
            $q->where('name', 'like', '%'.$r->input('q').'%');
        }

        return $q->select(['id', 'name', 'type'])->orderBy('name')->orderBy('id')->paginate(RiskRead::size($r))->through(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'type' => $row->type]);
    }
}
