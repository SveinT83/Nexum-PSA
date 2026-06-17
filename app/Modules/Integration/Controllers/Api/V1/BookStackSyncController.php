<?php

namespace App\Modules\Integration\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Actions\PushKnowledgeToBookStack;
use App\Modules\Integration\Actions\SyncBookStackToKnowledge;
use App\Modules\Integration\Services\BookStack\BookStackClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookStackSyncController extends Controller
{
    public function status()
    {
        $integration = Integration::where('type', 'book_stack')->first();

        return response()->json([
            'data' => $this->statusPayload($integration),
        ]);
    }

    public function test()
    {
        $integration = $this->configuredIntegration();
        $client = $this->client($integration);
        $test = $client->testConnection();

        $integration->forceFill([
            'is_healthy' => $test['success'],
            'last_error' => $test['success'] ? null : $test['message'],
        ])->save();

        return response()->json([
            'data' => $this->statusPayload($integration->refresh()),
        ], $test['success'] ? 200 : 422);
    }

    public function pull(Request $request)
    {
        $integration = $this->configuredIntegration();
        $client = $this->client($integration);

        try {
            $summary = (new SyncBookStackToKnowledge($integration, $client, $request->user()))->execute();
        } catch (\Throwable $exception) {
            Log::error('BookStack API pull failed: '.$exception->getMessage(), [
                'integration_id' => $integration->id,
                'exception' => $exception,
            ]);

            $summary = [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 1,
                'total' => 0,
                'errors' => [$exception->getMessage()],
            ];

            $config = $integration->config ?? [];
            $config['last_sync_summary'] = $summary;
            $config['last_pull_at'] = now()->toIso8601String();

            $integration->forceFill([
                'config' => $config,
                'last_sync_at' => now(),
                'is_healthy' => false,
                'last_error' => $exception->getMessage(),
            ])->save();
        }

        return response()->json([
            'data' => [
                'summary' => $summary,
                'status' => $this->statusPayload($integration->refresh()),
            ],
        ], ($summary['failed'] ?? 0) > 0 ? 422 : 200);
    }

    public function push()
    {
        $integration = $this->configuredIntegration();
        $config = $integration->config ?? [];

        if (! ($config['two_way_sync_enabled'] ?? false)) {
            throw ValidationException::withMessages([
                'book_stack' => 'Enable two-way sync before pushing local Knowledge content to BookStack.',
            ]);
        }

        $summary = (new PushKnowledgeToBookStack($integration, $this->client($integration)))->execute();
        $statusCode = ($summary['failed'] ?? 0) > 0 || ($summary['skipped'] ?? 0) > 0 ? 422 : 200;

        return response()->json([
            'data' => [
                'summary' => $summary,
                'status' => $this->statusPayload($integration->refresh()),
            ],
        ], $statusCode);
    }

    private function configuredIntegration(): Integration
    {
        $integration = Integration::where('type', 'book_stack')->first();

        if (! $integration || $integration->status !== 'active') {
            throw ValidationException::withMessages([
                'book_stack' => 'The BookStack integration must be active before running sync operations.',
            ]);
        }

        if (! $integration->server || ! $integration->getSecret('token_id') || ! $integration->getSecret('token_secret')) {
            throw ValidationException::withMessages([
                'book_stack' => 'BookStack URL, token ID, and token secret must be configured before running sync operations.',
            ]);
        }

        return $integration;
    }

    private function client(Integration $integration): BookStackClient
    {
        return new BookStackClient(
            $integration->server,
            (string) $integration->getSecret('token_id'),
            (string) $integration->getSecret('token_secret'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(?Integration $integration): array
    {
        $config = $integration?->config ?? [];

        return [
            'configured' => (bool) $integration,
            'active' => $integration?->status === 'active',
            'server' => $integration?->server,
            'is_healthy' => (bool) ($integration?->is_healthy ?? false),
            'last_error' => $integration?->last_error,
            'last_sync_at' => $integration?->last_sync_at,
            'last_pull_at' => $config['last_pull_at'] ?? null,
            'last_push_at' => $config['last_push_at'] ?? null,
            'sync_interval_minutes' => $config['sync_interval_minutes'] ?? null,
            'two_way_sync_enabled' => (bool) ($config['two_way_sync_enabled'] ?? false),
            'sync_mode' => $config['sync_mode'] ?? (($config['two_way_sync_enabled'] ?? false) ? 'two_way' : 'pull_only'),
            'last_pull_summary' => $config['last_sync_summary'] ?? null,
            'last_push_summary' => $config['last_push_summary'] ?? null,
        ];
    }
}
