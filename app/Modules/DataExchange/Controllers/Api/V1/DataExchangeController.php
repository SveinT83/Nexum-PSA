<?php

namespace App\Modules\DataExchange\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Actions\CommitDataExchangeImportPreview;
use App\Modules\DataExchange\Actions\RunDataExchangeExport;
use App\Modules\DataExchange\Actions\RunDataExchangeImportDryRun;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeImportPreview;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataExchangeController extends Controller
{
    public function profiles(Request $request): JsonResponse
    {
        $profiles = DataExchangeProfile::query()
            ->withCount(['runs', 'files'])
            ->orderBy('name')
            ->get()
            ->filter(fn (DataExchangeProfile $profile) => \App\Modules\Economy\Support\EconomyDataExchangeAccess::allowed($request, $profile, 'read'))
            ->values()
            ->map(fn (DataExchangeProfile $profile): array => [
                'id' => $profile->id,
                'key' => $profile->key,
                'name' => $profile->name,
                'direction' => $profile->direction,
                'format' => $profile->format,
                'status' => $profile->status,
                'runs_count' => $profile->runs_count,
                'files_count' => $profile->files_count,
            ]);

        return response()->json(['data' => $profiles]);
    }

    public function trigger(Request $request, DataExchangeProfile $profile, RunDataExchangeExport $export): JsonResponse
    {
        \App\Modules\Economy\Support\EconomyDataExchangeAccess::check($request, $profile, 'run');
        if (\App\Modules\Economy\Support\EconomyDataExchangeAccess::isEconomy($profile) && $profile->key === 'economy_orders_export') {

            return response()->json(app(\App\Modules\Economy\Actions\EconomyExportWorkflow::class)->command($request), 201);
        }
        $run = $export->handle($profile, $request->user(), 'api');
        $file = $run->files()->latest()->first();

        return response()->json([
            'data' => [
                'id' => $run->id,
                'status' => $run->status,
                'summary' => $run->summary,
                'file_id' => $file?->id,
            ],
        ], 201);
    }

    public function run(Request $request, DataExchangeRun $run): JsonResponse
    {
        \App\Modules\Economy\Support\EconomyDataExchangeAccess::check($request, DataExchangeProfile::withTrashed()->find($run->profile_id), 'read');
        $run->load(['profile', 'files']);
        if (\App\Modules\Economy\Support\EconomyDataExchangeAccess::isEconomy(DataExchangeProfile::withTrashed()->findOrFail($run->profile_id))) {
            return response()->json(['data' => \App\Modules\Economy\Actions\EconomyExportWorkflow::projection($run)]);
        }

        return response()->json([
            'data' => [
                'id' => $run->id,
                'profile' => $run->profile ? [
                    'id' => $run->profile->id,
                    'key' => $run->profile->key,
                    'name' => $run->profile->name,
                ] : null,
                'direction' => $run->direction,
                'status' => $run->status,
                'trigger_type' => $run->trigger_type,
                'summary' => $run->summary,
                'error_message' => $run->error_message,
                'files' => $run->files->map(fn (DataExchangeFile $file): array => [
                    'id' => $file->id,
                    'filename' => $file->filename,
                    'format' => $file->format,
                    'size_bytes' => $file->size_bytes,
                    'checksum' => $file->checksum,
                ])->values(),
            ],
        ]);
    }

    public function download(Request $request, DataExchangeFile $file): StreamedResponse
    {
        \App\Modules\Economy\Support\EconomyDataExchangeAccess::check($request, DataExchangeProfile::withTrashed()->find($file->profile_id), 'download');
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        $file->forceFill(['downloaded_at' => now()])->save();

        return Storage::disk($file->disk)->download($file->path, $file->filename);
    }

    public function dryRun(Request $request, RunDataExchangeImportDryRun $dryRun): JsonResponse
    {
        $data = $request->validate([
            'profile_id' => ['required', 'exists:data_exchange_profiles,id'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $profile = DataExchangeProfile::query()->findOrFail($data['profile_id']);
        $preview = $dryRun->handle($profile, $data['file'], $request->user());

        return response()->json([
            'data' => [
                'id' => $preview->id,
                'status' => $preview->status,
                'row_count' => $preview->row_count,
                'valid_count' => $preview->valid_count,
                'invalid_count' => $preview->invalid_count,
                'errors' => $preview->errors,
            ],
        ], 201);
    }

    public function commit(Request $request, DataExchangeImportPreview $preview, CommitDataExchangeImportPreview $commit): JsonResponse
    {
        $preview = $commit->handle($preview, $request->user());

        return response()->json([
            'data' => [
                'id' => $preview->id,
                'status' => $preview->status,
                'summary' => $preview->summary,
            ],
        ]);
    }
}
