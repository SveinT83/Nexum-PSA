<?php

namespace App\Modules\Nextcloud\Actions;

use App\Models\Clients\Client;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudFolderMapping;
use App\Modules\Nextcloud\Services\NextcloudReadClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AutoMatchClientFolders
{
    public function __construct(private readonly NextcloudReadClient $client)
    {
    }

    public function handle(NextcloudConnection $connection): array
    {
        if (! $connection->root_folder) {
            return [
                'matched' => 0,
                'deterministic' => 0,
                'ai' => 0,
                'ai_used' => false,
                'message' => 'Choose a client root folder before running Auto match.',
                'status' => 'warning',
            ];
        }

        $folders = collect($this->client->files($connection, $connection->root_folder))
            ->where('type', 'folder')
            ->values();

        $clients = $this->unmappedClients($connection);
        $unmappedFolders = $this->unmappedFolders($connection, $folders);
        $deterministicMatches = $this->deterministicMatches($clients, $unmappedFolders);
        $deterministicCount = $this->storeMatches($connection, $deterministicMatches);

        $clients = $this->unmappedClients($connection);
        $unmappedFolders = $this->unmappedFolders($connection, $folders);
        $aiMatches = $clients->isNotEmpty() && $unmappedFolders->isNotEmpty()
            ? $this->aiMatches($clients, $unmappedFolders)
            : collect();
        $aiCount = $this->storeMatches($connection, $aiMatches, true);

        $matched = $deterministicCount + $aiCount;

        return [
            'matched' => $matched,
            'deterministic' => $deterministicCount,
            'ai' => $aiCount,
            'ai_used' => $aiMatches !== null && $aiMatches->isNotEmpty(),
            'message' => "Auto match completed. {$matched} client folders matched ({$deterministicCount} direct, {$aiCount} by AI).",
            'status' => $matched > 0 ? 'success' : 'info',
        ];
    }

    private function unmappedClients(NextcloudConnection $connection): Collection
    {
        $mappedClientIds = $connection->folderMappings()
            ->where('purpose', 'client_files')
            ->where('mappable_type', Client::class)
            ->pluck('mappable_id')
            ->all();

        return Client::query()
            ->whereNotIn('id', $mappedClientIds)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function unmappedFolders(NextcloudConnection $connection, Collection $folders): Collection
    {
        $mappedPaths = $connection->folderMappings()
            ->where('purpose', 'client_files')
            ->pluck('remote_path')
            ->map(fn (string $path) => $this->normalizeFolderPath($path))
            ->all();

        return $folders
            ->reject(fn (array $folder) => in_array($this->normalizeFolderPath($folder['path']), $mappedPaths, true))
            ->values();
    }

    private function deterministicMatches(Collection $clients, Collection $folders): Collection
    {
        $folderLookup = $folders
            ->mapWithKeys(fn (array $folder) => [$this->matchKey($folder['name']) => $folder])
            ->filter(fn (array $folder, string $key) => $key !== '');

        return $clients
            ->map(function (Client $client) use ($folderLookup): ?array {
                $folder = $folderLookup[$this->matchKey($client->name)] ?? null;

                return $folder ? ['client_id' => $client->id, 'remote_path' => $folder['path']] : null;
            })
            ->filter()
            ->values();
    }

    private function aiMatches(Collection $clients, Collection $folders): Collection
    {
        $agent = AiAgent::query()
            ->with('provider')
            ->where('is_active', true)
            ->where('is_default', true)
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'))
            ->first();

        if (! $agent || ! $agent->provider) {
            return collect();
        }

        $response = $this->sendAiRequest($agent, [
            [
                'role' => 'system',
                'content' => 'You match PSA client names to Nextcloud folder names. Return only JSON. Never invent folders or clients.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'task' => 'Match each client to at most one folder when it is likely the same company. Use conservative judgment. Return {"matches":[{"client_id":1,"folder_path":"/Kunder/Acme","confidence":0.0,"reason":"short reason"}]}. Only include confidence >= 0.75.',
                    'clients' => $clients->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name])->values()->all(),
                    'folders' => $folders->map(fn (array $folder) => ['path' => $folder['path'], 'name' => $folder['name']])->values()->all(),
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $payload = json_decode($this->jsonFromResponse($response), true);
        $payload = is_array($payload) ? $payload : [];
        $validClientIds = $clients->pluck('id')->map(fn ($id) => (int) $id)->all();
        $validPaths = $folders->pluck('path')->map(fn ($path) => $this->normalizeFolderPath($path))->all();
        $usedClientIds = [];
        $usedPaths = [];

        return collect($payload['matches'] ?? [])
            ->map(function (array $match) use ($validClientIds, $validPaths, &$usedClientIds, &$usedPaths): ?array {
                $clientId = (int) ($match['client_id'] ?? 0);
                $path = $this->normalizeFolderPath($match['folder_path'] ?? '');
                $confidence = (float) ($match['confidence'] ?? 0);

                if ($confidence < 0.75 || ! in_array($clientId, $validClientIds, true) || ! in_array($path, $validPaths, true)) {
                    return null;
                }

                if (in_array($clientId, $usedClientIds, true) || in_array($path, $usedPaths, true)) {
                    return null;
                }

                $usedClientIds[] = $clientId;
                $usedPaths[] = $path;

                return ['client_id' => $clientId, 'remote_path' => $path];
            })
            ->filter()
            ->values();
    }

    private function storeMatches(NextcloudConnection $connection, Collection $matches, bool $aiSuggested = false): int
    {
        $count = 0;

        foreach ($matches as $match) {
            NextcloudFolderMapping::query()->create([
                'connection_id' => $connection->id,
                'mappable_type' => Client::class,
                'mappable_id' => $match['client_id'],
                'purpose' => 'client_files',
                'remote_path' => $this->normalizeFolderPath($match['remote_path']),
                'is_active' => true,
                'auto_created' => true,
                'metadata' => ['matched_by' => $aiSuggested ? 'ai' : 'direct'],
            ]);

            $count++;
        }

        return $count;
    }

    private function sendAiRequest(AiAgent $agent, array $messages): string
    {
        $provider = $agent->provider;
        $model = $agent->model ?: $provider->default_model;

        if (! $model) {
            return '';
        }

        return match ($provider->provider_key) {
            'openai', 'custom_openai_compatible' => $this->openAiCompatible($provider->base_url ?: 'https://api.openai.com/v1', $provider->getSecret('api_key'), $model, $messages),
            'mistral' => $this->openAiCompatible($provider->base_url ?: 'https://api.mistral.ai/v1', $provider->getSecret('api_key'), $model, $messages),
            'openrouter' => $this->openAiCompatible($provider->base_url ?: 'https://openrouter.ai/api/v1', $provider->getSecret('api_key'), $model, $messages),
            'ollama' => $this->ollama($provider->base_url, $model, $messages),
            default => '',
        };
    }

    private function openAiCompatible(?string $baseUrl, ?string $apiKey, string $model, array $messages): string
    {
        if (! $apiKey) {
            return '';
        }

        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->timeout(60)
            ->post(rtrim((string) $baseUrl, '/').'/chat/completions', [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.1,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('AI folder matching failed with HTTP '.$response->status().'.');
        }

        return (string) $response->json('choices.0.message.content');
    }

    private function ollama(?string $baseUrl, string $model, array $messages): string
    {
        if (! $baseUrl) {
            return '';
        }

        $response = Http::acceptJson()
            ->timeout(120)
            ->post(rtrim($baseUrl, '/').'/api/chat', [
                'model' => $model,
                'messages' => $messages,
                'format' => 'json',
                'stream' => false,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('AI folder matching failed with HTTP '.$response->status().'.');
        }

        return (string) $response->json('message.content');
    }

    private function jsonFromResponse(string $response): string
    {
        $response = trim($response);
        $response = preg_replace('/^```(?:json)?\s*/', '', $response) ?? $response;
        $response = preg_replace('/\s*```$/', '', $response) ?? $response;

        return trim($response);
    }

    private function normalizeFolderPath(?string $path): string
    {
        $path = trim((string) $path);

        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/'.trim($path, '/');
    }

    private function matchKey(?string $value): string
    {
        $value = Str::ascii(Str::lower((string) $value));
        $value = preg_replace('/\b(as|asa|ans|ba|da|enk|kf|sa|nuf|limited|ltd|inc|corp|llc)\b/u', '', $value) ?? $value;

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }
}
