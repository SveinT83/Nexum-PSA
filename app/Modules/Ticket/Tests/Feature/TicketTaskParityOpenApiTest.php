<?php

namespace App\Modules\Ticket\Tests\Feature;

use Illuminate\Support\Facades\Route;
use OpenApi\Generator;
use Psr\Log\NullLogger;
use Tests\TestCase;

class TicketTaskParityOpenApiTest extends TestCase
{
    public function test_every_registered_ticket_and_task_http_operation_has_a_complete_openapi_contract(): void
    {
        // Generate in memory: the test must not overwrite the independently served document.
        // Match the deployed attribute scanner, including its development-only exclusions.
        $scan = config('l5-swagger.defaults.scanOptions', []);
        $sources = new \OpenApi\SourceFinder(
            config('l5-swagger.documentations.default.paths.annotations', [app_path()]),
            $scan['exclude'] ?? [],
            $scan['pattern'] ?? '*.php',
        );
        $generator = new Generator(new NullLogger);
        $generator->setAnalyser(new \OpenApi\Analysers\ReflectionAnalyser([
            new \OpenApi\Analysers\AttributeAnnotationFactory,
        ]));
        $document = $generator->generate($sources);
        $this->assertNotNull($document);
        $spec = json_decode($document->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $operationIds = [];
        $count = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! preg_match('/Modules\\\\(Ticket|Task)\\\\Controllers\\\\Api\\\\V1/', $route->getActionName())) {
                continue;
            }
            $this->assertArrayHasKey('parity_domain', $route->defaults, $route->getName());
            foreach (array_diff($route->methods(), ['HEAD']) as $httpMethod) {
                $count++;
                $operation = $spec['paths']['/'.$route->uri()][strtolower($httpMethod)] ?? null;
                $this->assertNotNull($operation, $httpMethod.' '.$route->uri());
                $this->assertNotContains($operation['operationId'], $operationIds);
                $operationIds[] = $operation['operationId'];
                if (! in_array($httpMethod, ['GET', 'HEAD'], true)) {
                    $this->assertArrayHasKey('parity_input_fields', $route->defaults, $route->getName());
                    $this->assertContains(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class, $route->gatherMiddleware());
                    foreach ($operation['requestBody']['content'] ?? [] as $content) {
                        if (in_array('idempotency_key', $this->inputFields($content['schema'], $spec), true)) {
                            $this->assertSame('#/components/schemas/ParityReadError', $operation['responses'][409]['content']['application/json']['schema']['$ref'] ?? null, $route->getName().' retry conflict');
                        }
                        foreach ($this->inputFields($content['schema'], $spec) as $field) {
                            $this->assertContains($field, $route->defaults['parity_input_fields'], $route->getName().' '.$field);
                        }
                    }
                }

                $this->assertSame(array_merge([$route->defaults['parity_scope']], $route->defaults['parity_additional_scopes'] ?? []), $operation['x-required-scopes']);
                $this->assertSame($route->defaults['parity_permissions'], $operation['x-domain-permissions']);
                $this->assertSame($route->defaults['parity_additional_permissions'] ?? [], $operation['x-additional-domain-permissions']);
                $this->assertSame([['bearerAuth' => []]], $operation['security']);
                preg_match_all('/\{(\w+)\}/', $route->uri(), $parameters);
                foreach ($parameters[1] as $name) {
                    $pathParameter = collect($operation['parameters'])->first(fn ($parameter) => $parameter['in'] === 'path' && $parameter['name'] === $name);
                    $this->assertNotNull($pathParameter, $route->getName().' '.$name);
                    $this->assertTrue($pathParameter['required']);
                }
                $success = array_filter($operation['responses'], fn ($status) => (int) $status >= 200 && (int) $status < 300, ARRAY_FILTER_USE_KEY);
                $this->assertNotEmpty($success, $route->getName());
                foreach ($success as $status => $response) {
                    if ((int) $status === 204) {
                        continue;
                    }
                    $this->assertNotEmpty($response['content'], $route->getName());
                    foreach ($response['content'] as $content) {
                        $this->assertNotEmpty($content['schema'], $route->getName());
                    }
                }
                foreach ([401, 403, 404, 422] as $status) {
                    $this->assertSame('#/components/schemas/ParityReadError', $operation['responses'][$status]['content']['application/json']['schema']['$ref']);
                }
            }
        }
        $this->assertGreaterThan(200, $count);
        $this->assertReferencesExist($spec, $spec);
        $this->assertSame('binary', $spec['paths']['/api/v1/tickets/{ticket}/attachments/{attachment}/download']['get']['responses'][200]['content']['application/octet-stream']['schema']['format']);
        $this->assertArrayHasKey('multipart/form-data', $spec['paths']['/api/v1/tickets/{ticket}/messages']['post']['requestBody']['content']);
        $this->assertArrayHasKey('checklist_text', $spec['components']['schemas']['TaskEditRequest']['properties']);
        $this->assertArrayHasKey('recurrence_rule', $spec['components']['schemas']['TicketEditRequest']['properties']);
        $this->assertArrayNotHasKey('metadata', $spec['components']['schemas']['TicketEventReadback']['properties']);
        $this->assertArrayNotHasKey('message', $spec['components']['schemas']['TicketEventReadback']['properties']);
        $this->assertArrayNotHasKey('changes', $spec['components']['schemas']['TaskActivityReadback']['properties']);
        $this->assertArrayNotHasKey('secure_token', $spec['components']['schemas']['TicketQuoteVersionRecord']['properties']);
    }

    public function test_browser_and_api_route_inventory_requires_an_explicit_parity_audit_for_new_routes(): void
    {
        $matrix = json_decode(file_get_contents(base_path('docs/plans/2026-10-20-ticket-task-api-gap-matrix.json')), true, flags: JSON_THROW_ON_ERROR);
        $browser = collect($matrix['browser_routes'])->keyBy('name');
        $api = collect($matrix['implemented_api_routes'])->keyBy('name');
        foreach (Route::getRoutes() as $route) {
            if (! preg_match('/Modules\\\\(Ticket|Task)\\\\Controllers\\\\/', $route->getActionName())) {
                continue;
            }
            $inventory = str_starts_with($route->uri(), 'api/v1/') ? $api : $browser;
            $this->assertTrue($inventory->has($route->getName()), 'Audit the new route in the parity matrix: '.$route->getName());
            $record = $inventory->get($route->getName());
            $this->assertSame($record['uri'], $route->uri());
            $this->assertSame($record['method'], implode('|', $route->methods()));
            $this->assertContains($record['operation_kind'], ['read', 'write', 'action'], $route->getName());
            $this->assertNotEmpty($record['verification_tests'], $route->getName());
            foreach ($record['verification_tests'] as $testFile) {
                $this->assertFileExists(base_path($testFile));
            }
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                $this->assertNotEmpty($record['api_counterparts'], $route->getName());
                foreach ($record['api_counterparts'] as $counterpart) {
                    $this->assertTrue($api->has($counterpart), $route->getName().' -> '.$counterpart);
                }
            } else {
                $this->assertCount(count(array_diff($route->methods(), ['HEAD'])), $record['operation_contracts']);
                foreach ($record['readback_routes'] as $readback) {
                    $this->assertTrue($api->has($readback), $route->getName().' read-back '.$readback);
                }
            }
        }
        $livewire = collect($matrix['livewire_actions'])->keyBy(fn ($row) => $row['class'].'@'.$row['method']);
        foreach (['Ticket', 'Task'] as $domain) {
            foreach (\Illuminate\Support\Facades\File::allFiles(app_path('Modules/'.$domain.'/Livewire')) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen(app_path()) + 1, -4);
                $class = 'App\\'.str_replace('/', '\\', $relative);
                $reflection = new \ReflectionClass($class);
                foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->getDeclaringClass()->getName() !== $class) {
                        continue;
                    }
                    $key = $class.'@'.$method->getName();
                    $this->assertTrue($livewire->has($key), 'Audit the new Livewire action: '.$key);
                    foreach ($livewire[$key]['api_counterparts'] as $counterpart) {
                        $this->assertTrue($api->has($counterpart), $key.' -> '.$counterpart);
                    }
                }
            }
        }
        foreach ($matrix['functional_matrix'] as $group) {
            $this->assertSame('Implemented on Dev', $group['state'], $group['id']);
            $this->assertNotEmpty($group['implemented_api'], $group['id']);
        }
    }

    /** Top-level fields only; nested policy/registry values keep their domain validators. */
    private function inputFields(array $schema, array $spec): array
    {
        if (isset($schema['$ref'])) {
            return $this->inputFields($spec['components']['schemas'][basename($schema['$ref'])], $spec);
        }
        $fields = array_keys($schema['properties'] ?? []);
        foreach (['allOf', 'anyOf', 'oneOf'] as $kind) {
            foreach ($schema[$kind] ?? [] as $part) {
                $fields = array_merge($fields, $this->inputFields($part, $spec));
            }
        }

        return array_values(array_unique($fields));
    }

    private function assertReferencesExist(array $value, array $spec): void
    {
        if (isset($value['$ref']) && str_starts_with($value['$ref'], '#/')) {
            $target = $spec;
            foreach (explode('/', substr($value['$ref'], 2)) as $part) {
                $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                $this->assertArrayHasKey($part, $target, $value['$ref']);
                $target = $target[$part];
            }
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $this->assertReferencesExist($child, $spec);
            }
        }
    }
}
