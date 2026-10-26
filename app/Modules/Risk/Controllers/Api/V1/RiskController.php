<?php

namespace App\Modules\Risk\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use App\Modules\Risk\Actions\ApproveRiskAssessment;
use App\Modules\Risk\Actions\DeleteRiskItemUpdate;
use App\Modules\Risk\Actions\DeleteRiskRecord;
use App\Modules\Risk\Actions\ExecuteRiskCommand;
use App\Modules\Risk\Actions\StoreRiskAssessment;
use App\Modules\Risk\Actions\StoreRiskItem;
use App\Modules\Risk\Actions\StoreRiskItemUpdate;
use App\Modules\Risk\Actions\UpdateRiskAssessment;
use App\Modules\Risk\Actions\UpdateRiskItem;
use App\Modules\Risk\Actions\UpdateRiskSettings;
use App\Modules\Risk\Resources\Api\V1\RiskAssessmentResource;
use App\Modules\Risk\Resources\Api\V1\RiskItemResource;
use App\Modules\Risk\Resources\Api\V1\RiskItemUpdateResource;
use App\Modules\Risk\Support\RiskInput;
use App\Modules\Risk\Support\RiskPayload;
use App\Modules\Risk\Support\RiskPolicy;
use App\Modules\Risk\Support\RiskRead;
use App\Modules\Risk\Support\RiskRevision;
use App\Modules\Risk\Support\RiskSettings;
use Illuminate\Http\Request;

/** Typed transport adapter; authoritative state changes are shared with the browser. */
class RiskController extends Controller
{
    public function assessments(Request $r)
    {
        $v = $r->validate(['q' => 'sometimes|string|max:255', 'scope' => 'sometimes|in:internal,client', 'client_id' => 'sometimes|integer|exists:clients,id', 'work_context_id' => 'sometimes|integer|exists:work_contexts,id', 'context_type' => 'sometimes|in:internal,client', 'status' => 'sometimes|string|max:50', 'sort' => 'sometimes|in:created_at,updated_at,title,status', 'direction' => 'sometimes|in:asc,desc']);
        abort_if(($r->input('scope') === 'internal' || $r->input('context_type') === 'internal') && $r->filled('client_id'), 422, 'Internal filters cannot include a Client.');
        abort_if($r->filled('scope') && $r->filled('context_type') && $r->input('scope') !== $r->input('context_type'), 422, 'Conflicting scope filters.');
        if ($r->filled('work_context_id')) {
            $ctx = \App\Modules\WorkContext\Models\WorkContext::findOrFail($r->integer('work_context_id'));
            abort_if($r->filled('client_id') && (int) $ctx->client_id !== $r->integer('client_id'), 422, 'Conflicting Client and Work Context.');
            foreach (['scope', 'context_type'] as $key) {
                abort_if($r->filled($key) && $r->input($key) !== $ctx->type, 422, 'Conflicting Work Context type.');
            }
        }
        $q = RiskAssessment::query()->with(['client', 'workContext', 'items']);
        if ($r->filled('q')) {
            $q->where(fn ($q) => $q->where('title', 'like', '%'.$v['q'].'%')->orWhere('description', 'like', '%'.$v['q'].'%'));
        }
        foreach (['client_id', 'work_context_id', 'status'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $v[$f]);
            }
        }
        if ($r->input('scope') === 'internal') {
            $q->whereNull('client_id');
        }
        if ($r->input('scope') === 'client') {
            $q->whereNotNull('client_id');
        }
        if ($r->filled('context_type')) {
            $q->whereHas('workContext', fn ($q) => $q->where('type', $v['context_type']));
        }
        $q->orderBy($v['sort'] ?? 'updated_at', $v['direction'] ?? 'desc')->orderBy('id');

        return RiskAssessmentResource::collection($q->paginate(RiskRead::size($r)));
    }

    public function showAssessment($assessment)
    {
        return new RiskAssessmentResource(RiskRead::assessment(RiskPolicy::assessment((int) $assessment)));
    }

    public function showItem($item)
    {
        return new RiskItemResource(RiskRead::item(RiskPolicy::item((int) $item)));
    }

    public function showUpdate($update)
    {
        return new RiskItemUpdateResource(RiskPolicy::update((int) $update));
    }

    public function items(Request $r, $assessment)
    {
        $parent = RiskPolicy::assessment((int) $assessment);

        return RiskItemResource::collection($parent->items()->with(['category', 'assessment'])->orderBy('id')->paginate(RiskRead::size($r)));
    }

    public function updates(Request $r, $item)
    {
        $target = RiskPolicy::item((int) $item);

        return RiskItemUpdateResource::collection($target->updates()->reorder()->orderBy('created_at')->orderBy('id')->paginate(RiskRead::size($r)));
    }

    private function command(Request $r, array $ctx, callable $callback, bool $required = false, int $status = 200)
    {
        $command = app(ExecuteRiskCommand::class)->handle($r, $ctx, $callback, $required);
        $result = $command['result'];
        $kind = $result['kind'];
        if ($result['deleted'] ?? false) {
            $data = ['id' => $result['id'], 'resource_type' => $kind, 'deleted' => true];
            if (isset($result['item_id']) && RiskItem::whereKey($result['item_id'])->whereHas('assessment')->exists()) {
                $data['item'] = (new RiskItemResource(RiskRead::item(RiskPolicy::item($result['item_id']))))->resolve($r);
            }
        } elseif ($kind === 'assessment') {
            $data = (new RiskAssessmentResource(RiskRead::assessment(RiskPolicy::assessment($result['id']))))->resolve($r);
        } elseif ($kind === 'item') {
            $data = (new RiskItemResource(RiskRead::item(RiskPolicy::item($result['id']))))->resolve($r);
        } elseif ($kind === 'update') {
            $data = (new RiskItemUpdateResource(RiskPolicy::update($result['id'])))->resolve($r);
        } else {
            $data = app(RiskSettings::class)->get() + ['revision' => RiskRevision::of(null)];
        }

        return response()->json(['data' => $data, 'operation' => $command['operation']], $status);
    }

    public function storeAssessment(Request $r)
    {
        return $this->command($r, ['kind' => 'settings', 'rule' => 'create'], function () use ($r) {
            $record = app(StoreRiskAssessment::class)->handle(RiskPayload::assessment($r, true));

            return ['kind' => 'assessment', 'id' => $record->id];
        }, false, 201);
    }

    public function updateAssessment(Request $r, $assessment)
    {
        return $this->command($r, ['kind' => 'assessment', 'id' => (int) $assessment], function () use ($r, $assessment) {
            $record = RiskPolicy::assessment((int) $assessment);
            RiskRevision::check($r, $record);
            app(UpdateRiskAssessment::class)->handle($record, RiskPayload::assessment($r, false, $record));

            return ['kind' => 'assessment', 'id' => $record->id];
        });
    }

    public function storeItem(Request $r, $assessment)
    {
        return $this->command($r, ['kind' => 'assessment', 'id' => (int) $assessment], function () use ($r, $assessment) {
            $parent = RiskPolicy::assessment((int) $assessment);
            RiskRevision::check($r, $parent);
            $item = app(StoreRiskItem::class)->handle($parent, RiskPayload::item($r, true));

            return ['kind' => 'item', 'id' => $item->id];
        }, false, 201);
    }

    public function updateItem(Request $r, $item)
    {
        return $this->command($r, ['kind' => 'item', 'id' => (int) $item, 'rule' => 'edit_item'], function () use ($r, $item) {
            $record = RiskPolicy::item((int) $item);
            RiskRevision::check($r, $record);
            app(UpdateRiskItem::class)->handle($record, RiskPayload::item($r, false), $record->updates()->exists());

            return ['kind' => 'item', 'id' => $record->id];
        });
    }

    public function storeItemUpdate(Request $r, $item)
    {
        return $this->command($r, ['kind' => 'item', 'id' => (int) $item], function () use ($r, $item) {
            $record = RiskPolicy::item((int) $item);
            RiskRevision::check($r, $record);
            $update = app(StoreRiskItemUpdate::class)->handle($record, RiskPayload::history($r));

            return ['kind' => 'update', 'id' => $update->id];
        }, false, 201);
    }

    public function approve(Request $r, $assessment)
    {
        RiskInput::only($r, []);

        return $this->command($r, ['kind' => 'assessment', 'id' => (int) $assessment], function () use ($r, $assessment) {
            $record = RiskPolicy::assessment((int) $assessment);
            RiskRevision::check($r, $record, true);
            abort_unless(app(ApproveRiskAssessment::class)->handle($record), 409, 'Every item must be mitigated or accepted, and at least one item is required.');

            return ['kind' => 'assessment', 'id' => $record->id];
        }, true);
    }

    public function destroyAssessment(Request $r, $assessment)
    {
        RiskInput::only($r, []);

        return $this->command($r, ['kind' => 'assessment', 'id' => (int) $assessment, 'rule' => 'delete_assessment'], function () use ($r, $assessment) {
            $record = RiskPolicy::assessment((int) $assessment);
            RiskRevision::check($r, $record, true);
            app(DeleteRiskRecord::class)->handle($record);

            return ['kind' => 'assessment', 'id' => $record->id, 'deleted' => true];
        }, true);
    }

    public function destroyItem(Request $r, $item)
    {
        RiskInput::only($r, []);

        return $this->command($r, ['kind' => 'item', 'id' => (int) $item, 'rule' => 'delete_item'], function () use ($r, $item) {
            $record = RiskPolicy::item((int) $item);
            RiskRevision::check($r, $record, true);
            app(DeleteRiskRecord::class)->handle($record);

            return ['kind' => 'item', 'id' => $record->id, 'deleted' => true];
        }, true);
    }

    public function destroyUpdate(Request $r, $update)
    {
        RiskInput::only($r, []);

        return $this->command($r, ['kind' => 'update', 'id' => (int) $update, 'rule' => 'delete_update'], function () use ($r, $update) {
            $record = RiskPolicy::update((int) $update);
            RiskRevision::check($r, $record, true);
            $result = ['kind' => 'update', 'id' => $record->id, 'item_id' => $record->risk_item_id, 'update_creator' => $record->created_by, 'deleted' => true];
            app(DeleteRiskItemUpdate::class)->handle($record);

            return $result;
        }, true);
    }

    public function settings()
    {
        return response()->json(['data' => app(RiskSettings::class)->get() + ['revision' => RiskRevision::of(null)]]);
    }

    public function updateSettings(Request $r)
    {
        return $this->command($r, ['kind' => 'settings'], function () use ($r) {
            RiskRevision::check($r, null, true);
            app(UpdateRiskSettings::class)->handle($r->all(), $r->isMethod('PATCH'));

            return ['kind' => 'settings'];
        }, true);
    }

    public function pdf(Request $r, $assessment)
    {
        RiskInput::only($r, []);

        return app(\App\Modules\Risk\Actions\RenderRiskPdf::class)->handle(RiskPolicy::assessment((int) $assessment));
    }

    public function operation(Request $r, $command)
    {
        $row = \App\Modules\Risk\Models\RiskApiCommand::findOrFail($command);
        abort_unless(hash_equals($row->actor_key, ExecuteRiskCommand::actorKey($r)), 404);
        RiskPolicy::context($row->context, $row->result);

        return response()->json(['data' => ['id' => $row->id, 'state' => $row->state, 'resource_type' => $row->result['kind'], 'resource_id' => $row->result['id'] ?? null]]);
    }
}
