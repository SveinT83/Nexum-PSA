<?php

namespace App\Modules\Report\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorklogOpenApiTest extends TestCase
{
    #[Test]
    public function generated_contract_contains_actual_worklog_routes_and_completeness_metadata(): void
    {
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $spec = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['technicians' => 'worklog.read', 'time-entries' => 'time-entries.read'] as $endpoint => $scope) {
            $route = Route::getRoutes()->getByName('api.v1.worklog.'.$endpoint.'.index');
            $this->assertNotNull($route);
            $operation = $spec['paths']['/'.$route->uri()]['get'];
            $this->assertSame([$scope], $operation['x-required-scopes']);
            $this->assertTrue($operation['x-workload-bound']);
            $this->assertSame('pseudonymized', $operation['x-data-profile']);
            $this->assertSame([200, 401, 403, 422, 429], array_keys($operation['responses']));
            $this->assertSame($endpoint === 'technicians' ? ['date_from', 'date_to'] : ['date_from', 'date_to', 'page', 'per_page'], array_column($operation['parameters'], 'name'));
        }
        foreach (['time-consumptions' => 'commercial.worklog.read', 'contract-links' => 'commercial.worklog-links.read'] as $endpoint => $scope) {
            $route = Route::getRoutes()->getByName('api.v1.commercial.worklog.'.$endpoint.'.index');
            $this->assertNotNull($route);
            $operation = $spec['paths']['/'.$route->uri()]['get'];
            $this->assertSame([$scope], $operation['x-required-scopes']);
            $this->assertTrue($operation['x-workload-bound']);
            $this->assertSame([200, 401, 403, 422, 429], array_keys($operation['responses']));
        }
        $meta = $spec['components']['schemas']['WorklogWindowMeta'];
        foreach (['total', 'available_total', 'returned_count', 'truncated', 'recovery', 'maximum_results'] as $field) {
            $this->assertContains($field, $meta['required']);
        }
        $this->assertArrayNotHasKey('client_id', $spec['components']['schemas']['WorklogTimeEntry']['properties']);
        $this->assertArrayNotHasKey('contract_id', $spec['components']['schemas']['WorklogTimeEntry']['properties']);
    }
}
