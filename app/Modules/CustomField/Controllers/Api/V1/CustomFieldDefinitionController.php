<?php

namespace App\Modules\CustomField\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\CustomField\Actions\ExecuteCustomFieldCommand;
use App\Modules\CustomField\Actions\SaveCustomFieldDefinition;
use App\Modules\CustomField\Models\CustomFieldApiCommand;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Queries\CustomFieldDefinitionQuery;
use App\Modules\CustomField\Resources\Api\V1\CustomFieldDefinitionResource;
use App\Modules\CustomField\Support\CustomFieldApiAccess;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use App\Modules\CustomField\Support\CustomFieldRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/** Domain definitions only; owning record APIs retain value authority and side effects. */
class CustomFieldDefinitionController extends Controller
{
    public function index(Request $request, CustomFieldDefinitionQuery $query)
    {
        return CustomFieldDefinitionResource::collection($query->handle($request));
    }

    public function show(Request $request, string $definition)
    {
        $record = CustomFieldDefinition::findOrFail($definition);
        abort_unless(CustomFieldApiAccess::canDiscover($request->user(), $record), 404);

        return response()->json(['data' => (new CustomFieldDefinitionResource($record))->resolve($request)])
            ->header('ETag', '"'.CustomFieldRevision::of($record).'"');
    }

    public function context(Request $request, CustomFieldModelRegistry $models)
    {
        return response()->json(['data' => ['models' => collect($models->all())->map(fn ($class, $alias) => [
            'alias' => $alias, 'model_type' => $class, 'label' => $models->displayLabelFor($alias),
        ])->values(), 'field_types' => CustomFieldDefinition::SUPPORTED_TYPES,
            'permissions' => Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name'),
            'create_defaults' => ['visible_in_ui' => true, 'editable_in_ui' => true, 'editable_via_api' => true,
                'active' => true, 'searchable' => false, 'unique_per_model' => false, 'required' => false, 'admin_only' => false, 'sort_order' => 0],
            'ticket_write_gates' => ['ui' => (bool) config('ticket_rules.capabilities.custom_fields.ui_write', false),
                'api' => (bool) config('ticket_rules.capabilities.custom_fields.api_write', false)],
            'commands_available' => Schema::hasTable('custom_field_api_commands')]]);
    }

    public function store(Request $request, ExecuteCustomFieldCommand $command, SaveCustomFieldDefinition $save)
    {
        return $command->handle($request, function () use ($request, $save) {
            $record = $save->handle($request);

            return response()->json(['data' => (new CustomFieldDefinitionResource($record))->resolve($request)], 201)
                ->header('ETag', '"'.CustomFieldRevision::of($record).'"');
        });
    }

    public function update(Request $request, string $definition, ExecuteCustomFieldCommand $command, SaveCustomFieldDefinition $save)
    {
        return $command->handle($request, function () use ($request, $definition, $save) {
            $record = $save->handle($request, (int) $definition);

            return response()->json(['data' => (new CustomFieldDefinitionResource($record))->resolve($request)])
                ->header('ETag', '"'.CustomFieldRevision::of($record).'"');
        });
    }

    public function destroy(Request $request, string $definition, ExecuteCustomFieldCommand $command, SaveCustomFieldDefinition $save)
    {
        return $command->handle($request, function () use ($request, $definition, $save) {
            $record = $save->handle($request, (int) $definition, delete: true);

            return response()->json(['data' => ['id' => (int) $record->id, 'active' => false, 'deleted' => true,
                'values_retained' => true, 'deleted_at' => $record->deleted_at->toIso8601String()]]);
        });
    }

    public function operation(Request $request, string $operation)
    {
        abort_unless(Schema::hasTable('custom_field_api_commands'), 503, 'Apply the CustomField command migration first.');
        $command = CustomFieldApiCommand::where('actor_id', $request->user()->id)
            ->where('token_hash', CustomFieldApiAccess::tokenHash($request))->findOrFail($operation);
        abort_unless($request->user()->tokenCan($command->result['ability']), 403);

        return response()->json(['data' => ['id' => $command->id, 'operation' => $command->operation,
            'state' => $command->state, 'status' => $command->result['status'],
            'result' => $command->result['body'], 'etag' => $command->result['etag']]]);
    }
}
