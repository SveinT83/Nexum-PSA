<?php

namespace App\Modules\Marketing\Tests\Feature;

use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketingApiContractTest extends TestCase
{
    #[Test]
    public function swagger_exactly_covers_all_marketing_methods_scopes_and_typed_successes(): void
    {
        $document = json_decode(file_get_contents(getenv('MARKETING_API_CONTRACT_DOCUMENT') ?: storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $runtime = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/marketing')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $runtime[] = strtolower($method).' /'.$route->uri();
                $contract = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertIsArray($contract);
                $this->assertStringContainsString($route->defaults['marketing_ability'], $contract['description']);
                $this->assertContains($route->defaults['marketing_ability'], app(ApiAbilityCatalog::class)->values());
                $this->assertNotEmpty($contract['security']);
                $fields = $route->defaults['marketing_fields'] ?? [];
                if ($method === 'GET') {
                    $this->assertEqualsCanonicalizing($fields, collect($contract['parameters'] ?? [])->where('in', 'query')->pluck('name')->all());
                } elseif ($fields !== []) {
                    $bodyRef = $contract['requestBody']['content']['application/json']['schema']['$ref'];
                    $bodyFields = array_keys($document['components']['schemas'][basename($bodyRef)]['properties']);
                    $this->assertEqualsCanonicalizing(array_values(array_diff($fields, ['repeat_interval_value', 'repeat_interval_unit'])), $bodyFields);
                }

                preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
                $this->assertEqualsCanonicalizing($matches[1], collect($contract['parameters'] ?? [])->where('in', 'path')->pluck('name')->all());
                foreach ([401, 403, 404, 409, 412, 422, 428, 503] as $status) {
                    $this->assertArrayHasKey($status, $contract['responses']);
                }
                $this->assertTrue(collect(array_keys($contract['responses']))->contains(fn ($code) => $code >= 200 && $code < 300));
            }
        }
        $published = [];
        $ids = [];
        foreach ($document['paths'] as $path => $item) {
            if (! str_starts_with($path, '/api/v1/marketing')) {
                continue;
            }
            foreach ($item as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $published[] = $method.' '.$path;
                    $ids[] = $operation['operationId'];
                }
            }
        }
        $this->assertCount(42, $runtime);
        $this->assertCount(42, array_unique($ids));
        $this->assertEqualsCanonicalizing($runtime, $published);
        $walk = function ($node) use (&$walk, $document): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['$ref'])) {
                $this->assertArrayHasKey(basename($node['$ref']), $document['components']['schemas']);
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        foreach ($document['components']['schemas'] as $name => $schema) {
            if (str_starts_with($name, 'MarketingApi')) {
                $walk($schema);
            }
        }
    }
}
