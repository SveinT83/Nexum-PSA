<?php

namespace App\Modules\System\Controllers\Api\V1;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Modules\System\Actions\ExecuteSystemSettingsCommand;
use App\Modules\System\Models\SystemApiCommand;
use App\Modules\System\Queries\ApplicationVersionStatusQuery;
use App\Modules\System\Queries\QueueWorkerStatusQuery;
use App\Modules\System\Queries\SystemAdminNavigation;
use App\Modules\System\Resources\Api\V1\SystemApiView;
use App\Modules\System\Support\CompanyProfileRules;
use App\Modules\System\Support\CompanyProfileSettings;
use App\Modules\System\Support\SystemApiAccess;
use App\Modules\System\Support\SystemQueueMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/** Typed System entry points delegate settings, operational policy and persistence to their owner. */
final class SystemController
{
    /** Catch at controller dispatch, before Laravel's routing pipeline reports raw infrastructure errors. */
    public function callAction($method, $parameters)
    {
        try {
            return $this->{$method}(...array_values($parameters));
        } catch (\Illuminate\Validation\ValidationException $error) {
            return response()->json(['message' => 'Invalid System API input.', 'errors' => $error->errors()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'System target unavailable.'], 404);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            $status = $error->getStatusCode();

            return response()->json(['message' => $status === 404 ? 'System target unavailable.'
                : ($status >= 500 ? 'System storage or backend is unavailable.' : $error->getMessage())], $status >= 500 ? 503 : $status);
        } catch (\Throwable) {
            return response()->json(['message' => 'System storage or backend is unavailable.'], 503);
        }
    }

    public function context(Request $request, ApplicationVersionStatusQuery $version, SystemAdminNavigation $navigation)
    {
        $policy = app(EnforceTechRoutePermission::class);
        $sections = [];
        foreach ($navigation->all() as $section) {
            $links = [];
            foreach ($section['links'] as $link) {
                $route = Route::getRoutes()->match(Request::create($link['route'], 'GET'));
                if ($policy->allowsRoute($request->user(), (string) $route->getName())) {
                    $links[] = ['label' => $link['label'], 'url' => $link['route'], 'route' => $route->getName()];
                }
            }
            if ($links) {
                $sections[] = ['title' => $section['title'], 'description' => $section['description'], 'links' => $links];
            }
        }

        return response()->json(['data' => ['navigation' => $sections, 'version' => $version->local()]]);
    }

    public function version(ApplicationVersionStatusQuery $version)
    {
        return response()->json(['data' => $version->get()]);
    }

    public function company(CompanyProfileSettings $settings)
    {
        $snapshot = $settings->snapshot();

        return response()->json(['data' => SystemApiView::company($snapshot['values']),
            'fields' => array_keys(CompanyProfileRules::company()), 'version' => $snapshot['version']])
            ->header('ETag', $snapshot['version']);
    }

    public function updateCompany(Request $request, ExecuteSystemSettingsCommand $commands)
    {
        $data = $request->validate(CompanyProfileRules::company());

        return $commands->execute($request, 'company.update',
            fn ($settings) => ['data' => SystemApiView::company($settings->update($data))]);
    }

    public function branding(CompanyProfileSettings $settings)
    {
        $snapshot = $settings->snapshot();

        return response()->json(['data' => SystemApiView::branding($snapshot['values']),
            'fields' => array_keys(CompanyProfileRules::branding()), 'max_upload_kib' => 2048,
            'version' => $snapshot['version']])->header('ETag', $snapshot['version']);
    }

    public function updateBranding(Request $request, ExecuteSystemSettingsCommand $commands)
    {
        $data = $request->validate(CompanyProfileRules::branding());
        foreach (['remove_logo', 'remove_light_logo', 'remove_dark_logo'] as $key) {
            $data[$key] = $request->boolean($key);
        }

        return $commands->execute($request, 'branding.update', fn ($settings) => ['data' => SystemApiView::branding(
            $settings->update($data, $request->file('logo'), $request->file('logo_light'), $request->file('logo_dark')))]);
    }

    public function presets()
    {
        $presets = [];
        foreach (CompanyProfileSettings::presetValues() as $key => $values) {
            $label = $values['label'];
            unset($values['label']);
            $presets[] = ['key' => $key, 'label' => $label, 'values' => $values];
        }

        return response()->json(['data' => $presets]);
    }

    public function previewBranding(Request $request, CompanyProfileSettings $settings)
    {
        $rules = CompanyProfileRules::branding();
        foreach (['logo', 'logo_light', 'logo_dark'] as $key) {
            unset($rules[$key]);
        }
        foreach ($rules as &$rule) {
            $rule = array_map(fn ($item) => $item === 'required' ? 'sometimes' : $item, $rule);
        }
        unset($rule);
        $data = $request->validate($rules);

        $snapshot = $settings->snapshot($data);

        return response()->json(['data' => SystemApiView::branding($snapshot['values']),
            'persisted' => false, 'version' => $snapshot['version']]);
    }

    public function applyPreset(Request $request, ExecuteSystemSettingsCommand $commands)
    {
        $data = $request->validate(CompanyProfileRules::preset());

        return $commands->execute($request, 'branding.preset',
            fn ($settings) => ['data' => SystemApiView::branding($settings->applyPreset($data['preset']))]);
    }

    public function reset(Request $request, ExecuteSystemSettingsCommand $commands)
    {
        return $commands->execute($request, 'branding.reset',
            fn ($settings) => ['data' => SystemApiView::branding($settings->resetBranding())]);
    }

    public function queues(QueueWorkerStatusQuery $status)
    {
        return response()->json(['data' => $status->get(), 'maintenance_enabled' => (bool) config('system_api.maintenance_enabled')]);
    }

    public function previewMaintenance(Request $request, SystemQueueMaintenance $maintenance)
    {
        return response()->json(['data' => $maintenance->preview($request)]);
    }

    public function maintenance(Request $request, SystemQueueMaintenance $maintenance)
    {
        return $maintenance->api($request, $request->route()->defaults['system_action']);
    }

    public function operations(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $permissions = collect(['system.settings_manage', 'system.queue_manage'])
            ->filter(fn ($permission) => SystemApiAccess::allows($request->user(), $permission))->all();
        $scopes = collect(['system.settings.write', 'system.queue.restart', 'system.queue.clear', 'system.queue.retry', 'system.queue.flush'])
            ->filter(fn ($scope) => $request->user()->tokenCan($scope))->all();
        $query = SystemApiCommand::where('actor_id', $request->user()->id)
            ->where('token_hash', SystemApiAccess::tokenHash($request))->whereIn('scope', $scopes)
            ->whereIn('native_permission', $permissions)->orderByDesc('created_at')->orderByDesc('id');
        $paginator = $query->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $paginator->getCollection()->map(fn ($command) => $this->operationView($command))->all(),
            'meta' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]]);
    }

    public function operation(Request $request, string $operation)
    {
        $command = SystemApiCommand::findOrFail($operation);
        SystemApiAccess::receipt($request, $command);

        return response()->json(['data' => $this->operationView($command)]);
    }

    private function operationView(SystemApiCommand $command): array
    {
        $body = $command->result['body'];
        if (array_key_exists('target', $body)) {
            $body['target'] = (object) $body['target'];
        }

        return ['id' => $command->id, 'operation' => $command->operation, 'state' => $command->state,
            'result' => $body, 'version' => $command->result['etag'] ?? null,
            'created_at' => $command->created_at->toIso8601String(), 'updated_at' => $command->updated_at->toIso8601String()];
    }
}
