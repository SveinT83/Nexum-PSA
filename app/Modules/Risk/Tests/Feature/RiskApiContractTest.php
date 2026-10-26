<?php

namespace App\Modules\Risk\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Exact route/contract/Swagger equality catches omitted methods and untyped new surfaces. */
class RiskApiContractTest extends TestCase
{
    #[Test]
    public function every_registered_risk_api_method_has_an_exact_typed_contract(): void
    {
        $manifest = json_decode(file_get_contents(base_path('docs/plans/2026-10-26-risk-api-contract.json')), true, 512, JSON_THROW_ON_ERROR);
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! str_contains($route->getActionName(), 'Modules\\Risk\\')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[] = $method.' /'.$route->uri();
            }
        }
        $expected = [];
        foreach ($manifest['operations'] as $op) {
            $expected[] = $op['method'].' '.$op['path'];
            if ($op['response'] !== 'binary_pdf') {
                $this->assertArrayHasKey($op['response'], $manifest['schemas']);
            }
            if ($op['request']) {
                $this->assertArrayHasKey($op['request'], $manifest['schemas']);
            }
        }
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
        $this->assertCount(count(array_unique($expected)), $actual);
    }

    #[Test]
    public function generated_swagger_contains_every_exact_method_and_response_schema(): void
    {
        $manifest = json_decode(file_get_contents(base_path('docs/plans/2026-10-26-risk-api-contract.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $swagger = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['operations'] as $op) {
            $operation = $swagger['paths'][$op['path']][strtolower($op['method'])] ?? null;
            $this->assertIsArray($operation, $op['method'].' '.$op['path']);
            $this->assertSame($op['operationId'], $operation['operationId']);
            foreach ($operation['responses'] as $status => $response) {
                if ((int) $status >= 200 && (int) $status < 300) {
                    if ($op['response'] === 'binary_pdf') {
                        $this->assertSame('binary', $response['content']['application/pdf']['schema']['format']);
                    } else {
                        $this->assertSame('#/components/schemas/'.$op['response'], $response['content']['application/json']['schema']['$ref']);
                    }
                }
            }
        }
        foreach (array_keys($manifest['schemas']) as $name) {
            $this->assertArrayHasKey($name, $swagger['components']['schemas']);
            $this->assertEquals($manifest['schemas'][$name], $swagger['components']['schemas'][$name], $name);
        }
    }
}
