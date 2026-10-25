<?php

namespace App\Modules\DataExchange\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Actions\CommitDataExchangeImportPreview;
use App\Modules\DataExchange\Actions\EnsureDataExchangeProfileTemplates;
use App\Modules\DataExchange\Actions\ExecuteDataExchangeCommand;
use App\Modules\DataExchange\Actions\RunDataExchangeImportDryRun;
use App\Modules\DataExchange\Actions\SaveDataExchangeOperations;
use App\Modules\DataExchange\Actions\SaveDataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeAuditEvent;
use App\Modules\DataExchange\Models\DataExchangeCommandReceipt;
use App\Modules\DataExchange\Models\DataExchangeDeliveryAttempt;
use App\Modules\DataExchange\Models\DataExchangeDeliveryTarget;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeImportPreview;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeRun;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use App\Modules\DataExchange\Queries\DataExchangeQueries;
use App\Modules\DataExchange\Support\DataExchangeAuthority as Authority;
use App\Modules\DataExchange\Support\DataExchangeInput as Input;
use App\Modules\DataExchange\Support\DataExchangeProjection as Projection;
use App\Modules\DataExchange\Support\DataExchangeRevision as Revision;
use App\Modules\DataExchange\Support\DataExchangeSourceRegistry;
use App\Modules\Economy\Actions\EconomyExportWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Thin official HTTP adapters; composition, execution and ownership remain in domain actions. */
class DataExchangeController extends Controller
{
    public function context(Request $request): JsonResponse
    {
        Input::validate($request, []);
        $ids = app(DataExchangeQueries::class)->profileIds($request);
        $actions = [];
        foreach (['manage', 'run', 'download', 'import', 'approve_import', 'schedule', 'delivery'] as $action) {
            $actions[$action] = Authority::permits($request->user(), 'data_exchange.'.$action) && $request->user()->tokenCan('data_exchange.'.$action);
        }

        return response()->json(['data' => [
            'formats' => ['csv', 'xlsx', 'json'], 'directions' => ['export', 'import'], 'statuses' => ['draft', 'active', 'paused'],
            'filter_operators' => DataExchangeSourceRegistry::OPERATORS, 'timezone' => config('app.timezone'),
            'capabilities' => ['automatic_import_pickup' => false, 'api_delivery_enabled' => (bool) config('data_exchange.api_delivery_enabled'), 'max_import_bytes' => 10485760, 'max_import_rows' => 10000],
            'actions' => $actions, 'sources' => $this->sourceData($request),
            'counts' => ['profiles' => DataExchangeProfile::whereIn('id', $ids)->count(), 'runs' => DataExchangeRun::whereIn('profile_id', $ids)->count(), 'files' => DataExchangeFile::whereIn('profile_id', $ids)->count()],
            'delivery_disks' => $actions['delivery'] ? config('data_exchange.delivery_disks', []) : [],
            'credential_references' => $actions['delivery'] ? config('data_exchange.credential_references', []) : [],
        ]]);
    }

    public function sources(Request $request): JsonResponse
    {
        Input::validate($request, []);

        return response()->json(['data' => $this->sourceData($request)]);
    }

    public function source(Request $request, string $sourceKey): JsonResponse
    {
        Input::validate($request, []);
        $source = collect($this->sourceData($request))->firstWhere('key', $sourceKey);
        abort_unless($source, 404);

        return response()->json(['data' => $source]);
    }

    private function sourceData(Request $request): array
    {
        return app(DataExchangeSourceRegistry::class)->visibleFor($request->user())->map(fn ($s) => [
            'key' => $s->key, 'label' => $s->label, 'module' => $s->module, 'supports_export' => $s->supportsExport, 'supports_import' => $s->supportsImport,
            'fields' => collect($s->fields())->filter(fn ($f) => ! $f->blocked && ! $f->sensitive)->map(fn ($f) => ['key' => $f->key, 'label' => $f->label, 'type' => $f->type, 'exportable' => $f->exportable, 'importable' => $f->importable, 'relation' => $f->relation])->values()->all(),
            'filters' => $s->filters, 'relations' => $s->relations,
        ])->values()->all();
    }

    public function profiles(Request $request): JsonResponse
    {
        return response()->json(app(DataExchangeQueries::class)->page($request, DataExchangeProfile::query(), fn ($p) => Projection::profile($p), true));
    }

    public function profile(Request $request, DataExchangeProfile $profile): JsonResponse
    {
        Input::validate($request, []);
        Authority::profile($request, $profile);

        return response()->json(['data' => Projection::profile($profile)]);
    }

    public function storeProfile(Request $request): JsonResponse
    {
        // The shared action validates the full composition and rejects unknown nested fields.
        $result = app(ExecuteDataExchangeCommand::class)->handle($request, $request->all(), function () use ($request) {
            $source = Authority::source((string) $request->input('source_key'), $request->user());
            if ($source->key === 'economy.orders') {
                abort_unless($request->user()->tokenCan('economy.export') && Authority::permits($request->user(), 'economy.view'), 403);
            }
            $profile = app(SaveDataExchangeProfile::class)->handle($request->all(), $request->user());

            return ['data' => Projection::profile($profile), 'profile_id' => $profile->id];
        });

        return response()->json($result, 201);
    }

    public function updateProfile(Request $request, DataExchangeProfile $profile): JsonResponse
    {
        Authority::profile($request, $profile, 'manage');
        $input = $request->all();
        $result = app(ExecuteDataExchangeCommand::class)->handle($request, $input, function () use ($request, $profile, $input) {
            $profile = $profile->fresh();
            $data = array_replace(SaveDataExchangeProfile::editable($profile), $input);
            Authority::source($data['source_key'], $request->user());
            if ($data['source_key'] === 'economy.orders') {
                abort_unless($request->user()->tokenCan('economy.export'), 403);
            }
            $saved = app(SaveDataExchangeProfile::class)->handle($data, $request->user(), $profile, $request->header('If-Match'));

            return ['data' => Projection::profile($saved), 'profile_id' => $saved->id];
        }, $profile);

        return response()->json($result);
    }

    public function templates(Request $request): JsonResponse
    {
        $input = Input::validate($request, ['source_key' => 'required|in:clients,economy.orders']);
        $result = app(ExecuteDataExchangeCommand::class)->handle($request, $input, function () use ($request, $input) {
            Authority::source($input['source_key'], $request->user());
            if ($input['source_key'] === 'economy.orders') {
                abort_unless($request->user()->tokenCan('economy.export'), 403);
            }
            $templates = app(EnsureDataExchangeProfileTemplates::class);
            $profile = $input['source_key'] === 'clients' ? $templates->clientsBasicImportProfile($request->user()) : $templates->economyOrdersExportProfile($request->user());

            return ['data' => Projection::profile($profile), 'profile_id' => $profile->id];
        });

        return response()->json($result, 201);
    }

    public function exportPreview(Request $request, DataExchangeProfile $profile): JsonResponse
    {
        Input::validate($request, []);
        Authority::profile($request, $profile, 'run');

        return response()->json(['data' => app(\App\Modules\DataExchange\Actions\DataExchangeExportWorkflow::class)->preview($profile)]);
    }

    public function trigger(Request $request, DataExchangeProfile $profile): JsonResponse
    {
        Authority::profile($request, $profile, 'run');
        if ($profile->key === 'economy_orders_export') {
            return response()->json(app(EconomyExportWorkflow::class)->command($request), 201);
        }
        $input = Input::validate($request, ['preview_hash' => 'required|string|size:64']);
        $result = app(ExecuteDataExchangeCommand::class)->handle($request, $input, function () use ($request, $profile, $input) {
            $profile = $profile->fresh();
            abort_unless(hash_equals(app(\App\Modules\DataExchange\Actions\DataExchangeExportWorkflow::class)->preview($profile)['preview_hash'], $input['preview_hash']), 409, 'The export changed; preview again.');
            $run = app(\App\Modules\DataExchange\Actions\DataExchangeExportWorkflow::class)->execute($profile, $request->user());

            return ['data' => Projection::run($run) + ['file_id' => $run->files()->value('id')]];
        }, $profile, 'run');

        return response()->json($result, 201);
    }

    public function runs(Request $request): JsonResponse
    {
        return response()->json(app(DataExchangeQueries::class)->page($request, DataExchangeRun::query(), fn ($r) => Projection::run($r)));
    }

    public function run(Request $request, DataExchangeRun $run): JsonResponse
    {
        Input::validate($request, []);
        Authority::profile($request, DataExchangeProfile::withTrashed()->find($run->profile_id));

        return response()->json(['data' => Projection::run($run)]);
    }

    public function files(Request $request): JsonResponse
    {
        return response()->json(app(DataExchangeQueries::class)->page($request, DataExchangeFile::query(), fn ($f) => Projection::file($f)));
    }

    public function file(Request $request, DataExchangeFile $file): JsonResponse
    {
        Input::validate($request, []);
        Authority::profile($request, DataExchangeProfile::withTrashed()->find($file->profile_id));

        return response()->json(['data' => Projection::file($file)]);
    }

    public function download(Request $request, DataExchangeFile $file): StreamedResponse
    {
        Input::validate($request, []);
        Authority::profile($request, DataExchangeProfile::withTrashed()->find($file->profile_id), 'download');
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);
        abort_unless(hash_equals($file->checksum, hash('sha256', Storage::disk($file->disk)->get($file->path))), 409, 'The file checksum changed.');
        $file->forceFill(['downloaded_at' => now()])->save();

        return Storage::disk($file->disk)->download($file->path, $file->filename);
    }

    public function imports(Request $request): JsonResponse
    {
        return response()->json(app(DataExchangeQueries::class)->page($request, DataExchangeImportPreview::query(), fn ($p) => Projection::preview($p, 0, 0)));
    }

    public function preview(Request $request, DataExchangeImportPreview $preview): JsonResponse
    {
        $input = Input::validate($request, ['rows_offset' => 'sometimes|integer|min:0', 'rows_limit' => 'sometimes|integer|between:1,100']);
        Authority::profile($request, DataExchangeProfile::withTrashed()->find($preview->profile_id));

        return response()->json(['data' => Projection::preview($preview, $input['rows_offset'] ?? 0, $input['rows_limit'] ?? 100)]);
    }

    public function dryRun(Request $request): JsonResponse
    {
        $input = Input::validate($request, ['profile_id' => 'required|integer|exists:data_exchange_profiles,id', 'file' => 'required|file|max:10240']);
        $profile = DataExchangeProfile::findOrFail($input['profile_id']);
        Authority::profile($request, $profile, 'import');
        $hashInput = ['profile_id' => $profile->id, 'file_checksum' => hash_file('sha256', $input['file']->getRealPath()), 'filename' => $input['file']->getClientOriginalName()];
        $result = app(ExecuteDataExchangeCommand::class)->handle($request, $hashInput, function () use ($request, $input, $profile) {
            $preview = app(RunDataExchangeImportDryRun::class)->handle($profile, $input['file'], $request->user());

            return ['data' => Projection::preview($preview)];
        }, $profile, 'import', false);

        return response()->json($result, 201);
    }

    public function commit(Request $request, DataExchangeImportPreview $preview): JsonResponse
    {
        Input::validate($request, []);
        $profile = DataExchangeProfile::withTrashed()->findOrFail($preview->profile_id);
        Authority::profile($request, $profile, 'approve_import');
        $result = app(ExecuteDataExchangeCommand::class)->handle($request, [], function () use ($request, $preview) {
            Revision::match($preview->fresh(), $request->header('If-Match'));
            $saved = app(CommitDataExchangeImportPreview::class)->handle($preview, $request->user());

            return ['data' => Projection::preview($saved)];
        }, $profile, 'approve_import');

        return response()->json($result);
    }

    public function schedules(Request $request): JsonResponse
    {
        return response()->json(app(DataExchangeQueries::class)->page($request, DataExchangeSchedule::query(), fn ($s) => Projection::schedule($s)));
    }

    public function schedule(Request $request, DataExchangeSchedule $schedule): JsonResponse
    {
        Input::validate($request, []);
        Authority::profile($request, $schedule->profile, 'schedule');

        return response()->json(['data' => Projection::schedule($schedule)]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        return $this->saveSchedule($request);
    }

    public function updateSchedule(Request $request, DataExchangeSchedule $schedule): JsonResponse
    {
        Authority::profile($request, $schedule->profile, 'schedule');

        return $this->saveSchedule($request, $schedule);
    }

    private function saveSchedule(Request $request, ?DataExchangeSchedule $schedule = null): JsonResponse
    {
        $input = $request->all();
        $base = $schedule ? collect(Projection::schedule($schedule))->only(['profile_id', 'delivery_target_id', 'direction', 'active', 'frequency', 'run_time', 'weekdays'])->all() : [];
        $data = array_replace($base, $input);
        $profile = DataExchangeProfile::findOrFail($data['profile_id'] ?? 0);
        Authority::profile($request, $profile, 'schedule');
        $result = app(ExecuteDataExchangeCommand::class)->handle($request, $input, function () use ($request, $data, $schedule) {
            $saved = app(SaveDataExchangeOperations::class)->schedule($data, $request->user(), $schedule, $request->header('If-Match'));

            return ['data' => Projection::schedule($saved)];
        }, $profile, 'schedule');

        return response()->json($result, $schedule ? 200 : 201);
    }

    public function targets(Request $request): JsonResponse
    {
        return response()->json(app(DataExchangeQueries::class)->page($request, DataExchangeDeliveryTarget::query(), fn ($t) => Projection::target($t), false, true));
    }

    public function target(Request $request, DataExchangeDeliveryTarget $deliveryTarget): JsonResponse
    {
        Input::validate($request, []);
        $this->targetAccess($request, $deliveryTarget);

        return response()->json(['data' => Projection::target($deliveryTarget)]);
    }

    public function storeTarget(Request $request): JsonResponse
    {
        return $this->saveTarget($request);
    }

    public function updateTarget(Request $request, DataExchangeDeliveryTarget $deliveryTarget): JsonResponse
    {
        $this->targetAccess($request, $deliveryTarget);

        return $this->saveTarget($request, $deliveryTarget);
    }

    private function saveTarget(Request $request, ?DataExchangeDeliveryTarget $target = null): JsonResponse
    {
        $input = $request->all();
        $base = $target ? collect(Projection::target($target))->except(['id', 'revision'])->all() : [];
        $data = array_replace($base, $input);
        $profile = ($data['profile_id'] ?? null) ? DataExchangeProfile::findOrFail($data['profile_id']) : null;
        if ($profile) {
            Authority::profile($request, $profile, 'delivery');
        }
        $result = app(ExecuteDataExchangeCommand::class)->handle($request, $input, function () use ($request, $data, $target) {
            $saved = app(SaveDataExchangeOperations::class)->target($data, $request->user(), $target, $request->header('If-Match'));

            return ['data' => Projection::target($saved)];
        }, $profile, 'delivery');

        return response()->json($result, $target ? 200 : 201);
    }

    private function targetAccess(Request $request, DataExchangeDeliveryTarget $target): void
    {
        Authority::actor($request, 'data_exchange.delivery');
        if ($target->profile_id) {
            Authority::profile($request, DataExchangeProfile::withTrashed()->find($target->profile_id), 'delivery');
        }
    }

    public function deliveryPreview(Request $request, DataExchangeFile $file): JsonResponse
    {
        $input = Input::validate($request, ['target_id' => 'required|integer|min:1']);
        Authority::profile($request, DataExchangeProfile::withTrashed()->find($file->profile_id), 'delivery');
        $target = DataExchangeDeliveryTarget::findOrFail($input['target_id']);
        $this->targetAccess($request, $target);
        $data = app(\App\Modules\DataExchange\Actions\VerifiedDataExchangeDelivery::class)->preview($file, $target);
        unset($data['snapshot']);

        return response()->json(['data' => $data]);
    }

    public function deliver(Request $request, DataExchangeFile $file): JsonResponse
    {
        $input = Input::validate($request, ['target_id' => 'required|integer|min:1', 'preview_hash' => 'required|string|size:64']);
        abort_unless(config('data_exchange.api_delivery_enabled'), 503, 'API delivery is disabled. Preview and inspection remain available.');
        $profile = DataExchangeProfile::withTrashed()->findOrFail($file->profile_id);
        Authority::profile($request, $profile, 'delivery');
        $target = DataExchangeDeliveryTarget::findOrFail($input['target_id']);
        $this->targetAccess($request, $target);
        $delivery = app(\App\Modules\DataExchange\Actions\VerifiedDataExchangeDelivery::class);
        $result = app(ExecuteDataExchangeCommand::class)->handle($request, $input, function () use ($delivery, $file, $target, $input) {
            abort_unless(hash_equals($delivery->preview($file, $target)['preview_hash'], $input['preview_hash']), 409, 'The delivery changed; preview again.');

            return ['data' => Projection::attempt($delivery->prepare($file, $target, actor: request()->user()))];
        }, $profile, 'delivery');
        // The receipt and frozen intent commit BEFORE external I/O. A retry cannot restart a writing attempt.
        $attempt = DataExchangeDeliveryAttempt::findOrFail($result['data']['id']);
        $result['data'] = Projection::attempt($delivery->perform($attempt));

        return response()->json($result, 201);
    }

    public function attempts(Request $request): JsonResponse
    {
        $input = Input::validate($request, ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|between:1,100', 'profile_id' => 'sometimes|integer|min:1', 'status' => 'sometimes|in:queued,writing,unknown,succeeded,failed']);
        $ids = app(DataExchangeQueries::class)->profileIds($request);
        $query = DataExchangeDeliveryAttempt::whereHas('file', fn ($q) => $q->whereIn('profile_id', $ids));
        if (isset($input['profile_id'])) {
            $query->whereHas('file', fn ($q) => $q->where('profile_id', $input['profile_id']));
        }
        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }
        $page = $query->orderByDesc('id')->paginate($input['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn ($a) => Projection::attempt($a))->all(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function attempt(Request $request, DataExchangeDeliveryAttempt $attempt): JsonResponse
    {
        Input::validate($request, []);
        $this->attemptAccess($request, $attempt);

        return response()->json(['data' => Projection::attempt($attempt)]);
    }

    private function attemptAccess(Request $request, DataExchangeDeliveryAttempt $attempt): void
    {
        $file = DataExchangeFile::findOrFail($attempt->file_id);
        Authority::profile($request, DataExchangeProfile::withTrashed()->find($file->profile_id), 'delivery');
        $this->targetAccess($request, DataExchangeDeliveryTarget::withTrashed()->findOrFail($attempt->delivery_target_id));
    }

    public function retryDelivery(Request $request, DataExchangeDeliveryAttempt $attempt): JsonResponse
    {
        $this->attemptAccess($request, $attempt);
        abort_unless($attempt->status === 'failed' && data_get($attempt->metadata, 'safe_to_retry', false), 409, 'Inspect/reconcile the delivery before retrying.');
        abort_unless((int) $request->input('target_id') === $attempt->delivery_target_id, 422, 'Retry must keep the original target.');
        $file = DataExchangeFile::findOrFail($attempt->file_id);

        return $this->deliver($request, $file);
    }

    public function reconcileDelivery(Request $request, DataExchangeDeliveryAttempt $attempt): JsonResponse
    {
        Input::validate($request, []);
        $this->attemptAccess($request, $attempt);
        // Reconciliation reads the frozen destination; it never writes or changes the target.
        $data = app(\App\Modules\DataExchange\Actions\VerifiedDataExchangeDelivery::class)->reconcile($attempt);

        return response()->json(['data' => Projection::attempt($data)]);
    }

    public function audit(Request $request): JsonResponse
    {
        return response()->json(app(DataExchangeQueries::class)->page($request, DataExchangeAuditEvent::query(), fn ($a) => [
            'id' => $a->id, 'profile_id' => $a->profile_id, 'run_id' => $a->run_id, 'file_id' => $a->file_id,
            'actor_id' => $a->actor_id, 'event_type' => $a->event_type, 'outcome' => $a->outcome, 'occurred_at' => $a->occurred_at?->toIso8601String(),
        ]));
    }

    public function operation(Request $request, DataExchangeCommandReceipt $operation): JsonResponse
    {
        Input::validate($request, []);
        abort_unless($operation->actor_id === $request->user()->id, 404);
        $original = \Illuminate\Support\Facades\Route::getRoutes()->getByName($operation->operation);
        abort_unless($original && isset($original->defaults['dx_permission'], $original->defaults['dx_ability']), 403);
        Authority::actor($request, 'data_exchange.'.$original->defaults['dx_permission']);
        abort_unless($request->user()->tokenCan('data_exchange.'.$original->defaults['dx_ability']), 403);
        if ($operation->profile_id) {
            Authority::profile($request, DataExchangeProfile::withTrashed()->find($operation->profile_id));
        }

        $result = $operation->result;
        if (str_contains($operation->operation, 'deliver')) {
            $attempt = DataExchangeDeliveryAttempt::find(data_get($result, 'data.id'));
            if ($attempt) {
                $this->attemptAccess($request, $attempt);
                $result['data'] = Projection::attempt($attempt);
            }
        }

        return response()->json(['data' => ['id' => $operation->id, 'operation' => $operation->operation, 'result' => $result, 'created_at' => $operation->created_at->toIso8601String()]]);
    }
}
