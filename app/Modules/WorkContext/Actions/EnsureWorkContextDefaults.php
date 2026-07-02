<?php

namespace App\Modules\WorkContext\Actions;

use App\Models\Clients\Client;
use App\Modules\WorkContext\Models\WorkContext;
use App\Modules\WorkContext\Support\WorkContextType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EnsureWorkContextDefaults
{
    /**
     * Ensure the shared foundation contexts exist without changing domain records.
     *
     * @return array{internal: WorkContext, client_contexts: Collection<int, WorkContext>}
     */
    public function handle(): array
    {
        return DB::transaction(function (): array {
            $internal = $this->internalContext();
            $clientContexts = collect();

            Client::query()
                ->orderBy('id')
                ->chunkById(500, function (Collection $clients) use (&$clientContexts): void {
                    $clients->each(function (Client $client) use (&$clientContexts): void {
                        $clientContexts->push($this->clientContext($client));
                    });
                });

            return [
                'internal' => $internal->refresh(),
                'client_contexts' => $clientContexts,
            ];
        });
    }

    public function internalContext(): WorkContext
    {
        $internal = WorkContext::query()
            ->where('type', WorkContextType::INTERNAL)
            ->where('is_default', true)
            ->first();

        if (! $internal) {
            $internal = WorkContext::query()->firstOrCreate(
                ['type' => WorkContextType::INTERNAL, 'client_id' => null],
                [
                    'name' => 'Own organization',
                    'is_default' => true,
                    'metadata' => ['source' => 'ensure_defaults'],
                ],
            );
        }

        WorkContext::query()
            ->where('type', WorkContextType::INTERNAL)
            ->whereKeyNot($internal->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        if ($internal->client_id !== null || ! $internal->is_default) {
            $internal->forceFill([
                'client_id' => null,
                'is_default' => true,
            ])->save();
        }

        return $internal;
    }

    public function clientContext(Client $client): WorkContext
    {
        return WorkContext::query()->updateOrCreate(
            [
                'type' => WorkContextType::CLIENT,
                'client_id' => $client->id,
            ],
            [
                'name' => $client->name,
                'is_default' => false,
                'metadata' => ['source' => 'ensure_defaults'],
            ],
        );
    }
}
