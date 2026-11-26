<?php

namespace App\Modules\Commercial\Mcp;

use App\Modules\Integration\Support\DomainApiAuthorization;
use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Laravel\Mcp\Request as ToolRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class CommercialCatalogueTool extends Tool
{
    public function __construct(private Route $route, private string $method, private array $operation)
    {
        $this->name = $operation['operationId'];
    }

    public function shouldRegister(Request $request, DomainApiAuthorization $rights): bool
    {
        $ability = match ($this->method) {
            'POST' => 'commercial.create', 'PATCH' => 'commercial.update', default => 'commercial.read',
        };

        return $request->user()?->tokenCan($ability) && $rights->permits($request, $this->route->defaults['commercial_permission']);
    }

    public function toArray(): array
    {
        $properties = [];
        $required = [];
        foreach (($this->operation['parameters'] ?? []) as $parameter) {
            $name = $parameter['name'];
            $properties[$name] = $parameter['schema'];
            if (($parameter['required'] ?? false) || $parameter['in'] === 'header') {
                $required[] = $name;
            }
        }
        if (isset($this->operation['requestBody'])) {
            $body = $this->operation['requestBody']['content']['application/json']['schema'];
            if ($this->method === 'PATCH') {
                $body['properties'] = array_intersect_key($body['properties'], array_flip(['costs', 'terms']));
                $body['minProperties'] = 1;
            }
            $properties['body'] = $body;
            $required[] = 'body';
        }
        $description = $this->method === 'PATCH' ? 'Update only cost and versioned term links on an existing service. Supply the current revision.' : $this->operation['description'];

        return ['name' => $this->name, 'description' => $this->operation['summary'].'. '.$description,
            'inputSchema' => ['type' => 'object', 'properties' => (object) $this->jsonSchema($properties),
                'required' => $required, 'additionalProperties' => false],
            'annotations' => ['readOnlyHint' => $this->method === 'GET']];
    }

    /** The domain contract uses OpenAPI 3.0 nullability; MCP tools advertise JSON Schema. */
    private function jsonSchema(array $value): array
    {
        if (array_key_exists('nullable', $value)) {
            if ($value['nullable'] && isset($value['type'])) {
                $value['type'] = [$value['type'], 'null'];
                if (isset($value['enum'])) {
                    $value['enum'][] = null;
                }
            }
            unset($value['nullable']);
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->jsonSchema($child);
            }
        }

        return $value;
    }

    public function handle(ToolRequest $input, Router $router): Response
    {
        $outer = request();
        $args = $input->all();
        $path = [];
        $query = [];
        $headers = [];
        $known = [];
        foreach (($this->operation['parameters'] ?? []) as $parameter) {
            $name = $parameter['name'];
            $known[] = $name;
            if (! array_key_exists($name, $args)) {
                if (($parameter['required'] ?? false) || $parameter['in'] === 'header') {
                    return Response::error('Missing argument: '.$name);
                }
                continue;
            }
            $value = $args[$name];
            if (! is_string($value) && ! is_int($value)) {
                return Response::error('Invalid argument: '.$name);
            }
            switch ($parameter['in']) {
                case 'path':
                    if (isset($this->route->wheres[$name]) && ! preg_match('~^(?:'.$this->route->wheres[$name].')$~D', (string) $value)) {
                        return Response::error('Invalid path argument: '.$name);
                    }
                    if (! preg_match('/^[a-zA-Z0-9_-]+$/D', (string) $value)) {
                        return Response::error('Invalid path argument: '.$name);
                    }
                    $path[$name] = $value;
                    break;
                case 'query':
                    $query[$name] = $value;
                    break;
                case 'header':
                    if (! is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                        return Response::error('Invalid header argument: '.$name);
                    }
                    $headers[$name] = $value;
                    break;
            }
        }
        $body = $args['body'] ?? [];
        if (isset($this->operation['requestBody'])) {
            $known[] = 'body';
            if (! is_array($body) || ! $body || array_is_list($body)) {
                return Response::error('A JSON body is required.');
            }
        }
        if (array_diff(array_keys($args), $known) || ($this->method === 'PATCH' && array_diff(array_keys($body), ['costs', 'terms']))) {
            return Response::error('Unsupported argument or service field.');
        }

        // Dispatch a clone through the real API middleware and bindings in the same process.
        // No employee credential leaves this application, and native idempotency uses the original REST path.
        $uri = route($this->route->getName(), $path + $query, false);
        $sub = Request::create($uri, $this->method, [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => $outer->header('Authorization'), 'REMOTE_ADDR' => $outer->ip(),
            'HTTPS' => $outer->isSecure() ? 'on' : 'off', 'HTTP_HOST' => $outer->getHttpHost(),
        ], $this->method === 'GET' ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $sub->headers->add($headers);
        $sub->setUserResolver($outer->getUserResolver());
        $route = (clone $this->route)->bind($sub);
        $sub->setRouteResolver(fn () => $route);
        app()->instance('request', $sub);
        try {
            $response = (new Pipeline(app()))->send($sub)->through($router->gatherRouteMiddleware($route))
                ->then(fn (Request $request) => $router->prepareResponse($request, $route->run()));
        } finally {
            app()->instance('request', $outer);
        }
        $status = $response->getStatusCode();
        if ($status >= 500) {
            return Response::error('Commercial API unavailable (HTTP '.$status.'). Read back any previously submitted write before retrying.');
        }
        $result = json_decode($response->getContent(), true);
        $payload = ['status' => $status, 'result' => $result];
        if ($status >= 400) {
            // Laravel debug responses can include an exception trace. Keep only native validation feedback.
            $payload['result'] = array_intersect_key((array) $result, array_flip(['message', 'errors']));
            return Response::error(json_encode($payload, JSON_THROW_ON_ERROR));
        }

        return Response::json($payload);
    }
}
