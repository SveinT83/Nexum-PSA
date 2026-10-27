<?php

namespace App\Modules\Booking\Tests\Feature;

use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Executable route/scope/schema inventory for every supported browser workflow. */
class BookingApiContractTest extends TestCase
{
    #[Test]
    public function every_runtime_operation_matches_the_approved_coverage_and_typed_swagger(): void
    {
        $matrix = json_decode(file_get_contents(base_path('docs/plans/2026-10-27-booking-api-coverage-matrix.json')), true, flags: JSON_THROW_ON_ERROR);
        $spec = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/booking/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[$method.' /'.$route->uri()] = $route;
            }
        }
        $this->assertCount(17, $actual);
        $this->assertCount(13, $matrix['browser']);
        foreach ($matrix['operations'] as $operation) {
            $path = '/api/v1/'.str_replace('{slug}', '{setting}', $operation['path']);
            $key = $operation['method'].' '.$path;
            $this->assertArrayHasKey($key, $actual);
            $route = $actual[$key];
            $documented = $spec['paths'][$path][strtolower($operation['method'])];
            $this->assertSame('booking'.$operation['id'], $documented['operationId']);
            $status = $operation['id'] === 'P04' ? '202' : ($operation['id'] === 'A05' ? '201' : '200');
            $this->assertNotEmpty($documented['responses'][$status]['content']['application/json']['schema']['$ref']);
            if (! str_starts_with($operation['id'], 'P')) {
                $scope = explode(' ', $operation['authority'])[0];
                $this->assertContains(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.$scope, $route->gatherMiddleware());
                $this->assertContains(\App\Modules\Booking\Support\BookingApiAccess::class, $route->gatherMiddleware());
                $this->assertContains($scope, app(ApiAbilityCatalog::class)->values());
            } else {
                $this->assertSame([], $documented['security']);
            }
            if ($operation['method'] !== 'GET') {
                $this->assertNotEmpty($documented['requestBody']['content']['application/json']['schema']['$ref']);
            }
        }
        $schemas = array_filter(array_keys($spec['components']['schemas']), fn ($name) => str_starts_with($name, 'Booking'));
        $this->assertCount(43, $schemas);
        $walk = function ($value) use (&$walk, $spec): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['$ref']) && str_starts_with($value['$ref'], '#/components/schemas/')) {
                $this->assertArrayHasKey(basename($value['$ref']), $spec['components']['schemas']);
            }
            foreach ($value as $item) {
                $walk($item);
            }
        };
        foreach ($schemas as $name) {
            $walk($spec['components']['schemas'][$name]);
        }
    }
}
