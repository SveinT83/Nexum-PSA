<?php

namespace App\Modules\Warroom\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Warroom\Actions\SaveWarroomSettings;
use App\Modules\Warroom\Models\WarroomApiCommand;
use App\Modules\Warroom\Queries\MyDayWorkQuery;
use App\Modules\Warroom\Queries\WarroomDashboardQuery;
use App\Modules\Warroom\Resources\Api\V1\WarroomSnapshot;
use App\Modules\Warroom\Support\WarroomAccess;
use App\Modules\Warroom\Support\WarroomSettings;
use App\Modules\Warroom\Support\WarroomSources;
use Illuminate\Http\Request;

/** HTTP adapters only; shared queries/actions retain browser and domain semantics. */
class WarroomController extends Controller
{
    public function context(Request $request, WarroomSources $sources, WarroomSettings $settings)
    {
        $actor = $request->user();

        return response()->json(['data' => [
            'timezone' => config('app.timezone'), 'source_states' => $sources->states($actor),
            'section_options' => WarroomSettings::SECTION_OPTIONS, 'settings' => $settings->get(),
            'preview_limits' => ['tickets' => 8, 'tasks' => 8, 'events' => 12],
            'pagination' => ['default_per_page' => 25, 'maximum_per_page' => 100],
            'actions' => array_merge($sources->links($actor, true), $sources->workdayLinks($actor)), 'lanes' => $sources->links($actor),
            'capabilities' => ['read_dashboard' => true, 'read_my_day' => true,
                'read_settings' => WarroomAccess::allows($actor, 'warroom.manage_settings') && $actor->tokenCan('warroom.settings.read'),
                'update_settings' => WarroomAccess::allows($actor, 'warroom.manage_settings') && $actor->tokenCan('warroom.settings.manage')],
        ]]);
    }

    public function dashboard(Request $request, WarroomDashboardQuery $query)
    {
        return response()->json(['data' => WarroomSnapshot::dashboard($query->forUser($request->user()))]);
    }

    public function myDay(Request $request, MyDayWorkQuery $query)
    {
        $input = $request->validate(['focus' => 'sometimes|in:overdue', 'page' => 'sometimes|integer|min:1|max:10000',
            'per_page' => 'sometimes|integer|min:1|max:100']);
        abort_if(! isset($input['focus']) && (isset($input['page']) || isset($input['per_page'])), 422, 'Pagination requires overdue focus.');

        return response()->json(['data' => WarroomSnapshot::myDay($query->forUser($request->user(), null,
            isset($input['focus']), (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 25)))]);
    }

    public function overdue(Request $request, MyDayWorkQuery $query, WarroomSources $sources)
    {
        $input = $request->validate(['page' => 'sometimes|integer|min:1|max:10000', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return response()->json(['data' => WarroomSnapshot::overdue($query->overdue($request->user(), now(),
            $sources->states($request->user()), (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 25)))]);
    }

    public function settings(SaveWarroomSettings $action)
    {
        $result = $action->snapshot();

        return response()->json(['data' => $result])->header('ETag', $result['revision']);
    }

    public function updateSettings(Request $request, SaveWarroomSettings $action)
    {
        $result = $action->execute($request->user(), $request->all(), $request->header('If-Match'),
            $request->header('Idempotency-Key'), WarroomAccess::tokenHash($request));

        return response()->json(['data' => $result])->header('ETag', $result['revision'])->header('X-Operation-Id', $result['operation_id']);
    }

    public function operation(Request $request, string $command)
    {
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('warroom_api_commands'), 503, 'The Warroom settings-command migration is required.');
        $receipt = WarroomApiCommand::whereKey($command)->where('actor_id', $request->user()->id)
            ->where('token_hash', WarroomAccess::tokenHash($request))->firstOrFail();

        return response()->json(['data' => ['id' => $receipt->id, 'state' => 'completed',
            'result' => $receipt->result, 'created_at' => $receipt->created_at->toIso8601String()]]);
    }
}
