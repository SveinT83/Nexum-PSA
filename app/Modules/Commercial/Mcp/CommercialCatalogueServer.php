<?php

namespace App\Modules\Commercial\Mcp;

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;

/** An authenticated employee connection. Each tool delegates to an existing named Commercial API route. */
final class CommercialCatalogueServer extends Server
{
    protected string $name = 'Nexum Commercial';
    protected string $version = '1.0.0';
    protected string $instructions = 'Use a personal Nexum token for this installation. Read vendor and unit IDs before creating a cost. Read the service revision before linking costs or terms. Reuse the same idempotency key for identical retries.';
    protected array $capabilities = ['tools' => ['listChanged' => false]];

    private const ROUTES = [
        'costs.index' => 'GET', 'costs.show' => 'GET', 'costs.context' => 'GET', 'costs.lookup' => 'GET', 'costs.store' => 'POST',
        'terms.index' => 'GET', 'terms.show' => 'GET', 'terms.context' => 'GET', 'terms.store' => 'POST',
        'services.index' => 'GET', 'services.show' => 'GET', 'services.update' => 'PATCH',
    ];

    /** Reply with our latest supported protocol when a newer client offers a different version. */
    protected function handleInitializeMessage(JsonRpcRequest $request, ServerContext $context): void
    {
        $offered = $request->get('protocolVersion');
        if (is_string($offered) && ! in_array($offered, $context->supportedProtocolVersions, true)) {
            $request->params['protocolVersion'] = $context->supportedProtocolVersions[0];
        }
        parent::handleInitializeMessage($request, $context);
    }

    protected function boot(): void
    {
        $contract = json_decode(file_get_contents(__DIR__.'/../Docs/api-contract.json'), true, flags: JSON_THROW_ON_ERROR);
        $operations = [];
        foreach ($contract as $operation) {
            $operations[$operation['method'].' '.$operation['path']] = $operation;
        }
        foreach (self::ROUTES as $name => $method) {
            $route = Route::getRoutes()->getByName('api.v1.commercial.'.$name);
            $operation = $operations[$method.' /'.$route->uri()];
            $this->tools[] = new CommercialCatalogueTool($route, $method, $operation);
        }
    }
}
